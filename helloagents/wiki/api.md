# API 手册（摘要）

> 仅记录与本次修复相关的关键接口与约束。

## 后台接口（admin）

- 结算管理列表：`wanlshop/voucher.settlement/index`
- 结算打款：`wanlshop/voucher.settlement/transfer`
- 重试打款：`wanlshop/voucher.settlement/retry`

### 结算打款约束

- 当券存在退款记录且状态为：申请中/已同意退款/退款成功 时，必须禁止结算打款（服务端强制拦截）。

