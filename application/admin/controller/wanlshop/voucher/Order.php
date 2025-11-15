<?php

namespace app\admin\controller\wanlshop\voucher;

use app\common\controller\Backend;

/**
 * 核销券订单管理
 *
 * @icon fa fa-list-alt
 */
class Order extends Backend
{
    /**
     * VoucherOrder模型对象
     * @var \app\admin\model\wanlshop\VoucherOrder
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\wanlshop\VoucherOrder;
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
                ->with(['user', 'goods', 'category'])
                ->where($where)
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->with(['user', 'goods', 'category'])
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            foreach ($list as $row) {
                if ($row->user) {
                    $row->getRelation('user')->visible(['username', 'nickname']);
                }
                if ($row->goods) {
                    $row->getRelation('goods')->visible(['title', 'image']);
                }
                if ($row->category) {
                    $row->getRelation('category')->visible(['name']);
                }

                // 关联券数量
                $row->voucher_count = $row->vouchers ? count($row->vouchers) : 0;
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
        $row->goods;
        $row->category;

        // 关联生成的券列表
        $row->vouchers;

        $this->view->assign("row", $row);
        return $this->view->fetch();
    }
}
