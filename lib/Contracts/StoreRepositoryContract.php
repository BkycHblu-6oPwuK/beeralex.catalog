<?php

namespace Beeralex\Catalog\Contracts;

use Beeralex\Core\Repository\RepositoryContract;

interface StoreRepositoryContract extends RepositoryContract
{
    public function getPickPoints(?array $storeIds = null): array;
    public function getAllIds(): array;
}
