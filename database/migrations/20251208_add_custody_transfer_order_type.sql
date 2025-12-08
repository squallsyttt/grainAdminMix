-- 支付回调日志 order_type 枚举扩展
-- 创建日期: 2025-12-08
-- 说明: 添加 custody_transfer 类型，区分代管理返利转账回调

ALTER TABLE `grain_wanlshop_payment_callback_log`
MODIFY COLUMN `order_type` enum('goods','voucher','groups','voucher_refund','voucher_transfer','rebate_transfer','custody_transfer') NOT NULL DEFAULT 'goods'
    COMMENT '订单类型:goods=商品订单,voucher=核销券订单,voucher_refund=核销券退款,voucher_transfer=核销券结算转账回调,rebate_transfer=核销券返利转账回调,custody_transfer=代管理返利转账回调,groups=拼团订单';
