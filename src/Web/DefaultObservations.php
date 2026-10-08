<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Web;

use SatelliteWP\Manager\Rules\Translator;

/**
 * The report contract's 'default_observations': lines every extraction starts
 * with until an analyst saves its observations. A default line keeps a
 * 'template' key and no text of its own, so it renders in whichever language
 * the report is built in; once its text is edited it becomes a plain line.
 */
final class DefaultObservations
{
    /**
     * The stored items, or the contract's defaults while observations.json
     * was never written (an empty stored list stays empty).
     *
     * @param array<string, mixed>|null $stored observations.json
     * @param array<string, mixed>      $contract
     * @return list<mixed>
     */
    public static function items(?array $stored, array $contract): array
    {
        return $stored !== null ? array_values((array) ($stored['items'] ?? [])) : self::defaults($contract);
    }

    /**
     * @param array<string, mixed> $contract
     * @return list<array<string, mixed>>
     */
    public static function defaults(array $contract): array
    {
        $items = [];
        foreach ((array) ($contract['default_observations'] ?? []) as $d) {
            if (!is_array($d) || !is_string($d['key'] ?? null)) {
                continue;
            }
            $items[] = [
                'id'          => 'default-' . $d['key'],
                'section'     => (string) ($d['section'] ?? ''),
                'color'       => (string) ($d['color'] ?? ''),
                'title'       => '',
                'description' => '',
                'include'     => true,
                'template'    => $d['key'],
            ];
        }

        return $items;
    }

    /**
     * Fills the title and description of template items in $t's language.
     *
     * @param list<mixed> $items
     * @return list<mixed>
     */
    public static function resolve(array $items, Translator $t): array
    {
        return array_map(static function (mixed $item) use ($t): mixed {
            if (!is_array($item) || !is_string($item['template'] ?? null)) {
                return $item;
            }
            $key = $item['template'];

            return [
                'title'       => $t->report("observation_{$key}_title"),
                'description' => $t->report("observation_{$key}_description"),
            ] + $item;
        }, $items);
    }
}
