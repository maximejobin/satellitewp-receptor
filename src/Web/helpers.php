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

/** Severity badge (C/E/M/I) using the report's red/yellow/blue scheme. */
function badge_severity(string $severity, string $label): string
{
    $class = match ($severity) {
        'C'     => 'badge-error badge-critical',
        'E'     => 'badge-error',
        'M'     => 'badge-warn',
        default => 'badge-muted',
    };

    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

/**
 * A label/value card. $rows is pre-rendered <tr> HTML from field()/field_raw().
 * Inspired by the section-per-topic layout of the existing Xtract tool, styled
 * with this project's own CSS (no Bootstrap/Metronic).
 */
function section(string $title, string $rows, string $badge = ''): string
{
    if (trim($rows) === '') {
        return '';
    }

    return '<section class="card info-card"><h3>' . e($title) . ($badge !== '' ? ' ' . $badge : '')
        . '</h3><table class="kv"><tbody>' . $rows . '</tbody></table></section>';
}

/**
 * One label/value row. $status colours the value: ok (green), warn (orange),
 * error (red), or null (default). Booleans are rendered oui/non.
 */
function field(string $label, mixed $value, ?string $status = null): string
{
    if (is_bool($value)) {
        $value = $value ? 'oui' : 'non';
    }
    $display = ($value === null || $value === '') ? '—' : e($value);

    return field_raw($label, $display, $status);
}

/** Like field() but the value is trusted, pre-rendered HTML. */
function field_raw(string $label, string $html, ?string $status = null): string
{
    $cls = match ($status) {
        'ok'    => 'val-ok',
        'warn'  => 'val-warn',
        'error' => 'val-error',
        default => '',
    };

    return '<tr><th>' . e($label) . '</th><td class="' . $cls . '">' . $html . '</td></tr>';
}

/**
 * Inline EOL annotation from EndOfLife::eolStatus() — "(fin de vie : DATE)" in
 * red when past, "(supporté jusqu'au DATE)" muted otherwise.
 *
 * @param array{0: bool, 1: string|null}|null $status
 */
function eol_annotation(?array $status): string
{
    if ($status === null || $status[1] === null) {
        return '';
    }

    [$isEol, $date] = $status;
    $cls   = $isEol ? 'val-error' : 'val-muted';
    $label = $isEol ? 'fin de vie' : 'supporté jusqu\'au';

    return ' <span class="' . $cls . '">(' . $label . ' : ' . e($date) . ')</span>';
}

/** Compact comma list with a "+N" overflow. */
function fmt_list(mixed $items, int $max = 12): string
{
    if (!is_array($items) || $items === []) {
        return '—';
    }

    $shown = array_slice($items, 0, $max);
    $more  = count($items) - count($shown);

    return e(implode(', ', array_map('strval', $shown))) . ($more > 0 ? " <span class=\"val-muted\">+{$more}</span>" : '');
}

/** Pretty-printed JSON inside a collapsible block. */
function json_details(string $label, mixed $data, bool $open = false): string
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return '<details' . ($open ? ' open' : '') . '><summary>' . e($label) . '</summary>'
        . '<pre>' . e($json) . '</pre></details>';
}
