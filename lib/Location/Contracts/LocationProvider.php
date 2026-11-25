<?php

declare(strict_types=1);

namespace Beeralex\Catalog\Location\Contracts;

use Beeralex\Catalog\Location\LocationItem;
use Beeralex\Catalog\Location\LocationSearchException;

/**
 * Источник локаций. Новый провайдер — это класс с двумя методами и строка в LocationSearchService.
 */
interface LocationProvider
{
    /** Код провайдера: bitrix, dadata. */
    public function code(): string;

    /**
     * @return LocationItem[]
     * @throws LocationSearchException если провайдер недоступен или не настроен
     */
    public function search(string $query, int $limit): array;
}
