<?php

namespace app\admin\model\wanlshop;

use think\Model;
use traits\model\SoftDelete;

/**
 * 核销券订单模型
 */
class VoucherOrder extends Model
{
    use SoftDelete;

    // 表名（不含表前缀 grain_）
    protected $name = 'wanlshop_voucher_order';

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
     * 1=待支付, 2=已支付, 3=已取消
     * @return array
     */
    public function getStateList()
    {
        return [
            '1' => '待支付',
            '2' => '已支付',
            '3' => '已取消',
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
     * 关联：订单包含的核销券（一个订单可对应多张券）
     * @return \think\model\relation\HasMany
     */
    public function vouchers()
    {
        return $this->hasMany('Voucher', 'order_id', 'id');
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
}
