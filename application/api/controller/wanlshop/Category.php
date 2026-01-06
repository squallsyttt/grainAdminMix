<?php

namespace app\api\controller\wanlshop;

use app\common\controller\Api;
use fast\Tree;

/**
 * 商品类目接口
 *
 * 提供前端展示商品类目所需的API
 */
class Category extends Api
{
    // 无需登录即可访问的方法
    protected $noNeedLogin = ['lists', 'tree', 'detail', 'recommendPriceTree'];

    // 无需权限验证
    protected $noNeedRight = ['*'];

    /**
     * 获取商品类目列表（扁平结构）
     *
     * @ApiSummary  (获取商品类目列表)
     * @ApiMethod   (GET)
     *
     * @ApiParams   (name="type", type="string", required=false, description="类型: goods=商品, article=文章")
     * @ApiParams   (name="pid", type="integer", required=false, description="父级ID,获取子类目")
     * @ApiParams   (name="isnav", type="integer", required=false, description="是否导航: 1=是, 0=否")
     *
     * @ApiReturn   ({
     *   "code": 1,
     *   "msg": "获取成功",
     *   "data": [
     *     {
     *       "id": 1,
     *       "pid": 0,
     *       "name": "数码产品",
     *       "image": "/uploads/category/digital.jpg",
     *       "type": "goods",
     *       "type_text": "商品",
     *       "flag": "hot,recommend",
     *       "flag_text": "热门,推荐",
     *       "weigh": 100,
     *       "status": "normal"
     *     }
     *   ]
     * })
     */
    public function lists()
    {
        // 设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);

        // 获取参数
        $type = $this->request->get('type', 'goods'); // 默认商品类型
        $pid = $this->request->get('pid', '');
        $isnav = $this->request->get('isnav', '');

        // 构建查询条件
        $where = ['status' => 'normal'];

        if ($type) {
            $where['type'] = $type;
        }

        if ($pid !== '') {
            $where['pid'] = $pid;
        }

        if ($isnav !== '') {
            $where['isnav'] = $isnav;
        }

        // 查询数据
        $categoryModel = model('app\api\model\wanlshop\Category');
        $list = $categoryModel
            ->where($where)
            ->field('id,pid,name,image,type,flag,weigh,status,isnav')
            ->order('weigh', 'asc')
            ->order('id', 'asc')
            ->select();

        $this->success('获取成功', $list);
    }

    /**
     * 获取商品类目树形结构
     *
     * @ApiSummary  (获取商品类目树形结构 - 用于级联选择)
     * @ApiMethod   (GET)
     *
     * @ApiParams   (name="type", type="string", required=false, description="类型: goods=商品, article=文章")
     * @ApiParams   (name="all", type="integer", required=false, description="是否获取全部分类: 1=是（商品编辑用），0=否（首页展示用，默认）")
     *
     * @ApiReturn   ({
     *   "code": 1,
     *   "msg": "获取成功",
     *   "data": [
     *     {
     *       "id": 1,
     *       "pid": 0,
     *       "name": "数码产品",
     *       "image": "/uploads/category/digital.jpg",
     *       "children": [
     *         {
     *           "id": 2,
     *           "pid": 1,
     *           "name": "手机",
     *           "image": "/uploads/category/phone.jpg",
     *           "children": []
     *         }
     *       ]
     *     }
     *   ]
     * })
     */
    public function tree()
    {
        // 设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);

        // 获取参数
        $type = $this->request->get('type', 'goods');
        $all = $this->request->get('all', 0);

        // 构建查询条件
        $where = [
            'status' => 'normal',
            'type' => $type
        ];

        // 如果请求全部分类（商品编辑用），或非商品类型，直接返回全部分类树
        if ($all || $type !== 'goods') {
            $categoryModel = model('app\api\model\wanlshop\Category');
            $list = $categoryModel
                ->where($where)
                ->field('id,pid,name,image,weigh')
                ->order('weigh', 'asc')
                ->order('id', 'asc')
                ->select();

            $tree = Tree::instance();
            $tree->init(collection($list)->toArray(), 'pid');
            $treeArray = $tree->getTreeArray(0);

            $this->success('获取成功', $treeArray);
            return;
        }

        // 获取 shop_id=1 店铺所在城市
        $shopId = 1;
        $shop = model('app\api\model\wanlshop\Shop')
            ->where(['id' => $shopId])
            ->field('delivery_city_code')
            ->find();

        if (!$shop) {
            $this->success('获取成功', []);
        }

        $cityCode = $shop['delivery_city_code'];

        // 1) shop_id=1 有上架商品的分类
        $goodsModel = model('app\api\model\wanlshop\Goods');
        $shopCategoryIds = $goodsModel
            ->where(['shop_id' => $shopId, 'status' => 'normal'])
            ->column('category_id');

        // 2) 同城其他店铺有上架商品的分类
        $otherShopCategoryIds = $goodsModel
            ->alias('g')
            ->join('wanlshop_shop s', 'g.shop_id = s.id')
            ->where('g.shop_id', '<>', $shopId)
            ->where('g.status', 'normal')
            ->where('s.delivery_city_code', $cityCode)
            ->where('s.status', 'normal')
            ->column('g.category_id');

        $validIds = array_intersect(array_unique($shopCategoryIds), array_unique($otherShopCategoryIds));

        // 查询所有类目
        $categoryModel = model('app\api\model\wanlshop\Category');
        $list = $categoryModel
            ->where($where)
            ->field('id,pid,name,image,weigh')
            ->order('weigh', 'asc')
            ->order('id', 'asc')
            ->select();

        $categories = collection($list)->toArray();

        // 3) 保留交集分类的完整子树：
        //    a) 先找出这些叶子分类的所有祖先
        //    b) 祖先节点一旦保留，其全部子孙分类都需要保留

        // 构建 id=>pid 与 pid=>children 映射，便于向上和向下遍历
        $idToPid = [];
        $childrenMap = [];
        foreach ($categories as $category) {
            $id = $category['id'];
            $pid = $category['pid'];
            $idToPid[$id] = $pid;
            if (!isset($childrenMap[$pid])) {
                $childrenMap[$pid] = [];
            }
            $childrenMap[$pid][] = $id;
        }

        // a) 叶子分类向上找到所有祖先
        $ancestorIds = [];
        foreach ($validIds as $id) {
            // 只处理存在于当前类型集合的分类
            if (!isset($idToPid[$id])) {
                continue;
            }
            $current = $id;
            while ($current && isset($idToPid[$current])) {
                if (isset($ancestorIds[$current])) {
                    break; // 已处理
                }
                $ancestorIds[$current] = true;
                $current = $idToPid[$current];
            }
        }

        // b) 祖先节点的整棵子树都需要保留
        $keepIds = [];
        $collectDescendants = function ($rootId) use (&$collectDescendants, $childrenMap, &$keepIds) {
            $stack = [$rootId];
            while (!empty($stack)) {
                $current = array_pop($stack);
                if (isset($keepIds[$current])) {
                    continue;
                }
                $keepIds[$current] = true;
                if (isset($childrenMap[$current])) {
                    foreach ($childrenMap[$current] as $childId) {
                        $stack[] = $childId;
                    }
                }
            }
        };

        foreach (array_keys($ancestorIds) as $rootId) {
            $collectDescendants($rootId);
        }

        $filtered = array_values(array_filter($categories, function ($item) use ($keepIds) {
            return isset($keepIds[$item['id']]);
        }));

        // 转换为树形结构
        $tree = Tree::instance();
        $tree->init($filtered, 'pid');
        $treeArray = $tree->getTreeArray(0);

        $this->success('获取成功', $treeArray);
    }

