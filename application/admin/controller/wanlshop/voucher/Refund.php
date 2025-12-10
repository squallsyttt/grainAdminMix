<?php

namespace app\admin\controller\wanlshop\voucher;

use app\common\controller\Backend;
use app\common\library\WechatPayment;

/**
 * 退款管理
 *
 * @icon fa fa-reply
 */
class Refund extends Backend
{
    /**
     * VoucherRefund模型对象
     * @var \app\admin\model\wanlshop\VoucherRefund
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\wanlshop\VoucherRefund;
        $this->view->assign("stateList", $this->model->getStateList());
    }

    /**
     * 查看
     */
    public function index()
    {
        // 当前是否为关联查询
        $this->relationSearch = true;
        // 设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);

        if ($this->request->isAjax()) {
            // 如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }

            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            $total = $this->model
                ->with(['voucher', 'voucherOrder', 'user'])
                ->where($where)
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->with(['voucher', 'voucherOrder', 'user'])
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            foreach ($list as $row) {
                $row->getRelation('user')->visible(['username', 'nickname']);
                $row->getRelation('voucher')->visible(['voucher_no', 'goods_title', 'state']);
            }

            $list = collection($list)->toArray();
            $result = array("total" => $total, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }

    /**
     * 详情
     */
    public function detail($id = null)
    {
        $row = $this->model->get($id);
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        // 关联信息
        $row->voucher;
        $row->voucherOrder;
        $row->user;

        $this->view->assign("row", $row);
        return $this->view->fetch();
    }

    /**
     * 同意退款
     *
     * 影响的表：
     * - grain_wanlshop_voucher_refund.state: 0 -> 1 (申请中 -> 同意退款)
     * - grain_wanlshop_voucher.state: 1/5 -> 5 (退款中)
     *
     * 流程说明：
     * 1. 更新本地数据库状态为"同意退款"
     * 2. 调用微信退款 API
     * 3. 微信异步通知退款结果（通过 refundNotify 回调处理最终状态）
     */
    public function approve()
    {
        $row = $this->model->get($this->request->post('id'));
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        // 只能审核申请中的退款
        if ($row->state != 0) {
            $this->error(__('该退款不可审核'));
        }

        // 获取关联的订单信息
        $voucherOrder = \app\admin\model\wanlshop\VoucherOrder::get($row->order_id);
        if (!$voucherOrder) {
            $this->error(__('订单不存在'));
        }

        // 验证订单有微信支付流水号
        if (!$voucherOrder->transaction_id) {
            $this->error(__('订单缺少微信支付流水号，无法发起退款'));
        }

        // 开始事务
        \think\Db::startTrans();
        try {
            // 1. 更新退款状态
            $row->state = 1;  // 同意退款
            $row->save();

            // 2. 更新券状态为"退款中"
            $voucher = \app\admin\model\wanlshop\Voucher::get($row->voucher_id);
            if ($voucher) {
                $voucher->state = 5;  // 退款中
                $voucher->save();
            }

            \think\Db::commit();
        } catch (\Exception $e) {
            \think\Db::rollback();
            $this->error(__('操作失败: ') . $e->getMessage());
        }

        // 3. 调用微信退款接口（事务外执行，避免长时间锁表）
        try {
            $config = config('wechat.payment');
            $refundNotifyUrl = isset($config['refund_notify_url']) ? $config['refund_notify_url'] : '';

            // 计算退款金额（单位：分）
            $refundAmountFen = (int)bcmul($row->refund_amount, 100, 0);
            // 原订单总金额（单位：分）
            $totalAmountFen = (int)bcmul($voucherOrder->actual_payment, 100, 0);

            $refundParams = [
                'transaction_id' => $voucherOrder->transaction_id,
                'out_refund_no'  => $row->refund_no,
                'reason'         => $row->refund_reason ?: '用户申请退款',
                'refund_amount'  => $refundAmountFen,
                'total_amount'   => $totalAmountFen,
            ];

            // 如果配置了退款回调地址
            if ($refundNotifyUrl) {
                $refundParams['notify_url'] = $refundNotifyUrl;
            }

            $result = WechatPayment::refund($refundParams);

            \think\Log::info('微信退款发起成功: ' . json_encode($result, JSON_UNESCAPED_UNICODE));

            // 退款已提交，等待微信异步通知
            $this->success(__('退款已提交，等待微信处理'));

        } catch (\think\exception\HttpResponseException $e) {
            // success/error 方法会抛出此异常，需要重新抛出让框架处理
            throw $e;
        } catch (\Exception $e) {
            // 微信退款失败，需要回滚本地状态
            $errorMsg = $e->getMessage() ?: '未知错误';
            \think\Log::error('微信退款失败: ' . $errorMsg);

            \think\Db::startTrans();
            try {
                // 恢复退款状态为申请中
                $row->state = 0;
                $row->save();

                // 恢复券状态
                if ($voucher && $voucher->state == 5) {
                    $voucher->state = 1;  // 恢复为未使用
                    $voucher->save();
                }

                \think\Db::commit();
            } catch (\Exception $rollbackEx) {
                \think\Db::rollback();
                \think\Log::error('回滚失败: ' . $rollbackEx->getMessage());
            }

            $this->error(__('微信退款失败: ') . $errorMsg);
        }
    }

    /**
     * 拒绝退款
     *
     * 影响的表：
     * - grain_wanlshop_voucher_refund.state: 0 -> 2 (申请中 -> 拒绝退款)
     * - grain_wanlshop_voucher_refund.refuse_reason: 填入拒绝理由
     * - grain_wanlshop_voucher.state: 5 -> 1 (退款中 -> 未使用，恢复可用)
     */
    public function reject()
    {
        $row = $this->model->get($this->request->post('id'));
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        // 只能审核申请中的退款
        if ($row->state != 0) {
            $this->error(__('该退款不可审核'));
        }

        $refuseReason = $this->request->post('refuse_reason', '');
        if (!$refuseReason) {
            $this->error(__('请填写拒绝理由'));
        }

        // 开始事务
        \think\Db::startTrans();
        try {
            // 1. 更新退款状态
            $row->state = 2;  // 拒绝退款
            $row->refuse_reason = $refuseReason;
            $row->save();

            // 2. 恢复券状态为"未使用"（如果券还在退款中状态）
            $voucher = \app\admin\model\wanlshop\Voucher::get($row->voucher_id);
            if ($voucher && $voucher->state == 5) {
                $voucher->state = 1;  // 未使用，恢复可用
                $voucher->save();
            }

            \think\Db::commit();
            $this->success(__('操作成功'));
        } catch (\think\exception\HttpResponseException $e) {
            // success/error 方法会抛出此异常，需要重新抛出让框架处理
            throw $e;
        } catch (\Exception $e) {
            \think\Db::rollback();
            $this->error(__('操作失败: ') . $e->getMessage());
        }
    }

    /**
     * 确认退款完成
     *
     * 影响的表：
     * - grain_wanlshop_voucher_refund.state: 1 -> 3 (同意退款 -> 退款成功)
     * - grain_wanlshop_voucher.state: 5 -> 4 (退款中 -> 已退款)
     * - grain_wanlshop_voucher.refundtime: 填入当前时间戳
     * - grain_wanlshop_voucher_order.state: 2 -> 4 (已支付 -> 存在退款)
     */
    public function complete()
    {
        $row = $this->model->get($this->request->post('id'));
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        // 只能完成已同意的退款
        if ($row->state != 1) {
            $this->error(__('该退款不可完成'));
        }

        // 开始事务
        \think\Db::startTrans();
        try {
            // 1. 更新退款状态
            $row->state = 3;  // 退款成功
            $row->save();

            // 2. 更新券状态
            $voucher = \app\admin\model\wanlshop\Voucher::get($row->voucher_id);
            if ($voucher) {
                $voucher->state = 4;  // 已退款
                $voucher->refundtime = time();
                $voucher->save();
            }

            // 3. 更新订单状态（一个订单可能有多张券，标记为"存在退款"）
            $voucherOrder = \app\admin\model\wanlshop\VoucherOrder::get($row->order_id);
            if ($voucherOrder && $voucherOrder->state == 2) {
                $voucherOrder->state = 4;  // 存在退款
                $voucherOrder->save();
            }

            // 4. 【新增】取消店铺邀请返利待审核记录
            $this->cancelShopInvitePending($row->voucher_id);

            \think\Db::commit();
            $this->success(__('操作成功'));
        } catch (\think\exception\HttpResponseException $e) {
            // success/error 方法会抛出此异常，需要重新抛出让框架处理
            throw $e;
        } catch (\Exception $e) {
            \think\Db::rollback();
            $this->error(__('操作失败: ') . $e->getMessage());
        }
    }

    /**
     * 退款成功后取消店铺邀请返利待审核记录
     *
     * @param int $voucherId 券ID
     */
    protected function cancelShopInvitePending($voucherId)
    {
        \think\Db::name('shop_invite_pending')
            ->where('voucher_id', $voucherId)
            ->where('state', 0)
            ->update([
                'state' => 2, // 已取消（退款）
                'updatetime' => time()
            ]);
    }
}
