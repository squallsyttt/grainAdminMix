<?php
/**
 * 核销券返利计算服务
 */

namespace app\common\service;

use app\admin\model\wanlshop\Voucher;
use app\admin\model\wanlshop\VoucherOrder;
use app\admin\model\wanlshop\VoucherRebate;
use app\admin\model\wanlshop\VoucherRule;
use app\admin\model\wanlshop\VoucherVerification;
use app\common\model\User;
use Exception;
use think\Db;

class VoucherRebateService
{
    /**
     * 计算返利（动态获取规则配置）
     *
     * @param Voucher $voucher 核销券
     * @param int $verifyTime 核销时间戳
     * @param float|null $shopSupplyPrice 店铺供货价（核销店铺商品价格）
     * @return array
     * @throws Exception
     */
    public function calculateRebate(Voucher $voucher, $verifyTime, $shopSupplyPrice = null)
    {
        if (!$voucher || !$voucher->id) {
            throw new Exception('核销券不存在');
        }

        $verifyTime = (int)$verifyTime;
        if ($verifyTime <= 0) {
            throw new Exception('核销时间无效');
        }

        // 1. 动态获取规则配置
        $rule = VoucherRule::find($voucher->rule_id);
        if (!$rule) {
            throw new Exception('核销券规则不存在');
        }
        $freeDays = (int)$rule->free_days;
        $welfareDays = (int)$rule->welfare_days;
        $goodsDays = (int)$rule->goods_days;

        // 2. 获取订单付款时间
        $order = VoucherOrder::find($voucher->order_id);
        if (!$order || !$order->paymenttime) {
            throw new Exception('订单付款时间不存在');
        }
        $paymentTime = (int)$order->paymenttime;

        // 3. 获取用户返利比例
        $user = User::find($voucher->user_id);
        $userBonusRatio = $user && $user->bonus_ratio !== null ? (float)$user->bonus_ratio : 0;

        // 4. 获取货物原始重量
        $originalWeight = round(isset($voucher->sku_weight) ? (float)$voucher->sku_weight : 0, 2);

        // 5. 计算距付款天数（向下取整，最小为0）
        $daysFromPayment = (int)floor(($verifyTime - $paymentTime) / 86400);
        if ($daysFromPayment < 0) {
            $daysFromPayment = 0;
        }

        // 6. 阶段判定与计算
        if ($daysFromPayment <= $freeDays) {
            // 免费期：返利和货物均无损耗
            $stage = 'free';
            $actualBonusRatio = $userBonusRatio;
            $actualGoodsWeight = $originalWeight;
        } elseif ($daysFromPayment <= $freeDays + $welfareDays) {
            // 福利损耗期：返利和货物线性递减
            $stage = 'welfare';
            $welfareElapsedDays = $daysFromPayment - $freeDays;
            $ratioLossPerDay = $welfareDays > 0 ? $userBonusRatio / $welfareDays : 0;
            $actualBonusRatio = max(0, $userBonusRatio - ($ratioLossPerDay * $welfareElapsedDays));

            $goodsLossPerDay = $welfareDays > 0 ? (($userBonusRatio / 100) * $originalWeight / $welfareDays) : 0;
            $actualGoodsWeight = $originalWeight - ($goodsLossPerDay * $welfareElapsedDays);
        } elseif ($daysFromPayment <= $freeDays + $welfareDays + $goodsDays) {
            // 货物损耗期：返利为0，货物继续递减
            $stage = 'goods';
            $actualBonusRatio = 0;

            $remainingWeight = $originalWeight * (1 - $userBonusRatio / 100);
            $goodsElapsedDays = $daysFromPayment - $freeDays - $welfareDays;
            $goodsLossPerDay = $goodsDays > 0 ? $remainingWeight / $goodsDays : 0;
            $actualGoodsWeight = $remainingWeight - ($goodsLossPerDay * $goodsElapsedDays);
        } else {
            // 已过期：返利和货物均为0
            $stage = 'expired';
            $actualBonusRatio = 0;
            $actualGoodsWeight = 0;
        }

        // 7. 计算返利金额（基于面值计算）
        $actualBonusRatio = round(max(0, $actualBonusRatio), 2);
        $actualGoodsWeight = round(max(0, $actualGoodsWeight), 2);
        $faceValue = round((float)$voucher->face_value, 2);
        $rebateAmount = round($faceValue * ($actualBonusRatio / 100), 2);

        return [
            'stage' => $stage,
            'days_from_payment' => $daysFromPayment,
            'user_bonus_ratio' => round($userBonusRatio, 2),
            'actual_bonus_ratio' => $actualBonusRatio,
            'original_goods_weight' => $originalWeight,
            'actual_goods_weight' => $actualGoodsWeight,
            'rebate_amount' => $rebateAmount,
            'rule_id' => $rule->id,
            'free_days' => $freeDays,
            'welfare_days' => $welfareDays,
            'goods_days' => $goodsDays,
            'payment_time' => $paymentTime,
        ];
    }

