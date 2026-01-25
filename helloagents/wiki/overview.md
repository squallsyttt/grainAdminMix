# GrainAdminMix（项目概览）

## 1. 项目概述

本项目为 GrainAdminMix 后台系统，包含核销券（Voucher）核销、退款、结算打款等功能。

## 2. 模块索引

| 模块 | 职责 | 文档 |
|------|------|------|
| admin | 后台管理端（FastAdmin） | `modules/admin.md` |
| api | 对外/小程序 API | `modules/api.md` |
| index | 前台/商家端页面与接口 | `modules/index.md` |
| addons | 插件与支付/第三方集成 | `modules/addons.md` |

## 3. 关键流程（核销券）

- 核销：券从“未使用”变为“已核销”，同时生成核销记录与结算记录。
- 退款：存在“核销后退款”场景，退款成功后券状态变为“已退款”。
- 结算：结算记录用于给商家打款；当券进入“退款中/已退款”时必须禁止继续结算。

