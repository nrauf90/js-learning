<?php

namespace App\Support;

/**
 * The shop's units of sale.
 *
 * Prices and stock are stored in one canonical *base* unit per kind of goods:
 * grams for anything weighed, millilitres for anything poured, pieces for
 * anything counted. `price_unit` is only ever a display and data-entry concern
 * — the unit the shopkeeper thinks and quotes in ("Rs 250 per kg").
 *
 * Storing per-gram rather than per-kilo is what makes the till's two questions
 * both exact, and the second one is the common one over a Pakistani counter:
 *
 *   "Ek pao daal"   → 250 g × Rs 0.25/g = Rs 62.50
 *   "Pachaas ka daal" → Rs 50 ÷ Rs 0.25/g = 200 g
 *
 * Going the other way — holding Rs 250/kg and dividing — loses paisa on every
 * cheap-per-gram line (namak at Rs 40/kg is Rs 0.04/g, which rounds to zero at
 * two decimal places).
 */
final class Unit
{
    public const TYPE_EACH = 'each';

    public const TYPE_WEIGHT = 'weight';

    public const TYPE_VOLUME = 'volume';

    /**
     * `factor` is how many base units one of this unit contains, so a price
     * quoted in it is divided by the factor to reach the stored per-base price.
     *
     * @var array<string, array{type: string, base: string, factor: int, label: string, short: string}>
     */
    public const UNITS = [
        'pc' => ['type' => self::TYPE_EACH, 'base' => 'pc', 'factor' => 1, 'label' => 'Piece', 'short' => 'pc'],
        // Eggs, bananas and bread are quoted by the darjan across Pakistan, but
        // they are still sold one at a time — hence pieces underneath.
        'dozen' => ['type' => self::TYPE_EACH, 'base' => 'pc', 'factor' => 12, 'label' => 'Dozen (darjan)', 'short' => 'dz'],
        'g' => ['type' => self::TYPE_WEIGHT, 'base' => 'g', 'factor' => 1, 'label' => 'Gram', 'short' => 'g'],
        'kg' => ['type' => self::TYPE_WEIGHT, 'base' => 'g', 'factor' => 1000, 'label' => 'Kilogram', 'short' => 'kg'],
        'ml' => ['type' => self::TYPE_VOLUME, 'base' => 'ml', 'factor' => 1, 'label' => 'Millilitre', 'short' => 'ml'],
        'l' => ['type' => self::TYPE_VOLUME, 'base' => 'ml', 'factor' => 1000, 'label' => 'Litre', 'short' => 'L'],
    ];

    /** @var array<string, string> */
    public const BASE_FOR_TYPE = [
        self::TYPE_EACH => 'pc',
        self::TYPE_WEIGHT => 'g',
        self::TYPE_VOLUME => 'ml',
    ];

    /**
     * Base-unit quantities are held to three decimals, which is a milligram on
     * a gram scale — far finer than any shop scale, and enough that "Rs 50 of
     * daal" divides out without the money drifting.
     */
    public const QUANTITY_DP = 3;

    /** Per-base prices need four: Rs 40/kg is Rs 0.04/g. */
    public const PRICE_DP = 4;

    /** @return list<string> */
    public static function types(): array
    {
        return [self::TYPE_EACH, self::TYPE_WEIGHT, self::TYPE_VOLUME];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::UNITS);
    }

    public static function isValid(?string $unit): bool
    {
        return $unit !== null && isset(self::UNITS[$unit]);
    }

    /**
     * Which units a shopkeeper may quote a price in, given the kind of goods.
     * Quoting daal in litres is a data-entry slip, not a business decision.
     *
     * @return list<string>
     */
    public static function codesForType(string $type): array
    {
        return array_keys(array_filter(self::UNITS, fn (array $u) => $u['type'] === $type));
    }

    public static function baseFor(string $type): string
    {
        return self::BASE_FOR_TYPE[$type] ?? 'pc';
    }

    public static function typeOf(string $unit): string
    {
        return self::UNITS[$unit]['type'] ?? self::TYPE_EACH;
    }

    public static function factor(string $unit): int
    {
        return self::UNITS[$unit]['factor'] ?? 1;
    }

    public static function short(string $unit): string
    {
        return self::UNITS[$unit]['short'] ?? $unit;
    }

    /** Does a price quoted in `$unit` make sense for `$type` goods? */
    public static function matchesType(string $unit, string $type): bool
    {
        return self::isValid($unit) && self::typeOf($unit) === $type;
    }

    /** Quantity expressed in `$unit` → base units. 1.5 kg → 1500 g. */
    public static function toBase(float $quantity, string $unit): float
    {
        return round($quantity * self::factor($unit), self::QUANTITY_DP);
    }

    /** Base units → quantity expressed in `$unit`. 1500 g → 1.5 kg. */
    public static function fromBase(float $baseQuantity, string $unit): float
    {
        return round($baseQuantity / self::factor($unit), 6);
    }

    /**
     * The price the shopkeeper typed ("250", meaning per kg) → the per-base
     * price that gets stored (0.25, meaning per gram).
     */
    public static function priceToBase(float $quotedPrice, string $unit): float
    {
        return round($quotedPrice / self::factor($unit), self::PRICE_DP);
    }

    /** The stored per-base price → the price to show on the products screen. */
    public static function priceFromBase(float $basePrice, string $unit): float
    {
        return round($basePrice * self::factor($unit), 2);
    }

    /**
     * A quantity written the way a shopkeeper would say it — grams below a
     * kilo, kilos above. Trailing zeros are trimmed so a plain 2 kg does not
     * read as "2.000 kg" on a receipt.
     */
    public static function formatQuantity(float $baseQuantity, string $type): string
    {
        // Counted goods carry no suffix: "10 in stock" is how the shop already
        // reads, and "10 pc in stock" adds a word without adding a fact. Only
        // loose goods genuinely need the unit spelled out.
        if ($type === self::TYPE_EACH) {
            return self::trim($baseQuantity);
        }

        $big = $type === self::TYPE_WEIGHT ? 'kg' : 'l';
        $small = $type === self::TYPE_WEIGHT ? 'g' : 'ml';

        if (abs($baseQuantity) >= 1000) {
            return self::trim(round($baseQuantity / 1000, 3)).' '.self::short($big);
        }

        return self::trim(round($baseQuantity, 3)).' '.self::short($small);
    }

    /** "Rs 250 / kg" — the unit price as it is quoted, not as it is stored. */
    public static function formatUnitPrice(float $basePrice, string $priceUnit): string
    {
        $quoted = self::priceFromBase($basePrice, $priceUnit);

        return 'Rs '.self::trim($quoted).' / '.self::short($priceUnit);
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }
}
