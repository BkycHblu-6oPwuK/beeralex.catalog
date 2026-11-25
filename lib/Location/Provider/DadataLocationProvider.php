<?php

declare(strict_types=1);

namespace Beeralex\Catalog\Location\Provider;

use Beeralex\Catalog\Location\Contracts\LocationProvider;
use Beeralex\Catalog\Location\LocationItem;
use Beeralex\Catalog\Location\LocationArea;

/**
 * Подсказки DaData по адресу, ограниченные городами и населёнными пунктами.
 * Возвращает больше данных, чем локации Bitrix: ФИАС-GUID, координаты, индекс, часовой пояс, ОКАТО/ОКТМО.
 */
class DadataLocationProvider implements LocationProvider
{
    /** Максимум подсказок за один запрос к DaData. */
    protected const MAX_COUNT = 20;

    /**
     * @param object $client Dadata\DadataClient
     * @param ?callable(array):bool $filter отсев лишних подсказок (территории, СНТ и т.п.); null — без отсева
     * @param string $country ISO-код страны для ограничения поиска, пусто — без ограничения
     */
    public function __construct(
        protected readonly object $client,
        protected readonly mixed $filter = null,
        protected readonly string $fromBound = 'city',
        protected readonly string $toBound = 'settlement',
        protected readonly string $country = '',
    ) {}

    public function code(): string
    {
        return 'dadata';
    }

    public function search(string $query, int $limit): array
    {
        // часть подсказок отсеет фильтр, поэтому просим с запасом
        $count = $this->filter !== null ? min(static::MAX_COUNT, $limit * 2) : min(static::MAX_COUNT, $limit);

        $suggestions = $this->client->suggest('address', $query, $count, $this->kwargs());

        $items = [];
        foreach ((array)$suggestions as $suggestion) {
            if (!is_array($suggestion) || !isset($suggestion['data'])) {
                continue;
            }
            if ($this->filter !== null && is_callable($this->filter) && !($this->filter)($suggestion)) {
                continue;
            }

            $items[] = $this->toItem($suggestion);
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /** @return array<string,mixed> */
    protected function kwargs(): array
    {
        $kwargs = [
            'from_bound' => ['value' => $this->fromBound],
            'to_bound'   => ['value' => $this->toBound],
        ];
        if ($this->country !== '') {
            $kwargs['locations'] = [['country_iso_code' => $this->country]];
        }

        return $kwargs;
    }

    /** @param array<string,mixed> $s подсказка DaData: value, unrestricted_value, data */
    protected function toItem(array $s): LocationItem
    {
        $d = (array)$s['data'];

        $settlement = $this->area($d, 'settlement');
        $city = $this->area($d, 'city');
        $area = $this->area($d, 'area');
        $region = $this->area($d, 'region', true);

        // самый мелкий из найденных уровней и есть сама локация
        $own = $settlement ?? $city ?? $area ?? $region;
        $type = match (true) {
            $settlement !== null => 'village',
            $city !== null       => 'city',
            $area !== null       => 'subregion',
            $region !== null     => 'region',
            default              => null,
        };

        $name = $own?->name ?? trim((string)($s['value'] ?? ''));

        return new LocationItem(
            provider: $this->code(),
            name: $name,
            fullName: trim((string)($s['value'] ?? $name)),
            unrestrictedValue: $this->str($s['unrestricted_value'] ?? null),
            type: $type,
            path: $this->path($d, $own, $settlement !== null),
            guid: $this->str($d['settlement_fias_id'] ?? null)
                ?? $this->str($d['city_fias_id'] ?? null)
                ?? $this->str($d['fias_id'] ?? null),
            fiasId: $this->str($d['fias_id'] ?? null),
            kladrId: $this->str($d['kladr_id'] ?? null),
            fiasLevel: $this->int($d['fias_level'] ?? null),
            postalCode: $this->str($d['postal_code'] ?? null),
            countryIso: $this->str($d['country_iso_code'] ?? null),
            federalDistrict: $this->str($d['federal_district'] ?? null),
            region: $region,
            area: $area,
            city: $city,
            settlement: $settlement,
            latitude: $this->float($d['geo_lat'] ?? null),
            longitude: $this->float($d['geo_lon'] ?? null),
            timezone: $this->str($d['timezone'] ?? null),
            okato: $this->str($d['okato'] ?? null),
            oktmo: $this->str($d['oktmo'] ?? null),
            geonameId: $this->str($d['geoname_id'] ?? null),
            capitalMarker: $this->int($d['capital_marker'] ?? null),
        );
    }

    /** @param array<string,mixed> $d */
    protected function area(array $d, string $level, bool $withIso = false): ?LocationArea
    {
        $name = $this->str($d[$level] ?? null);
        if ($name === null) {
            return null;
        }

        return new LocationArea(
            name: $name,
            type: $this->str($d[$level . '_type'] ?? null),
            typeFull: $this->str($d[$level . '_type_full'] ?? null),
            fiasId: $this->str($d[$level . '_fias_id'] ?? null),
            kladrId: $this->str($d[$level . '_kladr_id'] ?? null),
            isoCode: $withIso ? $this->str($d['region_iso_code'] ?? null) : null,
        );
    }

    /**
     * Вышестоящие уровни с типом («Московская обл», «г Мытищи») от крупного к мелкому, без самой локации.
     *
     * @param array<string,mixed> $d
     * @return string[]
     */
    protected function path(array $d, ?LocationArea $own, bool $isSettlement): array
    {
        $levels = [$d['region_with_type'] ?? null, $d['area_with_type'] ?? null];
        if ($isSettlement) {
            $levels[] = $d['city_with_type'] ?? null;
        }

        $ownWithType = match (true) {
            $own === null => null,
            $isSettlement => $d['settlement_with_type'] ?? null,
            ($d['city'] ?? null) !== null => $d['city_with_type'] ?? null,
            ($d['area'] ?? null) !== null => $d['area_with_type'] ?? null,
            default => $d['region_with_type'] ?? null,
        };

        $path = [];
        foreach ($levels as $level) {
            $level = $this->str($level);
            if ($level !== null && $level !== $ownWithType && !in_array($level, $path, true)) {
                $path[] = $level;
            }
        }

        return $path;
    }

    protected function str(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string)$value) !== '' ? trim((string)$value) : null;
    }

    protected function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }

    protected function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }
}
