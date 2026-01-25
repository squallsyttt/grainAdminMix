# 数据模型（摘要）

> 仅记录与本次修复相关的字段语义与约束。

## `grain_wanlshop_voucher_refund`

- `voucher_id`：关联券
- `state`：
  - `0` 申请中
  - `1` 同意退款
  - `2` 拒绝退款
  - `3` 退款成功

## 结算拦截规则

- 当存在 `grain_wanlshop_voucher_refund` 记录且 `state IN (0,1,3)` 时：
  - 结算打款/重试打款/标记已结算 必须被拦截

