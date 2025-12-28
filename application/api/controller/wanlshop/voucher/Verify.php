<?php
namespace app\api\controller\wanlshop\voucher;

use app\common\controller\Api;
use app\admin\model\wanlshop\Voucher;
use app\admin\model\wanlshop\VoucherVerification;
use app\admin\model\wanlshop\VoucherSettlement;
use app\admin\model\wanlshop\Shop;
use app\common\service\VoucherRebateService;
use app\common\service\BdPromoterService;
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
        $shop = $this->getMerchantShop();

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

        // 获取券对应的城市信息（从关联商品获取）
        $voucherCityCode = null;
        $voucherCityName = null;
        if ($voucher->goods) {
            $voucherCityCode = $voucher->goods->region_city_code;
            $voucherCityName = $voucher->goods->region_city_name;
        }

        // 检查商家是否有对应的商品（城市 + 分类 + SKU）
        $shopGoodsCheck = $this->checkShopGoods(
            $shop->id,
            $voucher->category_id,
            $voucher->sku_difference,
            $voucherCityCode,
            $voucher->face_value
        );

        $this->success('ok', [
            'voucher' => $voucher,
            'can_verify' => $shopGoodsCheck['can_verify'],
            'verify_error' => $shopGoodsCheck['error_message'],
            'shop_goods_info' => $shopGoodsCheck['shop_goods_info']
        ]);
    }

    /**
     * 检查商家商品是否匹配核销券条件
     *
     * @param int $shopId 商家店铺ID
     * @param int $categoryId 券的分类ID
     * @param string $skuDifference SKU规格名（如 1KG、5KG）
     * @param string $voucherCityCode 券对应的城市编码
     * @param float $faceValue 券面值
     * @return array
     */
    protected function checkShopGoods($shopId, $categoryId, $skuDifference, $voucherCityCode, $faceValue)
    {
        $result = [
            'can_verify' => false,
            'error_message' => null,
            'shop_goods_info' => null
        ];

        // Step 1: 查找商家在该分类下的商品
        // 重要：必须过滤 status='normal'，否则可能匹配到已下架的商品
        // 优先级：1. 城市匹配+在售 2. 仅在售（跨城市兜底）3. 任意状态（用于诊断）
        $baseConditions = [
            'shop_id' => $shopId,
            'category_id' => $categoryId
        ];

        $goods = null;
        $matchType = null; // 记录匹配类型，便于调试

        // 优先查找：城市匹配 + 状态正常
        if ($voucherCityCode) {
            $goods = Db::name('wanlshop_goods')
                ->where($baseConditions)
                ->where('status', 'normal')
                ->where('region_city_code', $voucherCityCode)
                ->order('id', 'desc')
                ->find();
            if ($goods) {
                $matchType = 'city_and_status';
            }
        }

        // 兜底查找：状态正常（不限城市）
        if (!$goods) {
            $goods = Db::name('wanlshop_goods')
                ->where($baseConditions)
                ->where('status', 'normal')
                ->order('id', 'desc')
                ->find();
            if ($goods) {
                $matchType = 'status_only';
            }
        }

        // 诊断查找：如果没有在售商品，查找任意状态的商品用于错误提示
        $diagnosisGoods = null;
        if (!$goods) {
            $diagnosisGoods = Db::name('wanlshop_goods')
                ->where($baseConditions)
                ->order('id', 'desc')
                ->find();
        }

        $voucherCityName = $voucherCityCode ? Db::name('area')->where('code', $voucherCityCode)->value('name') : null;

        // 状态映射表
        $statusTextMap = [
            'normal' => '在售',
            'hidden' => '已下架',
            'offline' => '已下架',
            'violation' => '违规下架'
        ];

        // 如果找到了在售商品
        if ($goods) {
            $goodsCityCode = $goods['region_city_code'] ?? null;
            $goodsCityName = $goods['region_city_name'] ?? null;
            if (!$goodsCityName && $goodsCityCode) {
                $goodsCityName = Db::name('area')->where('code', $goodsCityCode)->value('name');
            }
            // 城市匹配校验（仅当券有城市要求时）
            $cityValid = $voucherCityCode ? ($goodsCityCode === $voucherCityCode) : true;
            // $goods 已经过滤了 status='normal'，所以状态一定有效
            $statusValid = true;
            $goodsStatus = 'normal';
            $goodsStatusText = '在售';
        } else {
            // 没有找到在售商品，使用诊断商品信息（如果有）
            $goodsCityCode = $diagnosisGoods ? ($diagnosisGoods['region_city_code'] ?? null) : null;
            $goodsCityName = $diagnosisGoods ? ($diagnosisGoods['region_city_name'] ?? null) : null;
            if (!$goodsCityName && $goodsCityCode) {
                $goodsCityName = Db::name('area')->where('code', $goodsCityCode)->value('name');
            }
            $cityValid = false;
            $statusValid = false;
            $goodsStatus = $diagnosisGoods ? ($diagnosisGoods['status'] ?? null) : null;
            $goodsStatusText = $goodsStatus ? ($statusTextMap[$goodsStatus] ?? $goodsStatus) : '未找到商品';
        }

        // Step 2: 查找该商品的对应规格 SKU
        $sku = null;
        if ($goods) {
            $sku = Db::name('wanlshop_goods_sku')
                ->where('goods_id', $goods['id'])
                ->where('difference', $skuDifference)
                ->where('state', '0')  // state=0 表示使用中
                ->where('status', 'normal')
                ->find();
        }

        // Step 3: 检查价格条件（供货价 <= 券面价 * 80%）
        $skuPrice = $sku ? round((float)$sku['price'], 2) : null;
        $faceValue = round((float)$faceValue, 2);
        $priceThreshold = round($faceValue * 0.8, 2);
        $priceValid = $sku ? ($skuPrice <= $priceThreshold) : false;

        // 整理返回的商家商品信息
        // 优先使用在售商品，其次使用诊断商品（用于前端展示问题所在）
        $displayGoods = $goods ?: $diagnosisGoods;
        if ($displayGoods) {
            $result['shop_goods_info'] = [
                'goods_id' => $displayGoods['id'],
                'goods_title' => $displayGoods['title'],
                'sku_id' => $sku['id'] ?? null,
                'sku_price' => $skuPrice,
                'sku_difference' => $skuDifference,
                'price_threshold' => $priceThreshold,
                'price_valid' => $priceValid,
                'goods_status' => $goods ? 'normal' : ($displayGoods['status'] ?? null),
                'goods_status_text' => $goods ? '在售' : $goodsStatusText,
                'city_code' => $goodsCityCode,
                'city_name' => $goodsCityName,
                'voucher_city_code' => $voucherCityCode,
                'voucher_city_name' => $voucherCityName,
                'city_valid' => $cityValid,
                'status_valid' => $statusValid
            ];
        }

        // 校验结果与错误提示
        if (!$goods) {
            $categoryName = Db::name('wanlshop_category')->where('id', $categoryId)->value('name');
            $cityLabel = $voucherCityName ? "【{$voucherCityName}】" : '';

            // 根据诊断商品给出更精确的错误提示
            if ($diagnosisGoods) {
                // 有商品但不在售
                $diagStatus = $diagnosisGoods['status'] ?? 'unknown';
                $diagStatusText = $statusTextMap[$diagStatus] ?? $diagStatus;
                $result['error_message'] = sprintf(
                    '商品状态异常：您的店铺有【%s】商品，但当前状态为【%s】，请先上架后再核销',
                    $diagnosisGoods['title'],
                    $diagStatusText
                );
            } else {
                // 完全没有该分类商品
                $result['error_message'] = sprintf(
                    '未找到%s分类%s的在售商品，请先上架对应商品后再核销',
                    $categoryName ? "【{$categoryName}】" : '指定',
                    $cityLabel ? "{$cityLabel}地区" : ''
                );
            }
            return $result;
        }

        if ($voucherCityCode && !$cityValid) {
            $result['error_message'] = sprintf(
                '城市校验未通过：券适用【%s】，但商家商品城市为【%s】',
                $voucherCityName ?: $voucherCityCode,
                $goodsCityName ?: '未知城市'
            );
            return $result;
        }

        // 注：$goods 已通过 status='normal' 过滤，此处无需再检查 $statusValid

        if (!$sku) {
            $result['error_message'] = sprintf(
                '规格校验未通过：商品【%s】没有上架【%s】规格，无法核销',
                $goods['title'],
                $skuDifference
            );
            return $result;
        }

        if (!$priceValid) {
            $result['error_message'] = sprintf(
                '价格校验未通过：供货价（¥%.2f）超过券面价80%%（¥%.2f），请调整价格后再试',
                $skuPrice,
                $priceThreshold
            );
            return $result;
        }

        // 全部校验通过
        $result['can_verify'] = true;
        $result['shop_goods_info'] = $result['shop_goods_info'] ?: [];
        $result['shop_goods_info']['price_valid'] = true;

        return $result;
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
            // 轨道3：店铺邀请返利 - 已移除
            // 新逻辑：店铺注册审核通过时直接触发升级，核销不再触发店铺邀请返利

            // 轨道4：被邀请人首次核销触发邀请人返利待审核（新增）
            $this->processInviterRebatePending($voucher->user_id, $verification->id, $voucher->id, $voucher->face_value);

            // 轨道5：BD推广员佣金计算（基于支付金额计算佣金）
            $this->processBdCommission($shop->id, $verification->id, $voucher->id, $voucher->order_id, $voucher->face_value);

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
     * 轨道4：被邀请人首次核销触发邀请人返利待审核
     *
     * 逻辑：核销后立即入队，后台审核时建议等待24h（但不阻止操作）
     *
     * @param int $inviteeUserId 被邀请人用户ID
     * @param int $verificationId 核销记录ID
     * @param int $voucherId 券ID
     * @param float $faceValue 券面值（返利基数）
     */
    protected function processInviterRebatePending($inviteeUserId, $verificationId, $voucherId, $faceValue)
    {
        // 1. 检查被邀请人是否有邀请人
        $invitee = Db::name('user')->where('id', $inviteeUserId)->field('inviter_id')->find();
        if (empty($invitee['inviter_id'])) {
            return; // 没有邀请人，跳过
        }
        $inviterId = (int)$invitee['inviter_id'];

        // 2. 幂等检查：该被邀请人是否已触发过返利入队
        $exists = Db::name('user_invite_pending')->where('invitee_id', $inviteeUserId)->find();
        if ($exists) {
            return; // 已入队，跳过
        }

        // 3. 获取邀请人当前返利比例
        $inviter = Db::name('user')
            ->where('id', $inviterId)
            ->field('id, bonus_ratio, bonus_level')
            ->find();
        if (!$inviter) {
            return; // 邀请人不存在，跳过
        }

        $ratioConfig = $this->getInviteRatios();
        $bonusLevel = (int)$inviter['bonus_level'];
        $bonusRatio = isset($ratioConfig[$bonusLevel]) ? (float)$ratioConfig[$bonusLevel] : (float)$inviter['bonus_ratio'];

        // 4. 计算预计返利金额 = 券面值 × 返利比例
        $rebateAmount = round((float)$faceValue * ($bonusRatio / 100), 2);
        $now = time();

        // 5. 写入待审核队列
        Db::name('user_invite_pending')->insert([
            'inviter_id' => $inviterId,
            'invitee_id' => $inviteeUserId,
            'verification_id' => $verificationId,
            'voucher_id' => $voucherId,
            'face_value' => (float)$faceValue,
            'bonus_ratio' => $bonusRatio,
            'rebate_amount' => $rebateAmount,
            'verify_time' => $now,
            'state' => 0, // 待审核
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

    /**
     * 轨道5：BD推广员佣金计算
     *
     * 核销时检查店铺是否有BD绑定，计算并记录BD佣金
     *
     * @param int $shopId 店铺ID
     * @param int $verificationId 核销记录ID
     * @param int $voucherId 券ID
     * @param int $orderId 订单ID
     * @param float $payPrice 支付金额（券面价）
     */
    protected function processBdCommission($shopId, $verificationId, $voucherId, $orderId, $payPrice)
    {
        try {
            $bdService = new BdPromoterService();
            $bdService->calculateCommission($shopId, $verificationId, $voucherId, $orderId, $payPrice);
        } catch (Exception $e) {
            // 记录日志但不影响核销流程
            \think\Log::error('BD佣金计算失败: ' . $e->getMessage());
        }
    }
}
