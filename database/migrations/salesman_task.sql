-- ============================================
-- 业务员管理 + 任务模块 数据库迁移脚本
-- 创建时间：2025-12-18
-- 更新时间：2025-12-18 简化方案，使用用户表标记
-- ============================================

-- 1. 在用户表添加业务员标记字段
ALTER TABLE `grain_user`
ADD COLUMN `is_salesman` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否业务员：0=否，1=是' AFTER `invite_bind_time`,
ADD COLUMN `salesman_remark` VARCHAR(255) DEFAULT '' COMMENT '业务员备注' AFTER `is_salesman`,
ADD COLUMN `salesman_admin_id` INT(10) UNSIGNED DEFAULT NULL COMMENT '指定人（管理员ID）' AFTER `salesman_remark`,
ADD INDEX `idx_is_salesman` (`is_salesman`);

-- 2. 任务配置表
CREATE TABLE IF NOT EXISTS `grain_salesman_task` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL COMMENT '任务名称',
    `type` ENUM('user_verify','shop_verify','rebate_amount') NOT NULL COMMENT '任务类型：user_verify=用户核销，shop_verify=商家核销，rebate_amount=返利金额',
    `target_count` INT(10) UNSIGNED DEFAULT 0 COMMENT '目标数量（user_verify/shop_verify类型使用）',
    `target_amount` DECIMAL(10,2) DEFAULT 0.00 COMMENT '目标金额（rebate_amount类型使用）',
    `reward_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '奖励金额',
    `description` TEXT COMMENT '任务描述',
    `status` ENUM('normal','disabled') NOT NULL DEFAULT 'normal' COMMENT '状态：normal=启用，disabled=禁用',
    `weigh` INT(10) DEFAULT 0 COMMENT '排序权重（越大越靠前）',
    `createtime` BIGINT(16) DEFAULT NULL,
    `updatetime` BIGINT(16) DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='业务员任务配置表';

-- 3. 业务员任务进度表
CREATE TABLE IF NOT EXISTS `grain_salesman_task_progress` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(10) UNSIGNED NOT NULL COMMENT '业务员用户ID（关联grain_user.id，is_salesman=1）',
    `task_id` INT(10) UNSIGNED NOT NULL COMMENT '任务ID',
    `current_count` INT(10) UNSIGNED DEFAULT 0 COMMENT '当前完成数量',
    `current_amount` DECIMAL(10,2) DEFAULT 0.00 COMMENT '当前完成金额',
    `state` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=进行中，1=已完成待审核，2=已审核待发放，3=已发放，4=已取消',
    `complete_time` BIGINT(16) DEFAULT NULL COMMENT '完成时间',
    `audit_time` BIGINT(16) DEFAULT NULL COMMENT '审核时间',
    `audit_admin_id` INT(10) UNSIGNED DEFAULT NULL COMMENT '审核管理员ID',
    `audit_remark` VARCHAR(255) DEFAULT '' COMMENT '审核备注',
    `reward_time` BIGINT(16) DEFAULT NULL COMMENT '发放时间',
    `reward_admin_id` INT(10) UNSIGNED DEFAULT NULL COMMENT '发放管理员ID',
    `reward_amount` DECIMAL(10,2) DEFAULT 0.00 COMMENT '实际奖励金额',
    `reward_remark` VARCHAR(255) DEFAULT '' COMMENT '发放备注',
    `createtime` BIGINT(16) DEFAULT NULL,
    `updatetime` BIGINT(16) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uk_user_task` (`user_id`, `task_id`),
    INDEX `idx_state` (`state`),
    INDEX `idx_task_id` (`task_id`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='业务员任务进度表';

-- 4. 业务员统计表
CREATE TABLE IF NOT EXISTS `grain_salesman_stats` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(10) UNSIGNED NOT NULL COMMENT '业务员用户ID（关联grain_user.id，is_salesman=1）',
    `invite_user_count` INT(10) UNSIGNED DEFAULT 0 COMMENT '邀请用户总数',
    `invite_user_verified` INT(10) UNSIGNED DEFAULT 0 COMMENT '邀请用户已核销数',
    `invite_shop_count` INT(10) UNSIGNED DEFAULT 0 COMMENT '邀请商家总数',
    `invite_shop_verified` INT(10) UNSIGNED DEFAULT 0 COMMENT '邀请商家已核销数',
    `total_rebate_amount` DECIMAL(12,2) DEFAULT 0.00 COMMENT '累计返利金额',
    `total_reward_amount` DECIMAL(12,2) DEFAULT 0.00 COMMENT '累计任务奖励金额（已发放）',
    `pending_reward_amount` DECIMAL(12,2) DEFAULT 0.00 COMMENT '待发放奖励金额',
    `updatetime` BIGINT(16) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uk_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='业务员统计表';

-- ============================================
-- 注册后台菜单
-- ============================================

-- 获取 wanlshop 父菜单ID
SET @wanlshop_pid = (SELECT id FROM grain_auth_rule WHERE name = 'wanlshop' LIMIT 1);

-- 插入业务员管理主菜单
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES ('file', @wanlshop_pid, 'wanlshop/salesman', '业务员管理', 'fa fa-user-secret', '', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');

SET @salesman_pid = (SELECT id FROM grain_auth_rule WHERE name = 'wanlshop/salesman' LIMIT 1);

-- 业务员列表
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES ('file', @salesman_pid, 'wanlshop/salesman/salesman/index', '业务员列表', 'fa fa-list', '', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 100, 'normal');

-- 业务员列表操作权限
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES
('file', @salesman_pid, 'wanlshop/salesman/salesman/add', '添加', 'fa fa-plus', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @salesman_pid, 'wanlshop/salesman/salesman/edit', '编辑', 'fa fa-pencil', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @salesman_pid, 'wanlshop/salesman/salesman/del', '删除', 'fa fa-trash', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @salesman_pid, 'wanlshop/salesman/salesman/detail', '详情', 'fa fa-eye', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @salesman_pid, 'wanlshop/salesman/salesman/refreshstats', '刷新统计', 'fa fa-refresh', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');

-- 任务配置
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES ('file', @salesman_pid, 'wanlshop/salesman/task/index', '任务配置', 'fa fa-tasks', '', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 90, 'normal');

-- 任务配置操作权限
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES
('file', @salesman_pid, 'wanlshop/salesman/task/add', '添加', 'fa fa-plus', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @salesman_pid, 'wanlshop/salesman/task/edit', '编辑', 'fa fa-pencil', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @salesman_pid, 'wanlshop/salesman/task/del', '删除', 'fa fa-trash', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');

-- 任务进度
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES ('file', @salesman_pid, 'wanlshop/salesman/progress/index', '任务进度', 'fa fa-line-chart', '', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 80, 'normal');

-- 任务进度操作权限
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES
('file', @salesman_pid, 'wanlshop/salesman/progress/audit', '审核', 'fa fa-check', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @salesman_pid, 'wanlshop/salesman/progress/grant', '发放奖励', 'fa fa-gift', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @salesman_pid, 'wanlshop/salesman/progress/cancel', '取消', 'fa fa-times', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @salesman_pid, 'wanlshop/salesman/progress/batchrefresh', '批量刷新', 'fa fa-refresh', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');

-- 待审核列表
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES ('file', @salesman_pid, 'wanlshop/salesman/progress/pending', '待审核', 'fa fa-clock-o', '', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 70, 'normal');

-- ============================================
-- 执行完成后请验证：
-- 1. SHOW TABLES LIKE 'grain_salesman%';
-- 2. SELECT * FROM grain_auth_rule WHERE name LIKE '%salesman%';
-- ============================================
