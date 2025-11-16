# 小程序前端接入计划文档

## 项目概述

本文档为 GrainAdminMix 核销券商城系统的小程序前端接入指南，包含完整的 API 接口文档、调用流程和集成说明。

## 系统架构

### 后端技术栈
- 框架：ThinkPHP 5.x + FastAdmin
- 支付：微信支付 V3（使用公钥模式）
- 数据库：MySQL（数据库名：grainPro，表前缀：grain_）

### API 基础信息
- 基础地址：`http://127.0.0.1:8000/api/wanlshop`
- 认证方式：Token（Bearer）
- 响应格式：JSON

## 一、用户认证模块

### 1.1 小程序登录授权

#### 第一步：小程序登录（获取 openid）
```
POST /api/wanlshop/user/third
```

**请求参数：**
```json
{
    "platform": "miniprogram",
    "code": "wx.login() 获取的 code",
    "client_id": "即时通讯客户端ID（可选）"
}
```

**响应示例：**
```json
{
    "code": 1,
    "msg": "登录成功",
    "data": {
        "userinfo": {
            "id": 1,
            "username": "user123",
            "nickname": "用户昵称",
            "mobile": "13800138000",
            "avatar": "/uploads/avatar.jpg",
            "token": "eyJ0eXAiOiJKV1..."
        }
    }
}
```

#### 第二步：手机号授权登录（可选）
```
POST /api/wanlshop/user/phone
```

**请求参数：**
```json
{
    "encryptedData": "微信加密数据",
    "iv": "初始向量",
    "session_key": "会话密钥",
    "openid": "用户openid",
    "client_id": "即时通讯客户端ID（可选）"
}
```

### 1.2 手机号验证码登录
```
POST /api/wanlshop/user/mobilelogin
```

**请求参数：**
```json
{
    "mobile": "13800138000",
    "captcha": "123456",
    "client_id": "即时通讯客户端ID（可选）"
}
```

### 1.3 用户信息管理

#### 获取用户信息
```
GET /api/wanlshop/user/index
Headers: Authorization: Bearer {token}
```

#### 更新用户资料
```
POST /api/wanlshop/user/profile
Headers: Authorization: Bearer {token}
```

**请求参数：**
```json
{
    "avatar": "头像URL",
    "username": "用户名",
    "nickname": "昵称",
    "bio": "个人简介"
}
```

## 二、商品浏览模块

### 2.1 商品分类

#### 获取分类列表
```
GET /api/wanlshop/category/lists
```

**响应示例：**
```json
{
    "code": 1,
    "data": [
        {
            "id": 1,
            "name": "美食餐饮",
            "image": "/uploads/category/food.jpg",
            "level": 1,
            "children": [
                {
                    "id": 2,
                    "name": "中餐",
                    "level": 2
                }
            ]
        }
    ]
}
```

#### 获取分类树
```
GET /api/wanlshop/category/tree
```

### 2.2 商品列表与详情

#### 获取商品列表
```
GET /api/wanlshop/product/lists?type=goods
```

**请求参数：**
- `type`: 商品类型（goods）
- `category_id`: 分类ID（可选）
- `keywords`: 搜索关键词（可选）
- `sort`: 排序方式（price_asc, price_desc, sales）
- `page`: 页码
- `limit`: 每页数量

#### 获取商品详情
```
GET /api/wanlshop/product/goods?id={goods_id}
```

**响应示例：**
```json
{
    "code": 1,
    "data": {
        "id": 1,
        "title": "商品名称",
        "description": "商品描述",
        "price": "99.00",
        "supply_price": "80.00",
        "images": ["/uploads/goods/1.jpg"],
        "category_id": 1,
        "category_name": "美食餐饮",
        "stock": 100,
        "sales": 50,
        "status": "normal"
    }
}
```

## 三、订单管理模块

### 3.1 订单创建与管理

#### 创建订单
```
POST /api/wanlshop/voucher/order/create
Headers: Authorization: Bearer {token}
```

**请求参数：**
```json
{
    "goods_id": 1,
    "quantity": 2
}
```

