<?php

declare(strict_types=1);

namespace Beeralex\Catalog\Location;

/**
 * Найденная локация в едином виде независимо от провайдера.
 *
 * Стандартные локации Bitrix дают id, code, name, type и path. DaData добавляет guid (ФИАС),
 * координаты, индекс, часовой пояс, коды ОКАТО/ОКТМО и разбор адреса по уровням.
 */
final class LocationItem
{
    /**
     * @param string $provider bitrix|dadata
     * @param ?string $id ID локации Bitrix (для DaData — null)
     * @param ?string $code код локации Bitrix (только у провайдера bitrix)
     * @param ?string $unrestrictedValue полный адрес одной строкой (DaData)
     * @param ?string $type country|region|subregion|city|village
     * @param string[] $path вышестоящие уровни от крупного к мелкому, без самой локации
     * @param ?string $guid ФИАС-идентификатор населённого пункта (для ApiShip это cityGuid)
     * @param ?string $fiasId ФИАС-идентификатор самого найденного объекта
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $name,
        public readonly string $fullName,
        public readonly ?string $id = null,
        public readonly ?string $code = null,
        public readonly ?string $unrestrictedValue = null,
        public readonly ?string $type = null,
        public readonly array $path = [],
        public readonly ?string $guid = null,
        public readonly ?string $fiasId = null,
        public readonly ?string $kladrId = null,
        public readonly ?int $fiasLevel = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $countryIso = null,
        public readonly ?string $federalDistrict = null,
        public readonly ?LocationArea $region = null,
        public readonly ?LocationArea $area = null,
        public readonly ?LocationArea $city = null,
        public readonly ?LocationArea $settlement = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?string $timezone = null,
        public readonly ?string $okato = null,
        public readonly ?string $oktmo = null,
        public readonly ?string $geonameId = null,
        public readonly ?int $capitalMarker = null,
    ) {}

    /** @return array<string,mixed> camelCase, вложенные уровни — массивами */
    public function toArray(): array
    {
        $data = get_object_vars($this);
        foreach (['region', 'area', 'city', 'settlement'] as $level) {
            $data[$level] = $this->{$level}?->toArray();
        }

        return $data;
    }
}
