<?php
namespace app\api\controller\wanlshop\voucher;

use app\common\controller\Api;
use app\admin\model\wanlshop\Voucher;
use app\admin\model\wanlshop\VoucherVerification;
use app\admin\model\wanlshop\VoucherSettlement;
use app\admin\model\wanlshop\Shop;
use think\Db;
use think\Exception;

/**
 * 核销券核销接口(商家端)
 */
class Verify extends Api
{
    protected $noNeedLogin = [];
    protected $noNeedRight = ['*'];

    /**
     * 验证码核销
     *
     * @ApiSummary  (核销券验证码核销)
     * @ApiMethod   (POST)
     *
     * @param string $voucher_no 券号(可选)
     * @param string $verify_code 验证码(可选,至少提供一个)
     * @param int $shop_id 店铺ID
     */
    public function code()
    {
        $this->request->filter(['strip_tags']);

        if (!$this->request->isPost()) {
            $this->error(__('非法请求'));
        }

        $voucherNo = $this->request->post('voucher_no', '');
        $verifyCode = $this->request->post('verify_code', '');
        $shopId = $this->request->post('shop_id/d');

        // 至少提供券号或验证码之一
        if (!$voucherNo && !$verifyCode) {
            $this->error(__('请输入券号或验证码'));
        }

        if (!$shopId) {
            $this->error(__('店铺ID不能为空'));
        }

        // 查询店铺
        $shop = Shop::find($shopId);
        if (!$shop) {
            $this->error(__('店铺不存在'));
        }

        // 构建查询条件
        $where = ['status' => 'normal'];
        if ($voucherNo) {
            $where['voucher_no'] = $voucherNo;
        } else {
            $where['verify_code'] = $verifyCode;
        }

        try {
            $result = $this->handleVerification($where, $shop, 'code');
            $this->success('核销成功', $result);
        } catch (Exception $e) {
            $this->error(__('核销失败: ') . $e->getMessage());
        }
    }

    /**
     * 券信息查询（扫码确认）
     *
     * @ApiSummary  (扫码后获取券详情)
     * @ApiMethod   (GET)
     *
     * @param string $voucher_no 券号
     */
    public function info()
    {
        $this->request->filter(['strip_tags']);

        $voucherNo = $this->request->get('voucher_no', '');
        if (!$voucherNo) {
            $this->error(__('券号不能为空'));
        }

        // 验证商家身份
        $this->getMerchantShop();

        $voucher = Voucher::with(['goods', 'user'])
            ->where([
                'voucher_no' => $voucherNo,
                'status' => 'normal'
            ])
            ->find();

        if (!$voucher) {
            $this->error(__('券不存在'));
        }

        $now = time();
        if ($voucher->state != 1) {
            $stateText = $voucher->state_text;
            $this->error("券不可核销(状态: {$stateText})");
        }

        if ($now < $voucher->valid_start || $now > $voucher->valid_end) {
            $this->error(__('券不在有效期内'));
        }

        // 预加载关联数据
        $voucher->goods;
        $voucher->user;

        $this->success('ok', [
            'voucher' => $voucher,
            'can_verify' => true
        ]);
    }

    /**
     * 扫码核销
     *
     * @ApiSummary  (扫码核销券)
     * @ApiMethod   (POST)
     *
     * @param string $voucher_no 券号
     */
    public function scan()
    {
        $this->request->filter(['strip_tags']);

        if (!$this->request->isPost()) {
            $this->error(__('非法请求'));
        }

        $voucherNo = $this->request->post('voucher_no', '');
        if (!$voucherNo) {
            $this->error(__('请输入券号'));
        }

        // 自动获取商家店铺
        $shop = $this->getMerchantShop();
        $where = [
            'status' => 'normal',
            'voucher_no' => $voucherNo
        ];

        try {
            $result = $this->handleVerification($where, $shop, 'scan', '券不存在');
            $this->success('核销成功', $result);
        } catch (Exception $e) {
            $this->error(__('核销失败: ') . $e->getMessage());
        }
    }

