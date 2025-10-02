<?php
// 2020年2月17日21:41:44
namespace app\index\controller\wanlshop;

use app\common\controller\Wanlshop;

/**
 * 附件管理
 *
 * @icon fa fa-circle-o
 * @remark 主要用于管理上传到又拍云的数据或上传至本服务的上传数据
 */
class Attachment extends Wanlshop
{
    protected $model = null;
	protected $searchFields = 'id,filename,url';
	
    protected $noNeedLogin = '';
    protected $noNeedRight = '*';
	
    public function _initialize()
    {
        parent::_initialize();
        $this->model = model('Attachment');
        $this->view->assign("mimetypeList", \app\common\model\Attachment::getMimetypeList());
    }
    
    /**
     * 查看
     */
    public function index()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            $mimetypeQuery = [];
            $filter = $this->request->request('filter');
            $filterArr = (array)json_decode($filter, true);
            if (isset($filterArr['category']) && $filterArr['category'] == 'unclassed') {
                $filterArr['category'] = ',unclassed';
                $this->request->get(['filter' => json_encode(array_diff_key($filterArr, ['category' => '']))]);
            }
            if (isset($filterArr['mimetype']) && preg_match("/(\/|\,|\*)/", $filterArr['mimetype'])) {
                $mimetype = $filterArr['mimetype'];
                $filterArr = array_diff_key($filterArr, ['mimetype' => '']);
                $mimetypeQuery = function ($query) use ($mimetype) {
                    $mimetypeArr = array_filter(explode(',', $mimetype));
                    foreach ($mimetypeArr as $index => $item) {
                        $query->whereOr('mimetype', 'like', '%' . str_replace("/*", "/", $item) . '%');
                    }
                };
            }
            $this->request->get(['filter' => json_encode($filterArr)]);

            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

			$total = $this->model
					->where($mimetypeQuery)
			        ->where($where)
			        ->order($sort, $order)
			        ->count();
			    
			$list = $this->model
					->where($mimetypeQuery)
			        ->where($where)
			        ->order($sort, $order)
			        ->limit($offset, $limit)
			        ->select();

            $cdnurl = preg_replace("/\/(\w+)\.php$/i", '', $this->request->root());
            foreach ($list as $k => &$v) {
                $v['fullurl'] = ($v['storage'] == 'local' ? $cdnurl : $this->view->config['upload']['cdnurl']) . $v['url'];
            }
            unset($v);
            $result = array("total" => $total, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }
    
    /**
     * 选择附件
     */
    public function select()
    {
        if ($this->request->isAjax()) {
            return $this->index();
        }
        $mimetype = $this->request->get('mimetype', '');
        $mimetype = substr($mimetype, -1) === '/' ? $mimetype . '*' : $mimetype;
        $this->view->assign('mimetype', $mimetype);
        return $this->view->fetch();
    }
    
	 /**
     * 添加
     */
    public function add()
    {
        if ($this->request->isAjax()) {
            $this->error();
        }
        return $this->view->fetch();
    }
	
	
    /**
     * 删除附件
     * @param array $ids
     */
    public function del($ids = "")
    {
         if (!$this->request->isPost()) {
            $this->error(__("Invalid parameters"));
        }
        $ids = $ids ? $ids : $this->request->post("ids");
        if ($ids) {
            \think\Hook::add('upload_delete', function ($params) {
                if ($params['storage'] == 'local') {
                    $attachmentFile = ROOT_PATH . '/public' . $params['url'];
                    if (is_file($attachmentFile)) {
                        @unlink($attachmentFile);
                    }
                }
            });
            $attachmentlist = $this->model->where('id', 'in', $ids)->select();
            foreach ($attachmentlist as $attachment) {
                \think\Hook::listen("upload_delete", $attachment);
                $attachment->delete();
            }
            $this->success();
        }
        $this->error(__('Parameter %s can not be empty', 'ids'));
    }
}
