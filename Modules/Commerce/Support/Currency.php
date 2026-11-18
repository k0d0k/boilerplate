<?php

namespace Modules\Commerce\Support;

/**
 * WS3 — explicit multi-currency handling for the commerce module.
 *
 * Only IDR and MYR are supported. Currency is ALWAYS carried explicitly on a
 * price, an order and a payment; it is never inferred from the interface
 * language or the tenant (config/localization.php is explicit that language
 * never alters currency).
 *
 * Amounts are stored as fixed-point decimals (`decimal(14,2)`). IDR is a
 * zero-decimal currency, so a stored value is rounded to the nearest whole
 * rupiah when it is presented; MYR keeps two decimals. Rounding is applied at
 * the boundary (presentation / gateway payload), never by silently changing the
 * stored minor unit.
 */
final class Currency
{
    public const IDR = 'IDR';

    public const MYR = 'MYR';

    /** @var list<string> */
    public const SUPPORTED = [self::IDR, self::MYR];

    /**
     * Minor-unit (decimal) places per currency. IDR uses none.
     *
     * @var array<string, int>
     */
    private const DECIMALS = [
        self::IDR => 0,
        self::MYR => 2,
    ];

    /**
     * @var array<string, string>
     */
    private const SYMBOLS = [
        self::IDR => 'Rp',
        self::MYR => 'RM',
    ];

    public static function isSupported(?string $code): bool
    {
        return is_string($code) && in_array(strtoupper($code), self::SUPPORTED, true);
    }

    public static function assertSupported(?string $code): string
    {
        $normalized = is_string($code) ? strtoupper($code) : '';

        if (! in_array($normalized, self::SUPPORTED, true)) {
            throw new \InvalidArgumentException("Unsupported currency [{$code}].");
        }

        return $normalized;
    }

    public static function decimals(string $code): int
    {
        return self::DECIMALS[self::assertSupported($code)] ?? 2;
    }

    public static function symbol(string $code): string
    {
        return self::SYMBOLS[self::assertSupported($code)] ?? '';
    }

    /**
     * The value rounded to the currency's minor unit and formatted with its
     * symbol, e.g. `Rp 125000` or `RM 125.00`.
     */
    public static function format(float|int|string $amount, string $code): string
    {
        $code = self::assertSupported($code);
        $decimals = self::decimals($code);

        $value = round((float) $amount, $decimals);

        return self::symbol($code).' '.number_format($value, $decimals, '.', ',');
    }

    /**
     * The amount rounded to the currency's minor unit as a plain decimal string
     * — the shape a payment gateway expects.
     */
    public static function round(float|int|string $amount, string $code): string
    {
        $code = self::assertSupported($code);
        $decimals = self::decimals($code);

        // A zero-decimal currency (IDR) is sent as a whole number with no
        // fractional part; others keep their fixed decimal places.
        return number_format(round((float) $amount, $decimals), $decimals, '.', '');
    }

    /**
     * The amount as an INTEGER of the currency's minor unit (the smallest unit
     * a provider settles in): rupiah for IDR, sen for MYR.
     *
     * The money-critical comparison path uses minor units so two amounts can be
     * compared for exact equality without binary-float drift (e.g. `0.1 + 0.2`).
     * This never rewrites a stored amount — it is a comparison/transport helper.
     */
    public static function toMinor(float|int|string $amount, string $code): int
    {
        $code = self::assertSupported($code);
        $factor = 10 ** self::decimals($code);

        return (int) round((float) $amount * $factor);
    }

    /**
     * The inverse of {@see self::toMinor}: minor units back to a major-unit
     * float, correctly scaled for the currency.
     */
    public static function fromMinor(int $minor, string $code): float
    {
        $code = self::assertSupported($code);
        $factor = 10 ** self::decimals($code);

        return $minor / $factor;
    }
}
