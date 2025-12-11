<?php
namespace app\api\controller\wanlshop\voucher;

use app\common\controller\Api;
use app\admin\model\wanlshop\VoucherOrder;
use app\admin\model\wanlshop\Voucher;
use app\admin\model\wanlshop\VoucherOrderItem;
use app\admin\model\wanlshop\VoucherRefund;
use app\admin\model\wanlshop\VoucherRule;
use app\api\model\wanlshop\Third;
use app\api\model\wanlshop\GoodsSku;
use app\common\library\WechatPayment;
use app\admin\model\wanlshop\Goods;
use app\common\model\PaymentCallbackLog;
use app\common\service\PriceCalculator;
use app\admin\service\CustodyRefundService;
use think\Db;
use think\Exception;

/**
 * 核销券订单接口
 */
class Order extends Api
{
    protected $noNeedLogin = ['notify', 'refundNotify'];
    protected $noNeedRight = ['*'];

    /**
     * 创建订单
     *
     * @ApiSummary  (创建核销券订单)
     * @ApiMethod   (POST)
     *
     * @param int $goods_id 商品ID
     * @param int $quantity 购买数量
     */
    public function create()
    {
        // 设置过滤方法
        $this->request->filter(['strip_tags']);

        if (!$this->request->isPost()) {
            $this->error(__('非法请求'));
        }

        $goodsId = $this->request->post('goods_id/d');
        $goodsSkuId = $this->request->post('goods_sku_id/d', 0);
        $quantity = $this->request->post('quantity/d', 1);

        if (!$goodsId || $quantity < 1) {
            $this->error(__('参数错误'));
        }

        // 查询商品信息
        $goods = Goods::where(['id' => $goodsId, 'status' => 'normal'])->find();
        if (!$goods) {
            $this->error(__('商品不存在'));
        }

        // 查询商品SKU信息（可选）
        $sku = null;
        $skuDifference = '';
        $skuWeight = 0.0;
        $unitPrice = $goods->price;
        if ($goodsSkuId) {
            $sku = GoodsSku::where(['id' => $goodsSkuId, 'goods_id' => $goodsId])->find();
            if (!$sku) {
                $this->error(__('商品规格不存在'));
            }
            // difference 可能是数组（通过获取器），统一存字符串快照
            $skuDifference = is_array($sku->difference) ? implode(',', $sku->difference) : (string)$sku->difference;
            $skuWeight = isset($sku->weigh) ? (float)$sku->weigh : 0.0;
            $unitPrice = isset($sku->price) ? $sku->price : $unitPrice;
        }

        // 生成订单号（到秒 + 随机6位，降低重复概率）
        $orderNo = 'ORD' . date('YmdHis') . mt_rand(100000, 999999);

        // 使用价格计算服务计算订单金额
        $priceResult = PriceCalculator::calculateOrderItemsPrices([[
            'goods_id' => $goodsId,
            'sku_id' => $goodsSkuId,
            'quantity' => $quantity,
            'shop_id' => $goods->shop_id,
        ]]);
        $priceItem = $priceResult[0] ?? [];
        $retailPrice = $priceItem['retail_price'] ?? ($unitPrice * $quantity);
        $actualPayment = $priceItem['actual_payment'] ?? $retailPrice;
        $now = time();

        Db::startTrans();
        try {
            // 创建订单（供货价在核销时才确定，订单不存储）
            $order = new VoucherOrder();
            $order->user_id = $this->auth->id;
            $order->order_no = $orderNo;
            $order->goods_id = $goods->id;
            $order->goods_sku_id = $goodsSkuId;
            $order->category_id = $goods->category_id;
            $order->sku_difference = $skuDifference;
            $order->sku_weight = $skuWeight;
            $order->quantity = $quantity;
            $order->retail_price = $retailPrice;
            $order->actual_payment = $actualPayment;
            $order->state = 1;  // 待支付
            $order->createtime = $now;
            $order->save();

            // 创建订单明细（单商品场景也记录，兼容多商品）
            VoucherOrderItem::create([
                'order_id' => $order->id,
                'goods_id' => $goods->id,
                'goods_sku_id' => $goodsSkuId,
                'category_id' => $goods->category_id,
                'goods_title' => $goods->title,
                'goods_image' => $goods->image,
                'sku_difference' => $skuDifference,
                'sku_weight' => $skuWeight,
                'quantity' => $quantity,
                'retail_price' => $retailPrice,
                'actual_payment' => $actualPayment,
                'createtime' => $now,
            ]);

            Db::commit();

            $this->success('订单创建成功', [
                'order_id' => $order->id,
                'order_no' => $orderNo,
                'amount' => $actualPayment,
            ]);
        } catch (Exception $e) {
            Db::rollback();
            $this->error(__('订单创建失败：') . $e->getMessage());
        }
    }

