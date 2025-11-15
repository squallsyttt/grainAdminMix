<?php

namespace app\admin\model\wanlshop;

use think\Model;
use traits\model\SoftDelete;

/**
 * 退款模型
 */
class VoucherRefund extends Model
{
    use SoftDelete;

    // 表名（不含表前缀 grain_）
    protected $name = 'wanlshop_voucher_refund';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = 'deletetime';

    // 追加属性：状态文本
    protected $append = [
        'state_text'
    ];

    /**
     * 状态枚举
     * 0=申请中, 1=同意退款, 2=拒绝退款, 3=退款成功
     * @return array
     */
    public function getStateList()
    {
        return [
            '0' => '申请中',
            '1' => '同意退款',
            '2' => '拒绝退款',
            '3' => '退款成功',
        ];
    }

    /**
     * 状态文本获取器
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getStateTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['state']) ? $data['state'] : '');
        $list = $this->getStateList();
        return isset($list[$value]) ? $list[$value] : '';
    }

    /**
     * 关联：所属券
     * @return \think\model\relation\BelongsTo
     */
    public function voucher()
    {
        return $this->belongsTo('Voucher', 'voucher_id', 'id');
    }

    /**
     * 关联：所属订单
     * @return \think\model\relation\BelongsTo
     */
    public function voucherOrder()
    {
        return $this->belongsTo('VoucherOrder', 'order_id', 'id');
    }

    /**
     * 关联：用户
     * @return \think\model\relation\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo('\\app\\common\\model\\User', 'user_id', 'id');
    }
}
