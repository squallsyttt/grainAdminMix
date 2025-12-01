-- 邀请返利双轨制 - 数据表迁移
-- 创建日期: 2025-12-01
-- 说明: 重命名邀请奖励表为返利流水表，迁移历史升级记录，新增用户绑定邀请码时间字段

-- 1) 新建等级升级日志表（存在则复用）
CREATE TABLE IF NOT EXISTS `grain_user_invite_upgrade_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `user_id` int(10) NOT NULL DEFAULT '0' COMMENT '邀请人ID',
  `invitee_id` int(10) NOT NULL DEFAULT '0' COMMENT '被邀请人ID',
  `verification_id` int(10) unsigned DEFAULT NULL COMMENT '核销记录ID',
  `voucher_id` int(10) unsigned DEFAULT NULL COMMENT '核销券ID',
  `before_level` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '变更前等级',
  `after_level` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '变更后等级',
  `before_ratio` decimal(5,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '变更前返利比例',
  `after_ratio` decimal(5,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '变更后返利比例',
  `createtime` bigint(16) DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_invitee_id` (`invitee_id`),
  KEY `idx_verification_id` (`verification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='邀请人等级升级日志';

-- 2) 迁移旧邀请奖励数据至升级日志（保留历史）
INSERT INTO `grain_user_invite_upgrade_log` (`id`,`user_id`,`invitee_id`,`verification_id`,`voucher_id`,`before_level`,`after_level`,`before_ratio`,`after_ratio`,`createtime`)
SELECT `id`,`user_id`,`invitee_id`,`verification_id`,`voucher_id`,`before_level`,`after_level`,`before_ratio`,`after_ratio`,`createtime`
FROM `grain_user_invite_reward`;

-- 3) 重命名旧表为返利流水表
RENAME TABLE `grain_user_invite_reward` TO `grain_user_cashback_log`;

-- 4) 调整返利流水表字段语义，保留 ID 自增
ALTER TABLE `grain_user_cashback_log`
  DROP COLUMN `invitee_id`,
  DROP COLUMN `before_ratio`,
  DROP COLUMN `after_ratio`,
  DROP COLUMN `before_level`,
  DROP COLUMN `after_level`,
  MODIFY COLUMN `user_id` int(10) NOT NULL DEFAULT '0' COMMENT '邀请人ID(核销人)',
  MODIFY COLUMN `voucher_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '核销券ID',
  MODIFY COLUMN `verification_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '核销记录ID',
  MODIFY COLUMN `createtime` bigint(16) DEFAULT NULL COMMENT '创建时间',
  ADD COLUMN `cashback_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '返利金额' AFTER `verification_id`,
  ADD COLUMN `supply_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '供货价' AFTER `cashback_amount`,
  ADD COLUMN `bonus_ratio` decimal(5,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '返利比例(%)' AFTER `supply_price`,
  ADD COLUMN `shop_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '核销店铺ID' AFTER `bonus_ratio`,
  ADD COLUMN `verify_method` enum('code','scan') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'code' COMMENT '核销方式' AFTER `shop_id`,
  ADD COLUMN `updatetime` bigint(16) DEFAULT NULL COMMENT '更新时间' AFTER `createtime`,
  ADD KEY `idx_cashback_user_id` (`user_id`),
  ADD KEY `idx_cashback_verification_id` (`verification_id`);

-- 清空旧语义数据，返利流水从空表开始
TRUNCATE TABLE `grain_user_cashback_log`;

-- 5) 用户表新增绑定邀请码时间
ALTER TABLE `grain_user`
  ADD COLUMN `invite_bind_time` int(10) DEFAULT NULL COMMENT '绑定邀请码时间';
