-- 核销券返利结算表 DDL
-- 创建日期: 2025-12-02
-- 说明: 根据 .claude/specs/voucher-rebate/dev-plan.md 创建 grain_wanlshop_voucher_rebate 表

CREATE TABLE IF NOT EXISTS `grain_wanlshop_voucher_rebate` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `voucher_id` int(10) unsigned NOT NULL COMMENT '核销券ID',
  `voucher_no` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT '核销券号',
  `order_id` int(10) unsigned NOT NULL COMMENT '订单ID',
  `verification_id` int(10) unsigned NOT NULL COMMENT '核销记录ID',
  `user_id` int(10) NOT NULL COMMENT '用户ID',
  `shop_id` int(10) unsigned NOT NULL COMMENT '核销店铺ID',
  `shop_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL COMMENT '店铺名称',

  `supply_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '供货价',
  `face_value` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '券面值',
  `rebate_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '实际返利金额',

  `user_bonus_ratio` decimal(5,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '用户原始返利比例(%)',
  `actual_bonus_ratio` decimal(5,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '实际返利比例(%)',

  `stage` enum('free','welfare','goods','expired') COLLATE utf8mb4_general_ci NOT NULL COMMENT '返利阶段:free=免费期,welfare=福利损耗期,goods=货物损耗期,expired=已过期',
  `days_from_payment` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '距付款天数',

  `goods_title` varchar(255) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '商品标题',
  `sku_weight` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT 'SKU 规格重量(斤)',
  `original_goods_weight` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '原始货物重量(斤)',
  `actual_goods_weight` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '实际货物重量(斤)',

  `rule_id` int(10) unsigned NOT NULL COMMENT '规则ID',
  `free_days` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '免费期天数',
  `welfare_days` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '福利损耗期天数',
  `goods_days` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '货物损耗期天数',

  `payment_time` bigint(16) NOT NULL COMMENT '订单付款时间',
  `verify_time` bigint(16) NOT NULL COMMENT '核销时间',
  `createtime` bigint(16) DEFAULT NULL COMMENT '创建时间',
  `updatetime` bigint(16) DEFAULT NULL COMMENT '更新时间',
  `deletetime` bigint(16) DEFAULT NULL COMMENT '删除时间',
  `status` enum('normal','hidden') COLLATE utf8mb4_general_ci DEFAULT 'normal' COMMENT '状态',

  PRIMARY KEY (`id`),
  UNIQUE KEY `voucher_id` (`voucher_id`),
  KEY `voucher_no` (`voucher_no`),
  KEY `order_id` (`order_id`),
  KEY `verification_id` (`verification_id`),
  KEY `user_id` (`user_id`),
  KEY `shop_id` (`shop_id`),
  KEY `stage` (`stage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='核销券返利结算表';
