<?php
/**
 * 价格计算服务
 *
 * 业务规则：
 * 1. 平台始终保证20%利润（即商家供货价 / 0.8 = 平台零售价）
 * 2. 展示价格不含手续费，实际支付时才加上0.6%微信手续费
 * 3. 价格保留两位小数，四舍五入
 *
 * 价格计算逻辑：
 * - 场景A：小程序首页（shop_id=1的商品）
 *   展示价格 = 平台商品SKU价格（不含手续费）
 *   实际支付 = 展示价格 × 1.006
 *
 * - 场景B：商家店铺页面
 *   1. 找到商家商品对应的平台商品（通过category_id匹配）
 *   2. 如果 商家供货价 ≤ 平台价格 × 80%：展示价格 = 平台价格
 *   3. 如果 商家供货价 > 平台价格 × 80%：展示价格 = 商家供货价 / 0.8
 *   4. 实际支付 = 展示价格 × 1.006
 */

namespace app\common\service;

use think\Db;

class PriceCalculator
{
    // 平台利润率 20%
    const PLATFORM_MARGIN_RATE = 0.20;

    // 微信手续费率 0.6% 但我现在平台选择不收
    const WECHAT_FEE_RATE = 0;

    // 平台店铺ID
    const PLATFORM_SHOP_ID = 1;

    // 商家供货价阈值系数（平台价格的80%）
    const SUPPLY_THRESHOLD_RATE = 0.80;

    /**
     * 计算实际支付价格（展示价格 + 微信手续费）
     *
     * @param float $displayPrice 展示价格（零售价）
     * @return float 实际支付价格（四舍五入保留两位小数）
     */
    public static function calculatePaymentPrice($displayPrice)
    {
        $price = $displayPrice * (1 + self::WECHAT_FEE_RATE);
        return round($price, 2);
    }

    /**
     * 计算展示价格（不含微信手续费，已废弃，保留兼容）
     *
     * @param float $basePrice 基础价格（平台零售价或计算后的零售价）
     * @return float 展示价格（四舍五入保留两位小数）
     * @deprecated 使用 calculatePaymentPrice 代替
     */
    public static function calculateDisplayPrice($basePrice)
    {
        // 展示价格不含手续费，直接返回
        return round($basePrice, 2);
    }

    /**
     * 根据供货价计算零售价（保证平台20%利润）
     *
     * @param float $supplyPrice 商家供货价
     * @return float 零售价（不含手续费）
     */
    public static function calculateRetailPriceFromSupply($supplyPrice)
    {
        // 零售价 = 供货价 / (1 - 20%) = 供货价 / 0.8
        return round($supplyPrice / (1 - self::PLATFORM_MARGIN_RATE), 2);
    }

    /**
     * 计算商家商品的展示价格和支付价格
     *
     * 规则：
     * - 如果商家供货价 ≤ 平台价格 × 80%，展示平台价格
     * - 如果商家供货价 > 平台价格 × 80%，展示 供货价/0.8
     * - 实际支付 = 展示价格 × 1.006
     *
     * @param float $supplierPrice 商家供货价（商家商品的SKU价格）
     * @param float $platformPrice 平台零售价（shop_id=1商品的SKU价格）
     * @return array ['display_price' => 展示价格, 'payment_price' => 实际支付价格, 'retail_price' => 零售价, 'use_platform_price' => 是否使用平台价]
     */
    public static function calculateMerchantDisplayPrice($supplierPrice, $platformPrice)
    {
        $threshold = $platformPrice * self::SUPPLY_THRESHOLD_RATE;

        if ($supplierPrice <= $threshold) {
            // 商家供货价在阈值内，使用平台价格
            $retailPrice = $platformPrice;
            $usePlatformPrice = true;
        } else {
            // 商家供货价超过阈值，按供货价反算零售价
            $retailPrice = self::calculateRetailPriceFromSupply($supplierPrice);
            $usePlatformPrice = false;
        }

        $displayPrice = round($retailPrice, 2);
        $paymentPrice = self::calculatePaymentPrice($displayPrice);

        return [
            'display_price' => $displayPrice,
            'payment_price' => $paymentPrice,
            'retail_price' => $retailPrice,
            'use_platform_price' => $usePlatformPrice,
        ];
    }

    /**
     * 计算平台商品的展示价格和支付价格
     *
     * @param float $platformPrice 平台商品SKU价格
     * @return array ['display_price' => 展示价格, 'payment_price' => 实际支付价格]
     */
    public static function calculatePlatformDisplayPrice($platformPrice)
    {
        $displayPrice = round($platformPrice, 2);
        $paymentPrice = self::calculatePaymentPrice($displayPrice);
        return [
            'display_price' => $displayPrice,
            'payment_price' => $paymentPrice,
        ];
    }

