<?php

namespace app\api\controller\wanlshop;

use app\common\controller\Api;

/**
 * 广告接口
 *
 * 提供前端展示所需的广告API
 */
class Advert extends Api
{
    // 无需登录即可访问的方法
    protected $noNeedLogin = ['lists', 'detail', 'position'];

    // 无需权限验证
    protected $noNeedRight = ['*'];

    /**
     * 获取广告列表
     *
     * @ApiSummary  (获取广告列表 - 支持分页和筛选)
     * @ApiMethod   (GET)
     *
     * @ApiParams   (name="module", type="string", required=false, description="广告位置: open/page/category/first/other")
     * @ApiParams   (name="type", type="string", required=false, description="广告类型: banner/image/video")
     * @ApiParams   (name="category_id", type="integer", required=false, description="分类ID")
     * @ApiParams   (name="city", type="string", required=false, description="城市筛选(支持模糊匹配)")
     * @ApiParams   (name="limit", type="integer", required=false, description="每页数量,默认10")
     * @ApiParams   (name="page", type="integer", required=false, description="页码,默认1")
     *
     * @ApiReturn   ({
     *   "code": 1,
     *   "msg": "获取成功",
     *   "data": {
     *     "total": 20,
     *     "per_page": 10,
     *     "current_page": 1,
     *     "last_page": 2,
     *     "data": [
     *       {
     *         "id": 1,
     *         "title": "双十一促销",
     *         "media": "/uploads/advert/banner1.jpg",
     *         "url": "/pages/activity/detail?id=1",
     *         "module": "page",
     *         "module_text": "页面轮播",
     *         "type": "banner",
     *         "type_text": "横幅图",
     *         "weigh": 100,
     *         "createtime": 1696204800
     *       }
     *     ]
     *   }
     * })
     */
    public function lists()
    {
        // 设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);

        // 获取参数
        $module = $this->request->get('module', '');
        $type = $this->request->get('type', '');
        $categoryId = $this->request->get('category_id', 0);
        $city = $this->request->get('city', '');
        $limit = $this->request->get('limit', 10);

        // 构建查询条件
        $where = ['status' => 'normal']; // 只查询正常状态的广告

        if ($module) {
            $where['module'] = $module;
        }

        if ($type) {
            $where['type'] = $type;
        }

        if ($categoryId) {
            $where['category_id'] = $categoryId;
        }

        // 查询数据
        $advertModel = model('app\api\model\wanlshop\Advert');
        $query = $advertModel->where($where);

        // city 筛选 - 包含匹配
        if ($city) {
            $query->where('city', 'like', '%' . $city . '%');
        }

        $list = $query
            ->order('weigh', 'desc')  // 按权重降序
            ->order('id', 'desc')     // 按ID降序
            ->paginate($limit);

        $this->success('获取成功', $list);
    }

    /**
     * 获取指定位置的广告
     *
     * @ApiSummary  (获取指定位置的广告 - 常用于首页/分类页快速获取)
     * @ApiMethod   (GET)
     *
     * @ApiParams   (name="module", type="string", required=true, description="广告位置")
     * @ApiParams   (name="category_id", type="integer", required=false, description="分类ID")
     * @ApiParams   (name="city", type="string", required=false, description="城市筛选(支持模糊匹配)")
     * @ApiParams   (name="limit", type="integer", required=false, description="数量,默认5")
     *
     * @ApiReturn   ({
     *   "code": 1,
     *   "msg": "获取成功",
     *   "data": [
     *     {
     *       "id": 1,
     *       "title": "双十一促销",
     *       "media": "/uploads/advert/banner1.jpg",
     *       "url": "/pages/activity/detail?id=1",
     *       "type": "banner",
     *       "weigh": 100
     *     }
     *   ]
     * })
     */
    public function position()
    {
        // 设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);

        // 获取参数
        $module = $this->request->get('module', '');
        $categoryId = $this->request->get('category_id', 0);
        $city = $this->request->get('city', '');
        $limit = $this->request->get('limit', 5);

        // 验证必填参数
        if (!$module) {
            $this->error('请指定广告位置');
        }

        // 构建查询条件
        $where = [
            'status' => 'normal',
            'module' => $module
        ];

        if ($categoryId) {
            $where['category_id'] = $categoryId;
        }

        // 查询数据
        $advertModel = model('app\api\model\wanlshop\Advert');
        $query = $advertModel->where($where);

        // city 筛选 - 包含匹配
        if ($city) {
            $query->where('city', 'like', '%' . $city . '%');
        }

        $list = $query
            ->field('id,title,media,url,type,weigh')
            ->order('weigh', 'desc')
            ->limit($limit)
            ->select();

        $this->success('获取成功', $list);
    }

    /**
     * 获取广告详情
     *
     * @ApiSummary  (获取广告详情)
     * @ApiMethod   (GET)
     *
     * @ApiParams   (name="id", type="integer", required=true, description="广告ID")
     *
     * @ApiReturn   ({
     *   "code": 1,
     *   "msg": "获取成功",
     *   "data": {
     *     "id": 1,
     *     "title": "双十一促销",
     *     "media": "/uploads/advert/banner1.jpg",
     *     "url": "/pages/activity/detail?id=1",
     *     "module": "page",
     *     "module_text": "页面轮播",
     *     "type": "banner",
     *     "type_text": "横幅图",
     *     "weigh": 100,
     *     "category": {
     *       "id": 1,
     *       "name": "数码产品"
     *     }
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
            $this->error('缺少广告ID');
        }

        // 查询广告
        $advertModel = model('app\api\model\wanlshop\Advert');
        $advert = $advertModel
            ->with(['category'])
            ->where(['id' => $id, 'status' => 'normal'])
            ->find();

        if (!$advert) {
            $this->error('广告不存在或已下架');
        }

        // 可见分类字段
        if ($advert->category) {
            $advert->getRelation('category')->visible(['id', 'name']);
        }

        $this->success('获取成功', $advert);
    }
}
