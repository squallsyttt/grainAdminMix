# 核销券订单与支付模块 - MVP 执行计划

**版本**：v1.0.0-MVP
**创建日期**：2025-11-14
**项目**：GrainAdminMix
**模块**：核销券（Voucher）订单与支付系统

---

## 一、MVP 范围

### 1.1 业务模型

**核心流程**（所有订单都基于核销券）：

下单 → 支付 → 生成核销券 → 用户到店核销 → 平台结算

**关键说明**：
- ❌ **不存在传统物流发货模式**：系统不涉及商品配送、物流跟踪等传统电商流程
- ✅ **强制生成核销券**：支付成功后必然生成核销券，无例外情况
- ✅ **未来扩展基于核销券**：所有后续功能（团购、预售、套餐等）都基于核销券机制实现

### 1.2 MVP 功能清单

✅ **必须实现**：
1. 创建订单（选商品/分类，计算金额）
2. 支付集成（**仅支持微信小程序 JSAPI 支付**，复用现有 `grain_wanlshop_pay`）
3. 生成券（支付成功回调）
4. 用户查看券（列表、详情、券码）
5. 店铺核销（扫码/验证码核销）
6. 核销记录（简单列表）
7. 退款申请（未使用的券）
8. 后台管理（订单/券/核销/退款的基本 CRUD）
9. **HTTP 测试文件**：所有 API 接口必须在 `api-tests/wanlshop/voucher/` 目录下编写对应的 `.http` 测试文件

❌ **暂不实现**（后续优化）：
- ~~Redis 缓存~~
- ~~分布式锁~~
- ~~短信/微信通知~~
- ~~复杂统计报表~~
- ~~自动化定时任务~~
- ~~风控系统~~
- ~~二维码生成~~（先用券号/验证码）

### 1.3 兼容性要求

✅ **复用现有模块**：
- 用户表（grain_user）
- 商家表（grain_wanlshop_shop）
- 商品表（grain_wanlshop_goods）
- 分类表（grain_wanlshop_category）
- 支付表（grain_wanlshop_pay）- 复用现有支付流程

✅ **不影响现有接口**：
- 所有现有 API 接口保持不变
- 仅扩展 `grain_wanlshop_pay.type` 字段（增加 'voucher' 枚举值）

✅ **支付方式限定**：
- **仅支持微信小程序内 JSAPI 支付**（`wx.requestPayment` API）
- **不涉及其他支付方式**：H5 支付、APP 支付、Native 支付、付款码支付等均不支持
- **前置条件**：用户必须在微信小程序内完成下单和支付流程
- **技术依赖**：需获取用户 openid（通过 `wx.login` 登录流程）

---

## 二、数据库设计（MVP 简化版）

### 2.1 新增表清单

| 表名 | 说明 | MVP 核心字段 |
|-----|------|---------|
| grain_wanlshop_voucher_order | 核销券订单表 | order_no, user_id, category_id, supply_price, retail_price, state |
| grain_wanlshop_voucher | 核销券表 | voucher_no, verify_code, supply_price, face_value, state |
| grain_wanlshop_voucher_verification | 核销记录表 | voucher_id, shop_id, supply_price, face_value |
| grain_wanlshop_voucher_settlement | 结算记录表 | shop_id, retail_price, supply_price, shop_amount, platform_amount |
| grain_wanlshop_voucher_refund | 退款表 | voucher_id, state（简化） |

**价格字段说明**：
- `supply_price` - 供货价（商家批发价，结算依据）
- `retail_price` / `face_value` - 零售价（用户支付金额）
- `shop_amount` - 商家结算金额（= supply_price）
- `platform_amount` - 平台利润（= retail_price - supply_price）

### 2.2 表结构详细 DDL

#### 2.2.1 核销券订单表

```sql
CREATE TABLE IF NOT EXISTS `grain_wanlshop_voucher_order` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `user_id` int(10) NOT NULL DEFAULT '0' COMMENT '用户ID',
  `order_no` varchar(18) COLLATE utf8mb4_general_ci NOT NULL COMMENT '订单号',
  `category_id` int(10) unsigned NOT NULL COMMENT '商品分类ID',
  `goods_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '商品ID',
  `coupon_id` int(10) NOT NULL DEFAULT '0' COMMENT '优惠券ID',
  `quantity` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '购买数量',
  `supply_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '供货价（商家结算价）',
  `retail_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '零售价（单价）',
  `coupon_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '优惠券金额',
  `discount_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '优惠金额',
  `actual_payment` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '实际支付',
  `state` enum('1','2','3') COLLATE utf8mb4_general_ci NOT NULL DEFAULT '1' COMMENT '订单状态:1=待支付,2=已支付,3=已取消',
  `remarks` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '订单备注',
  `createtime` bigint(16) DEFAULT NULL COMMENT '创建时间',
  `paymenttime` bigint(16) DEFAULT NULL COMMENT '付款时间',
  `canceltime` bigint(16) DEFAULT NULL COMMENT '取消时间',
  `updatetime` bigint(16) DEFAULT NULL COMMENT '更新时间',
  `deletetime` bigint(16) DEFAULT NULL COMMENT '删除时间',
  `status` enum('normal','hidden') COLLATE utf8mb4_general_ci DEFAULT 'normal' COMMENT '状态',
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_no` (`order_no`),
  KEY `user_id` (`user_id`),
  KEY `category_id` (`category_id`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='核销券订单表';
```

#### 2.2.2 核销券表

