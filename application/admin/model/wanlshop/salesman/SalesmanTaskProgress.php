<?php

namespace app\admin\model\wanlshop\salesman;

use think\Model;

/**
 * 业务员任务进度模型
 */
class SalesmanTaskProgress extends Model
{
    // 表名
    protected $name = 'salesman_task_progress';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    // 追加属性
    protected $append = [
        'state_text',
        'progress_percent'
    ];

    // 状态常量
    const STATE_ONGOING = 0;           // 进行中
    const STATE_COMPLETED = 1;         // 已完成待审核
    const STATE_AUDITED = 2;           // 已审核待发放
    const STATE_GRANTED = 3;           // 已发放
    const STATE_CANCELLED = 4;         // 已取消

    /**
     * 状态列表
     */
    public function getStateList()
    {
        return [
            self::STATE_ONGOING => '进行中',
            self::STATE_COMPLETED => '已完成待审核',
            self::STATE_AUDITED => '已审核待发放',
            self::STATE_GRANTED => '已发放',
            self::STATE_CANCELLED => '已取消'
        ];
    }

    /**
     * 状态文本获取器
     */
    public function getStateTextAttr($value, $data)
    {
        $value = isset($data['state']) ? $data['state'] : 0;
        $list = $this->getStateList();
        return $list[$value] ?? '';
    }

    /**
     * 进度百分比获取器
     */
    public function getProgressPercentAttr($value, $data)
    {
        $task = $this->task;
        if (!$task) {
            return 0;
        }

        $target = $task->getTargetValue();
        if ($target <= 0) {
            return 0;
        }

        if ($task->isAmountType()) {
            $current = (float)($data['current_amount'] ?? 0);
        } else {
            $current = (int)($data['current_count'] ?? 0);
        }

        $percent = round($current / $target * 100, 1);
        return min($percent, 100);
    }

    /**
     * 关联：业务员
     */
    public function salesman()
    {
        return $this->belongsTo('Salesman', 'salesman_id', 'id');
    }

    /**
     * 关联：任务
     */
    public function task()
    {
        return $this->belongsTo('SalesmanTask', 'task_id', 'id');
    }

    /**
     * 关联：审核管理员
     */
    public function auditAdmin()
    {
        return $this->belongsTo('\\app\\admin\\model\\Admin', 'audit_admin_id', 'id')
            ->field('id, username, nickname');
    }

    /**
     * 关联：发放管理员
     */
    public function rewardAdmin()
    {
        return $this->belongsTo('\\app\\admin\\model\\Admin', 'reward_admin_id', 'id')
            ->field('id, username, nickname');
    }

    /**
     * 检查是否可以审核
     */
    public function canAudit()
    {
        return $this->state == self::STATE_COMPLETED;
    }

    /**
     * 检查是否可以发放奖励
     */
    public function canGrant()
    {
        return $this->state == self::STATE_AUDITED;
    }

    /**
     * 检查是否可以取消
     */
    public function canCancel()
    {
        return in_array($this->state, [self::STATE_ONGOING, self::STATE_COMPLETED, self::STATE_AUDITED]);
    }

    /**
     * 检查任务是否完成
     */
    public function checkCompleted()
    {
        $task = $this->task;
        if (!$task) {
            return false;
        }

        $target = $task->getTargetValue();
        if ($target <= 0) {
            return false;
        }

        if ($task->isAmountType()) {
            return (float)$this->current_amount >= $target;
        }
        return (int)$this->current_count >= $target;
    }
}