**响应示例：**
```json
{
    "code": 1,
    "msg": "订单创建成功",
    "data": {
        "order_id": 123,
        "order_no": "ORD20251116123456",
        "amount": "198.00"
    }
}
```

#### 获取订单列表
```
GET /api/wanlshop/voucher/order/lists?state={state}
Headers: Authorization: Bearer {token}
```

**请求参数：**
- `state`: 订单状态（1=待支付，2=已支付，3=已取消）
- `page`: 页码
- `limit`: 每页数量

#### 获取订单详情
```
GET /api/wanlshop/voucher/order/detail?id={order_id}
Headers: Authorization: Bearer {token}
```

#### 取消订单
```
POST /api/wanlshop/voucher/order/cancel
Headers: Authorization: Bearer {token}
```

**请求参数：**
```json
{
    "id": 123
}
```

## 四、支付模块

### 4.1 微信支付流程

#### 步骤1：创建订单
调用 `/api/wanlshop/voucher/order/create` 创建订单

#### 步骤2：预下单
```
POST /api/wanlshop/voucher/order/prepay
Headers: Authorization: Bearer {token}
```

**请求参数：**
```json
{
    "order_id": 123
}
```

**响应示例：**
```json
{
    "code": 1,
    "msg": "ok",
    "data": {
        "order_no": "ORD20251116123456",
        "prepay_id": "wx201410272009395522657...",
        "payparams": {
            "appId": "wx2ea4629a10f048c3",
            "timeStamp": "1731748800",
            "nonceStr": "5K8264ILTKCH16CQ...",
            "package": "prepay_id=wx201410272009395522657...",
            "signType": "RSA",
            "paySign": "oR9d8PuhnIc+YZ8cBHFCwfgpaK9gd7va..."
        }
    }
}
```

#### 步骤3：小程序调起支付
```javascript
wx.requestPayment({
    timeStamp: payparams.timeStamp,
    nonceStr: payparams.nonceStr,
    package: payparams.package,
    signType: payparams.signType,
    paySign: payparams.paySign,
    success: function(res) {
        // 支付成功，查询订单状态
    },
    fail: function(res) {
        // 支付失败或取消
    }
})
```

#### 步骤4：查询支付结果
支付完成后，后端会自动处理回调。前端可以：
1. 轮询订单状态接口
2. 跳转到订单详情页查看状态

## 五、核销券模块

### 5.1 我的核销券

#### 获取券列表
```
GET /api/wanlshop/voucher/voucher/lists?state={state}
Headers: Authorization: Bearer {token}
```

**请求参数：**
- `state`: 券状态（1=未使用，2=已核销，3=已过期，4=已退款）
- `page`: 页码
- `limit`: 每页数量

**响应示例：**
```json
{
    "code": 1,
    "data": {
        "data": [
            {
                "id": 1,
                "voucher_no": "V202511161234567890",
                "verify_code": "123456",
                "goods_title": "商品名称",
                "goods_image": "/uploads/goods/1.jpg",
                "face_value": "99.00",
                "state": 1,
                "state_text": "未使用",
                "valid_start": 1731748800,
                "valid_end": 1734340800,
                "createtime": 1731748800
            }
        ],
        "current_page": 1,
        "total": 10
    }
}
```

#### 获取券详情
```
GET /api/wanlshop/voucher/voucher/detail?id={voucher_id}
Headers: Authorization: Bearer {token}
```

### 5.2 核销功能（商家端）

#### 验证码核销
```
POST /api/wanlshop/voucher/verify/code
Headers: Authorization: Bearer {token}
```

**请求参数：**
```json
{
    "voucher_no": "V202511161234567890",
    "verify_code": "123456",
    "shop_id": 1
}
```

**注意：**`voucher_no` 和 `verify_code` 至少提供一个

#### 扫码核销
```
POST /api/wanlshop/voucher/verify/scan
Headers: Authorization: Bearer {token}
```

**请求参数：**
```json
{
    "qr_content": "扫码获取的内容",
    "shop_id": 1
}
```

