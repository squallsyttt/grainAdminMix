<?php
namespace app\api\controller\wanlshop\voucher;

use app\common\controller\Api;
use think\Db;
use think\exception\HttpResponseException;

/**
 * 核销券评价接口
 */
class Comment extends Api
{
    protected $noNeedLogin = ['goodsComments'];
    protected $noNeedRight = ['*'];

    /**
     * 提交评价
     *
     * @ApiSummary  (核销券核销后提交评价)
     * @ApiMethod   (POST)
     *
     * @param int    $voucher_id      核销券ID
     * @param string $content         评价内容
     * @param string $images          评价图片(逗号分隔,最多9张)
     * @param float  $score_describe  描述相符(1-5)
     * @param float  $score_service   服务态度(1-5)
     * @param float  $score_logistics 物流服务(1-5)
     */
    public function submit()
    {
        $this->request->filter(['strip_tags']);
        if (!$this->request->isPost()) {
            $this->error(__('非法请求'));
        }

        $voucherId = $this->request->post('voucher_id/d');
        $content = $this->request->post('content', '');
        $images = $this->request->post('images', '');
        $scoreDescribe = $this->request->post('score_describe/f', 5.0);
        $scoreService = $this->request->post('score_service/f', 5.0);
        $scoreLogistics = $this->request->post('score_logistics/f', 5.0);

        if (!$voucherId) {
            $this->error(__('参数错误'));
        }

        // 验证评分范围
        $scoreDescribe = max(1, min(5, $scoreDescribe));
        $scoreService = max(1, min(5, $scoreService));
        $scoreLogistics = max(1, min(5, $scoreLogistics));

        // 查询核销券
        $voucher = Db::name('wanlshop_voucher')
            ->where([
                'id' => $voucherId,
                'user_id' => $this->auth->id,
                'state' => '2',  // 已核销
                'status' => 'normal'
            ])
            ->find();

        if (!$voucher) {
            $this->error(__('核销券不存在或未核销'));
        }

        // 检查是否已评价
        $existComment = Db::name('wanlshop_goods_comment')
            ->where([
                'user_id' => $this->auth->id,
                'order_id' => $voucherId,
                'order_type' => 'voucher'
            ])
            ->find();

        if ($existComment) {
            $this->error(__('该核销券已评价'));
        }

        // 获取核销券关联商品的城市编码
        $voucherGoods = Db::name('wanlshop_goods')
            ->where('id', $voucher['goods_id'])
            ->field('id,region_city_code')
            ->find();

        if (!$voucherGoods) {
            $this->error(__('核销券关联商品不存在'));
        }

        // 通过 category_id + region_city_code 找到 shopid=1 的对应商品
        $shop1Goods = Db::name('wanlshop_goods')
            ->where([
                'shop_id' => 1,
                'category_id' => $voucher['category_id'],
                'region_city_code' => $voucherGoods['region_city_code'] ?: '',
                'status' => 'normal'
            ])
            ->field('id')
            ->find();

        if (!$shop1Goods) {
            $this->error(__('未找到对应的平台商品，无法评价'));
        }

        // 计算综合评分
        $score = round(($scoreDescribe + $scoreService + $scoreLogistics) / 3, 1);

        // 评价状态: 0=好评(>=4), 1=中评(>=2.5), 2=差评(<2.5)
        if ($score >= 4) {
            $state = '0';
        } elseif ($score >= 2.5) {
            $state = '1';
        } else {
            $state = '2';
        }

        // 获取核销记录中的店铺信息
        $verification = Db::name('wanlshop_voucher_verification')
            ->where('voucher_id', $voucherId)
            ->find();

        $shopId = $verification ? $verification['shop_id'] : $voucher['shop_id'];
        $shopGoodsId = $verification ? $verification['shop_goods_id'] : 0;

        // 准备评价数据
        // goods_id 绑定到 shopid=1 的商品，shop_id 保留实际核销的店铺
        $commentData = [
            'user_id' => $this->auth->id,
            'shop_id' => $shopId,  // 实际核销的店铺
            'order_id' => $voucherId,  // 存储核销券ID
            'goods_id' => $shop1Goods['id'],  // 绑定到 shopid=1 的商品
            'order_type' => 'voucher',
            'order_goods_id' => $shopGoodsId,  // 实际核销的店铺商品ID
            'state' => $state,
            'content' => $content,
            'tag' => '',
            'suk' => $voucher['sku_difference'] ?: '',
            'images' => $images,
            'score' => $score,
            'score_describe' => $scoreDescribe,
            'score_service' => $scoreService,
            'score_deliver' => 0,  // 核销券无配送
            'score_logistics' => $scoreLogistics,
            'switch' => 0,
            'createtime' => time(),
            'updatetime' => time(),
            'status' => 'normal'
        ];

        Db::startTrans();
        try {
            // 插入评价
            $commentId = Db::name('wanlshop_goods_comment')->insertGetId($commentData);

            // 更新 shopid=1 商品的评价统计
            $goodsUpdate = [
                'comment' => Db::raw('comment + 1')
            ];
            if ($state == '0') {
                $goodsUpdate['praise'] = Db::raw('praise + 1');
            } elseif ($state == '1') {
                $goodsUpdate['moderate'] = Db::raw('moderate + 1');
            } else {
                $goodsUpdate['negative'] = Db::raw('negative + 1');
            }
            Db::name('wanlshop_goods')->where('id', $shop1Goods['id'])->update($goodsUpdate);

            // 更新店铺评分（平均分）
            $this->updateShopScore($shopId, $scoreDescribe, $scoreService, $scoreLogistics);

            Db::commit();

            $this->success('评价成功', ['comment_id' => $commentId]);
        } catch (HttpResponseException $e) {
            // success/error 方法抛出的响应异常，直接重新抛出
            throw $e;
        } catch (\Exception $e) {
            Db::rollback();
            // 记录详细错误日志
            \think\Log::error('评价失败: voucher_id=' . $voucherId . ', error=' . $e->getMessage() . ', trace=' . $e->getTraceAsString());
            $this->error('评价失败：' . $e->getMessage());
        }
    }