    /**
     * 批量创建订单（多商品）
     *
     * @ApiSummary  (创建多商品核销券订单)
     * @ApiMethod   (POST)
     *
     * @param array $items 商品列表 [{goods_id, quantity}]
     */
    public function createBatch()
    {
        $this->request->filter(['strip_tags']);

        if (!$this->request->isPost()) {
            $this->error(__('非法请求'));
        }

        $itemsInput = $this->request->post('items/a', []);
        if (!$itemsInput || !is_array($itemsInput)) {
            $this->error(__('参数错误'));
        }

        // 合并同一商品+SKU的数量
        $normalized = [];
        foreach ($itemsInput as $item) {
            $goodsId = isset($item['goods_id']) ? (int)$item['goods_id'] : 0;
            $goodsSkuId = isset($item['goods_sku_id']) ? (int)$item['goods_sku_id'] : 0;
            $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 0;
            if ($goodsId <= 0 || $quantity < 1) {
                $this->error(__('参数错误'));
            }
            $key = $goodsId . '-' . $goodsSkuId;
            if (!isset($normalized[$key])) {
                $normalized[$key] = [
                    'goods_id' => $goodsId,
                    'goods_sku_id' => $goodsSkuId,
                    'quantity' => 0
                ];
            }
            $normalized[$key]['quantity'] += $quantity;
        }

        $normalizedItems = array_values($normalized);
        $goodsIds = array_values(array_unique(array_column($normalizedItems, 'goods_id')));
        $skuIds = array_values(array_unique(array_filter(array_column($normalizedItems, 'goods_sku_id'))));
        $goodsList = Goods::where('status', 'normal')
            ->where('id', 'in', $goodsIds)
            ->select();

        if (!$goodsList || count($goodsList) != count($goodsIds)) {
            $this->error(__('部分商品不存在或已下架'));
        }

        // 关联商品映射
        $goodsMap = [];
        foreach ($goodsList as $g) {
            $goodsMap[$g->id] = $g;
        }

        // 查询相关SKU信息（仅对传入的 goods_sku_id）
        $skuMap = [];
        if (!empty($skuIds)) {
            $skuList = GoodsSku::where('id', 'in', $skuIds)->select();
            foreach ($skuList as $sku) {
                $skuMap[$sku->id] = $sku;
            }
        }

        // 生成订单号
        $orderNo = 'ORD' . date('YmdHis') . mt_rand(100000, 999999);

        $totalQuantity = 0;
        $totalRetailPrice = 0;
        $orderItemsData = [];
        $now = time();

        foreach ($normalizedItems as $itemRow) {
            $goodsId = $itemRow['goods_id'];
            $skuId = $itemRow['goods_sku_id'];
            $qty = $itemRow['quantity'];

            $goods = $goodsMap[$goodsId];
            $sku = $skuId ? ($skuMap[$skuId] ?? null) : null;
            if ($skuId && (!$sku || $sku->goods_id != $goodsId)) {
                $this->error(__('商品规格不存在'));
            }

            $skuDifference = $sku ? (is_array($sku->difference) ? implode(',', $sku->difference) : (string)$sku->difference) : '';
            $skuWeight = $sku ? (float)$sku->weigh : 0.0;
            $unitPrice = $sku && isset($sku->price) ? $sku->price : $goods->price;

            // 使用价格计算服务
            $priceResult = PriceCalculator::calculateOrderItemsPrices([[
                'goods_id' => $goodsId,
                'sku_id' => $skuId,
                'quantity' => $qty,
                'shop_id' => $goods->shop_id,
            ]]);
            $priceItem = $priceResult[0] ?? [];
            $retailPrice = $priceItem['retail_price'] ?? ($unitPrice * $qty);
            $itemActualPayment = $priceItem['actual_payment'] ?? $retailPrice;

            $orderItemsData[] = [
                'order_id' => 0, // 保存后填充
                'goods_id' => $goods->id,
                'goods_sku_id' => $skuId,
                'category_id' => $goods->category_id,
                'goods_title' => $goods->title,
                'goods_image' => $goods->image,
                'sku_difference' => $skuDifference,
                'sku_weight' => $skuWeight,
                'quantity' => $qty,
                'retail_price' => $retailPrice,
                'actual_payment' => $itemActualPayment,
                'createtime' => $now,
            ];

            $totalQuantity += $qty;
            $totalRetailPrice += $retailPrice;
        }

        // 手续费按总价统一计算，避免逐行进位误差
        $actualPayment = PriceCalculator::calculatePaymentPrice($totalRetailPrice);
        if ($actualPayment <= 0 || $totalQuantity <= 0) {
            $this->error(__('订单金额异常'));
        }

        Db::startTrans();
        try {
            // 创建订单（供货价在核销时才确定，订单不存储）
            $order = new VoucherOrder();
            $order->user_id = $this->auth->id;
            $order->order_no = $orderNo;
            $order->goods_id = $orderItemsData[0]['goods_id'];      // 兼容旧字段
            $order->category_id = $orderItemsData[0]['category_id']; // 兼容旧字段
            $order->goods_sku_id = $orderItemsData[0]['goods_sku_id']; // 兼容旧字段
            $order->sku_difference = $orderItemsData[0]['sku_difference'] ?? '';
            $order->sku_weight = $orderItemsData[0]['sku_weight'] ?? 0;
            $order->quantity = $totalQuantity;
            $order->retail_price = $totalRetailPrice;
            $order->actual_payment = $actualPayment;
            $order->state = 1;  // 待支付
            $order->createtime = $now;
            $order->save();

            foreach ($orderItemsData as &$item) {
                $item['order_id'] = $order->id;
            }

            (new VoucherOrderItem())->saveAll($orderItemsData);

            Db::commit();

            $this->success('订单创建成功', [
                'order_id' => $order->id,
                'order_no' => $orderNo,
                'amount' => $actualPayment,
                'total_quantity' => $totalQuantity,
                'items' => $orderItemsData
            ]);
        } catch (Exception $e) {
            Db::rollback();
            $this->error(__('订单创建失败：') . $e->getMessage());
        }
    }

