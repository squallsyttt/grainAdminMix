<?php
namespace app\api\controller\wanlshop\voucher;

use app\common\controller\Api;
use app\admin\model\wanlshop\VoucherRefund;
use app\admin\model\wanlshop\Voucher;
use app\admin\model\wanlshop\VoucherOrder;
use think\Db;
use think\Exception;

/**
 * 核销券退款接口
 */
class Refund extends Api
{
    protected $noNeedLogin = [];
    protected $noNeedRight = ['*'];

    /**
     * 申请退款
     *
     * @ApiSummary  (申请核销券退款)
     * @ApiMethod   (POST)
     *
     * @param int $voucher_id 券ID
     * @param string $refund_reason 退款理由
     */
    public function apply()
    {
        $this->request->filter(['strip_tags']);

        if (!$this->request->isPost()) {
            $this->error(__('非法请求'));
        }

        $voucherId = $this->request->post('voucher_id/d');
        $refundReason = $this->request->post('refund_reason', '');

        if (!$voucherId) {
            $this->error(__('参数错误'));
        }

        // 查询券并验证权限
        $voucher = Voucher::where([
            'id' => $voucherId,
            'user_id' => $this->auth->id,
            'status' => 'normal'
        ])->find();

        if (!$voucher) {
            $this->error(__('券不存在'));
        }

        // 判断券状态：支持 state=1(未使用) 或 state=2(已核销且24h内)
        $isUnused = ($voucher->state == 1);
        $isVerified24h = false;

        if ($voucher->state == 2 && $voucher->verifytime) {
            // 已核销券：检查是否在24小时内（含60秒容差）
            $elapsed = time() - $voucher->verifytime;
            $limit = 24 * 3600 + 60; // 24小时 + 60秒容差
            if ($elapsed <= $limit) {
                $isVerified24h = true;
            }
        }

        if (!$isUnused && !$isVerified24h) {
            $this->error(__('该券不可退款'));
        }

        // 读取退款配置
        $voucherConfig = config('voucher');
        $allowRefund = isset($voucherConfig['allow_refund']) ? (bool)$voucherConfig['allow_refund'] : true;
        if (!$allowRefund) {
            $this->error(__('当前暂不支持退款'));
        }

        // 未使用券：检查支付后N天内限制
        if ($isUnused) {
            $refundDays = isset($voucherConfig['refund_days']) ? (int)$voucherConfig['refund_days'] : 7;
            if ($refundDays > 0) {
                $order = VoucherOrder::get($voucher->order_id);
                if (!$order || !$order->paymenttime) {
                    $this->error(__('订单未支付，无法退款'));
                }

                $limitSeconds = $refundDays * 86400;
                $elapsed = time() - $order->paymenttime;
                if ($elapsed > $limitSeconds) {
                    $this->error(__('仅在支付后%s天内可申请退款', $refundDays));
                }
            }
        }

        // 检查是否已有退款申请（含商家待审核）
        $existRefund = VoucherRefund::where('voucher_id', $voucherId)
            ->where(function($query) {
                $query->whereIn('state', ['0', '1'])  // 申请中或已同意
                    ->whereOr('merchant_audit_state', 0);  // 或商家待审核
            })
            ->find();

        if ($existRefund) {
            $this->error(__('该券已有退款申请'));
        }

        // 生成退款单号
        $refundNo = 'RFD' . date('Ymd') . mt_rand(100000, 999999);

        Db::startTrans();
        try {
            // 创建退款记录
            $refund = new VoucherRefund();
            $refund->refund_no = $refundNo;
            $refund->voucher_id = $voucher->id;
            $refund->voucher_no = $voucher->voucher_no;
            $refund->order_id = $voucher->order_id;
            $refund->user_id = $this->auth->id;
            $refund->refund_amount = $voucher->face_value;
            $refund->refund_reason = $refundReason;
            $refund->createtime = time();

            if ($isVerified24h) {
                // 已核销24h内：需商家审核
                $refund->refund_source = 'verified_24h';
                $refund->merchant_audit_state = 0;  // 待审核
                $refund->shop_id = $voucher->shop_id;  // 记录核销店铺
                $refund->state = 0;  // 申请中（等待商家审核）
            } else {
                // 未使用券：直接进入后台退款流程
                $refund->refund_source = 'user';
                $refund->state = 0;  // 申请中
            }

            $refund->save();

            Db::commit();

            $message = $isVerified24h ? '退款申请已提交，等待商家审核' : '退款申请已提交';
            $this->success($message, [
                'refund_id' => $refund->id,
                'refund_no' => $refundNo,
                'need_merchant_audit' => $isVerified24h
            ]);
        } catch (Exception $e) {
            Db::rollback();
            $this->error(__('退款申请失败：') . $e->getMessage());
        }
    }

