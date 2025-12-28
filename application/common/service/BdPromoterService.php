<?php
/**
 * BD推广员服务类
 *
 * 核心功能：
 * 1. BD推广码生成与申请
 * 2. 周期管理与佣金比例计算
 * 3. 店铺绑定处理
 * 4. 核销佣金计算
 * 5. 退款佣金扣减
 */

namespace app\common\service;

use think\Db;
use think\Exception;

class BdPromoterService
{
    /**
     * 周期天数（90天）
     */
    const PERIOD_DAYS = 90;

    /**
     * 佣金比例上限（千分之三）
     */
    const MAX_RATE = 0.003;

    /**
     * 每邀请一家店铺增加的比例
     */
    const RATE_PER_SHOP = 0.001;

    /**
     * 生成BD推广码
     * 格式：BD + 8位随机数字 + 4位随机大写字母
     *
     * @return string
     */
    public static function generateBdCode(): string
    {
        do {
            $numbers = str_pad(mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
            $letters = '';
            for ($i = 0; $i < 4; $i++) {
                $letters .= chr(mt_rand(65, 90)); // A-Z
            }
            $code = 'BD' . $numbers . $letters;

            // 检查唯一性
            $exists = Db::name('user')->where('bd_code', $code)->find();
        } while ($exists);

        return $code;
    }

    /**
     * 用户申请成为BD推广员
     *
     * @param int $userId 用户ID
     * @return array ['bd_code' => string, 'period_id' => int]
     * @throws Exception
     */
    public function applyBdPromoter(int $userId): array
    {
        // 检查用户是否存在
        $user = Db::name('user')->where('id', $userId)->field('id, bd_code, bd_apply_time')->find();
        if (!$user) {
            throw new Exception('用户不存在');
        }

        // 如果已是BD推广员，直接返回现有信息
        if (!empty($user['bd_code'])) {
            $period = $this->getCurrentPeriod($userId);
            return [
                'bd_code' => $user['bd_code'],
                'period_id' => $period ? $period['id'] : null
            ];
        }

        Db::startTrans();
        try {
            $now = time();

            // 生成BD码
            $bdCode = self::generateBdCode();

            // 更新用户信息
            Db::name('user')->where('id', $userId)->update([
                'bd_code' => $bdCode,
                'bd_apply_time' => $now
            ]);

            // 创建首个周期
            $periodEnd = $now + self::PERIOD_DAYS * 86400;
            $periodId = Db::name('bd_promoter_period')->insertGetId([
                'user_id' => $userId,
                'period_index' => 1,
                'period_start' => $now,
                'period_end' => $periodEnd,
                'shop_count' => 0,
                'prev_rate' => 0.000,
                'current_rate' => 0.000, // 初始未激活
                'prev_zero_invite' => 0,
                'total_commission' => 0.00,
                'status' => 'active',
                'createtime' => $now,
                'updatetime' => $now
            ]);

            Db::commit();

            return [
                'bd_code' => $bdCode,
                'period_id' => $periodId
            ];

        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 获取BD当前周期
     *
     * @param int $bdUserId BD推广员用户ID
     * @return array|null
     */
    public function getCurrentPeriod(int $bdUserId): ?array
    {
        return Db::name('bd_promoter_period')
            ->where('user_id', $bdUserId)
            ->where('status', 'active')
            ->order('period_index', 'desc')
            ->find();
    }

    /**
     * 获取BD当前佣金比例
     *
     * @param int $bdUserId BD推广员用户ID
     * @return float
     */
    public function getCurrentRate(int $bdUserId): float
    {
        $period = $this->getCurrentPeriod($bdUserId);
        if (!$period) {
            return 0.000;
        }
        return (float)$period['current_rate'];
    }

    /**
     * 店铺绑定BD时更新周期数据
     *
     * @param int $bdUserId BD推广员用户ID
     * @param int $shopId 店铺ID
     * @param int $shopUserId 店铺所属用户ID
     * @throws Exception
     */
    public function onShopBind(int $bdUserId, int $shopId, int $shopUserId): void
    {
        // 检查是否已绑定
        $exists = Db::name('bd_shop_bindlog')
            ->where('bd_user_id', $bdUserId)
            ->where('shop_id', $shopId)
            ->find();
        if ($exists) {
            return; // 已绑定过，跳过
        }

        Db::startTrans();
        try {
            $now = time();

            // 获取或创建当前周期
            $period = $this->getCurrentPeriod($bdUserId);
            if (!$period) {
                // 用户是BD但没有活跃周期，可能需要创建新周期
                $period = $this->createNewPeriod($bdUserId);
            }

            // 检查周期是否已过期，如需要则切换周期
            if ($now > $period['period_end']) {
                $period = $this->processPeriodTransitionForUser($bdUserId);
            }

            // 更新周期店铺数
            $newShopCount = (int)$period['shop_count'] + 1;
            $newRate = $this->calculateNewRate(
                (float)$period['prev_rate'],
                $newShopCount,
                (int)$period['period_index']
            );

            Db::name('bd_promoter_period')
                ->where('id', $period['id'])
                ->update([
                    'shop_count' => $newShopCount,
                    'current_rate' => $newRate,
                    'updatetime' => $now
                ]);

            // 记录绑定日志
            Db::name('bd_shop_bindlog')->insert([
                'bd_user_id' => $bdUserId,
                'shop_id' => $shopId,
                'shop_user_id' => $shopUserId,
                'period_id' => $period['id'],
                'createtime' => $now
            ]);

            // 更新店铺的BD绑定信息
            Db::name('wanlshop_shop')
                ->where('id', $shopId)
                ->update([
                    'bder_id' => $bdUserId,
                    'bder_bind_time' => $now
                ]);

            Db::commit();

        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 计算新的佣金比例
     *
     * @param float $prevRate 上周期结束时的比例
     * @param int $shopCount 本周期邀请店铺数
     * @param int $periodIndex 周期索引（1表示首个周期）
     * @return float
     */
    protected function calculateNewRate(float $prevRate, int $shopCount, int $periodIndex): float
    {
        // 情况1：首个周期
        if ($periodIndex === 1) {
            return min(self::MAX_RATE, $shopCount * self::RATE_PER_SHOP);
        }

        // 情况2：非首个周期，有邀请
        if ($shopCount >= 1) {
            if ($shopCount == 1) {
                // 1邀请：如果之前是0则激活到千分之一，否则维持
                return $prevRate == 0 ? self::RATE_PER_SHOP : $prevRate;
            } else {
                // >=2邀请：升级
                if ($prevRate == 0) {
                    // 从0激活
                    return min(self::MAX_RATE, $shopCount * self::RATE_PER_SHOP);
                } else {
                    // 升级：prev_rate + (shopCount-1) * 0.001
                    return min(self::MAX_RATE, $prevRate + ($shopCount - 1) * self::RATE_PER_SHOP);
                }
            }
        }

        // 情况3：非首个周期，0邀请（周期刚开始时）
        // 此时保持 prev_rate 不变，周期结束时再判断是否降级
        return $prevRate;
    }

    /**
     * 核销时计算BD佣金
     *
     * @param int $shopId 店铺ID
     * @param int $verificationId 核销记录ID
     * @param int $voucherId 券ID
     * @param int $orderId 订单ID
     * @param float $payPrice 支付金额（券面价）
     * @throws Exception
     */
    public function calculateCommission(int $shopId, int $verificationId, int $voucherId, int $orderId, float $payPrice): void
    {
        // 检查店铺是否有BD绑定
        $shop = Db::name('wanlshop_shop')
            ->where('id', $shopId)
            ->field('id, bder_id')
            ->find();

        if (!$shop || empty($shop['bder_id'])) {
            return; // 店铺没有BD绑定，跳过
        }

        $bdUserId = (int)$shop['bder_id'];

        // 获取BD当前周期和佣金比例
        $period = $this->getCurrentPeriod($bdUserId);
        if (!$period) {
            return; // 没有活跃周期，跳过
        }

        $commissionRate = (float)$period['current_rate'];
        if ($commissionRate <= 0) {
            return; // 佣金比例为0，跳过
        }

        // 计算佣金金额（基于支付金额）
        $commissionAmount = round($payPrice * $commissionRate, 2);
        if ($commissionAmount <= 0) {
            return;
        }

        $now = time();

        // 写入佣金明细
        Db::name('bd_commission_log')->insert([
            'bd_user_id' => $bdUserId,
            'shop_id' => $shopId,
            'order_id' => $orderId,
            'voucher_id' => $voucherId,
            'verification_id' => $verificationId,
            'refund_id' => null,
            'type' => 'earn',
            'order_amount' => $payPrice,
            'commission_rate' => $commissionRate,
            'commission_amount' => $commissionAmount,
            'period_id' => $period['id'],
            'settle_status' => 'pending',
            'remark' => null,
            'createtime' => $now
        ]);

        // 更新周期累计佣金
        Db::name('bd_promoter_period')
            ->where('id', $period['id'])
            ->setInc('total_commission', $commissionAmount);
    }

    /**
     * 退款时扣减BD佣金
     *
     * @param int $verificationId 核销记录ID
     * @param int $refundId 退款记录ID
     * @throws Exception
     */
    public function deductCommission(int $verificationId, int $refundId): void
    {
        // 查找对应的佣金记录
        $earnLog = Db::name('bd_commission_log')
            ->where('verification_id', $verificationId)
            ->where('type', 'earn')
            ->where('settle_status', 'pending')
            ->find();

        if (!$earnLog) {
            return; // 没有对应的佣金记录，跳过
        }

        $now = time();

        // 写入扣减记录
        Db::name('bd_commission_log')->insert([
            'bd_user_id' => $earnLog['bd_user_id'],
            'shop_id' => $earnLog['shop_id'],
            'order_id' => $earnLog['order_id'],
            'voucher_id' => $earnLog['voucher_id'],
            'verification_id' => $verificationId,
            'refund_id' => $refundId,
            'type' => 'deduct',
            'order_amount' => $earnLog['order_amount'],
            'commission_rate' => $earnLog['commission_rate'],
            'commission_amount' => $earnLog['commission_amount'],
            'period_id' => $earnLog['period_id'],
            'settle_status' => 'pending',
            'remark' => '退款扣减',
            'createtime' => $now
        ]);

        // 更新原记录状态为已取消
        Db::name('bd_commission_log')
            ->where('id', $earnLog['id'])
            ->update(['settle_status' => 'cancelled']);

        // 更新周期累计佣金（扣减）
        if ($earnLog['period_id']) {
            Db::name('bd_promoter_period')
                ->where('id', $earnLog['period_id'])
                ->setDec('total_commission', $earnLog['commission_amount']);
        }
    }

    /**
     * 创建新周期
     *
     * @param int $bdUserId BD推广员用户ID
     * @return array 新周期记录
     * @throws Exception
     */
    protected function createNewPeriod(int $bdUserId): array
    {
        // 获取用户的BD申请时间
        $user = Db::name('user')
            ->where('id', $bdUserId)
            ->field('bd_apply_time')
            ->find();

        if (!$user || !$user['bd_apply_time']) {
            throw new Exception('用户不是BD推广员');
        }

        // 获取最新的周期
        $lastPeriod = Db::name('bd_promoter_period')
            ->where('user_id', $bdUserId)
            ->order('period_index', 'desc')
            ->find();

        $now = time();
        $periodIndex = $lastPeriod ? (int)$lastPeriod['period_index'] + 1 : 1;

        // 计算周期开始时间（基于BD申请时间）
        $applyTime = (int)$user['bd_apply_time'];
        $periodStart = $applyTime + ($periodIndex - 1) * self::PERIOD_DAYS * 86400;
        $periodEnd = $periodStart + self::PERIOD_DAYS * 86400;

        // 计算 prev_rate 和 prev_zero_invite
        $prevRate = 0.000;
        $prevZeroInvite = 0;
        if ($lastPeriod) {
            $prevRate = (float)$lastPeriod['current_rate'];
            $prevZeroInvite = ((int)$lastPeriod['shop_count'] === 0) ? 1 : 0;
        }

        // 判断是否需要降级（连续两个周期0邀请）
        $currentRate = $prevRate;
        if ($lastPeriod && (int)$lastPeriod['shop_count'] === 0 && (int)$lastPeriod['prev_zero_invite'] === 1) {
            // 连续两个周期0邀请，降级到0%
            $currentRate = 0.000;
        }

        $periodId = Db::name('bd_promoter_period')->insertGetId([
            'user_id' => $bdUserId,
            'period_index' => $periodIndex,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'shop_count' => 0,
            'prev_rate' => $prevRate,
            'current_rate' => $currentRate,
            'prev_zero_invite' => $prevZeroInvite,
            'total_commission' => 0.00,
            'status' => 'active',
            'createtime' => $now,
            'updatetime' => $now
        ]);

        // 关闭上一个周期
        if ($lastPeriod) {
            Db::name('bd_promoter_period')
                ->where('id', $lastPeriod['id'])
                ->update(['status' => 'closed', 'updatetime' => $now]);
        }

        return Db::name('bd_promoter_period')->where('id', $periodId)->find();
    }

    /**
     * 单个用户的周期切换处理
     *
     * @param int $bdUserId BD推广员用户ID
     * @return array 新周期记录
     * @throws Exception
     */
    public function processPeriodTransitionForUser(int $bdUserId): array
    {
        return $this->createNewPeriod($bdUserId);
    }

    /**
     * 周期切换处理（定时任务调用）
     * 检查所有BD推广员的周期状态，自动切换到新周期
     */
    public function processPeriodTransition(): void
    {
        $now = time();

        // 查找所有已过期的活跃周期
        $expiredPeriods = Db::name('bd_promoter_period')
            ->where('status', 'active')
            ->where('period_end', '<', $now)
            ->select();

        foreach ($expiredPeriods as $period) {
            try {
                $this->createNewPeriod($period['user_id']);
            } catch (Exception $e) {
                // 记录错误日志，继续处理下一个
                \think\Log::error('BD周期切换失败: user_id=' . $period['user_id'] . ', error=' . $e->getMessage());
            }
        }
    }

    /**
     * 获取BD推广员信息
     *
     * @param int $userId 用户ID
     * @return array
     */
    public function getBdPromoterInfo(int $userId): array
    {
        $user = Db::name('user')
            ->where('id', $userId)
            ->field('id, bd_code, bd_apply_time')
            ->find();

        if (!$user) {
            return [
                'is_bd_promoter' => false,
                'bd_code' => null,
                'apply_time' => null,
                'current_period_index' => 0,
                'current_rate' => 0,
                'is_active' => false,
                'current_period_shop_count' => 0,
                'total_shop_count' => 0,
                'total_commission' => 0,
                'pending_commission' => 0
            ];
        }

        if (empty($user['bd_code'])) {
            return [
                'is_bd_promoter' => false,
                'bd_code' => null,
                'apply_time' => null,
                'current_period_index' => 0,
                'current_rate' => 0,
                'is_active' => false,
                'current_period_shop_count' => 0,
                'total_shop_count' => 0,
                'total_commission' => 0,
                'pending_commission' => 0
            ];
        }

        // 获取当前周期
        $period = $this->getCurrentPeriod($userId);

        // 统计累计邀请店铺数
        $totalShopCount = Db::name('bd_shop_bindlog')
            ->where('bd_user_id', $userId)
            ->count();

        // 统计累计佣金
        $totalCommission = Db::name('bd_commission_log')
            ->where('bd_user_id', $userId)
            ->where('type', 'earn')
            ->where('settle_status', '<>', 'cancelled')
            ->sum('commission_amount') ?: 0;

        // 统计待结算佣金（只统计 pending 状态的 earn 记录）
        // 注意：被退款的 earn 记录已被标记为 cancelled，不会计入此处
        $pendingCommission = Db::name('bd_commission_log')
            ->where('bd_user_id', $userId)
            ->where('type', 'earn')
            ->where('settle_status', 'pending')
            ->sum('commission_amount') ?: 0;

        // 计算周期剩余天数
        $periodDaysRemaining = null;
        $periodStart = null;
        $periodEnd = null;
        if ($period) {
            $periodStart = (int)$period['period_start'];
            $periodEnd = (int)$period['period_end'];
            $now = time();
            if ($periodEnd > $now) {
                $periodDaysRemaining = (int)ceil(($periodEnd - $now) / 86400);
            } else {
                $periodDaysRemaining = 0;
            }
        }

        // 生成佣金比例显示文本
        $currentRate = $period ? (float)$period['current_rate'] : 0;
        $currentRateText = '';
        if ($currentRate > 0) {
            // 转换为千分比显示，如 0.001 -> "1‰"
            $ratePermille = $currentRate * 1000;
            $currentRateText = intval($ratePermille) . '‰';
        }

        return [
            'is_bd_promoter' => true,
            'bd_code' => $user['bd_code'],
            'apply_time' => (int)$user['bd_apply_time'],
            'current_period_index' => $period ? (int)$period['period_index'] : 0,
            'current_rate' => $currentRate,
            'current_rate_text' => $currentRateText,
            'is_active' => $currentRate > 0,
            'current_period_shop_count' => $period ? (int)$period['shop_count'] : 0,
            'total_shop_count' => (int)$totalShopCount,
            'total_commission' => round((float)$totalCommission, 2),
            'pending_commission' => round((float)$pendingCommission, 2),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'period_days_remaining' => $periodDaysRemaining
        ];
    }

    /**
     * 获取BD邀请的店铺列表
     *
     * @param int $bdUserId BD推广员用户ID
     * @param int $page 页码
     * @param int $limit 每页数量
     * @return array
     */
    public function getInvitedShops(int $bdUserId, int $page = 1, int $limit = 10): array
    {
        $offset = ($page - 1) * $limit;

        $total = Db::name('bd_shop_bindlog')
            ->where('bd_user_id', $bdUserId)
            ->count();

        $list = Db::name('bd_shop_bindlog')
            ->alias('b')
            ->join('wanlshop_shop s', 's.id = b.shop_id', 'LEFT')
            ->join('bd_promoter_period p', 'p.id = b.period_id', 'LEFT')
            ->where('b.bd_user_id', $bdUserId)
            ->field('b.id, b.shop_id, b.createtime as bind_time, s.shopname, s.avatar, s.city, p.period_index')
            ->order('b.createtime', 'desc')
            ->limit($offset, $limit)
            ->select();

        return [
            'list' => $list,
            'total' => $total
        ];
    }

    /**
     * 获取BD各周期统计
     *
     * @param int $bdUserId BD推广员用户ID
     * @return array
     */
    public function getPeriods(int $bdUserId): array
    {
        return Db::name('bd_promoter_period')
            ->where('user_id', $bdUserId)
            ->order('period_index', 'desc')
            ->select();
    }

    /**
     * 获取BD佣金明细
     *
     * @param int $bdUserId BD推广员用户ID
     * @param int $page 页码
     * @param int $limit 每页数量
     * @param int|null $startTime 开始时间
     * @param int|null $endTime 结束时间
     * @return array
     */
    public function getCommissionLogs(int $bdUserId, int $page = 1, int $limit = 10, ?int $startTime = null, ?int $endTime = null): array
    {
        $offset = ($page - 1) * $limit;

        // 构建基础条件闭包 - 只查询 earn 类型
        $buildWhere = function ($query) use ($bdUserId, $startTime, $endTime) {
            $query->where('c.bd_user_id', $bdUserId)
                  ->where('c.type', 'earn');  // 只查询收入记录
            if ($startTime) {
                $query->where('c.createtime', '>=', $startTime);
            }
            if ($endTime) {
                $query->where('c.createtime', '<=', $endTime);
            }
        };

        // 计数查询（只统计 earn 记录）
        $total = Db::name('bd_commission_log')
            ->alias('c')
            ->where($buildWhere)
            ->count();

        // 列表查询（关联更多表获取时间信息）
        $list = Db::name('bd_commission_log')
            ->alias('c')
            ->join('wanlshop_shop s', 's.id = c.shop_id', 'LEFT')
            ->join('wanlshop_voucher_verification v', 'v.id = c.verification_id', 'LEFT')
            ->join('wanlshop_voucher_order o', 'o.id = c.order_id', 'LEFT')
            ->where($buildWhere)
            ->field('c.*, s.shopname as shop_name, v.createtime as verify_time, o.paymenttime as payment_time')
            ->order('c.createtime', 'desc')
            ->limit($offset, $limit)
            ->select();

        $now = time();

        // 处理每条记录，添加状态标注
        foreach ($list as &$row) {
            $voucherId = $row['voucher_id'];
            $verificationId = $row['verification_id'];
            $paymentTime = (int)$row['payment_time'];
            $verifyTime = (int)$row['verify_time'];

            // 查找对应的 deduct 记录（退款扣减）
            $deductRecord = null;
            if ($verificationId) {
                $deductRecord = Db::name('bd_commission_log')
                    ->where('verification_id', $verificationId)
                    ->where('type', 'deduct')
                    ->field('id, commission_amount, createtime, remark')
                    ->find();
            }

            // 检查是否有退款记录（state=3 表示退款成功）
            $refundRecord = Db::name('wanlshop_voucher_refund')
                ->where('voucher_id', $voucherId)
                ->field('id, state, createtime')
                ->find();

            $hasRefund = $refundRecord && $refundRecord['state'] == 3;
            $refundPending = $refundRecord && in_array($refundRecord['state'], [0, 1]); // 申请中或同意退款

            // 条件1：7天无理由期（从支付时间算起）
            $sevenDaysDeadline = $paymentTime + 7 * 86400;
            $sevenDaysPassed = $now >= $sevenDaysDeadline;

            // 条件2：核销后24小时（仅在7天内有效）
            $twentyFourHoursDeadline = $verifyTime + 24 * 3600;
            $twentyFourHoursPassed = $now >= $twentyFourHoursDeadline;

            // 综合状态判断
            $settleSafe = false;
            if ($hasRefund || $refundPending) {
                $settleSafe = false;
            } elseif ($sevenDaysPassed) {
                $settleSafe = true;
            } elseif ($twentyFourHoursPassed) {
                $settleSafe = true;
            }

            // 添加状态字段
            $row['payment_time_text'] = $paymentTime ? date('Y-m-d H:i:s', $paymentTime) : '-';
            $row['verify_time_text'] = $verifyTime ? date('Y-m-d H:i:s', $verifyTime) : '-';
            $row['seven_days_deadline'] = $sevenDaysDeadline;
            $row['seven_days_deadline_text'] = $paymentTime ? date('Y-m-d H:i:s', $sevenDaysDeadline) : '-';
            $row['seven_days_passed'] = $sevenDaysPassed;
            $row['twenty_four_hours_deadline'] = $twentyFourHoursDeadline;
            $row['twenty_four_hours_deadline_text'] = $verifyTime ? date('Y-m-d H:i:s', $twentyFourHoursDeadline) : '-';
            $row['twenty_four_hours_passed'] = $twentyFourHoursPassed;
            $row['has_refund'] = $hasRefund;
            $row['refund_pending'] = $refundPending;
            $row['settle_safe'] = $settleSafe;

            // 添加 deduct 子记录信息
            $row['deduct_info'] = null;
            if ($deductRecord) {
                $row['deduct_info'] = [
                    'id' => $deductRecord['id'],
                    'amount' => $deductRecord['commission_amount'],
                    'time' => $deductRecord['createtime'],
                    'time_text' => date('Y-m-d H:i:s', $deductRecord['createtime']),
                    'remark' => $deductRecord['remark'] ?: '退款扣减'
                ];
            }

            // 生成综合状态文本
            if ($row['settle_status'] === 'cancelled' || $deductRecord) {
                $row['status_text'] = '已取消';
                $row['status_class'] = 'danger';
            } elseif ($row['settle_status'] === 'settled') {
                $row['status_text'] = '已结算';
                $row['status_class'] = 'success';
            } elseif ($hasRefund) {
                $row['status_text'] = '已退款';
                $row['status_class'] = 'danger';
            } elseif ($refundPending) {
                $row['status_text'] = '退款处理中';
                $row['status_class'] = 'warning';
            } elseif ($settleSafe) {
                $row['status_text'] = '可结算';
                $row['status_class'] = 'success';
            } else {
                // 计算剩余等待时间（在7天内，等24h过）
                $remainHours = ceil(($twentyFourHoursDeadline - $now) / 3600);
                $row['status_text'] = "待结算(24h期剩{$remainHours}h)";
                $row['status_class'] = 'info';
            }
        }
        unset($row);

        return [
            'list' => $list,
            'total' => $total
        ];
    }

    /**
     * 验证BD码是否有效
     *
     * @param string $bdCode BD推广码
     * @param int $excludeUserId 排除的用户ID（不能绑定自己）
     * @return array|null 返回BD用户信息，无效返回null
     */
    public function validateBdCode(string $bdCode, int $excludeUserId = 0): ?array
    {
        if (empty($bdCode)) {
            return null;
        }

        // 格式校验：BD + 8位数字 + 4位字母
        if (!preg_match('/^BD\d{8}[A-Z]{4}$/', strtoupper($bdCode))) {
            return null;
        }

        $bdUser = Db::name('user')
            ->where('bd_code', strtoupper($bdCode))
            ->field('id, nickname, avatar')
            ->find();

        if (!$bdUser) {
            return null;
        }

        // 不能绑定自己
        if ($excludeUserId > 0 && $bdUser['id'] == $excludeUserId) {
            return null;
        }

        return $bdUser;
    }

    /**
     * 为BD佣金创建返利记录（用于打款）
     *
     * @param int $commissionLogId BD佣金明细ID
     * @param bool $skipTimeCheck 是否跳过时间限制检查（后台管理员使用）
     * @return array ['success' => bool, 'message' => string, 'rebate_id' => int|null]
     * @throws Exception
     */
    public function createRebateRecord(int $commissionLogId, bool $skipTimeCheck = false): array
    {
        if ($commissionLogId <= 0) {
            return ['success' => false, 'message' => '参数无效', 'rebate_id' => null];
        }

        Db::startTrans();
        try {
            // 查找佣金记录
            $commissionLog = Db::name('bd_commission_log')
                ->where('id', $commissionLogId)
                ->where('type', 'earn')
                ->lock(true)
                ->find();

            if (!$commissionLog) {
                throw new Exception('佣金记录不存在');
            }

            // 检查状态：只能对 pending 状态的记录打款
            if ($commissionLog['settle_status'] !== 'pending') {
                $statusMap = [
                    'settled' => '已结算',
                    'cancelled' => '已取消',
                    'settling' => '结算中'
                ];
                $statusText = $statusMap[$commissionLog['settle_status']] ?? $commissionLog['settle_status'];
                throw new Exception("该记录状态为【{$statusText}】，无法打款");
            }

            // 检查是否满足打款条件（7天无理由期已过 或 核销后24小时已过）
            $now = time();
            $paymentTime = (int)$commissionLog['createtime']; // 使用佣金记录创建时间作为基准

            // 获取核销时间
            $verifyTime = 0;
            if ($commissionLog['verification_id']) {
                $verification = Db::name('wanlshop_voucher_verification')
                    ->where('id', $commissionLog['verification_id'])
                    ->field('createtime')
                    ->find();
                $verifyTime = $verification ? (int)$verification['createtime'] : 0;
            }

            // 获取订单支付时间
            if ($commissionLog['order_id']) {
                $order = Db::name('wanlshop_voucher_order')
                    ->where('id', $commissionLog['order_id'])
                    ->field('paymenttime')
                    ->find();
                $paymentTime = $order ? (int)$order['paymenttime'] : $paymentTime;
            }

            // 检查退款状态
            $hasRefund = false;
            $refundPending = false;
            if ($commissionLog['voucher_id']) {
                $refundRecord = Db::name('wanlshop_voucher_refund')
                    ->where('voucher_id', $commissionLog['voucher_id'])
                    ->field('id, state')
                    ->find();
                $hasRefund = $refundRecord && $refundRecord['state'] == 3;
                $refundPending = $refundRecord && in_array($refundRecord['state'], [0, 1]);
            }

            if ($hasRefund) {
                throw new Exception('该订单已退款，无法打款');
            }
            if ($refundPending) {
                throw new Exception('该订单退款处理中，无法打款');
            }

            // 条件判断：7天无理由期已过 或 核销后24小时已过（后台管理员可跳过此检查）
            $sevenDaysDeadline = $paymentTime + 7 * 86400;
            $twentyFourHoursDeadline = $verifyTime + 24 * 3600;
            $sevenDaysPassed = $now >= $sevenDaysDeadline;
            $twentyFourHoursPassed = $verifyTime > 0 && $now >= $twentyFourHoursDeadline;

            if (!$skipTimeCheck && !$sevenDaysPassed && !$twentyFourHoursPassed) {
                if ($verifyTime > 0) {
                    $remainHours = ceil(($twentyFourHoursDeadline - $now) / 3600);
                    throw new Exception("未满足打款条件，24小时确认期还剩 {$remainHours} 小时");
                } else {
                    $remainDays = ceil(($sevenDaysDeadline - $now) / 86400);
                    throw new Exception("未满足打款条件，7天无理由期还剩 {$remainDays} 天");
                }
            }

            // 检查是否已存在返利记录
            $existingRebate = Db::name('wanlshop_voucher_rebate')
                ->where('rebate_type', 'bd_promoter')
                ->where('remark', 'bd_commission_log_id:' . $commissionLogId)
                ->find();

            if ($existingRebate) {
                // 如果已存在且打款失败，可以重试
                if ($existingRebate['payment_status'] === 'failed') {
                    Db::commit();
                    return ['success' => true, 'message' => '使用已有记录重试', 'rebate_id' => (int)$existingRebate['id']];
                }
                throw new Exception('该佣金已创建打款记录，请勿重复操作');
            }

            // 创建返利记录
            $rebateData = [
                'user_id' => $commissionLog['bd_user_id'],
                'voucher_id' => $commissionLog['voucher_id'],
                'order_id' => $commissionLog['order_id'],
                'shop_id' => $commissionLog['shop_id'],
                'verification_id' => $commissionLog['verification_id'],
                'rule_id' => 0,
                'rebate_type' => 'bd_promoter',
                'face_value' => $commissionLog['order_amount'],
                'actual_bonus_ratio' => bcmul((string)$commissionLog['commission_rate'], '100', 2), // 转为百分比
                'rebate_amount' => $commissionLog['commission_amount'],
                'refund_amount' => 0,
                'stage' => 'goods', // BD佣金无阶段概念，使用货物损耗期
                'payment_time' => $paymentTime,
                'verify_time' => $verifyTime ?: $now,
                'payment_status' => 'unpaid',
                'status' => 'normal',
                'remark' => 'bd_commission_log_id:' . $commissionLogId,
                'createtime' => $now,
                'updatetime' => $now,
            ];

            $rebateId = Db::name('wanlshop_voucher_rebate')->insertGetId($rebateData);

            // 更新佣金记录状态为结算中
            Db::name('bd_commission_log')
                ->where('id', $commissionLogId)
                ->update(['settle_status' => 'settling']);

            Db::commit();

            return ['success' => true, 'message' => '创建成功', 'rebate_id' => $rebateId];

        } catch (Exception $e) {
            Db::rollback();
            return ['success' => false, 'message' => $e->getMessage(), 'rebate_id' => null];
        }
    }

    /**
     * 批量为满足条件的BD佣金创建返利记录
     *
     * @param int $bdUserId BD推广员用户ID
     * @return array ['success' => int, 'failed' => int, 'errors' => array]
     */
    public function batchCreateRebateRecords(int $bdUserId): array
    {
        $result = ['success' => 0, 'failed' => 0, 'errors' => []];

        // 获取所有待结算且可打款的佣金记录
        $logs = $this->getCommissionLogs($bdUserId, 1, 100);

        foreach ($logs['list'] as $log) {
            // 只处理可结算的记录
            if ($log['settle_status'] !== 'pending' || !$log['settle_safe']) {
                continue;
            }

            $createResult = $this->createRebateRecord((int)$log['id']);
            if ($createResult['success']) {
                $result['success']++;
            } else {
                $result['failed']++;
                $result['errors'][] = [
                    'id' => $log['id'],
                    'message' => $createResult['message']
                ];
            }
        }

        return $result;
    }
}
