<?php
declare(strict_types=1);

namespace Beeralex\Catalog\Location;

/**
 * Фильтр подсказок Дадаты для выбора города для ru.
 *
 * Дадата отдаёт в поле settlement не только настоящие населённые пункты
 * (сёла, деревни, хутора и т.п.), но и «территории»: автодороги, трассы,
 * СНТ, ГСК, промзоны, дачные посёлки и пр. Отфильтровать их запросом нельзя —
 * для Дадаты это валидные объекты уровня settlement.
 *
 */
class LocationSuggestionFilter
{
    protected const BLOCKED_SETTLEMENT_TYPES = [
        'территория',
        'территория снт',
        'территория днт',
        'территория онт',
        'территория гск',
        'территория ждт',
        'садовое товарищество',
        'садоводческое товарищество',
        'дачное товарищество',
        'дачное некоммерческое партнерство',
        'некоммерческое партнерство',
        'промышленная зона',
        'промзона',
        'гаражно-строительный кооператив',
        'territory',
    ];

    /**
     * Проверяет, что подсказка — настоящий населённый пункт, а не «территория».
     */
    public static function isValidCity(array $item): bool
    {
        $data = $item['data'] ?? [];

        if (empty($data['settlement'])) {
            return true;
        }

        return !static::isBlockedSettlementType($data['settlement_type_full'] ?? '');
    }

    /**
     * Возвращает название населённого пункта из подсказки (city или settlement).
     */
    public static function extractCityName(array $item): string
    {
        $data = $item['data'] ?? [];

        return (string)($data['city'] ?? $data['settlement'] ?? '');
    }

    protected static function isBlockedSettlementType(string $settlementTypeFull): bool
    {
        $normalized = mb_strtolower(trim($settlementTypeFull));

        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, static::BLOCKED_SETTLEMENT_TYPES, true);
    }
}
