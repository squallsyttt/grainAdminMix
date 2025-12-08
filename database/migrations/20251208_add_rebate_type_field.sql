-- 核销券返利类型字段迁移
-- 创建日期: 2025-12-08
-- 说明: 为返利记录增加类型字段，区分普通核销返利和代管理返利

ALTER TABLE `grain_wanlshop_voucher_rebate`
ADD COLUMN `rebate_type` enum('normal','custody') NOT NULL DEFAULT 'normal'
    COMMENT '返利类型:normal=核销返利,custody=代管理返利' AFTER `voucher_no`,
ADD INDEX `idx_rebate_type` (`rebate_type`);
