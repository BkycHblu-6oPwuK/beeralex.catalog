<?php

declare(strict_types=1);

namespace Beeralex\Catalog\Location;

/**
 * Ожидаемая ошибка поиска (провайдер недоступен, неверные настройки). Текст безопасно показывать клиенту.
 */
class LocationSearchException extends \RuntimeException
{
}
