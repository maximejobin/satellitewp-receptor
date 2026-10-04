<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Web;

use SatelliteWP\Manager\Rules\Pastille;

/**
 * Parses an observations CSV (header: section,color,title,description,include)
 * into observation records without ids. All or nothing: any invalid row
 * yields errors and no items, so a report never gets half an import.
 */
final class ObservationsCsv
{
    public const int MAX_BYTES = 1_048_576;
    public const int MAX_ROWS  = 500;

    private const array REQUIRED = ['section', 'title'];
    private const array COLUMNS  = ['section', 'color', 'title', 'description', 'include'];

    private const array INCLUDE_TRUE  = ['', '1', 'yes', 'y', 'true', 'oui', 'o', 'x'];
    private const array INCLUDE_FALSE = ['0', 'no', 'n', 'false', 'non'];

    /**
     * @param list<string> $sections the report contract's observation fields
     * @return array{items: list<array{section: string, color: string, title: string, description: string, include: bool}>, errors: list<string>}
     */
    public static function parse(string $raw, array $sections): array
    {
        if (strlen($raw) > self::MAX_BYTES) {
            return self::fail('The file is larger than 1 MB.');
        }
        $raw = str_starts_with($raw, "\u{FEFF}") ? substr($raw, 3) : $raw;
        if (!mb_check_encoding($raw, 'UTF-8')) {
            return self::fail('The file is not UTF-8: save it as "CSV UTF-8".');
        }
        if (trim($raw) === '') {
            return self::fail('The file is empty.');
        }

        // Excel in a French locale writes ";" — the header line tells which one is used.
        $firstLine = strtok($raw, "\r\n");
        $delimiter = substr_count((string) $firstLine, ';') > substr_count((string) $firstLine, ',') ? ';' : ',';

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return self::fail('The file could not be read.');
        }
        fwrite($stream, $raw);
        rewind($stream);

        $header = array_map(static fn ($h): string => strtolower(trim((string) $h)), fgetcsv($stream, null, $delimiter, '"', '') ?: []);
        $errors = [];
        foreach (array_diff($header, self::COLUMNS) as $unknown) {
            $errors[] = "Unknown column \"{$unknown}\" (expected: " . implode(', ', self::COLUMNS) . ').';
        }
        foreach (array_diff(self::REQUIRED, $header) as $missing) {
            $errors[] = "Missing column \"{$missing}\".";
        }
        if (count($header) !== count(array_unique($header))) {
            $errors[] = 'A column appears twice in the header.';
        }
        if ($errors !== []) {
            fclose($stream);

            return ['items' => [], 'errors' => $errors];
        }

        $items = [];
        $line  = 1;
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                continue;
            }
            if (count($items) >= self::MAX_ROWS) {
                $errors[] = 'More than ' . self::MAX_ROWS . ' observations: split the file.';
                break;
            }
            if (count($cells) > count($header)) {
                $errors[] = "Line {$line}: more cells than columns (an unquoted \"{$delimiter}\" in a text?).";
                continue;
            }

            $row     = array_combine($header, array_pad(array_map(static fn ($c): string => trim((string) $c), $cells), count($header), ''));
            $color   = strtolower($row['color'] ?? '') ?: Pastille::Blue->value;
            $include = strtolower($row['include'] ?? '');
            $problems = [];
            if (!in_array($row['section'], $sections, true)) {
                $problems[] = "unknown section \"{$row['section']}\"";
            }
            if (!Pastille::isValid($color)) {
                $problems[] = "unknown color \"{$color}\" (" . implode(', ', Pastille::values()) . ')';
            }
            if ($row['title'] === '') {
                $problems[] = 'empty title';
            }
            if (!in_array($include, [...self::INCLUDE_TRUE, ...self::INCLUDE_FALSE], true)) {
                $problems[] = "include \"{$row['include']}\" is neither 1 nor 0";
            }
            if ($problems !== []) {
                $errors[] = "Line {$line}: " . implode('; ', $problems) . '.';
                continue;
            }

            $items[] = [
                'section'     => $row['section'],
                'color'       => $color,
                'title'       => $row['title'],
                'description' => $row['description'] ?? '',
                'include'     => in_array($include, self::INCLUDE_TRUE, true),
            ];
        }
        fclose($stream);

        if ($errors === [] && $items === []) {
            $errors[] = 'The file has a header but no observation.';
        }

        return $errors === [] ? ['items' => $items, 'errors' => []] : ['items' => [], 'errors' => $errors];
    }

    /** @return array{items: list<never>, errors: list<string>} */
    private static function fail(string $error): array
    {
        return ['items' => [], 'errors' => [$error]];
    }
}
