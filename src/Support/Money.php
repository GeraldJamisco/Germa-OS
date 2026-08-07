<?php

declare(strict_types=1);

namespace Germa\Support;

/**
 * All money in Germa OS is stored as an integer in minor units plus a currency
 * code. UGX has no minor unit in practice (0 decimals), USD has 2. Floats are
 * never used for stored amounts this is a financial system and rounding drift
 * is not acceptable in a runway calculation.
 */
final class Money
{
    public function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {}

    public static function of(int $minor, ?string $currency = null): self
    {
        return new self($minor, $currency ?? self::base());
    }

    /** Build from a human-entered decimal string/number, e.g. "580000" or "12.50". */
    public static function fromMajor(int|float|string $major, ?string $currency = null): self
    {
        $currency = $currency ?? self::base();
        $decimals = self::decimals($currency);
        $clean    = is_string($major) ? preg_replace('/[^0-9.\-]/', '', $major) : (string) $major;
        $clean    = $clean === '' || $clean === null ? '0' : $clean;

        return new self((int) round(((float) $clean) * (10 ** $decimals)), $currency);
    }

    public static function zero(?string $currency = null): self
    {
        return new self(0, $currency ?? self::base());
    }

    public static function base(): string
    {
        return (string) Config::get('money.base', 'UGX');
    }

    public static function decimals(string $currency): int
    {
        return (int) (Config::get('money.decimals')[$currency] ?? 2);
    }

    public function toMajor(): float
    {
        return $this->minor / (10 ** self::decimals($this->currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->minor - $other->minor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    /** "UGX 580,000" / "$535.00" */
    public function format(): string
    {
        $decimals = self::decimals($this->currency);
        $symbol   = (string) (Config::get('money.symbols')[$this->currency] ?? $this->currency);
        $number   = number_format($this->toMajor(), $decimals, '.', ',');
        $sign     = '';

        if (str_starts_with($number, '-')) {
            $sign   = '-';
            $number = substr($number, 1);
        }

        // "$" hugs the number, alphabetic codes get a space.
        return preg_match('/^[A-Za-z]+$/', $symbol) === 1
            ? "$sign$symbol $number"
            : "$sign$symbol$number";
    }

    /** "UGX 580K" for tight stat tiles. Never used where precision matters. */
    public function compact(): string
    {
        $symbol = (string) (Config::get('money.symbols')[$this->currency] ?? $this->currency);
        $major  = abs($this->toMajor());
        $sign   = $this->isNegative() ? '-' : '';

        $formatted = null;

        // Largest unit first. The threshold is nudged down by half a rounding
        // step so 999,999 reads as 1M rather than 1000K.
        foreach ([1_000_000_000 => 'B', 1_000_000 => 'M', 1_000 => 'K'] as $unit => $suffix) {
            if ($major < $unit * 0.9995) {
                continue;
            }

            // One decimal before trimming, so the trim stops at the decimal
            // point and cannot eat a significant zero (450 must not become 45).
            $formatted = rtrim(rtrim(number_format($major / $unit, 1, '.', ''), '0'), '.') . $suffix;
            break;
        }

        $formatted ??= number_format($major, self::decimals($this->currency), '.', ',');

        return preg_match('/^[A-Za-z]+$/', $symbol) === 1
            ? "$sign$symbol $formatted"
            : "$sign$symbol$formatted";
    }

    /**
     * Convert using the stored USD/UGX rate. The result is always an ESTIMATE
     * the rate is a manually maintained setting, not a live feed.
     */
    public function convertTo(string $currency, float $usdToBaseRate): self
    {
        if ($currency === $this->currency) {
            return $this;
        }

        $base = self::base();
        $usd  = 'USD';

        $majorValue = $this->toMajor();

        if ($this->currency === $usd && $currency === $base) {
            return self::fromMajor($majorValue * $usdToBaseRate, $currency);
        }
        if ($this->currency === $base && $currency === $usd) {
            $rate = $usdToBaseRate > 0.0 ? $usdToBaseRate : 1.0;
            return self::fromMajor($majorValue / $rate, $currency);
        }

        return self::fromMajor($majorValue, $currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new \InvalidArgumentException(
                "Refusing to add {$other->currency} to {$this->currency} without an explicit conversion."
            );
        }
    }
}
