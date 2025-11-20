-- GrainAdminMix Voucher Order Items Migration
-- 创建日期: 2025-11-25
-- 说明: 为核销券订单增加明细表，支持多商品下单与核销券生成

CREATE TABLE IF NOT EXISTS `grain_wanlshop_voucher_order_item` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `order_id` int(10) unsigned NOT NULL COMMENT '订单ID',
  `goods_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '商品ID',
  `category_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '商品分类ID',
  `goods_title` varchar(255) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '商品标题快照',
  `goods_image` varchar(255) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '商品图片快照',
  `quantity` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '购买数量',
  `supply_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '供货价总额',
  `retail_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '零售价总额',
  `actual_payment` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '实付总额',
  `createtime` bigint(16) DEFAULT NULL COMMENT '创建时间',
  `updatetime` bigint(16) DEFAULT NULL COMMENT '更新时间',
  `deletetime` bigint(16) DEFAULT NULL COMMENT '删除时间',
  `status` enum('normal','hidden') COLLATE utf8mb4_general_ci DEFAULT 'normal' COMMENT '状态',
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `goods_id` (`goods_id`),
  KEY `category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='核销券订单明细表（支持多商品）';