    /**
     * 检查评价状态
     *
     * @ApiSummary  (检查核销券是否可评价/已评价)
     * @ApiMethod   (GET)
     *
     * @param int $voucher_id 核销券ID
     */
    public function check()
    {
        $this->request->filter(['strip_tags']);

        $voucherId = $this->request->get('voucher_id/d');
        if (!$voucherId) {
            $this->error(__('参数错误'));
        }

        // 查询核销券
        $voucher = Db::name('wanlshop_voucher')
            ->where([
                'id' => $voucherId,
                'user_id' => $this->auth->id,
                'status' => 'normal'
            ])
            ->field('id,state,goods_id,goods_title,shop_id')
            ->find();

        if (!$voucher) {
            $this->error(__('核销券不存在'));
        }

        // 检查是否已核销
        $canComment = $voucher['state'] == '2';

        // 检查是否已评价
        $comment = Db::name('wanlshop_goods_comment')
            ->where([
                'user_id' => $this->auth->id,
                'order_id' => $voucherId,
                'order_type' => 'voucher'
            ])
            ->field('id,content,score,images,createtime')
            ->find();

        $this->success('ok', [
            'voucher_id' => $voucherId,
            'can_comment' => $canComment && !$comment,  // 已核销且未评价
            'is_verified' => $voucher['state'] == '2',
            'has_comment' => !empty($comment),
            'comment' => $comment ?: null
        ]);
    }

