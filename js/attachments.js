/**
 * Receipts and payment screenshots — the paperwork behind money that moved.
 *
 * Two things use this today: the delivery form, where a photo of the
 * wholesaler's bill is attached as stock is booked in, and the payment form,
 * where the JazzCash/bank screenshot is attached to the instalment it paid.
 *
 * The one thing that makes this different from the catalogue's picture upload
 * in js/products.js: these files are private. The API serves them from a disk
 * with no public URL, behind an authorisation check, so `<img src="/api/…">`
 * fetches nothing — the browser sends that request without the bearer token and
 * gets a 401. Every thumbnail here is therefore fetched as a blob with the
 * token attached and shown through an object URL. loadThumbnail() below is that
 * dance, and it is why this module exists rather than a few lines inline.
 */
import { API_BASE_URL, apiDelete, getAuthToken } from './api.js';

/** Mirrors ReceiptImageStore on the API — see uploadAttachment(). */
export const MAX_ATTACHMENT_BYTES = 5 * 1024 * 1024;

export const ATTACHMENT_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/** Mirrors AttachmentController::MAX_PER_RECORD. */
export const MAX_PER_RECORD = 12;

/**
 * Object URLs handed out by loadThumbnail(), keyed by attachment id.
 *
 * Cached because the same receipt is re-rendered every time the invoice
 * refreshes — after a payment, after a delete — and re-fetching a 3 MB
 * screenshot each time would make the screen feel broken on a shop's DSL line.
 * Entries are released by releaseThumbnails() when the screen is torn down.
 */
const thumbnails = new Map();

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text ?? '';
  // The text-node serialiser escapes &, < and > but leaves quotes alone, and
  // these values land inside attributes too — a caption typed as
  // `x" onerror="…` would otherwise break straight out of one.
  return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/* --------------------------------------------------------------- uploading */

/**
 * Checks a file before it costs a round trip.
 *
 * The API re-checks all of this and decodes the image header besides, which is
 * the check that actually matters. This one exists so the shopkeeper hears
 * "that is a PDF" straight away rather than after pushing 5 MB up a DSL line.
 *
 * @returns {string|null} an error to show, or null if the file is worth sending
 */
export function attachmentProblem(file) {
  if (!file) return 'Pick a file first.';
  if (!ATTACHMENT_TYPES.includes(file.type)) return 'Attach a JPG, PNG or WebP image.';
  if (file.size > MAX_ATTACHMENT_BYTES) return 'That image is larger than 5 MB. Please pick a smaller one.';
  return null;
}

/**
 * Posts one file as multipart.
 *
 * Not apiPost(): api.js always sets `Content-Type: application/json`, and any
 * Content-Type set by hand stops the browser writing the multipart boundary the
 * server needs to find the file. The base URL and the bearer token are reused;
 * the request itself is built here.
 */
export async function uploadAttachment(path, file, caption = null) {
  const body = new FormData();
  body.append('image', file);
  if (caption) body.append('caption', caption);

  const token = getAuthToken();
  const response = await fetch(`${API_BASE_URL}${path}`, {
    method: 'POST',
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body,
  });

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    const error = new Error(
      data?.errors?.image?.[0] || data?.message || 'The attachment could not be uploaded.'
    );
    error.status = response.status;
    error.body = data;
    throw error;
  }

  return data?.attachment ?? null;
}

/**
 * Uploads a list of files one after another and reports what happened.
 *
 * Sequential rather than parallel on purpose: a shop's uplink is the narrow
 * part, and three 4 MB screenshots racing each other is slower than three in a
 * row as well as harder to report on. A failure does not stop the rest — the
 * second page of a bill going up is still worth having if the first failed.
 *
 * @returns {{uploaded: object[], failed: string[]}}
 */
export async function uploadAll(path, files, caption = null) {
  const uploaded = [];
  const failed = [];

  for (const file of files) {
    try {
      const attachment = await uploadAttachment(path, file, caption);
      if (attachment) uploaded.push(attachment);
    } catch (err) {
      failed.push(`${file.name}: ${err.message}`);
    }
  }

  return { uploaded, failed };
}

export function removeAttachment(id) {
  releaseThumbnail(id);
  return apiDelete(`/api/attachments/${id}`);
}

/* ---------------------------------------------------------------- viewing */