    /**
     * 核销记录列表
     *
     * @ApiSummary  (获取核销记录列表)
     * @ApiMethod   (GET)
     *
     * @param int $shop_id 店铺ID
     */
    public function records()
    {
        $this->request->filter(['strip_tags']);

        $shopId = $this->request->get('shop_id/d');
        if (!$shopId) {
            $this->error(__('店铺ID不能为空'));
        }

        // 查询核销记录
        $list = VoucherVerification::where([
            'shop_id' => $shopId,
            'status' => 'normal'
        ])
            ->order('createtime desc')
            ->paginate(10)
            ->each(function($record) {
                // 关联券信息
                $record->voucher;
                // 关联用户信息
                $record->user;
                return $record;
            });

        $this->success('ok', $list);
    }

    /**
     * 统一核销处理
     *
     * @param array $where 查询条件
     * @param Shop $shop 当前商家店铺
     * @param string $verifyMethod 核销方式
     * @param string $notFoundMessage 未找到券时的提示
     * @return array
     * @throws Exception
     */
    protected function handleVerification(array $where, Shop $shop, $verifyMethod = 'code', $notFoundMessage = '券不存在或验证码错误')
    {
        Db::startTrans();
        try {
            // 查询券并加锁
            $voucher = Voucher::where($where)
                ->lock(true)
                ->find();

            if (!$voucher) {
                throw new Exception($notFoundMessage);
            }

            // 验证券状态
            if ($voucher->state != 1) {
                $stateText = $voucher->state_text;
                throw new Exception("券不可核销(状态: {$stateText})");
            }

            // 验证有效期
            $now = time();
            if ($now < $voucher->valid_start || $now > $voucher->valid_end) {
                throw new Exception('券不在有效期内');
            }

            // 更新券状态
            $voucher->state = 2;  // 已核销
            $voucher->shop_id = $shop->id;
            $voucher->shop_name = $shop->shopname;
            $voucher->verify_user_id = $this->auth->id;  // 核销操作员
            $voucher->verifytime = time();
            $voucher->save();

            // 插入核销记录
            $verification = new VoucherVerification();
            $verification->voucher_id = $voucher->id;
            $verification->voucher_no = $voucher->voucher_no;
            $verification->user_id = $voucher->user_id;
            $verification->shop_id = $shop->id;
            $verification->shop_name = $shop->shopname;
            $verification->verify_user_id = $this->auth->id;
            $verification->supply_price = $voucher->supply_price;
            $verification->face_value = $voucher->face_value;
            $verification->verify_method = $verifyMethod;
            $verification->createtime = time();
            $verification->save();

            // 创建结算记录
            $settlementNo = 'STL' . date('Ymd') . mt_rand(100000, 999999);
            $settlement = new VoucherSettlement();
            $settlement->settlement_no = $settlementNo;
            $settlement->voucher_id = $voucher->id;
            $settlement->voucher_no = $voucher->voucher_no;
            $settlement->order_id = $voucher->order_id;
            $settlement->shop_id = $shop->id;
            $settlement->shop_name = $shop->shopname;
            $settlement->user_id = $voucher->user_id;
            $settlement->retail_price = $voucher->face_value;
            $settlement->supply_price = $voucher->supply_price;
            $settlement->shop_amount = $voucher->supply_price;  // 商家结算金额=供货价
            $settlement->platform_amount = $voucher->face_value - $voucher->supply_price;  // 平台利润
            $settlement->state = 1;  // 待结算
            $settlement->createtime = time();
            $settlement->save();

            Db::commit();

            return [
                'voucher_id' => $voucher->id,
                'voucher_no' => $voucher->voucher_no,
                'goods_title' => $voucher->goods_title,
                'face_value' => $voucher->face_value,
                'shop_amount' => $voucher->supply_price,
            ];

        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 获取当前登录商家的店铺
     *
     * @return Shop
     */
    protected function getMerchantShop()
    {
        $shop = Shop::where('user_id', $this->auth->id)->find();
        if (!$shop) {
            $this->error(__('店铺不存在'));
        }

        return $shop;
    }
}
