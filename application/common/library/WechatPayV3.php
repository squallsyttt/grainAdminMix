<?php

namespace app\common\library;

use think\Exception;
use think\Log;

/**
 * 微信支付 V3 JSAPI 辅助类
 *
 * 仅实现本项目所需的：
 * - JSAPI 下单：/v3/pay/transactions/jsapi
 * - 回调签名验证与报文解密
 *
 * 不依赖第三方 SDK，严格按官方签名规范实现。
 */
class WechatPayV3
{
    const BASE_URL = 'https://api.mch.weixin.qq.com';

    /**
     * JSAPI 下单
     *
     * @param array $orderParams [
     *   'description'     => string  订单描述
     *   'out_trade_no'    => string  商户订单号
     *   'amount_total'    => int     支付总金额（单位：分）
     *   'payer_openid'    => string  支付人 openid
     *   'payer_client_ip' => string  可选，用户端 IP
     * ]
     * @return array ['prepay_id' => string]
     * @throws Exception
     */
    public static function jsapiPrepay(array $orderParams)
    {
        $config = config('wechat.payment');
        if (empty($config) || !is_array($config)) {
            throw new Exception('微信支付配置未设置（wechat.payment）');
        }

        $appid      = isset($config['appid']) ? trim($config['appid']) : '';
        $mchId      = isset($config['mch_id']) ? trim($config['mch_id']) : '';
        $notifyUrl  = isset($config['notify_url']) ? trim($config['notify_url']) : '';
        $serialNo   = isset($config['serial_no']) ? trim($config['serial_no']) : '';
        $keyPath    = isset($config['key_path']) ? trim($config['key_path']) : '';

        if ($appid === '' || $mchId === '' || $notifyUrl === '' || $serialNo === '' || $keyPath === '') {
            throw new Exception('微信支付配置不完整：appid/mch_id/notify_url/serial_no/key_path 不能为空');
        }

        if (empty($orderParams['description']) || empty($orderParams['out_trade_no']) ||
            empty($orderParams['amount_total']) || empty($orderParams['payer_openid'])) {
            throw new Exception('下单参数不完整');
        }

        $body = [
            'appid'        => $appid,
            'mchid'        => $mchId,
            'description'  => (string)$orderParams['description'],
            'out_trade_no' => (string)$orderParams['out_trade_no'],
            'notify_url'   => $notifyUrl,
            'amount'       => [
                'total'    => (int)$orderParams['amount_total'],
                'currency' => 'CNY',
            ],
            'payer'        => [
                'openid' => (string)$orderParams['payer_openid'],
            ],
        ];

        // 可选：补充场景信息（如客户端 IP）
        if (!empty($orderParams['payer_client_ip'])) {
            $body['scene_info'] = [
                'payer_client_ip' => (string)$orderParams['payer_client_ip'],
            ];
        }

        $bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($bodyJson === false) {
            throw new Exception('下单参数 JSON 编码失败');
        }

        $path   = '/v3/pay/transactions/jsapi';
        $method = 'POST';

        $response = self::request($method, $path, $bodyJson, $config);

        if (!isset($response['prepay_id']) || !$response['prepay_id']) {
            Log::error('微信 JSAPI 下单失败：' . json_encode($response, JSON_UNESCAPED_UNICODE));
            throw new Exception('微信下单失败');
        }

        return $response;
    }

    /**
     * 为小程序 wx.requestPayment 生成签名参数
     *
     * @param string $prepayId  微信返回的 prepay_id
     * @return array [
     *   'appId','timeStamp','nonceStr','package','signType','paySign'
     * ]
     * @throws Exception
     */
    public static function buildJsapiPayParams($prepayId)
    {
        $config = config('wechat.payment');
        if (empty($config) || !is_array($config)) {
            throw new Exception('微信支付配置未设置（wechat.payment）');
        }

        $appid   = isset($config['appid']) ? trim($config['appid']) : '';
        $keyPath = isset($config['key_path']) ? trim($config['key_path']) : '';

        if ($appid === '' || $keyPath === '') {
            throw new Exception('微信支付配置不完整：appid 或 key_path 为空');
        }

        $timestamp = (string)time();
        $nonceStr  = bin2hex(random_bytes(16));
        $package   = 'prepay_id=' . $prepayId;

        // JSAPI 支付参数签名串：appId\ntimeStamp\nnonceStr\npackage\n
        $message = $appid . "\n" .
            $timestamp . "\n" .
            $nonceStr . "\n" .
            $package . "\n";

        $paySign = self::sign($message, $keyPath);

        return [
            'appId'     => $appid,
            'timeStamp' => $timestamp,
            'nonceStr'  => $nonceStr,
            'package'   => $package,
            'signType'  => 'RSA',
            'paySign'   => $paySign,
        ];
    }

