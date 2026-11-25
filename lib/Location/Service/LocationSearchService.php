<?php

declare(strict_types=1);

namespace Beeralex\Catalog\Location\Service;

use Beeralex\Catalog\Location\Contracts\LocationProvider;
use Beeralex\Catalog\Location\LocationItem;
use Beeralex\Catalog\Location\LocationSearchException;
use Beeralex\Catalog\Location\LocationSearchParams;
use Beeralex\Core\Traits\Cacheable;
use function Beeralex\Catalog\log;

/**
 * Поиск локаций: выбор провайдера и кеш.
 */
class LocationSearchService
{
    use Cacheable;

    /**
     * @param array<string,callable(LocationSearchParams):LocationProvider> $providerFactories код => фабрика
     */
    public function __construct(
        protected readonly array $providerFactories,
    ) {}

    /**
     * @return array{provider:string, items:array<int,array<string,mixed>>}
     * @throws LocationSearchException
     */
    public function search(LocationSearchParams $params): array
    {
        if (!$params->hasQuery()) {
            return ['provider' => $params->provider, 'items' => []];
        }

        $factory = $this->providerFactories[$params->provider] ?? null;
        if ($factory === null) {
            throw new LocationSearchException('Неизвестный провайдер локаций');
        }

        return $this->getCached(
            $this->getCacheSettingsDto(
                time: $params->cacheTime,
                key: $params->cacheKey(),
                dir: '/beeralex/location',
            ),
            static function () use ($factory, $params): array {
                /** @var LocationProvider $provider */
                $provider = $factory($params);
                $items = $provider->search($params->query, $params->limit);

                return [
                    'provider' => $provider->code(),
                    'items'    => array_map(static fn(LocationItem $item) => $item->toArray(), $items),
                ];
            },
        );
    }

    /**
     * То же, что search(), но без исключений: ошибки уходят в errors, внутренние детали — в лог.
     *
     * @return array{provider:string, items:array<int,array<string,mixed>>, errors:array<int,array{code:string,message:string}>}
     */
    public function safeSearch(LocationSearchParams $params): array
    {
        try {
            return $this->search($params) + ['errors' => []];
        } catch (LocationSearchException $e) {
            $error = ['code' => 'LOCATION_UNAVAILABLE', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            log('beeralex_location: ' . $e->getMessage());
            $error = ['code' => 'LOCATION_SEARCH_FAILED', 'message' => 'Не удалось выполнить поиск локаций'];
        }

        return ['provider' => $params->provider, 'items' => [], 'errors' => [$error]];
    }

    /**
     * Ключи результата в UPPER_SNAKE_CASE — привычный для Bitrix вид arResult.
     * Значения не меняются, списки (path, items) сохраняют порядок.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function toResult(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $key = strtoupper((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
            }
            $result[$key] = is_array($value) ? static::toResult($value) : $value;
        }

        return $result;
    }
}
