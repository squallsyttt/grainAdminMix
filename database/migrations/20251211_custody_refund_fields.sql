-- 代管理等量退款功能字段迁移
-- 创建日期: 2025-12-11
-- 说明: 为代管理返利增加等量退款相关字段

-- 1. 为返利表增加等量退款相关字段
ALTER TABLE `grain_wanlshop_voucher_rebate`
ADD COLUMN `refund_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00'
    COMMENT '等量退款金额（代管理返利专用）' AFTER `rebate_amount`,
ADD COLUMN `unit_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00'
    COMMENT '货物单价（元/斤）' AFTER `refund_amount`,
ADD COLUMN `custody_refund_id` int(10) unsigned DEFAULT NULL
    COMMENT '关联的代管理退款记录ID' AFTER `unit_price`,
ADD COLUMN `custody_refund_status` enum('none','pending','success','failed') NOT NULL DEFAULT 'none'
    COMMENT '代管理退款状态:none=无退款,pending=退款中,success=退款成功,failed=退款失败' AFTER `custody_refund_id`;

-- 2. 为退款表增加退款来源类型字段（区分普通退款和代管理退款）
ALTER TABLE `grain_wanlshop_voucher_refund`
ADD COLUMN `refund_source` enum('user','custody') NOT NULL DEFAULT 'user'
    COMMENT '退款来源:user=用户申请,custody=代管理退款' AFTER `state`,
ADD COLUMN `rebate_id` int(10) unsigned DEFAULT NULL
    COMMENT '关联的返利记录ID（代管理退款专用）' AFTER `refund_source`;

-- 3. 添加索引
ALTER TABLE `grain_wanlshop_voucher_rebate`
ADD INDEX `idx_custody_refund_status` (`custody_refund_status`);

ALTER TABLE `grain_wanlshop_voucher_refund`
ADD INDEX `idx_refund_source` (`refund_source`),
ADD INDEX `idx_rebate_id` (`rebate_id`);
