# 小程序接口文档汇总

> 本文档按页面分类整理所有API接口，包含接口定义、请求参数、响应格式和Mock数据说明。
>
> **生成时间**: 2025-10-15
> **基础URL**: `http://localhost:8080`

---

## 目录

- [全局配置](#全局配置)
- [1. 首页 (index)](#1-首页-index)
- [2. 资讯页 (news)](#2-资讯页-news)
- [3. 商家相关页面](#3-商家相关页面)
  - [3.1 商家列表 (merchant)](#31-商家列表-merchant)
  - [3.2 商家详情 (merchant-detail)](#32-商家详情-merchant-detail)
  - [3.3 商家扫码 (merchant-scan)](#33-商家扫码-merchant-scan)
- [4. 购物车 (cart)](#4-购物车-cart)
- [5. 商品详情 (product/detail)](#5-商品详情-productdetail)
- [6. 我的页面 (mine)](#6-我的页面-mine)
- [7. 核销券相关页面](#7-核销券相关页面)
  - [7.1 核销券列表 (voucher/list)](#71-核销券列表-voucherlist)
  - [7.2 核销券详情 (voucher/detail)](#72-核销券详情-voucherdetail)
- [8. 订单相关页面](#8-订单相关页面)
  - [8.1 订单列表 (order/list)](#81-订单列表-orderlist)
  - [8.2 订单详情 (order/detail)](#82-订单详情-orderdetail)
- [9. 门店相关接口](#9-门店相关接口)
- [附录](#附录)
  - [通用响应格式](#通用响应格式)
  - [错误码说明](#错误码说明)

---

## 全局配置

### 认证方式

所有需要登录的接口支持以下两种方式传递Token：

1. **Header方式**（推荐）
   ```
   token: your_token_here
   ```

2. **Query参数方式**
   ```
   ?token=your_token_here
   ```

### 超时设置

- 默认超时：10秒（10000ms）
- 可在请求时自定义超时时间

### Token存储

- 存储Key: `user_token`
- 使用Taro存储API: `Taro.getStorageSync()` / `Taro.setStorageSync()`

---

## 1. 首页 (index)

### 1.1 获取横幅广告位配置

**接口路径**: `GET https://api.example.com/ad/horizontal`

**接口文件**: `src/services/ad.ts` - `fetchAdConfig()`

**请求参数**: 无

**响应格式**:
```typescript
{
  code: 200,
  data: {
    visible: boolean,        // 是否显示广告
    imageUrl: string,        // 广告图片URL
    title: string,           // 广告标题
    subtitle: string,        // 广告副标题
    linkUrl: string,         // 点击跳转链接
    backgroundColor: string  // 背景颜色
  }
}
```

**注意事项**:
- 接口失败时返回 `visible: false`，不显示广告
- TODO: 需替换为真实的API地址

---

## 2. 资讯页 (news)

**说明**: 当前资讯页无后端接口，内容静态展示。

---

## 3. 商家相关页面

### 3.1 商家列表 (merchant)

#### 获取商家列表

**接口路径**: `GET /api/merchant/list`

**接口文件**: `src/services/merchant.ts` - `getMerchantList()`

**开发模式**: 使用Mock数据（`USE_MOCK = true`）

**请求参数**:
```typescript
{
  province?: string,  // 省份名称，如"黑龙江省"
  city?: string,      // 城市名称，如"哈尔滨市"
  page?: number,      // 页码，从1开始，默认1
  limit?: number,     // 每页数量，默认20
  filter?: string     // 筛选条件(JSON字符串)，如'{"is_active":1}'
}
```

**响应格式**:
```typescript
{
  code: 0,
  msg: "获取成功",
  data: {
    total: number,          // 总记录数
    per_page: number,       // 每页数量
    current_page: number,   // 当前页码
    last_page: number,      // 总页数
    data: [                 // 商家列表
      {
        id: number,                    // 商家ID
        name: string,                  // 商家名称
        logo: string,                  // 商家Logo URL（可选）
        region_id: number,             // 所属区域ID
        region_name: string,           // 所属区域名称
        province: string,              // 所属省份
        city: string,                  // 所属城市
        address: string,               // 详细地址
        phone: string,                 // 联系电话
        business_hours: string,        // 营业时间
        description: string,           // 商家简介
        is_active: 0 | 1,              // 是否营业(1=营业,0=关闭)
        rating: number,                // 用户评分(0-5)
        years_in_business: number,     // 经营年限
        certification_status: string,  // 认证状态('verified'|'pending'|'none')
        created_at: string,            // 创建时间
        updated_at: string,            // 更新时间
        distance?: number,             // 距离用户的距离(公里)
        product_tags?: string[]        // 支持的产品分类标签
      }
    ]
  }
}
```

**Mock数据**: `src/data/mock/merchant.ts` - `mockMerchantList`

**示例数据**:
```typescript
{
  id: 1,
  name: "五常大米直营店",
  logo: "https://picsum.photos/seed/merchant1/200/200",
  province: "黑龙江省",
  city: "哈尔滨市",
  address: "哈尔滨市道里区中央大街100号",
  phone: "0451-12345678",
  business_hours: "08:00-20:00",
  description: "专营五常大米，品质保证，产地直供。",
  is_active: 1,
  rating: 4.8,
  years_in_business: 15,
  certification_status: "verified",
  distance: 2.5,
  product_tags: ["五常大米", "有机大米", "长粒香"]
}
```

---

### 3.2 商家详情 (merchant-detail)

#### 获取商家详情

**接口路径**: `GET /api/merchant/detail`

**接口文件**: `src/services/merchant.ts` - `getMerchantDetail()`

**开发模式**: 使用Mock数据（`USE_MOCK = true`）

**请求参数**:
```typescript
{
  id: number  // 商家ID
}
```

**响应格式**:
```typescript
{
  code: 0,
  msg: "获取成功",
  data: {
    id: number,
    name: string,
    logo: string,
    region_id: number,
    region_name: string,
    province: string,
    city: string,
    address: string,
    phone: string,
    business_hours: string,
    description: string,          // 详细描述（比列表更详细）
    is_active: 0 | 1,
    rating: number,
    years_in_business: number,
    certification_status: string,
    created_at: string,
    updated_at: string
  }
}
```

**Mock数据**: `src/data/mock/merchant.ts` - `mockMerchantDetails`

#### 获取商家产品列表

**接口路径**: `GET /api/merchant/{id}/products`

**接口文件**: `src/services/merchant.ts` - `getMerchantProducts()`

**开发模式**: 使用Mock数据（`USE_MOCK = true`）

**请求参数**:
```typescript
{
  id: number,        // 商家ID（路径参数）
  province?: string, // 省份名称，用于获取区域价格
  city?: string      // 城市名称，用于获取区域价格
}
```

**响应格式**:
```typescript
{
  code: 0,
  msg: "获取成功",
  data: {
    products: [
      {
        category_id: number,      // 产品分类ID
        category_name: string,    // 产品分类名称
        category_image: string,   // 产品分类图片URL
        description: string,      // 产品分类描述
        price: number,            // 当前区域价格(元)
        price_unit: string,       // 价格单位，如"元/kg"
        is_available: boolean,    // 是否有货
        stock_status: string      // 库存状态描述
      }
    ]
  }
}
```

**Mock数据**: `src/data/mock/merchant.ts` - `mockMerchantProducts`

**示例数据**:
```typescript
{
  category_id: 1,
  category_name: "五常稻花香",
  category_image: "https://picsum.photos/seed/rice1/300/300",
  description: "正宗五常稻花香2号，米粒饱满，香味浓郁",
  price: 25.80,
  price_unit: "元/kg",
  is_available: true,
  stock_status: "库存充足"
}
```

---

### 3.3 商家扫码 (merchant-scan)

#### 根据券码获取核销券详情

**接口路径**: `GET /api/voucher/detail`

**接口文件**: `src/services/voucher.ts` - `getVoucherByCode()`

**请求参数**:
```typescript
{
  code: string  // 核销券码
}
```

**响应格式**: 见 [7.2 核销券详情](#72-核销券详情-voucherdetail)

#### 确认核销

**接口路径**: `POST /api/writeoff/confirm`

**接口文件**: `src/services/voucher.ts` - `writeOffVoucher()`

**请求参数**:
```typescript
{
  voucher_id: number,  // 核销券ID
  store_id: number     // 门店ID
}
```

**响应格式**:
```typescript
{
  code: 1,
  msg: "核销成功",
  time: number,
  data: {
    success: boolean,      // 是否成功
    message: string,       // 结果消息
    verified_at: number,   // 核销时间戳(秒)
    verified_by: string    // 核销员工
  }
}
```

**类型定义**: `src/types/writeoff.ts` - `WriteOffResult`

---

## 4. 购物车 (cart)

**说明**: 购物车数据存储在本地，使用Taro存储API。

### 存储键

- 存储Key: `regional_cart_data`

### 数据结构

```typescript
{
  "省份-城市": [  // 地区键，如"黑龙江省-哈尔滨市"
    {
      product: {
        id: string,           // 商品ID
        name: string,         // 商品名称
        price: number,        // 商品价格(元)
        image: string,        // 商品图片URL
        unit: string,         // 商品单位
        stock: number,        // 库存数量
        status: string,       // 商品状态
        description: string   // 商品描述
      },
      quantity: number,       // 购买数量(1-999)
      addedAt: number         // 添加时间戳(毫秒)
    }
  ]
}
```

### 相关工具函数

**文件**: `src/types/cart.ts`

- `loadCartFromStorage()` - 从存储读取购物车
- `saveCartToStorage()` - 保存购物车到存储
- `getRegionKey(province, city)` - 生成地区键
- `calculateCartStats(items)` - 计算购物车统计信息

---

## 5. 商品详情 (product/detail)

**说明**: 商品详情页面通常调用商家产品接口获取数据。

**相关接口**: 见 [3.2 获取商家产品列表](#获取商家产品列表)

**类型定义**: `src/types/product.ts` - `Product`

---

## 6. 我的页面 (mine)

### 6.1 获取用户信息

**接口路径**: `GET /api/user/info`

**接口文件**: `src/services/user.ts` - `getUserInfo()`

**开发模式**: 使用Mock数据（`USE_MOCK = true`）

**请求参数**: 需要Token认证

**响应格式**:
```typescript
{
  code: 1,
  msg: "操作成功",
  time: number,
  data: {
    id: number,                  // 用户ID
    avatar: string,              // 头像URL
    nickname: string,            // 用户昵称
    phone: string,               // 手机号
    member_level: string,        // 会员等级
    member_level_name: string,   // 会员等级显示名称
    member_icon: string,         // 会员等级图标URL
    register_time: number        // 注册时间(Unix时间戳,秒)
  }
}
```

**Mock数据**: `src/data/mock/user.ts` - `mockUserInfo`

**示例数据**:
```typescript
{
  id: 1,
  avatar: "https://img.yzcdn.cn/vant/cat.jpeg",
  nickname: "张三",
  phone: "13800138000",
  memberLevel: "vip1",
  memberLevelName: "VIP1会员",
  memberIcon: "https://img.yzcdn.cn/vant/icon-vip.png",
  registerTime: 1672502400
}
```

---

### 6.2 获取核销券统计

**接口路径**: `GET /api/user/voucher_stats`

**接口文件**: `src/services/user.ts` - `getVoucherStats()`

**开发模式**: 使用Mock数据（`USE_MOCK = true`）

**请求参数**: 需要Token认证

**响应格式**:
```typescript
{
  code: 1,
  msg: "操作成功",
  time: number,
  data: {
    pending: number,        // 待使用数量
    used: number,           // 已使用数量
    expired: number,        // 已过期数量
    expiring_soon: number   // 即将过期数量
  }
}
```

**Mock数据**: `src/data/mock/user.ts` - `mockVoucherStats`

---

### 6.3 获取订单统计

**接口路径**: `GET /api/user/order_stats`

**接口文件**: `src/services/user.ts` - `getOrderStats()`

**开发模式**: 使用Mock数据（`USE_MOCK = true`）

**请求参数**: 需要Token认证

**响应格式**:
```typescript
{
  code: 1,
  msg: "操作成功",
  time: number,
  data: {
    total: number,      // 总订单数
    pending: number,    // 待支付订单数
    verified: number    // 已核销订单数
  }
}
```

**Mock数据**: `src/data/mock/user.ts` - `mockOrderStats`

---

### 6.4 获取最近核销券列表

**接口路径**: `GET /api/user/recent_vouchers`

**接口文件**: `src/services/user.ts` - `getRecentVouchers()`

**开发模式**: 使用Mock数据（`USE_MOCK = true`）

**请求参数**:
```typescript
{
  limit?: number  // 返回数量，默认3
}
```

**响应格式**:
```typescript
{
  code: 1,
  msg: "操作成功",
  time: number,
  data: [
    {
      id: number,
      title: string,              // 核销券标题
      product_image: string,      // 商品图片URL
      product_name: string,       // 商品名称
      purchase_time: number,      // 购买时间(Unix时间戳,秒)
      expire_at: number,          // 过期时间(Unix时间戳,秒)
      days_remaining: number      // 剩余天数（前端计算）
    }
  ]
}
```

**Mock数据**: `src/data/mock/user.ts` - `mockRecentVouchers`

---

### 6.5 获取最近订单列表

**接口路径**: `GET /api/user/recent_orders`

**接口文件**: `src/services/user.ts` - `getRecentOrders()`

**开发模式**: 使用Mock数据（`USE_MOCK = true`）

**请求参数**:
```typescript
{
  limit?: number  // 返回数量，默认3
}
```

**响应格式**:
```typescript
{
  code: 1,
  msg: "操作成功",
  time: number,
  data: [
    {
      id: number,
      order_no: string,          // 订单号
      product_image: string,     // 商品图片URL
      product_name: string,      // 商品名称
      status: string,            // 订单状态
      status_text: string,       // 订单状态文本
      create_time: number        // 创建时间(Unix时间戳,秒)
    }
  ]
}
```

**Mock数据**: `src/data/mock/user.ts` - `mockRecentOrders`

---

## 7. 核销券相关页面

### 7.1 核销券列表 (voucher/list)

#### 获取核销券列表

**接口路径**: `GET /api/voucher/lists`

**接口文件**: `src/services/voucher.ts` - `getVoucherList()`

**请求参数**:
```typescript
{
  token?: string,     // Token(可通过Header或Query传递)
  filter?: string,    // 筛选条件(JSON字符串)，如'{"status":"unused"}'
  page?: number,      // 页码，从1开始，默认1
  limit?: number,     // 每页数量，默认20
  sort?: string,      // 排序字段
  order?: 'asc'|'desc'  // 排序方式
}
```

**常用筛选器**:
- 待核销券: `{"status":"unused"}`
- 已核销券: `{"status":"used"}`
- 已过期券: `{"status":"expired"}`

**响应格式**:
```typescript
{
  code: 1,
  msg: "操作成功",
  time: number,
  data: {
    total: number,
    per_page: number,
    current_page: number,
    last_page: number,
    data: [
      {
        id: number,              // 核销券ID
        code: string,            // 核销码
        type: string,            // 券类型('cash'|'discount'|'exchange'|'trial')
        title: string,           // 券标题
        description: string,     // 券描述
        amount: number,          // 金额(代金券,单位:元)
        discount: number,        // 折扣(折扣券,0.1-0.99)
        status: string,          // 状态('unused'|'used'|'expired'|'frozen')
        expire_at: number,       // 过期时间(Unix时间戳,秒)
        store_name: string,      // 门店名称
        store_address: string    // 门店地址
      }
    ]
  }
}
```

**Mock数据**: `src/data/mock/voucher.ts` - `mockVoucherList`

**辅助函数**:
- `getUnusedVouchers(page, limit)` - 获取待核销券
- `getUsedVouchers(page, limit)` - 获取已核销券
- `getExpiredVouchers(page, limit)` - 获取已过期券

---

### 7.2 核销券详情 (voucher/detail)

#### 获取核销券详情

**接口路径**: `GET /api/voucher/detail`

**接口文件**: `src/services/voucher.ts` - `getVoucherDetail()`

**请求参数**:
```typescript
{
  token?: string,  // Token
  id: number       // 核销券ID
}
```

**或**:
```typescript
{
  code: string  // 核销码（商家扫码时使用）
}
```

**响应格式**:
```typescript
{
  code: 1,
  msg: "操作成功",
  time: number,
  data: {
    id: number,
    code: string,
    type: string,
    title: string,
    description: string,
    amount: number,
    discount: number,
    status: string,
    expire_at: number,
    used_at: number,           // 使用时间(已使用时显示)
    order_id: number,          // 关联订单ID(已使用时显示)
    order_no: string,          // 关联订单号(已使用时显示)
    store_id: number,          // 门店ID
    store_name: string,
    store_address: string,
    store_phone: string,
    business_hours: string,
    longitude: number,         // 门店经度
    latitude: number           // 门店纬度
  }
}
```

**Mock数据**: `src/data/mock/voucher.ts` - `mockVoucherDetail`

---

## 8. 订单相关页面

### 8.1 订单列表 (order/list)

#### 获取订单列表

**接口路径**: `GET /api/order/lists`

**接口文件**: `src/services/order.ts` - `getOrderList()`

**请求参数**:
```typescript
{
  token?: string,     // Token
  filter?: string,    // 筛选条件(JSON字符串)，如'{"status":"paid"}'
  page?: number,      // 页码，从1开始
  limit?: number,     // 每页数量
  sort?: string,      // 排序字段
  order?: 'asc'|'desc'  // 排序方式
}
```

**常用筛选器**:
- 已支付订单: `{"status":"paid"}`
- 已核销订单: `{"status":"verified"}`
- 待支付订单: `{"status":"pending"}`

**响应格式**:
```typescript
{
  code: 1,
  msg: "操作成功",
  time: number,
  data: {
    total: number,
    per_page: number,
    current_page: number,
    last_page: number,
    data: [
      {
        id: number,                  // 订单ID
        order_no: string,            // 订单号
        original_amount: number,     // 原价(单位:元)
        discount_amount: number,     // 优惠金额(单位:元)
        final_amount: number,        // 实付金额(单位:元)
        status: string,              // 订单状态('pending'|'paid'|'verified'|'cancelled'|'refunded')
        store_name: string,          // 门店名称
        createtime: number,          // 创建时间(Unix时间戳,秒)
        verified_at: number,         // 核销时间(已核销时显示)
        delivery_mode: string        // 配送方式('self_pickup'|'delivery')
      }
    ]
  }
}
```

**Mock数据**: `src/data/mock/order.ts` - `mockOrderList`

**辅助函数**:
- `getPaidOrders(page, limit)` - 获取已支付订单
- `getVerifiedOrders(page, limit)` - 获取已核销订单

---

### 8.2 订单详情 (order/detail)

#### 获取订单详情

**接口路径**: `GET /api/order/detail`

**接口文件**: `src/services/order.ts` - `getOrderDetail()`

**请求参数**:
```typescript
{
  token?: string,  // Token
  id: number       // 订单ID
}
```

**响应格式**:
```typescript
{
  code: 1,
  msg: "操作成功",
  time: number,
  data: {
    id: number,
    order_no: string,
    original_amount: number,
    discount_amount: number,
    final_amount: number,
    status: string,
    store_name: string,
    store_address: string,       // 门店地址
    store_phone: string,         // 门店电话
    createtime: number,
    verified_at: number,
    voucher_title: string,       // 核销券标题(使用券时显示)
    voucher_type: string,        // 核销券类型(使用券时显示)
    verified_by: string,         // 核销员工(已核销时显示)
    remark: string,              // 备注
    delivery_mode: string        // 配送方式
  }
}
```

**Mock数据**:
- `src/data/mock/order.ts` - `mockOrderDetail`
- `src/data/mock/order.ts` - `mockVerifiedOrderDetail` (已核销)
- `src/data/mock/order.ts` - `mockDeliveryOrderDetail` (配送订单)

---

## 9. 门店相关接口

### 9.1 获取门店列表

**接口路径**: `GET /api/store/lists`

**接口文件**: `src/services/store.ts` - `getStoreList()`

**请求参数**:
```typescript
{
  token?: string,
  longitude?: number,  // 用户经度(用于距离计算)
  latitude?: number,   // 用户纬度(用于距离计算)
  filter?: string,     // 筛选条件，如'{"is_active":1}'
  page?: number,
  limit?: number,
  sort?: string,       // 排序字段，如'distance'
  order?: 'asc'|'desc'
}
```

**响应格式**:
```typescript
{
  code: 1,
  msg: "操作成功",
  time: number,
  data: {
    total: number,
    per_page: number,
    current_page: number,
    last_page: number,
    data: [
      {
        id: number,
        name: string,              // 门店名称
        address: string,           // 门店地址
        latitude: number,          // 纬度
        longitude: number,         // 经度
        phone: string,             // 电话
        business_hours: string,    // 营业时间
        is_active: 0 | 1,          // 是否营业
        distance: number           // 距离(公里,提供经纬度时返回)
      }
    ]
  }
}
```

**Mock数据**: `src/data/mock/store.ts` - `mockStoreList`

**辅助函数**:
- `getNearbyStores(longitude, latitude, page, limit)` - 获取附近门店
- `getActiveStores(page, limit)` - 获取营业中的门店

---

### 9.2 获取门店详情

**接口路径**: `GET /api/store/detail`

**接口文件**: `src/services/store.ts` - `getStoreDetail()`

**请求参数**:
```typescript
{
  token?: string,
  id: number  // 门店ID
}
```

**响应格式**:
```typescript
{
  code: 1,
  msg: "操作成功",
  time: number,
  data: {
    id: number,
    name: string,
    address: string,
    latitude: number,
    longitude: number,
    phone: string,
    business_hours: string,
    is_active: 0 | 1,
    description: string  // 门店描述（详情页可能包含）
  }
}
```

---

## 附录

### 通用响应格式

FastAdmin统一的API响应格式：

```typescript
{
  code: number,   // 状态码: 1=成功, 0=失败, -1=参数错误, -2=未授权, -3=无权限, -4=资源不存在
  msg: string,    // 响应消息
  time: number,   // 响应时间戳(秒)
  data?: any      // 响应数据(可选)
}
```

**类型定义**: `src/types/api.ts` - `ApiResponse<T>`

---

### 错误码说明

| 错误码 | 常量名 | 说明 | 处理方式 |
|--------|--------|------|----------|
| 1 | `SUCCESS` | 成功 | 正常处理 |
| 0 | `FAIL` | 失败 | 显示错误提示 |
| -1 | `PARAM_ERROR` | 参数错误 | 检查请求参数 |
| -2 | `UNAUTHORIZED` | 未授权/Token失效 | 跳转登录页 |
| -3 | `NO_PERMISSION` | 无权限 | 显示权限提示 |
| -4 | `NOT_FOUND` | 资源不存在 | 显示404提示 |

**常量定义**: `src/types/api.ts` - `API_ERROR_CODE`

---

### 分页数据格式

```typescript
{
  total: number,          // 总数
  per_page: number,       // 每页数量
  current_page: number,   // 当前页
  last_page: number,      // 总页数
  data: T[]               // 数据列表
}
```

**类型定义**: `src/types/api.ts` - `PaginationData<T>`

---

### 订单状态枚举

| 状态值 | 常量名 | 说明 |
|--------|--------|------|
| `pending` | `PENDING` | 待支付 |
| `paid` | `PAID` | 已支付 |
| `verified` | `VERIFIED` | 已核销 |
| `cancelled` | `CANCELLED` | 已取消 |
| `refunded` | `REFUNDED` | 已退款 |

**类型定义**: `src/types/order.ts` - `OrderStatus`

---

### 核销券状态枚举

| 状态值 | 常量名 | 说明 |
|--------|--------|------|
| `unused` | `UNUSED` | 未使用 |
| `used` | `USED` | 已使用 |
| `expired` | `EXPIRED` | 已过期 |
| `frozen` | `FROZEN` | 已冻结 |

**类型定义**: `src/types/voucher.ts` - `VoucherStatus`

---

### 核销券类型枚举

| 类型值 | 常量名 | 说明 |
|--------|--------|------|
| `cash` | `CASH` | 代金券 |
| `discount` | `DISCOUNT` | 折扣券 |
| `exchange` | `EXCHANGE` | 兑换券 |
| `trial` | `TRIAL` | 体验券 |

**类型定义**: `src/types/voucher.ts` - `VoucherType`

---

### 配送方式枚举

| 方式值 | 常量名 | 说明 |
|--------|--------|------|
| `self_pickup` | `SELF_PICKUP` | 核销自提 |
| `delivery` | `DELIVERY` | 跑腿配送 |

**类型定义**: `src/types/order.ts` - `DeliveryMode`

---

## 相关文件索引

### 类型定义文件 (src/types/)
- `api.ts` - 通用API类型
- `order.ts` - 订单类型
- `voucher.ts` - 核销券类型
- `user.ts` - 用户类型
- `store.ts` - 门店类型
- `merchant.ts` - 商家类型
- `product.ts` - 商品类型
- `cart.ts` - 购物车类型
- `writeoff.ts` - 核销结果类型
- `stats.ts` - 统计类型
- `recent.ts` - 最近数据类型

### 服务层文件 (src/services/)
- `request.ts` - HTTP请求封装
- `order.ts` - 订单服务
- `voucher.ts` - 核销券服务
- `user.ts` - 用户服务
- `store.ts` - 门店服务
- `merchant.ts` - 商家服务
- `ad.ts` - 广告服务

### Mock数据文件 (src/data/mock/)
- `order.ts` - 订单Mock数据
- `voucher.ts` - 核销券Mock数据
- `user.ts` - 用户Mock数据
- `store.ts` - 门店Mock数据
- `merchant.ts` - 商家Mock数据

---

**文档版本**: v1.0
**最后更新**: 2025-10-15
**维护者**: Claude Code
