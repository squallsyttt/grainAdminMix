# 数据库迁移汇总文档

**起始提交**: `936a0228099ee20d3c7ec190b4416f30afa8476b` (2025-12-08 统计大看板功能)
**截止提交**: `957a5d2` (main 分支 HEAD)
**生成日期**: 2025-12-11

---

## 执行顺序

按以下顺序执行 SQL，确保依赖关系正确：

1. `20251208_add_voucher_custody_fields.sql` - 代管理字段
2. `20251208_add_voucher_custody_menu.sql` - 代管理菜单
3. `20251208_add_rebate_type_field.sql` - 返利类型字段
4. `20251208_add_custody_transfer_order_type.sql` - 支付回调类型扩展
5. `shop_invite_rebate.sql` - 店铺邀请返利功能（含建表和菜单）
6. `20251211_custody_refund_fields.sql` - 代管理退款字段

---

## 一、表结构变更

### 1. grain_wanlshop_voucher（核销券表）

**新增字段**（代管理功能）：

```sql
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
```

---

### 2. grain_wanlshop_voucher_rebate（返利表）

**变更 1**：新增返利类型字段

```sql
ALTER TABLE `grain_wanlshop_voucher_rebate`
ADD COLUMN `rebate_type` enum('normal','custody') NOT NULL DEFAULT 'normal'
    COMMENT '返利类型:normal=核销返利,custody=代管理返利' AFTER `voucher_no`,
ADD INDEX `idx_rebate_type` (`rebate_type`);
```

**变更 2**：扩展返利类型枚举（店铺邀请）

```sql
ALTER TABLE `grain_wanlshop_voucher_rebate`
MODIFY COLUMN `rebate_type` ENUM('normal','custody','shop_invite') NOT NULL DEFAULT 'normal'
    COMMENT '返利类型：normal=核销返利，custody=代管理返利，shop_invite=店铺邀请返利';
```

**变更 3**：新增店铺邀请字段

```sql
ALTER TABLE `grain_wanlshop_voucher_rebate`
ADD COLUMN `invite_shop_id` INT(10) UNSIGNED DEFAULT NULL
    COMMENT '邀请的店铺ID（仅shop_invite类型）' AFTER `shop_id`,
ADD COLUMN `invite_shop_name` VARCHAR(100) DEFAULT ''
    COMMENT '邀请的店铺名称（仅shop_invite类型）' AFTER `invite_shop_id`,
ADD INDEX `idx_invite_shop_id` (`invite_shop_id`);
```

**变更 4**：新增店铺邀请返利比例字段

```sql
ALTER TABLE `grain_wanlshop_voucher_rebate`
ADD COLUMN `bonus_ratio` DECIMAL(5,2) UNSIGNED NOT NULL DEFAULT 0.00
    COMMENT '店铺邀请返利比例%' AFTER `rebate_amount`;
```

**变更 5**：新增代管理退款字段

```sql
ALTER TABLE `grain_wanlshop_voucher_rebate`
ADD COLUMN `refund_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00'
    COMMENT '等量退款金额（代管理返利专用）' AFTER `bonus_ratio`,
ADD COLUMN `unit_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00'
    COMMENT '货物单价（元/斤）' AFTER `refund_amount`,
ADD COLUMN `custody_refund_id` int(10) unsigned DEFAULT NULL
    COMMENT '关联的代管理退款记录ID' AFTER `unit_price`,
ADD COLUMN `custody_refund_status` enum('none','pending','success','failed') NOT NULL DEFAULT 'none'
    COMMENT '代管理退款状态:none=无退款,pending=退款中,success=退款成功,failed=退款失败' AFTER `custody_refund_id`,
ADD INDEX `idx_custody_refund_status` (`custody_refund_status`);
```

---

### 3. grain_wanlshop_voucher_refund（退款表）

**新增字段**（代管理退款来源）：

