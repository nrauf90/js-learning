/**
 * wa.me deep links for khata reminders.
 *
 * No SMS gateway and no WhatsApp Business API — the shop's own WhatsApp opens
 * with the message already typed, and the send button is still a human thumb.
 * Kept DOM-free so the phone normalisation can be unit-tested in node.
 */

/**
 * The number wa.me wants: digits only, international format, no '+'.
 *
 * Shop khatas carry whatever the cashier typed — "0300-1234567", "+92 300
 * 1234567", "300 1234567". Pakistani numbers normalise to 92…; anything that
 * cannot be a dialable number comes back null so the caller can grey the
 * button out rather than opening a chat to nobody.
 */
export function whatsappNumber(phone) {
  let digits = String(phone ?? '').replace(/\D/g, '');

  // 00 is the international dialling prefix ("0092 300 …"), not part of it.
  if (digits.startsWith('00')) digits = digits.slice(2);
  // The trunk zero: "0300…" becomes "92300…".
  else if (digits.startsWith('0')) digits = `92${digits.slice(1)}`;
  // A mobile typed without its leading zero is still a mobile.
  else if (digits.length === 10 && digits.startsWith('3')) digits = `92${digits}`;

  // A Pakistani mobile in international form is 12 digits (92 + 10); anything
  // shorter than 11 is a fragment, not a number WhatsApp can reach.
  return digits.length >= 11 ? digits : null;
}

/**
 * The pre-typed reminder. `balance` arrives already formatted ("Rs 1,240.00")
 * so this stays a pure string function the unit tests can pin down.
 */
export function reminderText({ name, shop, balance }) {
  const who = name || 'there';
  const from = shop || 'the shop';
  return (
    `Assalam-o-Alaikum ${who} — a reminder from ${from}: ` +
    `${balance} is outstanding on your khata. ` +
    `Please pay when you can. Thank you.`
  );
}

/** The full deep link, or null when there is no number to send to. */
export function reminderUrl(phone, text) {
  const number = whatsappNumber(phone);
  if (!number) return null;
  return `https://wa.me/${number}?text=${encodeURIComponent(text)}`;
}