    /**
     * 退款详情
     *
     * @ApiSummary  (获取退款详情)
     * @ApiMethod   (GET)
     *
     * @param int $id 退款ID
     */
    public function detail()
    {
        $this->request->filter(['strip_tags']);

        $id = $this->request->get('id/d');
        if (!$id) {
            $this->error(__('参数错误'));
        }

        // 查询退款记录并验证权限
        $refund = VoucherRefund::where([
            'id' => $id,
            'user_id' => $this->auth->id,
            'status' => 'normal'
        ])->find();

        if (!$refund) {
            $this->error(__('退款记录不存在'));
        }

        // 关联信息
        $refund->state_text;
        $refund->voucher;
        $refund->voucherOrder;

        $this->success('ok', $refund);
    }

    /**
     * 退款列表
     *
     * @ApiSummary  (获取退款列表)
     * @ApiMethod   (GET)
     *
     * @param string $state 退款状态(可选): 0=申请中,1=同意退款,2=拒绝退款,3=退款成功
     */
    public function lists()
    {
        $this->request->filter(['strip_tags']);

        $state = $this->request->get('state');

        $where = [
            'user_id' => $this->auth->id,
            'status' => 'normal'
        ];

        if ($state && in_array($state, ['0', '1', '2', '3'])) {
            $where['state'] = $state;
        }

        // 分页查询
        $list = VoucherRefund::where($where)
            ->order('createtime desc')
            ->paginate(10)
            ->each(function($refund) {
                // 添加状态文本
                $refund->state_text;
                // 关联券信息
                $refund->voucher;
                return $refund;
            });

        $this->success('ok', $list);
    }

    /**
     * 商家待审核退款列表
     *
     * @ApiSummary  (商家获取待审核退款列表)
     * @ApiMethod   (GET)
     *
     * @param string $state 审核状态(可选): 0=待审核,1=已同意,2=已拒绝
     */
    public function merchantPendingList()
    {
        $this->request->filter(['strip_tags']);

        // 获取当前用户绑定的店铺（通过 user.bind_shop 字段）
        $user = model('app\common\model\User')->get($this->auth->id);
        $shopId = $user ? $user['bind_shop'] : null;
        if (!$shopId) {
            $this->error(__('您未绑定商家，无法查看'));
        }

        // 验证店铺存在
        $shop = \app\admin\model\wanlshop\Shop::where('id', $shopId)->find();
        if (!$shop) {
            $this->error(__('绑定的商家不存在'));
        }

        $state = $this->request->get('state');

        // 构建查询条件
        $query = VoucherRefund::where([
            'shop_id' => $shop->id,
            'refund_source' => 'verified_24h',
            'status' => 'normal'
        ]);

        // 默认查待审核
        if ($state !== null && in_array($state, ['0', '1', '2'])) {
            $query->where('merchant_audit_state', $state);
        } else {
            $query->where('merchant_audit_state', 0);
        }

        // 分页查询
        $list = $query->order('createtime desc')
            ->paginate(10)
            ->each(function($refund) {
                $refund->state_text;
                $refund->merchant_audit_state_text = $this->getMerchantAuditStateText($refund->merchant_audit_state);
                // 关联券信息
                $voucher = $refund->voucher;
                if ($voucher) {
                    $refund->voucher_info = [
                        'voucher_no' => $voucher->voucher_no,
                        'goods_title' => $voucher->goods_title,
                        'face_value' => $voucher->face_value,
                        'verifytime' => $voucher->verifytime,
                        'verifytime_text' => $voucher->verifytime ? date('Y-m-d H:i:s', $voucher->verifytime) : '',
                    ];
                }
                // 关联用户信息
                $user = $refund->user;
                if ($user) {
                    $refund->user_info = [
                        'nickname' => $user->nickname,
                        'avatar' => $user->avatar,
                    ];
                }
                return $refund;
            });

        // 统计待审核数量
        $pendingCount = VoucherRefund::where([
            'shop_id' => $shop->id,
            'refund_source' => 'verified_24h',
            'merchant_audit_state' => 0,
            'status' => 'normal'
        ])->count();

        $this->success('ok', [
            'list' => $list,
            'pending_count' => $pendingCount
        ]);
    }

