<?php

namespace app\admin\model\wanlshop;

use think\Model;
use traits\model\SoftDelete;

/**
 * 核销记录模型
 */
class VoucherVerification extends Model
{
    use SoftDelete;

    // 表名（不含表前缀 grain_）
    protected $name = 'wanlshop_voucher_verification';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = 'deletetime';

    // 追加属性：核销方式文本
    protected $append = [
        'verify_method_text'
    ];

    /**
     * 核销方式枚举
     * code=验证码, scan=扫码
     * @return array
     */
    public function getVerifyMethodList()
    {
        return [
            'code' => '验证码',
            'scan' => '扫码',
        ];
    }

    /**
     * 核销方式文本获取器
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getVerifyMethodTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['verify_method']) ? $data['verify_method'] : '');
        $list = $this->getVerifyMethodList();
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
     * 关联：用户
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
}
