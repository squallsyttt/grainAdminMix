# 模块：admin

## 职责

- 提供后台管理端页面与操作入口（核销券、退款、结算等）。

## 相关实现（核销券-结算拦截）

- `application/admin/controller/wanlshop/voucher/Settlement.php`：结算管理接口（列表/打款/标记已结算）。
- `application/admin/service/SettlementTransferService.php`：结算打款服务（服务端最终兜底拦截）。

