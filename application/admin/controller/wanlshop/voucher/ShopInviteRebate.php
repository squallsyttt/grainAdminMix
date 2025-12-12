<?php

namespace app\admin\controller\wanlshop\voucher;

use app\admin\service\RebateTransferService;
use app\common\controller\Backend;
use think\Db;
use think\Exception;

/**
 * 店铺邀请返利管理（两阶段审核）
 *
 * 流程：核销 → shop_invite_pending → 后台发放 → voucher_rebate → 打款
 *
 * @icon fa fa-user-plus
 */
class ShopInviteRebate extends Backend
{
    protected $model = null;

    protected $noNeedRight = [];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\wanlshop\VoucherRebate;
        $this->view->assign("paymentStatusList", $this->model->getPaymentStatusList());
    }

    /**
     * 阶段一：待审核队列列表
     *
     * 展示 shop_invite_pending 表中 state=0 的记录
     */
    public function pending()
    {
        $this->request->filter(['strip_tags', 'trim']);

        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            $now = time();
            $twentyFourHoursAgo = $now - 86400;

            $list = Db::name('shop_invite_pending')
                ->alias('p')
                ->join('__WANLSHOP_SHOP__ s', 's.id = p.shop_id', 'LEFT')
                ->join('__USER__ u', 'u.id = p.inviter_id', 'LEFT')
                ->join('__USER__ cu', 'cu.id = p.user_id', 'LEFT')
                ->join('__WANLSHOP_VOUCHER__ v', 'v.id = p.voucher_id', 'LEFT')
                ->where('p.state', 0)
                ->where($where)
                ->field('p.*,
                        s.shopname, s.avatar as shop_avatar,
                        u.nickname as inviter_name, u.mobile as inviter_mobile, u.bonus_level as inviter_level,
                        cu.nickname as consumer_name,
                        v.state as voucher_state')
                ->order($sort ?: 'p.createtime', $order ?: 'desc')
                ->limit($offset, $limit)
                ->select();

            $total = Db::name('shop_invite_pending')
                ->alias('p')
                ->where('p.state', 0)
                ->where($where)
                ->count();

            // 计算每条记录的状态
            foreach ($list as &$row) {
                $row['hours_passed'] = round(($now - $row['verify_time']) / 3600, 1);
                $row['is_24h_passed'] = $row['verify_time'] <= $twentyFourHoursAgo;
                $row['is_refunded'] = ($row['voucher_state'] == 3);
                $row['can_grant'] = $row['is_24h_passed'] && !$row['is_refunded'];

                // 预估返利金额（仅供参考，实际以发放时计算为准）
                $row['estimated_ratio'] = $this->getInviteRatio((int)$row['inviter_level']);
                $row['estimated_amount'] = round($row['supply_price'] * $row['estimated_ratio'] / 100, 2);
            }

            return json(["total" => $total, "rows" => $list]);
        }

        return $this->view->fetch();
    }

    /**
     * 发放返利操作
     *
     * 检查24h + 无退款 → 处理升级 → 计算返利 → 写入 voucher_rebate
     */
    public function grantRebate($ids = null)
    {
        $pending = Db::name('shop_invite_pending')
            ->where('id', $ids)
            ->where('state', 0)
            ->find();

        if (!$pending) {
            $this->error('记录不存在或已处理');
        }

        $now = time();
        $twentyFourHoursAgo = $now - 86400;

        // 检查是否满24小时
        if ($pending['verify_time'] > $twentyFourHoursAgo) {
            $hoursLeft = round(($pending['verify_time'] + 86400 - $now) / 3600, 1);
            $this->error("距离24小时还差 {$hoursLeft} 小时，暂不可发放");
        }

        // 检查券是否已退款
        $voucher = Db::name('wanlshop_voucher')
            ->where('id', $pending['voucher_id'])
            ->field('state')
            ->find();
        if ($voucher && $voucher['state'] == 3) {
            Db::name('shop_invite_pending')
                ->where('id', $ids)
                ->update(['state' => 2, 'updatetime' => $now]);
            $this->error('该券已退款，记录已自动取消');
        }

        // POST 请求执行发放
        if ($this->request->isPost()) {
            Db::startTrans();
            try {
                $result = $this->processGrantRebate($pending);
                Db::commit();
                $this->success('返利发放成功', null, $result);
            } catch (Exception $e) {
                Db::rollback();
                $this->error('发放失败：' . $e->getMessage());
            }
        }

        // GET 请求显示确认页面
        $shop = Db::name('wanlshop_shop')->where('id', $pending['shop_id'])->find();
        $inviter = Db::name('user')->where('id', $pending['inviter_id'])->find();

        // 预计算返利信息
        $currentLevel = (int)$inviter['bonus_level'];
        $willUpgrade = $currentLevel < 2 && !$this->hasShopUpgradeLog($pending['inviter_id'], $pending['shop_id']);
        $afterLevel = $willUpgrade ? $currentLevel + 1 : $currentLevel;
        $afterRatio = $this->getInviteRatio($afterLevel);
        $estimatedAmount = round($pending['supply_price'] * $afterRatio / 100, 2);

        $this->view->assign('pending', $pending);
        $this->view->assign('shop', $shop);
        $this->view->assign('inviter', $inviter);
        $this->view->assign('willUpgrade', $willUpgrade);
        $this->view->assign('currentLevel', $currentLevel);
        $this->view->assign('afterLevel', $afterLevel);
        $this->view->assign('afterRatio', $afterRatio);
        $this->view->assign('estimatedAmount', $estimatedAmount);

        return $this->view->fetch();
    }

    /**
     * 执行发放返利逻辑
     */
    protected function processGrantRebate($pending)
    {
        $now = time();
        $inviterId = $pending['inviter_id'];
        $shopId = $pending['shop_id'];

        // 1. 幂等检查：该店铺是否已产生过返利
        $existsRebate = Db::name('shop_invite_rebate_log')
            ->where('shop_id', $shopId)
            ->find();
        if ($existsRebate) {
            Db::name('shop_invite_pending')
                ->where('id', $pending['id'])
                ->update(['state' => 1, 'process_time' => $now, 'updatetime' => $now]);
            return ['message' => '该店铺已发放过返利，跳过处理'];
        }

        // 2. 获取邀请人信息并加锁
        $inviter = Db::name('user')
            ->where('id', $inviterId)
            ->lock(true)
            ->field('id, bonus_level, bonus_ratio')
            ->find();
        if (!$inviter) {
            throw new Exception('邀请人不存在');
        }

        // 3. 计算是否升级
        $currentLevel = (int)$inviter['bonus_level'];
        $isUpgrade = false;
        $afterLevel = $currentLevel;

        $existsUpgrade = $this->hasShopUpgradeLog($inviterId, $shopId);

        if (!$existsUpgrade && $currentLevel < 2) {
            $afterLevel = $currentLevel + 1;
            $isUpgrade = true;
        }

        // 4. 获取返利比例（升级后的比例）
        $beforeRatio = $this->getInviteRatio($currentLevel);
        $afterRatio = $this->getInviteRatio($afterLevel);

        // 5. 如果升级，更新用户等级
        if ($isUpgrade) {
            Db::name('user')->where('id', $inviterId)->update([
                'bonus_level' => $afterLevel,
                'bonus_ratio' => $afterRatio
            ]);

            Db::name('shop_invite_upgrade_log')->insert([
                'user_id' => $inviterId,
                'shop_id' => $shopId,
                'verification_id' => $pending['verification_id'],
                'voucher_id' => $pending['voucher_id'],
                'before_level' => $currentLevel,
                'after_level' => $afterLevel,
                'before_ratio' => $beforeRatio,
                'after_ratio' => $afterRatio,
                'createtime' => $now
            ]);
        }

        // 6. 获取店铺和券信息
        $shop = Db::name('wanlshop_shop')
            ->where('id', $shopId)
            ->field('shopname')
            ->find();

        $voucher = Db::name('wanlshop_voucher')
            ->where('id', $pending['voucher_id'])
            ->find();

        // 7. 计算返利金额（按券面值和升级后的比例）
        $faceValue = (float)$voucher['face_value'];
        $rebateAmount = round($faceValue * ($afterRatio / 100), 2);

        // 8. 记录返利日志
        Db::name('shop_invite_rebate_log')->insert([
            'inviter_id' => $inviterId,
            'shop_id' => $shopId,
            'shop_name' => $shop['shopname'] ?? '',
            'verification_id' => $pending['verification_id'],
            'voucher_id' => $pending['voucher_id'],
            'user_id' => $pending['user_id'],
            'supply_price' => $pending['supply_price'],
            'face_value' => $faceValue,
            'rebate_amount' => $rebateAmount,
            'bonus_ratio' => $afterRatio,
            'before_level' => $currentLevel,
            'after_level' => $afterLevel,
            'is_upgrade' => $isUpgrade ? 1 : 0,
            'pending_id' => $pending['id'],
            'createtime' => $now
        ]);

        // 9. 写入统一返利表（用于后续打款）
        Db::name('wanlshop_voucher_rebate')->insert([
            'user_id' => $inviterId,
            'voucher_id' => $pending['voucher_id'],
            'voucher_no' => $voucher['voucher_no'] ?? '',
            'verification_id' => $pending['verification_id'],
            'order_id' => $voucher['order_id'] ?? 0,
            'shop_id' => $pending['shop_id'],
            'shop_name' => $shop['shopname'] ?? '',
            'rebate_type' => 'shop_invite',
            'invite_shop_id' => $shopId,
            'invite_shop_name' => $shop['shopname'] ?? '',
            'supply_price' => $supplyPrice,
            'face_value' => $voucher['face_value'] ?? 0,
            'rebate_amount' => $rebateAmount,
            'bonus_ratio' => $afterRatio,
            'refund_amount' => 0,
            'unit_price' => 0,
            'user_bonus_ratio' => 0,
            'actual_bonus_ratio' => $afterRatio,
            'stage' => 'free',
            'days_from_payment' => 0,
            'goods_title' => $voucher['goods_title'] ?? '',
            'shop_goods_id' => $voucher['goods_id'] ?? 0,
            'shop_goods_title' => $voucher['goods_title'] ?? '',
            'sku_weight' => $voucher['sku_weight'] ?? 0,
            'original_goods_weight' => $voucher['sku_weight'] ?? 0,
            'actual_goods_weight' => $voucher['sku_weight'] ?? 0,
            'rule_id' => $voucher['rule_id'] ?? 0,
            'free_days' => 0,
            'welfare_days' => 0,
            'goods_days' => 0,
            'payment_time' => $voucher['createtime'] ?? $now,
            'verify_time' => $pending['verify_time'],
            'payment_status' => 'unpaid',
            'createtime' => $now,
            'updatetime' => $now
        ]);

        // 10. 更新待审核记录状态
        Db::name('shop_invite_pending')
            ->where('id', $pending['id'])
            ->update([
                'state' => 1,
                'process_time' => $now,
                'admin_id' => $this->auth->id,
                'updatetime' => $now
            ]);

        return [
            'rebate_amount' => $rebateAmount,
            'is_upgrade' => $isUpgrade,
            'after_level' => $afterLevel,
            'after_ratio' => $afterRatio
        ];
    }

    /**
     * 阶段二：已发放返利列表（打款管理）
     */
    public function index()
    {
        $this->relationSearch = true;
        $this->request->filter(['strip_tags', 'trim']);

        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }

            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            $total = $this->model
                ->with(['user', 'inviteShop'])
                ->where('rebate_type', 'shop_invite')
                ->where($where)
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->with(['user', 'inviteShop'])
                ->where('rebate_type', 'shop_invite')
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            foreach ($list as $row) {
                $row->getRelation('user')->visible(['username', 'nickname', 'mobile']);
                if ($row->getRelation('inviteShop')) {
                    $row->getRelation('inviteShop')->visible(['shopname', 'mobile']);
                }
                $row->can_transfer = $row->canTransfer();
            }

            return json(["total" => $total, "rows" => collection($list)->toArray()]);
        }

        return $this->view->fetch();
    }

    /**
     * 打款操作（复用现有服务）
     */
    public function transfer($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        if ($row->rebate_type !== 'shop_invite') {
            $this->error('该记录不是店铺邀请返利类型');
        }

        if (!$row->canTransfer()) {
            if ($row->payment_status === 'pending') {
                $this->error('当前状态为打款中，请等待用户确认');
            }
            if ($row->payment_status === 'paid') {
                $this->error('已完成打款，请勿重复操作');
            }
            $this->error('当前状态不可打款');
        }

        $service = new RebateTransferService();

        if ($this->request->isPost()) {
            try {
                $result = $service->transfer((int)$ids);
                if ($result['success']) {
                    $this->success('打款发起成功', null, $result['data'] ?? []);
                }
                $this->error('打款失败：' . ($result['message'] ?? '未知错误'));
            } catch (\Exception $e) {
                $this->error('打款失败：' . $e->getMessage());
            }
        }

        $receiver = $service->getReceiver((int)$row->user_id);
        $this->view->assign('row', $row);
        $this->view->assign('receiver', $receiver);
        $this->view->assign('rebateAmount', $row->rebate_amount);

        return $this->view->fetch();
    }

    /**
     * 取消待审核记录
     */
    public function cancel($ids = null)
    {
        $pending = Db::name('shop_invite_pending')
            ->where('id', $ids)
            ->where('state', 0)
            ->find();

        if (!$pending) {
            $this->error('记录不存在或已处理');
        }

        $result = Db::name('shop_invite_pending')
            ->where('id', $ids)
            ->update([
                'state' => 2,
                'process_time' => time(),
                'admin_id' => $this->auth->id,
                'updatetime' => time()
            ]);

        if ($result) {
            $this->success('已取消');
        } else {
            $this->error('操作失败');
        }
    }

    /**
     * 检查是否已有店铺升级记录
     */
    protected function hasShopUpgradeLog($userId, $shopId)
    {
        return Db::name('shop_invite_upgrade_log')
            ->where('user_id', $userId)
            ->where('shop_id', $shopId)
            ->find();
    }

    /**
     * 获取指定等级的返利比例
     */
    protected function getInviteRatio($level)
    {
        $ratios = [
            0 => (float)config('site.invite_base_ratio') ?: 1.0,
            1 => (float)config('site.invite_level1_ratio') ?: 1.5,
            2 => (float)config('site.invite_level2_ratio') ?: 2.0,
        ];
        return $ratios[$level] ?? $ratios[0];
    }
}
