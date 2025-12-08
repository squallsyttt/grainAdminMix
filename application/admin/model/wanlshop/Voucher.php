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

    // 追加属性：状态文本 + 前端所需计算属性
    protected $append = [
        'state_text',
        'custody_state_text',
        'code',
        'type',
        'productName',
        'weight',
        'deliveryMethod',
        'description',
        'status',
        'expire_at',
        'store_address',
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
     * 代管理状态枚举
     * 0=未申请, 1=申请中, 2=已通过, 3=已拒绝
     * @return array
     */
    public function getCustodyStateList()
    {
        return [
            '0' => '未申请',
            '1' => '申请中',
            '2' => '已通过',
            '3' => '已拒绝',
        ];
    }

    /**
     * 代管理状态文本获取器
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getCustodyStateTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['custody_state']) ? $data['custody_state'] : '0');
        $list = $this->getCustodyStateList();
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
     * 关联：所属规则
     * @return \think\model\relation\BelongsTo
     */
    public function voucherRule()
    {
        return $this->belongsTo('VoucherRule', 'rule_id', 'id');
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

    /**
     * 关联：返利结算记录（与券一对一）
     * @return \think\model\relation\HasOne
     */
    public function voucherRebate()
    {
        return $this->hasOne('VoucherRebate', 'voucher_id', 'id');
    }

    /**
     * 计算属性：券码
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getCodeAttr($value, $data)
    {
        return isset($data['voucher_no']) ? (string)$data['voucher_no'] : '';
    }

    /**
     * 计算属性：券类型（固定 GRAIN）
     * @return string
     */
    public function getTypeAttr()
    {
        return 'GRAIN';
    }

    /**
     * 计算属性：商品名称
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getProductNameAttr($value, $data)
    {
        return isset($data['goods_title']) ? (string)$data['goods_title'] : '';
    }

    /**
     * 计算属性：重量（临时用券面值代替）
     * @param mixed $value
     * @param array $data
     * @return mixed
     */
    public function getWeightAttr($value, $data)
    {
        if (isset($data['sku_weight']) && $data['sku_weight'] !== null) {
            return (float)$data['sku_weight'];
        }
        return isset($data['face_value']) ? $data['face_value'] : '';
    }

    /**
     * 计算属性：配送方式（固定 PICKUP）
     * @return string
     */
    public function getDeliveryMethodAttr()
    {
        return 'PICKUP';
    }

    /**
     * 计算属性：商品描述（来自关联 goods）
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getDescriptionAttr($value, $data)
    {
        try {
            $goods = $this->goods;
            if ($goods) {
                return isset($goods['description']) ? (string)$goods['description'] : '';
            }
        } catch (\Exception $e) {
            // 忽略关联异常，返回空字符串
        }
        return '';
    }

    /**
     * 计算属性：前端状态值
     * 将 state 映射为：1→UNUSED, 2→USED, 3→EXPIRED, 4→REFUNDED
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getStatusAttr($value, $data)
    {
        $state = isset($data['state']) ? (string)$data['state'] : '';
        $map = [
            '1' => 'UNUSED',
            '2' => 'USED',
            '3' => 'EXPIRED',
            '4' => 'REFUNDED',
        ];
        return isset($map[$state]) ? $map[$state] : '';
    }

    /**
     * 计算属性：过期时间（valid_end）
     * @param mixed $value
     * @param array $data
     * @return mixed
     */
    public function getExpireAtAttr($value, $data)
    {
        return isset($data['valid_end']) ? $data['valid_end'] : '';
    }

    /**
     * 计算属性：门店地址（仅 state=2 已核销时返回关联 shop.return）
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getStoreAddressAttr($value, $data)
    {
        $state = isset($data['state']) ? (int)$data['state'] : 0;
        if ($state !== 2) {
            return '';
        }
        try {
            $shop = $this->shop;
            if ($shop) {
                return isset($shop['return']) ? (string)$shop['return'] : '';
            }
        } catch (\Exception $e) {
            // 忽略关联异常，返回空字符串
        }
        return '';
    }
}
