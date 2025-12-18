<?php

namespace app\admin\model\wanlshop\salesman;

use think\Model;
use think\Db;

/**
 * 业务员统计模型
 */
class SalesmanStats extends Model
{
    // 表名
    protected $name = 'salesman_stats';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = false;

    // 定义时间戳字段名
    protected $updateTime = 'updatetime';

    /**
     * 关联：用户（业务员）
     */
    public function user()
    {
        return $this->belongsTo('\\app\\common\\model\\User', 'user_id', 'id');
    }

    /**
     * 刷新统计数据
     *
     * @param int $userId 业务员用户ID
     * @return bool
     */
    public static function refreshStats($userId)
    {
        $now = time();

        // 1. 统计邀请用户总数
        $inviteUserCount = Db::name('user')
            ->where('inviter_id', $userId)
            ->count();

        // 2. 统计邀请用户已核销数（从升级日志统计）
        $inviteUserVerified = Db::name('user_invite_upgrade_log')
            ->where('user_id', $userId)
            ->count();

        // 3. 统计邀请商家总数
        $inviteShopCount = Db::name('wanlshop_shop')
            ->where('inviter_id', $userId)
            ->count();

        // 4. 统计邀请商家已核销数
        $inviteShopVerified = Db::name('shop_invite_rebate_log')
            ->where('inviter_id', $userId)
            ->count();

        // 5. 统计累计返利金额
        $totalRebateAmount = Db::name('wanlshop_voucher_rebate')
            ->where('user_id', $userId)
            ->sum('rebate_amount') ?: 0;

        // 6. 统计累计任务奖励（已发放）
        $totalRewardAmount = Db::name('salesman_task_progress')
            ->where('user_id', $userId)
            ->where('state', SalesmanTaskProgress::STATE_GRANTED)
            ->sum('reward_amount') ?: 0;

        // 7. 统计待发放奖励
        $pendingRewardAmount = Db::name('salesman_task_progress')
            ->where('user_id', $userId)
            ->whereIn('state', [SalesmanTaskProgress::STATE_COMPLETED, SalesmanTaskProgress::STATE_AUDITED])
            ->sum('reward_amount') ?: 0;

        // 更新或插入统计记录
        $exists = self::where('user_id', $userId)->find();
        $data = [
            'user_id' => $userId,
            'invite_user_count' => $inviteUserCount,
            'invite_user_verified' => $inviteUserVerified,
            'invite_shop_count' => $inviteShopCount,
            'invite_shop_verified' => $inviteShopVerified,
            'total_rebate_amount' => $totalRebateAmount,
            'total_reward_amount' => $totalRewardAmount,
            'pending_reward_amount' => $pendingRewardAmount,
            'updatetime' => $now
        ];

        if ($exists) {
            return $exists->save($data);
        } else {
            $stats = new self();
            return $stats->save($data);
        }
    }

    /**
     * 批量刷新所有业务员统计
     */
    public static function refreshAllStats()
    {
        $salesmen = Db::name('user')->where('is_salesman', 1)->select();
        foreach ($salesmen as $salesman) {
            self::refreshStats($salesman['id']);
        }
    }

    /**
     * 获取业务员在指定任务类型上的当前进度值
     *
     * @param int $userId 用户ID
     * @param string $taskType 任务类型
     * @return int|float
     */
    public static function getProgressValue($userId, $taskType)
    {
        switch ($taskType) {
            case SalesmanTask::TYPE_USER_VERIFY:
                return Db::name('user_invite_upgrade_log')
                    ->where('user_id', $userId)
                    ->count();

            case SalesmanTask::TYPE_SHOP_VERIFY:
                return Db::name('shop_invite_rebate_log')
                    ->where('inviter_id', $userId)
                    ->count();

            case SalesmanTask::TYPE_REBATE_AMOUNT:
                return Db::name('wanlshop_voucher_rebate')
                    ->where('user_id', $userId)
                    ->sum('rebate_amount') ?: 0;

            default:
                return 0;
        }
    }
}