    /**
     * 微信小程序 JSAPI 预下单
     *
     * @ApiSummary  (核销券订单微信预下单)
     * @ApiMethod   (POST)
     *
     * @param int $order_id 订单ID
     * @return void
     */
    public function prepay()
    {
        $this->request->filter(['strip_tags']);

        if (!$this->request->isPost()) {
            $this->error(__('非法请求'));
        }

        $orderId = $this->request->post('order_id/d');
        if (!$orderId) {
            $this->error(__('参数错误'));
        }

        // 查询订单并验证归属与状态
        $order = VoucherOrder::where([
            'id'      => $orderId,
            'user_id' => $this->auth->id,
            'status'  => 'normal',
        ])->find();

        if (!$order) {
            $this->error(__('订单不存在'));
        }

        if ($order->state != 1) {
            $this->error(__('订单状态异常,无法支付'));
        }

        if ($order->actual_payment <= 0) {
            $this->error(__('订单金额异常'));
        }

        // 订单明细（兼容多商品）
        // 注意：确保 items 关联被正确加载为集合
        $orderItems = $order->items()->select();

        // 获取当前用户的小程序 openid（通过第三方绑定表）
        $third = Third::where([
            'platform' => 'miniprogram',
            'user_id'  => $this->auth->id,
        ])->order('id', 'desc')->find();

        if (!$third || !$third->openid) {
            $this->error(__('未获取到小程序 openid，请先完成小程序登录绑定'));
        }

        // 计算支付金额（单位：分）
        $totalAmount = (int)bcmul($order->actual_payment, 100, 0);
        if ($totalAmount <= 0) {
            $this->error(__('订单金额异常'));
        }

        // 订单描述
        $description = '核销券订单-' . $order->order_no;
        if ($orderItems && count($orderItems) > 0) {
            $firstTitle = $orderItems[0]->goods_title ?: '';
            $description = count($orderItems) > 1
                ? ($firstTitle ?: '多商品订单') . '等' . count($orderItems) . '件商品'
                : ($firstTitle ?: $description);
        } else {
            $goods = Goods::find($order->goods_id);
            $description = $goods && $goods->title ? $goods->title : $description;
        }

        // 支付请求可能遇到探测流量，增加重试机制
        $maxRetries = 0;
        $lastError = null;
        $attemptErrors = [];
        
        for ($i = 0; $i <= $maxRetries; $i++) {
            try {
                // 调用微信 JSAPI 下单
                $resp = WechatPayment::jsapiPrepay([
                    'description'     => $description,
                    'out_trade_no'    => $order->order_no,
                    'amount_total'    => $totalAmount,
                    'payer_openid'    => $third->openid,
                    'payer_client_ip' => $this->request->ip(),
                ]);

                // 生成前端 wx.requestPayment 所需参数
                $payParams = WechatPayment::buildJsapiPayParams($resp['prepay_id']);


                $this->success('ok', [
                    'order_no'  => $order->order_no,
                    'prepay_id' => $resp['prepay_id'],
                    'payparams' => $payParams,
                ]);
                return; // 成功则直接返回
                
            } catch (Exception $e) {
                $lastError = trim($e->getMessage());
                $attemptErrors[] = '尝试' . ($i + 1) . '失败：' . $lastError;
                
                $isSignatureProbe = strpos($lastError, '签名验证失败') !== false ||
                    strpos($lastError, 'Verify the response') !== false;
                if ($i < $maxRetries && $isSignatureProbe) {
                    sleep(1); // 等待1秒后重试
                    continue;
                }
                break; // 其他错误直接退出
            }
        }
        
        // 所有重试都失败了
        $errorMessage = $lastError ?: __('微信预下单失败');
        $this->error($errorMessage);
    }

    /**
     * 订单详情
     *
     * @ApiSummary  (获取订单详情)
     * @ApiMethod   (GET)
     *
     * @param int $id 订单ID
     */
    public function detail()
    {
        $this->request->filter(['strip_tags']);

        $id = $this->request->get('id/d');
        if (!$id) {
            $this->error(__('参数错误'));
        }

        // 查询订单并验证权限
        $order = VoucherOrder::with(['goods', 'category', 'vouchers.voucherRefund', 'items'])->where([
            'id' => $id,
            'user_id' => $this->auth->id,
            'status' => 'normal'
        ])->find();

        if (!$order) {
            $this->error(__('订单不存在'));
        }

        // 关联查询商品信息
        $order->goods;
        $order->category;

        // 关联查询生成的核销券及退款信息
        $order->vouchers;
        // 关联查询订单明细（多商品）
        $order->items;

        $itemCount = $order->items ? count($order->items) : 0;
        $order->is_multi_item = $itemCount > 1;
        $order->item_count = $itemCount ?: 1;

        // 统一价格字段为数字类型，避免前端出现字符串/数字混用
        $orderData = $order->toArray();
        $this->castPriceFields($orderData, ['retail_price', 'coupon_price', 'discount_price', 'actual_payment']);

        if (!empty($orderData['goods']) && is_array($orderData['goods'])) {
            $this->castPriceFields($orderData['goods'], ['price', 'retail_price', 'coupon_price', 'discount_price', 'actual_payment']);
            $orderData['region_city_name'] = isset($orderData['goods']['region_city_name']) ? $orderData['goods']['region_city_name'] : '';
        }

        if (!empty($orderData['vouchers']) && is_array($orderData['vouchers'])) {
            foreach ($orderData['vouchers'] as &$voucher) {
                $this->castPriceFields($voucher, ['face_value', 'retail_price', 'actual_payment']);
            }
            unset($voucher);
        }

        if (!empty($orderData['items']) && is_array($orderData['items'])) {
            foreach ($orderData['items'] as &$item) {
                $this->castPriceFields($item, ['retail_price', 'coupon_price', 'discount_price', 'actual_payment']);
                if (!isset($orderData['region_city_name']) || $orderData['region_city_name'] === '') {
                    $orderData['region_city_name'] = isset($item['region_city_name']) ? $item['region_city_name'] : '';
                }
            }
            unset($item);
        }
        if (!isset($orderData['region_city_name'])) {
            $orderData['region_city_name'] = '';
        }

        $this->success('ok', $orderData);
    }

