<?php
namespace app\api\controller\wanlshop\voucher;

use app\common\controller\Api;
use app\admin\model\wanlshop\Voucher;
use app\admin\model\wanlshop\VoucherVerification;
use app\admin\model\wanlshop\VoucherSettlement;
use app\admin\model\wanlshop\Shop;
use app\common\service\VoucherRebateService;
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

            // 获取核销店铺商品信息（含供货价）
            $rebateService = new VoucherRebateService();
            $shopGoodsInfo = $rebateService->getShopGoodsInfo(
                $shop->id,
                $voucher->category_id,
                $voucher->sku_difference
            );
            $shopSupplyPrice = $shopGoodsInfo['sku_price'];

            // 更新券状态
            $voucher->state = 2;  // 已核销
            $voucher->shop_id = $shop->id;
            $voucher->shop_name = $shop->shopname;
            $voucher->verify_user_id = $this->auth->id;  // 核销操作员
            $voucher->verifytime = time();
            $voucher->supply_price = $shopSupplyPrice;
            $voucher->save();

            // 插入核销记录
            $verification = new VoucherVerification();
            $verification->voucher_id = $voucher->id;
            $verification->voucher_no = $voucher->voucher_no;
            $verification->user_id = $voucher->user_id;
            $verification->shop_id = $shop->id;
            $verification->shop_name = $shop->shopname;
            $verification->verify_user_id = $this->auth->id;
            $verification->shop_goods_id = $shopGoodsInfo['goods_id'];
            $verification->shop_goods_title = $shopGoodsInfo['goods_title'];
            $verification->supply_price = $shopSupplyPrice;
            $verification->face_value = $voucher->face_value;
            $verification->verify_method = $verifyMethod;
            $verification->createtime = time();
            $verification->save();

            // 创建结算记录（使用店铺供货价）
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
            $settlement->supply_price = $shopSupplyPrice;  // 使用店铺供货价
            $settlement->shop_amount = $shopSupplyPrice;  // 商家结算金额=店铺供货价
            $settlement->platform_amount = round((float)$voucher->face_value - $shopSupplyPrice, 2);  // 平台利润
            $settlement->state = 1;  // 待结算
            $settlement->createtime = time();
            $settlement->shop_goods_id = $shopGoodsInfo['goods_id'];
            $settlement->shop_goods_title = $shopGoodsInfo['goods_title'];
            $settlement->save();

            // 生成返利结算记录（店铺无对应SKU会抛出异常，拒绝核销）
            $rebateService->createRebateRecord($voucher, $verification, time(), $shop->id, $shopGoodsInfo);

            // 轨道1：被邀请人核销触发邀请人升级
            $this->processInviterUpgrade($voucher->user_id, $verification->id, $voucher->id);
            // 轨道2：邀请人自核销返利发放
            $this->processCashbackReward($voucher->user_id, $verification->id, $voucher->id);

            Db::commit();

            return [
                'voucher_id' => $voucher->id,
                'voucher_no' => $voucher->voucher_no,
                'goods_title' => $voucher->goods_title,
                'face_value' => $voucher->face_value,
                'shop_amount' => $shopSupplyPrice,
            ];

        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 轨道1：被邀请人首次核销触发邀请人等级升级
     *
     * @param int $inviteeUserId 被核销券的用户ID（被邀请者）
     * @param int $verificationId 核销记录ID
     * @param int $voucherId 券ID
     */
    protected function processInviterUpgrade($inviteeUserId, $verificationId, $voucherId)
    {
        $invitee = Db::name('user')->where('id', $inviteeUserId)->field('inviter_id')->find();
        if (empty($invitee['inviter_id'])) {
            return;
        }

        $inviterId = (int)$invitee['inviter_id'];

        $inviter = Db::name('user')
            ->where('id', $inviterId)
            ->lock(true)
            ->field('id, bonus_ratio, bonus_level')
            ->find();

        if (!$inviter) {
            throw new Exception('邀请人不存在');
        }

        $currentLevel = (int)$inviter['bonus_level'];
        if ($currentLevel >= 2) {
            return;
        }

        $alreadyUpgraded = Db::name('user_invite_upgrade_log')
            ->where('user_id', $inviterId)
            ->where('invitee_id', $inviteeUserId)
            ->lock(true)
            ->find();
        if ($alreadyUpgraded) {
            return;
        }

        $ratioConfig = $this->getInviteRatios();
        $afterLevel = $currentLevel + 1;
        if (!array_key_exists($afterLevel, $ratioConfig)) {
            throw new Exception('返利比例配置异常');
        }

        $beforeRatio = (float)$inviter['bonus_ratio'];
        $afterRatio = (float)$ratioConfig[$afterLevel];
        $now = time();

        Db::name('user')->where('id', $inviterId)->update([
            'bonus_ratio' => $afterRatio,
            'bonus_level' => $afterLevel
        ]);

        Db::name('user_invite_upgrade_log')->insert([
            'user_id' => $inviterId,
            'invitee_id' => $inviteeUserId,
            'verification_id' => $verificationId,
            'voucher_id' => $voucherId,
            'before_level' => $currentLevel,
            'after_level' => $afterLevel,
            'before_ratio' => $beforeRatio,
            'after_ratio' => $afterRatio,
            'createtime' => $now
        ]);
    }

    /**
     * 轨道2：邀请人自核销返利发放
     *
     * @param int $userId 核销人（邀请人）ID
     * @param int $verificationId 核销记录ID
     * @param int $voucherId 券ID
     */
    protected function processCashbackReward($userId, $verificationId, $voucherId)
    {
        $user = Db::name('user')
            ->where('id', $userId)
            ->field('id, bonus_level, bonus_ratio')
            ->find();

        if (!$user) {
            throw new Exception('核销用户不存在');
        }

        $ratioConfig = $this->getInviteRatios();
        $bonusLevel = (int)$user['bonus_level'];
        if (!array_key_exists($bonusLevel, $ratioConfig)) {
            throw new Exception('返利比例配置异常');
        }
        $bonusRatio = (float)$ratioConfig[$bonusLevel];

        $voucher = Voucher::where('id', $voucherId)->field('id')->find();
        if (!$voucher) {
            throw new Exception('核销券不存在');
        }

        $verification = VoucherVerification::where('id', $verificationId)
            ->field('shop_id, verify_method, supply_price')
            ->find();
        if (!$verification) {
            throw new Exception('核销记录不存在');
        }

        $supplyPrice = (float)$verification->supply_price;
        $cashbackAmount = round($supplyPrice * ($bonusRatio / 100), 2);
        $now = time();

        Db::name('user_cashback_log')->insert([
            'user_id' => $userId,
            'voucher_id' => $voucherId,
            'verification_id' => $verificationId,
            'cashback_amount' => $cashbackAmount,
            'supply_price' => $supplyPrice,
            'bonus_ratio' => $bonusRatio,
            'shop_id' => $verification->shop_id,
            'verify_method' => $verification->verify_method,
            'createtime' => $now,
            'updatetime' => $now
        ]);
    }

    /**
     * 获取返利比例配置
     *
     * @return array
     * @throws Exception
     */
    protected function getInviteRatios()
    {
        $ratios = [
            0 => config('site.invite_base_ratio'),
            1 => config('site.invite_level1_ratio'),
            2 => config('site.invite_level2_ratio'),
        ];

        foreach ($ratios as $level => $ratio) {
            if (!is_numeric($ratio)) {
                throw new Exception('返利比例配置异常');
            }
            $ratios[$level] = (float)$ratio;
        }

        return $ratios;
    }

    /**
     * 获取当前登录商家的店铺
     *
     * @return Shop
     */
    protected function getMerchantShop()
    {
        // 先取当前登录用户在 user 表中绑定的店铺 ID
        $bindShopId = Db::name('user')->where('id', $this->auth->id)->value('bind_shop');
        if (!$bindShopId) {
            $this->error(__('店铺不存在'));
        }

        // 再用绑定的店铺 ID 查询具体店铺
        $shop = Shop::where('id', $bindShopId)->find();
        if (!$shop) {
            $this->error(__('店铺不存在'));
        }

        return $shop;
    }
}
