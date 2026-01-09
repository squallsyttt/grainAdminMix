<?php
/**
 * 商家商品管理 API
 *
 * 核心原则：
 * - 当前登录的是用户账户（通过 token 识别）
 * - 用户绑定的店铺是独立实体（通过 user.bind_shop 字段关联）
 * - 所有操作必须校验用户已绑定店铺，且只能操作绑定店铺的商品
 */

namespace app\api\controller\wanlshop;

use app\common\controller\Api;
use think\Db;
use think\Exception;

class MerchantGoods extends Api
{
    // 所有接口都需要登录
    protected $noNeedLogin = [];
    protected $noNeedRight = ['*'];

    /**
     * 商品模型
     */
    protected $goodsModel = null;

    /**
     * 初始化
     */
    public function _initialize()
    {
        parent::_initialize();
        $this->goodsModel = model('app\api\model\wanlshop\Goods');
    }

    /**
     * 获取当前用户绑定的店铺ID
     *
     * @return int|null
     */
    protected function getBindShopId()
    {
        $userId = $this->auth->id;
        if (!$userId) {
            return null;
        }
        $user = model('app\common\model\User')->get($userId);
        return $user ? $user['bind_shop'] : null;
    }

    /**
     * 校验绑定店铺，返回店铺ID
     *
     * @return int
     */
    protected function checkBindShop()
    {
        $bindShopId = $this->getBindShopId();
        if (!$bindShopId) {
            $this->error('请先绑定店铺', null, 403);
        }
        return $bindShopId;
    }

    /**
     * 获取绑定店铺信息
     *
     * @return array|null
     */
    protected function getBindShop()
    {
        $bindShopId = $this->getBindShopId();
        if (!$bindShopId) {
            return null;
        }
        return model('app\api\model\wanlshop\Shop')->get($bindShopId);
    }

    /**
     * 商品列表
     *
     * @ApiMethod (GET)
     * @ApiParams (name="status", type="string", required=false, description="状态筛选：normal-上架中,hidden-已下架,空-全部")
     * @ApiParams (name="page", type="int", required=false, description="页码")
     * @ApiParams (name="limit", type="int", required=false, description="每页数量")
     */
    public function list()
    {
        $bindShopId = $this->checkBindShop();

        $status = $this->request->get('status', '');
        $page = (int)$this->request->get('page', 1);
        $limit = (int)$this->request->get('limit', 10);

        // 基础条件（不带表前缀，用于单表查询）
        $where = [
            'shop_id' => $bindShopId,
            'deletetime' => null
        ];

        // 状态筛选
        if ($status && in_array($status, ['normal', 'hidden'])) {
            $where['status'] = $status;
        }

        // 统计总数（单表查询，不需要表前缀）
        $total = $this->goodsModel->where($where)->count();

        // 查询列表（关联查询，with() 自动将表别名设为 goods）
        $list = $this->goodsModel
            ->with(['category'])
            ->where('goods.shop_id', $bindShopId)
            ->where('goods.deletetime', null)
            ->where(function ($query) use ($status) {
                if ($status && in_array($status, ['normal', 'hidden'])) {
                    $query->where('goods.status', $status);
                }
            })
            ->order('goods.weigh desc, goods.id desc')
            ->page($page, $limit)
            ->select();

        // 格式化数据
        $rows = [];
        foreach ($list as $row) {
            // 计算商品总库存（所有有效 SKU 库存之和）
            $totalStock = model('app\\api\\model\\wanlshop\\GoodsSku')
                ->where('goods_id', $row['id'])
                ->where('state', 0)
                ->sum('stock');

            $rows[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'image' => $row['image'],
                'price' => $row['price'],
                'stock' => (int)$totalStock,
                'status' => $row['status'],
                'sales' => $row['sales'],
                'views' => $row['views'],
                'category_name' => $row->category ? $row->category['name'] : '',
                'createtime' => $row['createtime'],
                'updatetime' => $row['updatetime']
            ];
        }

        // 统计各状态数量
        $countAll = $this->goodsModel->where(['shop_id' => $bindShopId, 'deletetime' => null])->count();
        $countNormal = $this->goodsModel->where(['shop_id' => $bindShopId, 'deletetime' => null, 'status' => 'normal'])->count();
        $countHidden = $this->goodsModel->where(['shop_id' => $bindShopId, 'deletetime' => null, 'status' => 'hidden'])->count();

        $this->success('获取成功', [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'rows' => $rows,
            'count' => [
                'all' => $countAll,
                'normal' => $countNormal,
                'hidden' => $countHidden
            ]
        ]);
    }

