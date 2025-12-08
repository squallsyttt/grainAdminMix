-- 核销券代管理字段迁移
-- 创建日期: 2025-12-08
-- 说明: 在 grain_wanlshop_voucher 表新增代管理相关字段

ALTER TABLE `grain_wanlshop_voucher`
ADD COLUMN `custody_state` enum('0','1','2','3') NOT NULL DEFAULT '0'
    COMMENT '代管理状态:0=未申请,1=申请中,2=已通过,3=已拒绝' AFTER `rule_id`,
ADD COLUMN `custody_apply_time` bigint(16) DEFAULT NULL
    COMMENT '代管理申请时间' AFTER `custody_state`,
ADD COLUMN `custody_audit_time` bigint(16) DEFAULT NULL
    COMMENT '代管理审核时间' AFTER `custody_apply_time`,
ADD COLUMN `custody_admin_id` int(10) unsigned DEFAULT NULL
    COMMENT '审核管理员ID' AFTER `custody_audit_time`,
ADD COLUMN `custody_refuse_reason` varchar(500) DEFAULT NULL
    COMMENT '代管理拒绝理由' AFTER `custody_admin_id`,
ADD COLUMN `custody_platform_price` decimal(10,2) unsigned DEFAULT NULL
    COMMENT '申请时平台基准价（快照）' AFTER `custody_refuse_reason`,
ADD COLUMN `custody_estimated_rebate` decimal(10,2) unsigned DEFAULT NULL
    COMMENT '预估返利金额' AFTER `custody_platform_price`,
ADD INDEX `idx_custody_state` (`custody_state`);
