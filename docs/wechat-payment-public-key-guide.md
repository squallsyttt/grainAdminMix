# 微信支付公钥模式配置指南

## 概述

本项目已更新为使用**微信支付公钥**模式进行回调验签，不再使用平台证书模式。这种方式更简单，无需定期下载平台证书。

## 配置步骤

### 1. 获取微信支付公钥

登录[微信支付商户平台](https://pay.weixin.qq.com)：

1. 进入「账户中心」→「API安全」
2. 找到「微信支付公钥」区域
3. 点击「设置」或「查看」
4. 下载公钥文件（通常命名为 `platform_public_key.pem`）
5. 记录**公钥ID**（显示在页面上，格式如：`PUB_KEY_ID_0114...`）

### 2. 放置公钥文件

将下载的公钥文件放置到项目目录：

```bash
# 创建证书目录（如果不存在）
mkdir -p cert/wechat/

# 将公钥文件放入
cp ~/Downloads/platform_public_key.pem cert/wechat/
```

### 3. 配置 .env 文件

编辑项目根目录的 `.env` 文件，在 `[wechat_payment]` 段添加：

```ini
[wechat_payment]
; ... 其他配置 ...

; 微信支付公钥文件路径（相对于项目根目录）
platform_public_key_path = cert/wechat/platform_public_key.pem

; 微信支付公钥ID（在商户平台查看）
platform_public_key_id = 你的公钥ID
```

### 4. 确保其他配置完整

确保以下配置也已正确设置：

```ini
[wechat_payment]
; 小程序 APPID
appid = wx2ea4629a10f048c3

; 商户号
mch_id = 你的商户号

; API密钥 V2（32位，可选）
api_key = 你的APIv2密钥

; APIv3密钥（32位，必须）
apiv3_key = 你的APIv3密钥

; 商户证书路径
cert_path = cert/wechat/apiclient_cert.pem

; 商户私钥路径
key_path = cert/wechat/apiclient_key.pem

; 商户证书序列号
serial_no = 你的证书序列号

; 回调通知URL
notify_url = https://你的域名/api/wanlshop/voucher/order/notify
```

## 技术说明

### 使用的 SDK

项目使用官方的 `wechatpay/wechatpay` SDK（v1.4.12），该 SDK 支持：

- ✅ JSAPI 下单
- ✅ 支付签名生成
- ✅ 回调验签（支持公钥模式）
- ✅ 回调报文解密

### 主要类说明

#### WechatPayment 类

位置：`application/common/library/WechatPayment.php`

主要方法：

1. **jsapiPrepay()** - JSAPI 下单
   ```php
   $result = WechatPayment::jsapiPrepay([
       'description'     => '商品描述',
       'out_trade_no'    => '订单号',
       'amount_total'    => 100,  // 单位：分
       'payer_openid'    => 'openid',
       'payer_client_ip' => 'IP地址（可选）'
   ]);
   ```

2. **buildJsapiPayParams()** - 生成小程序支付参数
   ```php
   $payParams = WechatPayment::buildJsapiPayParams($prepay_id);
   // 返回：appId, timeStamp, nonceStr, package, signType, paySign
   ```

3. **verifyCallbackSignature()** - 验证回调签名
   ```php
   $headers = [
       'timestamp' => $request->header('Wechatpay-Timestamp'),
       'nonce'     => $request->header('Wechatpay-Nonce'),
       'signature' => $request->header('Wechatpay-Signature'),
       'serial'    => $request->header('Wechatpay-Serial'),
   ];
   $verified = WechatPayment::verifyCallbackSignature($headers, $body);
   ```

4. **decryptCallbackResource()** - 解密回调数据
   ```php
   $resource = WechatPayment::decryptCallbackResource($data['resource']);
   ```

### 与旧版本的区别

| 功能 | 旧版（WechatPayV3） | 新版（WechatPayment） |
|------|-------------------|---------------------|
| 验签方式 | 平台证书 | 微信支付公钥 |
| SDK | 自定义实现 | 官方 wechatpay/wechatpay |
| 证书管理 | 需定期更新平台证书 | 公钥长期有效 |
| 配置复杂度 | 较复杂 | 简单 |

## 测试验证

### 1. 测试下单功能

调用预下单接口：

```bash
POST /api/wanlshop/voucher/order/prepay
{
    "order_id": 1
}
```

### 2. 测试回调验签

微信支付成功后会自动回调 notify_url，观察日志：

```bash
tail -f runtime/log/$(date +%Y%m)/$(date +%d).log
```

### 3. 常见问题排查

#### 签名验证失败

检查：
- 公钥文件路径是否正确
- 公钥ID是否匹配
- 公钥文件内容是否完整（应以 `-----BEGIN PUBLIC KEY-----` 开头）

#### 解密失败

检查：
- APIv3密钥是否正确（32位字符串）
- 是否在商户平台正确设置了APIv3密钥

#### 下单失败

检查：
- 商户号、APPID 是否正确
- 商户私钥文件是否正确
- 证书序列号是否匹配

## 安全提醒

⚠️ **重要**：

1. 不要将证书文件提交到 Git 仓库
2. 确保 `.gitignore` 包含 `cert/` 目录
3. 生产环境的密钥应通过环境变量或安全的配置管理系统管理
4. 定期检查商户平台的安全设置

## 参考文档

- [微信支付官方文档](https://pay.weixin.qq.com/docs/merchant/)
- [微信支付公钥使用指引](https://pay.weixin.qq.com/docs/merchant/products/platform-certificate/wxp-pub-key-guide.html)
- [wechatpay/wechatpay SDK](https://github.com/wechatpay-apiv3/wechatpay-php)
