<?php

namespace app\admin\validate\wanlshop\voucher;

use think\Validate;

class Voucher extends Validate
{
    /**
     * 验证规则
     */
    protected $rule = [
        'id' => 'require|integer|gt:0',
    ];

    /**
     * 提示消息（中文）
     */
    protected $message = [
        'id.require' => 'ID不能为空',
        'id.integer' => 'ID必须为整数',
        'id.gt'      => 'ID必须大于0',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        'detail' => ['id'],
    ];
}

