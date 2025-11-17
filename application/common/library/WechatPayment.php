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
 * **架构模式**：微信支付公钥验签模式（Platform Public Key Mode）
 *
 * **核心特性**：
 * - JSAPI 下单（小程序支付）
 * - 回调签名验证（使用平台公钥）
 * - 回调报文解密（AES-GCM）
 *
 * **验签方式**：
 * - 请求签名：使用商户私钥（apiclient_key.pem）
 * - 响应验签：使用微信支付平台公钥（pub_key.pem）
 * - 回调验签：使用微信支付平台公钥（pub_key.pem）
 *
 * **配置要求**：
 * - platform_public_key_id: 平台公钥ID（必需）
 * - platform_public_key_path: 平台公钥文件路径（必需）
 * - 不再支持平台证书模式（platform_cert_*）
 *
 * @see https://pay.weixin.qq.com/wiki/doc/apiv3/wechatpay/wechatpay6_0.shtml 公钥验签说明
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

            if ($mchId === '' || $serialNo === '' || $keyPath === '') {
                throw new Exception('微信支付配置不完整：mch_id/serial_no/key_path 不能为空');
            }

            // 处理商户私钥路径：确保使用绝对路径
            if (!empty($keyPath)) {
                // 如果不是绝对路径，转换为绝对路径
                if (substr($keyPath, 0, 1) !== '/' && substr($keyPath, 1, 1) !== ':') {
                    // 相对路径，转换为绝对路径
                    $keyPath = ROOT_PATH . $keyPath;
                }

                // 验证文件是否存在
                if (!is_file($keyPath)) {
                    Log::error('商户私钥文件不存在：' . $keyPath);
                    throw new Exception('商户私钥文件不存在：' . $keyPath);
                }

                Log::info('使用商户私钥文件：' . $keyPath);
            }

            // 加载商户私钥
            $merchantPrivateKeyInstance = Rsa::from('file://' . $keyPath, Rsa::KEY_TYPE_PRIVATE);

            // 构建配置数组
            $buildConfig = [
                'mchid' => $mchId,
                'serial' => $serialNo,
                'privateKey' => $merchantPrivateKeyInstance,
            ];

            // 微信支付公钥验签模式：加载平台公钥
            $platformPublicKeyId = isset($config['platform_public_key_id']) ? trim($config['platform_public_key_id']) : '';
            $platformPublicKeyPath = isset($config['platform_public_key_path']) ? trim($config['platform_public_key_path']) : '';

            if ($platformPublicKeyId === '' || $platformPublicKeyPath === '') {
                throw new Exception('微信支付配置不完整：必须配置 platform_public_key_id 和 platform_public_key_path（公钥验签模式）');
            }

            // 处理平台公钥路径
            if (substr($platformPublicKeyPath, 0, 1) !== '/' && substr($platformPublicKeyPath, 1, 1) !== ':') {
                $platformPublicKeyPath = ROOT_PATH . $platformPublicKeyPath;
            }

            if (!is_file($platformPublicKeyPath)) {
                Log::error('微信支付平台公钥文件不存在：' . $platformPublicKeyPath);
                throw new Exception('微信支付平台公钥文件不存在：' . $platformPublicKeyPath);
            }

            // 加载平台公钥
            try {
                $platformPublicKeyInstance = Rsa::from('file://' . $platformPublicKeyPath, Rsa::KEY_TYPE_PUBLIC);
            } catch (\Throwable $e) {
                Log::error('加载微信支付平台公钥失败：' . $e->getMessage());
                throw new Exception('加载微信支付平台公钥失败：' . $e->getMessage());
            }

            // 配置平台公钥到 certs（使用公钥ID作为标识符）
            $buildConfig['certs'] = [
                $platformPublicKeyId => $platformPublicKeyInstance,
            ];

            Log::info('微信支付：使用公钥验签模式，公钥ID：' . $platformPublicKeyId);

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
        ];//var_dump($body);exit;
        
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
                throw new Exception('微信下单失败：' . json_encode($result, JSON_UNESCAPED_UNICODE));
            }

            return $result;

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $responseBody = '';
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $responseBody = (string) $response->getBody();
            }
            $message = '微信支付请求失败：' . $e->getMessage();
            if ($responseBody !== '') {
                $message .= ' | 响应：' . $responseBody;
            }
            throw new Exception($message);
        } catch (\Exception $e) {
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
        
        // 处理证书路径：确保使用绝对路径
        if (!empty($keyPath)) {
            if (substr($keyPath, 0, 1) !== '/' && substr($keyPath, 1, 1) !== ':') {
                // 相对路径，转换为绝对路径
                $keyPath = ROOT_PATH . $keyPath;
            }
            
            if (!is_file($keyPath)) {
                throw new Exception('商户私钥文件不存在：' . $keyPath);
            }
        }
        
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
     *   'serial'    => '',  // 公钥模式下对应 platform_public_key_id
     * ]
     * @param string $body    原始回调报文
     * @return bool
     */
    public static function verifyCallbackSignature(array $headers, $body)
    {
        $config = config('wechat.payment');
        $platformPublicKeyId = isset($config['platform_public_key_id']) ? trim($config['platform_public_key_id']) : '';
        $platformPublicKeyPath = isset($config['platform_public_key_path']) ? trim($config['platform_public_key_path']) : '';

        if ($platformPublicKeyId === '' || $platformPublicKeyPath === '') {
            Log::error('微信支付回调：未配置平台公钥（platform_public_key_id 和 platform_public_key_path）');
            return false;
        }

        $timestamp = isset($headers['timestamp']) ? (string)$headers['timestamp'] : '';
        $nonce = isset($headers['nonce']) ? (string)$headers['nonce'] : '';
        $signature = isset($headers['signature']) ? (string)$headers['signature'] : '';
        $serialHeader = '';
        foreach (['serial', 'wechatpay-serial', 'Wechatpay-Serial', 'Wechatpay-serial'] as $serialKey) {
            if (!empty($headers[$serialKey])) {
                $serialHeader = (string)$headers[$serialKey];
                break;
            }
        }

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

        // 验证公钥ID是否匹配
        if ($serialHeader !== '' && $serialHeader !== $platformPublicKeyId) {
            Log::error('微信支付回调：公钥ID不匹配，期望：' . $platformPublicKeyId . '，实际：' . $serialHeader);
            return false;
        }

        // 构造验签名串
        $message = Formatter::joinedByLineFeed($timestamp, $nonce, $body);

        // 加载平台公钥
        if (substr($platformPublicKeyPath, 0, 1) !== '/' && substr($platformPublicKeyPath, 1, 1) !== ':') {
            $platformPublicKeyPath = ROOT_PATH . $platformPublicKeyPath;
        }

        if (!is_file($platformPublicKeyPath)) {
            Log::error('微信支付回调：平台公钥文件不存在：' . $platformPublicKeyPath);
            return false;
        }

        try {
            $platformPublicKeyInstance = Rsa::from('file://' . $platformPublicKeyPath, Rsa::KEY_TYPE_PUBLIC);
        } catch (\Throwable $e) {
            Log::error('微信支付回调：加载平台公钥失败：' . $e->getMessage());
            return false;
        }

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
