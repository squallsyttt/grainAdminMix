<?php

namespace app\admin\model\wanlshop;

use think\Model;
use traits\model\SoftDelete;

/**
 * 核销券模型
 */
class Voucher extends Model
{
    use SoftDelete;

    // 表名（不含表前缀 grain_）
    protected $name = 'wanlshop_voucher';

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
     * 1=未使用, 2=已核销, 3=已过期, 4=已退款
     * @return array
     */
    public function getStateList()
    {
        return [
            '1' => '未使用',
            '2' => '已核销',
            '3' => '已过期',
            '4' => '已退款',
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
     * 关联：所属分类
     * @return \think\model\relation\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo('Category', 'category_id', 'id');
    }

    /**
     * 关联：所属商品
     * @return \think\model\relation\BelongsTo
     */
    public function goods()
    {
        return $this->belongsTo('Goods', 'goods_id', 'id');
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
     * 关联：核销记录（与券一对一）
     * @return \think\model\relation\HasOne
     */
    public function voucherVerification()
    {
        return $this->hasOne('VoucherVerification', 'voucher_id', 'id');
    }

    /**
     * 关联：结算记录（与券一对一）
     * @return \think\model\relation\HasOne
     */
    public function voucherSettlement()
    {
        return $this->hasOne('VoucherSettlement', 'voucher_id', 'id');
    }

    /**
     * 关联：退款记录（与券一对一）
     * @return \think\model\relation\HasOne
     */
    public function voucherRefund()
    {
        return $this->hasOne('VoucherRefund', 'voucher_id', 'id');
    }
}
