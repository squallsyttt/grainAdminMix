-- 为核销相关表新增核销店铺商品信息字段
-- 创建日期: 2025-12-02

ALTER TABLE `grain_wanlshop_voucher_verification`
    ADD COLUMN `shop_goods_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '核销店铺商品ID' AFTER `shop_name`,
    ADD COLUMN `shop_goods_title` varchar(255) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '核销店铺商品标题' AFTER `shop_goods_id`;

ALTER TABLE `grain_wanlshop_voucher_settlement`
    ADD COLUMN `shop_goods_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '核销店铺商品ID' AFTER `shop_name`,
    ADD COLUMN `shop_goods_title` varchar(255) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '核销店铺商品标题' AFTER `shop_goods_id`;

ALTER TABLE `grain_wanlshop_voucher_rebate`
    ADD COLUMN `shop_goods_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '核销店铺商品ID' AFTER `goods_title`,
    ADD COLUMN `shop_goods_title` varchar(255) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '核销店铺商品标题' AFTER `shop_goods_id`;
