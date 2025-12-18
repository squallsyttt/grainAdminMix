<?php

namespace app\admin\model\wanlshop\salesman;

use think\Model;

/**
 * 业务员任务配置模型
 */
class SalesmanTask extends Model
{
    // 表名
    protected $name = 'salesman_task';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    // 追加属性
    protected $append = [
        'type_text',
        'status_text',
        'target_text'
    ];

    // 任务类型常量
    const TYPE_USER_VERIFY = 'user_verify';
    const TYPE_SHOP_VERIFY = 'shop_verify';
    const TYPE_REBATE_AMOUNT = 'rebate_amount';

    /**
     * 任务类型列表
     */
    public function getTypeList()
    {
        return [
            self::TYPE_USER_VERIFY => '邀请用户核销',
            self::TYPE_SHOP_VERIFY => '邀请商家核销',
            self::TYPE_REBATE_AMOUNT => '累计返利金额'
        ];
    }

    /**
     * 任务类型文本获取器
     */
    public function getTypeTextAttr($value, $data)
    {
        $value = $value ?: ($data['type'] ?? '');
        $list = $this->getTypeList();
        return $list[$value] ?? '';
    }

    /**
     * 状态列表
     */
    public function getStatusList()
    {
        return [
            'normal' => '启用',
            'disabled' => '禁用'
        ];
    }

    /**
     * 状态文本获取器
     */
    public function getStatusTextAttr($value, $data)
    {
        $value = $value ?: ($data['status'] ?? '');
        $list = $this->getStatusList();
        return $list[$value] ?? '';
    }

    /**
     * 目标文本获取器
     */
    public function getTargetTextAttr($value, $data)
    {
        $type = $data['type'] ?? '';
        if ($type === self::TYPE_REBATE_AMOUNT) {
            return '¥' . number_format($data['target_amount'] ?? 0, 2);
        }
        return ($data['target_count'] ?? 0) . '个';
    }

    /**
     * 关联：任务进度
     */
    public function progress()
    {
        return $this->hasMany('SalesmanTaskProgress', 'task_id', 'id');
    }

    /**
     * 检查是否为数量类型任务
     */
    public function isCountType()
    {
        return in_array($this->type, [self::TYPE_USER_VERIFY, self::TYPE_SHOP_VERIFY]);
    }

    /**
     * 检查是否为金额类型任务
     */
    public function isAmountType()
    {
        return $this->type === self::TYPE_REBATE_AMOUNT;
    }

    /**
     * 获取目标值
     */
    public function getTargetValue()
    {
        if ($this->isAmountType()) {
            return (float)$this->target_amount;
        }
        return (int)$this->target_count;
    }
}
