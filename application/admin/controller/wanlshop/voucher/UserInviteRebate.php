<?php

namespace app\admin\controller\wanlshop\voucher;

use app\admin\service\RebateTransferService;
use app\common\controller\Backend;
use think\Db;
use think\Exception;

/**
 * 用户邀请返利管理（两阶段审核）
 *
 * 流程：被邀请人首次核销 → user_invite_pending → 后台发放 → voucher_rebate → 打款
 * 注意：24h提示仅供参考，不阻止管理员操作
 *
 * @icon fa fa-user-plus
 */
class UserInviteRebate extends Backend
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
     * 展示 user_invite_pending 表中 state=0 的记录
     */
    public function pending()
    {
        $this->request->filter(['strip_tags', 'trim']);

        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            $now = time();
            $twentyFourHoursAgo = $now - 86400;

            $list = Db::name('user_invite_pending')
                ->alias('p')
                ->join('__USER__ inviter', 'inviter.id = p.inviter_id', 'LEFT')
                ->join('__USER__ invitee', 'invitee.id = p.invitee_id', 'LEFT')
                ->join('__WANLSHOP_VOUCHER__ v', 'v.id = p.voucher_id', 'LEFT')
                ->where('p.state', 0)
                ->where($where)
                ->field('p.*,
                        inviter.nickname as inviter_name, inviter.mobile as inviter_mobile, inviter.bonus_level as inviter_level,
                        invitee.nickname as invitee_name, invitee.mobile as invitee_mobile,
                        v.state as voucher_state, v.voucher_no, v.goods_title')
                ->order($sort ?: 'p.createtime', $order ?: 'desc')
                ->limit($offset, $limit)
                ->select();

            $total = Db::name('user_invite_pending')
                ->alias('p')
                ->where('p.state', 0)
                ->where($where)
                ->count();

            // 计算每条记录的状态提示
            foreach ($list as &$row) {
                $hoursPassed = round(($now - $row['verify_time']) / 3600, 1);
                $row['hours_passed'] = $hoursPassed;
                $row['is_24h_passed'] = $row['verify_time'] <= $twentyFourHoursAgo;
                $row['is_refunded'] = ($row['voucher_state'] == 3);

                // 24h仅作为提示，不阻止操作
                if ($row['is_refunded']) {
                    $row['time_hint'] = '⚠️ 该券已退款';
                    $row['can_grant'] = false;
                } elseif ($row['is_24h_passed']) {
                    $row['time_hint'] = '✅ 已过24h，可放心审核';
                    $row['can_grant'] = true;
                } else {
                    $hoursLeft = round(24 - $hoursPassed, 1);
                    $row['time_hint'] = "⏳ 距24h还有{$hoursLeft}小时（可操作）";
                    $row['can_grant'] = true; // 不阻止操作
                }
            }

            return json(["total" => $total, "rows" => $list]);
        }

        return $this->view->fetch();
    }

    /**
     * 发放返利操作
     *
     * 检查退款状态 → 计算返利 → 写入 voucher_rebate
     * 注意：24h限制已移除，仅作为提示
     */
    public function grantRebate($ids = null)
    {
        $pending = Db::name('user_invite_pending')
            ->where('id', $ids)
            ->where('state', 0)
            ->find();

        if (!$pending) {
            $this->error('记录不存在或已处理');
        }

        $now = time();

        // 检查券是否已退款（这是硬性条件）
        $voucher = Db::name('wanlshop_voucher')
            ->where('id', $pending['voucher_id'])
            ->find();
        if ($voucher && $voucher['state'] == 3) {
            Db::name('user_invite_pending')
                ->where('id', $ids)
                ->update(['state' => 2, 'updatetime' => $now]);
            $this->error('该券已退款，记录已自动取消');
        }

        // POST 请求执行发放
        if ($this->request->isPost()) {
            Db::startTrans();
            try {
                $result = $this->processGrantRebate($pending, $voucher);
                Db::commit();
                $this->success('返利发放成功', null, $result);
            } catch (Exception $e) {
                Db::rollback();
                $this->error('发放失败：' . $e->getMessage());
            }
        }

        // GET 请求显示确认页面
        $inviter = Db::name('user')->where('id', $pending['inviter_id'])->find();
        $invitee = Db::name('user')->where('id', $pending['invitee_id'])->find();

        // 24h状态提示
        $hoursPassed = round(($now - $pending['verify_time']) / 3600, 1);
        $is24hPassed = $pending['verify_time'] <= ($now - 86400);

        $this->view->assign('pending', $pending);
        $this->view->assign('voucher', $voucher);
        $this->view->assign('inviter', $inviter);
        $this->view->assign('invitee', $invitee);
        $this->view->assign('hoursPassed', $hoursPassed);
        $this->view->assign('is24hPassed', $is24hPassed);

        return $this->view->fetch();
    }

    /**
     * 执行发放返利逻辑
     */
    protected function processGrantRebate($pending, $voucher)
    {
        $now = time();
        $inviterId = $pending['inviter_id'];
        $inviteeId = $pending['invitee_id'];

        // 1. 幂等检查：该被邀请人是否已产生过返利
        $existsRebate = Db::name('user_invite_rebate_log')
            ->where('invitee_id', $inviteeId)
            ->find();
        if ($existsRebate) {
            Db::name('user_invite_pending')
                ->where('id', $pending['id'])
                ->update(['state' => 1, 'process_time' => $now, 'updatetime' => $now]);
            return ['message' => '该被邀请人已发放过返利，跳过处理'];
        }

        // 2. 获取被邀请人昵称
        $invitee = Db::name('user')
            ->where('id', $inviteeId)
            ->field('nickname')
            ->find();

        // 3. 返利金额直接使用入队时计算的值（已锁定当时的比例）
        $rebateAmount = (float)$pending['rebate_amount'];
        $bonusRatio = (float)$pending['bonus_ratio'];
        $faceValue = (float)$pending['face_value'];

        // 4. 记录返利日志
        Db::name('user_invite_rebate_log')->insert([
            'inviter_id' => $inviterId,
            'invitee_id' => $inviteeId,
            'invitee_nickname' => $invitee['nickname'] ?? '',
            'verification_id' => $pending['verification_id'],
            'voucher_id' => $pending['voucher_id'],
            'voucher_no' => $voucher['voucher_no'] ?? '',
            'face_value' => $faceValue,
            'rebate_amount' => $rebateAmount,
            'bonus_ratio' => $bonusRatio,
            'pending_id' => $pending['id'],
            'createtime' => $now
        ]);

        // 5. 获取核销记录中的店铺信息
        $verification = Db::name('wanlshop_voucher_verification')
            ->where('id', $pending['verification_id'])
            ->field('shop_id')
            ->find();
        $shopId = $verification['shop_id'] ?? 0;
        $shop = $shopId ? Db::name('wanlshop_shop')->where('id', $shopId)->field('shopname')->find() : null;

        // 6. 写入统一返利表（用于后续打款）
        Db::name('wanlshop_voucher_rebate')->insert([
            'user_id' => $inviterId,
            'voucher_id' => $pending['voucher_id'],
            'voucher_no' => $voucher['voucher_no'] ?? '',
            'verification_id' => $pending['verification_id'],
            'order_id' => $voucher['order_id'] ?? 0,
            'shop_id' => $shopId,
            'shop_name' => $shop['shopname'] ?? '',
            'rebate_type' => 'user_invite',
            'invite_shop_id' => 0,
            'invite_shop_name' => '',
            'invitee_id' => $inviteeId,
            'invitee_nickname' => $invitee['nickname'] ?? '',
            'supply_price' => $voucher['supply_price'] ?? 0,
            'face_value' => $faceValue,
            'rebate_amount' => $rebateAmount,
            'bonus_ratio' => $bonusRatio,
            'refund_amount' => 0,
            'unit_price' => 0,
            'user_bonus_ratio' => 0,
            'actual_bonus_ratio' => $bonusRatio,
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

        // 7. 更新待审核记录状态
        Db::name('user_invite_pending')
            ->where('id', $pending['id'])
            ->update([
                'state' => 1,
                'process_time' => $now,
                'admin_id' => $this->auth->id,
                'updatetime' => $now
            ]);

        return [
            'rebate_amount' => $rebateAmount,
            'bonus_ratio' => $bonusRatio,
            'invitee_nickname' => $invitee['nickname'] ?? ''
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
                ->with(['user'])
                ->where('rebate_type', 'user_invite')
                ->where($where)
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->with(['user'])
                ->where('rebate_type', 'user_invite')
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            foreach ($list as $row) {
                $row->getRelation('user')->visible(['username', 'nickname', 'mobile']);
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

        if ($row->rebate_type !== 'user_invite') {
            $this->error('该记录不是用户邀请返利类型');
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
        $pending = Db::name('user_invite_pending')
            ->where('id', $ids)
            ->where('state', 0)
            ->find();

        if (!$pending) {
            $this->error('记录不存在或已处理');
        }

        $result = Db::name('user_invite_pending')
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
}
