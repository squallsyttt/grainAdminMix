## 地区信息对接说明（基于商品 region_city_name）

新增/透出的字段：`region_city_name`（字符串，来自商品的发布城市，若订单含多件商品则用去重后的城市名以 `/` 连接；无值时为空字符串）。

### 后台/商家端
- 后台订单列表/详情：`/wanlshop/order/index`、`/wanlshop/order/detail` 返回 `region_city_name`
- 商家端订单列表/详情：`/wanlshop/order/index`（index 模块）、`/wanlshop/order/detail` 返回 `region_city_name`
- 后台核销券订单列表/详情：`/wanlshop/voucher.order/index`、`/wanlshop/voucher.order/detail` 返回 `region_city_name`
- 后台核销券核销记录：`/wanlshop/voucher.verification/index` 返回 `region_city_name`

### C 端核销券接口（api-tests/wanlshop/voucher/*.http）
- `/api/wanlshop/voucher/lists`：每张券新增 `region_city_name`
- `/api/wanlshop/voucher/detail`：券对象新增 `region_city_name`
- `/api/wanlshop/voucher/order/lists`：订单对象新增 `region_city_name`，`items[*].region_city_name`
- `/api/wanlshop/voucher/order/detail`：订单对象新增 `region_city_name`，明细 items 同样带 `region_city_name`

说明：未修改数据表结构，全部通过订单商品关联的商品表 `region_city_name` 汇总获得。
