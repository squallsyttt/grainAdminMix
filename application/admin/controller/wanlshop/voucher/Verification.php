<?php

namespace app\admin\controller\wanlshop\voucher;

use app\common\controller\Backend;

/**
 * 核销记录管理
 *
 * @icon fa fa-check-square
 */
class Verification extends Backend
{
    /**
     * VoucherVerification模型对象
     * @var \app\admin\model\wanlshop\VoucherVerification
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\wanlshop\VoucherVerification;
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
                ->with(['voucher' => function($query){ $query->with(['goods']); }, 'user'])
                ->where($where)
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->with(['voucher' => function($query){ $query->with(['goods']); }, 'user'])
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            foreach ($list as $row) {
                $row->getRelation('user')->visible(['username', 'nickname']);
                $row->getRelation('voucher')->visible(['voucher_no', 'goods_title', 'state', 'goods']);
                $row['region_city_name'] = (isset($row->voucher->goods) && $row->voucher->goods->region_city_name) ? $row->voucher->goods->region_city_name : '';
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
        $row->user;

        $this->view->assign("row", $row);
        return $this->view->fetch();
    }
}
