-- ============================================
-- 店铺邀请返利功能 - 数据库迁移脚本
-- 创建时间：2025-12-09
-- ============================================

-- 1. 扩展返利表 rebate_type 枚举，新增 shop_invite 类型
ALTER TABLE `grain_wanlshop_voucher_rebate`
MODIFY COLUMN `rebate_type` ENUM('normal','custody','shop_invite') NOT NULL DEFAULT 'normal' COMMENT '返利类型：normal=核销返利，custody=代管理返利，shop_invite=店铺邀请返利';

-- 2. 为返利表新增店铺邀请专用字段
ALTER TABLE `grain_wanlshop_voucher_rebate`
ADD COLUMN `invite_shop_id` INT(10) UNSIGNED DEFAULT NULL COMMENT '邀请的店铺ID（仅shop_invite类型）' AFTER `shop_id`,
ADD COLUMN `invite_shop_name` VARCHAR(100) DEFAULT '' COMMENT '邀请的店铺名称（仅shop_invite类型）' AFTER `invite_shop_id`,
ADD INDEX `idx_invite_shop_id` (`invite_shop_id`);

-- 3. 修改店铺表，新增邀请人字段
ALTER TABLE `grain_wanlshop_shop`
ADD COLUMN `inviter_id` INT(10) UNSIGNED DEFAULT NULL COMMENT '邀请人用户ID' AFTER `user_id`,
ADD COLUMN `invite_bind_time` BIGINT(16) DEFAULT NULL COMMENT '邀请码绑定时间' AFTER `inviter_id`,
ADD INDEX `idx_inviter_id` (`inviter_id`);

-- 4. 修改入驻申请表，新增邀请码暂存字段
ALTER TABLE `grain_wanlshop_auth`
ADD COLUMN `invite_code` VARCHAR(20) DEFAULT '' COMMENT '邀请码（暂存，审核通过后同步到店铺表）' AFTER `city`;

-- 5. 创建店铺邀请待审核表
CREATE TABLE `grain_shop_invite_pending` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `shop_id` INT(10) UNSIGNED NOT NULL COMMENT '被邀请店铺ID',
    `inviter_id` INT(10) UNSIGNED NOT NULL COMMENT '邀请人用户ID',
    `verification_id` INT(10) UNSIGNED NOT NULL COMMENT '核销记录ID',
    `voucher_id` INT(10) UNSIGNED NOT NULL COMMENT '券ID',
    `user_id` INT(10) UNSIGNED NOT NULL COMMENT '核销用户ID',
    `supply_price` DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00 COMMENT '供货价（返利基数）',
    `verify_time` BIGINT(16) NOT NULL COMMENT '核销时间',
    `state` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=待审核 1=已发放 2=已取消（退款）',
    `process_time` BIGINT(16) DEFAULT NULL COMMENT '处理时间',
    `admin_id` INT(10) UNSIGNED DEFAULT NULL COMMENT '操作管理员ID',
    `createtime` BIGINT(16) DEFAULT NULL,
    `updatetime` BIGINT(16) DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_shop_id` (`shop_id`),
    INDEX `idx_inviter_id` (`inviter_id`),
    INDEX `idx_state` (`state`),
    UNIQUE INDEX `uk_shop_state_pending` (`shop_id`, `state`) COMMENT '确保每店仅一条待审核记录'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='店铺邀请返利待审核队列';

-- 6. 创建店铺邀请返利记录表
CREATE TABLE `grain_shop_invite_rebate_log` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `inviter_id` INT(10) UNSIGNED NOT NULL COMMENT '邀请人用户ID',
    `shop_id` INT(10) UNSIGNED NOT NULL COMMENT '被邀请店铺ID',
    `shop_name` VARCHAR(100) DEFAULT '' COMMENT '店铺名称',
    `verification_id` INT(10) UNSIGNED NOT NULL COMMENT '核销记录ID',
    `voucher_id` INT(10) UNSIGNED NOT NULL COMMENT '券ID',
    `user_id` INT(10) UNSIGNED NOT NULL COMMENT '核销用户ID',
    `supply_price` DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00 COMMENT '供货价',
    `rebate_amount` DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00 COMMENT '返利金额',
    `bonus_ratio` DECIMAL(5,2) UNSIGNED NOT NULL DEFAULT 0.00 COMMENT '返利比例%',
    `before_level` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '处理前等级',
    `after_level` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '处理后等级',
    `is_upgrade` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否触发升级 0=否 1=是',
    `pending_id` INT(10) UNSIGNED DEFAULT NULL COMMENT '关联待处理记录ID',
    `createtime` BIGINT(16) DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_inviter_id` (`inviter_id`),
    UNIQUE INDEX `uk_shop_rebate` (`shop_id`) COMMENT '每店仅一次返利'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='店铺邀请返利记录';

