<?php

namespace app\admin\service;

use app\admin\model\wanlshop\VoucherSettlement;
use app\common\library\WechatPayment;
use app\common\model\TransferLog;
use Exception;
use think\Db;
use think\Log;

/**
 * 结算打款服务
 */
class SettlementTransferService
{
    /**
     * 判断结算单是否存在“进行中/已成功”的退款，存在则禁止结算打款
     *
     * @param int $voucherId
     * @return array|null
     */
    protected function getBlockingRefundByVoucherId(int $voucherId): ?array
    {
        $voucherId = (int)$voucherId;
        if ($voucherId <= 0) {
            return null;
        }

        // state: 0=申请中,1=同意退款,2=拒绝退款,3=退款成功
        // 结算侧必须拦截：申请中/已同意/退款成功
        $refund = Db::name('wanlshop_voucher_refund')
            ->where('voucher_id', $voucherId)
            ->where('state', 'in', ['0', '1', '3'])
            ->where('status', 'normal')
            ->whereNull('deletetime')
            ->field('id,refund_no,state,refund_source,merchant_audit_state,updatetime,createtime')
            ->order('id', 'desc')
            ->find();

        return $refund ? (array)$refund : null;
    }

    /**
     * 退款拦截提示文案
     *
     * @param array $refund
     * @return string
     */
    protected function buildRefundBlockMessage(array $refund): string
    {
        $state = isset($refund['state']) ? (string)$refund['state'] : '';
        $stateTextMap = [
            '0' => '申请中',
            '1' => '已同意退款',
            '3' => '退款成功',
        ];
        $stateText = $stateTextMap[$state] ?? '退款处理中';
        $refundNo = isset($refund['refund_no']) ? (string)$refund['refund_no'] : '';
        $suffix = $refundNo !== '' ? "（退款单号：{$refundNo}）" : '';
        return "该券存在{$stateText}退款记录{$suffix}，禁止结算打款";
    }

    /**
     * 查询绑定指定店铺的小程序收款人列表
     *
     * @param int $shopId 店铺ID
     * @return array
     */
    public function getReceivers(int $shopId): array
    {
        $shopId = (int)$shopId;
        if ($shopId <= 0) {
            return [];
        }

        return Db::name('user')
            ->alias('u')
            ->join('wanlshop_third t', 't.user_id = u.id')
            ->where('u.bind_shop', $shopId)
            ->where('t.platform', 'miniprogram')
            ->field('u.id,u.nickname,t.openid')
            ->select();
    }

