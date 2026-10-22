<?php

namespace Beeralex\Catalog\Service;

use Beeralex\Core\Repository\PropertyFeaturesRepository;
use Beeralex\Core\Repository\PropertyRepository;
use Beeralex\Core\Service\FileService;
use Beeralex\Core\Service\HlblockService;
use Bitrix\Main\Loader;

class PropertyService
{
    public function __construct(
        protected readonly PropertyRepository $propertyRepository,
        protected readonly PropertyFeaturesRepository $propertyFeaturesRepository,
        protected readonly HlblockService $hlblockService,
        protected readonly FileService $fileService,
    ) {
        Loader::includeModule('iblock');
    }

    /**
     * Обогащает товары и их офферы свойствами (+ HL_DATA). Работает пачкой.
     */
    public function attachProperties(array &$products): void
    {
        if (empty($products)) {
            return;
        }

        $ids = [];
        foreach ($products as $product) {
            foreach ($this->getPropertiesIds($product) as $id) {
                $ids[$id] = $id;
            }
        }
        if (!$ids) {
            return;
        }

        $properties = $this->propertyRepository->getByIds(
            array_values($ids),
            ['ID', 'CODE', 'NAME', 'USER_TYPE_SETTINGS_LIST', 'USER_TYPE', 'PROPERTY_TYPE']
        );
        $properties = $this->prepareProperties($properties);
        $hlData = $this->loadHlData($products, $properties);

        foreach ($products as &$product) {
            $this->applyToEntity($product, $properties, $hlData);

            if (!empty($product['OFFERS'])) {
                foreach ($product['OFFERS'] as &$offer) {
                    $this->applyToEntity($offer, $properties, $hlData);
                }
                unset($offer);
            }
        }
        unset($product);
    }

    /**
     * Строит дерево предложений. Вызывать ПОСЛЕ attachProperties().
     */
    public function buildOfferTree(array $offers): array
    {
        $empty = ['PROPS' => [], 'MAP' => []];
        if (empty($offers)) {
            return $empty;
        }

        $treeProps = [];
        foreach ($offers as $offer) {
            foreach ($offer as $code => $prop) {
                if (is_array($prop) && !empty($prop['TREE'])) {
                    $treeProps[$code] = [
                        'CODE'   => $code,
                        'NAME'   => $prop['NAME'] ?? $code,
                        'VALUES' => [],
                    ];
                }
            }
        }
        if (!$treeProps) {
            return $empty;
        }

        $map = [];
        foreach ($offers as $offer) {
            $offerId = $offer['ID'];
            $map[$offerId] = [];

            foreach ($treeProps as $code => $_) {
                if (!isset($offer[$code]['VALUE'])) {
                    continue;
                }
                $prop  = $offer[$code];
                $value = $prop['ITEM']['VALUE'] ?? $prop['VALUE'];
                $hl    = $prop['HL_DATA'] ?? null;

                $map[$offerId][$code] = $value;

                $treeProps[$code]['VALUES'][$value] ??= [
                    'ID'          => $prop['IBLOCK_PROPERTY_ID'] ?? 0,
                    'VALUE'       => $value,
                    'NAME'        => $hl['UF_NAME'] ?? $prop['NAME'] ?? '',
                    'XML_ID'      => $prop['XML_ID'] ?? null,
                    'PICTURE_SRC' => $hl['PICTURE_SRC'] ?? null,
                ];
            }
        }

        foreach ($treeProps as &$p) {
            $p['VALUES'] = array_values($p['VALUES']);
        }
        unset($p);

        return ['PROPS' => array_values($treeProps), 'MAP' => $map];
    }

    /** Метаданные свойств: TREE-флаг и HL-класс. Результат индексирован по CODE. */
    protected function prepareProperties(array $properties): array
    {
        $treeEnabled = [];
        foreach ($this->propertyFeaturesRepository->getByOffersTree(['PROPERTY_ID', 'IS_ENABLED']) as $feat) {
            if (!empty($feat['PROPERTY_ID'])) {
                $treeEnabled[$feat['PROPERTY_ID']] = $feat['IS_ENABLED'] === 'Y';
            }
        }

        $tableNames = [];
        foreach ($properties as $p) {
            if ($t = $p['USER_TYPE_SETTINGS_LIST']['TABLE_NAME'] ?? null) {
                $tableNames[$t] = $t;
            }
        }
        $hlClasses = $this->hlblockService->getHlBlocksByTableNames(array_values($tableNames));

        $result = [];
        foreach ($properties as $p) {
            $table = $p['USER_TYPE_SETTINGS_LIST']['TABLE_NAME'] ?? null;
            $p['TREE'] = $treeEnabled[$p['ID']] ?? false;
            $p['HLBLOCK_CLASS'] = ($table && isset($hlClasses[$table])) ? $hlClasses[$table] : null;
            $result[$p['CODE']] = $p;
        }

        return $result;
    }

