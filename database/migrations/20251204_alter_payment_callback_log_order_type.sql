-- 扩展支付回调日志 order_type 枚举，补充返利转账类型
-- 变更日期: 2025-12-04
-- 说明: 之前枚举缺少 rebate_transfer，导致返利打款回调插入 wanlshop_payment_callback_log 时出现
--       “Data truncated for column 'order_type'” 警告并中断业务处理

ALTER TABLE `grain_wanlshop_payment_callback_log`
MODIFY COLUMN `order_type` enum(
    'goods',
    'voucher',
    'groups',
    'voucher_refund',
    'voucher_transfer',
    'rebate_transfer'
) COLLATE utf8mb4_general_ci NOT NULL
COMMENT '订单类型:goods=商品订单,voucher=核销券订单,voucher_refund=核销券退款,voucher_transfer=核销券结算转账回调,rebate_transfer=核销券返利转账回调,groups=拼团订单';
