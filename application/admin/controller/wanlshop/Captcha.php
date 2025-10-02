<?php

namespace app\admin\controller\wanlshop;

use app\common\controller\Backend;
use think\Db;
use Exception;
use think\exception\DbException;
use think\exception\PDOException;
use think\exception\ValidateException;
/**
 * 验证码原图
 *
 * @icon fa fa-circle-o
 */
class Captcha extends Backend
{

    /**
     * Captcha模型对象
     * @var \app\admin\model\wanlshop\Captcha
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\wanlshop\Captcha;

    }
    
    
    /**
     * 日志
     *
     * @return string|Json
     * @throws \think\Exception
     * @throws DbException
     */
    public function log()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);
        $this->model = new \app\admin\model\wanlshop\CaptchaLog;
        //如果发送的来源是 Selectpage，则转发到 Selectpage
        if ($this->request->request('keyField')) {
            return $this->selectpage();
        }
        [$where, $sort, $order, $offset, $limit] = $this->buildparams();
        $list = $this->model
            ->where($where)
            ->order($sort, $order)
            ->paginate($limit);
        $result = ['total' => $list->total(), 'rows' => $list->items()];
        return json($result);
    }
    
    
    /**
     * 清空全部
     *
     * @return string|Json
     * @throws \think\Exception
     * @throws DbException
     */
    public function clear()
    {
        //设置过滤方法
        $row = model('app\admin\model\wanlshop\CaptchaLog')
            ->where('id','egt',1)
            ->delete();
        if($row){
            $this->success();
        }else{
            $this->error('网络异常清空失败');
        }
    }
    
    
	/**
	 * 添加
	 *
	 * @return string
	 * @throws \think\Exception
	 */
	public function add()
	{
	    if (false === $this->request->isPost()) {
	        return $this->view->fetch();
	    }
	    $params = $this->request->post('row/a');
	    if (empty($params)) {
	        $this->error(__('Parameter %s can not be empty', ''));
	    }
	    $params = $this->preExcludeFields($params);
	
	    if ($this->dataLimit && $this->dataLimitFieldAutoFill) {
	        $params[$this->dataLimitField] = $this->auth->id;
	    }
		// 计算文件MD5 1.1.9升级
		$params['md5'] = md5_file(cdnurl($params['file']));
		
	    $result = false;
	    Db::startTrans();
	    try {
			
	        //是否采用模型验证
	        if ($this->modelValidate) {
	            $name = str_replace("\\model\\", "\\validate\\", get_class($this->model));
	            $validate = is_bool($this->modelValidate) ? ($this->modelSceneValidate ? $name . '.add' : $name) : $this->modelValidate;
	            $this->model->validateFailException()->validate($validate);
	        }
	        $result = $this->model->allowField(true)->save($params);
	        Db::commit();
	    } catch (ValidateException|PDOException|Exception $e) {
	        Db::rollback();
	        $this->error($e->getMessage());
	    }
	    if ($result === false) {
	        $this->error(__('No rows were inserted'));
	    }
	    $this->success();
	}
	
	/**
	 * 编辑
	 *
	 * @param $ids
	 * @return string
	 * @throws DbException
	 * @throws \think\Exception
	 */
	public function edit($ids = null)
	{
	    $row = $this->model->get($ids);
	    if (!$row) {
	        $this->error(__('No Results were found'));
	    }
	    $adminIds = $this->getDataLimitAdminIds();
	    if (is_array($adminIds) && !in_array($row[$this->dataLimitField], $adminIds)) {
	        $this->error(__('You have no permission'));
	    }
	    if (false === $this->request->isPost()) {
	        $this->view->assign('row', $row);
	        return $this->view->fetch();
	    }
	    $params = $this->request->post('row/a');
	    if (empty($params)) {
	        $this->error(__('Parameter %s can not be empty', ''));
	    }
	    $params = $this->preExcludeFields($params);
		// 计算文件MD5
		$params['md5'] = md5_file('.'.$params['file']);
	    $result = false;
	    Db::startTrans();
	    try {
	        //是否采用模型验证
	        if ($this->modelValidate) {
	            $name = str_replace("\\model\\", "\\validate\\", get_class($this->model));
	            $validate = is_bool($this->modelValidate) ? ($this->modelSceneValidate ? $name . '.edit' : $name) : $this->modelValidate;
	            $row->validateFailException()->validate($validate);
	        }
	        $result = $row->allowField(true)->save($params);
	        Db::commit();
	    } catch (ValidateException|PDOException|Exception $e) {
	        Db::rollback();
	        $this->error($e->getMessage());
	    }
	    if (false === $result) {
	        $this->error(__('No rows were updated'));
	    }
	    $this->success();
	}
}