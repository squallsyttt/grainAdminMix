<?php

namespace app\admin\controller\wanlshop\voucher;

use app\common\controller\Backend;

/**
 * 返利记录
 *
 * @icon fa fa-undo
 */
class Rebate extends Backend
{
    /**
     * VoucherRebate模型对象
     * @var \app\admin\model\wanlshop\VoucherRebate
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\wanlshop\VoucherRebate;
        $this->view->assign("stageList", $this->model->getStageList());
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
                ->with(['voucher', 'voucherOrder', 'user', 'shop'])
                ->where($where)
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->with(['voucher', 'voucherOrder', 'user', 'shop'])
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            foreach ($list as $row) {
                $row->getRelation('user')->visible(['username', 'nickname']);
                $row->getRelation('shop')->visible(['shopname']);
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
        $row = $this->model
            ->with(['voucher', 'voucherOrder', 'user', 'shop', 'voucherRule', 'verification'])
            ->find($id);
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        $this->view->assign("row", $row);
        $this->view->assign("stageList", $this->model->getStageList());
        return $this->view->fetch();
    }
}