```sql
CREATE TABLE IF NOT EXISTS `grain_wanlshop_voucher` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `voucher_no` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT '核销券号（唯一）',
  `order_id` int(10) unsigned NOT NULL COMMENT '订单ID',
  `user_id` int(10) NOT NULL COMMENT '用户ID',
  `category_id` int(10) unsigned NOT NULL COMMENT '适用分类ID',
  `goods_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '商品ID',
  `goods_title` varchar(255) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '商品标题',
  `goods_image` varchar(255) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '商品图片',
  `supply_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '供货价（商家结算金额）',
  `face_value` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '券面值（用户支付金额）',
  `shop_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '核销店铺ID（核销后填写）',
  `shop_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT '' COMMENT '核销店铺名称',
  `verify_user_id` int(10) unsigned DEFAULT '0' COMMENT '核销操作员ID',
  `verify_code` varchar(6) COLLATE utf8mb4_general_ci NOT NULL COMMENT '核销验证码（6位数字）',
  `state` enum('1','2','3','4') COLLATE utf8mb4_general_ci NOT NULL DEFAULT '1' COMMENT '状态:1=未使用,2=已核销,3=已过期,4=已退款',
  `valid_start` bigint(16) NOT NULL COMMENT '有效期开始时间',
  `valid_end` bigint(16) NOT NULL COMMENT '有效期结束时间',
  `createtime` bigint(16) DEFAULT NULL COMMENT '创建时间',
  `verifytime` bigint(16) DEFAULT NULL COMMENT '核销时间',
  `refundtime` bigint(16) DEFAULT NULL COMMENT '退款时间',
  `updatetime` bigint(16) DEFAULT NULL COMMENT '更新时间',
  `deletetime` bigint(16) DEFAULT NULL COMMENT '删除时间',
  `status` enum('normal','hidden') COLLATE utf8mb4_general_ci DEFAULT 'normal' COMMENT '状态',
  PRIMARY KEY (`id`),
  UNIQUE KEY `voucher_no` (`voucher_no`),
  KEY `order_id` (`order_id`),
  KEY `user_id` (`user_id`),
  KEY `shop_id` (`shop_id`),
  KEY `category_id` (`category_id`),
  KEY `state` (`state`),
  KEY `valid_end` (`valid_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='核销券表';
```

#### 2.2.3 核销记录表（MVP 简化）

```sql
CREATE TABLE IF NOT EXISTS `grain_wanlshop_voucher_verification` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `voucher_id` int(10) unsigned NOT NULL COMMENT '核销券ID',
  `voucher_no` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT '核销券号',
  `user_id` int(10) NOT NULL COMMENT '用户ID',
  `shop_id` int(10) unsigned NOT NULL COMMENT '核销店铺ID',
  `shop_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL COMMENT '店铺名称',
  `verify_user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '核销操作员ID',
  `supply_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '供货价（商家结算金额）',
  `face_value` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '券面值（用户支付金额）',
  `verify_method` enum('code','scan') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'code' COMMENT '核销方式:code=验证码,scan=扫码',
  `remarks` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '备注',
  `createtime` bigint(16) DEFAULT NULL COMMENT '核销时间',
  `updatetime` bigint(16) DEFAULT NULL COMMENT '更新时间',
  `deletetime` bigint(16) DEFAULT NULL COMMENT '删除时间',
  `status` enum('normal','hidden') COLLATE utf8mb4_general_ci DEFAULT 'normal' COMMENT '状态',
  PRIMARY KEY (`id`),
  KEY `voucher_id` (`voucher_id`),
  KEY `shop_id` (`shop_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='核销记录表';
```

#### 2.2.4 结算表（MVP 简化 - 供货价模式）

```sql
CREATE TABLE IF NOT EXISTS `grain_wanlshop_voucher_settlement` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `settlement_no` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT '结算单号',
  `voucher_id` int(10) unsigned NOT NULL COMMENT '核销券ID',
  `voucher_no` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT '核销券号',
  `order_id` int(10) unsigned NOT NULL COMMENT '订单ID',
  `shop_id` int(10) unsigned NOT NULL COMMENT '店铺ID',
  `shop_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL COMMENT '店铺名称',
  `user_id` int(10) NOT NULL COMMENT '用户ID',
  `retail_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '零售价（用户支付金额）',
  `supply_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '供货价（商家结算金额）',
  `platform_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '平台利润（零售价-供货价）',
  `shop_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '商家结算金额（=供货价）',
  `state` enum('1','2') COLLATE utf8mb4_general_ci NOT NULL DEFAULT '1' COMMENT '状态:1=待结算,2=已结算',
  `settlement_time` bigint(16) DEFAULT NULL COMMENT '结算时间',
  `remarks` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '备注',
  `createtime` bigint(16) DEFAULT NULL COMMENT '创建时间',
  `updatetime` bigint(16) DEFAULT NULL COMMENT '更新时间',
  `deletetime` bigint(16) DEFAULT NULL COMMENT '删除时间',
  `status` enum('normal','hidden') COLLATE utf8mb4_general_ci DEFAULT 'normal' COMMENT '状态',
  PRIMARY KEY (`id`),
  UNIQUE KEY `settlement_no` (`settlement_no`),
  KEY `voucher_id` (`voucher_id`),
  KEY `shop_id` (`shop_id`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='核销券结算表';
```

#### 2.2.5 退款表（MVP 简化）

