<?php
namespace app\api\controller\wanlshop\voucher;

use app\common\controller\Api;
use app\admin\model\wanlshop\Voucher;
use think\Db;
use think\Exception;

/**
 * 核销券代管理接口
 */
class Custody extends Api
{
    protected $noNeedLogin = [];
    protected $noNeedRight = ['*'];

    /**
     * 申请代管理
     *
     * @ApiSummary  (申请将核销券交由平台代管理)
     * @ApiMethod   (POST)
     *
     * @param int voucher_id 券ID
     */
    public function apply()
    {
        $this->request->filter(['strip_tags']);

        if (!$this->request->isPost()) {
            $this->error(__('非法请求'));
        }

        $voucherId = $this->request->post('voucher_id/d');
        if (!$voucherId) {
            $this->error(__('参数错误'));
        }

        Db::startTrans();
        try {
            // 查询券并加锁
            $voucher = Voucher::where([
                'id' => $voucherId,
                'user_id' => $this->auth->id,
                'status' => 'normal'
            ])->lock(true)->find();

            if (!$voucher) {
                throw new Exception('券不存在');
            }

            // 验证券状态：必须是未使用
            if ($voucher->state != 1) {
                throw new Exception('只有未使用的券才能申请代管理');
            }

            // 验证代管理状态：未申请或已拒绝才能申请
            if (!in_array($voucher->custody_state, ['0', '3'])) {
                throw new Exception('当前券已申请代管理，无法重复申请');
            }

            // 验证在免费期内
            $voucherOrder = $voucher->voucherOrder;
            if (!$voucherOrder || !$voucherOrder->paymenttime) {
                throw new Exception('订单信息异常');
            }

            $voucherRule = $voucher->voucherRule;
            $freeDays = $voucherRule ? (int)$voucherRule->free_days : 0;
            $paymentTime = (int)$voucherOrder->paymenttime;
            $now = time();
            $daysFromPayment = floor(($now - $paymentTime) / 86400);

            if ($daysFromPayment > $freeDays) {
                throw new Exception('已超过免费期，无法申请代管理');
            }

            // 查询平台基准价（shop_id=1 的对应 SKU）
            $platformPrice = $this->getPlatformPrice($voucher->category_id, $voucher->sku_difference);
            if ($platformPrice === null) {
                throw new Exception('平台暂无对应商品，无法申请代管理');
            }

            // 计算预估返利金额
            $estimatedRebate = $this->calculateEstimatedRebate($voucher, $platformPrice);

            // 更新券代管理状态
            $voucher->custody_state = '1';  // 申请中
            $voucher->custody_apply_time = $now;
            $voucher->custody_platform_price = $platformPrice;
            $voucher->custody_estimated_rebate = $estimatedRebate;
            // 清空之前的拒绝理由
            $voucher->custody_refuse_reason = null;
            $voucher->custody_audit_time = null;
            $voucher->custody_admin_id = null;
            $voucher->save();

            Db::commit();

            $this->success('申请成功', [
                'voucher_id' => $voucher->id,
                'custody_state' => $voucher->custody_state,
                'custody_state_text' => '申请中',
                'estimated_rebate' => (float)$estimatedRebate,
                'platform_price' => (float)$platformPrice
            ]);

        } catch (Exception $e) {
            Db::rollback();
            $this->error(__('申请失败: ') . $e->getMessage());
        }
    }

    /**
     * 取消代管理申请
     *
     * @ApiSummary  (取消代管理申请)
     * @ApiMethod   (POST)
     *
     * @param int voucher_id 券ID
     */
    public function cancel()
    {
        $this->request->filter(['strip_tags']);

        if (!$this->request->isPost()) {
            $this->error(__('非法请求'));
        }

        $voucherId = $this->request->post('voucher_id/d');
        if (!$voucherId) {
            $this->error(__('参数错误'));
        }

        Db::startTrans();
        try {
            // 查询券并加锁
            $voucher = Voucher::where([
                'id' => $voucherId,
                'user_id' => $this->auth->id,
                'status' => 'normal'
            ])->lock(true)->find();

            if (!$voucher) {
                throw new Exception('券不存在');
            }

            // 验证代管理状态：只有申请中才能取消
            if ($voucher->custody_state != '1') {
                throw new Exception('只有申请中的代管理才能取消');
            }

            // 重置代管理状态
            $voucher->custody_state = '0';  // 未申请
            $voucher->custody_apply_time = null;
            $voucher->custody_platform_price = null;
            $voucher->custody_estimated_rebate = null;
            $voucher->save();

            Db::commit();

            $this->success('取消成功', [
                'voucher_id' => $voucher->id,
                'custody_state' => '0',
                'custody_state_text' => '未申请'
            ]);

        } catch (Exception $e) {
            Db::rollback();
            $this->error(__('取消失败: ') . $e->getMessage());
        }
    }

