<?php

namespace app\admin\model\wanlshop;

use think\Model;

/**
 * 核销券规则模型
 */
class VoucherRule extends Model
{

    // 表名（不含表前缀 grain_）
    protected $name = 'wanlshop_voucher_rule';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    // 追加属性
    protected $append = [
        'state_text',
        'voucher_count',
    ];

    /**
     * 状态枚举
     * @return array
     */
    public function getStateList()
    {
        return [
            '1' => '启用',
            '0' => '禁用',
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
     * 计算属性：关联券数量
     * @param mixed $value
     * @param array $data
     * @return int
     */
    public function getVoucherCountAttr($value, $data)
    {
        if (!isset($data['id'])) {
            return 0;
        }

        return Voucher::where('rule_id', $data['id'])->count();
    }

    /**
     * 关联：返利结算记录
     * @return \think\model\relation\HasMany
     */
    public function voucherRebates()
    {
        return $this->hasMany('VoucherRebate', 'rule_id', 'id');
    }

    /**
     * 获取启用状态且优先级最高的规则
     * @return static|null
     */
    public static function getActiveRule()
    {
        return self::where('state', '1')->order('priority', 'desc')->find();
    }
}
