<?php

declare(strict_types=1);

namespace Beeralex\Catalog\Location\Service\Parser;

use Beeralex\Catalog\Location\Contracts\LocationDataParserContract;

class DadataLocationParser implements LocationDataParserContract
{
    public function parse(array $suggestions, ?string $location = null): array
    {
        $valid = array_values(array_filter($suggestions, fn($s) => isset($s['data'])));

        $needle = $location !== null ? $this->normalize($location) : '';
        if ($needle !== '') {
            foreach ($valid as $s) {
                if ($this->matchesQuery($s['data'], $needle)) {
                    $result = $this->build($s['data']);
                    if ($this->isNotEmpty($result)) {
                        return $result;
                    }
                }
            }
        }

        foreach ($valid as $s) {
            $result = $this->build($s['data']);
            if ($this->isNotEmpty($result)) {
                return $result;
            }
        }

        return [[], [], [], []];
    }

    private function build(array $data): array
    {
        return [
            $this->makeName($data['settlement'] ?? null, null, $data['settlement_type_full'] ?? null),
            $this->makeName($data['city'] ?? null, null, $data['city_type_full'] ?? null),
            $this->makeName($data['area'] ?? null, $data['area_type'] ?? null, $data['area_type_full'] ?? null),
            $this->makeName($data['region'] ?? null, $data['region_type'] ?? null, $data['region_type_full'] ?? null),
        ];
    }

    private function isNotEmpty(array $variants): bool
    {
        return !empty($variants[0]) || !empty($variants[1]) || !empty($variants[2]) || !empty($variants[3]);
    }

    /**
     * Совпадает ли запрос с самым специфичным уровнем подсказки.
     * Если в подсказке есть улица/дом — она точнее запроса, не считаем совпадением.
     */
    private function matchesQuery(array $data, string $needle): bool
    {
        if (!empty($data['street']) || !empty($data['house'])) {
            return false;
        }

        $levels = [
            ['settlement', 'settlement_with_type', 'settlement_type_full'],
            ['city',       'city_with_type',       'city_type_full'],
            ['area',       'area_with_type',       'area_type_full'],
            ['region',     'region_with_type',     'region_type_full'],
        ];

        foreach ($levels as [$nameKey, $withTypeKey, $typeFullKey]) {
            $name = trim((string)($data[$nameKey] ?? ''));
            if ($name === '') {
                continue;
            }

            $candidates = [
                $name,
                (string)($data[$withTypeKey] ?? ''),
                $name . ' ' . ($data[$typeFullKey] ?? ''),
                ($data[$typeFullKey] ?? '') . ' ' . $name,
            ];

            foreach ($candidates as $c) {
                if ($c !== '' && $this->normalize($c) === $needle) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = str_replace('ё', 'е', $s);
        return preg_replace('/\s+/u', ' ', $s);
    }

    private function makeName(?string $base, ?string $type = null, ?string $typeFull = null): array
    {
        $base = $base !== null ? trim($base) : null;
        if (!$base) {
            return [];
        }

        return array_values(array_unique(array_filter([
            $base,
            $typeFull ? trim("$base $typeFull") : null,
            $type ? trim("$base $type") : null,
        ])));
    }
}