    /**
     * 验证回调签名
     *
     * @param array  $headers [
     *   'timestamp' => '',
     *   'nonce'     => '',
     *   'signature' => '',
     *   'serial'    => '',
     * ]
     * @param string $body    原始回调报文
     * @return bool
     */
    public static function verifyCallbackSignature(array $headers, $body)
    {
        $config = config('wechat.payment');
        $platformCertPath = isset($config['platform_cert_path']) ? trim($config['platform_cert_path']) : '';

        if ($platformCertPath === '' || !is_file($platformCertPath)) {
            Log::error('微信支付回调：平台证书未配置或文件不存在');
            return false;
        }

        $timestamp = isset($headers['timestamp']) ? (string)$headers['timestamp'] : '';
        $nonce     = isset($headers['nonce']) ? (string)$headers['nonce'] : '';
        $signature = isset($headers['signature']) ? (string)$headers['signature'] : '';

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            Log::error('微信支付回调：签名头不完整');
            return false;
        }

        $message = $timestamp . "\n" .
            $nonce . "\n" .
            $body . "\n";

        $publicKey = openssl_pkey_get_public(file_get_contents($platformCertPath));
        if ($publicKey === false) {
            Log::error('微信支付回调：加载平台证书失败');
            return false;
        }

        $verify = openssl_verify($message, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256);
        openssl_free_key($publicKey);

        if ($verify !== 1) {
            Log::error('微信支付回调：签名验证失败');
            return false;
        }

        return true;
    }

    /**
     * 解密回调资源（resource）
     *
     * @param array  $resource resource 数组
     * @return array 解密后的明文字段
     * @throws Exception
     */
    public static function decryptCallbackResource(array $resource)
    {
        $config  = config('wechat.payment');
        $apiV3Key = isset($config['apiv3_key']) ? trim($config['apiv3_key']) : '';

        if ($apiV3Key === '') {
            throw new Exception('微信支付配置未设置 APIv3 密钥');
        }

        if (empty($resource['ciphertext']) || empty($resource['nonce'])) {
            throw new Exception('回调资源数据不完整');
        }

        $ciphertext    = base64_decode($resource['ciphertext']);
        $nonce         = $resource['nonce'];
        $associatedData = isset($resource['associated_data']) ? $resource['associated_data'] : '';

        // 根据官方文档：ciphertext = AES_GCM(plaintext) + tag（16字节），整体再 base64
        $len = strlen($ciphertext);
        if ($len <= 16) {
            throw new Exception('回调密文长度异常');
        }

        $ct  = substr($ciphertext, 0, $len - 16);
        $tag = substr($ciphertext, -16);

        $plaintext = openssl_decrypt(
            $ct,
            'aes-256-gcm',
            $apiV3Key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associatedData
        );

        if ($plaintext === false) {
            throw new Exception('回调报文解密失败');
        }

        $data = json_decode($plaintext, true);
        if (!is_array($data)) {
            throw new Exception('回调报文解密后 JSON 解析失败');
        }

        return $data;
    }

    /**
     * 统一 HTTP 请求封装，自动签名
     *
     * @param string $method  请求方法
     * @param string $path    请求路径（含查询字符串）
     * @param string $body    请求体（JSON）
     * @param array  $config  wechat.payment 配置
     * @return array
     * @throws Exception
     */
    protected static function request($method, $path, $body, array $config)
    {
        $method = strtoupper($method);

        $mchId    = isset($config['mch_id']) ? trim($config['mch_id']) : '';
        $serialNo = isset($config['serial_no']) ? trim($config['serial_no']) : '';
        $keyPath  = isset($config['key_path']) ? trim($config['key_path']) : '';

        if ($mchId === '' || $serialNo === '' || $keyPath === '') {
            throw new Exception('微信支付配置不完整：mch_id/serial_no/key_path 不能为空');
        }

        $timestamp = (string)time();
        $nonce     = bin2hex(random_bytes(16));

        // HTTP 请求签名串：method\npath\ntimestamp\nnonce\nbody\n
        $message = $method . "\n" .
            $path . "\n" .
            $timestamp . "\n" .
            $nonce . "\n" .
            $body . "\n";

        $signature = self::sign($message, $keyPath);

        $authorization = sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%s",serial_no="%s"',
            $mchId,
            $nonce,
            $signature,
            $timestamp,
            $serialNo
        );

        $url = rtrim(self::BASE_URL, '/') . $path;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
            'Authorization: ' . $authorization,
            'User-Agent: GrainVoucher/1.0',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Log::error('微信支付 HTTP 请求失败：' . $error);
            throw new Exception('微信支付请求失败：' . $error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            Log::error('微信支付 HTTP 状态码异常：' . $httpCode . '，响应：' . $response);
            throw new Exception('微信支付请求失败，HTTP 状态码：' . $httpCode);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            Log::error('微信支付返回数据解析失败：' . $response);
            throw new Exception('微信支付返回数据格式错误');
        }

        return $data;
    }

    /**
     * 使用商户私钥生成签名
     *
     * @param string $message        待签名字符串
     * @param string $privateKeyPath 私钥路径
     * @return string base64 编码后的签名
     * @throws Exception
     */
    protected static function sign($message, $privateKeyPath)
    {
        if (!is_file($privateKeyPath)) {
            throw new Exception('微信支付私钥文件不存在：' . $privateKeyPath);
        }

        $privateKey = openssl_pkey_get_private(file_get_contents($privateKeyPath));
        if ($privateKey === false) {
            throw new Exception('微信支付私钥加载失败');
        }

        $signature = '';
        $ok = openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        openssl_free_key($privateKey);

        if (!$ok) {
            throw new Exception('微信支付签名失败');
        }

        return base64_encode($signature);
    }
}

