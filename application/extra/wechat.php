<?php

/**
 * 微信支付配置（用于核销券订单支付）
 *
 * 说明：
 * - 实际敏感配置从 .env 中的 [wechat_payment] 段读取，避免写死在代码中
 * - 仅在缺省时使用默认值（如 notify_url）
 * - 具体配置说明参考 .specify/voucher-config-guide.md
 */
return [
    'payment' => [
        // 小程序 appid（与小程序配置相同）
        'appid'      => \think\Env::get('wechat_payment.appid', ''),

        // 商户号（在微信商户平台获取）
        'mch_id'     => \think\Env::get('wechat_payment.mch_id', ''),

        // API密钥 V2（32位字符串）
        'key'        => \think\Env::get('wechat_payment.api_key', ''),

        // APIv3密钥（推荐使用，32位字符串）
        'apiv3_key'  => \think\Env::get('wechat_payment.apiv3_key', ''),

        // API证书路径（绝对路径）
        'cert_path'  => \think\Env::get('wechat_payment.cert_path', ''),

        // 证书密钥路径（绝对路径）
        'key_path'   => \think\Env::get('wechat_payment.key_path', ''),

        // 证书序列号
        'serial_no'  => \think\Env::get('wechat_payment.serial_no', ''),

        // 微信支付平台证书路径（用于回调验签）
        'platform_cert_path' => \think\Env::get('wechat_payment.platform_cert_path', ''),

        // 支付回调地址（必须 HTTPS，可通过 .env 覆盖）
        'notify_url' => \think\Env::get(
            'wechat_payment.notify_url',
            'https://yourdomain.com/api/wanlshop/voucher/order/notify'
        ),
    ],
];