/**
 * Fetches one attachment's bytes with the bearer token and returns an object
 * URL for them.
 *
 * Returns null rather than throwing when the fetch fails: a receipt that will
 * not load should leave a placeholder on an otherwise working invoice, not take
 * the screen down with it.
 */
export async function loadThumbnail(id) {
  if (thumbnails.has(id)) return thumbnails.get(id);

  const token = getAuthToken();

  try {
    const response = await fetch(`${API_BASE_URL}/api/attachments/${id}`, {
      credentials: 'include',
      headers: {
        Accept: 'image/*',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    });

    if (!response.ok) return null;

    const url = URL.createObjectURL(await response.blob());
    thumbnails.set(id, url);
    return url;
  } catch {
    return null;
  }
}

function releaseThumbnail(id) {
  const url = thumbnails.get(id);
  if (!url) return;
  URL.revokeObjectURL(url);
  thumbnails.delete(id);
}

/** Call when leaving a screen: object URLs pin their blob in memory until revoked. */
export function releaseThumbnails() {
  for (const url of thumbnails.values()) URL.revokeObjectURL(url);
  thumbnails.clear();
}

/* --------------------------------------------------------------- rendering */

function sizeLabel(bytes) {
  const kb = Number(bytes) / 1024;
  if (!Number.isFinite(kb) || kb <= 0) return '';
  return kb >= 1024 ? `${(kb / 1024).toFixed(1)} MB` : `${Math.round(kb)} KB`;
}

/**
 * The gallery markup for one record's attachments.
 *
 * Thumbnails start empty and are filled in by hydrateGallery() once their bytes
 * arrive, so the layout does not jump about while a slow connection catches up.
 *
 * @param {object[]} attachments
 * @param {{ deletable?: boolean, emptyText?: string }} options
 */
export function galleryHTML(attachments, options = {}) {
  const list = attachments || [];
  const { deletable = false, emptyText = 'No receipt attached.' } = options;

  if (list.length === 0) {
    return `<p class="attachment-empty">${escapeHtml(emptyText)}</p>`;
  }

  return `<ul class="attachment-grid">${list
    .map((attachment) => {
      const id = escapeHtml(String(attachment.id));
      const label = escapeHtml(attachment.caption || attachment.original_name || 'Receipt');
      const size = sizeLabel(attachment.size_bytes);

      return `
      <li class="attachment-item" data-attachment-id="${id}">
        <button type="button" class="attachment-open" data-attachment-open="${id}"
                aria-label="Open ${label} full size">
          <span class="attachment-thumb" data-attachment-thumb="${id}"></span>
        </button>
        <span class="attachment-meta">
          <span class="attachment-name">${label}</span>
          ${size ? `<span class="attachment-size">${escapeHtml(size)}</span>` : ''}
        </span>
        ${
          deletable
            ? `<button type="button" class="attachment-remove" data-attachment-remove="${id}"
                       aria-label="Remove ${label}">&times;</button>`
            : ''
        }
      </li>`;
    })
    .join('')}</ul>`;
}

/**
 * Fills in the thumbnails a galleryHTML() block left empty.
 *
 * Scoped to a container so an invoice with a bill and four payment screenshots
 * on it does not re-fetch the lot every time one section re-renders.
 */
export async function hydrateGallery(container) {
  if (!container) return;

  const slots = [...container.querySelectorAll('[data-attachment-thumb]')];

  await Promise.all(
    slots.map(async (slot) => {
      if (slot.dataset.loaded === 'yes') return;

      const url = await loadThumbnail(Number(slot.dataset.attachmentThumb));

      if (!url) {
        slot.classList.add('is-missing');
        slot.textContent = '!';
        slot.dataset.loaded = 'yes';
        return;
      }

      const img = document.createElement('img');
      img.src = url;
      img.alt = '';
      img.loading = 'lazy';
      slot.replaceChildren(img);
      slot.dataset.loaded = 'yes';
    })
  );
}

/**
 * Opens one attachment full size in a new tab.
 *
 * The object URL is handed over rather than the API path, for the same reason
 * the thumbnails use one: a plain navigation to /api/attachments/… carries no
 * bearer token and would land on a 401.
 */
export async function openAttachment(id) {
  const url = await loadThumbnail(Number(id));
  if (url) window.open(url, '_blank', 'noopener');
}
