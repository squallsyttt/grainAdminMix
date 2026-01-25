# 项目技术约定（GrainAdminMix）

## 技术栈
- 后端：ThinkPHP 5.x + FastAdmin
- 前端：RequireJS + Bootstrap + AdminLTE
- 数据库：MySQL（库名：`grainPro`，表前缀：`grain_`）

## 开发约定
- PHP 遵循 PSR-2；变量/函数/类命名使用英文；注释与文档使用中文。
- 不修改 FastAdmin 核心文件，尽量以最小必要改动完成需求。

## 资金相关安全约定
- 涉及“结算/退款/转账”等资金流程的变更必须：
  - 服务端校验兜底（前端按钮禁用仅作提示）
  - 关键流程保持幂等/可追溯（日志/记录）
  - 对“退款中/已退款”状态做严格拦截，避免重复出款