    /**
     * 执行结算打款
     *
     * @param int $settlementId 结算ID
     * @param int $receiverUserId 收款人用户ID
     * @return array
     */
    public function transfer(int $settlementId, int $receiverUserId): array
    {
        $settlementId = (int)$settlementId;
        $receiverUserId = (int)$receiverUserId;
        $config = config('wechat.payment');
        $transferNotifyUrl = isset($config['transfer_notify_url']) ? trim($config['transfer_notify_url']) : '';

        if ($settlementId <= 0 || $receiverUserId <= 0) {
            return ['success' => false, 'message' => '参数无效'];
        }

        $outBillNo = null;
        $requestData = null;
        $receiver = null;
        $settlement = null;
        $amountFen = 0;

        Db::startTrans();
        try {
            // 将警告提升为异常，便于定位 strpos 等类型错误
            set_error_handler(function ($severity, $message, $file, $line) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });

            $settlement = VoucherSettlement::where('id', $settlementId)->lock(true)->find();
            if (!$settlement) {
                throw new Exception('结算记录不存在');
            }
            if (!in_array($settlement->state, ['1', '4'])) {
                throw new Exception('当前状态不可打款');
            }

            // 防止已退款/退款中的券继续给商家结算（严重资金漏洞）
            $blockingRefund = $this->getBlockingRefundByVoucherId((int)$settlement->voucher_id);
            if ($blockingRefund) {
                throw new Exception($this->buildRefundBlockMessage($blockingRefund));
            }

            $receiver = Db::name('user')
                ->alias('u')
                ->join('wanlshop_third t', 't.user_id = u.id')
                ->where('u.id', $receiverUserId)
                ->where('u.bind_shop', (int)$settlement->shop_id)
                ->where('t.platform', 'miniprogram')
                ->field('u.id,u.nickname,t.openid')
                ->find();

            if (!$receiver || empty($receiver['openid'])) {
                throw new Exception('收款人未绑定该店铺或缺少小程序 openid');
            }

            $amountFen = (int)bcmul(
                isset($settlement->shop_amount) ? (float)$settlement->shop_amount : (float)$settlement->supply_price,
                100,
                0
            );
            if ($amountFen <= 0) {
                throw new Exception('打款金额无效');
            }

            // 业务关联：携带结算ID方便后续回查
            $outBillNo = 'STL' . date('YmdHis') . $settlementId;

            $requestData = [
                'out_bill_no'      => $outBillNo,
                'openid'           => $receiver['openid'],
                'transfer_amount'  => $amountFen,
                'transfer_remark'  => '核销券结算打款',
                'transfer_scene_id' => '1009', // 采购货款场景
                'scene_report_infos' => [
                    ['info_type' => '采购商品名称', 'info_content' => '核销券结算款'],
                ],
            ];
            if ($transferNotifyUrl !== '') {
                $requestData['notify_url'] = $transferNotifyUrl;
            }

            // 更新状态为打款中
            $settlement->state = '3';
            $settlement->save();

            $result = WechatPayment::transferToWallet($requestData);

            // 新版API返回 state=WAIT_USER_CONFIRM 表示等待用户确认
            $transferState = (string)($result['state'] ?? '');
            $needUserConfirm = in_array($transferState, ['WAIT_USER_CONFIRM', 'ACCEPTED'], true);
            $isImmediateSuccess = $transferState === 'SUCCESS';
            $logStatus = $isImmediateSuccess ? 2 : ($needUserConfirm ? 1 : 3); // 1=待确认,2=成功,3=失败

            TransferLog::create([
                'settlement_id' => $settlement->id,
                'out_batch_no' => $outBillNo,
                'out_detail_no' => $outBillNo,
                'transfer_amount' => $amountFen,
                'receiver_openid' => $receiver['openid'],
                'receiver_user_id' => $receiverUserId,
                'status' => $logStatus,
                'wechat_batch_id' => $result['transfer_bill_no'] ?? null,
                'wechat_detail_id' => $result['transfer_bill_no'] ?? null,
                'fail_reason' => $isImmediateSuccess || $needUserConfirm ? null : ($result['fail_reason'] ?? null),
                'request_data' => json_encode($requestData, JSON_UNESCAPED_UNICODE),
                'response_data' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'package_info' => isset($result['package_info']) ? json_encode($result['package_info'], JSON_UNESCAPED_UNICODE) : null,
            ]);

            // 根据返回状态决定结算状态
            if ($isImmediateSuccess) {
                $settlement->state = '2';
                $settlement->settlement_time = time();
            } elseif ($needUserConfirm) {
                $settlement->state = '3';
            } else {
                $settlement->state = '4';
            }
            $settlement->save();

            Db::commit();

            return [
                'success' => true,
                'data' => [
                    'out_bill_no' => $outBillNo,
                    'transfer_bill_no' => $result['transfer_bill_no'] ?? null,
                    'transfer_state' => $transferState,
                    'need_user_confirm' => $needUserConfirm,
                    'package_info' => $result['package_info'] ?? null,
                    'amount' => $amountFen,
                ]
            ];
        } catch (Exception $e) {
            Db::rollback();

            Log::error('结算打款异常: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            if ($settlement && $settlement->id) {
                VoucherSettlement::where('id', $settlement->id)->update([
                    'state' => '4',
                    'updatetime' => time(),
                ]);
            }

            // 记录失败日志（不影响主流程异常抛出）
            try {
                if ($settlement && $outBillNo) {
                    TransferLog::create([
                        'settlement_id' => $settlement->id,
                        'out_batch_no' => $outBillNo,
                        'out_detail_no' => $outBillNo,
                        'transfer_amount' => $amountFen,
                        'receiver_openid' => $receiver['openid'] ?? '',
                        'receiver_user_id' => $receiverUserId,
                        'status' => 3,
                        'fail_reason' => $e->getMessage(),
                        'request_data' => $requestData ? json_encode($requestData, JSON_UNESCAPED_UNICODE) : null,
                        'response_data' => null,
                        'package_info' => null,
                    ]);
                }
            } catch (Exception $logEx) {
                Log::error('记录打款失败日志异常: ' . $logEx->getMessage());
            }

            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            restore_error_handler();
        }
    }

    /**
     * 重试失败的打款
     *
     * @param int $settlementId 结算ID
     * @return array
     */
    public function retry(int $settlementId): array
    {
        $settlementId = (int)$settlementId;
        if ($settlementId <= 0) {
            return ['success' => false, 'message' => '参数无效'];
        }

        $settlement = VoucherSettlement::get($settlementId);
        if (!$settlement) {
            return ['success' => false, 'message' => '结算记录不存在'];
        }
        if ($settlement->state !== '4') {
            return ['success' => false, 'message' => '仅支持对打款失败记录重试'];
        }

        $lastLog = TransferLog::where('settlement_id', $settlementId)
            ->order('id', 'desc')
            ->find();

        if (!$lastLog || (int)$lastLog->receiver_user_id <= 0) {
            return ['success' => false, 'message' => '未找到可用的打款记录'];
        }

        return $this->transfer($settlementId, (int)$lastLog->receiver_user_id);
    }
}
