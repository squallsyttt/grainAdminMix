<?php
namespace app\api\controller\wanlshop\voucher;

use app\common\controller\Api;
use app\admin\model\wanlshop\VoucherSettlement;
use app\common\library\WechatPayment;
use app\common\model\PaymentCallbackLog;
use app\common\model\TransferLog;
use think\Db;
use think\Log;
use Exception;

/**
 * 核销券结算接口(商家端)
 */
class Settlement extends Api
{
    protected $noNeedLogin = ['transferNotify'];
    protected $noNeedRight = ['*'];

    /**
     * 结算列表
     *
     * @ApiSummary  (获取结算列表)
     * @ApiMethod   (GET)
     *
     * @param int $shop_id 店铺ID
     * @param string $state 结算状态(可选): 1=待结算,2=已结算
     */
    public function lists()
    {
        $this->request->filter(['strip_tags']);

        $shopId = $this->request->get('shop_id/d');
        $state = $this->request->get('state');

        if (!$shopId) {
            $this->error(__('店铺ID不能为空'));
        }

        $where = [
            'shop_id' => $shopId,
            'status' => 'normal'
        ];

        if ($state && in_array($state, ['1', '2'])) {
            $where['state'] = $state;
        }

        // 分页查询
        $list = VoucherSettlement::where($where)
            ->order('createtime desc')
            ->paginate(10)
            ->each(function($settlement) {
                // 关联券信息
                $settlement->voucher;
                // 关联订单信息
                $settlement->voucherOrder;
                // 关联用户信息
                $settlement->user;
                return $settlement;
            });

        $this->success('ok', $list);
    }

    /**
     * 结算详情
     *
     * @ApiSummary  (获取结算详情)
     * @ApiMethod   (GET)
     *
     * @param int $id 结算ID
     */
    public function detail()
    {
        $this->request->filter(['strip_tags']);

        $id = $this->request->get('id/d');
        if (!$id) {
            $this->error(__('参数错误'));
        }

        // 查询结算记录
        $settlement = VoucherSettlement::where([
            'id' => $id,
            'status' => 'normal'
        ])->find();

        if (!$settlement) {
            $this->error(__('结算记录不存在'));
        }

        // 关联信息
        $settlement->voucher;
        $settlement->voucherOrder;
        $settlement->user;

        $this->success('ok', $settlement);
    }

    /**
     * 获取转账列表（当前用户）
     *
     * @ApiSummary  (获取转账列表，支持按状态筛选)
     * @ApiMethod   (GET)
     * @ApiParams   (name="status", type="string", required=false, description="状态筛选：1=待确认,2=成功,3=失败,all=全部，默认1")
     * @ApiParams   (name="pagesize", type="integer", required=false, description="每页数量，默认10，最大50")
     */
    public function pendingTransfers()
    {
        $this->request->filter(['strip_tags']);

        $userId = $this->auth ? (int)$this->auth->id : 0;
        if ($userId <= 0) {
            $this->error(__('未登录'));
        }

        $pageSize = (int)$this->request->get('pagesize/d', 10);
        if ($pageSize < 1) {
            $pageSize = 10;
        }
        if ($pageSize > 50) {
            $pageSize = 50;
        }

        // 状态筛选：1=待确认,2=成功,3=失败,all=全部
        $status = $this->request->get('status', '1');

        $query = TransferLog::where('receiver_user_id', $userId);

        if ($status !== 'all') {
            $statusVal = (int)$status;
            if (in_array($statusVal, [1, 2, 3], true)) {
                $query->where('status', $statusVal);
            } else {
                // 无效状态值，默认查待确认
                $query->where('status', 1);
            }
        }

        $list = $query->order('createtime desc')
            ->paginate($pageSize)
            ->each(function($item) {
                $item->package_payload = $item->package_info ? json_decode($item->package_info, true) : null;
                // 添加状态文字说明
                $statusMap = [1 => '待确认', 2 => '成功', 3 => '失败'];
                $item->status_text = $statusMap[$item->status] ?? '未知';
                return $item;
            });

        $this->success('ok', $list);
    }

