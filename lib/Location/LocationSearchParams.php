<?php

declare(strict_types=1);

namespace Beeralex\Catalog\Location;

/**
 * Нормализованные параметры поиска.
 */
class LocationSearchParams
{
    public const BOUNDS = ['country', 'region', 'area', 'city', 'settlement', 'street', 'house'];
    public const MAX_QUERY_LENGTH = 100;
    public const MAX_COUNT = 20;

    public function __construct(
        public readonly string $provider,
        public readonly string $query,
        public readonly int $limit,
        public readonly bool $dadataFilter,
        public readonly string $fromBound,
        public readonly string $toBound,
        public readonly string $country,
        public readonly int $minQueryLength,
        public readonly int $cacheTime,
    ) {}

    /**
     * @param array<string,mixed> $p ключи как у параметров компонента: PROVIDER, QUERY, COUNT, DADATA_FILTER, ...
     */
    public static function fromArray(array $p): self
    {
        $provider = strtolower(trim((string)($p['PROVIDER'] ?? '')));
        $country = strtoupper(trim((string)($p['DADATA_COUNTRY'] ?? '')));

        return new self(
            provider: $provider,
            query: static::normalizeQuery((string)($p['QUERY'] ?? '')),
            limit: max(1, min(static::MAX_COUNT, (int)($p['COUNT'] ?? 7) ?: 7)),
            dadataFilter: static::flag($p['DADATA_FILTER'] ?? true),
            fromBound: static::bound($p['DADATA_FROM_BOUND'] ?? null, 'city'),
            toBound: static::bound($p['DADATA_TO_BOUND'] ?? null, 'settlement'),
            country: preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : '',
            minQueryLength: max(1, (int)($p['MIN_QUERY_LENGTH'] ?? 2) ?: 2),
            cacheTime: max(0, (int)($p['CACHE_TIME'] ?? 3600)),
        );
    }

    public function hasQuery(): bool
    {
        return mb_strlen($this->query) >= $this->minQueryLength;
    }

    /** Ключ кеша: всё, что влияет на результат. */
    public function cacheKey(): string
    {
        return 'loc_' . md5(json_encode([
            $this->provider, mb_strtolower($this->query), $this->limit,
            $this->dadataFilter, $this->fromBound, $this->toBound, $this->country,
        ], JSON_UNESCAPED_UNICODE));
    }

    private static function normalizeQuery(string $query): string
    {
        $query = trim((string)preg_replace('/\s+/u', ' ', strip_tags($query)));

        return mb_substr($query, 0, static::MAX_QUERY_LENGTH);
    }

    private static function flag(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'Y', 'y', 'true'], true);
    }

    private static function bound(mixed $value, string $default): string
    {
        $value = strtolower(trim((string)$value));

        return in_array($value, static::BOUNDS, true) ? $value : $default;
    }
}
