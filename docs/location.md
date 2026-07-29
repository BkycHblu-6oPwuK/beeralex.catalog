# Система Location (Геолокация)

Модуль `beeralex.catalog` включает продвинутую систему определения местоположения, которая по умолчанию интегрируется с DaData (`Dadata\DadataClient`) и преобразует адреса в коды локаций Bitrix.

## Архитектура

Система построена на двух основных компонентах:

1. **Parser** - разбор данных от API и извлечение нужной информации
2. **Resolver** - преобразование данных API в коды локаций Bitrix

По умолчанию `BitrixLocationResolver` работает напрямую с `Dadata\DadataClient`. Для смены источника подсказок достаточно унаследовать резолвер и переопределить protected-методы `fetchSuggestions()` и `getParser()` — отдельный интерфейс клиента не требуется.

```
Адрес/Координаты → DadataClient → Parser → Resolver → Код локации Bitrix
```

---

## LocationDTO

### Описание

Data Transfer Object для передачи координат.

### Свойства

```php
public float $latitude;   // Широта
public float $longitude;  // Долгота
```

### Использование

```php
use Beeralex\Catalog\Dto\LocationDTO;

$location = LocationDTO::make([
    'LATITUDE' => 55.753215,
    'LONGITUDE' => 37.622504
]);

echo $location->latitude;  // 55.753215
echo $location->longitude; // 37.622504
```

---

## Контракты (Interfaces)

### LocationDataParserContract

Интерфейс для парсеров данных геолокации.

```php
interface LocationDataParserContract
{
    /**
     * Парсит данные и возвращает варианты названий для поиска
     * 
     * @return array [
     *     0 => array settlement варианты (населенный пункт),
     *     1 => array city варианты (город),
     *     2 => array area варианты (район),
     *     3 => array region варианты (регион)
     * ]
     */
    public function parse(array $suggestions): array;
}
```

### BitrixLocationResolverContract

Интерфейс для резолвера локаций Bitrix.

```php
interface BitrixLocationResolverContract
{
    /**
     * Возвращает данные местоположения из Bitrix по адресу или координатам
     * 
     * @param string|LocationDTO $location Адрес или координаты
     * @return array|null ['city' => ..., 'code' => ..., 'area' => ..., 'region' => ...]
     */
    public function getBitrixLocationByAddress(string|LocationDTO $location): ?array;
}
```

---

## DadataClient

### Описание

Клиент для API сервиса DaData (dadata.ru) из пакета `hflabs/dadata` (`Dadata\DadataClient`). Предоставляет подсказки адресов (`suggest`) и геолокацию по координатам (`geolocate`).

Клиент не регистрируется в DI отдельно — он создаётся внутри `BitrixLocationResolver::getClient()` из настроек модуля (`Options::dadataApiKey`, `Options::dadataSecretKey`). Чтобы подменить клиент — переопределите `getClient()` в наследнике резолвера.

---

## DadataLocationParser

### Описание

Парсер для данных, полученных от DaData API. Извлекает названия населенных пунктов, городов, районов и регионов для дальнейшего поиска в Bitrix.

### Метод parse()

```php
public function parse(array $suggestions): array
```

**Входные данные:** массив подсказок от DaData API.

**Возвращает:**

```php
[
    0 => ['Москва'],              // settlement варианты
    1 => ['Москва', 'Moscow'],    // city варианты
    2 => [],                       // area варианты
    3 => ['Москва', 'Московская'] // region варианты
]
```

---

## BitrixLocationResolver

### Описание

Главный класс для определения локации в Bitrix. По умолчанию использует `Dadata\DadataClient` для получения данных о местоположении, а затем ищет соответствующий код локации в справочнике Bitrix. Логика получения подсказок и выбор парсера вынесены в protected-методы, что позволяет наследникам легко подменить источник данных без изменения интерфейса.

### Особенности

- **Кеширование результатов** на 3600000 секунд (1000 часов)
- **Приоритетный поиск**: сначала ищет по населенному пункту, потом по городу, району, региону
- **Умное сопоставление**: пытается найти наиболее точное совпадение по региону и району
- **Обработка ошибок**: безопасно обрабатывает исключения и возвращает null

### Конструктор

```php
public function __construct(
    protected readonly LocationService $locationService
)
```

