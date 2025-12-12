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

    // 关闭字段严格模式，允许访问所有数据库字段
    protected $strict = false;

    // 追加属性：阶段文本、返现状态、返利类型文本、代管理退款状态文本
    protected $append = [
        'stage_text',
        'payment_status_text',
        'rebate_type_text',
        'custody_refund_status_text'
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

    // 打款状态常量
    const PAYMENT_STATUS_UNPAID = 'unpaid';     // 未打款
    const PAYMENT_STATUS_PENDING = 'pending';   // 打款中
    const PAYMENT_STATUS_PAID = 'paid';         // 已打款
    const PAYMENT_STATUS_FAILED = 'failed';     // 打款失败

    /**
     * 返现状态枚举
     * @return array
     */
    public function getPaymentStatusList()
    {
        return [
            self::PAYMENT_STATUS_UNPAID => '未打款',
            self::PAYMENT_STATUS_PENDING => '打款中',
            self::PAYMENT_STATUS_PAID => '已打款',
            self::PAYMENT_STATUS_FAILED => '打款失败',
        ];
    }

    /**
     * 判断是否可以打款
     * 条件：
     * - 普通返利/代管理返利：付款时间超过7天 + 未打款或打款失败 + 已核销
     * - 店铺邀请返利：无需等待7天（已经过24小时审核期），直接可打款
     * @return bool
     */
    public function canTransfer()
    {
        // 基本条件：未打款或打款失败，且已核销
        $statusOk = in_array($this->payment_status, [self::PAYMENT_STATUS_UNPAID, self::PAYMENT_STATUS_FAILED])
            && $this->verify_time > 0;

        if (!$statusOk) {
            return false;
        }

        // 店铺邀请返利：已经过后台审核（24小时等待期），无需再等7天
        if ($this->rebate_type === 'shop_invite') {
            return true;
        }

        // 其他类型：需要付款时间超过7天
        $sevenDaysAgo = time() - 7 * 86400;
        return $this->payment_time < $sevenDaysAgo;
    }

    /**
     * 计算返利金额（分）
     * @return int
     */
    public function calculateRebateAmountFen()
    {
        $amount = bcmul($this->face_value, $this->actual_bonus_ratio, 4);
        $amount = bcdiv($amount, 100, 2);
        return (int)bcmul($amount, 100, 0);
    }

    /**
     * 关联：打款日志
     * @return \think\model\relation\HasMany
     */
    public function transferLogs()
    {
        return $this->hasMany('\\app\\common\\model\\TransferLog', 'rebate_id', 'id');
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
     * 返利类型枚举
     * @return array
     */
    public function getRebateTypeList()
    {
        return [
            'normal' => '核销返利',
            'custody' => '代管理返利',
            'shop_invite' => '店铺邀请返利',
        ];
    }

    /**
     * 返利类型文本获取器
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getRebateTypeTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['rebate_type']) ? $data['rebate_type'] : 'normal');
        $list = $this->getRebateTypeList();
        return isset($list[$value]) ? $list[$value] : '核销返利';
    }

    /**
     * 代管理退款状态枚举
     * @return array
     */
    public function getCustodyRefundStatusList()
    {
        return [
            'none' => '无退款',
            'pending' => '退款中',
            'success' => '退款成功',
            'failed' => '退款失败',
        ];
    }

    /**
     * 代管理退款状态文本获取器
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getCustodyRefundStatusTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['custody_refund_status']) ? $data['custody_refund_status'] : 'none');
        $list = $this->getCustodyRefundStatusList();
        return isset($list[$value]) ? $list[$value] : '无退款';
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

    /**
     * 关联：邀请的店铺（店铺邀请返利类型专用）
     * @return \think\model\relation\BelongsTo
     */
    public function inviteShop()
    {
        return $this->belongsTo('Shop', 'invite_shop_id', 'id');
    }

    /**
     * 关联：代管理退款记录
     * @return \think\model\relation\BelongsTo
     */
    public function custodyRefund()
    {
        return $this->belongsTo('VoucherRefund', 'custody_refund_id', 'id');
    }

    /**
     * 计算总打款金额（返利 + 等量退款）
     * @return float
     */
    public function getTotalAmountAttr()
    {
        return round((float)$this->rebate_amount + (float)$this->refund_amount, 2);
    }
}
