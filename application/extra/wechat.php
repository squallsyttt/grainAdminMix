<?php

/**
 * 微信支付配置（用于核销券订单支付）
 *
 * 说明：
 * - 实际敏感配置从 .env 中的 [wechat_payment] 段读取，避免写死在代码中
 * - 仅在缺省时使用默认值（如 notify_url）
 * - 使用微信支付公钥验签模式（platform_public_key_id + platform_public_key_path）
 * - 具体配置说明参考 .specify/voucher-config-guide.md
 */
return [
    'payment' => [
        // 小程序 appid（与小程序配置相同）
        'appid'      => \think\Env::get('wechat_payment.appid', ''),

        // 商户号（在微信商户平台获取）
        'mch_id'     => \think\Env::get('wechat_payment.mch_id', ''),

        // APIv3密钥（32位字符串）
        'apiv3_key'  => \think\Env::get('wechat_payment.apiv3_key', ''),

        // API证书路径（绝对路径或相对于项目根目录）
        'cert_path'  => \think\Env::get('wechat_payment.cert_path', ''),

        // 证书密钥路径（绝对路径或相对于项目根目录）
        'key_path'   => \think\Env::get('wechat_payment.key_path', ''),

        // 商户证书序列号
        'serial_no'  => \think\Env::get('wechat_payment.serial_no', ''),

        // 微信支付平台公钥文件路径（用于验签）
        'platform_public_key_path' => \think\Env::get('wechat_payment.platform_public_key_path', ''),

        // 微信支付平台公钥ID（在商户平台查看）
        'platform_public_key_id' => \think\Env::get('wechat_payment.platform_public_key_id', ''),

        // 微信支付平台证书序列号（用于验签）
        'platform_public_cert_serial_no' => \think\Env::get(
            'wechat_payment.platform_public_cert_serial_no',
            '3151A8551CC1BDEB9046CB4CCD347410DC3A8922'
        ),

        // 微信支付平台证书路径（用于验签，相对于项目根目录）
        'platform_public_cert_path' => \think\Env::get(
            'wechat_payment.platform_public_cert_path',
            'cert/wechat/wechatpay_3151A8551CC1BDEB9046CB4CCD347410DC3A8922.pem'
        ),

        // 支付回调地址（必须 HTTPS，可通过 .env 覆盖）
        'notify_url' => \think\Env::get(
            'wechat_payment.notify_url',
            'https://yourdomain.com/api/wanlshop/voucher/order/notify'
        ),

        // 退款回调地址（必须 HTTPS，可通过 .env 覆盖）
        'refund_notify_url' => \think\Env::get(
            'wechat_payment.refund_notify_url',
            'https://yourdomain.com/api/wanlshop/voucher/order/refundNotify'
        ),

        // 转账回调地址（必须 HTTPS，可通过 .env 覆盖）
        'transfer_notify_url' => \think\Env::get(
            'wechat_payment.transfer_notify_url',
            'https://yourdomain.com/api/wanlshop/voucher/settlement/transferNotify'
        ),
    ],
];
