<?php

namespace app\admin\controller\wanlshop\voucher;

use app\common\controller\Backend;

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

        // 更新退款状态
        $row->state = 1;  // 同意退款
        $row->save();

        // TODO: 调用微信退款接口
        // MVP 简化：仅更新状态，实际退款线下处理

        $this->success(__('操作成功'));
    }

    /**
     * 拒绝退款
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

        // 更新退款状态
        $row->state = 2;  // 拒绝退款
        $row->refuse_reason = $refuseReason;
        $row->save();

        $this->success(__('操作成功'));
    }

    /**
     * 确认退款完成
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
            // 更新退款状态
            $row->state = 3;  // 退款成功
            $row->save();

            // 更新券状态
            $voucher = \app\admin\model\wanlshop\Voucher::get($row->voucher_id);
            if ($voucher) {
                $voucher->state = 4;  // 已退款
                $voucher->refundtime = time();
                $voucher->save();
            }

            \think\Db::commit();
            $this->success(__('操作成功'));
        } catch (\Exception $e) {
            \think\Db::rollback();
            $this->error(__('操作失败: ') . $e->getMessage());
        }
    }
}
