<?php
declare(strict_types=1);

namespace Beeralex\Catalog\Location;

use Beeralex\Catalog\Dto\LocationDTO;
use Beeralex\Catalog\Location\Contracts\BitrixLocationResolverContract;
use Beeralex\Catalog\Location\Contracts\LocationDataParserContract;
use Beeralex\Catalog\Location\Service\Parser\DadataLocationParser;
use Beeralex\Core\Traits\Cacheable;
use Bitrix\Main\Web\Json;
use Beeralex\Core\Dto\CacheSettingsDTO;
use Beeralex\Core\Enum\Locations;
use Beeralex\Core\Service\LocationService;
use Dadata\DadataClient;

use function Beeralex\Catalog\log;

class BitrixLocationResolver implements BitrixLocationResolverContract
{
    use Cacheable;

    public function __construct(
        protected readonly LocationService $locationService,
    ) {}

    /**
     * Возвращает клиент для получения подсказок.
     * Переопределите в наследнике для использования другого клиента/сервиса.
     *
     * @return DadataClient
     */
    protected function getClient(): object
    {
        return service(DadataClient::class);
    }

    /**
     * Возвращает парсер ответа клиента в структуру вариантов.
     * @return DadataLocationParser|null
     */
    protected function getParser(): ?LocationDataParserContract
    {
        return new DadataLocationParser();
    }

    public function getBitrixLocationByAddress(string|LocationDTO $location): ?string
    {
        $cacheKey = is_string($location) ? $location : Json::encode($location);
        $cacheSettings = new CacheSettingsDTO(3600000, md5($cacheKey), 'beeralex.catalog/location');
        try {
            return $this->getCached($cacheSettings, function () use ($location) {
                $variants = $this->getVariantsFromLocation($location);
                if ($variants === null) {
                    return null;
                }
                [$settlementVariants, $cityVariants, $areaVariants, $regionVariants] = $variants;

                $groupsMap     = $this->locationService->getGroupMap();
                $regionType    = $groupsMap[Locations::REGION->name];
                $subregionType = $groupsMap[Locations::SUBREGION->name];

                $groups = [
                    $groupsMap[Locations::VILLAGE->name] => $settlementVariants,
                    $groupsMap[Locations::CITY->name]    => $cityVariants,
                    $subregionType                        => $areaVariants,
                    $regionType                            => $regionVariants,
                ];

                $final = $this->findBestLocation($groups, $regionVariants, $areaVariants, $regionType, $subregionType);
                return $final['CODE'] ?? null;
            });
        } catch (\Throwable $e) {
            log("BitrixLocationResolver error: " . $e->getMessage());
            return null;
        }
    }

    protected function getVariantsFromLocation(string|LocationDTO $location): ?array
    {
        $parser = $this->getParser();
        if ($parser === null) {
            return null;
        }
        $suggestions = $this->fetchSuggestions($location);
        if ($suggestions === null) {
            return null;
        }
        return $parser->parse($suggestions);
    }

    protected function fetchSuggestions(string|LocationDTO $location): ?array
    {
        $client = $this->getClient();
        if (is_string($location)) {
            return $client->suggest('address', $location, 5);
        }
        if ($location->latitude && $location->longitude) {
            return $client->geolocate('address', $location->latitude, $location->longitude, 100, 3);
        }
        return null;
    }

    /**
     * Идёт от самого специфичного уровня (settlement) к самому общему (region).
     * На каждом уровне ищет кандидата и сразу пытается подтвердить его
     * регионом/областью из Dadata. Первый подтверждённый — сразу побеждает.
     * Если ни один уровень не подтверждён — возвращает кандидата
     * с самого специфичного уровня, где хоть что-то нашлось (fallback).
     */
    private function findBestLocation(
        array $groups,
        array $regionVariants,
        array $areaVariants,
        int $regionType,
        int $subregionType
    ): ?array {
        $fallback = null;

        foreach ($groups as $expectedType => $nameVariants) {
            if (empty($nameVariants)) {
                continue;
            }

            $items = $this->searchInBitrix($nameVariants);
            if (empty($items)) {
                continue;
            }

            $result = $this->resolveLevel(
                $items,
                $expectedType,
                $nameVariants,
                $expectedType === $regionType ? [] : $regionVariants,
                $expectedType === $subregionType ? [] : $areaVariants,
            );

            if ($result['candidate'] === null) {
                continue;
            }
            if ($result['confirmed']) {
                return $result['candidate'];
            }

            $fallback ??= $result['candidate'];
        }

        return $fallback;
    }

