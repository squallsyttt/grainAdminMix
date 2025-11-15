<?php
namespace app\api\controller\wanlshop\voucher;

use app\common\controller\Api;
use app\admin\model\wanlshop\VoucherOrder;
use app\admin\model\wanlshop\Voucher;
use app\api\model\wanlshop\Third;
use app\common\library\WechatPayV3;
use app\admin\model\wanlshop\Goods;
use think\Db;
use think\Exception;

/**
 * 核销券订单接口
 */
class Order extends Api
{
    protected $noNeedLogin = [];
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
        $quantity = $this->request->post('quantity/d', 1);

        if (!$goodsId || $quantity < 1) {
            $this->error(__('参数错误'));
        }

        // 查询商品信息
        $goods = Goods::where(['id' => $goodsId, 'status' => 'normal'])->find();
        if (!$goods) {
            $this->error(__('商品不存在'));
        }

        // 生成订单号
        $orderNo = 'ORD' . date('Ymd') . mt_rand(100000, 999999);

        // 计算订单金额
        $supplyPrice = $goods->supply_price * $quantity;  // 供货价总额
        $retailPrice = $goods->price * $quantity;         // 零售价总额
        $actualPayment = $retailPrice;                    // 实际支付(暂不考虑优惠)

        Db::startTrans();
        try {
            // 创建订单
            $order = new VoucherOrder();
            $order->user_id = $this->auth->id;
            $order->order_no = $orderNo;
            $order->goods_id = $goods->id;
            $order->category_id = $goods->category_id;
            $order->quantity = $quantity;
            $order->supply_price = $supplyPrice;
            $order->retail_price = $retailPrice;
            $order->actual_payment = $actualPayment;
            $order->state = 1;  // 待支付
            $order->createtime = time();
            $order->save();

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
        $goods = Goods::find($order->goods_id);
        $description = $goods && $goods->title ? $goods->title : ('核销券订单-' . $order->order_no);

        try {
            // 调用微信 JSAPI 下单
            $resp = WechatPayV3::jsapiPrepay([
                'description'     => $description,
                'out_trade_no'    => $order->order_no,
                'amount_total'    => $totalAmount,
                'payer_openid'    => $third->openid,
                'payer_client_ip' => $this->request->ip(),
            ]);

            // 生成前端 wx.requestPayment 所需参数
            $payParams = WechatPayV3::buildJsapiPayParams($resp['prepay_id']);

            $this->success('ok', [
                'order_no'  => $order->order_no,
                'prepay_id' => $resp['prepay_id'],
                'payparams' => $payParams,
            ]);
        } catch (Exception $e) {
            \think\Log::error('核销券订单预下单失败：' . $e->getMessage());
            $this->error(__('微信预下单失败：') . $e->getMessage());
        }
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
        $order = VoucherOrder::where([
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

        // 关联查询生成的核销券
        $order->vouchers;

        $this->success('ok', $order);
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

        if ($state && in_array($state, ['1', '2', '3'])) {
            $where['state'] = $state;
        }

        // 分页查询
        $list = VoucherOrder::where($where)
            ->order('createtime desc')
            ->paginate(10)
            ->each(function($order) {
                // 关联商品信息
                $order->goods;
                // 关联券信息
                $order->vouchers;
                return $order;
            });

        $this->success('ok', $list);
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
        try {
            // 原始报文
            $body = file_get_contents('php://input');

            // 记录回调日志
            \think\facade\Log::info('微信支付回调原始报文: ' . $body);

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
            if (!WechatPayV3::verifyCallbackSignature($headers, $body)) {
                \think\facade\Log::error('微信支付回调：签名验证失败');
                return json(['code' => 'FAIL', 'message' => '签名验证失败']);
            }

            $data = json_decode($body, true);
            if (!is_array($data) || empty($data['resource'])) {
                \think\facade\Log::error('微信支付回调：报文格式错误');
                return json(['code' => 'FAIL', 'message' => '报文格式错误']);
            }

            // 解密资源数据
            $resource = WechatPayV3::decryptCallbackResource($data['resource']);

            // 仅处理支付成功通知
            if (!isset($resource['trade_state']) || $resource['trade_state'] !== 'SUCCESS') {
                \think\facade\Log::info('微信支付回调：非成功交易状态，忽略');
                return json(['code' => 'SUCCESS', 'message' => '']);
            }

            $outTradeNo = $resource['out_trade_no'] ?? '';
            if (!$outTradeNo) {
                \think\facade\Log::error('微信支付回调：out_trade_no 缺失');
                return json(['code' => 'FAIL', 'message' => '订单号缺失']);
            }

            // 金额校验
            $order = VoucherOrder::where('order_no', $outTradeNo)->find();
            if (!$order) {
                \think\facade\Log::error('微信支付回调：订单不存在 - ' . $outTradeNo);
                return json(['code' => 'FAIL', 'message' => '订单不存在']);
            }

            if (!empty($resource['amount']['total'])) {
                $total = (int)$resource['amount']['total'];
                $orderAmount = (int)bcmul($order->actual_payment, 100, 0);
                if ($total !== $orderAmount) {
                    \think\facade\Log::error(sprintf(
                        '微信支付回调：金额不匹配，订单号=%s, 回调金额=%s, 订单金额=%s',
                        $outTradeNo,
                        $total,
                        $orderAmount
                    ));
                    return json(['code' => 'FAIL', 'message' => '金额不匹配']);
                }
            }

            // 处理支付成功逻辑
            $this->handlePaymentSuccess($outTradeNo);

            // 必须在5秒内返回成功响应
            return json(['code' => 'SUCCESS', 'message' => '']);

        } catch (Exception $e) {
            \think\facade\Log::error('微信支付回调处理失败: ' . $e->getMessage());
            return json(['code' => 'FAIL', 'message' => $e->getMessage()]);
        }
    }

    /**
     * 处理支付成功逻辑
     *
     * @param string $orderNo 订单号
     * @throws Exception
     */
    private function handlePaymentSuccess($orderNo)
    {
        Db::startTrans();
        try {
            // 查询订单(加行锁)
            $order = VoucherOrder::where('order_no', $orderNo)
                ->lock(true)
                ->find();

            if (!$order) {
                throw new Exception('订单不存在');
            }

            // 防止重复处理
            if ($order->state == 2) {
                Db::commit();
                return;
            }

            // 验证订单状态
            if ($order->state != 1) {
                throw new Exception('订单状态异常');
            }

            // 更新订单状态
            $order->state = 2;  // 已支付
            $order->paymenttime = time();
            $order->save();

            // 查询商品信息
            $goods = Goods::find($order->goods_id);
            if (!$goods) {
                throw new Exception('商品不存在');
            }

            // 读取券有效期配置（单位：天）
            $voucherConfig = config('voucher');
            $validDays = isset($voucherConfig['valid_days']) ? (int)$voucherConfig['valid_days'] : 30;
            if ($validDays <= 0) {
                $validDays = 30;
            }
            $now = time();
            $validEnd = $now + $validDays * 86400;

            // 生成核销券
            for ($i = 0; $i < $order->quantity; $i++) {
                $voucher = new Voucher();
                $voucher->voucher_no = $this->generateVoucherNo();
                $voucher->verify_code = $this->generateVerifyCode();
                $voucher->order_id = $order->id;
                $voucher->user_id = $order->user_id;
                $voucher->category_id = $order->category_id;
                $voucher->goods_id = $order->goods_id;
                $voucher->goods_title = $goods->title;
                $voucher->goods_image = $goods->image;
                $voucher->supply_price = $order->supply_price / $order->quantity;
                $voucher->face_value = $order->actual_payment / $order->quantity;
                $voucher->valid_start = $now;
                $voucher->valid_end = $validEnd;
                $voucher->state = 1;  // 未使用
                $voucher->createtime = $now;
                $voucher->save();
            }

            Db::commit();

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
            $no = 'VCH' . date('Ymd') . mt_rand(100000, 999999);
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
}