Клиент в конструктор не передаётся — он получается через метод `getClient()`.

### Метод getClient()

Возвращает клиент для получения подсказок. По умолчанию создаёт `Dadata\DadataClient` из настроек модуля. **Переопределите в наследнике** для использования другого клиента.

```php
/**
 * @return DadataClient
 */
protected function getClient(): object
```

### Метод getBitrixLocationByAddress()

Возвращает данные местоположения из Bitrix по адресу или координатам.

```php
public function getBitrixLocationByAddress(string|LocationDTO $location): ?array
```

**Параметры:**
- `$location` - строка адреса или объект `LocationDTO` с координатами

**Возвращает:**

```php
[
    'city' => 'Москва',           // Название города
    'code' => '0000073738',       // Код локации в Bitrix
    'area' => null,               // Название района (если есть)
    'region' => 'Москва'          // Название региона
]
```

Возвращает `null`, если локация не найдена.

**Примеры:**

```php
use Beeralex\Catalog\Location\Contracts\BitrixLocationResolverContract;

$resolver = service(BitrixLocationResolverContract::class);

// Поиск по адресу
$location = $resolver->getBitrixLocationByAddress('Москва, Красная площадь, 1');
/*
[
    'city' => 'Москва',
    'code' => '0000073738',
    'area' => null,
    'region' => 'Москва'
]
*/

// Поиск по координатам
$coords = LocationDTO::make([
    'LATITUDE' => 55.753215,
    'LONGITUDE' => 37.622504
]);
$location = $resolver->getBitrixLocationByAddress($coords);
```

### Алгоритм работы

1. **Получение данных от API** (метод `fetchSuggestions()`)
   - Если передан адрес → вызов `$client->suggest('address', ...)`
   - Если координаты → вызов `$client->geolocate('address', ...)`

2. **Парсинг данных**
   - Извлечение вариантов названий (settlement, city, area, region)

3. **Приоритетный поиск в Bitrix**
   - Поиск по населенному пункту (settlement)
   - Если не найдено → поиск по городу (city)
   - Если не найдено → поиск по району (area)
   - Если не найдено → поиск по региону (region)

4. **Уточнение по региону и району**
   - Среди найденных локаций ищет наиболее точное совпадение
   - Проверяет совпадение региона и района в иерархии локации

5. **Кеширование**
   - Результат кешируется на длительный срок

### Внутренние методы

#### getVariantsFromLocation()

Получает варианты названий из данных API.

```php
protected function getVariantsFromLocation(string|LocationDTO $location): ?array
```

#### fetchSuggestions()

Получает подсказки от клиента по адресу или координатам. **Переопределите в наследнике** для использования другого клиента/сервиса.

```php
protected function fetchSuggestions(string|LocationDTO $location): ?array
```

#### getParser()

Возвращает парсер ответа клиента в структуру вариантов. **Переопределите в наследнике** при смене клиента.

```php
protected function getParser(): ?LocationDataParserContract
```

#### searchPriority()

Выполняет приоритетный поиск по группам вариантов.

```php
private function searchPriority(array $groups): array
```

#### searchInBitrix()

Ищет локации в справочнике Bitrix по вариантам названий.

```php
private function searchInBitrix(array $variants): array
```

#### matchRegionAndArea()

Уточняет результат поиска по региону и району.

```php
private function matchRegionAndArea(
    array $items,
    array $regionVariants,
    array $areaVariants
): ?array
```

---

## Использование в компонентах

### Пример в компоненте оформления заказа

```php
use Beeralex\Catalog\Location\Contracts\BitrixLocationResolverContract;

class CheckoutComponent extends CBitrixComponent
{
    protected ?BitrixLocationResolverContract $locationResolver = null;
    
    public function executeComponent()
    {
        $this->locationResolver = service(BitrixLocationResolverContract::class);
        
        // Получаем адрес от пользователя
        $address = $_POST['address'] ?? '';
        
        if ($address) {
            $location = $this->locationResolver->getBitrixLocationByAddress($address);
            
            if ($location) {
                // Устанавливаем локацию в заказ
                $this->arResult['LOCATION_CODE'] = $location['code'];
                $this->arResult['CITY'] = $location['city'];
            }
        }
        
        $this->includeComponentTemplate();
    }
}
```