    /**
     * 获取带推荐价格的分类树
     *
     * @ApiSummary  (获取带推荐价格的分类树 - 用于商品编辑时参考定价)
     * @ApiMethod   (GET)
     *
     * @ApiReturn   ({
     *   "code": 1,
     *   "msg": "获取成功",
     *   "data": [
     *     {
     *       "id": 106,
     *       "pid": 0,
     *       "name": "糯",
     *       "recommend_price": null,
     *       "childlist": [
     *         {
     *           "id": 108,
     *           "pid": 106,
     *           "name": "粳糯",
     *           "recommend_price": "2.2~3.6",
     *           "childlist": []
     *         }
     *       ]
     *     }
     *   ]
     * })
     */
    public function recommendPriceTree()
    {
        // 查询所有 goods 类型的分类，包含 recommend_price 字段
        $categoryModel = model('app\api\model\wanlshop\Category');
        $list = $categoryModel
            ->where(['status' => 'normal', 'type' => 'goods'])
            ->field('id,pid,name,recommend_price,weigh')
            ->order('weigh', 'asc')
            ->order('id', 'asc')
            ->select();

        $categories = collection($list)->toArray();

        // 找出所有有 recommend_price 的分类ID
        $priceIds = [];
        foreach ($categories as $category) {
            if (!empty($category['recommend_price'])) {
                $priceIds[] = $category['id'];
            }
        }

        if (empty($priceIds)) {
            $this->success('获取成功', []);
            return;
        }

        // 构建 id => category 映射
        $idToCategory = [];
        foreach ($categories as $category) {
            $idToCategory[$category['id']] = $category;
        }

        // 找出所有需要保留的ID（有价格的分类及其祖先）
        $keepIds = [];
        foreach ($priceIds as $id) {
            $current = $id;
            while ($current && isset($idToCategory[$current])) {
                if (isset($keepIds[$current])) {
                    break; // 已处理过
                }
                $keepIds[$current] = true;
                $current = $idToCategory[$current]['pid'];
            }
        }

        // 过滤只保留需要的分类
        $filtered = array_filter($categories, function ($item) use ($keepIds) {
            return isset($keepIds[$item['id']]);
        });

        // 构建树形结构
        $tree = Tree::instance();
        $tree->init(array_values($filtered), 'pid');
        $treeArray = $tree->getTreeArray(0);

        $this->success('获取成功', $treeArray);
    }

    /**
     * 获取类目详情
     *
     * @ApiSummary  (获取类目详情)
     * @ApiMethod   (GET)
     *
     * @ApiParams   (name="id", type="integer", required=true, description="类目ID")
     *
     * @ApiReturn   ({
     *   "code": 1,
     *   "msg": "获取成功",
     *   "data": {
     *     "id": 1,
     *     "pid": 0,
     *     "name": "数码产品",
     *     "image": "/uploads/category/digital.jpg",
     *     "type": "goods",
     *     "type_text": "商品",
     *     "flag": "hot,recommend",
     *     "flag_text": "热门,推荐"
     *   }
     * })
     */
    public function detail()
    {
        // 设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);

        // 获取参数
        $id = $this->request->get('id', 0);

        if (!$id) {
            $this->error('缺少类目ID');
        }

        // 查询类目
        $categoryModel = model('app\api\model\wanlshop\Category');
        $category = $categoryModel
            ->where(['id' => $id, 'status' => 'normal'])
            ->find();

        if (!$category) {
            $this->error('类目不存在或已下架');
        }

        $this->success('获取成功', $category);
    }
}
