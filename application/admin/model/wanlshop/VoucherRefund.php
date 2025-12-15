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
        'state_text',
        'merchant_audit_state_text',
        'refund_source_text'
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
     *
     * 对于已核销券退款，根据商家审核状态细化显示：
     * - 商家待审核：显示"待商家审核"
     * - 商家已同意：显示"待平台审核"
     * - 商家已拒绝：显示"商家已拒绝"
     *
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getStateTextAttr($value, $data)
    {
        $state = $value ? $value : (isset($data['state']) ? $data['state'] : '');
        $list = $this->getStateList();
        $stateText = isset($list[$state]) ? $list[$state] : '';

        // 对于已核销券退款，细化状态显示
        if (isset($data['refund_source']) && $data['refund_source'] == 'verified_24h' && $state == '0') {
            $merchantState = isset($data['merchant_audit_state']) ? $data['merchant_audit_state'] : null;
            if ($merchantState === 0 || $merchantState === '0') {
                return '待商家审核';
            } elseif ($merchantState == 1) {
                return '待平台审核';
            } elseif ($merchantState == 2) {
                return '商家已拒绝';
            }
        }

        return $stateText;
    }

    /**
     * 商家审核状态文本获取器
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getMerchantAuditStateTextAttr($value, $data)
    {
        $state = isset($data['merchant_audit_state']) ? $data['merchant_audit_state'] : null;
        $list = [
            0 => '待审核',
            1 => '已同意',
            2 => '已拒绝',
        ];
        return ($state !== null && isset($list[$state])) ? $list[$state] : '-';
    }

    /**
     * 退款来源文本获取器
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getRefundSourceTextAttr($value, $data)
    {
        $source = isset($data['refund_source']) ? $data['refund_source'] : '';
        $list = [
            'user' => '未使用券退款',
            'custody' => '托管退款',
            'verified_24h' => '核销后退款',
        ];
        return isset($list[$source]) ? $list[$source] : $source;
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