    /**
     * Один запрос на каждый HL-класс вместо запроса на каждое значение.
     * @return array<string, array<string, array>> [hlClass => [xmlId => row]]
     */
    protected function loadHlData(array $products, array $properties): array
    {
        $need = [];
        $collect = function (array $entity) use ($properties, &$need) {
            foreach ($properties as $code => $prop) {
                $class = $prop['HLBLOCK_CLASS'];
                if (!$class || !isset($entity[$code])) {
                    continue;
                }
                $values = is_array($entity[$code][0] ?? null) ? $entity[$code] : [$entity[$code]];
                foreach ($values as $v) {
                    if (!empty($v['VALUE'])) {
                        $need[$class][$v['VALUE']] = $v['VALUE'];
                    }
                }
            }
        };

        foreach ($products as $product) {
            $collect($product);
            foreach ($product['OFFERS'] ?? [] as $offer) {
                $collect($offer);
            }
        }

        $result = [];
        foreach ($need as $class => $xmlIds) {
            $rs = $this->fileService
                ->addPictireSrcInQuery($class::query(), 'UF_FILE')
                ->setSelect(['*', 'PICTURE_SRC'])
                ->setFilter(['=UF_XML_ID' => array_values($xmlIds)])
                ->exec();
            while ($row = $rs->fetch()) {
                $result[$class][$row['UF_XML_ID']] = $row;
            }
        }

        return $result;
    }

    protected function applyToEntity(array &$entity, array $properties, array $hlData): void
    {
        foreach ($properties as $code => $property) {
            if (!isset($entity[$code])) {
                continue;
            }
            if (is_array($entity[$code][0] ?? null)) {
                foreach ($entity[$code] as $i => $value) {
                    $entity[$code][$i] = $this->decorate($value, $property, $hlData);
                }
            } else {
                $entity[$code] = $this->decorate($entity[$code], $property, $hlData);
            }
        }
    }

    protected function decorate(array $value, array $property, array $hlData): array
    {
        $value = array_merge($value, $property);
        $class = $property['HLBLOCK_CLASS'];
        $value['HL_DATA'] = $class ? ($hlData[$class][$value['VALUE'] ?? null] ?? null) : null;

        return $value;
    }

    protected function getPropertiesIds(array $product): array
    {
        $propertyIds = [];

        if (!empty($product['IBLOCK_PROPERTY_ID'])) {
            $propertyIds[] = $product['IBLOCK_PROPERTY_ID'];
        }

        foreach ($product as $item) {
            if (is_array($item)) {
                foreach ($item as $key => $subItem) {
                    if ($key === 'IBLOCK_PROPERTY_ID' && !empty($subItem)) {
                        $propertyIds[$subItem] = $subItem;
                    } elseif (is_array($subItem) && !empty($subItem['IBLOCK_PROPERTY_ID'])) {
                        $propertyIds[$subItem['IBLOCK_PROPERTY_ID']] = $subItem['IBLOCK_PROPERTY_ID'];
                    }
                }
            }
        }

        if (!empty($product['OFFERS']) && is_array($product['OFFERS'])) {
            foreach ($product['OFFERS'] as $offer) {
                foreach ($offer as $key => $item) {
                    if ($key === 'IBLOCK_PROPERTY_ID' && !empty($item)) {
                        $propertyIds[$item] = $item;
                    } elseif (is_array($item) && !empty($item['IBLOCK_PROPERTY_ID'])) {
                        $propertyIds[$item['IBLOCK_PROPERTY_ID']] = $item['IBLOCK_PROPERTY_ID'];
                    }
                }
            }
        }

        return array_values($propertyIds);
    }
}
