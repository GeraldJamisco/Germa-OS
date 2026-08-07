<?php

declare(strict_types=1);

namespace Germa\Support;

/**
 * A single number destined for the dashboard, carrying its own provenance and
 * an optional one-line explanation of how it was arrived at. Services return
 * Metrics rather than bare ints so the view never has to guess how to label a
 * value.
 */
final class Metric implements \JsonSerializable
{
    public function __construct(
        public readonly string $display,
        public readonly Provenance $provenance,
        public readonly ?string $note = null,
        public readonly int|float|null $raw = null,
    ) {
    }

    public static function money(Money $money, Provenance $provenance, ?string $note = null): self
    {
        return new self($money->format(), $provenance, $note, $money->minor);
    }

    public static function moneyCompact(Money $money, Provenance $provenance, ?string $note = null): self
    {
        return new self($money->compact(), $provenance, $note, $money->minor);
    }

    public static function number(int|float $value, Provenance $provenance, ?string $note = null, string $suffix = ''): self
    {
        $display = is_float($value) ? rtrim(rtrim(number_format($value, 1, '.', ','), '0'), '.') : number_format($value);
        return new self($display . $suffix, $provenance, $note, $value);
    }

    public static function text(string $display, Provenance $provenance, ?string $note = null): self
    {
        return new self($display, $provenance, $note);
    }

    public static function unavailable(string $note): self
    {
        return new self('n/a', Provenance::Unavailable, $note);
    }

    public function isUnavailable(): bool
    {
        return $this->provenance === Provenance::Unavailable;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'display'    => $this->display,
            'provenance' => $this->provenance->value,
            'label'      => $this->provenance->label(),
            'note'       => $this->note,
            'raw'        => $this->raw,
        ];
    }
}
