# 模块：admin

## 职责

- 提供后台管理端页面与操作入口（核销券、退款、结算等）。

## 相关实现（核销券-结算拦截）

- `application/admin/controller/wanlshop/voucher/Settlement.php`：结算管理接口（列表/打款/标记已结算）。
- `application/admin/service/SettlementTransferService.php`：结算打款服务（服务端最终兜底拦截）。

## 后台展示约定

- 结算管理列表的“结算状态”：
  - 默认按结算单状态展示（待结算/打款中/打款失败/已结算）
  - 当券存在退款记录（申请中/已同意/退款成功）或券状态为退款相关时，优先展示“退款中/已退款”（已结算则展示“已结算 / 已退款”）
