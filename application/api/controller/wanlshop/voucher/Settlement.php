<?php
namespace app\api\controller\wanlshop\voucher;

use app\common\controller\Api;
use app\admin\model\wanlshop\VoucherSettlement;

/**
 * 核销券结算接口(商家端)
 */
class Settlement extends Api
{
    protected $noNeedLogin = [];
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
}
