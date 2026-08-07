/**
 * Units of sale — the browser half of app/Support/Unit.php.
 *
 * Kept in step with the PHP table by hand rather than fetched: the till has to
 * price a line while offline, and a unit table that arrives over the network is
 * a unit table that can be missing exactly when the shop is busiest.
 *
 * Prices and stock live in one canonical *base* unit — grams for anything
 * weighed, millilitres for anything poured, pieces for anything counted — and
 * `price_unit` is only how the shopkeeper quotes it ("Rs 250 per kg").
 */

export const TYPE_EACH = 'each';
export const TYPE_WEIGHT = 'weight';
export const TYPE_VOLUME = 'volume';

/** `factor` = how many base units one of this unit holds. */
export const UNITS = {
  pc: { type: TYPE_EACH, base: 'pc', factor: 1, label: 'Piece', short: 'pc' },
  dozen: { type: TYPE_EACH, base: 'pc', factor: 12, label: 'Dozen (darjan)', short: 'dz' },
  g: { type: TYPE_WEIGHT, base: 'g', factor: 1, label: 'Gram', short: 'g' },
  kg: { type: TYPE_WEIGHT, base: 'g', factor: 1000, label: 'Kilogram', short: 'kg' },
  ml: { type: TYPE_VOLUME, base: 'ml', factor: 1, label: 'Millilitre', short: 'ml' },
  l: { type: TYPE_VOLUME, base: 'ml', factor: 1000, label: 'Litre', short: 'L' },
};

export const BASE_FOR_TYPE = { [TYPE_EACH]: 'pc', [TYPE_WEIGHT]: 'g', [TYPE_VOLUME]: 'ml' };

export const TYPE_LABELS = {
  [TYPE_EACH]: 'Counted (packets, bottles)',
  [TYPE_WEIGHT]: 'Weighed (atta, daal, sabzi)',
  [TYPE_VOLUME]: 'Poured (oil, milk, ghee)',
};

/**
 * The weights a Pakistani counter actually calls out. "Pao" is a quarter kilo
 * and is asked for by name far more often than "250 grams" is.
 */
export const QUICK_WEIGHTS = [
  { base: 250, label: 'Pao' },
  { base: 500, label: 'Adha kg' },
  { base: 750, label: '3 Pao' },
  { base: 1000, label: '1 kg' },
  { base: 2000, label: '2 kg' },
  { base: 5000, label: '5 kg' },
];

export const QUICK_VOLUMES = [
  { base: 250, label: '250 ml' },
  { base: 500, label: '500 ml' },
  { base: 1000, label: '1 L' },
  { base: 2000, label: '2 L' },
  { base: 5000, label: '5 L' },
];

/** "Sau ka doodh", "pachaas ka daal" — the customer names the money, not the weight. */
export const QUICK_AMOUNTS = [20, 50, 100, 200, 500, 1000];

export function isValidUnit(unit) {
  return Boolean(unit && Object.prototype.hasOwnProperty.call(UNITS, unit));
}

export function factorOf(unit) {
  return UNITS[unit]?.factor ?? 1;
}

export function shortOf(unit) {
  return UNITS[unit]?.short ?? unit ?? '';
}

export function typeOf(unit) {
  return UNITS[unit]?.type ?? TYPE_EACH;
}

export function baseFor(type) {
  return BASE_FOR_TYPE[type] ?? 'pc';
}

/** Price units a shopkeeper may quote for this kind of goods. */
export function codesForType(type) {
  return Object.keys(UNITS).filter((code) => UNITS[code].type === type);
}

/** Anything not sold one-at-a-time needs the weight keypad rather than +/− buttons. */
export function isMeasured(product) {
  return (product?.unit_type ?? TYPE_EACH) !== TYPE_EACH;
}

const num = (value) => (Number.isFinite(Number(value)) ? Number(value) : 0);

/** 1.5 kg → 1500 g */
export function toBase(quantity, unit) {
  return round(num(quantity) * factorOf(unit), 3);
}

/** 1500 g → 1.5 kg */
export function fromBase(baseQuantity, unit) {
  return round(num(baseQuantity) / factorOf(unit), 6);
}

/** "250" typed against a kg price → 0.25 stored per gram. */
export function priceToBase(quotedPrice, unit) {
  return round(num(quotedPrice) / factorOf(unit), 4);
}

/** 0.25 per gram → 250 shown per kg. */
export function priceFromBase(basePrice, unit) {
  return round(num(basePrice) * factorOf(unit), 2);
}

/**
 * "Rs 50 ka daal" → how much to weigh out.
 *
 * The weight is deliberately *not* snapped to a scale step: the customer asked
 * for fifty rupees of daal and that is what the ticket must say, so the money
 * stays exact and the weight carries the remainder. The keypad shows the figure
 * rounded for the scale; the line keeps the precise one.
 */
export function quantityForAmount(amount, basePrice) {
  const price = num(basePrice);
  if (price <= 0) return 0;
  return round(num(amount) / price, 3);
}

export function amountForQuantity(baseQuantity, basePrice) {
  return round(num(baseQuantity) * num(basePrice), 2);
}

/**
 * A quantity written the way it would be said aloud: grams below a kilo, kilos
 * above, trailing zeros trimmed so 2 kg does not read as "2.000 kg".
 */
export function formatQuantity(baseQuantity, type) {
  const qty = num(baseQuantity);

  // Counted goods carry no suffix: "10 in stock" is how the shop already reads,
  // and "10 pc in stock" adds a word without adding a fact. Only loose goods
  // genuinely need the unit spelled out.
  if ((type ?? TYPE_EACH) === TYPE_EACH) return trim(qty);

  const [big, small] = type === TYPE_WEIGHT ? ['kg', 'g'] : ['l', 'ml'];

  return Math.abs(qty) >= 1000
    ? `${trim(round(qty / 1000, 3))} ${shortOf(big)}`
    : `${trim(round(qty, 3))} ${shortOf(small)}`;
}

/** "Rs 250 / kg" */
export function formatUnitPrice(basePrice, priceUnit) {
  return `Rs ${trim(priceFromBase(basePrice, priceUnit))} / ${shortOf(priceUnit)}`;
}

/**
 * The step a +/− tap should move by. Counted goods move one piece; weighed and
 * poured goods move in the smallest amount anyone actually asks for.
 */
export function stepFor(type) {
  if (type === TYPE_WEIGHT) return 50;
  if (type === TYPE_VOLUME) return 50;
  return 1;
}

function round(value, dp) {
  const f = 10 ** dp;
  return Math.round((num(value) + Number.EPSILON) * f) / f;
}

function trim(value) {
  const fixed = num(value).toFixed(3);
  return fixed.replace(/\.?0+$/, '') || '0';
}
