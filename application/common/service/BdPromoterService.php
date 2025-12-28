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

        // 统计待结算佣金（扣除扣减的）
        $deductAmount = Db::name('bd_commission_log')
            ->where('bd_user_id', $userId)
            ->where('type', 'deduct')
            ->sum('commission_amount') ?: 0;

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
            'pending_commission' => round((float)$pendingCommission - (float)$deductAmount, 2),
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

        // 构建基础条件闭包，避免 count() 后 Query 状态被重置
        $buildWhere = function ($query) use ($bdUserId, $startTime, $endTime) {
            $query->where('c.bd_user_id', $bdUserId);
            if ($startTime) {
                $query->where('c.createtime', '>=', $startTime);
            }
            if ($endTime) {
                $query->where('c.createtime', '<=', $endTime);
            }
        };

        // 计数查询
        $total = Db::name('bd_commission_log')
            ->alias('c')
            ->where($buildWhere)
            ->count();

        // 列表查询
        $list = Db::name('bd_commission_log')
            ->alias('c')
            ->join('wanlshop_shop s', 's.id = c.shop_id', 'LEFT')
            ->where($buildWhere)
            ->field('c.*, s.shopname as shop_name')
            ->order('c.createtime', 'desc')
            ->limit($offset, $limit)
            ->select();

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
}
