<?php
namespace app\index\controller\wanlshop;

use app\common\controller\Wanlshop;
use addons\wanlshop\library\WanlPay\WanlPay;
use think\Db;
use think\Exception;
use think\exception\PDOException;
use think\exception\ValidateException;

/**
 * 图标管理
 *
 * @icon fa fa-circle-o
 */
class Finance extends Wanlshop
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
    }
    
    /**
     * 账单列表
     */
    public function bill()
    {
        //当前是否为关联查询
        $this->relationSearch = true;
        //设置过滤方法
        $this->request->filter(['strip_tags']);
		// 设置模块
		$this->model = model('app\common\model\MoneyLog');
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $total = $this->model
                ->where('user_id', $this->auth->id)
                ->where($where)
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->where('user_id', $this->auth->id)
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            $list = collection($list)->toArray();

            $result = array("total" => $total, "rows" => $list);
            return json($result);
        }
		// 定义账单类型列表
		$typeList = [
			'pay' => '服务购买',
			'refund' => '退款',
			'sys' => '系统调整'
		];
		$this->view->assign("typeList", $typeList);
        return $this->view->fetch();
    }
	/**
	 * 账单详情
	 */
	public function billDetail($ids = null)
	{
		$this->model = model('app\common\model\MoneyLog');
		$row = $this->model
			->where(['id' => $ids, 'user_id' => $this->auth->id])
			->find();
		if (!$row) {
		    $this->error(__('No Results were found'));
		}
		// 定义账单类型列表
		$typeList = [
			'pay' => '服务购买',
			'refund' => '退款',
			'sys' => '系统调整'
		];
		$this->view->assign("typeList", $typeList);
		$this->view->assign("row", $row);
		return $this->view->fetch();
	}
}