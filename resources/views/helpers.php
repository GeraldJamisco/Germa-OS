<?php

declare(strict_types=1);

use Germa\Support\Hours;
use Germa\Support\Html;
use Germa\Support\Metric;

/** Escape. Short name because the views are dense with it. */
function e(?string $value): string
{
    return Html::e($value);
}

/**
 * The provenance chip. Every figure on the dashboard is accompanied by one, so
 * an entered fact never looks like a model's opinion.
 */
function chip(Metric $metric, ?string $labelOverride = null): string
{
    $title = $metric->note === null ? '' : ' title="' . e($metric->note) . '"';

    return '<span class="chip ' . e($metric->provenance->modifier()) . '"' . $title . '>'
        . e($labelOverride ?? $metric->provenance->label())
        . '</span>';
}

/** A bare chip with custom text, for non-Metric contexts. */
function chip_raw(string $modifier, string $text, ?string $title = null): string
{
    return '<span class="chip chip--' . e($modifier) . '"'
        . ($title === null ? '' : ' title="' . e($title) . '"')
        . '>' . e($text) . '</span>';
}

/** Clamped width string for a meter fill. */
function bar(int|float $pct, int $floor = 2): string
{
    return Html::barWidth($pct, $floor);
}

function pct_label(int|float $value): string
{
    return round((float) $value) . '%';
}

/** Hours, formatted once so every screen agrees on what "12h" looks like. */
function hours_label(int|float $hours): string
{
    return Hours::formatOrNone((float) $hours);
}
