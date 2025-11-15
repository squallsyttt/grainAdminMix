-- GrainAdminMix Voucher System DDL Migration
-- 创建日期: 2025-11-14
-- 版本: v1.0.0-MVP
-- 说明: 根据 .specify/voucher-system-plan.md 第 2.2 节创建核销券相关表结构及支付表修改

CREATE TABLE IF NOT EXISTS `grain_wanlshop_voucher_order` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `user_id` int(10) NOT NULL DEFAULT '0' COMMENT '用户ID',
  `order_no` varchar(18) COLLATE utf8mb4_general_ci NOT NULL COMMENT '订单号',
  `category_id` int(10) unsigned NOT NULL COMMENT '商品分类ID',
  `goods_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '商品ID',
  `coupon_id` int(10) NOT NULL DEFAULT '0' COMMENT '优惠券ID',
  `quantity` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '购买数量',
  `supply_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '供货价（商家结算价）',
  `retail_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '零售价（单价）',
  `coupon_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '优惠券金额',
  `discount_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '优惠金额',
  `actual_payment` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '实际支付',
  `state` enum('1','2','3') COLLATE utf8mb4_general_ci NOT NULL DEFAULT '1' COMMENT '订单状态:1=待支付,2=已支付,3=已取消',
  `remarks` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '订单备注',
  `createtime` bigint(16) DEFAULT NULL COMMENT '创建时间',
  `paymenttime` bigint(16) DEFAULT NULL COMMENT '付款时间',
  `canceltime` bigint(16) DEFAULT NULL COMMENT '取消时间',
  `updatetime` bigint(16) DEFAULT NULL COMMENT '更新时间',
  `deletetime` bigint(16) DEFAULT NULL COMMENT '删除时间',
  `status` enum('normal','hidden') COLLATE utf8mb4_general_ci DEFAULT 'normal' COMMENT '状态',
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_no` (`order_no`),
  KEY `user_id` (`user_id`),
  KEY `category_id` (`category_id`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='核销券订单表';

CREATE TABLE IF NOT EXISTS `grain_wanlshop_voucher` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `voucher_no` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT '核销券号（唯一）',
  `order_id` int(10) unsigned NOT NULL COMMENT '订单ID',
  `user_id` int(10) NOT NULL COMMENT '用户ID',
  `category_id` int(10) unsigned NOT NULL COMMENT '适用分类ID',
  `goods_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '商品ID',
  `goods_title` varchar(255) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '商品标题',
  `goods_image` varchar(255) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '商品图片',
  `supply_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '供货价（商家结算金额）',
  `face_value` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '券面值（用户支付金额）',
  `shop_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '核销店铺ID（核销后填写）',
  `shop_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT '' COMMENT '核销店铺名称',
  `verify_user_id` int(10) unsigned DEFAULT '0' COMMENT '核销操作员ID',
  `verify_code` varchar(6) COLLATE utf8mb4_general_ci NOT NULL COMMENT '核销验证码（6位数字）',
  `state` enum('1','2','3','4') COLLATE utf8mb4_general_ci NOT NULL DEFAULT '1' COMMENT '状态:1=未使用,2=已核销,3=已过期,4=已退款',
  `valid_start` bigint(16) NOT NULL COMMENT '有效期开始时间',
  `valid_end` bigint(16) NOT NULL COMMENT '有效期结束时间',
  `createtime` bigint(16) DEFAULT NULL COMMENT '创建时间',
  `verifytime` bigint(16) DEFAULT NULL COMMENT '核销时间',
  `refundtime` bigint(16) DEFAULT NULL COMMENT '退款时间',
  `updatetime` bigint(16) DEFAULT NULL COMMENT '更新时间',
  `deletetime` bigint(16) DEFAULT NULL COMMENT '删除时间',
  `status` enum('normal','hidden') COLLATE utf8mb4_general_ci DEFAULT 'normal' COMMENT '状态',
  PRIMARY KEY (`id`),
  UNIQUE KEY `voucher_no` (`voucher_no`),
  KEY `order_id` (`order_id`),
  KEY `verify_code` (`verify_code`),
  KEY `user_id` (`user_id`),
  KEY `shop_id` (`shop_id`),
  KEY `category_id` (`category_id`),
  KEY `state` (`state`),
  KEY `valid_end` (`valid_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='核销券表';

CREATE TABLE IF NOT EXISTS `grain_wanlshop_voucher_verification` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `voucher_id` int(10) unsigned NOT NULL COMMENT '核销券ID',
  `voucher_no` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT '核销券号',
  `user_id` int(10) NOT NULL COMMENT '用户ID',
  `shop_id` int(10) unsigned NOT NULL COMMENT '核销店铺ID',
  `shop_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL COMMENT '店铺名称',
  `verify_user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '核销操作员ID',
  `supply_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '供货价（商家结算金额）',
  `face_value` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '券面值（用户支付金额）',
  `verify_method` enum('code','scan') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'code' COMMENT '核销方式:code=验证码,scan=扫码',
  `remarks` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '备注',
  `createtime` bigint(16) DEFAULT NULL COMMENT '核销时间',
  `updatetime` bigint(16) DEFAULT NULL COMMENT '更新时间',
  `deletetime` bigint(16) DEFAULT NULL COMMENT '删除时间',
  `status` enum('normal','hidden') COLLATE utf8mb4_general_ci DEFAULT 'normal' COMMENT '状态',
  PRIMARY KEY (`id`),
  KEY `voucher_id` (`voucher_id`),
  KEY `shop_id` (`shop_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='核销记录表';

CREATE TABLE IF NOT EXISTS `grain_wanlshop_voucher_settlement` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `settlement_no` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT '结算单号',
  `voucher_id` int(10) unsigned NOT NULL COMMENT '核销券ID',
  `voucher_no` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT '核销券号',
  `order_id` int(10) unsigned NOT NULL COMMENT '订单ID',
  `shop_id` int(10) unsigned NOT NULL COMMENT '店铺ID',
  `shop_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL COMMENT '店铺名称',
  `user_id` int(10) NOT NULL COMMENT '用户ID',
  `retail_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '零售价（用户支付金额）',
  `supply_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '供货价（商家结算金额）',
  `platform_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '平台利润（零售价-供货价）',
  `shop_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '商家结算金额（=供货价）',
  `state` enum('1','2') COLLATE utf8mb4_general_ci NOT NULL DEFAULT '1' COMMENT '状态:1=待结算,2=已结算',
  `settlement_time` bigint(16) DEFAULT NULL COMMENT '结算时间',
  `remarks` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '备注',
  `createtime` bigint(16) DEFAULT NULL COMMENT '创建时间',
  `updatetime` bigint(16) DEFAULT NULL COMMENT '更新时间',
  `deletetime` bigint(16) DEFAULT NULL COMMENT '删除时间',
  `status` enum('normal','hidden') COLLATE utf8mb4_general_ci DEFAULT 'normal' COMMENT '状态',
  PRIMARY KEY (`id`),
  UNIQUE KEY `settlement_no` (`settlement_no`),
  KEY `voucher_id` (`voucher_id`),
  KEY `shop_id` (`shop_id`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='核销券结算表';

CREATE TABLE IF NOT EXISTS `grain_wanlshop_voucher_refund` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `refund_no` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT '退款单号',
  `voucher_id` int(10) unsigned NOT NULL COMMENT '核销券ID',
  `voucher_no` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT '核销券号',
  `order_id` int(10) unsigned NOT NULL COMMENT '订单ID',
  `user_id` int(10) NOT NULL COMMENT '用户ID',
  `refund_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '退款金额',
  `refund_reason` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '退款理由',
  `refuse_reason` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '拒绝理由',
  `state` enum('0','1','2','3') COLLATE utf8mb4_general_ci NOT NULL DEFAULT '0' COMMENT '状态:0=申请中,1=同意退款,2=拒绝退款,3=退款成功',
  `createtime` bigint(16) DEFAULT NULL COMMENT '创建时间',
  `updatetime` bigint(16) DEFAULT NULL COMMENT '更新时间',
  `deletetime` bigint(16) DEFAULT NULL COMMENT '删除时间',
  `status` enum('normal','hidden') COLLATE utf8mb4_general_ci DEFAULT 'normal' COMMENT '状态',
  PRIMARY KEY (`id`),
  UNIQUE KEY `refund_no` (`refund_no`),
  KEY `voucher_id` (`voucher_id`),
  KEY `order_id` (`order_id`),
  KEY `user_id` (`user_id`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='核销券退款表';

-- 扩展支付类型，增加 voucher
ALTER TABLE `grain_wanlshop_pay`
MODIFY COLUMN `type` enum('goods','groups','voucher') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'goods'
COMMENT '订单类型:goods=商品订单,groups=拼团订单,voucher=核销券订单';
