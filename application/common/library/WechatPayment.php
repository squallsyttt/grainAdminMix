<?php

namespace app\common\library;

use think\Exception;
use think\Log;
use WeChatPay\Builder;
use WeChatPay\Crypto\Rsa;
use WeChatPay\Crypto\AesGcm;
use WeChatPay\Formatter;

/**
 * 微信支付 V3 辅助类（使用 wechatpay/wechatpay SDK）
 * 
 * 采用微信支付公钥模式，不使用平台证书
 * 仅实现本项目所需的：
 * - JSAPI 下单
 * - 回调签名验证与报文解密
 */
class WechatPayment
{
    /**
     * @var \WeChatPay\BuilderChainable SDK 实例
     */
    private static $instance;
    
    /**
     * 获取 SDK 实例
     * 
     * @return \WeChatPay\BuilderChainable
     * @throws Exception
     */
    private static function getInstance()
    {
        if (self::$instance === null) {
            $config = config('wechat.payment');
            
            if (empty($config) || !is_array($config)) {
                throw new Exception('微信支付配置未设置（wechat.payment）');
            }
            
            $mchId = isset($config['mch_id']) ? trim($config['mch_id']) : '';
            $serialNo = isset($config['serial_no']) ? trim($config['serial_no']) : '';
            $keyPath = isset($config['key_path']) ? trim($config['key_path']) : '';
            $publicKeyPath = isset($config['platform_public_key_path']) ? trim($config['platform_public_key_path']) : '';
            $publicKeyId = isset($config['platform_public_key_id']) ? trim($config['platform_public_key_id']) : '';
            
            if ($mchId === '' || $serialNo === '' || $keyPath === '') {
                throw new Exception('微信支付配置不完整：mch_id/serial_no/key_path 不能为空');
            }
            
            // 加载商户私钥
            $merchantPrivateKeyInstance = Rsa::from('file://' . $keyPath, Rsa::KEY_TYPE_PRIVATE);
            
            // 构建配置数组
            $buildConfig = [
                'mchid' => $mchId,
                'serial' => $serialNo,
                'privateKey' => $merchantPrivateKeyInstance,
                'certs' => [],
            ];
            
            // 如果配置了微信支付公钥，则添加到配置中
            if ($publicKeyPath !== '' && $publicKeyId !== '' && is_file($publicKeyPath)) {
                $platformPublicKeyInstance = Rsa::from('file://' . $publicKeyPath, Rsa::KEY_TYPE_PUBLIC);
                $buildConfig['certs'][$publicKeyId] = $platformPublicKeyInstance;
            }
            
            // 构造 APIv3 客户端实例
            self::$instance = Builder::factory($buildConfig);
        }
        
        return self::$instance;
    }
    
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
        
        $appid = isset($config['appid']) ? trim($config['appid']) : '';
        $mchId = isset($config['mch_id']) ? trim($config['mch_id']) : '';
        $notifyUrl = isset($config['notify_url']) ? trim($config['notify_url']) : '';
        
        if ($appid === '' || $mchId === '' || $notifyUrl === '') {
            throw new Exception('微信支付配置不完整：appid/mch_id/notify_url 不能为空');
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
        
        try {
            $instance = self::getInstance();
            $response = $instance->chain('v3/pay/transactions/jsapi')->post(['json' => $body]);
            
            $result = json_decode($response->getBody(), true);
            
            if (!isset($result['prepay_id']) || !$result['prepay_id']) {
                Log::error('微信 JSAPI 下单失败：' . json_encode($result, JSON_UNESCAPED_UNICODE));
                throw new Exception('微信下单失败');
            }
            
            return $result;
            
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            Log::error('微信支付请求异常：' . $e->getMessage());
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $body = (string) $response->getBody();
                Log::error('微信支付响应：' . $body);
            }
            throw new Exception('微信支付请求失败：' . $e->getMessage());
        } catch (\Exception $e) {
            Log::error('微信支付异常：' . $e->getMessage());
            throw new Exception('微信支付失败：' . $e->getMessage());
        }
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
        
        $appid = isset($config['appid']) ? trim($config['appid']) : '';
        $keyPath = isset($config['key_path']) ? trim($config['key_path']) : '';
        
        if ($appid === '' || $keyPath === '') {
            throw new Exception('微信支付配置不完整：appid 或 key_path 为空');
        }
        
        $timestamp = (string)time();
        $nonceStr = bin2hex(random_bytes(16));
        $package = 'prepay_id=' . $prepayId;
        