### Пример с координатами

```php
use Beeralex\Catalog\Dto\LocationDTO;
use Beeralex\Catalog\Location\Contracts\BitrixLocationResolverContract;

// Получаем координаты от пользователя (например, через HTML5 Geolocation API)
$lat = (float)$_POST['latitude'];
$lon = (float)$_POST['longitude'];

$locationDTO = LocationDTO::make([
    'LATITUDE' => $lat,
    'LONGITUDE' => $lon
]);

$resolver = service(BitrixLocationResolverContract::class);
$location = $resolver->getBitrixLocationByAddress($locationDTO);

if ($location) {
    echo "Вы находитесь в городе: " . $location['city'];
    echo "Код локации: " . $location['code'];
}
```

---

## Настройка

### Получение API ключей DaData

1. Зарегистрируйтесь на [dadata.ru](https://dadata.ru)
2. Получите API ключ и секретный ключ
3. Добавьте их в настройки модуля

### Настройка через админку

В административной панели Bitrix → Настройки → Настройки модулей → beeralex.catalog:

- **DADATA_API_KEY** - API ключ (токен) DaData
- **DADATA_SECRET_KEY** - Секретный ключ DaData

### Программная настройка

```php
use Beeralex\Catalog\Options;

$options = service(Options::class);
$apiKey = $options->dadataApiKey;
$secretKey = $options->dadataSecretKey;
```

---

## Кеширование

Результаты поиска локаций кешируются на **1000 часов** (3600000 секунд), так как справочник локаций Bitrix меняется редко.

Кеш хранится в директории: `bitrix/cache/beeralex.catalog/location/`

Для очистки кеша можно использовать:

```php
\Bitrix\Main\Data\Cache::clearCache(true, 'beeralex.catalog/location');
```

---

## Смена источника подсказок (кастомный клиент)

Вместо поддержки отдельного интерфейса клиента, для использования другого API (например, Google Maps, Yandex Maps) достаточно унаследовать `BitrixLocationResolver` и переопределить `getClient()`, `fetchSuggestions()` и `getParser()`:

```php
namespace App\Location;

use Beeralex\Catalog\Dto\LocationDTO;
use Beeralex\Catalog\Location\BitrixLocationResolver;
use Beeralex\Catalog\Location\Contracts\LocationDataParserContract;

class GoogleMapsLocationResolver extends BitrixLocationResolver
{
    /**
     * @return GoogleMapsClient
     */
    protected function getClient(): object
    {
        return new GoogleMapsClient(/* ... */);
    }

    protected function fetchSuggestions(string|LocationDTO $location): ?array
    {
        // Ваша реализация через $this->getClient()
        // верните массив подсказок или null
        return $suggestions;
    }

    protected function getParser(): ?LocationDataParserContract
    {
        return new GoogleMapsParser();
    }
}
```

Затем переопределите привязку резолвера в DI:

```php
use App\Location\GoogleMapsLocationResolver;
use Beeralex\Catalog\Location\Contracts\BitrixLocationResolverContract;
use Beeralex\Core\Service\LocationService;

return [
    'services' => [
        'value' => [
            BitrixLocationResolverContract::class => [
                'constructor' => static function() {
                    return new GoogleMapsLocationResolver(
                        locationService: service(LocationService::class)
                    );
                }
            ]
        ]
    ]
];
```

---

## Обработка ошибок

Система безопасно обрабатывает ошибки и исключения:

```php
try {
    $location = $resolver->getBitrixLocationByAddress($address);
    if ($location === null) {
        // Локация не найдена
        echo "К сожалению, не удалось определить ваше местоположение";
    }
} catch (\Throwable $e) {
    // Ошибка не выбрасывается наружу, но можно логировать
    \Beeralex\Catalog\log("Location error: " . $e->getMessage());
}
```

---

## Рекомендации

1. **Используйте кеширование** - не делайте повторные запросы к API для одного и того же адреса
2. **Обрабатывайте null** - метод может вернуть null, если локация не найдена
3. **Проверяйте лимиты API** - DaData имеет ограничения на количество запросов
4. **Тестируйте на реальных данных** - проверьте работу на адресах вашего региона
5. **Логируйте ошибки** - используйте функцию `log()` для отладки проблем с геолокацией
