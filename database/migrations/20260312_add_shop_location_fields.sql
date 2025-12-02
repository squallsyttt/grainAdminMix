-- 为店铺增加导航经纬度与详细地址字段，便于小程序导航
ALTER TABLE `grain_wanlshop_shop`
ADD COLUMN `location_latitude` DECIMAL(10,6) NULL DEFAULT NULL COMMENT '店铺纬度（WGS84）' AFTER `city`,
ADD COLUMN `location_longitude` DECIMAL(10,6) NULL DEFAULT NULL COMMENT '店铺经度（WGS84）' AFTER `location_latitude`,
ADD COLUMN `location_address` VARCHAR(255) NULL DEFAULT NULL COMMENT '店铺详细地址（含路牌/门牌）' AFTER `location_longitude`;

CREATE INDEX `idx_shop_location` ON `grain_wanlshop_shop`(`location_latitude`, `location_longitude`);