```sql
ALTER TABLE `grain_wanlshop_voucher_refund`
ADD COLUMN `refund_source` enum('user','custody') NOT NULL DEFAULT 'user'
    COMMENT '退款来源:user=用户申请,custody=代管理退款' AFTER `state`,
ADD COLUMN `rebate_id` int(10) unsigned DEFAULT NULL
    COMMENT '关联的返利记录ID（代管理退款专用）' AFTER `refund_source`,
ADD INDEX `idx_refund_source` (`refund_source`),
ADD INDEX `idx_rebate_id` (`rebate_id`);
```

---

### 4. grain_wanlshop_payment_callback_log（支付回调日志表）

**枚举扩展**：

```sql
ALTER TABLE `grain_wanlshop_payment_callback_log`
MODIFY COLUMN `order_type` enum('goods','voucher','groups','voucher_refund','voucher_transfer','rebate_transfer','custody_transfer') NOT NULL DEFAULT 'goods'
    COMMENT '订单类型:goods=商品订单,voucher=核销券订单,voucher_refund=核销券退款,voucher_transfer=核销券结算转账回调,rebate_transfer=核销券返利转账回调,custody_transfer=代管理返利转账回调,groups=拼团订单';
```

---

### 5. grain_wanlshop_shop（店铺表）

**新增字段**（邀请人信息）：

```sql
ALTER TABLE `grain_wanlshop_shop`
ADD COLUMN `inviter_id` INT(10) UNSIGNED DEFAULT NULL
    COMMENT '邀请人用户ID' AFTER `user_id`,
ADD COLUMN `invite_bind_time` BIGINT(16) DEFAULT NULL
    COMMENT '邀请码绑定时间' AFTER `inviter_id`,
ADD INDEX `idx_inviter_id` (`inviter_id`);
```

---

### 6. grain_wanlshop_auth（入驻申请表）

**新增字段**：

```sql
ALTER TABLE `grain_wanlshop_auth`
ADD COLUMN `invite_code` VARCHAR(20) DEFAULT ''
    COMMENT '邀请码（暂存，审核通过后同步到店铺表）' AFTER `city`;
```

---

## 二、新建表

### 1. grain_shop_invite_pending（店铺邀请待审核表）

```sql
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
    UNIQUE INDEX `uk_shop_first` (`shop_id`, `state`) COMMENT '确保每店仅一条待审核记录'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='店铺邀请返利待审核队列';
```

---

### 2. grain_shop_invite_rebate_log（店铺邀请返利记录表）

```sql
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
```

---

### 3. grain_shop_invite_upgrade_log（店铺邀请升级记录表）

```sql
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
```

---

## 三、菜单权限变更

### 1. 代管理审核菜单

```sql
-- 代管理菜单权限（挂在 wanlshop/voucher 下）
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `ismenu`, `weigh`, `status`)
SELECT 'menu', id, 'wanlshop/voucher/custody', '代管理审核', 'fa fa-hand-paper-o', 1, 0, 'normal'
FROM `grain_auth_rule` WHERE `name` = 'wanlshop/voucher';

-- 子操作权限
SET @custody_pid = LAST_INSERT_ID();
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `ismenu`, `weigh`, `status`) VALUES
('file', @custody_pid, 'wanlshop/voucher/custody/index', '查看', 0, 0, 'normal'),
('file', @custody_pid, 'wanlshop/voucher/custody/approve', '审核通过', 0, 0, 'normal'),
('file', @custody_pid, 'wanlshop/voucher/custody/reject', '审核拒绝', 0, 0, 'normal'),
('file', @custody_pid, 'wanlshop/voucher/custody/detail', '详情', 0, 0, 'normal');
```

---

### 2. 店铺邀请返利菜单

