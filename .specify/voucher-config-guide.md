# 核销券系统配置说明

## 一、.env 配置

### 1. 微信支付配置 `[wechat_payment]`

**重要提示**：以下配置需要在微信商户平台获取。

```ini
[wechat_payment]
# 小程序 appid（与 wechat_miniprogram 的 app_id 相同）
appid = wx2ea4629a10f048c3

# 商户号（在微信商户平台获取）
mch_id = 1234567890

# API密钥 V2（32位字符串，在商户平台设置）
api_key = your_32_char_api_key_here

# API密钥 V3（推荐使用，32位字符串）
apiv3_key = your_32_char_apiv3_key_here

# API证书路径（绝对路径）
cert_path = /path/to/cert/apiclient_cert.pem
key_path = /path/to/cert/apiclient_key.pem

# 证书序列号（在商户平台查看）
serial_no = 5E3F7D8A9B1C2D4E5F6A7B8C9D0E1F2A3B4C5D6E
```

**如何获取这些配置：**

1. **商户号 (mch_id)**
   - 登录 [微信商户平台](https://pay.weixin.qq.com/)
   - 在"账户中心" → "商户信息"中查看

2. **API密钥 (api_key / apiv3_key)**
   - 登录微信商户平台
   - "账户中心" → "API安全" → 设置APIv3密钥
   - APIv3密钥为32位字符串

3. **API证书 (cert_path / key_path)**
   - 登录微信商户平台
   - "账户中心" → "API安全" → 下载证书
   - 将证书文件放到服务器安全目录（如 `/www/cert/`）
   - 配置文件中填写绝对路径

4. **证书序列号 (serial_no)**
   - 在商户平台"API安全"中查看
   - 或使用命令获取：
     ```bash
     openssl x509 -in apiclient_cert.pem -noout -serial
     ```

### 2. 核销券系统配置 `[voucher]`

```ini
[voucher]
# 核销券有效期（天数，默认30天）
valid_days = 30

# 是否允许退款（true/false）
allow_refund = true

# 退款期限（有效期前N天内可退款，默认7天）
refund_days = 7
```

**配置说明：**
- `valid_days`：券生成后的有效天数，过期后无法核销
- `allow_refund`：是否允许用户申请退款
- `refund_days`：券在有效期前多少天内可以申请退款

---

## 二、application/extra/wechat.php

此文件已自动读取 `.env` 中的配置，一般不需要修改。

**唯一需要修改的是回调地址：**

```php
'notify_url' => 'https://yourdomain.com/api/wanlshop/voucher/order/notify',
```

将 `yourdomain.com` 替换为你的实际域名（**必须是 HTTPS**）。

---

## 三、application/extra/voucher.php

此文件已自动读取 `.env` 中的配置，无需修改。

---

## 四、配置检查清单

在启用核销券功能前，请确认：

- [ ] 已在微信商户平台开通"JSAPI支付"
- [ ] 已配置 `.env` 中的所有微信支付参数
- [ ] API证书文件已下载并放到服务器
- [ ] 证书文件路径填写正确（绝对路径）
- [ ] 支付回调地址使用 HTTPS 协议
- [ ] 回调地址可从外网访问（微信服务器需回调）
- [ ] 小程序已配置支付域名白名单

---

## 五、测试配置

### 方法1：使用微信沙箱环境（推荐）

1. 登录微信商户平台
2. 进入"开发配置" → "沙箱环境"
3. 获取沙箱商户号和密钥
4. 临时替换 `.env` 中的配置进行测试

### 方法2：使用真实环境（小金额测试）

1. 确保配置正确
2. 创建一个低价商品（如 0.01元）
3. 完整测试支付流程
4. 验证回调是否正常生成券

---

## 六、常见问题

### 1. 支付失败：签名错误
- 检查 `api_key` 或 `apiv3_key` 是否正确
- 确认使用的是V2还是V3接口（推荐V3）

### 2. 回调未生成券
- 检查回调地址是否可访问（HTTPS）
- 查看日志：`runtime/log/` 目录下的错误日志
- 确认微信服务器能访问你的回调URL

### 3. 证书错误
- 检查证书文件路径是否正确
- 确认证书文件权限（可读）
- 确认证书未过期

### 4. 回调地址配置
- 必须使用 HTTPS 协议
- 必须是公网可访问的域名
- 本地开发可使用 ngrok 等内网穿透工具

---

## 七、安全建议

1. **敏感信息保护**
   - `.env` 文件不要提交到 Git
   - 证书文件存放在 Web 目录外
   - API密钥定期更换

2. **回调验证**
   - 生产环境必须验证微信签名
   - 当前 MVP 版本已预留验证代码位置
   - 在 `Order.php` 的 `notify()` 方法中补全

3. **日志监控**
   - 定期查看支付回调日志
   - 监控异常回调（如重复回调）
   - 设置报警机制

---

**文档版本**：v1.0
**更新日期**：2025-11-14
**相关文件**：
- `.env`
- `application/extra/wechat.php`
- `application/extra/voucher.php`
- `application/api/controller/wanlshop/voucher/Order.php`
