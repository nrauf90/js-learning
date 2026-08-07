/**
 * Shop details + staff management for a shop admin.
 *
 * Everything here is also enforced server-side (ShopAdminOnly, StaffController
 * and the product policies) — hiding the staff section for a cashier is a
 * courtesy, not the security boundary.
 */
import { apiGet, apiPost, apiPut, apiDelete, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import { initTheme } from './theme.js';

/** Set once the current user is known; gates what the page even renders. */
let isShopOwner = false;
let pendingDeactivation = null;

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('shop.html')}`);
  return false;
}

function showAlert(message, type = 'error') {
  const el = document.getElementById('shop-alert');
  if (!el) return;
  el.hidden = false;
  el.textContent = message;
  el.dataset.type = type;
  el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function clearAlert() {
  const el = document.getElementById('shop-alert');
  if (!el) return;
  el.hidden = true;
  el.textContent = '';
}

/** Laravel returns per-field messages; the first one is the useful one. */
function firstValidationError(err, fallback) {
  const errors = err?.body?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first[0]) return first[0];
  }
  return err?.message || fallback;
}

function formatDate(dateString) {
  if (!dateString) return '—';
  return new Date(dateString).toLocaleDateString('en-PK', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

/**
 * Staff names and emails are chosen by a shop admin and rendered back into the
 * table, so they go through this on the way — the same fix M11 applied after
 * the stored-XSS bug in the admin user list.
 */
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text ?? '';
  // textContent → innerHTML leaves quotes alone, and these values also land in
  // data-* attributes, where a bare " would close the attribute early and turn
  // the rest of a staff name into markup.
  return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function setValue(id, value) {
  const el = document.getElementById(id);
  if (el) el.value = value ?? '';
}

function fieldValue(id) {
  return document.getElementById(id)?.value.trim() || '';
}

async function loadShop() {
  try {
    const { shop } = await apiGet('/api/shop');
    if (!shop) return;
    setValue('shop-name', shop.name);
    setValue('shop-phone', shop.phone);
    setValue('shop-address', shop.address);
    setValue('shop-footer', shop.receipt_footer);
  } catch (err) {
    showAlert(err.message || 'Could not load your shop.');
  }
}

async function saveShop(event) {
  event.preventDefault();
  clearAlert();

  const name = fieldValue('shop-name');
  if (!name) {
    showAlert('Shop name is required.');
    return;
  }

  const button = document.getElementById('shop-save');
  button.disabled = true;

  try {
    await apiPut('/api/shop', {
      name,
      phone: fieldValue('shop-phone') || null,
      address: fieldValue('shop-address') || null,
      receipt_footer: fieldValue('shop-footer') || null,
    });
    showAlert('Shop details saved.', 'success');
    // The first save is also what creates the shop, so the staff list stops
    // being a 409 at that point.
    await loadStaff();
  } catch (err) {
    showAlert(firstValidationError(err, 'Could not save your shop.'));
  } finally {
    button.disabled = false;
  }
}

function renderStaff(staff) {
  const tbody = document.getElementById('staff-body');
  if (!tbody) return;

  if (!staff || staff.length === 0) {
    tbody.innerHTML =
      '<tr><td colspan="5" class="admin-table-empty">No staff yet. Add one above.</td></tr>';
    return;
  }

  tbody.innerHTML = staff
    .map(
      (member) => `
    <tr>
      <td><strong>${escapeHtml(member.name)}</strong></td>
      <td>${escapeHtml(member.email)}</td>
      <td>
        <label class="admin-checkbox staff-permission">
          <input type="checkbox" data-action="permission" data-id="${member.id}"
                 ${member.can_manage_products ? 'checked' : ''}
                 aria-label="Can manage products" />
          <span>${member.can_manage_products ? 'Products &amp; stock' : 'Till only'}</span>
        </label>
      </td>
      <td>${formatDate(member.created_at)}</td>
      <td>
        <div class="admin-actions">
          <button class="admin-btn admin-btn-sm admin-btn-danger" data-action="deactivate"
                  data-id="${member.id}" data-name="${escapeHtml(member.name)}">
            Deactivate
          </button>
        </div>
      </td>
    </tr>`
    )
    .join('');
}

async function loadStaff() {
  if (!isShopOwner) return;

  const tbody = document.getElementById('staff-body');
  try {
    const { staff } = await apiGet('/api/staff');
    renderStaff(staff);
  } catch (err) {
    // 409 means the shop row does not exist yet — the owner has to save their
    // details first, which is the form directly above this table.
    if (err.status === 409 && tbody) {
      tbody.innerHTML =
        '<tr><td colspan="5" class="admin-table-empty">Save your shop details first, then add staff.</td></tr>';
      return;
    }
    showAlert(err.message || 'Could not load your staff.');
  }
}

async function createStaff(event) {
  event.preventDefault();
  clearAlert();

  const password = document.getElementById('staff-password').value;
  const confirmation = document.getElementById('staff-password-confirm').value;

  if (password !== confirmation) {
    showAlert('The two passwords do not match.');
    return;
  }

  const button = document.getElementById('staff-save');
  button.disabled = true;

  try {
    await apiPost('/api/staff', {
      name: fieldValue('staff-name'),
      email: fieldValue('staff-email'),
      password,
      password_confirmation: confirmation,
      can_manage_products: document.getElementById('staff-can-manage').checked,
    });
    document.getElementById('staff-form').reset();
    showAlert('Staff member added.', 'success');
    await loadStaff();
  } catch (err) {
    showAlert(firstValidationError(err, 'Could not add that staff member.'));
  } finally {
    button.disabled = false;
  }
}

async function togglePermission(checkbox) {
  clearAlert();
  const allowed = checkbox.checked;
  checkbox.disabled = true;

  try {
    await apiPut(`/api/staff/${checkbox.dataset.id}`, { can_manage_products: allowed });
    showAlert(allowed ? 'Catalogue access granted.' : 'Catalogue access removed.', 'success');
    await loadStaff();
  } catch (err) {
    // The server said no, so put the checkbox back where it was rather than
    // leaving the UI claiming a permission that was never granted.
    checkbox.checked = !allowed;
    showAlert(firstValidationError(err, 'Could not change that permission.'));
  } finally {
    checkbox.disabled = false;
  }
}

function openDeactivateModal(id, name) {
  pendingDeactivation = id;
  document.getElementById('deactivate-name').textContent = name;
  document.getElementById('deactivate-modal').hidden = false;
}

function closeDeactivateModal() {
  pendingDeactivation = null;
  document.getElementById('deactivate-modal').hidden = true;
}

async function confirmDeactivate() {
  if (!pendingDeactivation) return;
  const id = pendingDeactivation;
  closeDeactivateModal();

  try {
    await apiDelete(`/api/staff/${id}`);
    showAlert('Staff member deactivated. Their history is untouched.', 'success');
    await loadStaff();
  } catch (err) {
    showAlert(err.message || 'Could not deactivate that staff member.');
  }
}

function wireStaffTable() {
  document.getElementById('staff-body')?.addEventListener('change', (e) => {
    const checkbox = e.target.closest('[data-action="permission"]');
    if (checkbox) togglePermission(checkbox);
  });

  document.getElementById('staff-body')?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action="deactivate"]');
    if (btn) openDeactivateModal(btn.dataset.id, btn.dataset.name);
  });

  document.getElementById('deactivate-close')?.addEventListener('click', closeDeactivateModal);
  document.getElementById('deactivate-cancel')?.addEventListener('click', closeDeactivateModal);
  document.getElementById('deactivate-overlay')?.addEventListener('click', closeDeactivateModal);
  document.getElementById('deactivate-confirm')?.addEventListener('click', confirmDeactivate);
}

/** Staff get the shop details read-only; the staff sections never render. */
function applyRole() {
  document.getElementById('staff-section').hidden = !isShopOwner;
  document.getElementById('staff-list-section').hidden = !isShopOwner;

  if (isShopOwner) return;

  document.getElementById('shop-readonly').hidden = false;
  document.getElementById('shop-save').hidden = true;
  document
    .querySelectorAll('#shop-form input')
    .forEach((input) => input.setAttribute('disabled', 'disabled'));
}

async function boot() {
  initTheme();
  initShell({ current: 'shop' });
  if (!requireAuth()) return;

  wireStaffTable();
  document.getElementById('shop-form')?.addEventListener('submit', saveShop);
  document.getElementById('staff-form')?.addEventListener('submit', createStaff);

  try {
    const { user } = await apiGet('/api/user');
    isShopOwner = user?.role === 'shop_admin' && !user?.is_admin;
  } catch {
    // A failed lookup leaves the page in its safest state: read-only.
    isShopOwner = false;
  }

  applyRole();
  await loadShop();
  await loadStaff();
}

boot();
