<?php

declare(strict_types=1);

namespace Beeralex\Catalog\Location;

/**
 * Административный уровень адреса (регион, район, город, населённый пункт).
 */
final class LocationArea
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $type = null,
        public readonly ?string $typeFull = null,
        public readonly ?string $fiasId = null,
        public readonly ?string $kladrId = null,
        public readonly ?string $isoCode = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
