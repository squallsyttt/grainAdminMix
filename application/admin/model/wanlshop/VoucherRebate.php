<?php

namespace app\admin\model\wanlshop;

use think\Model;
use traits\model\SoftDelete;

/**
 * 返利结算模型
 */
class VoucherRebate extends Model
{
    use SoftDelete;

    // 表名（不含表前缀 grain_）
    protected $name = 'wanlshop_voucher_rebate';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = 'deletetime';

    // 追加属性：阶段文本、返现状态
    protected $append = [
        'stage_text',
        'payment_status_text'
    ];

    /**
     * 阶段枚举
     * @return array
     */
    public function getStageList()
    {
        return [
            'free' => '免费期',
            'welfare' => '福利损耗期',
            'goods' => '货物损耗期',
            'expired' => '已过期',
        ];
    }

    /**
     * 阶段文本获取器
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getStageTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['stage']) ? $data['stage'] : '');
        $list = $this->getStageList();
        return isset($list[$value]) ? $list[$value] : '';
    }

    /**
     * 返现状态枚举
     * @return array
     */
    public function getPaymentStatusList()
    {
        return [
            'unpaid' => '未打款',
            'paid' => '已打款',
        ];
    }

    /**
     * 返现状态文本获取器
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getPaymentStatusTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['payment_status']) ? $data['payment_status'] : '');
        $list = $this->getPaymentStatusList();
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
     * 关联：所属用户
     * @return \think\model\relation\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo('\\app\\common\\model\\User', 'user_id', 'id');
    }

    /**
     * 关联：核销店铺
     * @return \think\model\relation\BelongsTo
     */
    public function shop()
    {
        return $this->belongsTo('Shop', 'shop_id', 'id');
    }

    /**
     * 关联：所属规则
     * @return \think\model\relation\BelongsTo
     */
    public function voucherRule()
    {
        return $this->belongsTo('VoucherRule', 'rule_id', 'id');
    }

    /**
     * 关联：核销记录
     * @return \think\model\relation\BelongsTo
     */
    public function verification()
    {
        return $this->belongsTo('VoucherVerification', 'verification_id', 'id');
    }
}
