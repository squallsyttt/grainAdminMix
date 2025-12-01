-- 邀请返利双轨制 - 回滚脚本
-- 创建日期: 2025-12-01
-- 说明: 恢复 grain_user_invite_reward 原语义，移除返利流水表与新增字段

-- 1) 移除用户表新增字段
ALTER TABLE `grain_user`
  DROP COLUMN `invite_bind_time`;

-- 2) 恢复邀请奖励表结构（覆盖式重建）
DROP TABLE IF EXISTS `grain_user_invite_reward`;
CREATE TABLE `grain_user_invite_reward` (
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
  `updatetime` bigint(16) DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_invitee_id` (`invitee_id`),
  KEY `idx_verification_id` (`verification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='邀请奖励变更记录';

-- 3) 将升级日志回填回旧表结构
INSERT INTO `grain_user_invite_reward` (`id`,`user_id`,`invitee_id`,`verification_id`,`voucher_id`,`before_level`,`after_level`,`before_ratio`,`after_ratio`,`createtime`,`updatetime`)
SELECT `id`,`user_id`,`invitee_id`,`verification_id`,`voucher_id`,`before_level`,`after_level`,`before_ratio`,`after_ratio`,`createtime`,NULL
FROM `grain_user_invite_upgrade_log`;

-- 4) 清理新增表
DROP TABLE IF EXISTS `grain_user_cashback_log`;
DROP TABLE IF EXISTS `grain_user_invite_upgrade_log`;