## 六、退款模块

### 6.1 申请退款

```
POST /api/wanlshop/voucher/refund/apply
Headers: Authorization: Bearer {token}
```

**请求参数：**
```json
{
    "voucher_id": 1,
    "reason": "退款原因"
}
```

### 6.2 退款列表

```
GET /api/wanlshop/voucher/refund/lists
Headers: Authorization: Bearer {token}
```

## 七、小程序端开发要点

### 7.1 环境配置

#### 开发环境
```javascript
// config/dev.js
export default {
    baseUrl: 'http://127.0.0.1:8000/api/wanlshop',
    uploadUrl: 'http://127.0.0.1:8000/api/common/upload'
}
```

#### 生产环境
```javascript
// config/prod.js
export default {
    baseUrl: 'https://yourdomain.com/api/wanlshop',
    uploadUrl: 'https://yourdomain.com/api/common/upload'
}
```

### 7.2 请求封装

```javascript
// utils/request.js
const request = (options) => {
    const token = wx.getStorageSync('token');
    
    return new Promise((resolve, reject) => {
        wx.request({
            ...options,
            url: config.baseUrl + options.url,
            header: {
                'Content-Type': 'application/json',
                'Authorization': token ? `Bearer ${token}` : '',
                ...options.header
            },
            success: (res) => {
                if (res.data.code === 1) {
                    resolve(res.data);
                } else {
                    // 处理错误
                    if (res.data.code === 401) {
                        // token 失效，跳转登录
                        wx.navigateTo({ url: '/pages/login/index' });
                    }
                    reject(res.data);
                }
            },
            fail: reject
        });
    });
};
```

### 7.3 登录流程

```javascript
// pages/login/index.js
async function login() {
    // 1. 获取 code
    const { code } = await wx.login();
    
    // 2. 发送到后端
    const res = await request({
        url: '/user/third',
        method: 'POST',
        data: {
            platform: 'miniprogram',
            code: code
        }
    });
    
    // 3. 保存 token
    wx.setStorageSync('token', res.data.userinfo.token);
    wx.setStorageSync('userinfo', res.data.userinfo);
    
    // 4. 跳转首页
    wx.switchTab({ url: '/pages/index/index' });
}
```

### 7.4 支付流程

```javascript
// pages/order/pay.js
async function payOrder(orderId) {
    try {
        // 1. 预下单
        const res = await request({
            url: '/voucher/order/prepay',
            method: 'POST',
            data: { order_id: orderId }
        });
        
        // 2. 调起支付
        const payResult = await wx.requestPayment(res.data.payparams);
        
        // 3. 支付成功
        wx.showToast({ title: '支付成功' });
        wx.redirectTo({ url: `/pages/order/detail?id=${orderId}` });
        
    } catch (error) {
        wx.showToast({ title: '支付失败', icon: 'none' });
    }
}
```

### 7.5 核销券展示

```javascript
// pages/voucher/detail.js
Page({
    data: {
        voucher: {},
        qrcode: ''
    },
    
    async onLoad(options) {
        const res = await request({
            url: `/voucher/voucher/detail?id=${options.id}`
        });
        
        // 生成二维码（包含券号和验证码）
        const qrData = JSON.stringify({
            voucher_no: res.data.voucher_no,
            verify_code: res.data.verify_code
        });
        
        this.setData({
            voucher: res.data,
            qrcode: this.generateQRCode(qrData)
        });
    }
});
```

## 八、页面规划

### 8.1 必需页面

1. **登录授权页** `/pages/login/index`
2. **首页** `/pages/index/index`
3. **分类页** `/pages/category/index`
4. **商品列表页** `/pages/goods/list`
5. **商品详情页** `/pages/goods/detail`
6. **订单确认页** `/pages/order/confirm`
7. **订单列表页** `/pages/order/list`
8. **订单详情页** `/pages/order/detail`
9. **我的券列表** `/pages/voucher/list`
10. **券详情页** `/pages/voucher/detail`
11. **个人中心** `/pages/user/index`

### 8.2 商家端页面（可选）