    /**
     * 仓库商品列表（status=hidden）
     *
     * @ApiMethod (GET)
     */
    public function stockList()
    {
        $bindShopId = $this->checkBindShop();

        $page = (int)$this->request->get('page', 1);
        $limit = (int)$this->request->get('limit', 10);

        // 基础条件（不带表前缀，用于单表查询）
        $where = [
            'shop_id' => $bindShopId,
            'status' => 'hidden',
            'deletetime' => null
        ];

        // 统计总数（单表查询）
        $total = $this->goodsModel->where($where)->count();

        // 查询列表（关联查询，with() 自动将表别名设为 goods）
        $list = $this->goodsModel
            ->with(['category'])
            ->where('goods.shop_id', $bindShopId)
            ->where('goods.status', 'hidden')
            ->where('goods.deletetime', null)
            ->order('goods.updatetime desc, goods.id desc')
            ->page($page, $limit)
            ->select();

        $rows = [];
        foreach ($list as $row) {
            // 计算商品总库存（所有有效 SKU 库存之和）
            $totalStock = model('app\\api\\model\\wanlshop\\GoodsSku')
                ->where('goods_id', $row['id'])
                ->where('state', 0)
                ->sum('stock');

            $rows[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'image' => $row['image'],
                'price' => $row['price'],
                'stock' => (int)$totalStock,
                'status' => $row['status'],
                'category_name' => $row->category ? $row->category['name'] : '',
                'createtime' => $row['createtime'],
                'updatetime' => $row['updatetime']
            ];
        }

        $this->success('获取成功', [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'rows' => $rows
        ]);
    }

