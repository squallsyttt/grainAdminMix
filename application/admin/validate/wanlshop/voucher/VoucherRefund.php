<?php

namespace app\admin\validate\wanlshop\voucher;

use think\Validate;

class VoucherRefund extends Validate
{
    /**
     * 验证规则
     */
    protected $rule = [
        'voucher_id'    => 'require|integer|gt:0',
        'refund_reason' => 'require|max:255',
        'state'         => 'require|in:1,2',
        'refuse_reason' => 'requireIf:state,2|max:255',
    ];

    /**
     * 提示消息（中文）
     */
    protected $message = [
        'voucher_id.require'    => '核销券ID不能为空',
        'voucher_id.integer'    => '核销券ID必须为整数',
        'voucher_id.gt'         => '核销券ID必须大于0',
        'refund_reason.require' => '退款原因不能为空',
        'refund_reason.max'     => '退款原因长度不能超过255个字符',
        'state.require'         => '审核状态不能为空',
        'state.in'              => '审核状态取值不合法',
        'refuse_reason.requireIf' => '当审核不通过时必须填写拒绝原因',
        'refuse_reason.max'       => '拒绝原因长度不能超过255个字符',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        'apply' => ['voucher_id', 'refund_reason'],
        'audit' => ['state', 'refuse_reason'],
    ];
}

