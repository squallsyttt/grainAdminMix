<?php

namespace app\admin\service;

use app\admin\model\wanlshop\VoucherRebate;
use app\common\library\WechatPayment;
use app\common\model\TransferLog;
use Exception;
use think\Db;
use think\Log;

/**
 * 返利打款服务
 */
class RebateTransferService
{
    /**
     * 获取用户的小程序收款信息
     * 返利打款直接打给购买用户本人
     *
     * @param int $userId 用户ID
     * @return array|null
     */
    public function getReceiver(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        return Db::name('user')
            ->alias('u')
            ->join('wanlshop_third t', 't.user_id = u.id')
            ->where('u.id', $userId)
            ->where('t.platform', 'miniprogram')
            ->field('u.id,u.nickname,t.openid')
            ->find();
    }

    /**
     * 执行返利打款
     *
     * @param int $rebateId 返利ID
     * @return array
     */
    public function transfer(int $rebateId): array
    {
        $config = config('wechat.payment');
        $transferNotifyUrl = isset($config['transfer_notify_url']) ? trim($config['transfer_notify_url']) : '';

        if ($rebateId <= 0) {
            return ['success' => false, 'message' => '参数无效'];
        }

        $outBillNo = null;
        $requestData = null;
        $receiver = null;
        $rebate = null;
        $amountFen = 0;

        Db::startTrans();
        try {
            set_error_handler(function ($severity, $message, $file, $line) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });

            $rebate = VoucherRebate::where('id', $rebateId)->lock(true)->find();
            if (!$rebate) {
                throw new Exception('返利记录不存在');
            }

            // 检查是否可以打款
            if (!$rebate->canTransfer()) {
                if ($rebate->payment_status === VoucherRebate::PAYMENT_STATUS_PENDING) {
                    throw new Exception('当前状态为打款中，请勿重复操作');
                }
                if ($rebate->payment_status === VoucherRebate::PAYMENT_STATUS_PAID) {
                    throw new Exception('已打款，请勿重复操作');
                }
                if ($rebate->verify_time <= 0) {
                    throw new Exception('该记录尚未核销');
                }
                $sevenDaysAgo = time() - 7 * 86400;
                if ($rebate->payment_time >= $sevenDaysAgo) {
                    $daysLeft = ceil(($rebate->payment_time - $sevenDaysAgo) / 86400);
                    throw new Exception("付款时间未满7天，还需等待 {$daysLeft} 天");
                }
                throw new Exception('当前状态不可打款');
            }

            // 获取收款人（购买用户本人）
            $receiver = $this->getReceiver((int)$rebate->user_id);
            if (!$receiver || empty($receiver['openid'])) {
                throw new Exception('用户未绑定小程序，无法打款');
            }

            // 计算返利金额（直接使用表里的 rebate_amount 字段）
            $amountFen = (int)bcmul((float)$rebate->rebate_amount, 100, 0);
            if ($amountFen <= 0) {
                throw new Exception('返利金额无效');
            }

            // 生成单号：RBT 前缀区分返利打款
            $outBillNo = 'RBT' . date('YmdHis') . $rebateId;

            $requestData = [
                'out_bill_no'       => $outBillNo,
                'openid'            => $receiver['openid'],
                'transfer_amount'   => $amountFen,
                'transfer_remark'   => '核销券返利',
                'transfer_scene_id' => '1009', // 采购货款场景
                'scene_report_infos' => [
                    ['info_type' => '采购商品名称', 'info_content' => '核销券返利款'],
                ],
            ];
            if ($transferNotifyUrl !== '') {
                $requestData['notify_url'] = $transferNotifyUrl;
            }

            // 更新状态为打款中
            $rebate->payment_status = VoucherRebate::PAYMENT_STATUS_PENDING;
            $rebate->save();

            $result = WechatPayment::transferToWallet($requestData);

            // 处理返回状态
            $transferState = (string)($result['state'] ?? '');
            $needUserConfirm = in_array($transferState, ['WAIT_USER_CONFIRM', 'ACCEPTED'], true);
            $isImmediateSuccess = $transferState === 'SUCCESS';
            $logStatus = $isImmediateSuccess ? TransferLog::STATUS_SUCCESS : ($needUserConfirm ? TransferLog::STATUS_PENDING : TransferLog::STATUS_FAILED);

            // 记录打款日志
            TransferLog::create([
                'order_type'        => TransferLog::ORDER_TYPE_REBATE,
                'settlement_id'     => null,
                'rebate_id'         => $rebate->id,
                'out_batch_no'      => $outBillNo,
                'out_detail_no'     => $outBillNo,
                'transfer_amount'   => $amountFen,
                'receiver_openid'   => $receiver['openid'],
                'receiver_user_id'  => $rebate->user_id,
                'status'            => $logStatus,
                'wechat_batch_id'   => $result['transfer_bill_no'] ?? null,
                'wechat_detail_id'  => $result['transfer_bill_no'] ?? null,
                'fail_reason'       => $isImmediateSuccess || $needUserConfirm ? null : ($result['fail_reason'] ?? null),
                'request_data'      => json_encode($requestData, JSON_UNESCAPED_UNICODE),
                'response_data'     => json_encode($result, JSON_UNESCAPED_UNICODE),
                'package_info'      => isset($result['package_info']) ? json_encode($result['package_info'], JSON_UNESCAPED_UNICODE) : null,
            ]);

            // 根据返回状态更新返利记录
            if ($isImmediateSuccess) {
                $rebate->payment_status = VoucherRebate::PAYMENT_STATUS_PAID;
            } elseif ($needUserConfirm) {
                $rebate->payment_status = VoucherRebate::PAYMENT_STATUS_PENDING;
            } else {
                $rebate->payment_status = VoucherRebate::PAYMENT_STATUS_FAILED;
            }
            $rebate->save();

            Db::commit();

            return [
                'success' => true,
                'data' => [
                    'out_bill_no'       => $outBillNo,
                    'transfer_bill_no'  => $result['transfer_bill_no'] ?? null,
                    'transfer_state'    => $transferState,
                    'need_user_confirm' => $needUserConfirm,
                    'package_info'      => $result['package_info'] ?? null,
                    'amount'            => $amountFen,
                ]
            ];
        } catch (Exception $e) {
            Db::rollback();

            Log::error('返利打款异常: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            // 更新状态为失败
            if ($rebate && $rebate->id) {
                VoucherRebate::where('id', $rebate->id)->update([
                    'payment_status' => VoucherRebate::PAYMENT_STATUS_FAILED,
                    'updatetime' => time(),
                ]);
            }

            // 记录失败日志
            try {
                if ($rebate && $outBillNo) {
                    TransferLog::create([
                        'order_type'        => TransferLog::ORDER_TYPE_REBATE,
                        'settlement_id'     => null,
                        'rebate_id'         => $rebate->id,
                        'out_batch_no'      => $outBillNo,
                        'out_detail_no'     => $outBillNo,
                        'transfer_amount'   => $amountFen,
                        'receiver_openid'   => $receiver['openid'] ?? '',
                        'receiver_user_id'  => $rebate->user_id,
                        'status'            => TransferLog::STATUS_FAILED,
                        'fail_reason'       => $e->getMessage(),
                        'request_data'      => $requestData ? json_encode($requestData, JSON_UNESCAPED_UNICODE) : null,
                        'response_data'     => null,
                        'package_info'      => null,
                    ]);
                }
            } catch (Exception $logEx) {
                Log::error('记录返利打款失败日志异常: ' . $logEx->getMessage());
            }

            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            restore_error_handler();
        }
    }

