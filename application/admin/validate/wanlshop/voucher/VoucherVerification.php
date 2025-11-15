<?php

namespace app\admin\validate\wanlshop\voucher;

use think\Validate;

class VoucherVerification extends Validate
{
    /**
     * 验证规则
     * voucher_no 与 verify_code 至少填一个
     */
    protected $rule = [
        'voucher_no'  => 'requireWithout:verify_code',
        'verify_code' => 'requireWithout:voucher_no',
    ];

    /**
     * 提示消息（中文）
     */
    protected $message = [
        'voucher_no.requireWithout'  => '券号与核销码至少填写一个',
        'verify_code.requireWithout' => '券号与核销码至少填写一个',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        'code' => ['voucher_no', 'verify_code'],
    ];
}