    /**
     * 我的核销券评价列表
     *
     * @ApiSummary  (获取我的核销券评价列表)
     * @ApiMethod   (GET)
     */
    public function lists()
    {
        $this->request->filter(['strip_tags']);

        $list = Db::name('wanlshop_goods_comment')
            ->alias('c')
            ->join('wanlshop_voucher v', 'v.id = c.order_id')
            ->join('wanlshop_goods g', 'g.id = c.goods_id', 'LEFT')
            ->join('wanlshop_shop s', 's.id = c.shop_id', 'LEFT')
            ->where([
                'c.user_id' => $this->auth->id,
                'c.order_type' => 'voucher',
                'c.status' => 'normal'
            ])
            ->field('c.id,c.content,c.images,c.score,c.score_describe,c.score_service,c.score_logistics,c.state,c.createtime,
                     v.voucher_no,v.goods_title AS voucher_goods_title,v.goods_image AS voucher_goods_image,
                     g.id AS goods_id,g.title AS goods_title,g.image AS goods_image,
                     s.id AS shop_id,s.shopname')
            ->order('c.createtime desc')
            ->paginate(10);

        $this->success('ok', $list);
    }

    /**
     * 商品评价列表（公开接口）
     *
     * @ApiSummary  (获取商品的所有核销券评价，通过分类和城市定位shopid=1的商品)
     * @ApiMethod   (GET)
     *
     * @param int    $category_id       分类ID（必填）
     * @param string $region_city_code  城市编码（必填）
     * @param int    $page              页码
     * @param string $state             评价类型筛选: 0=好评,1=中评,2=差评,空=全部
     */
    public function goodsComments()
    {
        $this->request->filter(['strip_tags']);

        $categoryId = $this->request->get('category_id/d');
        $regionCityCode = $this->request->get('region_city_code', '');
        $state = $this->request->get('state', '');

        if (!$categoryId || !$regionCityCode) {
            $this->error(__('参数错误：category_id 和 region_city_code 必填'));
        }

        // 通过分类和城市找到 shopid=1 的商品
        $shop1Goods = Db::name('wanlshop_goods')
            ->where([
                'shop_id' => 1,
                'category_id' => $categoryId,
                'region_city_code' => $regionCityCode,
                'status' => 'normal'
            ])
            ->field('id,title,image,price,comment,praise,moderate,negative')
            ->find();

        if (!$shop1Goods) {
            // 没有找到对应商品，返回空列表
            $this->success('ok', [
                'goods' => null,
                'statistics' => [
                    'total' => 0,
                    'praise' => 0,
                    'moderate' => 0,
                    'negative' => 0,
                    'praise_rate' => '0'
                ],
                'list' => [
                    'total' => 0,
                    'per_page' => 10,
                    'current_page' => 1,
                    'last_page' => 1,
                    'data' => []
                ]
            ]);
            return;
        }

        // 构建查询条件
        $where = [
            'c.goods_id' => $shop1Goods['id'],
            'c.order_type' => 'voucher',
            'c.status' => 'normal'
        ];

        // 评价类型筛选
        if ($state !== '' && in_array($state, ['0', '1', '2'])) {
            $where['c.state'] = $state;
        }

        // 查询评价列表，包含核销店铺信息
        $list = Db::name('wanlshop_goods_comment')
            ->alias('c')
            ->join('wanlshop_shop s', 's.id = c.shop_id', 'LEFT')
            ->join('user u', 'u.id = c.user_id', 'LEFT')
            ->where($where)
            ->field('c.id,c.content,c.images,c.score,c.score_describe,c.score_service,c.score_logistics,
                     c.state,c.suk,c.createtime,
                     s.id AS shop_id,s.shopname,s.avatar AS shop_avatar,s.city AS shop_city,
                     u.id AS user_id,u.nickname,u.avatar AS user_avatar')
            ->order('c.createtime desc')
            ->paginate(10);

        // 统计信息
        $total = (int)$shop1Goods['comment'];
        $praise = (int)$shop1Goods['praise'];
        $praiseRate = $total > 0 ? bcmul(bcdiv($praise, $total, 2), 100, 0) : '0';

        $this->success('ok', [
            'goods' => [
                'id' => $shop1Goods['id'],
                'title' => $shop1Goods['title'],
                'image' => $shop1Goods['image'],
                'price' => (float)$shop1Goods['price']
            ],
            'statistics' => [
                'total' => $total,
                'praise' => $praise,
                'moderate' => (int)$shop1Goods['moderate'],
                'negative' => (int)$shop1Goods['negative'],
                'praise_rate' => $praiseRate
            ],
            'list' => $list
        ]);
    }

    /**
     * 更新店铺评分
     *
     * @param int   $shopId
     * @param float $scoreDescribe
     * @param float $scoreService
     * @param float $scoreLogistics
     */
    private function updateShopScore($shopId, $scoreDescribe, $scoreService, $scoreLogistics)
    {
        // 获取店铺当前评分和评价数
        $shop = Db::name('wanlshop_shop')
            ->where('id', $shopId)
            ->field('id,score_describe,score_service,score_logistics')
            ->find();

        if (!$shop) {
            return;
        }

        // 统计该店铺的所有评价数
        $commentCount = Db::name('wanlshop_goods_comment')
            ->where('shop_id', $shopId)
            ->where('status', 'normal')
            ->count();

        if ($commentCount <= 1) {
            // 首条评价，直接使用当前评分
            $newScoreDescribe = $scoreDescribe;
            $newScoreService = $scoreService;
            $newScoreLogistics = $scoreLogistics;
        } else {
            // 计算新的平均分
            $oldCount = $commentCount - 1;
            $newScoreDescribe = round((($shop['score_describe'] * $oldCount) + $scoreDescribe) / $commentCount, 1);
            $newScoreService = round((($shop['score_service'] * $oldCount) + $scoreService) / $commentCount, 1);
            $newScoreLogistics = round((($shop['score_logistics'] * $oldCount) + $scoreLogistics) / $commentCount, 1);
        }

        Db::name('wanlshop_shop')->where('id', $shopId)->update([
            'score_describe' => $newScoreDescribe,
            'score_service' => $newScoreService,
            'score_logistics' => $newScoreLogistics
        ]);
    }
}