    /**
     * 重试失败的返利打款
     *
     * @param int $rebateId 返利ID
     * @return array
     */
    public function retry(int $rebateId): array
    {
        if ($rebateId <= 0) {
            return ['success' => false, 'message' => '参数无效'];
        }

        $rebate = VoucherRebate::get($rebateId);
        if (!$rebate) {
            return ['success' => false, 'message' => '返利记录不存在'];
        }
        if ($rebate->payment_status !== VoucherRebate::PAYMENT_STATUS_FAILED) {
            return ['success' => false, 'message' => '仅支持对打款失败记录重试'];
        }

        // 代管理返利使用专用方法
        if ($rebate->rebate_type === 'custody') {
            return $this->transferCustody($rebateId);
        }

        return $this->transfer($rebateId);
    }

    /**
     * 执行代管理返利打款（无需等待7天）
     *
     * @param int $rebateId 返利ID
     * @return array
     */
    public function transferCustody(int $rebateId): array
    {
        $config = config('wechat.payment');
        $transferNotifyUrl = isset($config['transfer_notify_url']) ? trim($config['transfer_notify_url']) : '';

        if ($rebateId <= 0) {
            return ['success' => false, 'message' => '参数无效'];
        }

        $outBillNo = null;
        $requestData = null;
        $receiver = null;
        $rebate = null;
        $amountFen = 0;

        Db::startTrans();
        try {
            set_error_handler(function ($severity, $message, $file, $line) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });

            $rebate = VoucherRebate::where('id', $rebateId)->lock(true)->find();
            if (!$rebate) {
                throw new Exception('返利记录不存在');
            }

            // 代管理返利不需要等待7天，直接检查状态
            if ($rebate->payment_status === VoucherRebate::PAYMENT_STATUS_PENDING) {
                throw new Exception('当前状态为打款中，请勿重复操作');
            }
            if ($rebate->payment_status === VoucherRebate::PAYMENT_STATUS_PAID) {
                throw new Exception('已打款，请勿重复操作');
            }

            // 获取收款人（购买用户本人）
            $receiver = $this->getReceiver((int)$rebate->user_id);
            if (!$receiver || empty($receiver['openid'])) {
                throw new Exception('用户未绑定小程序，无法打款');
            }

            // 计算返利金额
            $amountFen = (int)bcmul((float)$rebate->rebate_amount, 100, 0);
            if ($amountFen <= 0) {
                throw new Exception('返利金额无效');
            }

            // 生成单号：CUS 前缀区分代管理返利打款
            $outBillNo = 'CUS' . date('YmdHis') . $rebateId;

            $requestData = [
                'out_bill_no'       => $outBillNo,
                'openid'            => $receiver['openid'],
                'transfer_amount'   => $amountFen,
                'transfer_remark'   => '代管理返利',
                'transfer_scene_id' => '1009', // 采购货款场景
                'scene_report_infos' => [
                    ['info_type' => '采购商品名称', 'info_content' => '核销券代管理返利款'],
                ],
            ];
            if ($transferNotifyUrl !== '') {
                $requestData['notify_url'] = $transferNotifyUrl;
            }

            // 更新状态为打款中
            $rebate->payment_status = VoucherRebate::PAYMENT_STATUS_PENDING;
            $rebate->save();

            $result = WechatPayment::transferToWallet($requestData);

            // 处理返回状态
            $transferState = (string)($result['state'] ?? '');
            $needUserConfirm = in_array($transferState, ['WAIT_USER_CONFIRM', 'ACCEPTED'], true);
            $isImmediateSuccess = $transferState === 'SUCCESS';
            $logStatus = $isImmediateSuccess ? TransferLog::STATUS_SUCCESS : ($needUserConfirm ? TransferLog::STATUS_PENDING : TransferLog::STATUS_FAILED);

            // 记录打款日志
            TransferLog::create([
                'order_type'        => TransferLog::ORDER_TYPE_REBATE,
                'settlement_id'     => null,
                'rebate_id'         => $rebate->id,
                'out_batch_no'      => $outBillNo,
                'out_detail_no'     => $outBillNo,
                'transfer_amount'   => $amountFen,
                'receiver_openid'   => $receiver['openid'],
                'receiver_user_id'  => $rebate->user_id,
                'status'            => $logStatus,
                'wechat_batch_id'   => $result['transfer_bill_no'] ?? null,
                'wechat_detail_id'  => $result['transfer_bill_no'] ?? null,
                'fail_reason'       => $isImmediateSuccess || $needUserConfirm ? null : ($result['fail_reason'] ?? null),
                'request_data'      => json_encode($requestData, JSON_UNESCAPED_UNICODE),
                'response_data'     => json_encode($result, JSON_UNESCAPED_UNICODE),
                'package_info'      => isset($result['package_info']) ? json_encode($result['package_info'], JSON_UNESCAPED_UNICODE) : null,
            ]);

            // 根据返回状态更新返利记录
            if ($isImmediateSuccess) {
                $rebate->payment_status = VoucherRebate::PAYMENT_STATUS_PAID;
            } elseif ($needUserConfirm) {
                $rebate->payment_status = VoucherRebate::PAYMENT_STATUS_PENDING;
            } else {
                $rebate->payment_status = VoucherRebate::PAYMENT_STATUS_FAILED;
            }
            $rebate->save();

            Db::commit();

            return [
                'success' => true,
                'data' => [
                    'out_bill_no'       => $outBillNo,
                    'transfer_bill_no'  => $result['transfer_bill_no'] ?? null,
                    'transfer_state'    => $transferState,
                    'need_user_confirm' => $needUserConfirm,
                    'package_info'      => $result['package_info'] ?? null,
                    'amount'            => $amountFen,
                ]
            ];
        } catch (Exception $e) {
            Db::rollback();

            Log::error('代管理返利打款异常: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            // 更新状态为失败
            if ($rebate && $rebate->id) {
                VoucherRebate::where('id', $rebate->id)->update([
                    'payment_status' => VoucherRebate::PAYMENT_STATUS_FAILED,
                    'updatetime' => time(),
                ]);
            }

            // 记录失败日志
            try {
                if ($rebate && $outBillNo) {
                    TransferLog::create([
                        'order_type'        => TransferLog::ORDER_TYPE_REBATE,
                        'settlement_id'     => null,
                        'rebate_id'         => $rebate->id,
                        'out_batch_no'      => $outBillNo,
                        'out_detail_no'     => $outBillNo,
                        'transfer_amount'   => $amountFen,
                        'receiver_openid'   => $receiver['openid'] ?? '',
                        'receiver_user_id'  => $rebate->user_id,
                        'status'            => TransferLog::STATUS_FAILED,
                        'fail_reason'       => $e->getMessage(),
                        'request_data'      => $requestData ? json_encode($requestData, JSON_UNESCAPED_UNICODE) : null,
                        'response_data'     => null,
                        'package_info'      => null,
                    ]);
                }
            } catch (Exception $logEx) {
                Log::error('记录代管理返利打款失败日志异常: ' . $logEx->getMessage());
            }

            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            restore_error_handler();
        }
    }
}