    /**
     * 订单列表
     *
     * @ApiSummary  (获取订单列表)
     * @ApiMethod   (GET)
     *
     * @param string $state 订单状态(可选)
     */
    public function lists()
    {
        $this->request->filter(['strip_tags']);

        $state = $this->request->get('state');

        $where = [
            'user_id' => $this->auth->id,
            'status' => 'normal'
        ];

        if ($state && in_array($state, ['1', '2', '3', '4'])) {
            $where['state'] = $state;
        }

        // 分页查询
        $list = VoucherOrder::where($where)
            ->order('createtime desc')
            ->paginate(10)
            ->each(function($order) {
                // 关联商品信息
                $order->goods;
                $order->goods && $order->goods->region_city_name;
                // 关联券信息（含门店信息）
                $order->vouchers()->with('shop')->select();
                // 预加载明细（多商品）
                $order->items;

                $itemCount = $order->items ? count($order->items) : 0;
                $order->is_multi_item = $itemCount > 1;
                $order->item_count = $itemCount ?: 1;

                // 构建前端需要的 items 结构
                $order->items = $this->buildOrderItems($order);

                // 补充金额明细
                $order->total_quantity = $order->quantity;
                $order->original_amount = $order->retail_price;
                $order->discount_amount = $order->retail_price - $order->actual_payment;
                $order->final_amount = $order->actual_payment;

                // 地区汇总（优先主商品，否则明细）
                $cityNames = [];
                if ($order->goods && $order->goods->region_city_name) {
                    $cityNames[] = $order->goods->region_city_name;
                }
                foreach ($order->items as $it) {
                    if (isset($it->region_city_name) && $it->region_city_name) {
                        $cityNames[] = $it->region_city_name;
                    }
                }
                $order->region_city_name = $cityNames ? implode('/', array_unique($cityNames)) : '';

                return $order;
            });

        $this->success('ok', $list);
    }

    /**
     * 订单统计
     *
     * @ApiSummary  (获取订单统计信息)
     * @ApiMethod   (GET)
     *
     * @return void
     */
    public function statistics()
    {
        $this->request->filter(['strip_tags']);

        // 基础查询条件：当前用户且未软删除
        $baseWhere = [
            'user_id' => $this->auth->id,
            'status' => 'normal'
        ];

        // 统计总订单数量
        $totalCount = VoucherOrder::where($baseWhere)->count();

        // 统计待支付订单数量（state = 1）
        $pendingCount = VoucherOrder::where($baseWhere)
            ->where('state', 1)
            ->count();

        // 统计已支付订单数量（state = 2）
        $paidCount = VoucherOrder::where($baseWhere)
            ->where('state', 2)
            ->count();

        // 统计已取消订单数量（state = 3）
        $cancelledCount = VoucherOrder::where($baseWhere)
            ->where('state', 3)
            ->count();

        // 统计总金额（已支付订单的实际支付金额总和）
        $totalAmount = VoucherOrder::where($baseWhere)
            ->where('state', 2)
            ->sum('actual_payment');

        // 统计待支付订单的总金额
        $pendingAmount = VoucherOrder::where($baseWhere)
            ->where('state', 1)
            ->sum('actual_payment');

        // 返回统计结果
        $this->success('ok', [
            'total_count' => (int)$totalCount,
            'pending_count' => (int)$pendingCount,
            'paid_count' => (int)$paidCount,
            'cancelled_count' => (int)$cancelledCount,
            'total_amount' => (float)$totalAmount,
            'pending_amount' => (float)$pendingAmount,
        ]);
    }

    /**
     * 构建订单明细项（用于前端展示）
     *
     * @param VoucherOrder $order 订单对象
     * @return array
     */
    private function buildOrderItems($order)
    {
        $items = [];
        $vouchers = $order->vouchers;

        // 如果有核销券，每张券对应一个 item
        if ($vouchers && count($vouchers) > 0) {
            foreach ($vouchers as $voucher) {
                $items[] = [
                    'id' => $voucher->id,
                    'product_name' => $voucher->goods_title,
                    'weight' => isset($voucher->sku_weight) ? (float)$voucher->sku_weight : $this->extractWeight($voucher->goods_title),
                    'quantity' => 1,
                    'unit_price' => (float)$voucher->supply_price,
                    'subtotal' => (float)$voucher->face_value,
                    'goods_sku_id' => isset($voucher->goods_sku_id) ? (int)$voucher->goods_sku_id : 0,
                    'sku_difference' => isset($voucher->sku_difference) ? (string)$voucher->sku_difference : '',
                    'sku_weight' => isset($voucher->sku_weight) ? (float)$voucher->sku_weight : null,
                    'region_city_name' => isset($voucher->goods) && isset($voucher->goods['region_city_name']) ? $voucher->goods['region_city_name'] : '',

                    // 核销券信息
                    'voucher_id' => $voucher->id,
                    'voucher_code' => $voucher->voucher_no,
                    'voucher_status' => $this->mapVoucherStatus($voucher->state),
                    'voucher_expire_at' => (int)$voucher->valid_end,

                    // 配送信息（暂时为空，因为未开发配送功能）
                    'delivery_method' => null,
                    'store_id' => $voucher->shop_id > 0 ? $voucher->shop_id : null,
                    'store_name' => $voucher->shop_name ?: null,
                    'store_address' => null,
                ];
            }
        } elseif ($order->items && count($order->items) > 0) {
            foreach ($order->items as $item) {
                $items[] = [
                    'id' => $item->id,
                    'product_name' => $item->goods_title ?: '未知商品',
                    'weight' => isset($item->sku_weight) ? (float)$item->sku_weight : $this->extractWeight($item->goods_title),
                    'quantity' => $item->quantity,
                    'unit_price' => $item->quantity > 0 ? (float)($item->actual_payment / $item->quantity) : 0.0,
                    'subtotal' => (float)$item->actual_payment,
                    'goods_sku_id' => isset($item->goods_sku_id) ? (int)$item->goods_sku_id : 0,
                    'sku_difference' => isset($item->sku_difference) ? (string)$item->sku_difference : '',
                    'sku_weight' => isset($item->sku_weight) ? (float)$item->sku_weight : null,
                    'region_city_name' => isset($item->region_city_name) ? $item->region_city_name : '',

                    'voucher_id' => null,
                    'voucher_code' => null,
                    'voucher_status' => null,
                    'voucher_expire_at' => null,

                    'delivery_method' => null,
                    'store_id' => null,
                    'store_name' => null,
                    'store_address' => null,
                ];
            }
        } else {
            // 如果没有核销券（待支付状态），返回基础订单信息
            $items[] = [
                'id' => $order->id,
                'product_name' => $order->goods ? $order->goods->title : '未知商品',
                'weight' => isset($order->sku_weight) ? (float)$order->sku_weight : null,
                'quantity' => $order->quantity,
                'unit_price' => $order->quantity > 0 ? (float)($order->actual_payment / $order->quantity) : 0.0,
                'subtotal' => (float)$order->actual_payment,
                'goods_sku_id' => isset($order->goods_sku_id) ? (int)$order->goods_sku_id : 0,
                'sku_difference' => isset($order->sku_difference) ? (string)$order->sku_difference : '',
                'sku_weight' => isset($order->sku_weight) ? (float)$order->sku_weight : null,

                'voucher_id' => null,
                'voucher_code' => null,
                'voucher_status' => null,
                'voucher_expire_at' => null,

                'delivery_method' => null,
                'store_id' => null,
                'store_name' => null,
                'store_address' => null,
            ];
        }

        return $items;
    }

