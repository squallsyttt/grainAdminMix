-- GrainAdminMix Payment Callback Log DDL Migration
-- 创建日期: 2025-11-19
-- 说明: 新增微信支付回调日志表，用于记录回调报文、签名验证与处理结果

CREATE TABLE IF NOT EXISTS `grain_wanlshop_payment_callback_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `order_type` enum('goods','voucher','groups','voucher_refund','voucher_transfer') COLLATE utf8mb4_general_ci NOT NULL COMMENT '订单类型:goods=商品订单,voucher=核销券订单,voucher_refund=核销券退款,voucher_transfer=核销券转账回调,groups=拼团订单',
  `order_no` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT '商户订单号',
  `transaction_id` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '微信支付订单号',
  `trade_state` varchar(32) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '交易状态(SUCCESS/REFUND/NOTPAY/...)',
  `callback_body` text COLLATE utf8mb4_general_ci COMMENT '完整回调报文(JSON)',
  `callback_headers` text COLLATE utf8mb4_general_ci COMMENT '回调头部信息(JSON,含签名)',
  `verify_result` enum('success','fail') COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '签名验证结果',
  `process_status` enum('pending','success','fail') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending' COMMENT '处理状态',
  `process_result` text COLLATE utf8mb4_general_ci COMMENT '处理结果(JSON,含错误信息)',
  `createtime` bigint(16) DEFAULT NULL COMMENT '创建时间',
  `updatetime` bigint(16) DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_transaction_id` (`transaction_id`),
  KEY `idx_order_no` (`order_no`),
  KEY `idx_process_status` (`process_status`),
  KEY `idx_createtime` (`createtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='微信支付回调日志表';