```sql
-- 获取父菜单ID
SET @voucher_pid = (SELECT id FROM grain_auth_rule WHERE name = 'wanlshop/voucher' LIMIT 1);

-- 主菜单
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES ('file', @voucher_pid, 'wanlshop/voucher/shop_invite_rebate', '店铺邀请返利', 'fa fa-user-plus', '', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');

SET @shop_invite_pid = (SELECT id FROM grain_auth_rule WHERE name = 'wanlshop/voucher/shop_invite_rebate' LIMIT 1);

-- 子菜单和权限
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`) VALUES
('file', @shop_invite_pid, 'wanlshop/voucher/shop_invite_rebate/pending', '待审核', 'fa fa-clock-o', '', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 10, 'normal'),
('file', @shop_invite_pid, 'wanlshop/voucher/shop_invite_rebate/index', '打款管理', 'fa fa-money', '', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @shop_invite_pid, 'wanlshop/voucher/shop_invite_rebate/grantrebate', '发放返利', 'fa fa-check', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @shop_invite_pid, 'wanlshop/voucher/shop_invite_rebate/transfer', '打款', 'fa fa-send', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @shop_invite_pid, 'wanlshop/voucher/shop_invite_rebate/cancel', '取消', 'fa fa-times', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');
```

---

## 四、合并后的完整迁移脚本

将以上所有变更合并为一个可执行的脚本：

```sql
-- ============================================
-- 数据库迁移脚本（合并版）
-- 起始提交: 936a0228
-- 执行前请先备份数据库！
-- ============================================
SET autocommit = 0;
START TRANSACTION;
-- 如执行出错请执行: ROLLBACK;

-- 1. 核销券表 - 代管理字段
ALTER TABLE `grain_wanlshop_voucher`
ADD COLUMN IF NOT EXISTS `custody_state` enum('0','1','2','3') NOT NULL DEFAULT '0'
    COMMENT '代管理状态:0=未申请,1=申请中,2=已通过,3=已拒绝' AFTER `rule_id`,
ADD COLUMN IF NOT EXISTS `custody_apply_time` bigint(16) DEFAULT NULL
    COMMENT '代管理申请时间' AFTER `custody_state`,
ADD COLUMN IF NOT EXISTS `custody_audit_time` bigint(16) DEFAULT NULL
    COMMENT '代管理审核时间' AFTER `custody_apply_time`,
ADD COLUMN IF NOT EXISTS `custody_admin_id` int(10) unsigned DEFAULT NULL
    COMMENT '审核管理员ID' AFTER `custody_audit_time`,
ADD COLUMN IF NOT EXISTS `custody_refuse_reason` varchar(500) DEFAULT NULL
    COMMENT '代管理拒绝理由' AFTER `custody_admin_id`,
ADD COLUMN IF NOT EXISTS `custody_platform_price` decimal(10,2) unsigned DEFAULT NULL
    COMMENT '申请时平台基准价（快照）' AFTER `custody_refuse_reason`,
ADD COLUMN IF NOT EXISTS `custody_estimated_rebate` decimal(10,2) unsigned DEFAULT NULL
    COMMENT '预估返利金额' AFTER `custody_platform_price`,
ADD INDEX IF NOT EXISTS `idx_custody_state` (`custody_state`);

-- 2. 返利表 - 返利类型（先加字段，再扩展枚举）
ALTER TABLE `grain_wanlshop_voucher_rebate`
ADD COLUMN IF NOT EXISTS `rebate_type` enum('normal','custody','shop_invite') NOT NULL DEFAULT 'normal'
    COMMENT '返利类型:normal=核销返利,custody=代管理返利,shop_invite=店铺邀请返利' AFTER `voucher_no`,
ADD INDEX IF NOT EXISTS `idx_rebate_type` (`rebate_type`);

-- 3. 返利表 - 店铺邀请字段
ALTER TABLE `grain_wanlshop_voucher_rebate`
ADD COLUMN IF NOT EXISTS `invite_shop_id` INT(10) UNSIGNED DEFAULT NULL
    COMMENT '邀请的店铺ID（仅shop_invite类型）' AFTER `shop_id`,
ADD COLUMN IF NOT EXISTS `invite_shop_name` VARCHAR(100) DEFAULT ''
    COMMENT '邀请的店铺名称（仅shop_invite类型）' AFTER `invite_shop_id`,
ADD INDEX IF NOT EXISTS `idx_invite_shop_id` (`invite_shop_id`);

-- 4. 返利表 - 店铺邀请返利比例
ALTER TABLE `grain_wanlshop_voucher_rebate`
ADD COLUMN IF NOT EXISTS `bonus_ratio` decimal(5,2) unsigned NOT NULL DEFAULT '0.00'
    COMMENT '店铺邀请返利比例%' AFTER `rebate_amount`;

-- 5. 返利表 - 代管理退款字段
ALTER TABLE `grain_wanlshop_voucher_rebate`
ADD COLUMN IF NOT EXISTS `refund_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00'
    COMMENT '等量退款金额（代管理返利专用）' AFTER `bonus_ratio`,
ADD COLUMN IF NOT EXISTS `unit_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00'
    COMMENT '货物单价（元/斤）' AFTER `refund_amount`,
ADD COLUMN IF NOT EXISTS `custody_refund_id` int(10) unsigned DEFAULT NULL
    COMMENT '关联的代管理退款记录ID' AFTER `unit_price`,
ADD COLUMN IF NOT EXISTS `custody_refund_status` enum('none','pending','success','failed') NOT NULL DEFAULT 'none'
    COMMENT '代管理退款状态:none=无退款,pending=退款中,success=退款成功,failed=退款失败' AFTER `custody_refund_id`,
ADD INDEX IF NOT EXISTS `idx_custody_refund_status` (`custody_refund_status`);

-- 6. 退款表 - 退款来源
ALTER TABLE `grain_wanlshop_voucher_refund`
ADD COLUMN IF NOT EXISTS `refund_source` enum('user','custody') NOT NULL DEFAULT 'user'
    COMMENT '退款来源:user=用户申请,custody=代管理退款' AFTER `state`,
ADD COLUMN IF NOT EXISTS `rebate_id` int(10) unsigned DEFAULT NULL
    COMMENT '关联的返利记录ID（代管理退款专用）' AFTER `refund_source`,
ADD INDEX IF NOT EXISTS `idx_refund_source` (`refund_source`),
ADD INDEX IF NOT EXISTS `idx_rebate_id` (`rebate_id`);

-- 7. 支付回调日志 - 扩展类型枚举
ALTER TABLE `grain_wanlshop_payment_callback_log`
MODIFY COLUMN `order_type` enum('goods','voucher','groups','voucher_refund','voucher_transfer','rebate_transfer','custody_transfer') NOT NULL DEFAULT 'goods'
    COMMENT '订单类型:goods=商品订单,voucher=核销券订单,voucher_refund=核销券退款,voucher_transfer=核销券结算转账回调,rebate_transfer=核销券返利转账回调,custody_transfer=代管理返利转账回调,groups=拼团订单';

-- 8. 店铺表 - 邀请人字段
ALTER TABLE `grain_wanlshop_shop`
ADD COLUMN IF NOT EXISTS `inviter_id` INT(10) UNSIGNED DEFAULT NULL
    COMMENT '邀请人用户ID' AFTER `user_id`,
ADD COLUMN IF NOT EXISTS `invite_bind_time` BIGINT(16) DEFAULT NULL
    COMMENT '邀请码绑定时间' AFTER `inviter_id`,
ADD INDEX IF NOT EXISTS `idx_inviter_id` (`inviter_id`);

-- 9. 入驻申请表 - 邀请码字段
ALTER TABLE `grain_wanlshop_auth`
ADD COLUMN IF NOT EXISTS `invite_code` VARCHAR(20) DEFAULT ''
    COMMENT '邀请码（暂存，审核通过后同步到店铺表）' AFTER `city`;

-- 10. 新建表 - 店铺邀请待审核
CREATE TABLE IF NOT EXISTS `grain_shop_invite_pending` (
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
    UNIQUE INDEX `uk_shop_first` (`shop_id`, `state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='店铺邀请返利待审核队列';

-- 11. 新建表 - 店铺邀请返利记录
CREATE TABLE IF NOT EXISTS `grain_shop_invite_rebate_log` (
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
    UNIQUE INDEX `uk_shop_rebate` (`shop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='店铺邀请返利记录';

-- 12. 新建表 - 店铺邀请升级记录
CREATE TABLE IF NOT EXISTS `grain_shop_invite_upgrade_log` (
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
    UNIQUE INDEX `uk_user_shop` (`user_id`, `shop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='店铺邀请升级记录';

-- 13. 菜单权限 - 代管理审核
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `ismenu`, `weigh`, `status`)
SELECT 'menu', id, 'wanlshop/voucher/custody', '代管理审核', 'fa fa-hand-paper-o', 1, 0, 'normal'
FROM `grain_auth_rule` WHERE `name` = 'wanlshop/voucher';

SET @custody_pid = LAST_INSERT_ID();
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `ismenu`, `weigh`, `status`) VALUES
('file', @custody_pid, 'wanlshop/voucher/custody/index', '查看', 0, 0, 'normal'),
('file', @custody_pid, 'wanlshop/voucher/custody/approve', '审核通过', 0, 0, 'normal'),
('file', @custody_pid, 'wanlshop/voucher/custody/reject', '审核拒绝', 0, 0, 'normal'),
('file', @custody_pid, 'wanlshop/voucher/custody/detail', '详情', 0, 0, 'normal');

-- 14. 菜单权限 - 店铺邀请返利
SET @voucher_pid = (SELECT id FROM grain_auth_rule WHERE name = 'wanlshop/voucher' LIMIT 1);

INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES ('file', @voucher_pid, 'wanlshop/voucher/shop_invite_rebate', '店铺邀请返利', 'fa fa-user-plus', '', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');

SET @shop_invite_pid = (SELECT id FROM grain_auth_rule WHERE name = 'wanlshop/voucher/shop_invite_rebate' LIMIT 1);

INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`) VALUES
('file', @shop_invite_pid, 'wanlshop/voucher/shop_invite_rebate/pending', '待审核', 'fa fa-clock-o', '', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 10, 'normal'),
('file', @shop_invite_pid, 'wanlshop/voucher/shop_invite_rebate/index', '打款管理', 'fa fa-money', '', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @shop_invite_pid, 'wanlshop/voucher/shop_invite_rebate/grantrebate', '发放返利', 'fa fa-check', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @shop_invite_pid, 'wanlshop/voucher/shop_invite_rebate/transfer', '打款', 'fa fa-send', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @shop_invite_pid, 'wanlshop/voucher/shop_invite_rebate/cancel', '取消', 'fa fa-times', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');

-- ============================================
-- 迁移完成！
-- ============================================
COMMIT;
```

---

## 五、验证脚本

执行完迁移后，运行以下 SQL 验证变更：

```sql
-- 验证表结构
DESCRIBE grain_wanlshop_voucher;
DESCRIBE grain_wanlshop_voucher_rebate;
DESCRIBE grain_wanlshop_voucher_refund;
DESCRIBE grain_wanlshop_payment_callback_log;
DESCRIBE grain_wanlshop_shop;
DESCRIBE grain_wanlshop_auth;

-- 验证新增字段与索引（信息_schema）
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'grain_wanlshop_voucher_rebate'
  AND COLUMN_NAME IN ('rebate_type','invite_shop_id','invite_shop_name','bonus_ratio','refund_amount','unit_price','custody_refund_id','custody_refund_status');

SHOW INDEX FROM grain_wanlshop_voucher_rebate WHERE Key_name IN ('idx_rebate_type','idx_invite_shop_id','idx_custody_refund_status');
SHOW INDEX FROM grain_shop_invite_pending WHERE Key_name = 'uk_shop_first';
SHOW TABLES LIKE 'grain_shop_invite%';

-- 验证菜单权限
SELECT * FROM grain_auth_rule WHERE name LIKE '%custody%' OR name LIKE '%shop_invite%';
```

---

## 六、注意事项

1. **执行前备份数据库**
2. 如果线上已部分执行过迁移，需先检查表结构避免重复执行
3. 菜单权限需要在后台「权限管理」中给管理员分配
4. 新增字段均有默认值，不影响现有数据

---

## 七、线上执行检查清单

1. 确认已在从库/备份库演练并验证结构一致（本次已验证线上结构完全匹配）
2. 停应用写入或确保单节点维护窗口，备份数据库
3. 在同一事务内执行「合并版迁移脚本」，若报错立即 ROLLBACK 并定位
4. 复核验证脚本输出：字段/索引/表名及唯一索引 `uk_shop_first` 是否存在
5. 登录后台为相关角色分配新增菜单权限
6. 恢复业务写入并做一次功能回归（店铺邀请返利、代管理退款）
