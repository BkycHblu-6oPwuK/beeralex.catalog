<?php
declare(strict_types=1);

namespace Beeralex\Catalog\Location\Service\Parser;

use Beeralex\Catalog\Location\Contracts\LocationDataParserContract;

class DadataLocationParser implements LocationDataParserContract
{
    public function parse(array $suggestions): array
    {
        foreach ($suggestions as $s) {
            if (!isset($s['data'])) {
                continue;
            }

            $data = $s['data'];

            $settlementVariants = $this->makeName($data['settlement'] ?? null, null, $data['settlement_type_full'] ?? null);
            $cityVariants       = $this->makeName($data['city'] ?? null, null, $data['city_type_full'] ?? null);
            $areaVariants       = $this->makeName($data['area'] ?? null, $data['area_type'] ?? null, $data['area_type_full'] ?? null);
            $regionVariants     = $this->makeName($data['region'] ?? null, $data['region_type'] ?? null, $data['region_type_full'] ?? null);

            if (!empty($settlementVariants) || !empty($cityVariants) || !empty($areaVariants) || !empty($regionVariants)) {
                return [$settlementVariants, $cityVariants, $areaVariants, $regionVariants];
            }
        }

        return [[], [], [], []];
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