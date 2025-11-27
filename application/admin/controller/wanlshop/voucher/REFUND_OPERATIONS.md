# 退款操作影响表说明

## 涉及的数据库表

| 表名 | 说明 |
|------|------|
| `grain_wanlshop_voucher_refund` | 核销券退款表 |
| `grain_wanlshop_voucher` | 核销券表 |
| `grain_wanlshop_voucher_order` | 核销券订单表（一个订单可包含多张券） |
| `grain_wanlshop_payment_callback_log` | 支付/退款回调日志表（复用） |

---

## 操作影响总结

| 操作 | grain_wanlshop_voucher_refund | grain_wanlshop_voucher | grain_wanlshop_voucher_order |
|------|------------------------------|------------------------|------------------------------|
| **同意退款 (approve)** | state: 0→1 (申请中→同意退款) | state: →5 (退款中) | - |
| **拒绝退款 (reject)** | state: 0→2 (申请中→拒绝退款), refuse_reason: 填入拒绝理由 | state: 5→1 (退款中→未使用) | - |
| **微信退款成功回调** | state: 1→3 (同意退款→退款成功) | state: 5→4 (退款中→已退款), refundtime: 当前时间戳 | state: 2→4 (已支付→存在退款) |
| **确认完成 (complete)** | state: 1→3 (同意退款→退款成功) | state: 5→4 (退款中→已退款), refundtime: 当前时间戳 | state: 2→4 (已支付→存在退款) |

---

## 状态枚举值说明

### grain_wanlshop_voucher_refund.state

| 值 | 含义 |
|----|------|
| 0 | 申请中 |
| 1 | 同意退款 |
| 2 | 拒绝退款 |
| 3 | 退款成功 |

### grain_wanlshop_voucher.state

| 值 | 含义 |
|----|------|
| 1 | 未使用 |
| 2 | 已核销 |
| 3 | 已过期 |
| 4 | 已退款 |
| 5 | 退款中 |

### grain_wanlshop_voucher_order.state

| 值 | 含义 |
|----|------|
| 1 | 待支付 |
| 2 | 已支付 |
| 3 | 已取消 |
| 4 | 存在退款（订单内有券已退款） |

> **注意**: 一个订单可包含多张核销券，当其中任意一张券完成退款时，订单状态变为"存在退款"。

---

## 微信退款集成

### 退款流程（集成微信支付）

```
用户申请退款 → 退款单状态=0(申请中), 券状态保持
       ↓
管理员点击"同意退款"
       ↓
  ┌─ 更新本地状态 → 退款单状态=1(同意退款), 券状态=5(退款中)
  │
  └─ 调用微信退款API (POST /v3/refund/domestic/refunds)
       │
       ├─ 成功 → 等待微信异步通知
       │    ↓
       │  微信回调 refundNotify (event_type=REFUND.SUCCESS)
       │    ↓
       │  退款单状态=3(退款成功), 券状态=4(已退款), 订单状态=4(存在退款)
       │  记录到 grain_wanlshop_payment_callback_log (order_type='voucher_refund')
       │
       └─ 失败 → 回滚本地状态 → 退款单状态=0(申请中), 券状态=1(未使用)

管理员点击"拒绝退款"
       ↓
  退款单状态=2(拒绝退款), 券状态=1(未使用，恢复可用)
```

### 微信退款回调事件类型

| event_type | 说明 | 处理方式 |
|------------|------|----------|
| `REFUND.SUCCESS` | 退款成功 | 更新状态为退款成功 |
| `REFUND.ABNORMAL` | 退款异常 | 记录日志，需人工处理 |
| `REFUND.CLOSED` | 退款关闭 | 恢复券状态为未使用 |

### 回调日志表复用说明

退款回调复用 `grain_wanlshop_payment_callback_log` 表：

| 字段 | 支付回调 | 退款回调 |
|------|----------|----------|
| `order_type` | `voucher` | `voucher_refund` |
| `order_no` | 订单号 (ORD...) | 退款单号 (RFD...) |
| `transaction_id` | 微信支付流水号 | 微信退款单号 |
| `trade_state` | SUCCESS/等 | SUCCESS/ABNORMAL/CLOSED |

### 配置项

在 `.env` 文件中配置退款回调地址：

```ini
[wechat_payment]
refund_notify_url = https://yourdomain.com/api/wanlshop/voucher/order/refundNotify
```

---

## 相关代码文件

### 控制器
- 后台退款管理: `/application/admin/controller/wanlshop/voucher/Refund.php`
- API 回调接口: `/application/api/controller/wanlshop/voucher/Order.php`
  - `refundNotify()` - 微信退款结果回调

### 模型
- `/application/admin/model/wanlshop/VoucherRefund.php`
- `/application/admin/model/wanlshop/Voucher.php`
- `/application/admin/model/wanlshop/VoucherOrder.php`
- `/application/common/model/PaymentCallbackLog.php` - 支付/退款回调日志

### 库文件
- `/application/common/library/WechatPayment.php`
  - `refund()` - 申请退款
  - `queryRefund()` - 查询退款

### 配置
- `/application/extra/wechat.php` - 微信支付配置
- `/.env` - 环境变量配置

---

## 注意事项

1. **退款金额**：单张券的退款金额 (`refund_amount`) 不能超过用户实际支付金额
2. **退款时效**：微信支付退款需在交易完成后 1 年内发起
3. **部分退款**：一笔订单最多支持 50 次部分退款，间隔需大于 1 分钟
4. **异步通知**：退款结果通过异步回调通知，不要依赖同步返回结果
5. **幂等性**：使用相同的 `out_refund_no` 多次请求只会退一笔，保证幂等性
6. **回调延迟**：微信退款回调通常需要几秒钟才能到达，属于正常现象
