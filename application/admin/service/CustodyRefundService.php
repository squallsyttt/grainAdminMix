<?php
/**
 * 代管理退款服务
 *
 * 处理代管理审核通过后的等量退款逻辑
 */

namespace app\admin\service;

use app\admin\model\wanlshop\Voucher;
use app\admin\model\wanlshop\VoucherOrder;
use app\admin\model\wanlshop\VoucherRebate;
use app\admin\model\wanlshop\VoucherRefund;
use app\common\library\WechatPayment;
use Exception;
use think\Db;
use think\Log;

class CustodyRefundService
{
    /**
     * 生成退款单号
     *
     * @param int $rebateId 返利记录ID
     * @return string
     */
    protected function generateRefundNo($rebateId)
    {
        // 格式：CRF + 年月日时分秒 + 返利ID（CRF = Custody ReFund）
        return 'CRF' . date('YmdHis') . str_pad($rebateId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * 创建代管理退款记录并发起微信退款
     *
     * @param VoucherRebate $rebate 返利记录
     * @param Voucher $voucher 核销券
     * @return array ['success' => bool, 'message' => string, 'refund' => VoucherRefund|null]
     */
    public function createCustodyRefund(VoucherRebate $rebate, Voucher $voucher)
    {
        // 验证是否为代管理返利
        if ($rebate->rebate_type !== 'custody') {
            return ['success' => false, 'message' => '仅支持代管理返利类型', 'refund' => null];
        }

        // 代管理退款金额 = 券的实际支付金额（face_value）全额退款
        $refundAmount = (float)$voucher->face_value;
        if ($refundAmount <= 0) {
            Log::info("代管理退款[rebate_id={$rebate->id}]: 券面值为0，无需退款");
            return ['success' => true, 'message' => '券面值为0，无需退款', 'refund' => null];
        }

        // 检查是否已存在退款记录（使用 getData 安全访问，避免字段不存在报错）
        $custodyRefundId = isset($rebate['custody_refund_id']) ? $rebate['custody_refund_id'] : null;
        if ($custodyRefundId) {
            return ['success' => false, 'message' => '已存在退款记录', 'refund' => null];
        }

        // 获取订单信息
        $voucherOrder = VoucherOrder::get($voucher->order_id);
        if (!$voucherOrder) {
            return ['success' => false, 'message' => '订单不存在', 'refund' => null];
        }

        // 验证订单有微信支付流水号
        if (!$voucherOrder->transaction_id) {
            return ['success' => false, 'message' => '订单缺少微信支付流水号，无法发起退款', 'refund' => null];
        }

        Db::startTrans();
        try {
            // 1. 创建退款记录
            $refundNo = $this->generateRefundNo($rebate->id);
            $refund = new VoucherRefund();
            $refund->refund_no = $refundNo;
            $refund->voucher_id = $voucher->id;
            $refund->voucher_no = $voucher->voucher_no;
            $refund->order_id = $voucher->order_id;
            $refund->user_id = $voucher->user_id;
            $refund->refund_amount = $refundAmount;
            $refund->refund_reason = '代管理本金退还';
            $refund->state = 1;  // 同意退款（直接发起）
            $refund->refund_source = 'custody';  // 代管理退款
            $refund->rebate_id = $rebate->id;
            $refund->createtime = time();
            $refund->save();

            // 2. 更新返利记录的退款关联
            $rebate->custody_refund_id = $refund->id;
            $rebate->custody_refund_status = 'pending';  // 退款中
            $rebate->save();

            Db::commit();

            // 3. 发起微信退款（事务外执行）
            $wxResult = $this->executeWechatRefund($refund, $voucherOrder, $rebate);

            return $wxResult;

        } catch (Exception $e) {
            Db::rollback();
            Log::error("代管理退款创建失败[rebate_id={$rebate->id}]: " . $e->getMessage());
            return ['success' => false, 'message' => '创建退款记录失败: ' . $e->getMessage(), 'refund' => null];
        }
    }

    /**
     * 执行微信退款
     *
     * @param VoucherRefund $refund 退款记录
     * @param VoucherOrder $voucherOrder 订单
     * @param VoucherRebate $rebate 返利记录
     * @return array
     */
    protected function executeWechatRefund(VoucherRefund $refund, VoucherOrder $voucherOrder, VoucherRebate $rebate)
    {
        try {
            $config = config('wechat.payment');
            $refundNotifyUrl = isset($config['refund_notify_url']) ? $config['refund_notify_url'] : '';

            // 计算退款金额（单位：分）
            $refundAmountFen = (int)bcmul($refund->refund_amount, 100, 0);
            // 原订单总金额（单位：分）
            $totalAmountFen = (int)bcmul($voucherOrder->actual_payment, 100, 0);

            $refundParams = [
                'transaction_id' => $voucherOrder->transaction_id,
                'out_refund_no'  => $refund->refund_no,
                'reason'         => '代管理等量退款',  // 备注
                'refund_amount'  => $refundAmountFen,
                'total_amount'   => $totalAmountFen,
            ];

            // 如果配置了退款回调地址
            if ($refundNotifyUrl) {
                $refundParams['notify_url'] = $refundNotifyUrl;
            }

            $result = WechatPayment::refund($refundParams);

            Log::info('代管理退款发起成功[refund_no=' . $refund->refund_no . ']: ' . json_encode($result, JSON_UNESCAPED_UNICODE));

            return [
                'success' => true,
                'message' => '退款已提交，等待微信处理',
                'refund' => $refund
            ];

        } catch (Exception $e) {
            $errorMsg = $e->getMessage() ?: '未知错误';
            Log::error('代管理微信退款失败[refund_no=' . $refund->refund_no . ']: ' . $errorMsg);

            // 微信退款失败，更新状态
            Db::startTrans();
            try {
                // 更新退款状态为失败（使用 state=0 申请中，表示可重试）
                $refund->state = 0;
                $refund->refuse_reason = '微信退款失败: ' . $errorMsg;
                $refund->save();

                // 更新返利记录退款状态
                $rebate->custody_refund_status = 'failed';
                $rebate->save();

                Db::commit();
            } catch (Exception $rollbackEx) {
                Db::rollback();
                Log::error('代管理退款回滚失败: ' . $rollbackEx->getMessage());
            }

            return [
                'success' => false,
                'message' => '微信退款失败: ' . $errorMsg,
                'refund' => $refund
            ];
        }
    }

    /**
     * 重试代管理退款
     *
     * @param int $rebateId 返利记录ID
     * @return array
     */
    public function retryCustodyRefund($rebateId)
    {
        $rebate = VoucherRebate::where('id', $rebateId)
            ->where('rebate_type', 'custody')
            ->find();

        if (!$rebate) {
            return ['success' => false, 'message' => '返利记录不存在或非代管理类型'];
        }

        $custodyRefundId = isset($rebate['custody_refund_id']) ? $rebate['custody_refund_id'] : null;
        if (!$custodyRefundId) {
            return ['success' => false, 'message' => '无退款记录'];
        }

        $refund = VoucherRefund::get($custodyRefundId);
        if (!$refund) {
            return ['success' => false, 'message' => '退款记录不存在'];
        }

        // 只能重试失败或申请中的退款
        if (!in_array($refund->state, ['0'])) {
            return ['success' => false, 'message' => '退款状态不支持重试'];
        }

        $voucher = Voucher::get($rebate->voucher_id);
        if (!$voucher) {
            return ['success' => false, 'message' => '券不存在'];
        }

        $voucherOrder = VoucherOrder::get($voucher->order_id);
        if (!$voucherOrder) {
            return ['success' => false, 'message' => '订单不存在'];
        }

        // 更新状态为退款中
        Db::startTrans();
        try {
            $refund->state = 1;  // 同意退款
            $refund->refuse_reason = null;
            $refund->save();

            $rebate->custody_refund_status = 'pending';
            $rebate->save();

            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            return ['success' => false, 'message' => '更新状态失败: ' . $e->getMessage()];
        }

        // 重新发起微信退款
        return $this->executeWechatRefund($refund, $voucherOrder, $rebate);
    }

    /**
     * 处理代管理退款成功回调
     *
     * @param string $outRefundNo 商户退款单号
     * @return bool
     */
    public function handleRefundSuccess($outRefundNo)
    {
        // 判断是否为代管理退款（CRF开头）
        if (strpos($outRefundNo, 'CRF') !== 0) {
            return false;  // 不是代管理退款
        }

        Db::startTrans();
        try {
            $refund = VoucherRefund::where('refund_no', $outRefundNo)
                ->where('refund_source', 'custody')
                ->lock(true)
                ->find();

            if (!$refund) {
                Log::error('代管理退款记录不存在: ' . $outRefundNo);
                Db::rollback();
                return false;
            }

            // 防止重复处理
            if ($refund->state == 3) {
                Db::commit();
                return true;
            }

            // 更新退款记录状态
            $refund->state = 3;  // 退款成功
            $refund->save();

            // 更新返利记录退款状态
            if ($refund->rebate_id) {
                $rebate = VoucherRebate::get($refund->rebate_id);
                if ($rebate) {
                    $rebate->custody_refund_status = 'success';
                    $rebate->save();
                }
            }

            // 注意：代管理退款不需要更新券状态（券已经是已核销状态）
            // 也不需要更新订单状态

            Db::commit();
            Log::info('代管理退款成功处理完成: ' . $outRefundNo);
            return true;

        } catch (Exception $e) {
            Db::rollback();
            Log::error('代管理退款成功处理失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 处理代管理退款失败/关闭回调
     *
     * @param string $outRefundNo 商户退款单号
     * @param string $reason 失败原因
     * @return bool
     */
    public function handleRefundFailed($outRefundNo, $reason = '')
    {
        // 判断是否为代管理退款（CRF开头）
        if (strpos($outRefundNo, 'CRF') !== 0) {
            return false;  // 不是代管理退款
        }

        Db::startTrans();
        try {
            $refund = VoucherRefund::where('refund_no', $outRefundNo)
                ->where('refund_source', 'custody')
                ->lock(true)
                ->find();

            if (!$refund) {
                Db::rollback();
                return false;
            }

            // 更新退款记录状态
            $refund->state = 0;  // 回到申请中，可重试
            $refund->refuse_reason = $reason ?: '微信退款失败';
            $refund->save();

            // 更新返利记录退款状态
            if ($refund->rebate_id) {
                $rebate = VoucherRebate::get($refund->rebate_id);
                if ($rebate) {
                    $rebate->custody_refund_status = 'failed';
                    $rebate->save();
                }
            }

            Db::commit();
            Log::warning('代管理退款失败处理完成: ' . $outRefundNo . ', 原因: ' . $reason);
            return true;

        } catch (Exception $e) {
            Db::rollback();
            Log::error('代管理退款失败处理异常: ' . $e->getMessage());
            return false;
        }
    }
}