    /**
     * 从商品名称提取重量（kg）
     *
     * @param string $title 商品标题
     * @return float|null
     */
    private function extractWeight($title)
    {
        // 尝试匹配 "XXkg" 或 "XX公斤"
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:kg|公斤)/i', $title, $matches)) {
            return (float)$matches[1];
        }

        return null;
    }

    /**
     * 映射核销券状态到前端格式
     *
     * @param string $dbState 数据库状态 (1,2,3,4)
     * @return string|null
     */
    private function mapVoucherStatus($dbState)
    {
        $map = [
            '1' => 'unused',    // 未使用
            '2' => 'used',      // 已核销
            '3' => 'expired',   // 已过期
            '4' => 'refunded',  // 已退款
        ];

        return isset($map[$dbState]) ? $map[$dbState] : null;
    }

    /**
     * 将金额字段统一转换为数字类型，避免出现字符串/数字混用
     *
     * @param array $data   引用的数组数据
     * @param array $fields 需要转换的字段列表
     * @return void
     */
    private function castPriceFields(array &$data, array $fields)
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $data[$field] = (float)$data[$field];
            }
        }
    }

    /**
     * 取消订单
     *
     * @ApiSummary  (取消订单)
     * @ApiMethod   (POST)
     *
     * @param int $id 订单ID
     */
    public function cancel()
    {
        $this->request->filter(['strip_tags']);

        if (!$this->request->isPost()) {
            $this->error(__('非法请求'));
        }

        $id = $this->request->post('id/d');
        if (!$id) {
            $this->error(__('参数错误'));
        }

        // 查询订单并验证权限
        $order = VoucherOrder::where([
            'id' => $id,
            'user_id' => $this->auth->id,
            'status' => 'normal'
        ])->find();

        if (!$order) {
            $this->error(__('订单不存在'));
        }

        // 只能取消待支付订单
        if ($order->state != 1) {
            $this->error(__('订单状态异常,无法取消'));
        }

        // 更新订单状态
        $order->state = 3;  // 已取消
        $order->canceltime = time();
        $order->save();

        $this->success('订单已取消');
    }

    /**
     * 微信支付回调
     *
     * @ApiSummary  (微信支付回调通知)
     * @ApiMethod   (POST)
     */
    public function notify()
    {
        $body = '';
        $headers = [];
        $resource = [];
        $outTradeNo = '';
        $transactionId = '';

        try {
            // 原始报文
            $body = file_get_contents('php://input');

            // 记录回调日志
            \think\Log::info('微信支付回调原始报文: ' . $body);

            // 读取回调头部
            $timestamp = $this->request->header('Wechatpay-Timestamp');
            $nonce     = $this->request->header('Wechatpay-Nonce');
            $signature = $this->request->header('Wechatpay-Signature');
            $serial    = $this->request->header('Wechatpay-Serial');

            $headers = [
                'timestamp' => $timestamp,
                'nonce'     => $nonce,
                'signature' => $signature,
                'serial'    => $serial,
            ];

            // 验证签名
            if (!WechatPayment::verifyCallbackSignature($headers, $body)) {
                \think\Log::error('微信支付回调：签名验证失败');
                // 签名验证失败时不记录到数据库，避免transaction_id为空导致的唯一键冲突
                return json(['code' => 'FAIL', 'message' => '签名验证失败']);
            }

            $data = json_decode($body, true);
            if (!is_array($data) || empty($data['resource'])) {
                \think\Log::error('微信支付回调：报文格式错误');
                return json(['code' => 'FAIL', 'message' => '报文格式错误']);
            }

            // 解密资源数据
            $resource = WechatPayment::decryptCallbackResource($data['resource']);
            $transactionId = $resource['transaction_id'] ?? '';

            // 仅处理支付成功通知
            if (!isset($resource['trade_state']) || $resource['trade_state'] !== 'SUCCESS') {
                \think\Log::info('微信支付回调：非成功交易状态，忽略');
                return json(['code' => 'SUCCESS', 'message' => '']);
            }

            $outTradeNo = $resource['out_trade_no'] ?? '';
            if (!$outTradeNo) {
                \think\Log::error('微信支付回调：out_trade_no 缺失');
                return json(['code' => 'FAIL', 'message' => '订单号缺失']);
            }

            if ($transactionId && PaymentCallbackLog::isProcessed($transactionId)) {
                \think\Log::info('微信支付回调:重复通知,已处理 - ' . $transactionId);
                return json(['code' => 'SUCCESS', 'message' => '']);
            }

            // 金额校验
            $order = VoucherOrder::where('order_no', $outTradeNo)->find();
            if (!$order) {
                \think\Log::error('微信支付回调：订单不存在 - ' . $outTradeNo);
                return json(['code' => 'FAIL', 'message' => '订单不存在']);
            }

            if (!empty($resource['amount']['total'])) {
                $total = (int)$resource['amount']['total'];
                $orderAmount = (int)bcmul($order->actual_payment, 100, 0);
                if ($total !== $orderAmount) {
                    \think\Log::error(sprintf(
                        '微信支付回调：金额不匹配，订单号=%s, 回调金额=%s, 订单金额=%s',
                        $outTradeNo,
                        $total,
                        $orderAmount
                    ));
                    return json(['code' => 'FAIL', 'message' => '金额不匹配']);
                }
            }

            // 处理支付成功逻辑
            $this->handlePaymentSuccess($outTradeNo, $resource);

            // 必须在5秒内返回成功响应
            return json(['code' => 'SUCCESS', 'message' => '']);

        } catch (Exception $e) {
            \think\Log::error('微信支付回调处理失败: ' . $e->getMessage());
            try {
                PaymentCallbackLog::recordCallback([
                    'order_type'       => 'voucher',
                    'order_no'         => $outTradeNo ?? '',
                    'transaction_id'   => $transactionId ?? '',
                    'trade_state'      => $resource['trade_state'] ?? '',
                    'callback_body'    => $body,
                    'callback_headers' => json_encode($headers, JSON_UNESCAPED_UNICODE),
                    'verify_result'    => 'fail',
                ])->markProcessed('fail', ['error' => $e->getMessage()]);
            } catch (Exception $logEx) {
                \think\Log::error('回调日志记录失败: ' . $logEx->getMessage());
            }
            return json(['code' => 'FAIL', 'message' => $e->getMessage()]);
        }
    }

    /**
     * 处理支付成功逻辑
     *
     * @param string $orderNo 订单号
     * @param array  $resource 回调资源数据
     * @throws Exception
     */
    private function handlePaymentSuccess($orderNo, array $resource)
    {
        $transactionId = $resource['transaction_id'] ?? '';

        Db::startTrans();
        try {
            // 查询订单(加行锁)
            $order = VoucherOrder::where('order_no', $orderNo)
                ->lock(true)
                ->find();

            if (!$order) {
                throw new Exception('订单不存在');
            }

            // 订单明细（兼容多商品）
            // 注意：确保 items 关联被正确加载为集合
            $orderItems = $order->items()->select();

            // 防止重复处理
            if ($order->state == 2) {
                Db::commit();
                return;
            }

            // 验证订单状态
            if ($order->state != 1) {
                throw new Exception('订单状态异常');
            }

            // 同步订单数量（以明细为准）
            if ($orderItems && count($orderItems) > 0) {
                // 兼容处理：确保可以调用 toArray()
                $itemsArray = is_object($orderItems) && method_exists($orderItems, 'toArray')
                    ? $orderItems->toArray()
                    : (array)$orderItems;

                $order->quantity = array_sum(array_map(function ($item) {
                    // 兼容数组和对象
                    $quantity = is_array($item) ? ($item['quantity'] ?? 0) : ($item->quantity ?? 0);
                    return (int)$quantity;
                }, $itemsArray));
            }

            // 更新订单状态
            $order->state = 2;  // 已支付
            $order->paymenttime = time();
            $order->transaction_id = $transactionId;
            $order->save();

            // 读取券有效期配置（单位：天）
            $rule = VoucherRule::getActiveRule();
            if ($rule) {
                $validDays = (int)$rule->expire_days;
                $ruleId = (int)$rule->id;
            } else {
                $voucherConfig = config('voucher');
                $validDays = isset($voucherConfig['valid_days']) ? (int)$voucherConfig['valid_days'] : 30;
                $ruleId = 0;
            }
            if ($validDays <= 0) {
                $validDays = 30;
            }
            $now = time();
            $validEnd = $now + $validDays * 86400;

            $totalVouchers = 0;

            if ($orderItems && count($orderItems) > 0) {
                foreach ($orderItems as $item) {
                    $itemQuantity = (int)$item->quantity;
                    if ($itemQuantity < 1) {
                        continue;
                    }

                    // 更新商品销量
                    Goods::where('id', $item->goods_id)->setInc('sales', $itemQuantity);

                    // 补充商品信息
                    $goodsTitle = $item->goods_title;
                    $goodsImage = $item->goods_image;
                    if (!$goodsTitle || !$goodsImage) {
                        $goods = Goods::find($item->goods_id);
                        if ($goods) {
                            $goodsTitle = $goodsTitle ?: $goods->title;
                            $goodsImage = $goodsImage ?: $goods->image;
                        }
                    }

                    $facePerUnit = $itemQuantity > 0 ? $item->actual_payment / $itemQuantity : 0;
                    $goodsSkuId = isset($item->goods_sku_id) ? (int)$item->goods_sku_id : 0;
                    $skuDifference = isset($item->sku_difference) ? (string)$item->sku_difference : '';
                    $skuWeight = isset($item->sku_weight) ? (float)$item->sku_weight : 0;

                    for ($i = 0; $i < $itemQuantity; $i++) {
                        $voucher = new Voucher();
                        $voucher->voucher_no = $this->generateVoucherNo();
                        $voucher->verify_code = $this->generateVerifyCode();
                        $voucher->order_id = $order->id;
                        $voucher->user_id = $order->user_id;
                        $voucher->category_id = $item->category_id;
                        $voucher->goods_id = $item->goods_id;
                        $voucher->goods_sku_id = $goodsSkuId;
                        $voucher->goods_title = $goodsTitle;
                        $voucher->goods_image = $goodsImage;
                        $voucher->sku_difference = $skuDifference;
                        $voucher->sku_weight = $skuWeight;
                        $voucher->supply_price = 0;  // 供货价在核销时确定
                        $voucher->face_value = $facePerUnit;
                        $voucher->valid_start = $now;
                        $voucher->valid_end = $validEnd;
                        $voucher->rule_id = $ruleId;
                        $voucher->state = 1;  // 未使用
                        $voucher->createtime = $now;
                        $voucher->save();
                        $totalVouchers++;
                    }
                }
            } else {
                // 兼容单商品旧路径
                $goods = Goods::find($order->goods_id);
                if (!$goods) {
                    throw new Exception('商品不存在');
                }
                // 更新商品销量
                Goods::where('id', $order->goods_id)->setInc('sales', $order->quantity);

                for ($i = 0; $i < $order->quantity; $i++) {
                    $voucher = new Voucher();
                    $voucher->voucher_no = $this->generateVoucherNo();
                    $voucher->verify_code = $this->generateVerifyCode();
                    $voucher->order_id = $order->id;
                    $voucher->user_id = $order->user_id;
                    $voucher->category_id = $order->category_id;
                    $voucher->goods_id = $order->goods_id;
                    $voucher->goods_sku_id = isset($order->goods_sku_id) ? (int)$order->goods_sku_id : 0;
                    $voucher->goods_title = $goods->title;
                    $voucher->goods_image = $goods->image;
                    $voucher->sku_difference = isset($order->sku_difference) ? (string)$order->sku_difference : '';
                    $voucher->sku_weight = isset($order->sku_weight) ? (float)$order->sku_weight : 0;
                    $voucher->supply_price = 0;  // 供货价在核销时确定
                    $voucher->face_value = $order->actual_payment / $order->quantity;
                    $voucher->valid_start = $now;
                    $voucher->valid_end = $validEnd;
                    $voucher->rule_id = $ruleId;
                    $voucher->state = 1;  // 未使用
                    $voucher->createtime = $now;
                    $voucher->save();
                    $totalVouchers++;
                }
            }
            Db::commit();

            // 事务成功后记录回调处理成功
            try {
                PaymentCallbackLog::recordCallback([
                    'order_type'       => 'voucher',
                    'order_no'         => $orderNo,
                    'transaction_id'   => $transactionId,
                    'trade_state'      => $resource['trade_state'] ?? '',
                    'callback_body'    => json_encode($resource),
                    'callback_headers' => json_encode([]),  // 由 notify 方法传入
                    'verify_result'    => 'success',
                ])->markProcessed('success', ['vouchers_created' => $totalVouchers ?: $order->quantity]);
            } catch (Exception $logEx) {
                // 日志记录失败不影响业务
                \think\Log::error('回调日志记录失败: ' . $logEx->getMessage());
            }

        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 生成券号(保证唯一)
     *
     * @return string
     */
    private function generateVoucherNo()
    {
        do {
            $no = 'VCH' . date('YmdHis') . mt_rand(100000, 999999);
        } while (Voucher::where('voucher_no', $no)->count() > 0);

        return $no;
    }

    /**
     * 生成6位数字验证码
     *
     * @return string
     */
    private function generateVerifyCode()
    {
        return str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 微信退款回调
     *
     * @ApiSummary  (微信退款结果通知)
     * @ApiMethod   (POST)
     *
     * 回调事件类型（event_type）：
     * - REFUND.SUCCESS: 退款成功
     * - REFUND.ABNORMAL: 退款异常
     * - REFUND.CLOSED: 退款关闭
     */
    public function refundNotify()
    {
        $body = '';
        $headers = [];
        $resource = [];
        $outRefundNo = '';
        $refundId = '';
        $eventType = '';

        try {
            // 原始报文
            $body = file_get_contents('php://input');

            // 记录回调日志
            \think\Log::info('微信退款回调原始报文: ' . $body);

            // 读取回调头部
            $timestamp = $this->request->header('Wechatpay-Timestamp');
            $nonce     = $this->request->header('Wechatpay-Nonce');
            $signature = $this->request->header('Wechatpay-Signature');
            $serial    = $this->request->header('Wechatpay-Serial');

            $headers = [
                'timestamp' => $timestamp,
                'nonce'     => $nonce,
                'signature' => $signature,
                'serial'    => $serial,
            ];

            // 验证签名
            if (!WechatPayment::verifyCallbackSignature($headers, $body)) {
                \think\Log::error('微信退款回调：签名验证失败');
                // 签名验证失败不记录到数据库，避免无效数据
                return json(['code' => 'FAIL', 'message' => '签名验证失败']);
            }

            $data = json_decode($body, true);
            if (!is_array($data) || empty($data['resource'])) {
                \think\Log::error('微信退款回调：报文格式错误');
                return json(['code' => 'FAIL', 'message' => '报文格式错误']);
            }

            // 获取事件类型
            $eventType = $data['event_type'] ?? '';
            \think\Log::info('微信退款回调事件类型: ' . $eventType);

            // 解密资源数据
            $resource = WechatPayment::decryptCallbackResource($data['resource']);
            $outRefundNo = $resource['out_refund_no'] ?? '';
            $refundId = $resource['refund_id'] ?? '';
            $refundStatus = $resource['refund_status'] ?? '';
            $outTradeNo = $resource['out_trade_no'] ?? '';

            \think\Log::info('微信退款回调解密数据: ' . json_encode($resource, JSON_UNESCAPED_UNICODE));

            if (!$outRefundNo) {
                \think\Log::error('微信退款回调：out_refund_no 缺失');
                return json(['code' => 'FAIL', 'message' => '退款单号缺失']);
            }

            // 幂等性检查：使用 refund_id 判断是否已处理
            if ($refundId && PaymentCallbackLog::where('transaction_id', $refundId)
                ->where('order_type', 'voucher_refund')
                ->where('process_status', 'success')
                ->value('id')) {
                \think\Log::info('微信退款回调:重复通知,已处理 - ' . $refundId);
                return json(['code' => 'SUCCESS', 'message' => '']);
            }

            // 根据事件类型处理
            $processResult = 'success';
            $processData = [];
            if ($eventType === 'REFUND.SUCCESS' || $refundStatus === 'SUCCESS') {
                $this->handleRefundSuccess($outRefundNo, $resource);
                $processData = ['action' => 'refund_success', 'refund_no' => $outRefundNo];
            } elseif ($eventType === 'REFUND.ABNORMAL' || $refundStatus === 'ABNORMAL') {
                $this->handleRefundAbnormal($outRefundNo, $resource);
                $processResult = 'fail';
                $processData = ['action' => 'refund_abnormal', 'refund_no' => $outRefundNo];
            } elseif ($eventType === 'REFUND.CLOSED' || $refundStatus === 'CLOSED') {
                $this->handleRefundClosed($outRefundNo, $resource);
                $processData = ['action' => 'refund_closed', 'refund_no' => $outRefundNo];
            } else {
                \think\Log::info('微信退款回调：未知事件类型或退款状态，忽略');
                $processData = ['action' => 'ignored', 'event_type' => $eventType];
            }

            // 记录回调日志到数据库
            try {
                PaymentCallbackLog::recordCallback([
                    'order_type'       => 'voucher_refund',
                    'order_no'         => $outRefundNo,  // 使用退款单号
                    'transaction_id'   => $refundId,      // 使用微信退款单号
                    'trade_state'      => $refundStatus ?: $eventType,
                    'callback_body'    => $body,
                    'callback_headers' => json_encode($headers, JSON_UNESCAPED_UNICODE),
                    'verify_result'    => 'success',
                ])->markProcessed($processResult, $processData);
            } catch (Exception $logEx) {
                \think\Log::error('退款回调日志记录失败: ' . $logEx->getMessage());
            }

            // 必须在5秒内返回成功响应
            return json(['code' => 'SUCCESS', 'message' => '']);

        } catch (Exception $e) {
            \think\Log::error('微信退款回调处理失败: ' . $e->getMessage());
            // 记录失败日志
            try {
                PaymentCallbackLog::recordCallback([
                    'order_type'       => 'voucher_refund',
                    'order_no'         => $outRefundNo ?: '',
                    'transaction_id'   => $refundId ?: '',
                    'trade_state'      => $eventType ?: '',
                    'callback_body'    => $body,
                    'callback_headers' => json_encode($headers, JSON_UNESCAPED_UNICODE),
                    'verify_result'    => 'success',
                ])->markProcessed('fail', ['error' => $e->getMessage()]);
            } catch (Exception $logEx) {
                \think\Log::error('退款回调日志记录失败: ' . $logEx->getMessage());
            }
            return json(['code' => 'FAIL', 'message' => $e->getMessage()]);
        }
    }

    /**
     * 处理退款成功逻辑
     *
     * @param string $outRefundNo 商户退款单号
     * @param array  $resource 回调资源数据
     * @throws Exception
     */
    private function handleRefundSuccess($outRefundNo, array $resource)
    {
        \think\Log::info('处理退款成功: ' . $outRefundNo);

        // 判断是否为代管理退款（CRF开头）
        if (strpos($outRefundNo, 'CRF') === 0) {
            $custodyRefundService = new CustodyRefundService();
            $custodyRefundService->handleRefundSuccess($outRefundNo);
            return;
        }

        // 以下是普通用户退款处理逻辑
        Db::startTrans();
        try {
            // 查询退款记录（商户退款单号 = refund_no）
            $refund = VoucherRefund::where('refund_no', $outRefundNo)
                ->lock(true)
                ->find();

            if (!$refund) {
                \think\Log::error('退款记录不存在: ' . $outRefundNo);
                throw new Exception('退款记录不存在');
            }

            // 防止重复处理（已经是退款成功状态）
            if ($refund->state == 3) {
                \think\Log::info('退款已处理，跳过: ' . $outRefundNo);
                Db::commit();
                return;
            }

            // 更新退款记录状态
            $refund->state = 3;  // 退款成功
            $refund->save();

            // 更新券状态
            $voucher = Voucher::get($refund->voucher_id);
            if ($voucher) {
                $voucher->state = 4;  // 已退款
                $voucher->refundtime = time();
                $voucher->save();
            }

            // 更新订单状态（标记为存在退款）
            $voucherOrder = VoucherOrder::get($refund->order_id);
            if ($voucherOrder && $voucherOrder->state == 2) {
                $voucherOrder->state = 4;  // 存在退款
                $voucherOrder->save();
            }

            Db::commit();

            \think\Log::info('退款成功处理完成: ' . $outRefundNo);

        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 处理退款异常逻辑
     *
     * @param string $outRefundNo 商户退款单号
     * @param array  $resource 回调资源数据
     */
    private function handleRefundAbnormal($outRefundNo, array $resource)
    {
        \think\Log::warning('退款异常: ' . $outRefundNo . ', 数据: ' . json_encode($resource, JSON_UNESCAPED_UNICODE));

        // 退款异常需要人工处理，记录日志但不自动更改状态
        // 可以在后台管理界面查看异常情况并手动处理
    }

    /**
     * 处理退款关闭逻辑
     *
     * @param string $outRefundNo 商户退款单号
     * @param array  $resource 回调资源数据
     */
    private function handleRefundClosed($outRefundNo, array $resource)
    {
        \think\Log::warning('退款关闭: ' . $outRefundNo . ', 数据: ' . json_encode($resource, JSON_UNESCAPED_UNICODE));

        // 判断是否为代管理退款（CRF开头）
        if (strpos($outRefundNo, 'CRF') === 0) {
            $custodyRefundService = new CustodyRefundService();
            $custodyRefundService->handleRefundFailed($outRefundNo, '微信退款关闭');
            return;
        }

        // 以下是普通用户退款处理逻辑
        // 退款关闭意味着退款请求被取消
        // 可根据业务需求决定是否恢复券状态
        try {
            $refund = VoucherRefund::where('refund_no', $outRefundNo)->find();
            if ($refund && $refund->state == 1) {
                // 如果退款被关闭，恢复券状态为未使用
                $voucher = Voucher::get($refund->voucher_id);
                if ($voucher && $voucher->state == 5) {
                    $voucher->state = 1;  // 恢复为未使用
                    $voucher->save();
                }

                // 标记退款为拒绝状态
                $refund->state = 2;  // 拒绝退款
                $refund->refuse_reason = '微信退款关闭';
                $refund->save();
            }
        } catch (Exception $e) {
            \think\Log::error('处理退款关闭失败: ' . $e->getMessage());
        }
    }
}