    /**
     * 获取平台商品价格（通过类目匹配）
     *
     * @param int $categoryId 类目ID
     * @param int|null $skuId 可选，指定SKU
     * @return float|null 平台商品SKU价格，找不到返回null
     */
    public static function getPlatformPriceByCategoryId($categoryId, $skuId = null)
    {
        // 查找平台店铺(shop_id=1)中该类目的商品
        $query = Db::name('wanlshop_goods')
            ->alias('g')
            ->where('g.shop_id', self::PLATFORM_SHOP_ID)
            ->where('g.category_id', $categoryId)
            ->where('g.deletetime', null)
            ->where('g.status', 'normal');

        $goods = $query->field('g.id, g.price')->find();

        if (!$goods) {
            return null;
        }

        // 如果指定了SKU，查找对应SKU价格
        if ($skuId) {
            $sku = Db::name('wanlshop_goods_sku')
                ->where('goods_id', $goods['id'])
                ->where('id', $skuId)
                ->where('deletetime', null)
                ->where('state', 0)
                ->field('price')
                ->find();

            if ($sku) {
                return (float)$sku['price'];
            }
        }

        // 否则返回商品主价格（最低SKU价格）
        return (float)$goods['price'];
    }

    /**
     * 批量计算订单项的价格
     *
     * @param array $items 订单项 [['goods_id', 'sku_id', 'quantity', 'shop_id'], ...]
     * @return array 计算后的订单项（增加价格字段）
     */
    public static function calculateOrderItemsPrices(array $items)
    {
        $result = [];

        foreach ($items as $item) {
            $goodsId = $item['goods_id'] ?? 0;
            $skuId = $item['sku_id'] ?? $item['goods_sku_id'] ?? 0;
            $quantity = $item['quantity'] ?? 1;
            $shopId = $item['shop_id'] ?? 0;

            // 获取商品和SKU信息
            $goods = Db::name('wanlshop_goods')
                ->where('id', $goodsId)
                ->where('deletetime', null)
                ->field('id, shop_id, category_id, price')
                ->find();

            if (!$goods) {
                continue;
            }

            // 获取SKU价格
            $unitPrice = (float)$goods['price'];
            if ($skuId) {
                $sku = Db::name('wanlshop_goods_sku')
                    ->where('id', $skuId)
                    ->where('goods_id', $goodsId)
                    ->where('deletetime', null)
                    ->field('price')
                    ->find();
                if ($sku) {
                    $unitPrice = (float)$sku['price'];
                }
            }

            // 判断是平台商品还是商家商品
            if ($goods['shop_id'] == self::PLATFORM_SHOP_ID) {
                // 平台商品：直接使用平台价格
                $platformPriceInfo = self::calculatePlatformDisplayPrice($unitPrice);
                $displayPrice = $platformPriceInfo['display_price'];
                $paymentPrice = $platformPriceInfo['payment_price'];
                $retailPrice = $unitPrice;
                $usePlatformPrice = true;
            } else {
                // 商家商品：需要对比平台价格
                $platformPrice = self::getPlatformPriceByCategoryId($goods['category_id'], $skuId);

                if ($platformPrice !== null) {
                    $priceInfo = self::calculateMerchantDisplayPrice($unitPrice, $platformPrice);
                    $displayPrice = $priceInfo['display_price'];
                    $paymentPrice = $priceInfo['payment_price'];
                    $retailPrice = $priceInfo['retail_price'];
                    $usePlatformPrice = $priceInfo['use_platform_price'];
                } else {
                    // 找不到平台价格，按供货价反算
                    $retailPrice = self::calculateRetailPriceFromSupply($unitPrice);
                    $displayPrice = round($retailPrice, 2);
                    $paymentPrice = self::calculatePaymentPrice($displayPrice);
                    $usePlatformPrice = false;
                }
            }

            // 计算总价
            $supplyTotal = round($unitPrice * $quantity, 2);
            $retailTotal = round($retailPrice * $quantity, 2);
            $displayTotal = round($displayPrice * $quantity, 2);
            $paymentTotal = round($paymentPrice * $quantity, 2);

            $result[] = array_merge($item, [
                'unit_supply_price' => $unitPrice,
                'unit_retail_price' => $retailPrice,
                'unit_display_price' => $displayPrice,
                'unit_payment_price' => $paymentPrice,
                'supply_price' => $supplyTotal,
                'retail_price' => $retailTotal,
                'display_price' => $displayTotal,
                'actual_payment' => $paymentTotal,
                'use_platform_price' => $usePlatformPrice,
            ]);
        }

        return $result;
    }

    /**
     * 计算订单总金额
     *
     * @param array $calculatedItems calculateOrderItemsPrices的返回结果
     * @return array ['total_supply' => 供货价总额, 'total_retail' => 零售价总额, 'total_payment' => 实际支付]
     */
    public static function calculateOrderTotals(array $calculatedItems)
    {
        $totalSupply = 0;
        $totalRetail = 0;
        $totalPayment = 0;

        foreach ($calculatedItems as $item) {
            $totalSupply += $item['supply_price'] ?? 0;
            $totalRetail += $item['retail_price'] ?? 0;
            $totalPayment += $item['actual_payment'] ?? 0;
        }

        return [
            'total_supply' => round($totalSupply, 2),
            'total_retail' => round($totalRetail, 2),
            'total_payment' => round($totalPayment, 2),
        ];
    }
}
