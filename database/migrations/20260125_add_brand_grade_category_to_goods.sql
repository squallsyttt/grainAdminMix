-- 商品增加品牌/等级类目可选关联字段
-- 创建日期: 2026-01-25
-- 说明: brand_category_id / grade_category_id 均为可空字段，不影响旧数据

ALTER TABLE `grain_wanlshop_goods`
  ADD COLUMN `brand_category_id` int(10) unsigned NULL DEFAULT NULL COMMENT '品牌类目ID' AFTER `category_id`,
  ADD COLUMN `grade_category_id` int(10) unsigned NULL DEFAULT NULL COMMENT '等级类目ID' AFTER `brand_category_id`;

CREATE INDEX `idx_shop_status_brand_category` ON `grain_wanlshop_goods` (`shop_id`, `status`, `brand_category_id`);
CREATE INDEX `idx_shop_status_grade_category` ON `grain_wanlshop_goods` (`shop_id`, `status`, `grade_category_id`);

