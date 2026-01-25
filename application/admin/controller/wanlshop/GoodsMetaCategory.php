<?php

namespace app\admin\controller\wanlshop;

use app\common\controller\Backend;
use fast\Tree;

/**
 * 商品品牌/等级类目管理
 *
 * @icon fa fa-tags
 */
class GoodsMetaCategory extends Backend
{
    /**
     * GoodsMetaCategory 模型对象
     *
     * @var \app\admin\model\wanlshop\GoodsMetaCategory
     */
    protected $model = null;

    protected $tree = null;
    protected $channelList = [];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\wanlshop\GoodsMetaCategory();

        $this->tree = Tree::instance();
        $type = $this->request->request('type');

        if ($type) {
            $rows = $this->model->where('type', $type)->order('weigh asc,id asc')->select();
        } else {
            $rows = $this->model->order('weigh asc,id asc')->select();
        }

        $this->tree->init(collection($rows)->toArray(), 'pid');
        $this->channelList = $this->tree->getTreeList($this->tree->getTreeArray(0), 'name');

        $this->view->assign('type', $type);
        $this->view->assign('channelList', $this->channelList);
        $this->view->assign('typeList', $this->model->getTypeList());
        $this->view->assign('statusList', $this->model->getStatusList());
    }

    /**
     * 查看（全部）
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            $list = array_values($this->channelList);
            return json(['total' => count($list), 'rows' => $list]);
        }
        return $this->view->fetch();
    }

    /**
     * 品牌类目
     */
    public function brand()
    {
        if ($this->request->isAjax()) {
            $list = [];
            foreach ($this->channelList as $item) {
                if ($item['type'] === 'brand') {
                    $item['channel'] = $this->tree->getChildrenIds($item['id'], true);
                    $list[] = $item;
                }
            }
            $list = array_values($list);
            return json(['total' => count($list), 'rows' => $list]);
        }
        return $this->view->fetch();
    }

    /**
     * 等级类目
     */
    public function grade()
    {
        if ($this->request->isAjax()) {
            $list = [];
            foreach ($this->channelList as $item) {
                if ($item['type'] === 'grade') {
                    $item['channel'] = $this->tree->getChildrenIds($item['id'], true);
                    $list[] = $item;
                }
            }
            $list = array_values($list);
            return json(['total' => count($list), 'rows' => $list]);
        }
        return $this->view->fetch();
    }

    /**
     * Selectpage 搜索
     *
     * @internal
     */
    public function selectpage()
    {
        return parent::selectpage();
    }
}