    /**
     * 商家审核退款
     *
     * @ApiSummary  (商家审核退款申请)
     * @ApiMethod   (POST)
     *
     * @param int $id 退款ID
     * @param int $action 审核动作: 1=同意, 2=拒绝
     * @param string $remark 审核备注(拒绝时必填)
     */
    public function merchantAudit()
    {
        $this->request->filter(['strip_tags']);

        if (!$this->request->isPost()) {
            $this->error(__('非法请求'));
        }

        // 获取当前用户绑定的店铺（通过 user.bind_shop 字段）
        $user = model('app\common\model\User')->get($this->auth->id);
        $shopId = $user ? $user['bind_shop'] : null;
        if (!$shopId) {
            $this->error(__('您未绑定商家，无法审核'));
        }

        // 验证店铺存在
        $shop = \app\admin\model\wanlshop\Shop::where('id', $shopId)->find();
        if (!$shop) {
            $this->error(__('绑定的商家不存在'));
        }

        $id = $this->request->post('id/d');
        $action = $this->request->post('action/d');
        $remark = $this->request->post('remark', '');

        if (!$id || !in_array($action, [1, 2])) {
            $this->error(__('参数错误'));
        }

        // 拒绝时备注必填
        if ($action == 2 && empty($remark)) {
            $this->error(__('拒绝退款时必须填写理由'));
        }

        // 查询退款记录
        $refund = VoucherRefund::where([
            'id' => $id,
            'shop_id' => $shop->id,
            'refund_source' => 'verified_24h',
            'merchant_audit_state' => 0,  // 待审核
            'status' => 'normal'
        ])->find();

        if (!$refund) {
            $this->error(__('退款记录不存在或已处理'));
        }

        Db::startTrans();
        try {
            $refund->merchant_audit_state = $action;
            $refund->merchant_audit_time = time();
            $refund->merchant_audit_remark = $remark;

            if ($action == 1) {
                // 商家同意：进入后台退款流程
                $refund->state = 1;  // 同意退款（等待后台处理）
            } else {
                // 商家拒绝：流程结束
                $refund->state = 2;  // 拒绝退款
                $refund->refuse_reason = '商家拒绝：' . $remark;
            }

            $refund->save();

            Db::commit();

            $message = $action == 1 ? '已同意退款，等待平台处理' : '已拒绝退款';
            $this->success($message, [
                'refund_id' => $refund->id,
                'merchant_audit_state' => $refund->merchant_audit_state
            ]);
        } catch (Exception $e) {
            Db::rollback();
            $this->error(__('审核失败：') . $e->getMessage());
        }
    }

    /**
     * 获取商家审核状态文本
     * @param mixed $state
     * @return string
     */
    protected function getMerchantAuditStateText($state)
    {
        $list = [
            0 => '待审核',
            1 => '已同意',
            2 => '已拒绝',
        ];
        return isset($list[$state]) ? $list[$state] : '';
    }
}
