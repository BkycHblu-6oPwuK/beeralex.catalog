<?php
declare(strict_types=1);

namespace Beeralex\Catalog\Repository;

use Beeralex\Core\Repository\Repository;
use Beeralex\Core\Repository\SortingRepositoryContract;

class EmptySortingRepository extends Repository implements SortingRepositoryContract
{
    public function __construct() {}

    public function all(
        array $filter = [],
        array $select = ['*'],
        array $order = [],
        int $cacheTtl = 0,
        bool $cacheJoins = false
    ): array {
        return [];
    }

    public function one(
        array $filter = [],
        array $select = ['*'],
        int $cacheTtl = 0,
        bool $cacheJoins = false
    ): ?array {
        $allSortings = $this->all($filter, $select, [], $cacheTtl, $cacheJoins);
        return $allSortings[0] ?? null;
    }

    public function getDefaultSorting(
        array $select = ['*'],
        int $cacheTtl = 0,
        bool $cacheJoins = false
    ): ?array {
        return $this->one(['ID' => 1], $select, $cacheTtl, $cacheJoins);
    }
}