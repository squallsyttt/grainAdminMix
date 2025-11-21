-- 添加 SKU 相关字段到核销券订单/核销券/订单明细表
-- 执行顺序：在已有表基础上追加，可重复执行（存在即忽略需自行处理）

ALTER TABLE `grain_wanlshop_voucher_order`
    ADD COLUMN `goods_sku_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '商品SKU ID' AFTER `goods_id`,
    ADD COLUMN `sku_difference` varchar(255) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT 'SKU规格快照' AFTER `category_id`,
    ADD COLUMN `sku_weight` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT 'SKU重量' AFTER `sku_difference`,
    ADD KEY `goods_sku_id` (`goods_sku_id`);

ALTER TABLE `grain_wanlshop_voucher_order_item`
    ADD COLUMN `goods_sku_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '商品SKU ID' AFTER `goods_id`,
    ADD COLUMN `sku_difference` varchar(255) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT 'SKU规格快照' AFTER `goods_image`,
    ADD COLUMN `sku_weight` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT 'SKU重量' AFTER `sku_difference`,
    ADD KEY `goods_sku_id` (`goods_sku_id`);

ALTER TABLE `grain_wanlshop_voucher`
    ADD COLUMN `goods_sku_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '商品SKU ID' AFTER `goods_id`,
    ADD COLUMN `sku_difference` varchar(255) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT 'SKU规格快照' AFTER `goods_image`,
    ADD COLUMN `sku_weight` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT 'SKU重量' AFTER `sku_difference`,
    ADD KEY `goods_sku_id` (`goods_sku_id`);