    /**
     * 查询代管理详情
     *
     * @ApiSummary  (查询券的代管理详情)
     * @ApiMethod   (GET)
     *
     * @param int voucher_id 券ID
     */
    public function detail()
    {
        $this->request->filter(['strip_tags']);

        $voucherId = $this->request->get('voucher_id/d');
        if (!$voucherId) {
            $this->error(__('参数错误'));
        }

        // 查询券
        $voucher = Voucher::with(['voucherOrder', 'voucherRule'])
            ->where([
                'id' => $voucherId,
                'user_id' => $this->auth->id,
                'status' => 'normal'
            ])->find();

        if (!$voucher) {
            $this->error(__('券不存在'));
        }

        // 计算是否可申请代管理
        $canApply = $this->checkCanApply($voucher);
        // 计算是否可取消
        $canCancel = ($voucher->custody_state == '1');

        $this->success('ok', [
            'voucher_id' => $voucher->id,
            'custody_state' => $voucher->custody_state,
            'custody_state_text' => $voucher->custody_state_text,
            'custody_apply_time' => $voucher->custody_apply_time ? (int)$voucher->custody_apply_time : null,
            'custody_audit_time' => $voucher->custody_audit_time ? (int)$voucher->custody_audit_time : null,
            'custody_platform_price' => $voucher->custody_platform_price ? (float)$voucher->custody_platform_price : null,
            'custody_estimated_rebate' => $voucher->custody_estimated_rebate ? (float)$voucher->custody_estimated_rebate : null,
            'custody_refuse_reason' => $voucher->custody_refuse_reason,
            'can_apply' => $canApply,
            'can_cancel' => $canCancel
        ]);
    }

    /**
     * 获取平台基准价（shop_id=1 的 SKU 价格）
     *
     * @param int $categoryId 分类ID
     * @param string $skuDifference 规格差异
     * @return float|null
     */
    protected function getPlatformPrice($categoryId, $skuDifference)
    {
        // Step 1: 先找平台店铺（shop_id=1）对应分类的商品
        $goods = Db::name('wanlshop_goods')
            ->where('shop_id', 1)
            ->where('category_id', $categoryId)
            ->where('status', 'normal')
            ->where('deletetime', null)
            ->field('id')
            ->find();

        if (!$goods) {
            return null;
        }

        // Step 2: 再根据 goods_id + difference 找对应 SKU
        $sku = Db::name('wanlshop_goods_sku')
            ->where('goods_id', $goods['id'])
            ->where('difference', $skuDifference)
            ->where('state', '0')  // state=0 表示启用中
            ->where('status', 'normal')
            ->where('deletetime', null)
            ->field('price')
            ->find();

        return $sku ? (float)$sku['price'] : null;
    }

    /**
     * 计算预估返利金额
     *
     * @param Voucher $voucher 券模型
     * @param float $platformPrice 平台基准价
     * @return float
     */
    protected function calculateEstimatedRebate($voucher, $platformPrice)
    {
        // 获取用户返利比例
        $user = Db::name('user')
            ->where('id', $voucher->user_id)
            ->field('bonus_ratio')
            ->find();

        $bonusRatio = $user ? (float)$user['bonus_ratio'] : 0;

        // 预估返利 = 平台供货价 * 用户返利比例
        return round($platformPrice * ($bonusRatio / 100), 2);
    }

    /**
     * 检查是否可申请代管理
     *
     * @param Voucher $voucher 券模型
     * @return bool
     */
    protected function checkCanApply($voucher)
    {
        // 1. 券状态必须是未使用
        if ($voucher->state != 1) {
            return false;
        }

        // 2. 代管理状态必须是未申请或已拒绝
        if (!in_array($voucher->custody_state, ['0', '3'])) {
            return false;
        }

        // 3. 必须在免费期内
        $voucherOrder = $voucher->voucherOrder;
        if (!$voucherOrder || !$voucherOrder->paymenttime) {
            return false;
        }

        $voucherRule = $voucher->voucherRule;
        $freeDays = $voucherRule ? (int)$voucherRule->free_days : 0;
        $paymentTime = (int)$voucherOrder->paymenttime;
        $now = time();
        $daysFromPayment = floor(($now - $paymentTime) / 86400);

        return $daysFromPayment <= $freeDays;
    }
}