    /**
     * 微信转账回调（商家转账到零钱）
     *
     * @ApiSummary  (微信转账回调通知)
     * @ApiMethod   (POST)
     */
    public function transferNotify()
    {
        $body = '';
        $headers = [];
        $resource = [];
        $outBillNo = '';
        $transferBillNo = '';
        $state = '';
        $callbackLog = null;

        try {
            $body = file_get_contents('php://input');
            Log::info('微信转账回调原始报文: ' . $body);

            // 读取回调头部
            $timestamp = $this->request->header('Wechatpay-Timestamp');
            $nonce     = $this->request->header('Wechatpay-Nonce');
            $signature = $this->request->header('Wechatpay-Signature');
            $serial    = $this->request->header('Wechatpay-Serial');

            $headers = [
                'timestamp' => $timestamp,
                'nonce'     => $nonce,
                'signature' => $signature,
                'serial'    => $serial,
            ];

            // 验签
            if (!WechatPayment::verifyCallbackSignature($headers, $body)) {
                Log::error('微信转账回调：签名验证失败');
                return json(['code' => 'FAIL', 'message' => '签名验证失败']);
            }

            $data = json_decode($body, true);
            if (!is_array($data) || empty($data['resource'])) {
                Log::error('微信转账回调：报文格式错误');
                return json(['code' => 'FAIL', 'message' => '报文格式错误']);
            }

            $resource = WechatPayment::decryptCallbackResource($data['resource']);
            $transferBillNo = $resource['transfer_bill_no'] ?? '';
            $outBillNo = $resource['out_bill_no'] ?? '';
            $state = (string)($resource['state'] ?? '');

            // 幂等：已处理直接返回成功
            if ($transferBillNo && PaymentCallbackLog::isProcessed($transferBillNo)) {
                Log::info('微信转账回调：重复通知，已处理 - ' . $transferBillNo);
                return json(['code' => 'SUCCESS', 'message' => '']);
            }

            // 记录回调日志（若存在则复用）
            $callbackLog = PaymentCallbackLog::where('transaction_id', $transferBillNo)->find();
            if (!$callbackLog) {
                $callbackLog = PaymentCallbackLog::recordCallback([
                    'order_type'       => 'voucher_transfer',
                    'order_no'         => $outBillNo,
                    'transaction_id'   => $transferBillNo,
                    'trade_state'      => $state,
                    'callback_body'    => $body,
                    'callback_headers' => json_encode($headers, JSON_UNESCAPED_UNICODE),
                    'verify_result'    => 'success',
                ]);
            }

            if ($outBillNo === '' || $transferBillNo === '') {
                $callbackLog->markProcessed('fail', ['error' => '缺少必要字段']);
                return json(['code' => 'FAIL', 'message' => '缺少必要字段']);
            }

            Db::startTrans();

            $transferLog = TransferLog::where('out_batch_no', $outBillNo)
                ->lock(true)
                ->order('id', 'desc')
                ->find();
            if (!$transferLog) {
                throw new Exception('转账日志不存在');
            }

            $settlement = VoucherSettlement::where('id', $transferLog->settlement_id)
                ->lock(true)
                ->find();
            if (!$settlement) {
                throw new Exception('结算记录不存在');
            }

            $needUserConfirm = in_array($state, ['WAIT_USER_CONFIRM', 'ACCEPTED'], true);
            $isSuccess = $state === 'SUCCESS';

            $transferLog->wechat_batch_id = $transferBillNo ?: $transferLog->wechat_batch_id;
            $transferLog->wechat_detail_id = $transferBillNo ?: $transferLog->wechat_detail_id;
            $transferLog->response_data = json_encode($resource, JSON_UNESCAPED_UNICODE);

            if ($isSuccess) {
                $transferLog->status = 2;
                $transferLog->fail_reason = null;
                $settlement->state = '2';
                $settlement->settlement_time = time();
            } elseif ($needUserConfirm) {
                $transferLog->status = 1;
                $transferLog->fail_reason = null;
                $settlement->state = '3';
            } else {
                $transferLog->status = 3;
                $transferLog->fail_reason = $resource['fail_reason'] ?? ($resource['fail_reason_desc'] ?? null);
                $settlement->state = '4';
            }

            $transferLog->save();
            $settlement->save();

            Db::commit();

            $callbackLog->markProcessed('success', [
                'settlement_id' => $settlement->id,
                'state' => $state
            ]);

            // 必须在5秒内返回成功
            return json(['code' => 'SUCCESS', 'message' => '']);
        } catch (Exception $e) {
            Db::rollback();
            Log::error('微信转账回调处理失败: ' . $e->getMessage());
            try {
                if ($callbackLog) {
                    $callbackLog->markProcessed('fail', ['error' => $e->getMessage()]);
                } else {
                    PaymentCallbackLog::recordCallback([
                        'order_type'       => 'voucher_transfer',
                        'order_no'         => $outBillNo,
                        'transaction_id'   => $transferBillNo,
                        'trade_state'      => $state,
                        'callback_body'    => $body,
                        'callback_headers' => json_encode($headers, JSON_UNESCAPED_UNICODE),
                        'verify_result'    => 'fail',
                    ])->markProcessed('fail', ['error' => $e->getMessage()]);
                }
            } catch (Exception $logEx) {
                Log::error('转账回调日志记录失败: ' . $logEx->getMessage());
            }
            return json(['code' => 'FAIL', 'message' => $e->getMessage()]);
        }
    }
}
