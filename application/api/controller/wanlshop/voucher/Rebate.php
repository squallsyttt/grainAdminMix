<?php
namespace app\api\controller\wanlshop\voucher;

use app\common\controller\Api;
use app\admin\model\wanlshop\VoucherRebate;

/**
 * 核销券返利记录接口(用户端)
 */
class Rebate extends Api
{
    protected $noNeedLogin = [];
    protected $noNeedRight = ['*'];

    /**
     * 返利记录列表
     *
     * @ApiSummary  (获取当前用户的返利记录列表)
     * @ApiMethod   (GET)
     */
    public function index()
    {
        $this->request->filter(['strip_tags']);

        // 当前用户返利记录，按创建时间倒序分页
        $list = VoucherRebate::with(['voucher', 'voucherOrder', 'shop'])
            ->where([
                'user_id' => $this->auth->id,
                'status' => 'normal'
            ])
            ->field('id,voucher_no,goods_title,rebate_amount,stage,actual_goods_weight,verify_time,createtime')
            ->order('createtime desc')
            ->paginate(10);

        $this->success('ok', $list);
    }

    /**
     * 返利记录详情
     *
     * @ApiSummary  (获取返利记录详情)
     * @ApiMethod   (GET)
     *
     * @param int $id 返利记录ID
     */
    public function detail()
    {
        $this->request->filter(['strip_tags']);

        $id = $this->request->get('id/d');
        if (!$id) {
            $this->error(__('参数错误'));
        }

        // 仅可查看自己的返利记录
        $rebate = VoucherRebate::with(['voucher', 'voucherOrder', 'shop'])
            ->where([
                'id' => $id,
                'user_id' => $this->auth->id,
                'status' => 'normal'
            ])
            ->find();

        if (!$rebate) {
            $this->error(__('返利记录不存在'));
        }

        $this->success('ok', $rebate);
    }
}
