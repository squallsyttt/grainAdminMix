<?php

namespace app\admin\controller\wanlshop\voucher;

use app\common\controller\Backend;

/**
 * 结算管理
 *
 * @icon fa fa-dollar
 */
class Settlement extends Backend
{
    /**
     * VoucherSettlement模型对象
     * @var \app\admin\model\wanlshop\VoucherSettlement
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\wanlshop\VoucherSettlement;
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
                $row->getRelation('voucher')->visible(['voucher_no', 'goods_title']);
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
     * 标记已结算
     */
    public function settle()
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
                // 只能结算待结算状态的记录
                if ($item->state == 1) {
                    $item->state = 2;  // 已结算
                    $item->settlement_time = time();
                    $item->save();
                    $count++;
                }
            }

            if ($count) {
                $this->success(__('结算成功'));
            } else {
                $this->error(__('没有可结算的记录'));
            }
        }
        $this->error(__('Parameter %s can not be empty', 'ids'));
    }
}
