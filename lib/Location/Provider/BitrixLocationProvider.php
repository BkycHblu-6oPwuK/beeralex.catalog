<?php

declare(strict_types=1);

namespace Beeralex\Catalog\Location\Provider;

use Beeralex\Catalog\Location\Contracts\LocationProvider;
use Beeralex\Catalog\Location\LocationItem;
use Beeralex\Core\Enum\Locations;
use Beeralex\Core\Service\LocationService;

/**
 * Стандартные локации Bitrix через Beeralex\Core\Service\LocationService::find().
 * Тип, id и код есть сразу; координат и ФИАС в этом источнике нет.
 */
class BitrixLocationProvider implements LocationProvider
{
    public function __construct(protected readonly LocationService $locationService) {}

    public function code(): string
    {
        return 'bitrix';
    }

    public function search(string $query, int $limit): array
    {
        $rows = $this->locationService->find($query, $limit, 0);
        $typeCodes = array_flip($this->locationService->getGroupMap());

        $items = [];
        $addedCodes = [];
        foreach ((array)$rows as $row) {
            $code = isset($row['CODE']) && $row['CODE'] !== '' ? (string)$row['CODE'] : null;
            if ($code !== null && isset($addedCodes[$code])) {
                continue;
            }
            $item = $this->toItem((array)$row, $typeCodes);
            if ($item === null) {
                continue;
            }

            $items[] = $item;
            if ($code !== null) {
                $addedCodes[$code] = true;
            }
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $typeCodes ID типа => код типа
     */
    protected function toItem(array $row, array $typeCodes): ?LocationItem
    {
        $name = trim((string)($row['DISPLAY'] ?? ''));
        if ($name === '') {
            return null;
        }

        $path = [];
        foreach ((array)($row['PATH'] ?? []) as $node) {
            $title = is_array($node) ? trim((string)($node['DISPLAY'] ?? '')) : '';
            if ($title !== '') {
                $path[] = $title;
            }
        }
        $path = array_reverse($path);

        $typeCode = $typeCodes[(int)($row['TYPE_ID'] ?? 0)] ?? null;

        return new LocationItem(
            provider: $this->code(),
            name: $name,
            fullName: implode(', ', [...$path, $name]),
            id: isset($row['VALUE']) ? (string)$row['VALUE'] : null,
            code: isset($row['CODE']) && $row['CODE'] !== '' ? (string)$row['CODE'] : null,
            type: $this->normalizeType($typeCode),
            path: $path,
        );
    }

    protected function normalizeType(?string $typeCode): ?string
    {
        if ($typeCode === null) {
            return null;
        }

        foreach (Locations::cases() as $location) {
            if ($location->name === $typeCode) {
                return strtolower($location->name);
            }
        }

        return strtolower($typeCode);
    }
}
