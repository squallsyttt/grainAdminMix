-- GrainAdminMix Voucher Order DDL Migration
-- 创建日期: 2025-11-19
-- 说明: 核销券订单表增加微信支付订单号字段，用于回写交易号

ALTER TABLE `grain_wanlshop_voucher_order`
ADD COLUMN `transaction_id` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '微信支付订单号' AFTER `order_no`;