    /**
     * 同步规范商品
     *
     * 从平台店铺（shop_id=1）同步商品到当前绑定店铺的仓库
     *
     * @ApiMethod (POST)
     */
    public function syncStandard()
    {
        $bindShopId = $this->checkBindShop();
        $shop = $this->getBindShop();

        if (!$shop) {
            $this->error('店铺信息不存在');
        }

        // 获取店铺配送城市
        $deliveryCityName = $shop['delivery_city_name'];
        if (empty($deliveryCityName)) {
            $this->error('店铺未设置配送地级市，无法获取规范商品');
        }

        // 检查当前商家是否已有商品（使用 Db 类避免触发模型获取器）
        $existingCount = Db::name('wanlshop_goods')
            ->where('shop_id', $bindShopId)
            ->where('deletetime', null)
            ->count();

        $message = $existingCount > 0
            ? '仓库已有商品，开始同步最新的规范商品'
            : '开始同步规范商品';

        try {
            // 获取规范商品（shop_id=1，配送城市匹配，且在售状态）
            // 使用 Db 类直接查询，避免触发模型获取器（如 getCommentListAttr）
            $standardGoods = Db::name('wanlshop_goods')
                ->where('shop_id', 1)
                ->where('region_city_name', $deliveryCityName)
                ->where('status', 'normal')  // 只同步在售商品
                ->where('deletetime', null)
                ->select();

            if (empty($standardGoods)) {
                $this->error('当前城市暂无规范商品可同步');
            }

            $insertCount = 0;

            Db::startTrans();
            try {
                foreach ($standardGoods as $goods) {
                    $sourceGoodsId = $goods['id'];

                    // 复制商品数据（$goods 已经是数组）
                    $newGoods = $goods;
                    unset($newGoods['id']);

                    // 修改必要字段
                    $newGoods['shop_id'] = $bindShopId;
                    $newGoods['status'] = 'hidden'; // 同步到仓库，默认下架
                    // 保持原始标题，不追加时间戳（避免重复同步导致标题过长）
                    $newGoods['createtime'] = time();
                    $newGoods['updatetime'] = time();
                    $newGoods['deletetime'] = null;
                    // 强制价格为0.01，迫使商家必须修改价格
                    if (isset($newGoods['price'])) {
                        $newGoods['price'] = '0.01';
                    }
                    if (isset($newGoods['market_price'])) {
                        $newGoods['market_price'] = '0.01';
                    }

                    // 插入新商品
                    $newGoodsModel = new \app\api\model\wanlshop\Goods;
                    if ($newGoodsModel->allowField(true)->save($newGoods)) {
                        $insertCount++;

                        // 复制 SPU（使用 Db 类避免触发 getItemAttr 获取器）
                        $spuList = Db::name('wanlshop_goods_spu')
                            ->where('goods_id', $sourceGoodsId)
                            ->select();
                        if ($spuList) {
                            $spuRows = [];
                            $nowTs = time();
                            foreach ($spuList as $spu) {
                                $spuRows[] = [
                                    'goods_id' => $newGoodsModel->id,
                                    'name' => $spu['name'],
                                    'item' => $spu['item'],
                                    'createtime' => $nowTs,
                                    'updatetime' => $nowTs
                                ];
                            }
                            if ($spuRows) {
                                Db::name('wanlshop_goods_spu')->insertAll($spuRows);
                            }
                        }

                        // 复制 SKU（仅复制有效 state=0 的规格，使用 Db 类避免触发 getDifferenceAttr 获取器）
                        $skuList = Db::name('wanlshop_goods_sku')
                            ->where('goods_id', $sourceGoodsId)
                            ->where('state', 0)
                            ->select();
                        if ($skuList) {
                            $skuRows = [];
                            $nowTs = time();
                            foreach ($skuList as $sku) {
                                $skuRows[] = [
                                    'goods_id' => $newGoodsModel->id,
                                    'thumbnail' => isset($sku['thumbnail']) ? $sku['thumbnail'] : null,
                                    'difference' => $sku['difference'],
                                    // 强制价格为0.01，迫使商家必须修改价格
                                    'market_price' => '0.01',
                                    'price' => '0.01',
                                    // 同步商品库存默认为0，需商家自行设置库存
                                    'stock' => 0,
                                    'weigh' => isset($sku['weigh']) ? $sku['weigh'] : 0,
                                    'sn' => isset($sku['sn']) ? $sku['sn'] : ('wanl_' . $nowTs),
                                    'state' => '0', // enum 类型，需要字符串
                                    'createtime' => $nowTs,
                                    'updatetime' => $nowTs
                                ];
                            }
                            if ($skuRows) {
                                Db::name('wanlshop_goods_sku')->insertAll($skuRows);
                            }
                        }
                    }
                }

                Db::commit();
            } catch (\Exception $e) {
                Db::rollback();
                throw $e; // 向上抛出让外层处理
            }

            // 事务成功后返回响应（放在内层 try-catch 外面，避免 HttpResponseException 触发 rollback）
            $this->success($message . '，成功同步 ' . $insertCount . ' 个商品', [
                'count' => $insertCount
            ]);

        } catch (\think\exception\HttpResponseException $e) {
            // HttpResponseException 是正常的响应流程（error/success），直接抛出让框架处理
            throw $e;
        } catch (\Exception $e) {
            // 记录详细错误信息便于调试
            $errorDetail = get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
            \think\Log::error('syncStandard 异常: ' . $errorDetail);
            $this->error('同步失败：' . $errorDetail);
        } catch (\Error $e) {
            // 捕获 PHP 7+ 的 Error（如 TypeError、ArgumentCountError 等）
            $errorDetail = get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
            \think\Log::error('syncStandard Error: ' . $errorDetail);
            $this->error('同步失败：' . $errorDetail);
        }
    }

