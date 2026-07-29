# Модуль beeralex.catalog

Расширенная система управления каталогом товаров для Bitrix с унифицированными интерфейсами для работы с товарами, предложениями (SKU), корзиной, заказами и системой продаж.

## Основные возможности

- 🛍️ Работа с товарами и предложениями через репозитории
- 💰 Система цен и скидок с применением купонов
- 🛒 Расширенное управление корзиной покупателя
- 📦 Обработка заказов и свойств заказа
- 🌍 Автоматическое определение местоположения через DaData API
- 💳 Кастомные чеки и ограничения для касс/платежей
- 🔍 Быстрый поиск товаров по каталогу

## Требования

- PHP 8.2+
- Bitrix Framework 25.0+ (рекомендуемая для php 8.2)
- Модули: `beeralex.core`

## Установка

1. Разместите модуль в `/local/modules/beeralex.catalog/`
2. Установите через административную панель Bitrix
3. Модуль автоматически зарегистрирует все сервисы в DI контейнере

## Быстрый старт

```php
use Beeralex\Catalog\Service\CatalogService;
use Beeralex\Catalog\Service\Basket\BasketFactory;

// Получение товаров с предложениями и скидками
$catalogService = service(CatalogService::class);
$products = $catalogService->getProductsWithOffers([1, 2, 3], true, true);

// Работа с корзиной
$basketFactory = service(BasketFactory::class);
$basketService = $basketFactory->createBasketServiceForCurrentUser();
$basketService->increment($offerId = 123, $quantity = 2);
```

## Переопределение репозиториев

Создайте свой класс-наследник:

```php
namespace App\Repository;

use Beeralex\Catalog\Repository\ProductsRepository as BaseRepository;

class ProductsRepository extends BaseRepository
{
    public function getProducts(array $productIds, bool $onlyActive = true): array
    {
        $products = parent::getProducts($productIds, $onlyActive);
        // Ваша дополнительная логика
        return $products;
    }
}
```

Зарегистрируйте в `/local/.settings_extra.php`:

```php
use Beeralex\Catalog\Enum\DIServiceKey;
use App\Repository\ProductsRepository;

return [
    'services' => [
        'value' => [
            DIServiceKey::PRODUCT_REPOSITORY->value => [
                'constructor' => static function () {
                    return new ProductsRepository(...);
                }
            ],
        ]
    ]
];
```

## Основные компоненты

### Репозитории
- `ProductsRepository` - работа с товарами
- `OffersRepository` - работа с предложениями (SKU)
- `PriceRepository` - работа с ценами
- `StoreRepository` - работа со складами

### Сервисы
- `CatalogService` - основной сервис каталога
- `BasketService` - управление корзиной
- `OrderService` - обработка заказов
- `SearchService` - поиск товаров
- `PriceService` - расчет и форматирование цен

### Геолокация
- `BitrixLocationResolver` - определение локации в Bitrix (по умолчанию через `Dadata\DadataClient`)

### Расширения Sale
- `PrepaymentCheck` - исправленный чек частичной предоплаты
- `UserRestriction` - ограничение по пользователям
- `MyPriceExtraService` - кастомная цена доставки

## Документация

Полная документация доступна в папке [docs/](./docs/):

- [Подробное описание модуля](./docs/README.md)
- [Репозитории](./docs/repositories.md)
- [Сервисы](./docs/services.md)
- [Корзина и скидки](./docs/basket-services.md)
- [Система геолокации](./docs/location.md)
- [Расширения Sale](./docs/sale-extensions.md)
- [Примеры использования](./docs/examples.md)

## Поддержка

При возникновении вопросов обращайтесь к разработчикам модуля.

## Лицензия

Проприетарный модуль. © beeralex