        // JSAPI 支付参数签名串：appId\ntimeStamp\nnonceStr\npackage\n
        $message = $appid . "\n" .
            $timestamp . "\n" .
            $nonceStr . "\n" .
            $package . "\n";
        
        // 加载私钥并签名
        $merchantPrivateKeyInstance = Rsa::from('file://' . $keyPath, Rsa::KEY_TYPE_PRIVATE);
        $paySign = Rsa::sign($message, $merchantPrivateKeyInstance);
        
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
     * 验证回调签名（使用微信支付公钥）
     * 
     * @param array  $headers [
     *   'timestamp' => '',
     *   'nonce'     => '',
     *   'signature' => '',
     *   'serial'    => '',  // 这个字段在公钥模式下可能不使用
     * ]
     * @param string $body    原始回调报文
     * @return bool
     */
    public static function verifyCallbackSignature(array $headers, $body)
    {
        $config = config('wechat.payment');
        $publicKeyPath = isset($config['platform_public_key_path']) ? trim($config['platform_public_key_path']) : '';
        $publicKeyId = isset($config['platform_public_key_id']) ? trim($config['platform_public_key_id']) : '';
        
        if ($publicKeyPath === '' || !is_file($publicKeyPath)) {
            Log::error('微信支付回调：微信支付公钥未配置或文件不存在');
            return false;
        }
        
        $timestamp = isset($headers['timestamp']) ? (string)$headers['timestamp'] : '';
        $nonce = isset($headers['nonce']) ? (string)$headers['nonce'] : '';
        $signature = isset($headers['signature']) ? (string)$headers['signature'] : '';
        
        if ($timestamp === '' || $nonce === '' || $signature === '') {
            Log::error('微信支付回调：签名头不完整');
            return false;
        }
        
        // 检查时间偏移量，允许5分钟之内的偏移
        $timeOffsetStatus = 300 >= abs(Formatter::timestamp() - (int)$timestamp);
        if (!$timeOffsetStatus) {
            Log::error('微信支付回调：时间偏移量超出允许范围');
            return false;
        }
        
        // 构造验签名串
        $message = Formatter::joinedByLineFeed($timestamp, $nonce, $body);
        
        // 加载微信支付公钥
        $platformPublicKeyInstance = Rsa::from('file://' . $publicKeyPath, Rsa::KEY_TYPE_PUBLIC);
        
        // 验证签名
        $verifiedStatus = Rsa::verify(
            $message,
            $signature,
            $platformPublicKeyInstance
        );
        
        if (!$verifiedStatus) {
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
        $config = config('wechat.payment');
        $apiV3Key = isset($config['apiv3_key']) ? trim($config['apiv3_key']) : '';
        
        if ($apiV3Key === '') {
            throw new Exception('微信支付配置未设置 APIv3 密钥');
        }
        
        if (empty($resource['ciphertext']) || empty($resource['nonce'])) {
            throw new Exception('回调资源数据不完整');
        }
        
        $ciphertext = $resource['ciphertext'];
        $nonce = $resource['nonce'];
        $associatedData = isset($resource['associated_data']) ? $resource['associated_data'] : '';
        
        // 使用 SDK 的 AesGcm 解密
        $plaintext = AesGcm::decrypt($ciphertext, $apiV3Key, $nonce, $associatedData);
        
        $data = json_decode($plaintext, true);
        if (!is_array($data)) {
            throw new Exception('回调报文解密后 JSON 解析失败');
        }
        
        return $data;
    }
    
    /**
     * 查询订单
     * 
     * @param string $outTradeNo 商户订单号
     * @return array
     * @throws Exception
     */
    public static function queryOrder($outTradeNo)
    {
        $config = config('wechat.payment');
        $mchId = isset($config['mch_id']) ? trim($config['mch_id']) : '';
        
        if ($mchId === '') {
            throw new Exception('微信支付配置不完整：mch_id 不能为空');
        }
        
        try {
            $instance = self::getInstance();
            $response = $instance
                ->chain('v3/pay/transactions/out-trade-no/{out_trade_no}')
                ->get([
                    'out_trade_no' => $outTradeNo,
                    'query' => ['mchid' => $mchId]
                ]);
            
            return json_decode($response->getBody(), true);
            
        } catch (\Exception $e) {
            Log::error('查询订单失败：' . $e->getMessage());
            throw new Exception('查询订单失败：' . $e->getMessage());
        }
    }
}
