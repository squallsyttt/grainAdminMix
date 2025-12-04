<?php

namespace app\common\model;

use think\Model;

/**
 * 打款日志模型（支持结算和返利）
 */
class TransferLog extends Model
{
    // 表名（不含 grain_ 前缀）
    protected $name = 'wanlshop_transfer_log';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    // 业务类型常量
    const ORDER_TYPE_SETTLEMENT = 'settlement';
    const ORDER_TYPE_REBATE = 'rebate';

    // 状态常量
    const STATUS_PENDING = 1;   // 待确认
    const STATUS_SUCCESS = 2;   // 成功
    const STATUS_FAILED = 3;    // 失败

    /**
     * 业务类型列表
     * @return array
     */
    public static function getOrderTypeList()
    {
        return [
            self::ORDER_TYPE_SETTLEMENT => '结算',
            self::ORDER_TYPE_REBATE => '返利',
        ];
    }

    /**
     * 状态列表
     * @return array
     */
    public static function getStatusList()
    {
        return [
            self::STATUS_PENDING => '待确认',
            self::STATUS_SUCCESS => '成功',
            self::STATUS_FAILED => '失败',
        ];
    }

    /**
     * 关联：结算记录
     * @return \think\model\relation\BelongsTo
     */
    public function settlement()
    {
        return $this->belongsTo('\\app\\admin\\model\\wanlshop\\VoucherSettlement', 'settlement_id', 'id');
    }

    /**
     * 关联：返利记录
     * @return \think\model\relation\BelongsTo
     */
    public function rebate()
    {
        return $this->belongsTo('\\app\\admin\\model\\wanlshop\\VoucherRebate', 'rebate_id', 'id');
    }

    /**
     * 关联：收款用户
     * @return \think\model\relation\BelongsTo
     */
    public function receiverUser()
    {
        return $this->belongsTo('\\app\\common\\model\\User', 'receiver_user_id', 'id');
    }
}