```sql
CREATE TABLE IF NOT EXISTS `grain_wanlshop_voucher_refund` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `refund_no` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT '退款单号',
  `voucher_id` int(10) unsigned NOT NULL COMMENT '核销券ID',
  `voucher_no` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT '核销券号',
  `order_id` int(10) unsigned NOT NULL COMMENT '订单ID',
  `user_id` int(10) NOT NULL COMMENT '用户ID',
  `refund_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '退款金额',
  `refund_reason` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '退款理由',
  `refuse_reason` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '拒绝理由',
  `state` enum('0','1','2','3') COLLATE utf8mb4_general_ci NOT NULL DEFAULT '0' COMMENT '状态:0=申请中,1=同意退款,2=拒绝退款,3=退款成功',
  `createtime` bigint(16) DEFAULT NULL COMMENT '创建时间',
  `updatetime` bigint(16) DEFAULT NULL COMMENT '更新时间',
  `deletetime` bigint(16) DEFAULT NULL COMMENT '删除时间',
  `status` enum('normal','hidden') COLLATE utf8mb4_general_ci DEFAULT 'normal' COMMENT '状态',
  PRIMARY KEY (`id`),
  UNIQUE KEY `refund_no` (`refund_no`),
  KEY `voucher_id` (`voucher_id`),
  KEY `order_id` (`order_id`),
  KEY `user_id` (`user_id`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='核销券退款表';
```

#### 2.2.6 修改现有支付表

```sql
-- 扩展支付类型，增加 voucher
ALTER TABLE `grain_wanlshop_pay`
MODIFY COLUMN `type` enum('goods','groups','voucher') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'goods'
COMMENT '订单类型:goods=商品订单,groups=拼团订单,voucher=核销券订单';
```

---

## 三、ER 关系图

```
[User用户] 1 ----创建----> N [VoucherOrder核销券订单]
    |                               |
    |                               | 1:N (一笔订单可生成多张券)
    |                               ↓
    |                          [Voucher核销券]
    |                               |
    |                     +---------+---------+---------+
    |                     |         |         |         |
    |                     ↓         ↓         ↓         ↓
    |              [Verification] [Settlement] [Refund] [Category]
    |                  核销记录    结算记录    退款      分类
    |                     |         |
    +--核销到--------> [Shop店铺] <-+

[Goods商品] → 属于 → [Category分类] ← 关联 ← [Voucher核销券]

[VoucherOrder订单] → 支付 → [Pay支付记录]
```

**关系说明**：
1. 一个用户可以创建多个核销券订单（1:N）
2. 一个订单可以生成多张核销券（1:N）
3. 一张券只能核销一次（1:1）
4. 一张券核销后生成一条结算记录（1:1）
5. 一张券可能产生一条退款记录（1:0或1）
6. 券关联商品分类，核销时验证店铺是否支持该分类
7. **价格流转**：商品供货价 → 订单供货价 → 券供货价 → 结算供货价

---

## 四、业务流程（MVP 简化版）

### 4.1 购买流程（微信小程序 JSAPI 支付）

**重要说明**：
- 仅支持微信小程序内 JSAPI 支付方式
- 用户必须在小程序内完成全流程（下单 → 支付 → 获取券）
- 前端调用 `wx.requestPayment` API 发起支付
- 后端调用微信支付 V3 接口 `/v3/pay/transactions/jsapi`

```
【用户端】
用户在小程序内选择商品(按分类) → 点击购买
  ↓
【后端】创建订单
1. 生成订单号（order_no）
2. 创建 voucher_order 记录（state=1待支付）
3. 调用微信支付统一下单接口（小程序 JSAPI 支付）
   POST /v3/pay/transactions/jsapi
   请求参数：
   - appid: 小程序 appid
   - mchid: 商户号
   - description: 商品描述
   - out_trade_no: 订单号
   - notify_url: 回调地址
   - amount: { total: 100, currency: "CNY" }
   - payer: { openid: "用户 openid" }  # 通过 wx.login 获取
4. 微信返回 prepay_id
5. 后端生成前端调起支付参数（JSAPI 签名）
  ↓
【前端小程序】调起支付
6. 接收后端返回的支付参数
7. 调用小程序支付 API: wx.requestPayment({
     timeStamp, nonceStr, package, signType, paySign
   })
8. 用户在微信客户端输入密码完成支付
  ↓
【微信服务器】异步回调
9. 微信向 notify_url 发送 POST 回调（加密数据）
  ↓
【后端】处理回调
10. 验证签名（Wechatpay-Signature 等 HTTP 头）
11. 解密回调数据（AEAD_AES_256_GCM）
12. 验证订单状态（避免重复处理）
13. 数据库事务：
    - 更新订单状态（state=2已支付）
    - 生成核销券（quantity 张）
14. 5秒内返回 HTTP 200（必须！）
15. 异步处理业务（发通知等）
```

**关键点**：
- 订单号规则：`ORD + YYYYMMDD + 6位随机数`（必须唯一）
- 回调 URL：`https://yourdomain.com/api/wanlshop/voucher/order/notify`
- 回调必须 5 秒内响应，否则微信会重发（最多15次）
- 前端回调不可靠，必须等后端回调确认

### 4.2 核销流程

```
店员输入券号或验证码
  ↓
系统验证：
  - 券是否存在
  - 状态是否为"未使用"(state=1)
  - 是否在有效期内
  ↓
验证通过 → 数据库事务：
  - 更新券状态(state=2已核销, shop_id, verifytime)
  - 插入核销记录(verification)
  - 创建结算记录(settlement, state=1待结算)
```

**防重逻辑**：
- 数据库唯一索引（voucher_no）
- 状态机控制（WHERE state=1）
- 数据库事务保证

### 4.3 退款流程

```
用户申请退款
  ↓
验证：
  - 券状态为"未使用"(state=1)
  ↓
创建退款记录(refund, state=0申请中)
  ↓
后台人工审核：
  - 同意：state=1
  - 拒绝：state=2
  ↓
同意后 → 手动触发退款（后台按钮）
  ↓
更新券状态(state=4已退款)
更新退款记录(state=3退款成功)
```

**MVP 简化**：
- 不自动退款，全部人工审核
- 不调用支付平台接口，后台手动操作
- 过期券暂不处理（后续优化）

### 4.4 结算流程

```
核销触发 → 创建结算记录(state=1待结算)
  ↓
结算金额：
  - 商家结算金额 = 供货价（商品设置时就确定）
  - 平台利润 = 零售价 - 供货价
  ↓
后台查看结算列表
  ↓
手动标记"已结算"（线下转账后）
```

**MVP 简化**：
- 不自动转账，全部线下处理
- 不做批量结算，逐笔确认
- 不做 T+N 限制

**价格模型**：
- 商家上传商品时设置**供货价**（批发价）
- 平台展示**零售价**（含平台利润）
- 用户购买支付**零售价**
- 核销后平台按**供货价**结算给商家

---

## 五、API 接口规划（MVP 简化版）

### 5.1 用户端接口 (/api/wanlshop/voucher)

#### 订单模块

| 接口 | 方法 | 说明 | MVP 必须 |
|-----|------|-----|---------|
| `/order/create` | POST | 创建订单 | ✅ |
| `/order/detail` | GET | 订单详情 | ✅ |
| `/order/lists` | GET | 订单列表（分页） | ✅ |
| `/order/cancel` | POST | 取消订单（仅待支付） | ✅ |

**请求示例 (create)**：
```json
POST /api/wanlshop/voucher/order/create
{
  "goods_id": 123,
  "category_id": 10,
  "quantity": 2
}
```

#### 核销券模块

| 接口 | 方法 | 说明 | MVP 必须 |
|-----|------|-----|---------|
| `/lists` | GET | 我的券列表（分页，可按状态筛选） | ✅ |
| `/detail` | GET | 券详情（含券号、验证码） | ✅ |

**响应示例 (detail)**：
```json
{
  "code": 1,
  "data": {
    "id": 1,
    "voucher_no": "VCH202511140001",
    "verify_code": "123456",
    "face_value": 100.00,
    "goods_title": "美发套餐",
    "state": "1",
    "state_text": "未使用",
    "valid_end": 1700000000
  }
}
```

#### 退款模块

| 接口 | 方法 | 说明 | MVP 必须 |
|-----|------|-----|---------|
| `/refund/apply` | POST | 申请退款 | ✅ |
| `/refund/detail` | GET | 退款详情 | ✅ |
| `/refund/lists` | GET | 退款列表（分页） | ✅ |

### 5.2 商家端接口 (/api/wanlshop/voucher)

#### 核销模块

| 接口 | 方法 | 说明 | MVP 必须 |
|-----|------|-----|---------|
| `/verify/code` | POST | 验证码核销 | ✅ |
| `/verify/records` | GET | 核销记录（分页） | ✅ |

**请求示例 (code)**：
```json
POST /api/wanlshop/voucher/verify/code
{
  "voucher_no": "VCH202511140001"
}
或
{
  "verify_code": "123456"
}
```

#### 结算模块

| 接口 | 方法 | 说明 | MVP 必须 |
|-----|------|-----|---------|
| `/settlement/lists` | GET | 结算列表（分页） | ✅ |
| `/settlement/detail` | GET | 结算详情 | ✅ |

### 5.3 管理后台接口 (/admin/wanlshop/voucher)

| 模块 | 控制器 | 功能 | MVP 必须 |
|-----|--------|-----|---------|
| 订单管理 | VoucherOrder.php | 列表、详情、搜索 | ✅ |
| 券管理 | Voucher.php | 列表、详情、作废 | ✅ |
| 核销管理 | Verification.php | 记录列表、详情 | ✅ |
| 结算管理 | Settlement.php | 列表、详情、标记已结算 | ✅ |
| 退款管理 | Refund.php | 列表、审核（同意/拒绝） | ✅ |

### 5.4 HTTP 测试文件要求

⚠️ **重要**：所有 API 接口必须编写 HTTP 测试文件

**目录结构**：
```
api-tests/wanlshop/voucher/
├── order.http          # 订单接口测试
├── voucher.http        # 核销券接口测试
├── refund.http         # 退款接口测试
├── verify.http         # 核销接口测试
└── settlement.http     # 结算接口测试
```

**测试文件要求**：
1. ✅ 每个接口必须包含完整的请求示例（URL、方法、参数、Header）
2. ✅ 必须覆盖正常流程和异常情况（如参数错误、权限不足等）
3. ✅ 必须包含测试数据说明（如测试用户ID、商品ID等）
4. ✅ 接口响应格式必须与接口规划一致
5. ✅ 开发完成后必须经过实际测试验证

**示例格式**：
```http
### 创建订单 - 正常流程
POST http://localhost:8000/api/wanlshop/voucher/order/create
Content-Type: application/json
Token: {{user_token}}

{
  "goods_id": 123,
  "category_id": 10,
  "quantity": 2
}

### 创建订单 - 商品不存在
POST http://localhost:8000/api/wanlshop/voucher/order/create
Content-Type: application/json
Token: {{user_token}}

{
  "goods_id": 99999,
  "quantity": 1
}
```

---

## 六、实施步骤（MVP 7天）

### 阶段一：数据库与模型层（1天）

**任务清单**：
1. ✅ 编写数据库迁移文件（5张表 + 1个ALTER）
2. ✅ 创建 ThinkPHP 模型类（5个模型）
3. ✅ 定义模型关联关系（hasOne、belongsTo）
4. ✅ 编写基础验证规则（Validate 类）

**文件清单**：
```
application/common/model/
  ├── VoucherOrder.php
  ├── Voucher.php
  ├── VoucherVerification.php
  ├── VoucherSettlement.php
  └── VoucherRefund.php

database/migrations/
  └── 20251114_create_voucher_tables.sql
```

### 阶段二：用户端 API（2天）

**任务清单**：
1. ✅ 订单接口（create、detail、lists、cancel）
2. ✅ 核销券接口（lists、detail）
3. ✅ 退款接口（apply、detail、lists）
4. ✅ 支付回调处理（生成券逻辑）
5. ✅ **编写 HTTP 测试用例**（必须！所有接口都要有测试文件）

**文件清单**：
```
application/api/controller/wanlshop/voucher/
  ├── Order.php
  ├── Voucher.php
  └── Refund.php

api-tests/wanlshop/voucher/
  ├── order.http
  ├── voucher.http
  └── refund.http
```

### 阶段三：商家端 API（1天）

**任务清单**：
1. ✅ 核销接口（code、records）
2. ✅ 结算接口（lists、detail）
3. ✅ **编写 HTTP 测试用例**（必须！所有接口都要有测试文件）

**文件清单**：
```
application/api/controller/wanlshop/voucher/
  ├── Verify.php
  └── Settlement.php

api-tests/wanlshop/voucher/
  ├── verify.http
  └── settlement.http
```

### 阶段四：管理后台（2天）

**任务清单**：
1. ✅ 订单管理（列表、详情、搜索）
2. ✅ 券管理（列表、详情、作废）
3. ✅ 核销管理（记录列表）
4. ✅ 结算管理（列表、标记已结算）
5. ✅ 退款管理（审核、同意/拒绝）
6. ✅ 权限规则注册（grain_auth_rule）
7. ✅ 菜单配置

**文件清单**：
```
application/admin/controller/wanlshop/voucher/
  ├── Order.php
  ├── Voucher.php
  ├── Verification.php
  ├── Settlement.php
  └── Refund.php

application/admin/view/wanlshop/voucher/
  ├── order/
  ├── voucher/
  ├── verification/
  ├── settlement/
  └── refund/
```

### 阶段五：测试与修复（1天）

**任务清单**：
1. ✅ 完整业务流程测试（购买→核销→退款）
2. ✅ 边界测试（重复核销、过期券、退款限制）
3. ✅ **验证所有 HTTP 测试文件**（必须能正常运行，响应格式正确）
4. ✅ Bug 修复
5. ✅ 代码审查（PSR-2 规范）

---

## 七、关键技术点（MVP 简化版）

### 7.1 微信小程序支付配置

**说明**：以下配置仅用于微信小程序 JSAPI 支付，不涉及其他支付方式。

在 `application/extra/wechat.php` 中配置：

```php
return [
    'payment' => [
        'appid' => env('WECHAT_PAYMENT_APPID'),          // 小程序 appid（非公众号 appid）
        'mch_id' => env('WECHAT_PAYMENT_MCH_ID'),        // 商户号
        'key' => env('WECHAT_PAYMENT_KEY'),              // API密钥（V2，如果用V3可不填）
        'apiv3_key' => env('WECHAT_PAYMENT_APIV3_KEY'),  // APIv3密钥
        'cert_path' => env('WECHAT_PAYMENT_CERT_PATH'),  // 证书路径
        'key_path' => env('WECHAT_PAYMENT_KEY_PATH'),    // 证书密钥路径
        'serial_no' => env('WECHAT_PAYMENT_SERIAL_NO'),  // 证书序列号
        'notify_url' => 'https://yourdomain.com/api/wanlshop/voucher/order/notify',
    ],
];
```

**关键配置说明**：
- `appid`：必须使用小程序 appid，不能使用公众号或 APP 的 appid
- `openid`：用户的 openid 需通过小程序 `wx.login` 流程获取
- `notify_url`：支付成功后微信回调地址（必须 HTTPS）

### 7.2 创建订单并调用微信支付

```php
// application/api/controller/wanlshop/voucher/Order.php

public function create()
{
    $userId = $this->auth->id;
    $goodsId = $this->request->post('goods_id');
    $quantity = $this->request->post('quantity', 1);

    // 1. 查询商品信息
    $goods = Goods::find($goodsId);
    if (!$goods) {
        $this->error('商品不存在');
    }

    // 2. 创建订单
    $orderNo = 'ORD' . date('Ymd') . mt_rand(100000, 999999);
    $order = new VoucherOrder();
    $order->user_id = $userId;
    $order->order_no = $orderNo;
    $order->goods_id = $goods->id;
    $order->category_id = $goods->category_id;
    $order->quantity = $quantity;
    $order->supply_price = $goods->supply_price * $quantity;  // 供货价总额
    $order->retail_price = $goods->price * $quantity;         // 零售价总额
    $order->actual_payment = $goods->price * $quantity;       // 实际支付（未考虑优惠）
    $order->state = 1;  // 待支付
    $order->createtime = time();
    $order->save();

    // 3. 调用微信支付统一下单
    $paymentData = $this->createWechatPayment($order);

    $this->success('订单创建成功', [
        'order_id' => $order->id,
        'order_no' => $orderNo,
        'amount' => $order->actual_payment,
        'payment' => $paymentData,  // 前端调起支付的参数
    ]);
}

/**
 * 调用微信支付统一下单（JSAPI）
 */
private function createWechatPayment($order)
{
    $config = config('wechat.payment');
    $user = User::find($order->user_id);

    // 请求参数
    $params = [
        'appid' => $config['appid'],
        'mchid' => $config['mch_id'],
        'description' => '核销券购买',  // 或使用商品标题
        'out_trade_no' => $order->order_no,
        'notify_url' => $config['notify_url'],
        'amount' => [
            'total' => (int)($order->actual_payment * 100),  // 转为分
            'currency' => 'CNY',
        ],
        'payer' => [
            'openid' => $user->openid,  // 用户的openid
        ],
    ];

    // 调用微信支付API（使用你现有的支付库或自己实现）
    // 这里假设使用 EasyWeChat 或类似库
    $app = \EasyWeChat\Factory::payment($config);
    $result = $app->order->unify($params);

    if ($result['return_code'] !== 'SUCCESS' || $result['result_code'] !== 'SUCCESS') {
        throw new Exception('微信支付下单失败：' . ($result['err_code_des'] ?? '未知错误'));
    }

    // 返回前端调起支付需要的参数
    $prepayId = $result['prepay_id'];
    return [
        'appId' => $config['appid'],
        'timeStamp' => (string)time(),
        'nonceStr' => \think\helper\Str::random(32),
        'package' => 'prepay_id=' . $prepayId,
        'signType' => 'RSA',
        'paySign' => $this->generatePaySign($prepayId, $config),
    ];
}

/**
 * 生成前端调起支付的签名
 */
private function generatePaySign($prepayId, $config)
{
    $timestamp = time();
    $nonceStr = \think\helper\Str::random(32);

    // 签名字符串
    $signStr = $config['appid'] . "\n" .
               $timestamp . "\n" .
               $nonceStr . "\n" .
               'prepay_id=' . $prepayId . "\n";

    // 使用商户私钥签名（RSA_SHA256）
    $privateKey = file_get_contents($config['key_path']);
    openssl_sign($signStr, $signature, $privateKey, OPENSSL_ALGO_SHA256);

    return base64_encode($signature);
}
```

### 7.3 微信支付回调处理

```php
// application/api/controller/wanlshop/voucher/Order.php

/**
 * 微信支付回调通知
 */
public function notify()
{
    $config = config('wechat.payment');

    // 1. 获取回调数据
    $headers = $this->request->header();
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    // 2. 验证签名（重要！防止伪造回调）
    if (!$this->verifyWechatSignature($headers, $body, $config)) {
        // 签名验证失败，返回失败响应
        return json([
            'code' => 'FAIL',
            'message' => '签名验证失败',
        ]);
    }

    // 3. 解密回调数据
    $resource = $data['resource'];
    $decryptedData = $this->decryptAesGcm(
        $resource['ciphertext'],
        $config['apiv3_key'],
        $resource['nonce'],
        $resource['associated_data']
    );
    $transaction = json_decode($decryptedData, true);

    // 4. 验证事件类型
    if ($data['event_type'] !== 'TRANSACTION.SUCCESS') {
        return json(['code' => 'SUCCESS', 'message' => '']);
    }

    // 5. 处理订单（事务）
    try {
        $this->handlePaymentSuccess($transaction);

        // 必须在5秒内返回成功响应
        return json(['code' => 'SUCCESS', 'message' => '']);
    } catch (Exception $e) {
        // 记录错误日志
        \think\facade\Log::error('微信支付回调处理失败：' . $e->getMessage());

        // 返回失败，微信会重试
        return json([
            'code' => 'FAIL',
            'message' => $e->getMessage(),
        ]);
    }
}

/**
 * 验证微信签名
 */
private function verifyWechatSignature($headers, $body, $config)
{
    $timestamp = $headers['wechatpay-timestamp'] ?? '';
    $nonce = $headers['wechatpay-nonce'] ?? '';
    $signature = $headers['wechatpay-signature'] ?? '';
    $serial = $headers['wechatpay-serial'] ?? '';

    // 构造签名原文
    $signStr = $timestamp . "\n" .
               $nonce . "\n" .
               $body . "\n";

    // 使用微信平台公钥验证（需要先下载平台证书）
    $platformPublicKey = $this->getPlatformPublicKey($serial);
    if (!$platformPublicKey) {
        return false;
    }

    $signature = base64_decode($signature);
    return openssl_verify(
        $signStr,
        $signature,
        $platformPublicKey,
        OPENSSL_ALGO_SHA256
    ) === 1;
}

/**
 * 解密AES-GCM加密数据
 */
private function decryptAesGcm($ciphertext, $key, $nonce, $associatedData)
{
    $ciphertext = base64_decode($ciphertext);

    // PHP 7.1+ 支持 aes-256-gcm
    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        substr($ciphertext, -16),  // tag
        $associatedData
    );

    return $plaintext;
}

/**
 * 处理支付成功业务逻辑
 */
private function handlePaymentSuccess($transaction)
{
    $outTradeNo = $transaction['out_trade_no'];  // 商户订单号

    Db::startTrans();
    try {
        // 查询订单
        $order = VoucherOrder::where('order_no', $outTradeNo)
            ->lock(true)  // 行锁
            ->find();

        if (!$order) {
            throw new Exception('订单不存在');
        }

        // 防止重复处理
        if ($order->state == 2) {
            Db::commit();
            return;
        }

        // 验证金额
        $totalAmount = $transaction['amount']['total'];  // 分
        if ($totalAmount != $order->actual_payment * 100) {
            throw new Exception('订单金额不匹配');
        }

        // 更新订单状态
        $order->state = 2;
        $order->paymenttime = time();
        $order->save();

        // 查询商品信息
        $goods = Goods::find($order->goods_id);

        // 生成核销券
        for ($i = 0; $i < $order->quantity; $i++) {
            $voucher = new Voucher();
            $voucher->voucher_no = $this->generateVoucherNo();
            $voucher->verify_code = $this->generateVerifyCode();
            $voucher->order_id = $order->id;
            $voucher->user_id = $order->user_id;
            $voucher->category_id = $order->category_id;
            $voucher->goods_id = $order->goods_id;
            $voucher->goods_title = $goods->title;
            $voucher->goods_image = $goods->image;
            $voucher->supply_price = $order->supply_price / $order->quantity;
            $voucher->face_value = $order->actual_payment / $order->quantity;
            $voucher->valid_start = time();
            $voucher->valid_end = strtotime('+30 days');
            $voucher->state = 1;  // 未使用
            $voucher->createtime = time();
            $voucher->save();
        }

        Db::commit();

        // 异步发送通知（不阻塞响应）
        // $this->sendNotification($order);

    } catch (Exception $e) {
        Db::rollback();
        throw $e;
    }
}
```

### 7.4 前端调起支付示例（小程序）

**说明**：以下代码为微信小程序内调起支付的示例，使用 `wx.requestPayment` API。

```javascript
// 小程序前端代码（pages/order/create.js）
Page({
  data: {
    orderId: null,
    paymentData: null
  },

  /**
   * 创建订单
   */
  createOrder() {
    wx.request({
      url: 'https://yourdomain.com/api/wanlshop/voucher/order/create',
      method: 'POST',
      header: {
        'Content-Type': 'application/json',
        'token': wx.getStorageSync('token')  // 用户登录 token
      },
      data: {
        goods_id: 123,
        quantity: 1
      },
      success: (res) => {
        if (res.data.code === 1) {
          this.setData({
            orderId: res.data.data.order_id,
            paymentData: res.data.data.payment
          });
          // 调起微信支付
          this.callWechatPay(res.data.data.payment);
        } else {
          wx.showToast({
            title: res.data.msg,
            icon: 'none'
          });
        }
      },
      fail: (err) => {
        wx.showToast({
          title: '请求失败',
          icon: 'none'
        });
      }
    });
  },

  /**
   * 调起微信支付（小程序 JSAPI 支付）
   */
  callWechatPay(paymentData) {
    wx.requestPayment({
      timeStamp: paymentData.timeStamp,
      nonceStr: paymentData.nonceStr,
      package: paymentData.package,      // 格式: prepay_id=wx...
      signType: paymentData.signType,    // RSA
      paySign: paymentData.paySign,
      success: (res) => {
        // 支付成功（前端回调不可靠，仅用于UI提示）
        wx.showToast({
          title: '支付成功',
          icon: 'success'
        });

        // 跳转到订单详情或券列表
        setTimeout(() => {
          wx.redirectTo({
            url: `/pages/voucher/list`
          });
        }, 1500);
      },
      fail: (res) => {
        // 支付失败或取消
        if (res.errMsg === 'requestPayment:fail cancel') {
          wx.showToast({
            title: '支付已取消',
            icon: 'none'
          });
        } else {
          wx.showToast({
            title: '支付失败',
            icon: 'none'
          });
        }
      },
      complete: (res) => {
        console.log('支付完成', res);
      }
    });
  }
});
```

**关键点**：
- 必须在小程序环境内调用 `wx.requestPayment`
- 前端支付回调不可靠，仅用于 UI 反馈
- 真正的支付确认依赖后端微信支付回调（notify_url）
- 支付参数中的 `package` 格式必须为 `prepay_id=***`

### 7.5 订单号生成规则

```php
// ORD + YYYYMMDD + 6位随机数
$orderNo = 'ORD' . date('Ymd') . mt_rand(100000, 999999);

// 券号: VCH + YYYYMMDD + 6位随机数
$voucherNo = 'VCH' . date('Ymd') . mt_rand(100000, 999999);

// 结算单号: STL + YYYYMMDD + 6位随机数
$settlementNo = 'STL' . date('Ymd') . mt_rand(100000, 999999);

// 退款单号: RFD + YYYYMMDD + 6位随机数
$refundNo = 'RFD' . date('Ymd') . mt_rand(100000, 999999);
```

### 7.2 验证码生成

```php
// 6位数字验证码
$verifyCode = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
```

### 7.3 核销防重逻辑（MVP 简化）

```php
// 使用数据库事务 + WHERE 状态判断
Db::startTrans();
try {
    // 查询并锁定行（FOR UPDATE）
    $voucher = Voucher::where('voucher_no', $voucherNo)
        ->where('state', '1')  // 只有未使用的券
        ->lock(true)  // 行锁
        ->find();

    if (!$voucher) {
        throw new Exception('券不存在或已使用');
    }

    // 检查有效期
    if ($voucher->valid_end < time()) {
        throw new Exception('券已过期');
    }

    // 更新券状态
    $voucher->state = 2;
    $voucher->shop_id = $shopId;
    $voucher->verifytime = time();
    $voucher->save();

    // 插入核销记录
    $verification = new VoucherVerification();
    $verification->voucher_id = $voucher->id;
    $verification->shop_id = $shopId;
    $verification->supply_price = $voucher->supply_price;
    $verification->face_value = $voucher->face_value;
    // ...
    $verification->save();

    // 创建结算记录
    $settlement = new VoucherSettlement();
    $settlement->settlement_no = 'STL' . date('Ymd') . mt_rand(100000, 999999);
    $settlement->voucher_id = $voucher->id;
    $settlement->voucher_no = $voucher->voucher_no;
    $settlement->order_id = $voucher->order_id;
    $settlement->shop_id = $shopId;
    $settlement->user_id = $voucher->user_id;
    $settlement->retail_price = $voucher->face_value;
    $settlement->supply_price = $voucher->supply_price;
    $settlement->shop_amount = $voucher->supply_price;
    $settlement->platform_amount = $voucher->face_value - $voucher->supply_price;
    $settlement->state = 1;
    $settlement->createtime = time();
    $settlement->save();

    Db::commit();
} catch (Exception $e) {
    Db::rollback();
    throw $e;
}
```

### 7.4 支付回调处理（MVP 简化）

```php
// 支付成功回调
public function notifyCallback($orderNo)
{
    Db::startTrans();
    try {
        // 1. 更新订单状态
        $order = VoucherOrder::where('order_no', $orderNo)->find();
        if ($order->state != 1) {
            throw new Exception('订单状态异常');
        }

        $order->state = 2;
        $order->paymenttime = time();
        $order->save();

        // 2. 查询商品信息（获取供货价）
        $goods = Goods::find($order->goods_id);

        // 3. 生成核销券
        for ($i = 0; $i < $order->quantity; $i++) {
            $voucher = new Voucher();
            $voucher->voucher_no = $this->generateVoucherNo();
            $voucher->verify_code = $this->generateVerifyCode();
            $voucher->order_id = $order->id;
            $voucher->user_id = $order->user_id;
            $voucher->category_id = $order->category_id;
            $voucher->goods_id = $order->goods_id;
            $voucher->goods_title = $goods->title;
            $voucher->goods_image = $goods->image;
            $voucher->supply_price = $order->supply_price / $order->quantity;
            $voucher->face_value = $order->actual_payment / $order->quantity;
            $voucher->valid_start = time();
            $voucher->valid_end = strtotime('+30 days');
            $voucher->save();
        }

        Db::commit();
    } catch (Exception $e) {
        Db::rollback();
        throw $e;
    }
}

// 券号生成（保证唯一）
private function generateVoucherNo()
{
    do {
        $no = 'VCH' . date('Ymd') . mt_rand(100000, 999999);
    } while (Voucher::where('voucher_no', $no)->count() > 0);

    return $no;
}
```

### 7.5 结算金额计算（供货价模式）

```php
// 核销时创建结算记录
$settlement = new VoucherSettlement();
$settlement->settlement_no = 'STL' . date('Ymd') . mt_rand(100000, 999999);
$settlement->voucher_id = $voucher->id;
$settlement->voucher_no = $voucher->voucher_no;
$settlement->order_id = $voucher->order_id;
$settlement->shop_id = $shopId;
$settlement->user_id = $voucher->user_id;

// 价格字段
$settlement->retail_price = $voucher->face_value;  // 零售价（用户支付）
$settlement->supply_price = $voucher->supply_price; // 供货价（商家结算）
$settlement->shop_amount = $voucher->supply_price;  // 商家结算金额 = 供货价
$settlement->platform_amount = $voucher->face_value - $voucher->supply_price; // 平台利润

$settlement->state = 1; // 待结算
$settlement->createtime = time();
$settlement->save();
```

**价格示例**：
```
零售价（用户支付）：100 元
供货价（商家设置）：80 元
商家结算金额：80 元
平台利润：20 元
```

---

## 八、注意事项与风险（MVP 版）

### 8.1 兼容性

✅ **已验证**：
- 现有 User/Shop/Goods/Category 表全部复用
- 支付表仅增加枚举值，不影响现有查询
- 所有现有接口保持不变

### 8.2 性能

⚠️ **已知限制**：
- 核销并发：依赖数据库行锁（InnoDB），单表 QPS < 1000
- 大数据查询：需要手动加索引（已在 DDL 中定义）

**后续优化方向**：
- Redis 缓存券信息
- Redis 分布式锁
- 读写分离

### 8.3 安全

✅ **MVP 已实现**：
- 参数验证（Validate 类）
- SQL 注入防护（ORM 查询）
- 状态机控制（防重复核销）

⚠️ **未实现（后续优化）**：
- 券码水印
- IP/设备追踪
- 风控系统

### 8.4 业务

⚠️ **MVP 限制**：
- 退款全部人工审核（不自动）
- 结算全部线下处理（不自动转账）
- 过期券不自动处理（后续增加定时任务）
- 不支持优惠券（后续扩展）

---

## 九、配置项（MVP 版）

在 `application/extra/voucher.php` 中配置：

```php
return [
    // 券有效期（天）
    'valid_days' => 30,

    // 是否允许退款
    'allow_refund' => true,

    // 退款期限（有效期前N天内可退款）
    'refund_days' => 7,
];
```

**价格说明**：
- 商家在上传商品时设置**供货价**（字段：supply_price）
- 平台在展示商品时设置**零售价**（字段：retail_price 或 price）
- 用户购买支付**零售价**
- 核销后平台按**供货价**结算给商家
- 平台利润 = 零售价 - 供货价

**商品表字段要求**：
- `supply_price` decimal(10,2) - 供货价（商家设置）
- `price` decimal(10,2) - 零售价（平台展示）

---

## 十、总结

### MVP 核心特点

1. **简单可用**：7天交付，核心功能完整
2. **无过度设计**：不用 Redis、不做自动化、不做复杂统计
3. **易扩展**：数据表预留字段，后续可平滑升级
4. **兼容性强**：不破坏现有业务，仅增加新模块
5. **供货价模式**：商家设置供货价，平台展示零售价，核销时按供货价结算

### 价格模型

```
商家上传商品:
  - 供货价: 80元（商家批发价）
  - 平台设置零售价: 100元

用户购买:
  - 支付金额: 100元

核销结算:
  - 商家收入: 80元（供货价）
  - 平台利润: 20元（差价）
```

### 交付清单

- ✅ 5 张数据表 + DDL（含供货价字段）
- ✅ 5 个模型类 + 验证规则
- ✅ 11 个 API 接口（用户端 + 商家端）
- ✅ **5 个 HTTP 测试文件**（order.http、voucher.http、refund.http、verify.http、settlement.http）
- ✅ 5 个后台管理模块
- ✅ 权限规则配置

### 实施周期

- **总工期**：7 天
- **核心开发**：5 天
- **测试修复**：1 天
- **部署上线**：1 天

### 后续优化方向

1. **性能优化**：Redis 缓存 + 分布式锁
2. **自动化**：定时任务（过期券、自动结算）
3. **通知**：短信/微信推送
4. **统计**：报表、数据分析
5. **风控**：刷单检测、限额限频
6. **价格策略**：动态定价、阶梯价格

---

**文档版本**：v1.0.0-MVP（供货价模式）
**编写日期**：2025-11-14
**实施周期**：7 天
**作者**：Claude Code
**核心特性**：供货价结算，无抽佣概念
