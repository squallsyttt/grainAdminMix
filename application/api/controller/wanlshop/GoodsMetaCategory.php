<?php

namespace app\api\controller\wanlshop;

use app\common\controller\Api;
use fast\Tree;

/**
 * 商品品牌/等级类目接口
 */
class GoodsMetaCategory extends Api
{
    protected $noNeedLogin = ['tree'];
    protected $noNeedRight = ['*'];

    /**
     * 获取品牌/等级类目树
     *
     * @ApiMethod (GET)
     * @ApiParams (name="type", type="string", required=true, description="brand|grade")
     */
    public function tree()
    {
        $this->request->filter(['strip_tags', 'trim']);
        $type = $this->request->get('type', '');
        if (!in_array($type, ['brand', 'grade'], true)) {
            $this->error('type 参数无效（仅支持 brand/grade）');
        }

        $model = model('app\api\model\wanlshop\GoodsMetaCategory');
        $list = $model
            ->where(['type' => $type, 'status' => 'normal'])
            ->field('id,pid,name,weigh')
            ->order('weigh', 'asc')
            ->order('id', 'asc')
            ->select();

        $tree = Tree::instance();
        $tree->init(collection($list)->toArray(), 'pid');
        $treeArray = $tree->getTreeArray(0);

        $this->success('获取成功', $treeArray);
    }
}

