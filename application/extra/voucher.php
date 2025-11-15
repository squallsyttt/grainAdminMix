<?php

/**
 * 核销券系统配置
 *
 * 说明：
 * - 默认值与 .specify/voucher-config-guide.md 中的示例保持一致
 * - 实际配置优先从 .env 中的 [voucher] 段读取
 */
return [
    // 券有效期（天）
    'valid_days'   => (int) \think\Env::get('voucher.valid_days', 30),

    // 是否允许退款
    'allow_refund' => (bool) \think\Env::get('voucher.allow_refund', true),

    // 退款期限（有效期前N天内可退款）
    'refund_days'  => (int) \think\Env::get('voucher.refund_days', 7),
];