    /**
     * 批量操作（上架/下架）
     *
     * @ApiMethod (POST)
     * @ApiParams (name="ids", type="string", required=true, description="商品ID，多个用逗号分隔")
     * @ApiParams (name="params", type="string", required=true, description="操作参数，如 status=normal")
     */
    public function multi()
    {
        $bindShopId = $this->checkBindShop();

        $ids = $this->request->post('ids', '');
        $params = $this->request->post('params', '');

        if (empty($ids)) {
            $this->error('请选择要操作的商品');
        }

        // 解析参数
        parse_str($params, $values);
        if (empty($values)) {
            $this->error('操作参数不能为空');
        }

        // 只允许修改 status 字段
        $allowFields = ['status'];
        $values = array_intersect_key($values, array_flip($allowFields));
        if (empty($values)) {
            $this->error('无有效的操作参数');
        }

        // 验证 status 值
        if (isset($values['status']) && !in_array($values['status'], ['normal', 'hidden'])) {
            $this->error('状态值无效');
        }

        $idsArr = explode(',', $ids);
        $count = 0;

        Db::startTrans();
        try {
            // 只更新属于当前绑定店铺的商品
            $list = $this->goodsModel
                ->where('id', 'in', $idsArr)
                ->where('shop_id', $bindShopId)
                ->where('deletetime', null)
                ->select();

            foreach ($list as $item) {
                $count += $item->allowField(true)->isUpdate(true)->save($values);
            }

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            $this->error('操作失败：' . $e->getMessage());
        }

        if ($count) {
            $this->success('操作成功，已更新 ' . $count . ' 个商品', ['count' => $count]);
        } else {
            $this->error('没有商品被更新');
        }
    }

    /**
     * 商品详情（编辑用）
     *
     * @ApiMethod (GET)
     * @ApiParams (name="id", type="int", required=true, description="商品ID")
     */
    public function detail()
    {
        $bindShopId = $this->checkBindShop();

        $id = (int)$this->request->get('id', 0);
        if (!$id) {
            $this->error('商品ID不能为空');
        }

        // 查询商品（必须属于绑定店铺）
        $goods = $this->goodsModel
            ->where('id', $id)
            ->where('shop_id', $bindShopId)
            ->where('deletetime', null)
            ->find();

        if (!$goods) {
            $this->error('商品不存在或无权访问');
        }

        // 查询 SKU
        $skuList = model('app\api\model\wanlshop\GoodsSku')
            ->where('goods_id', $id)
            ->where('state', 0)
            ->field('id,thumbnail,difference,price,market_price,stock,weigh,sn')
            ->select();

        // 查询 SPU
        $spuList = model('app\api\model\wanlshop\GoodsSpu')
            ->where('goods_id', $id)
            ->field('id,name,item')
            ->select();

        // 查询类目
        $category = $goods->category;

        // 计算商品总库存（所有有效 SKU 库存之和）
        $totalStock = model('app\\api\\model\\wanlshop\\GoodsSku')
            ->where('goods_id', $id)
            ->where('state', 0)
            ->sum('stock');

        $this->success('获取成功', [
            'goods' => [
                'id' => $goods['id'],
                'title' => $goods['title'],
                'description' => $goods['description'],
                'image' => $goods['image'],
                'images' => $goods['images'],
                'content' => $goods['content'],
                'price' => $goods['price'],
                'stock' => (int)$totalStock,
                'status' => $goods['status'],
                'category_id' => $goods['category_id'],
                'category_name' => $category ? $category['name'] : '',
                'createtime' => $goods['createtime'],
                'updatetime' => $goods['updatetime']
            ],
            'sku_list' => $skuList,
            'spu_list' => $spuList
        ]);
    }

