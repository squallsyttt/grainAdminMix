# 返利模块技术文档

> 最后更新：2025-12-11

## 目录

1. [模块概述](#1-模块概述)
2. [返利类型](#2-返利类型)
3. [数据库表结构](#3-数据库表结构)
4. [返利计算逻辑](#4-返利计算逻辑)
5. [核销返利流程](#5-核销返利流程)
6. [代管理返利流程](#6-代管理返利流程)
7. [店铺邀请返利流程](#7-店铺邀请返利流程)
8. [打款流程](#8-打款流程)
9. [后台管理界面](#9-后台管理界面)
10. [API 接口](#10-api-接口)
11. [配置项](#11-配置项)
12. [文件索引](#12-文件索引)

---

## 1. 模块概述

返利模块是核销券系统的核心组成部分，负责根据用户购买的核销券和核销时间计算返利金额，并通过微信支付将返利款项打给用户。

### 1.1 核心功能

- **返利计算**：根据核销时间与付款时间的间隔，动态计算返利比例和返利金额
- **阶段管理**：免费期、福利损耗期、货物损耗期、已过期四个阶段
- **多类型返利**：支持普通核销返利、代管理返利、店铺邀请返利
- **微信打款**：通过微信商家转账到零钱功能发放返利

### 1.2 业务关系图

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           核销券返利系统                                 │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  ┌──────────────┐     ┌──────────────┐     ┌──────────────┐            │
│  │   用户购买   │ ──► │   核销使用   │ ──► │   返利计算   │            │
│  │   核销券     │     │   (商家/代管理)│     │              │            │
│  └──────────────┘     └──────────────┘     └──────┬───────┘            │
│                                                    │                    │
│                                                    ▼                    │
│                              ┌──────────────────────────────────────┐  │
│                              │           返利记录表                  │  │
│                              │     grain_wanlshop_voucher_rebate    │  │
│                              └──────────────────┬───────────────────┘  │
│                                                 │                       │
│                     ┌───────────────────────────┼───────────────────┐  │
│                     │                           │                   │  │
│                     ▼                           ▼                   ▼  │
│          ┌──────────────┐            ┌──────────────┐    ┌──────────────┐
│          │ 普通核销返利 │            │ 代管理返利   │    │ 店铺邀请返利 │
│          │ (等待7天)    │            │ (立即打款)   │    │ (24h审核)    │
│          └──────────────┘            └──────────────┘    └──────────────┘
│                     │                           │                   │  │
│                     └───────────────────────────┼───────────────────┘  │
│                                                 │                       │
│                                                 ▼                       │
│                              ┌──────────────────────────────────────┐  │
│                              │        微信商家转账到零钱            │  │
│                              └──────────────────────────────────────┘  │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## 2. 返利类型

系统支持三种返利类型，存储在 `rebate_type` 字段中：

| 类型值 | 名称 | 说明 | 打款时机 |
|--------|------|------|----------|
| `normal` | 核销返利 | 用户核销券后产生的返利 | 付款后 7 天 |
| `custody` | 代管理返利 | 用户申请代管理后平台审核通过产生的返利 | 审核通过后立即 |
| `shop_invite` | 店铺邀请返利 | 邀请人首次被邀请店铺核销产生的返利 | 核销 24 小时后（需人工审核） |

### 2.1 返利类型详解

#### 2.1.1 核销返利 (normal)

- **触发条件**：用户持券到店铺核销
- **返利对象**：购买核销券的用户
- **返利基数**：券面值 × 实际返利比例
- **打款限制**：需等待付款后满 7 天

#### 2.1.2 代管理返利 (custody)

- **触发条件**：用户申请代管理，后台审核通过
- **返利对象**：购买核销券的用户
- **返利基数**：券面值 × 实际返利比例（基于审核时间计算）
- **打款限制**：审核通过后立即发起打款

#### 2.1.3 店铺邀请返利 (shop_invite)

- **触发条件**：被邀请的店铺首次核销
- **返利对象**：邀请该店铺的用户
- **返利基数**：供货价 × 邀请返利比例
- **打款限制**：核销后 24 小时，且券未退款

---

## 3. 数据库表结构

### 3.1 核心表：grain_wanlshop_voucher_rebate

返利记录主表，存储所有类型的返利记录。

```sql
CREATE TABLE `grain_wanlshop_voucher_rebate` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `voucher_id` int(10) unsigned NOT NULL COMMENT '券ID',
    `voucher_no` varchar(32) NOT NULL COMMENT '券号',
    `rebate_type` enum('normal','custody','shop_invite') NOT NULL DEFAULT 'normal' COMMENT '返利类型',
    `audit_status` enum('pending_audit','approved','rejected') NOT NULL DEFAULT 'approved' COMMENT '审核状态',
    `order_id` int(10) unsigned NOT NULL COMMENT '订单ID',
    `verification_id` int(10) unsigned NOT NULL COMMENT '核销记录ID',
    `user_id` int(10) NOT NULL COMMENT '返利接收用户ID',
    `shop_id` int(10) unsigned NOT NULL COMMENT '核销店铺ID',
    `invite_shop_id` int(10) unsigned DEFAULT NULL COMMENT '邀请的店铺ID（仅shop_invite类型）',
    `invite_shop_name` varchar(100) DEFAULT '' COMMENT '邀请的店铺名称',
    `shop_name` varchar(100) NOT NULL COMMENT '店铺名称',
    `supply_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '供货价',
    `face_value` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '券面值',
    `rebate_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '返利金额',
    `user_bonus_ratio` decimal(5,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '用户原始返利比例',
    `actual_bonus_ratio` decimal(5,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '实际返利比例（损耗后）',
    `stage` enum('free','welfare','goods','expired') NOT NULL COMMENT '返利阶段',
    `days_from_payment` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '距付款天数',
    `goods_title` varchar(255) NOT NULL DEFAULT '' COMMENT '商品标题',
    `shop_goods_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '核销店铺商品ID',
    `shop_goods_title` varchar(255) NOT NULL DEFAULT '' COMMENT '核销店铺商品标题',
    `sku_weight` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT 'SKU重量',
    `original_goods_weight` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '原始货物重量',
    `actual_goods_weight` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '实际货物重量',
    `rule_id` int(10) unsigned NOT NULL COMMENT '规则ID',
    `free_days` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '免费期天数',
    `welfare_days` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '福利损耗期天数',
    `goods_days` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '货物损耗期天数',
    `payment_time` bigint(16) NOT NULL COMMENT '付款时间',
    `verify_time` bigint(16) NOT NULL COMMENT '核销时间',
    `createtime` bigint(16) DEFAULT NULL,
    `updatetime` bigint(16) DEFAULT NULL,
    `deletetime` bigint(16) DEFAULT NULL,
    `status` enum('normal','hidden') DEFAULT 'normal' COMMENT '记录状态',
    `payment_status` enum('unpaid','pending','paid','failed') NOT NULL DEFAULT 'unpaid' COMMENT '打款状态',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_voucher_id` (`voucher_id`),
    KEY `idx_voucher_no` (`voucher_no`),
    KEY `idx_rebate_type` (`rebate_type`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_shop_id` (`shop_id`),
    KEY `idx_invite_shop_id` (`invite_shop_id`),
    KEY `idx_stage` (`stage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='核销券返利记录';
```

### 3.2 返利规则表：grain_wanlshop_voucher_rule

定义返利的时间阶段规则。

```sql
CREATE TABLE `grain_wanlshop_voucher_rule` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(100) NOT NULL COMMENT '规则名称',
    `expire_days` int(10) unsigned NOT NULL DEFAULT '90' COMMENT '有效期天数',
    `free_days` int(10) unsigned NOT NULL DEFAULT '30' COMMENT '免费期天数',
    `welfare_days` int(10) unsigned NOT NULL DEFAULT '30' COMMENT '福利损耗期天数',
    `goods_days` int(10) unsigned NOT NULL DEFAULT '30' COMMENT '货物损耗期天数',
    `priority` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '优先级',
    `state` enum('1','0') NOT NULL DEFAULT '1' COMMENT '启用状态',
    `remark` varchar(255) DEFAULT '' COMMENT '备注',
    `createtime` bigint(16) DEFAULT NULL,
    `updatetime` bigint(16) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='核销券规则';
```

### 3.3 打款日志表：grain_wanlshop_transfer_log

记录每次打款操作的详细信息。

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | int(10) | 主键 |
| `order_type` | enum('settlement','rebate') | 业务类型 |
| `settlement_id` | int(10) | 结算ID（结算打款时使用） |
| `rebate_id` | int(10) | 返利ID（返利打款时使用） |
| `out_batch_no` | varchar(64) | 商户批次单号 |
| `out_detail_no` | varchar(64) | 商户明细单号 |
| `transfer_amount` | int(10) | 转账金额（分） |
| `receiver_openid` | varchar(64) | 收款人 OpenID |
| `receiver_user_id` | int(10) | 收款人用户ID |
| `status` | tinyint(1) | 状态：1=待确认 2=成功 3=失败 |
| `wechat_batch_id` | varchar(64) | 微信批次单号 |
| `wechat_detail_id` | varchar(64) | 微信明细单号 |
| `fail_reason` | varchar(255) | 失败原因 |
| `request_data` | text | 请求数据 |
| `response_data` | text | 响应数据 |
| `package_info` | text | 跳转信息 |

### 3.4 店铺邀请相关表

#### 3.4.1 待审核队列：grain_shop_invite_pending

```sql
CREATE TABLE `grain_shop_invite_pending` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `shop_id` int(10) unsigned NOT NULL COMMENT '被邀请店铺ID',
    `inviter_id` int(10) unsigned NOT NULL COMMENT '邀请人用户ID',
    `verification_id` int(10) unsigned NOT NULL COMMENT '核销记录ID',
    `voucher_id` int(10) unsigned NOT NULL COMMENT '券ID',
    `user_id` int(10) unsigned NOT NULL COMMENT '核销用户ID',
    `supply_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '供货价（返利基数）',
    `verify_time` bigint(16) NOT NULL COMMENT '核销时间',
    `state` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '0=待审核 1=已发放 2=已取消',
    `process_time` bigint(16) DEFAULT NULL COMMENT '处理时间',
    `admin_id` int(10) unsigned DEFAULT NULL COMMENT '操作管理员ID',
    `createtime` bigint(16) DEFAULT NULL,
    `updatetime` bigint(16) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_shop_state_pending` (`shop_id`, `state`),
    KEY `idx_inviter_id` (`inviter_id`),
    KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='店铺邀请返利待审核队列';
```

#### 3.4.2 返利记录：grain_shop_invite_rebate_log

```sql
CREATE TABLE `grain_shop_invite_rebate_log` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `inviter_id` int(10) unsigned NOT NULL COMMENT '邀请人用户ID',
    `shop_id` int(10) unsigned NOT NULL COMMENT '被邀请店铺ID',
    `shop_name` varchar(100) DEFAULT '' COMMENT '店铺名称',
    `verification_id` int(10) unsigned NOT NULL COMMENT '核销记录ID',
    `voucher_id` int(10) unsigned NOT NULL COMMENT '券ID',
    `user_id` int(10) unsigned NOT NULL COMMENT '核销用户ID',
    `supply_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '供货价',
    `rebate_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '返利金额',
    `bonus_ratio` decimal(5,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '返利比例%',
    `before_level` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '处理前等级',
    `after_level` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '处理后等级',
    `is_upgrade` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '是否触发升级',
    `pending_id` int(10) unsigned DEFAULT NULL COMMENT '关联待处理记录ID',
    `createtime` bigint(16) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_shop_rebate` (`shop_id`),
    KEY `idx_inviter_id` (`inviter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='店铺邀请返利记录';
```

#### 3.4.3 升级记录：grain_shop_invite_upgrade_log

记录因店铺邀请触发的用户等级升级。

```sql
CREATE TABLE `grain_shop_invite_upgrade_log` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int(10) unsigned NOT NULL COMMENT '被升级用户ID',
    `shop_id` int(10) unsigned NOT NULL COMMENT '触发升级的店铺ID',
    `verification_id` int(10) unsigned DEFAULT NULL,
    `voucher_id` int(10) unsigned DEFAULT NULL,
    `before_level` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '升级前等级',
    `after_level` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '升级后等级',
    `before_ratio` decimal(5,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '升级前比例',
    `after_ratio` decimal(5,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '升级后比例',
    `createtime` bigint(16) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_shop` (`user_id`, `shop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='店铺邀请升级记录';
```

---

## 4. 返利计算逻辑

### 4.1 阶段定义

返利根据核销时间与付款时间的间隔，分为四个阶段：

| 阶段 | 英文标识 | 说明 | 返利损耗 | 货物损耗 |
|------|----------|------|----------|----------|
| 免费期 | `free` | 付款后 0~N 天 | 无 | 无 |
| 福利损耗期 | `welfare` | 免费期结束后 N 天 | 线性递减至 0 | 无 |
| 货物损耗期 | `goods` | 福利损耗期结束后 N 天 | 0 | 线性递减至 0 |
| 已过期 | `expired` | 超过所有期限 | 0 | 0 |

### 4.2 计算公式

#### 4.2.1 免费期（days_from_payment ≤ free_days）

```php
$actualBonusRatio = $userBonusRatio;  // 返利比例不变
$actualGoodsWeight = $originalWeight;  // 货物重量不变
$rebateAmount = $faceValue * ($actualBonusRatio / 100);
```

#### 4.2.2 福利损耗期（free_days < days ≤ free_days + welfare_days）

```php
$welfareElapsedDays = $daysFromPayment - $freeDays;

// 返利比例线性递减
$ratioLossPerDay = $userBonusRatio / $welfareDays;
$actualBonusRatio = max(0, $userBonusRatio - ($ratioLossPerDay * $welfareElapsedDays));

// 货物重量不变（福利损耗期只损耗福利，不损耗货物）
$actualGoodsWeight = $originalWeight;

$rebateAmount = $faceValue * ($actualBonusRatio / 100);
```

#### 4.2.3 货物损耗期（free_days + welfare_days < days ≤ free_days + welfare_days + goods_days）

```php
$actualBonusRatio = 0;  // 返利为 0

$goodsElapsedDays = $daysFromPayment - $freeDays - $welfareDays;
// 货物从完整重量开始线性递减至 0
$goodsLossPerDay = $originalWeight / $goodsDays;
$actualGoodsWeight = $originalWeight - ($goodsLossPerDay * $goodsElapsedDays);

$rebateAmount = 0;
```

#### 4.2.4 已过期

```php
$actualBonusRatio = 0;
$actualGoodsWeight = 0;
$rebateAmount = 0;
```

### 4.3 计算示例

假设用户返利比例为 10%，券面值 100 元，货物重量 10 斤，规则配置：
- 免费期：30 天
- 福利损耗期：30 天
- 货物损耗期：30 天

| 核销时间（距付款天数） | 阶段 | 实际返利比例 | 返利金额 | 实际货物重量 |
|------------------------|------|-------------|----------|-------------|
| 第 10 天 | 免费期 | 10% | ¥10.00 | 10 斤 |
| 第 30 天 | 免费期 | 10% | ¥10.00 | 10 斤 |
| 第 45 天 | 福利损耗期 | 5% | ¥5.00 | 10 斤 |
| 第 60 天 | 福利损耗期 | 0% | ¥0.00 | 10 斤 |
| 第 75 天 | 货物损耗期 | 0% | ¥0.00 | 5 斤 |
| 第 90 天 | 货物损耗期 | 0% | ¥0.00 | 0 斤 |
| 第 100 天 | 已过期 | 0% | ¥0.00 | 0 斤 |

---

## 5. 核销返利流程

### 5.1 流程图

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   商家扫码  │ ──► │  券信息校验 │ ──► │  核销处理   │ ──► │ 创建返利记录 │
└─────────────┘     └─────────────┘     └─────────────┘     └──────┬──────┘
                                                                   │
                                                                   ▼
┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   打款完成  │ ◄── │  用户确认   │ ◄── │  发起打款   │ ◄── │  等待7天    │
└─────────────┘     └─────────────┘     └─────────────┘     └─────────────┘
```

### 5.2 详细步骤

#### Step 1：扫码/输入验证码核销

**接口**：`POST /api/wanlshop/voucher.verify/scan` 或 `POST /api/wanlshop/voucher.verify/code`

**校验项**：
- 券状态必须为 `state=1`（未使用）
- 券在有效期内
- 商家有对应品类商品
- 商家商品有对应 SKU 规格
- 供货价 ≤ 券面价 × 80%

#### Step 2：更新券状态

```php
$voucher->state = 2;  // 已核销
$voucher->shop_id = $shop->id;
$voucher->shop_name = $shop->shopname;
$voucher->verify_user_id = $this->auth->id;
$voucher->verifytime = time();
$voucher->supply_price = $shopSupplyPrice;
```

#### Step 3：创建核销记录

写入 `grain_wanlshop_voucher_verification` 表。

#### Step 4：创建结算记录

写入 `grain_wanlshop_voucher_settlement` 表，用于给商家结算。

#### Step 5：创建返利记录

调用 `VoucherRebateService::createRebateRecord()` 创建返利记录：

```php
$rebateService = new VoucherRebateService();
$rebateService->createRebateRecord($voucher, $verification, time(), $shop->id, $shopGoodsInfo, 'normal');
```

#### Step 6：等待 7 天

返利记录状态为 `payment_status=unpaid`，需等待付款时间满 7 天。

**判断条件**：
```php
public function canTransfer()
{
    $sevenDaysAgo = time() - 7 * 86400;
    return $this->payment_time < $sevenDaysAgo
        && in_array($this->payment_status, ['unpaid', 'failed'])
        && $this->verify_time > 0;
}
```

#### Step 7：后台发起打款

管理员在后台点击「打款」按钮，发起微信转账。

#### Step 8：用户确认收款

微信支付返回 `WAIT_USER_CONFIRM` 状态时，用户需要在微信中确认收款。

---

## 6. 代管理返利流程

### 6.1 流程图

```
┌─────────────┐     ┌─────────────┐     ┌─────────────────────────────┐
│ 用户申请    │ ──► │ 后台审核    │ ──► │ 按审核时间计算返利+货物退款  │
│ 代管理      │     │ (通过/拒绝) │     │ (应用模块4计算逻辑)         │
└─────────────┘     └─────────────┘     └──────────────┬──────────────┘
                                                       │
                                                       ▼
                    ┌─────────────────────────────────────────────────┐
                    │              双重打款                            │
                    │  ┌──────────────────┐  ┌──────────────────────┐ │
                    │  │  返利金额打款    │  │  货物等量退款        │ │
                    │  │  (面值×实际返利%) │  │  (实际货物重量×单价) │ │
                    │  └──────────────────┘  └──────────────────────┘ │
                    └─────────────────────────────────────────────────┘
```

### 6.2 核心概念：审核时间结算 + 等量退款

代管理返利与普通核销返利的**关键区别**在于：

1. **结算时间点**：以**审核通过时间**（而非核销时间）作为计算返利的基准时间
2. **双重打款机制**：
   - **返利打款**：按模块4计算逻辑计算的返利金额
   - **等量退款**：按规则下实际货物存量的等值退款（这是代管理独有的）

#### 6.2.1 审核时间结算逻辑

```php
// 计算距付款的天数时，使用审核通过时间而非核销时间
$daysFromPayment = ceil(($approveTime - $paymentTime) / 86400);

// 根据天数判断阶段并计算返利（与模块4逻辑完全一致）
$stage = $this->determineStage($daysFromPayment, $rule);
$actualBonusRatio = $this->calculateActualBonusRatio($daysFromPayment, $userBonusRatio, $rule);
$actualGoodsWeight = $this->calculateActualGoodsWeight($daysFromPayment, $originalWeight, $rule);
```

#### 6.2.2 等量退款逻辑（核心特性）

**等量退款**是代管理返利的关键特性：用户将券交给平台代管理后，平台需要按照当前阶段的**实际货物存量**进行等值退款。

```php
/**
 * 等量退款计算公式
 *
 * 退款金额 = 实际货物重量(斤) × 单价(元/斤)
 *
 * 其中：
 * - 实际货物重量：根据模块4计算逻辑，按审核通过时间计算的货物存量
 * - 单价：券对应商品的供货单价（supply_price / original_weight）
 */
$unitPrice = $supplyPrice / $originalGoodsWeight;  // 供货单价（元/斤）
$refundAmount = $actualGoodsWeight * $unitPrice;   // 等量退款金额
```

**退款场景示例**：

| 审核时间（距付款天数） | 阶段 | 实际货物重量 | 单价 | 等量退款金额 |
|------------------------|------|-------------|------|-------------|
| 第 10 天 | 免费期 | 10 斤 | ¥5/斤 | ¥50.00 |
| 第 45 天 | 福利损耗期 | 10 斤 | ¥5/斤 | ¥50.00 |
| 第 60 天 | 福利损耗期末 | 10 斤 | ¥5/斤 | ¥50.00 |
| 第 75 天 | 货物损耗期 | 5 斤 | ¥5/斤 | ¥25.00 |
| 第 90 天 | 货物损耗期末 | 0 斤 | ¥5/斤 | ¥0.00 |

> **重要说明**：
> - 免费期和福利损耗期：货物不损耗，等量退款金额等于全部供货价
> - 货物损耗期：货物线性递减，退款金额按剩余货物计算
> - 已过期：货物为 0，无退款

### 6.3 详细步骤

#### Step 1：用户申请代管理

用户在小程序端对未核销的券申请代管理，券的 `custody_state` 变为 `1`（申请中）。

#### Step 2：后台审核（返利计算时间点）

**接口**：`POST /admin/wanlshop/voucher.custody/approve`

审核通过时执行：

```php
$approveTime = time();  // 审核通过时间，作为返利计算基准

// 更新券状态
$voucher->state = 2;  // 已核销
$voucher->custody_state = '2';  // 已通过
$voucher->shop_id = 1;  // 平台店铺
$voucher->shop_name = '平台代管理';

// 创建虚拟核销记录（核销方式为 custody，核销时间为审核通过时间）
$verification->verify_method = 'custody';
$verification->verifytime = $approveTime;  // 使用审核通过时间

// 创建返利记录（类型为 custody，按审核通过时间计算返利阶段和金额）
$rebateService->createRebateRecord(
    $voucher,
    $verification,
    $approveTime,      // 审核通过时间作为计算基准
    1,                 // 平台店铺ID
    $platformGoodsInfo,
    'custody'
);
```

#### Step 3：返利计算（应用模块4逻辑）

代管理返利**完全遵循模块4的返利计算逻辑**，唯一区别是时间基准为审核通过时间：

```php
// 计算距付款天数（以审核通过时间为准）
$daysFromPayment = ceil(($approveTime - $paymentTime) / 86400);

// 获取规则配置
$rule = VoucherRule::get($voucher->rule_id);
$freeDays = $rule->free_days;
$welfareDays = $rule->welfare_days;
$goodsDays = $rule->goods_days;

// 判断阶段（与模块4完全一致）
if ($daysFromPayment <= $freeDays) {
    $stage = 'free';
} elseif ($daysFromPayment <= $freeDays + $welfareDays) {
    $stage = 'welfare';
} elseif ($daysFromPayment <= $freeDays + $welfareDays + $goodsDays) {
    $stage = 'goods';
} else {
    $stage = 'expired';
}

// 计算实际返利比例和货物重量（与模块4公式完全一致）
// 详见模块4计算公式
```

#### Step 4：等量退款计算

```php
// 计算单价
$unitPrice = $voucher->supply_price / $voucher->original_goods_weight;

// 计算实际货物重量（模块4逻辑）
$actualGoodsWeight = $this->calculateActualGoodsWeight($daysFromPayment, $originalWeight, $rule);

// 计算等量退款金额
$refundAmount = round($actualGoodsWeight * $unitPrice, 2);
```

#### Step 5：双重打款

代管理审核通过后，发起**两笔打款**：

```php
// 打款1：返利金额（基于面值和实际返利比例）
$rebateAmount = $faceValue * ($actualBonusRatio / 100);
$transferService->transferCustody($rebate->id, 'rebate');

// 打款2：等量退款（基于实际货物存量）
$refundAmount = $actualGoodsWeight * $unitPrice;
$transferService->transferCustody($rebate->id, 'refund');

// 总打款金额 = 返利金额 + 等量退款金额
$totalTransferAmount = $rebateAmount + $refundAmount;
```

### 6.4 计算示例

假设用户返利比例为 10%，券面值 100 元，供货价 50 元，货物重量 10 斤（单价 5 元/斤），规则配置：
- 免费期：30 天
- 福利损耗期：30 天
- 货物损耗期：30 天

| 审核通过时间（距付款天数） | 阶段 | 实际返利比例 | 返利金额 | 实际货物重量 | 等量退款 | **总打款** |
|---------------------------|------|-------------|----------|-------------|---------|-----------|
| 第 10 天 | 免费期 | 10% | ¥10.00 | 10 斤 | ¥50.00 | **¥60.00** |
| 第 30 天 | 免费期 | 10% | ¥10.00 | 10 斤 | ¥50.00 | **¥60.00** |
| 第 45 天 | 福利损耗期 | 5% | ¥5.00 | 10 斤 | ¥50.00 | **¥55.00** |
| 第 60 天 | 福利损耗期末 | 0% | ¥0.00 | 10 斤 | ¥50.00 | **¥50.00** |
| 第 75 天 | 货物损耗期 | 0% | ¥0.00 | 5 斤 | ¥25.00 | **¥25.00** |
| 第 90 天 | 货物损耗期末 | 0% | ¥0.00 | 0 斤 | ¥0.00 | **¥0.00** |

### 6.5 与普通核销的区别

| 对比项 | 普通核销返利 | 代管理返利 |
|--------|------------|-----------|
| 核销店铺 | 实际商家店铺 | 平台店铺 (shop_id=1) |
| 核销方式 | scan/code | custody |
| **时间基准** | 核销时间 | **审核通过时间** |
| **返利计算** | 模块4逻辑 | **模块4逻辑（相同）** |
| **等量退款** | 无 | **有（按实际货物存量退款）** |
| 打款时机 | 付款后 7 天 | 审核通过后立即 |
| 打款内容 | 仅返利金额 | **返利金额 + 等量退款** |
| 单号前缀 | RBT | CUS |

### 6.6 数据库记录

代管理返利记录需要额外存储等量退款相关信息：

```php
// 返利记录 (grain_wanlshop_voucher_rebate)
[
    'rebate_type' => 'custody',
    'rebate_amount' => $rebateAmount,        // 返利金额
    'refund_amount' => $refundAmount,        // 等量退款金额（新增字段）
    'total_amount' => $rebateAmount + $refundAmount,  // 总打款金额（新增字段）
    'unit_price' => $unitPrice,              // 货物单价（新增字段）
    'actual_goods_weight' => $actualGoodsWeight,
    'stage' => $stage,
    'verify_time' => $approveTime,           // 审核通过时间
    // ...其他字段
]
```

---

## 7. 店铺邀请返利流程

### 7.1 流程概述

店铺邀请返利是一种两阶段审核机制：

1. **阶段一**：核销时自动写入待审核队列
2. **阶段二**：24 小时后管理员手动审核发放

### 7.2 流程图

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│ 被邀请店铺  │ ──► │ 写入待审核  │ ──► │ 等待24小时  │ ──► │ 后台审核    │
│ 首次核销    │     │ 队列        │     │ (防退款)    │     │ 发放返利    │
└─────────────┘     └─────────────┘     └─────────────┘     └──────┬──────┘
                                                                   │
                    ┌──────────────────────────────────────────────┘
                    ▼
             ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
             │ 邀请人升级  │ ──► │ 写入返利表  │ ──► │  打款       │
             │ (如符合条件) │     │ (shop_invite)│     │             │
             └─────────────┘     └─────────────┘     └─────────────┘
```

### 7.3 详细步骤

#### Step 1：核销时检查店铺邀请关系

在 `Verify::handleVerification()` 方法末尾调用：

```php
$this->processShopInviteRebatePending($shop, $verification, $voucher);
```

检查条件：
- 店铺有邀请人 (`shop.inviter_id` 不为空)
- 该店铺未产生过返利记录
- 待审核队列中没有该店铺的记录

#### Step 2：写入待审核队列

```php
Db::name('shop_invite_pending')->insert([
    'shop_id' => $shop->id,
    'inviter_id' => $shop->inviter_id,
    'verification_id' => $verification->id,
    'voucher_id' => $voucher->id,
    'user_id' => $voucher->user_id,
    'supply_price' => $verification->supply_price,
    'verify_time' => time(),
    'state' => 0,  // 待审核
]);
```

#### Step 3：后台审核（24 小时后）

**接口**：`POST /admin/wanlshop/voucher.shop_invite_rebate/grantRebate`

审核条件：
- 核销时间距今超过 24 小时
- 券未退款 (`voucher.state != 3`)

#### Step 4：处理升级并计算返利

```php
// 检查是否触发升级（每店每用户仅一次，最高升至 level=2）
if (!$existsUpgrade && $currentLevel < 2) {
    $afterLevel = $currentLevel + 1;
    $isUpgrade = true;
}

// 获取返利比例（升级后的比例）
$afterRatio = $this->getInviteRatio($afterLevel);

// 计算返利金额
$rebateAmount = round($supplyPrice * ($afterRatio / 100), 2);
```

#### Step 5：写入返利表

```php
Db::name('wanlshop_voucher_rebate')->insert([
    'user_id' => $inviterId,  // 邀请人
    'rebate_type' => 'shop_invite',
    'invite_shop_id' => $shopId,
    'invite_shop_name' => $shopName,
    'rebate_amount' => $rebateAmount,
    'bonus_ratio' => $afterRatio,
    'payment_status' => 'unpaid',
    // ...
]);
```

### 7.4 邀请返利比例配置

从系统配置中读取：

```php
$ratios = [
    0 => config('site.invite_base_ratio'),    // 默认 1.0%
    1 => config('site.invite_level1_ratio'),  // 默认 1.5%
    2 => config('site.invite_level2_ratio'),  // 默认 2.0%
];
```

---

## 8. 打款流程

### 8.1 打款条件

| 返利类型 | 打款条件 |
|----------|----------|
| normal | `payment_time` 距今 > 7 天 且 `verify_time > 0` 且 `payment_status` in ('unpaid', 'failed') |
| custody | 审核通过后立即 |
| shop_invite | 与 normal 相同（需等待 7 天） |

### 8.2 打款状态流转

```
        发起打款
           │
           ▼
    ┌─────────────┐
    │   unpaid    │ ──────────────────┐
    │   (未打款)  │                   │
    └──────┬──────┘                   │
           │                          │
           │ 发起转账                 │
           ▼                          │
    ┌─────────────┐                   │
    │   pending   │ ◄─────────────────┘
    │   (打款中)  │      重试
    └──────┬──────┘
           │
     ┌─────┴─────┐
     │           │
     ▼           ▼
┌─────────┐  ┌─────────┐
│  paid   │  │ failed  │
│ (已打款) │  │(打款失败)│
└─────────┘  └─────────┘
```

### 8.3 微信转账状态映射

| 微信状态 | 系统状态 | 说明 |
|----------|----------|------|
| SUCCESS | paid | 立即成功 |
| WAIT_USER_CONFIRM | pending | 等待用户确认 |
| ACCEPTED | pending | 已受理 |
| 其他/失败 | failed | 打款失败 |

### 8.4 打款单号规则

| 返利类型 | 前缀 | 示例 |
|----------|------|------|
| normal | RBT | RBT20251210123456789_123 |
| custody | CUS | CUS20251210123456789_456 |
| shop_invite | RBT | RBT20251210123456789_789 |

### 8.5 打款失败重试

管理员可以在后台对 `payment_status=failed` 的记录点击「重试」按钮：

```php
public function retry(int $rebateId): array
{
    // 代管理返利使用专用方法
    if ($rebate->rebate_type === 'custody') {
        return $this->transferCustody($rebateId);
    }
    return $this->transfer($rebateId);
}
```

---

## 9. 后台管理界面

### 9.1 返利管理列表

**路由**：`/admin/wanlshop/voucher.rebate`

**功能**：
- 查看所有类型的返利记录
- 按券号、用户、店铺、阶段、打款状态筛选
- 查看返利详情
- 对可打款记录发起打款
- 对失败记录重试打款

**列表字段**：
- ID、券号、商品标题
- 用户昵称、店铺名称
- 返利阶段、实际返利比例、返利金额
- 付款时间、核销时间
- 打款状态

### 9.2 店铺邀请返利管理

**路由**：`/admin/wanlshop/voucher.shop_invite_rebate`

**子页面**：

#### 9.2.1 待审核 (pending)

显示 `shop_invite_pending` 表中 `state=0` 的记录。

**操作**：
- 查看详情
- 发放返利（满 24 小时且未退款）
- 取消

#### 9.2.2 打款管理 (index)

显示 `voucher_rebate` 表中 `rebate_type='shop_invite'` 的记录。

**操作**：
- 查看详情
- 打款（满 7 天）
- 重试

### 9.3 代管理审核

**路由**：`/admin/wanlshop/voucher.custody`

**功能**：
- 查看代管理申请列表
- 审核通过（自动打款）
- 审核拒绝（填写拒绝理由）

---

## 10. API 接口

### 10.1 用户端接口

#### 10.1.1 获取返利记录列表

```
GET /api/wanlshop/voucher.rebate/index
```

**参数**：无（自动获取当前登录用户）

**返回**：

```json
{
    "code": 1,
    "msg": "ok",
    "data": {
        "total": 10,
        "per_page": 10,
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "voucher_no": "VCH202512...",
                "goods_title": "东北大米",
                "rebate_amount": "10.00",
                "stage": "free",
                "actual_goods_weight": "10.00",
                "verify_time": 1733836800,
                "createtime": 1733836800
            }
        ]
    }
}
```

#### 10.1.2 获取返利记录详情

```
GET /api/wanlshop/voucher.rebate/detail?id=1
```

### 10.2 商家端接口

核销相关接口见 `Verify.php` 控制器。

---

## 11. 配置项

### 11.1 返利规则配置

在 `grain_wanlshop_voucher_rule` 表中配置：

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| free_days | 30 | 免费期天数 |
| welfare_days | 30 | 福利损耗期天数 |
| goods_days | 30 | 货物损耗期天数 |
| expire_days | 90 | 券有效期天数 |

### 11.2 邀请返利比例配置

在系统配置 (`site` 配置组) 中：

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| invite_base_ratio | 1.0 | 基础等级返利比例 |
| invite_level1_ratio | 1.5 | 一级等级返利比例 |
| invite_level2_ratio | 2.0 | 二级等级返利比例 |

### 11.3 微信支付配置

在 `config/wechat.php` 中：

```php
'payment' => [
    'transfer_notify_url' => 'https://xxx/api/wanlshop/voucher.notify/transfer',
    // ...
]
```

---

## 12. 文件索引

### 12.1 后端控制器

| 文件路径 | 说明 |
|----------|------|
| `application/admin/controller/wanlshop/voucher/Rebate.php` | 后台返利管理 |
| `application/admin/controller/wanlshop/voucher/ShopInviteRebate.php` | 店铺邀请返利管理 |
| `application/admin/controller/wanlshop/voucher/Custody.php` | 代管理审核 |
| `application/api/controller/wanlshop/voucher/Rebate.php` | 用户端返利接口 |
| `application/api/controller/wanlshop/voucher/Verify.php` | 核销接口 |

### 12.2 模型

| 文件路径 | 说明 |
|----------|------|
| `application/admin/model/wanlshop/VoucherRebate.php` | 返利记录模型 |
| `application/common/model/TransferLog.php` | 打款日志模型 |

### 12.3 服务类

| 文件路径 | 说明 |
|----------|------|
| `application/common/service/VoucherRebateService.php` | 返利计算服务 |
| `application/admin/service/RebateTransferService.php` | 返利打款服务 |

### 12.4 视图模板

| 文件路径 | 说明 |
|----------|------|
| `application/admin/view/wanlshop/voucher/rebate/index.html` | 返利列表页 |
| `application/admin/view/wanlshop/voucher/rebate/detail.html` | 返利详情页 |
| `application/admin/view/wanlshop/voucher/rebate/transfer.html` | 打款弹窗 |
| `application/admin/view/wanlshop/voucher/shop_invite_rebate/pending.html` | 待审核列表 |
| `application/admin/view/wanlshop/voucher/shop_invite_rebate/grant_rebate.html` | 发放返利弹窗 |
| `application/admin/view/wanlshop/voucher/shop_invite_rebate/index.html` | 打款管理列表 |
| `application/admin/view/wanlshop/voucher/shop_invite_rebate/transfer.html` | 打款弹窗 |

### 12.5 前端 JS

| 文件路径 | 说明 |
|----------|------|
| `public/assets/js/backend/wanlshop/voucher/rebate.js` | 返利管理 JS |

### 12.6 数据库迁移

| 文件路径 | 说明 |
|----------|------|
| `database/migrations/20251208_add_rebate_type_field.sql` | 添加 rebate_type 字段 |
| `database/migrations/shop_invite_rebate.sql` | 店铺邀请返利相关表 |

---

## 附录 A：返利阶段文本映射

```php
const STAGE_LIST = [
    'free' => '免费期',
    'welfare' => '福利损耗期',
    'goods' => '货物损耗期',
    'expired' => '已过期',
];
```

## 附录 B：打款状态文本映射

```php
const PAYMENT_STATUS_LIST = [
    'unpaid' => '未打款',
    'pending' => '打款中',
    'paid' => '已打款',
    'failed' => '打款失败',
];
```

## 附录 C：返利类型文本映射

```php
const REBATE_TYPE_LIST = [
    'normal' => '核销返利',
    'custody' => '代管理返利',
    'shop_invite' => '店铺邀请返利',
];
```
