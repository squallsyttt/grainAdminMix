<?php

namespace app\admin\controller\wanlshop\voucher;

use app\common\controller\Backend;

/**
 * 核销券管理
 *
 * @icon fa fa-ticket
 */
class Voucher extends Backend
{
    /**
     * Voucher模型对象
     * @var \app\admin\model\wanlshop\Voucher
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\wanlshop\Voucher;
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
                ->with(['user', 'voucherOrder', 'goods', 'shop'])
                ->where($where)
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->with(['user', 'voucherOrder', 'goods', 'shop'])
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            foreach ($list as $row) {
                $row->getRelation('user')->visible(['username', 'nickname']);
                $row->getRelation('goods')->visible(['title']);
                if ($row->shop) {
                    $row->getRelation('shop')->visible(['shopname']);
                }
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
        $row->user;
        $row->voucherOrder;
        $row->goods;
        $row->category;

        // 如果已核销，显示核销和结算信息
        if ($row->state == 2) {
            $row->shop;
            $row->voucherVerification;
            $row->voucherSettlement;
        }

        // 如果已退款，显示退款信息
        if ($row->state == 4) {
            $row->voucherRefund;
        }

        $this->view->assign("row", $row);
        return $this->view->fetch();
    }

    /**
     * 作废券
     */
    public function cancel()
    {
        $ids = $this->request->post("ids");
        if ($ids) {
            $pk = $this->model->getPk();
            $adminIds = $this->getDataLimitAdminIds();
            if (is_array($adminIds)) {
                $this->model->where($this->dataLimitField, 'in', $adminIds);
            }

            $list = $this->model->where($pk, 'in', $ids)->select();
            $count = 0;

            foreach ($list as $item) {
                // 只能作废未使用的券
                if ($item->state == 1) {
                    $item->state = 3;  // 已过期（作废）
                    $item->save();
                    $count++;
                }
            }

            if ($count) {
                $this->success(__('作废成功'));
            } else {
                $this->error(__('没有可作废的券'));
            }
        }
        $this->error(__('Parameter %s can not be empty', 'ids'));
    }
}
