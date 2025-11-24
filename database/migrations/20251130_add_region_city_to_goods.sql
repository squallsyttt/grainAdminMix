-- 为商品增加地级市发布维度（默认不限制，shop_id=1 可按市设置）
ALTER TABLE `grain_wanlshop_goods`
ADD COLUMN `region_city_code` VARCHAR(12) NULL DEFAULT NULL COMMENT '可售城市编码（地级市，GB/T2260）' AFTER `shop_category_id`,
ADD COLUMN `region_city_name` VARCHAR(50) NULL DEFAULT NULL COMMENT '可售城市名称（地级市中文）' AFTER `region_city_code`;

CREATE INDEX `idx_region_city_code` ON `grain_wanlshop_goods`(`region_city_code`);