-- 7. 创建店铺邀请升级记录表
CREATE TABLE `grain_shop_invite_upgrade_log` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(10) UNSIGNED NOT NULL COMMENT '被升级的用户ID（邀请人）',
    `shop_id` INT(10) UNSIGNED NOT NULL COMMENT '触发升级的店铺ID',
    `verification_id` INT(10) UNSIGNED DEFAULT NULL COMMENT '核销记录ID',
    `voucher_id` INT(10) UNSIGNED DEFAULT NULL COMMENT '券ID',
    `before_level` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '升级前等级',
    `after_level` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '升级后等级',
    `before_ratio` DECIMAL(5,2) UNSIGNED NOT NULL DEFAULT 0.00 COMMENT '升级前比例',
    `after_ratio` DECIMAL(5,2) UNSIGNED NOT NULL DEFAULT 0.00 COMMENT '升级后比例',
    `createtime` BIGINT(16) DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_user_id` (`user_id`),
    UNIQUE INDEX `uk_user_shop` (`user_id`, `shop_id`) COMMENT '每店每用户仅触发一次升级'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='店铺邀请升级记录';

-- 8. 注册后台菜单
-- 先获取父菜单ID（核销券管理）
SET @voucher_pid = (SELECT id FROM grain_auth_rule WHERE name = 'wanlshop/voucher' LIMIT 1);

-- 插入店铺邀请返利菜单组
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES ('file', @voucher_pid, 'wanlshop/voucher/shop_invite_rebate', '店铺邀请返利', 'fa fa-user-plus', '', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');

SET @shop_invite_pid = (SELECT id FROM grain_auth_rule WHERE name = 'wanlshop/voucher/shop_invite_rebate' LIMIT 1);

-- 待审核子菜单
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES ('file', @shop_invite_pid, 'wanlshop/voucher/shop_invite_rebate/pending', '待审核', 'fa fa-clock-o', '', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 10, 'normal');

-- 打款管理子菜单
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES ('file', @shop_invite_pid, 'wanlshop/voucher/shop_invite_rebate/index', '打款管理', 'fa fa-money', '', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');

-- 发放返利操作权限
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES ('file', @shop_invite_pid, 'wanlshop/voucher/shop_invite_rebate/grantrebate', '发放返利', 'fa fa-check', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');

-- 打款操作权限
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES ('file', @shop_invite_pid, 'wanlshop/voucher/shop_invite_rebate/transfer', '打款', 'fa fa-send', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');

-- 取消操作权限
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES ('file', @shop_invite_pid, 'wanlshop/voucher/shop_invite_rebate/cancel', '取消', 'fa fa-times', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');

-- ============================================
-- 执行完成后请验证：
-- 1. SELECT * FROM grain_auth_rule WHERE name LIKE '%shop_invite%';
-- 2. DESCRIBE grain_wanlshop_voucher_rebate;
-- 3. DESCRIBE grain_wanlshop_shop;
-- 4. SHOW TABLES LIKE 'grain_shop_invite%';
-- ============================================
