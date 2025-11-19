<?php
namespace app\api\controller\wanlshop\voucher;

use app\common\controller\Api;
use app\admin\model\wanlshop\Voucher as VoucherModel;

/**
 * 核销券接口
 */
class Voucher extends Api
{
    protected $noNeedLogin = [];
    protected $noNeedRight = ['*'];

    /**
     * 我的券列表
     *
     * @ApiSummary  (获取我的核销券列表)
     * @ApiMethod   (GET)
     *
     * @param string $state 券状态(可选): 1=未使用,2=已核销,3=已过期,4=已退款
     */
    public function lists()
    {
        $this->request->filter(['strip_tags']);

        $state = $this->request->get('state');

        $where = [
            'user_id' => $this->auth->id,
            'status' => 'normal'
        ];

        if ($state && in_array($state, ['1', '2', '3', '4'])) {
            $where['state'] = $state;
        }

        // 分页查询
        $list = VoucherModel::with(['goods', 'shop', 'voucherOrder'])
            ->where($where)
            ->order('createtime desc')
            ->paginate(10);

        $this->success('ok', $list);
    }

    /**
     * 券详情
     *
     * @ApiSummary  (获取核销券详情)
     * @ApiMethod   (GET)
     *
     * @param int $id 券ID
     */
    public function detail()
    {
        $this->request->filter(['strip_tags']);

        $id = $this->request->get('id/d');
        if (!$id) {
            $this->error(__('参数错误'));
        }

        // 查询券并验证权限
        $voucher = VoucherModel::where([
            'id' => $id,
            'user_id' => $this->auth->id,
            'status' => 'normal'
        ])->find();

        if (!$voucher) {
            $this->error(__('券不存在'));
        }

        // 关联信息
        $voucher->state_text;
        $voucher->voucherOrder;
        $voucher->goods;
        $voucher->category;

        // 如果已核销,显示核销信息
        if ($voucher->state == 2) {
            $voucher->shop;
            $voucher->voucherVerification;
        }

        // 如果已退款,显示退款信息
        if ($voucher->state == 4) {
            $voucher->voucherRefund;
        }

        $this->success('ok', $voucher);
    }
}
