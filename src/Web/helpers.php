<?php

declare(strict_types=1);

/**
 * Template helpers — loaded by layout.php.
 */

/** HTML-escape. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Human-readable bytes. */
function fmt_bytes(mixed $bytes): string
{
    if (!is_numeric($bytes)) {
        return '—';
    }

    $bytes = (float) $bytes;
    foreach (['o', 'Ko', 'Mo', 'Go', 'To'] as $unit) {
        if ($bytes < 1024) {
            return round($bytes, 1) . ' ' . $unit;
        }
        $bytes /= 1024;
    }

    return round($bytes, 1) . ' Po';
}

/** Status badge (ok / warn / error / pending / running / done). */
function badge(?string $status): string
{
    $status = $status ?? 'unknown';
    $class  = match ($status) {
        'ok', 'done'       => 'badge-ok',
        'warn', 'pending', 'running' => 'badge-warn',
        'error'            => 'badge-error',
        default            => 'badge-muted',
    };

    return '<span class="badge ' . $class . '">' . e($status) . '</span>';
}

/** Lighthouse-style score badge: green >= 90, orange >= 50, red below. */
function badge_score(int $score): string
{
    $class = match (true) {
        $score >= 90 => 'badge-ok',
        $score >= 50 => 'badge-warn',
        default      => 'badge-error',
    };

    return '<span class="badge ' . $class . '">' . $score . '</span>';
}

/** Pretty-printed JSON inside a collapsible block. */
function json_details(string $label, mixed $data, bool $open = false): string
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return '<details' . ($open ? ' open' : '') . '><summary>' . e($label) . '</summary>'
        . '<pre>' . e($json) . '</pre></details>';
}
