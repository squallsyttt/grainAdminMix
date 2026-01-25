# 架构设计

## 总体架构

- 管理后台（admin）：FastAdmin 控制器 + Model
- API（api）：对外接口（核销、结算回调等）
- 第三方支付：微信支付（退款、转账到零钱）

## 核心数据表（核销券相关）

- `grain_wanlshop_voucher`：核销券主表（包含状态：未使用/已核销/退款中/已退款等）
- `grain_wanlshop_voucher_verification`：核销记录
- `grain_wanlshop_voucher_settlement`：结算记录（待结算/打款中/打款失败/已结算）
- `grain_wanlshop_voucher_refund`：退款记录（申请中/同意退款/拒绝退款/退款成功）

## 关键约束

- 结算打款必须在服务端校验券是否存在“申请中/已同意/退款成功”的退款记录，避免已退款仍出款。

