<?php

namespace Beeralex\Catalog\Location\Contracts;

use Beeralex\Catalog\Dto\LocationDTO;

interface BitrixLocationResolverContract
{
    /**
     * Получить местоположение в Битрикс (код местоположения) по адресу или координатам
     * для хорошего поиска нужно сделать импорт местоположений до сёл и передавать в качестве строки адреса полное unrestricted_value значение
     * Лучше использовать когда хотите отказаться от локаций битрикс, но при этом сохранить заполнение местоположения в созданном заказе
     */
    public function getBitrixLocationByAddress(string|LocationDTO $location): ?string;
}
