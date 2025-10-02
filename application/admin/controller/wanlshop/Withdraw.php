<?php

namespace app\admin\controller\wanlshop;

use app\common\controller\Backend;
use think\Db;
use think\Exception;

use think\exception\PDOException;
use think\exception\ValidateException;

/**
 * 提现管理
 *
 * @icon fa fa-circle-o
 */
class Withdraw extends Backend
{
    
    /**
     * Withdraw模型对象
     * @var \app\admin\model\wanlshop\Withdraw
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\wanlshop\Withdraw;
        $this->view->assign("statusList", $this->model->getStatusList());
    }
	
	/**
	 * 查看
	 */
	public function index()
	{
	    //当前是否为关联查询
	    $this->relationSearch = true;
	    //设置过滤方法
	    $this->request->filter(['strip_tags', 'trim']);
	    if ($this->request->isAjax())
	    {
	        //如果发送的来源是Selectpage，则转发到Selectpage
	        if ($this->request->request('keyField'))
	        {
	            return $this->selectpage();
	        }
	        list($where, $sort, $order, $offset, $limit) = $this->buildparams();
	        $total = $this->model
	                ->with(['bank'])
	                ->where($where)
	                ->order($sort, $order)
	                ->count();
	
	        $list = $this->model
	                ->with(['bank'])
	                ->where($where)
	                ->order($sort, $order)
	                ->limit($offset, $limit)
	                ->select();
	
	        foreach ($list as $row) {
	            $row->getRelation('bank')->visible(['username']);
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
    public function detail($ids = null)
    {
    	$row = $this->model->get($ids);
    	if (!$row) {
    	    $this->error(__('No Results were found'));
    	}
		$row['bank'] = model('app\admin\model\wanlshop\PayAccount')
			->where(['cardCode' => $row['account'], 'user_id' => $this->auth->id])
			->find();
    	$this->view->assign("row", $row);
    	return $this->view->fetch();
    }
    
    /**
     * 同意
     */
    public function agree($ids = null)
    {
    	$row = $this->model->get($ids);
    	if (!$row) {
    	    $this->error(__('No Results were found'));
    	}
    	$adminIds = $this->getDataLimitAdminIds();
    	if (is_array($adminIds)) {
    	    if (!in_array($row[$this->dataLimitField], $adminIds)) {
    	        $this->error(__('You have no permission'));
    	    }
    	}
    	if ($row['status'] == 'successed') {
    	    $this->error(__('已审核过本店铺，请不要重复审核！'));
    	}
    	if ($this->request->isPost()) {
    		$result = false;
    		Db::startTrans();
    		try {
    		    //是否采用模型验证
    		    if ($this->modelValidate) {
    		        $name = str_replace("\\model\\", "\\validate\\", get_class($this->model));
    		        $validate = is_bool($this->modelValidate) ? ($this->modelSceneValidate ? $name . '.edit' : $name) : $this->modelValidate;
    		        $row->validateFailException(true)->validate($validate);
    		    }
    			// 审核通过
    		    $result = $row->allowField(true)->save([
					'status' => 'successed',
					'transfertime' => time()
				]);
    		    Db::commit();
    		} catch (ValidateException $e) {
    		    Db::rollback();
    		    $this->error($e->getMessage());
    		} catch (PDOException $e) {
    		    Db::rollback();
    		    $this->error($e->getMessage());
    		} catch (Exception $e) {
    		    Db::rollback();
    		    $this->error($e->getMessage());
    		}
    		if ($result !== false) {
    		    $this->success();
    		} else {
    		    $this->error(__('No rows were updated'));
    		}
    	}
    	$this->view->assign("row", $row);
    	return $this->view->fetch();
    }
    
    /**
     * 拒绝
     */
    public function refuse($ids = null)
    {
    	$row = $this->model->get($ids);
    	if (!$row) {
    	    $this->error(__('No Results were found'));
    	}
    	$adminIds = $this->getDataLimitAdminIds();
    	if (is_array($adminIds)) {
    	    if (!in_array($row[$this->dataLimitField], $adminIds)) {
    	        $this->error(__('You have no permission'));
    	    }
    	}
    	if ($this->request->isPost()) {
    	    $params = $this->request->post("row/a");
    	    if ($params) {
    	        $params = $this->preExcludeFields($params);
    	        $result = false;
    	        Db::startTrans();
    	        try {
    	            //是否采用模型验证
    	            if ($this->modelValidate) {
    	                $name = str_replace("\\model\\", "\\validate\\", get_class($this->model));
    	                $validate = is_bool($this->modelValidate) ? ($this->modelSceneValidate ? $name . '.edit' : $name) : $this->modelValidate;
    	                $row->validateFailException(true)->validate($validate);
    	            }
    				$params['status'] = 'rejected';
    	            $result = $row->allowField(true)->save($params);
					// 更新用户金额
					controller('addons\wanlshop\library\WanlPay\WanlPay')->money(+bcadd($row['money'], $row['handingfee'], 2), $row['user_id'], '提现失败返回余额', 'withdraw', $row['id']);
    	            Db::commit();
    	        } catch (ValidateException $e) {
    	            Db::rollback();
    	            $this->error($e->getMessage());
    	        } catch (PDOException $e) {
    	            Db::rollback();
    	            $this->error($e->getMessage());
    	        } catch (Exception $e) {
    	            Db::rollback();
    	            $this->error($e->getMessage());
    	        }
    	        if ($result !== false) {
    	            $this->success();
    	        } else {
    	            $this->error(__('No rows were updated'));
    	        }
    	    }
    	    $this->error(__('Parameter %s can not be empty', ''));
    	}
    	$this->view->assign("row", $row);
    	return $this->view->fetch();
    }
    

}