1. **商家登录** `/pages/shop/login`
2. **核销扫码** `/pages/shop/scan`
3. **核销记录** `/pages/shop/verify-list`
4. **结算记录** `/pages/shop/settlement`

## 九、注意事项

### 9.1 安全要求

1. **Token 管理**
   - Token 存储在 Storage 中
   - 每次请求携带 Token
   - Token 失效时自动跳转登录

2. **支付安全**
   - 所有支付参数由后端生成
   - 前端不存储任何支付密钥
   - 支付结果以后端通知为准

3. **数据加密**
   - 敏感数据传输使用 HTTPS
   - 手机号等信息使用微信加密传输

### 9.2 性能优化

1. **图片优化**
   - 使用 CDN 加速
   - 图片懒加载
   - 适配不同屏幕尺寸

2. **请求优化**
   - 接口缓存
   - 分页加载
   - 防抖节流

3. **用户体验**
   - 加载状态提示
   - 错误友好提示
   - 操作反馈及时

### 9.3 测试要点

1. **功能测试**
   - 登录流程完整性
   - 支付流程完整性
   - 核销流程完整性

2. **兼容性测试**
   - 不同机型适配
   - 微信版本兼容
   - 网络环境测试

3. **异常测试**
   - 网络异常处理
   - 支付异常处理
   - 数据异常处理

## 十、开发进度规划

### 第一阶段：基础功能（1周）
- [ ] 项目初始化和配置
- [ ] 用户登录授权
- [ ] 首页和商品浏览
- [ ] 商品详情展示

### 第二阶段：交易功能（1周）
- [ ] 订单创建流程
- [ ] 微信支付集成
- [ ] 订单管理页面
- [ ] 支付结果处理

### 第三阶段：核销功能（3天）
- [ ] 核销券列表和详情
- [ ] 二维码生成展示
- [ ] 核销状态更新
- [ ] 退款功能（如需要）

### 第四阶段：优化完善（3天）
- [ ] 界面美化
- [ ] 性能优化
- [ ] 测试修复
- [ ] 上线准备

## 十一、联调测试

### 11.1 接口联调清单

- [ ] 用户登录授权接口
- [ ] 商品列表和详情接口
- [ ] 订单创建接口
- [ ] 支付预下单接口
- [ ] 支付回调处理
- [ ] 券列表和详情接口
- [ ] 核销功能接口

### 11.2 测试账号准备

1. **测试用户账号**
   - 手机号：由后端提供
   - 验证码：测试环境固定值

2. **测试商家账号**
   - 用于核销功能测试
   - 店铺ID：由后端提供

3. **测试支付**
   - 使用微信支付沙箱环境
   - 或设置测试金额（如0.01元）

## 十二、部署上线

### 12.1 小程序配置

1. **服务器域名配置**
   - request 合法域名
   - uploadFile 合法域名
   - downloadFile 合法域名

2. **业务域名配置**
   - 配置 H5 页面域名（如有）

3. **支付配置**
   - 绑定商户号
   - 配置支付目录

### 12.2 版本管理

1. **开发版本**：日常开发测试
2. **体验版本**：内部测试
3. **正式版本**：对外发布

### 12.3 监控与维护

1. **错误监控**：集成错误上报
2. **性能监控**：页面加载时间、接口响应时间
3. **用户反馈**：反馈入口和处理流程

---

## 附录：错误码说明

| 错误码 | 说明 | 处理方式 |
|--------|------|----------|
| 0 | 失败 | 显示错误信息 |
| 1 | 成功 | 正常处理 |
| 401 | 未授权 | 跳转登录页 |
| 403 | 禁止访问 | 提示无权限 |
| 404 | 资源不存在 | 提示不存在 |
| 500 | 服务器错误 | 提示系统繁忙 |

## 技术支持

- 后端接口问题：联系后端开发人员
- 支付问题：查看微信支付文档
- 小程序问题：查看微信小程序文档

---

*文档版本：v1.0*  
*更新日期：2025-11-16*  
*适用项目：GrainAdminMix 核销券商城系统*
