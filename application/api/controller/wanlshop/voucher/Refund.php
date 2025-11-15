<?php
namespace app\api\controller\wanlshop\voucher;

use app\common\controller\Api;
use app\admin\model\wanlshop\VoucherRefund;
use app\admin\model\wanlshop\Voucher;
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

        // 只能退款未使用的券
        if ($voucher->state != 1) {
            $this->error(__('该券不可退款'));
        }

        // 读取退款配置
        $voucherConfig = config('voucher');
        $allowRefund = isset($voucherConfig['allow_refund']) ? (bool)$voucherConfig['allow_refund'] : true;
        if (!$allowRefund) {
            $this->error(__('当前暂不支持退款'));
        }

        $refundDays = isset($voucherConfig['refund_days']) ? (int)$voucherConfig['refund_days'] : 7;
        if ($refundDays > 0 && $voucher->valid_end) {
            $now = time();
            $remaining = $voucher->valid_end - $now;
            $limitSeconds = $refundDays * 86400;
            // 仅在到期前 N 天内允许发起退款
            if ($remaining > $limitSeconds) {
                $this->error(__('仅在到期前%s天内可申请退款', $refundDays));
            }
        }

        // 检查是否已有退款申请
        $existRefund = VoucherRefund::where([
            'voucher_id' => $voucherId,
            'state' => ['in', ['0', '1']]  // 申请中或已同意
        ])->find();

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
            $refund->state = 0;  // 申请中
            $refund->createtime = time();
            $refund->save();

            Db::commit();

            $this->success('退款申请已提交', [
                'refund_id' => $refund->id,
                'refund_no' => $refundNo
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
}
