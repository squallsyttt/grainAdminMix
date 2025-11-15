<?php

namespace app\admin\validate\wanlshop\voucher;

use think\Validate;

class VoucherOrder extends Validate
{
    /**
     * 验证规则
     */
    protected $rule = [
        'goods_id'    => 'require|integer|gt:0',
        'category_id' => 'require|integer|gt:0',
        'quantity'    => 'require|integer|gt:0',
    ];

    /**
     * 提示消息（中文）
     */
    protected $message = [
        'goods_id.require'    => '商品ID不能为空',
        'goods_id.integer'    => '商品ID必须为整数',
        'goods_id.gt'         => '商品ID必须大于0',
        'category_id.require' => '分类ID不能为空',
        'category_id.integer' => '分类ID必须为整数',
        'category_id.gt'      => '分类ID必须大于0',
        'quantity.require'    => '购买数量不能为空',
        'quantity.integer'    => '购买数量必须为整数',
        'quantity.gt'         => '购买数量必须大于0',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        'create' => ['goods_id', 'category_id', 'quantity'],
        'cancel' => [],
    ];
}