    /**
     * 获取核销店铺对应商品及规格信息
     *
     * @param int $shopId 核销店铺ID
     * @param int $categoryId 券对应的品类ID
     * @param string $skuDifference SKU规格名
     * @return array
     * @throws Exception
     */
    public function getShopGoodsInfo($shopId, $categoryId, $skuDifference)
    {
        // Step 1: 找店铺的唯一商品
        $goods = Db::name('wanlshop_goods')
            ->where('shop_id', $shopId)
            ->where('category_id', $categoryId)
            ->where('status', 'normal')
            ->find();

        if (!$goods) {
            throw new Exception('核销店铺没有对应品类的商品');
        }

        // Step 2: 找该商品的对应规格 SKU（state=0 表示使用中，state=1 表示禁用）
        $sku = Db::name('wanlshop_goods_sku')
            ->where('goods_id', $goods['id'])
            ->where('difference', $skuDifference)
            ->where('state', '0')  // state=0 表示使用中
            ->where('status', 'normal')
            ->find();

        if (!$sku) {
            throw new Exception('核销店铺商品没有对应规格');
        }

        return [
            'goods_id' => $goods['id'],
            'goods_title' => $goods['title'],
            'sku_id' => $sku['id'],
            'sku_price' => round((float)$sku['price'], 2),
        ];
    }

    /**
     * 创建返利结算记录
     *
     * @param Voucher $voucher 核销券
     * @param VoucherVerification $verification 核销记录
     * @param int $verifyTime 核销时间戳
     * @param int $shopId 核销店铺ID
     * @param array $shopGoodsInfo 核销店铺商品信息
     * @return VoucherRebate
     * @throws Exception
     */
    public function createRebateRecord(Voucher $voucher, VoucherVerification $verification, $verifyTime, $shopId, array $shopGoodsInfo = [])
    {
        if (!$verification || !$verification->id) {
            throw new Exception('核销记录不存在');
        }

        $verifyTime = (int)$verifyTime;
        if ($verifyTime <= 0) {
            throw new Exception('核销时间无效');
        }

        // 防重复插入
        $exists = VoucherRebate::where('voucher_id', $voucher->id)->find();
        if ($exists) {
            throw new Exception('返利结算记录已存在');
        }

        $shopId = (int)$shopId;
        if ($shopId <= 0) {
            $shopId = $verification->shop_id ? (int)$verification->shop_id : (int)$voucher->shop_id;
        }

        if (!$shopGoodsInfo) {
            $shopGoodsInfo = $this->getShopGoodsInfo(
                $shopId,
                isset($voucher->category_id) ? (int)$voucher->category_id : 0,
                isset($voucher->sku_difference) ? (string)$voucher->sku_difference : ''
            );
        }
        $shopSupplyPrice = round(isset($shopGoodsInfo['sku_price']) ? (float)$shopGoodsInfo['sku_price'] : 0, 2);

        $result = $this->calculateRebate($voucher, $verifyTime, $shopSupplyPrice);

        $rebate = new VoucherRebate();
        $rebate->voucher_id = $voucher->id;
        $rebate->voucher_no = $voucher->voucher_no;
        $rebate->order_id = $voucher->order_id;
        $rebate->verification_id = $verification->id;
        $rebate->user_id = $voucher->user_id;
        $rebate->shop_id = $shopId ?: ($verification->shop_id ? (int)$verification->shop_id : (int)$voucher->shop_id);
        $rebate->shop_name = $verification->shop_name ? (string)$verification->shop_name : (string)$voucher->shop_name;

        $rebate->supply_price = $shopSupplyPrice;
        $rebate->face_value = round((float)$voucher->face_value, 2);
        $rebate->rebate_amount = $result['rebate_amount'];
        $rebate->user_bonus_ratio = $result['user_bonus_ratio'];
        $rebate->actual_bonus_ratio = $result['actual_bonus_ratio'];

        $rebate->stage = $result['stage'];
        $rebate->days_from_payment = $result['days_from_payment'];

        $rebate->goods_title = isset($voucher->goods_title) ? (string)$voucher->goods_title : '';
        $rebate->shop_goods_id = isset($shopGoodsInfo['goods_id']) ? (int)$shopGoodsInfo['goods_id'] : 0;
        $rebate->shop_goods_title = isset($shopGoodsInfo['goods_title']) ? (string)$shopGoodsInfo['goods_title'] : '';
        $rebate->sku_weight = round(isset($voucher->sku_weight) ? (float)$voucher->sku_weight : 0, 2);
        $rebate->original_goods_weight = round($result['original_goods_weight'], 2);
        $rebate->actual_goods_weight = $result['actual_goods_weight'];

        $rebate->rule_id = $result['rule_id'];
        $rebate->free_days = $result['free_days'];
        $rebate->welfare_days = $result['welfare_days'];
        $rebate->goods_days = $result['goods_days'];

        $rebate->payment_time = $result['payment_time'];
        $rebate->verify_time = $verifyTime;
        $rebate->status = 'normal';

        $rebate->save();

        return $rebate;
    }
}