    /**
     * Находит кандидата на данном уровне и определяет, подтверждён ли он
     * регионом/областью. Если проверять нечем (пустые $regionVariants
     * и $areaVariants — обычно потому что это сам регион/область,
     * либо Dadata их не вернула) — кандидат считается подтверждённым
     * по имени.
     *
     * @return array{candidate: ?array, confirmed: bool}
     */
    private function resolveLevel(
        array $items,
        int $expectedType,
        array $nameVariants,
        array $regionVariants,
        array $areaVariants
    ): array {
        $named = $this->filterByName($this->collectTypedNodes($items, $expectedType), $nameVariants);
        if (empty($named)) {
            return ['candidate' => null, 'confirmed' => false];
        }

        $hasCheckData = !empty($regionVariants) || !empty($areaVariants);
        $matched = $hasCheckData ? $this->matchRegionAndArea($named, $regionVariants, $areaVariants) : null;

        return [
            'candidate' => $matched ?? reset($named),
            'confirmed' => $matched !== null || !$hasCheckData,
        ];
    }

    private function filterByName(array $nodes, array $nameVariants): array
    {
        $nameLower = array_values(array_filter(
            array_map(fn($v) => mb_strtolower(trim($v)), $nameVariants),
            fn($v) => $v !== ''
        ));
        if (empty($nameLower)) {
            return [];
        }

        $exact = array_values(array_filter(
            $nodes,
            fn($n) => in_array(mb_strtolower(trim($n['DISPLAY'] ?? '')), $nameLower, true)
        ));
        if (!empty($exact)) {
            return $exact;
        }

        return array_values(array_filter($nodes, function ($n) use ($nameLower) {
            $display = mb_strtolower(trim($n['DISPLAY'] ?? ''));
            if ($display === '') {
                return false;
            }
            foreach ($nameLower as $name) {
                if (str_contains($display, $name)) {
                    return true;
                }
            }
            return false;
        }));
    }

    private function searchInBitrix(array $variants): array
    {
        $collected = [];
        $seenCodes = [];

        foreach ($variants as $variant) {
            $variant = trim(mb_strtolower($variant));
            if ($variant === '') {
                continue;
            }

            foreach ($this->locationService->find($variant, 25, 0) as $item) {
                $code = $item['CODE'] ?? null;
                if ($code === null || isset($seenCodes[$code])) {
                    continue;
                }
                $seenCodes[$code] = true;
                $collected[] = $item;
            }
        }

        return $collected;
    }

    /**
     * Собирает уникальные узлы нужного типа (TYPE_ID === $expectedType) —
     * как сами найденные элементы, так и узлы из их PATH.
     */
    private function collectTypedNodes(array $items, int $expectedType): array
    {
        $nodes = [];
        $seenCodes = [];

        foreach ($items as $item) {
            $this->addTypedNode($item, null, $expectedType, $nodes, $seenCodes);

            foreach ($item['PATH'] ?? [] as $i => $pathNode) {
                $this->addTypedNode(
                    $pathNode,
                    array_slice($item['PATH'], $i + 1),
                    $expectedType,
                    $nodes,
                    $seenCodes
                );
            }
        }

        return $nodes;
    }

    private function addTypedNode(
        array $node,
        ?array $path,
        int $expectedType,
        array &$nodes,
        array &$seenCodes
    ): void {
        if ((int)($node['TYPE_ID'] ?? -1) !== $expectedType) {
            return;
        }

        $code = $node['CODE'] ?? null;
        if ($code === null || isset($seenCodes[$code])) {
            return;
        }

        $seenCodes[$code] = true;
        if ($path !== null) {
            $node['PATH'] = $path;
        }
        $nodes[] = $node;
    }

    private function matchRegionAndArea(array $items, array $regionVariants, array $areaVariants): ?array
    {
        foreach ($regionVariants as $regionVariant) {
            $regionLower = mb_strtolower(trim($regionVariant));
            if ($regionLower === '') {
                continue;
            }
            foreach ($items as $item) {
                if (!$this->pathContains($item, $regionLower)) {
                    continue;
                }
                if (!empty($areaVariants) && !$this->matchArea($item, $areaVariants)) {
                    continue;
                }
                return $item;
            }
        }
        return null;
    }

    private function matchArea(array $item, array $areaVariants): bool
    {
        foreach ($areaVariants as $areaVariant) {
            $areaLower = mb_strtolower(trim($areaVariant));
            if ($areaLower === '') {
                continue;
            }
            if ($this->pathContains($item, $areaLower)) {
                return true;
            }
        }
        return false;
    }

    private function pathContains(array $item, string $needleLower): bool
    {
        foreach ($item['PATH'] ?? [] as $path) {
            if (isset($path['DISPLAY']) && str_contains(mb_strtolower($path['DISPLAY']), $needleLower)) {
                return true;
            }
        }
        return false;
    }
}