    /**
     * 编辑商品
     *
     * @ApiMethod (POST)
     * @ApiParams (name="id", type="int", required=true, description="商品ID")
     * @ApiParams (name="price", type="string", required=false, description="价格")
     * @ApiParams (name="stock", type="int", required=false, description="库存")
     * @ApiParams (name="status", type="string", required=false, description="状态：normal/hidden")
     * @ApiParams (name="sku", type="array", required=false, description="SKU列表")
     */
    public function edit()
    {
        $bindShopId = $this->checkBindShop();

        $id = (int)$this->request->post('id', 0);
        if (!$id) {
            $this->error('商品ID不能为空');
        }

        // 查询商品（必须属于绑定店铺）
        $goods = $this->goodsModel
            ->where('id', $id)
            ->where('shop_id', $bindShopId)
            ->where('deletetime', null)
            ->find();

        if (!$goods) {
            $this->error('商品不存在或无权访问');
        }

        // 允许修改的字段
        $allowFields = ['title', 'category_id', 'price', 'stock', 'status', 'image', 'images'];
        $data = [];

        foreach ($allowFields as $field) {
            $value = $this->request->post($field);
            if ($value !== null && $value !== '') {
                if ($field === 'status' && !in_array($value, ['normal', 'hidden'])) {
                    $this->error('状态值无效');
                }
                $data[$field] = $value;
            }
        }

        // 处理 SKU 更新
        $skuData = $this->request->post('sku/a');

        Db::startTrans();
        try {
            // 更新商品基本信息
            if (!empty($data)) {
                $data['updatetime'] = time();
                $goods->allowField(true)->save($data);
            }

            // 更新 SKU
            if (!empty($skuData) && is_array($skuData)) {
                foreach ($skuData as $sku) {
                    if (empty($sku['id'])) {
                        continue;
                    }

                    $skuModel = model('app\api\model\wanlshop\GoodsSku')
                        ->where('id', $sku['id'])
                        ->where('goods_id', $id)
                        ->find();

                    if ($skuModel) {
                        $skuUpdate = [];
                        if (isset($sku['price'])) {
                            $skuUpdate['price'] = $sku['price'];
                        }
                        if (isset($sku['market_price'])) {
                            $skuUpdate['market_price'] = $sku['market_price'];
                        }
                        if (isset($sku['stock'])) {
                            $skuUpdate['stock'] = (int)$sku['stock'];
                        }
                        if (!empty($skuUpdate)) {
                            $skuUpdate['updatetime'] = time();
                            $skuModel->allowField(true)->save($skuUpdate);
                        }
                    }
                }

                // 更新商品最低价
                $minPrice = model('app\api\model\wanlshop\GoodsSku')
                    ->where('goods_id', $id)
                    ->where('state', 0)
                    ->min('price');
                if ($minPrice !== null) {
                    $goods->save(['price' => $minPrice]);
                }
            }

            Db::commit();
            $this->success('保存成功');
        } catch (\think\exception\HttpResponseException $e) {
            // HttpResponseException 是正常的响应，重新抛出
            throw $e;
        } catch (\Exception $e) {
            Db::rollback();
            // 记录详细错误日志
            \think\Log::error('[MerchantGoods::edit] 保存失败: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());
            $this->error('保存失败：' . $e->getMessage());
        }
    }

    /**
     * 删除商品
     *
     * @ApiMethod (POST)
     * @ApiParams (name="ids", type="string", required=true, description="商品ID，多个用逗号分隔")
     */
    public function del()
    {
        $bindShopId = $this->checkBindShop();

        $ids = $this->request->post('ids', '');
        if (empty($ids)) {
            $this->error('请选择要删除的商品');
        }

        $idsArr = explode(',', $ids);
        $count = 0;

        Db::startTrans();
        try {
            // 软删除属于当前绑定店铺的商品
            $list = $this->goodsModel
                ->where('id', 'in', $idsArr)
                ->where('shop_id', $bindShopId)
                ->where('deletetime', null)
                ->select();

            foreach ($list as $item) {
                $count += $item->delete();
            }

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            $this->error('删除失败：' . $e->getMessage());
        }

        if ($count) {
            $this->success('删除成功，已删除 ' . $count . ' 个商品', ['count' => $count]);
        } else {
            $this->error('没有商品被删除');
        }
    }
}
