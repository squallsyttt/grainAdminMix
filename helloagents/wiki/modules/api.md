# 模块：api

## 职责

- 对外/小程序 API，包括核销、结算回调、退款回调等。

## 相关实现（核销券-结算回调）

- `application/api/controller/wanlshop/voucher/Verify.php`：核销成功后创建结算记录。
- `application/api/controller/wanlshop/voucher/Settlement.php`：微信转账回调处理，更新结算状态。

