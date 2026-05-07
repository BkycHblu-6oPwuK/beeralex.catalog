<?php

namespace Beeralex\Catalog\Cashbox;

use Bitrix\Sale\Cashbox\Check;

/**
 * Исправленное округление в частичной предоплате
 */
class PrepaymentCheck extends Check
{
    public static function getType()
    {
        return 'prepayment';
    }

    public static function getCalculatedSign()
    {
        return static::CALCULATED_SIGN_INCOME;
    }

    public static function getName()
    {
        return 'Частичная предоплата parfum';
    }

    public static function getSupportedEntityType()
    {
        return static::SUPPORTED_ENTITY_TYPE_PAYMENT;
    }

    public static function getSupportedRelatedEntityType()
    {
        return static::SUPPORTED_ENTITY_TYPE_SHIPMENT;
    }

    protected function extractDataInternal()
    {
        $result = parent::extractDataInternal();
        $result = $this->correlatePrices($result);

        foreach ($result['PRODUCTS'] as $i => $item) {
            $result['PRODUCTS'][$i]['PAYMENT_OBJECT'] = static::PAYMENT_OBJECT_PAYMENT;
        }

        if (!empty($result['DELIVERY'])) {
            foreach ($result['DELIVERY'] as $i => $item) {
                $result['DELIVERY'][$i]['PAYMENT_OBJECT'] = static::PAYMENT_OBJECT_PAYMENT;
            }
        }

        return $result;
    }

    protected function needPrintMarkingCode(mixed $basketItem): bool
    {
        return false;
    }

    private function correlatePrices(array $result): array
    {
        $paymentKop = 0;
        foreach ($result['PAYMENTS'] as $payment) {
            $paymentKop += (int) round($payment['SUM'] * 100);
        }

        /** @var \Bitrix\Sale\Order $order */
        $order = $result['ORDER'];
        $orderKop = (int) round($order->getPrice() * 100);

        if ($orderKop <= 0 || $paymentKop <= 0) {
            return $result;
        }

        $rate = $paymentKop / $orderKop;

        $positions = [];

        if (!empty($result['PRODUCTS'])) {
            foreach ($result['PRODUCTS'] as $index => $item) {
                $positions[] = [
                    'type' => 'PRODUCTS',
                    'index' => $index
                ];
            }
        }

        if (!empty($result['DELIVERY'])) {
            foreach ($result['DELIVERY'] as $index => $item) {
                $positions[] = [
                    'type' => 'DELIVERY',
                    'index' => $index
                ];
            }
        }

        if (empty($positions)) {
            return $result;
        }

        $lineKops = [];
        $distributedKop = 0;

        foreach ($positions as $i => $pos) {
            $item = $result[$pos['type']][$pos['index']];
            $originalKop = (int) round($item['SUM'] * 100);
            $lineSumKop = (int) round($originalKop * $rate);
            $lineKops[$i] = $lineSumKop;
            $distributedKop += $lineSumKop;
        }

        $diff = $paymentKop - $distributedKop;
        $lastIdx = count($positions) - 1;
        $lineKops[$lastIdx] += $diff;

        if ($lineKops[$lastIdx] <= 0) {
            $lineKops[$lastIdx] -= $diff;
            $lineKops[0] += $diff;
        }

        $newProducts = [];
        $newDelivery = [];
        $hasDelivery = !empty($result['DELIVERY']);

        foreach ($positions as $i => $pos) {
            $item = $result[$pos['type']][$pos['index']];
            unset($item['DISCOUNT']);

            $quantity = (float)$item['QUANTITY'];
            $qtyInt   = (int) round($quantity);
            $lineKop  = $lineKops[$i];

            $isIntQty = abs($quantity - $qtyInt) < 0.000001 && $qtyInt > 0;

            if ($isIntQty) {
                $priceKop = intdiv($lineKop, $qtyInt);
                $remainder = $lineKop - $priceKop * $qtyInt;
            } else {
                $priceKop = null;
                $remainder = 0;
            }

            if ($priceKop !== null && $remainder > 0) {
                $itemA = $item;
                $itemA['QUANTITY']   = $remainder;
                $itemA['PRICE']      = $this->formatMoney(($priceKop + 1) / 100);
                $itemA['BASE_PRICE'] = $itemA['PRICE'];
                $itemA['SUM']        = $this->formatMoney(($priceKop + 1) * $remainder / 100);

                $itemB = $item;
                $itemB['QUANTITY']   = $qtyInt - $remainder;
                $itemB['PRICE']      = $this->formatMoney($priceKop / 100);
                $itemB['BASE_PRICE'] = $itemB['PRICE'];
                $itemB['SUM']        = $this->formatMoney($priceKop * ($qtyInt - $remainder) / 100);

                if ($pos['type'] === 'PRODUCTS') {
                    $newProducts[] = $itemA;
                    $newProducts[] = $itemB;
                } else {
                    $newDelivery[] = $itemA;
                    $newDelivery[] = $itemB;
                }
            } else {
                if ($priceKop !== null) {
                    $item['PRICE'] = $this->formatMoney($priceKop / 100);
                } else {
                    $price = $quantity > 0 ? ($lineKop / 100) / $quantity : $lineKop / 100;
                    $item['PRICE'] = $this->formatMoney($price);
                }
                $item['BASE_PRICE'] = $item['PRICE'];
                $item['SUM']        = $this->formatMoney($lineKop / 100);

                if ($pos['type'] === 'PRODUCTS') {
                    $newProducts[] = $item;
                } else {
                    $newDelivery[] = $item;
                }
            }
        }

        if (!empty($newProducts)) {
            $result['PRODUCTS'] = $newProducts;
        }
        if ($hasDelivery) {
            $result['DELIVERY'] = $newDelivery;
        }

        return $result;
    }

    protected function formatMoney(float $sum)
    {
        return number_format($sum, 2, '.', '');
    }
}

