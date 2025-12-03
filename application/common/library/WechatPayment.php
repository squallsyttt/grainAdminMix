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
 * - 回调签名验证（使用平台公钥或平台证书）
 * - 回调报文解密（AES-GCM）
 *
 * **验签方式**：
 * - 请求签名：使用商户私钥（apiclient_key.pem）
 * - 响应验签：优先使用平台证书，备用平台公钥
 * - 回调验签：优先使用平台证书，备用平台公钥
 *
 * **配置要求**：
 * - platform_public_key_id: 平台公钥ID（必需）
 * - platform_public_key_path: 平台公钥文件路径（必需）
 * - platform_public_cert_serial_no: 平台证书序列号（可选，推荐配置）
 * - platform_public_cert_path: 平台证书文件路径（可选，推荐配置）
 *
 * **验签机制**：SDK 会自动选择最合适的验签方式（证书优先，公钥备用）
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
     * 获取 SDK 实例（简化重构版，参考官方示范代码）
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

            // 1. 验证必需配置
            $requiredKeys = ['mch_id', 'serial_no', 'key_path', 'platform_public_key_id', 'platform_public_key_path'];
            foreach ($requiredKeys as $key) {
                if (empty($config[$key])) {
                    throw new Exception("微信支付配置不完整：{$key} 不能为空");
                }
                self::assertString($config[$key], "微信支付配置项 {$key}");
            }

            // 2. 加载商户私钥（用于生成请求签名）
            $merchantPrivateKeyPath = self::resolveFilePath($config['key_path'], '商户私钥');
            $merchantPrivateKeyInstance = Rsa::from('file://' . $merchantPrivateKeyPath, Rsa::KEY_TYPE_PRIVATE);

            // 3. 初始化构建配置
            $buildConfig = [
                'mchid'      => trim((string)$config['mch_id']),
                'serial'     => trim((string)$config['serial_no']),
                'privateKey' => $merchantPrivateKeyInstance,
                'certs'      => [],
            ];

            // 4. 加载平台公钥（必需，用于验签）
            $platformPublicKeyId = trim((string)$config['platform_public_key_id']);
            $platformPublicKeyPath = self::resolveFilePath($config['platform_public_key_path'], '平台公钥');
            $platformPublicKeyInstance = Rsa::from('file://' . $platformPublicKeyPath, Rsa::KEY_TYPE_PUBLIC);
            $buildConfig['certs'][$platformPublicKeyId] = $platformPublicKeyInstance;

            Log::info('微信支付初始化：公钥ID = ' . $platformPublicKeyId);

            // 5. 可选：加载平台证书（推荐配置，SDK 优先使用证书验签）
            if (!empty($config['platform_public_cert_serial_no']) && !empty($config['platform_public_cert_path'])) {
                self::assertString($config['platform_public_cert_serial_no'], '微信支付配置项 platform_public_cert_serial_no');
                self::assertString($config['platform_public_cert_path'], '微信支付配置项 platform_public_cert_path');

                $platformCertSerialNo = trim((string)$config['platform_public_cert_serial_no']);
                $platformCertPath = self::resolveFilePath($config['platform_public_cert_path'], '平台证书', false);

                if ($platformCertPath !== null) {
                    try {
                        $platformCertInstance = Rsa::from('file://' . $platformCertPath, Rsa::KEY_TYPE_PUBLIC);
                        $buildConfig['certs'][$platformCertSerialNo] = $platformCertInstance;
                        Log::info('微信支付初始化：已加载平台证书，序列号 = ' . $platformCertSerialNo);
                    } catch (\Throwable $e) {
                        Log::warning('平台证书加载失败（将使用公钥验签）：' . $e->getMessage());
                    }
                }
            }

            // 6. 构造 APIv3 客户端实例
            self::$instance = Builder::factory($buildConfig);
        }

        return self::$instance;
    }

    /**
     * 统一处理文件路径：相对路径转绝对路径，验证文件存在
     *
     * @param string $path         配置的路径（可能是相对路径或绝对路径）
     * @param string $description  文件描述（用于日志）
     * @param bool   $required     是否必需（必需文件不存在时抛出异常，可选文件返回 null）
     * @return string|null         绝对路径（文件存在时）或 null（可选文件不存在时）
     * @throws Exception
     */
    private static function resolveFilePath($path, $description, $required = true)
    {
        // 防御：配置项必须是字符串
        if (!is_string($path)) {
            $type = gettype($path);
            $message = "{$description}路径配置异常，期望字符串，实际为 {$type}";
            Log::error($message);
            if ($required) {
                throw new Exception($message);
            }
            return null;
        }

        $path = trim($path);

        // 如果不是绝对路径，转换为绝对路径
        if (substr($path, 0, 1) !== '/' && substr($path, 1, 1) !== ':') {
            $path = ROOT_PATH . $path;
        }

        // 验证文件是否存在
        if (!is_file($path)) {
            $message = "{$description}文件不存在：{$path}";
            if ($required) {
                Log::error($message);
                throw new Exception($message);
            } else {
                Log::warning($message);
                return null;
            }
        }

        Log::info("{$description}文件：{$path}");
        return $path;
    }

    /**
     * 校验配置项必须为字符串
     *
     * @param mixed  $value
     * @param string $label
     * @throws Exception
     */
    private static function assertString($value, string $label)
    {
        if (!is_string($value)) {
            $type = gettype($value);
            $message = "{$label} 应为字符串，实际为 {$type}";
            Log::error($message);
            throw new Exception($message);
        }
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

        if ($serialHeader === '') {
            Log::error('微信支付回调：缺少证书序列号');
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

        // 根据证书序列号加载对应的平台证书
        $platformCertPath = ROOT_PATH . 'cert/wechat/wechatpay_' . $serialHeader . '.pem';
        
        if (!is_file($platformCertPath)) {
            Log::error('微信支付回调：平台证书文件不存在：' . $platformCertPath);
            Log::info('提示：请确保已下载对应的平台证书，序列号：' . $serialHeader);
            return false;
        }

        try {
            $platformPublicKeyInstance = Rsa::from('file://' . $platformCertPath, Rsa::KEY_TYPE_PUBLIC);
        } catch (\Throwable $e) {
            Log::error('微信支付回调：加载平台证书失败：' . $e->getMessage());
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

    /**
     * 商家转账到零钱（新版API）
     *
     * 微信支付 V3 API：POST /v3/fund-app/mch-transfer/transfer-bills
     * 注意：新版API需要用户确认收款，返回 package_info 供前端调起确认页面
     *
     * @param array $params [
     *   'out_bill_no'          => string 商户单号（必填，唯一）
     *   'openid'               => string 收款用户OpenID（必填）
     *   'transfer_amount'      => int    转账金额（单位：分，必填）
     *   'transfer_remark'      => string 转账备注（必填）
     *   'transfer_scene_id'    => string 转账场景ID（必填，如 1000=现金营销）
     *   'notify_url'           => string 回调URL（可选）
     *   'user_name'            => string 收款人姓名（>=2000元时必填）
     *   'user_recv_perception' => string 用户收款感知（可选）
     *   'scene_report_infos'   => array  场景报备信息（必填，至少2项）
     * ]
     * @return array
     * @throws Exception
     */
    public static function transferToWallet(array $params)
    {
        $config = config('wechat.payment');
        if (empty($config) || !is_array($config)) {
            throw new Exception('微信支付配置未设置（wechat.payment）');
        }

        $appid = isset($config['appid']) ? trim($config['appid']) : '';
        $defaultNotifyUrl = isset($config['transfer_notify_url']) ? trim($config['transfer_notify_url']) : '';
        if ($appid === '') {
            throw new Exception('微信支付配置不完整：appid 不能为空');
        }

        // 可选：平台证书路径（用于 user_name 加密）
        $platformCertPath = !empty($config['platform_public_cert_path'])
            ? self::resolveFilePath($config['platform_public_cert_path'], '平台证书', false)
            : null;
        $platformPublicCert = null;
        if ($platformCertPath !== null) {
            $platformPublicCert = Rsa::from('file://' . $platformCertPath, Rsa::KEY_TYPE_PUBLIC);
        }

        // 参数验证
        if (empty($params['out_bill_no'])) {
            throw new Exception('转账参数不完整：out_bill_no 不能为空');
        }
        if (empty($params['openid'])) {
            throw new Exception('转账参数不完整：openid 不能为空');
        }
        if (empty($params['transfer_amount']) || (int)$params['transfer_amount'] <= 0) {
            throw new Exception('转账参数不完整：transfer_amount 必须大于0');
        }
        if (empty($params['transfer_remark'])) {
            throw new Exception('转账参数不完整：transfer_remark 不能为空');
        }

        // 转账场景ID，默认使用 1000（现金营销）
        $transferSceneId = !empty($params['transfer_scene_id']) ? (string)$params['transfer_scene_id'] : '1000';

        // 场景报备信息，至少需要2项
        $sceneReportInfos = [];
        if (!empty($params['scene_report_infos']) && is_array($params['scene_report_infos'])) {
            $sceneReportInfos = $params['scene_report_infos'];
        } else {
            // 默认报备信息
            $sceneReportInfos = [
                ['info_type' => '活动名称', 'info_content' => '核销券结算'],
                ['info_type' => '奖励说明', 'info_content' => '商家结算打款'],
            ];
        }

        $body = [
            'appid'                       => $appid,
            'out_bill_no'                 => (string)$params['out_bill_no'],
            'transfer_scene_id'           => $transferSceneId,
            'openid'                      => (string)$params['openid'],
            'transfer_amount'             => (int)$params['transfer_amount'],
            'transfer_remark'             => (string)$params['transfer_remark'],
            'transfer_scene_report_infos' => $sceneReportInfos,
        ];

        // 可选参数：回调URL
        if (!empty($params['notify_url'])) {
            $body['notify_url'] = (string)$params['notify_url'];
        } elseif ($defaultNotifyUrl !== '') {
            $body['notify_url'] = $defaultNotifyUrl;
        }

        // 可选参数：用户收款感知
        if (!empty($params['user_recv_perception'])) {
            $body['user_recv_perception'] = (string)$params['user_recv_perception'];
        }

        // 可选参数：收款人姓名（>=2000元时必填，需加密）
        if (!empty($params['user_name'])) {
            if ($platformPublicCert === null) {
                throw new Exception('转账参数不完整：缺少平台证书，无法加密 user_name');
            }
            $body['user_name'] = Rsa::encrypt((string)$params['user_name'], $platformPublicCert);
        }

        Log::info('微信转账请求（新版API）：' . json_encode($body, JSON_UNESCAPED_UNICODE));

        try {
            $instance = self::getInstance();
            $response = $instance->chain('v3/fund-app/mch-transfer/transfer-bills')->post(['json' => $body]);

            $result = json_decode($response->getBody(), true);

            Log::info('微信转账成功：' . json_encode($result, JSON_UNESCAPED_UNICODE));

            return $result;

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $responseBody = '';
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $responseBody = (string)$response->getBody();
            }
            $message = '微信转账请求失败：' . $e->getMessage();
            if ($responseBody !== '') {
                $message .= ' | 响应：' . $responseBody;
            }
            Log::error($message);
            throw new Exception($message);
        } catch (\Exception $e) {
            Log::error('微信转账失败：' . $e->getMessage());
            throw new Exception('微信转账失败：' . $e->getMessage());
        }
    }

    /**
     * 申请退款
     *
     * 微信支付 V3 API：POST /v3/refund/domestic/refunds
     *
     * @param array $params [
     *   'transaction_id' => string  微信支付订单号（与 out_trade_no 二选一）
     *   'out_trade_no'   => string  商户订单号（与 transaction_id 二选一）
     *   'out_refund_no'  => string  商户退款单号（必填，商户系统内部唯一）
     *   'reason'         => string  退款原因（可选）
     *   'notify_url'     => string  退款结果回调地址（可选，不填则使用商户平台配置的）
     *   'refund_amount'  => int     退款金额（单位：分）
     *   'total_amount'   => int     原订单金额（单位：分）
     * ]
     * @return array 退款响应结果
     * @throws Exception
     */
    public static function refund(array $params)
    {
        $config = config('wechat.payment');

        if (empty($config) || !is_array($config)) {
            throw new Exception('微信支付配置未设置（wechat.payment）');
        }

        // 验证必填参数
        if (empty($params['out_refund_no'])) {
            throw new Exception('退款参数不完整：out_refund_no 不能为空');
        }
        if (empty($params['transaction_id']) && empty($params['out_trade_no'])) {
            throw new Exception('退款参数不完整：transaction_id 或 out_trade_no 必须提供其一');
        }
        if (empty($params['refund_amount']) || empty($params['total_amount'])) {
            throw new Exception('退款参数不完整：refund_amount 和 total_amount 不能为空');
        }

        // 构建请求体
        $body = [
            'out_refund_no' => (string)$params['out_refund_no'],
            'amount' => [
                'refund'   => (int)$params['refund_amount'],
                'total'    => (int)$params['total_amount'],
                'currency' => 'CNY',
            ],
        ];

        // 微信支付订单号优先
        if (!empty($params['transaction_id'])) {
            $body['transaction_id'] = (string)$params['transaction_id'];
        } else {
            $body['out_trade_no'] = (string)$params['out_trade_no'];
        }

        // 可选参数
        if (!empty($params['reason'])) {
            $body['reason'] = (string)$params['reason'];
        }
        if (!empty($params['notify_url'])) {
            $body['notify_url'] = (string)$params['notify_url'];
        }

        try {
            $instance = self::getInstance();
            $response = $instance->chain('v3/refund/domestic/refunds')->post(['json' => $body]);

            $result = json_decode($response->getBody(), true);

            Log::info('微信退款请求成功：' . json_encode($result, JSON_UNESCAPED_UNICODE));

            return $result;

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $responseBody = '';
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $responseBody = (string)$response->getBody();
            }
            $message = '微信退款请求失败：' . $e->getMessage();
            if ($responseBody !== '') {
                $message .= ' | 响应：' . $responseBody;
            }
            Log::error($message);
            throw new Exception($message);
        } catch (\Exception $e) {
            Log::error('微信退款失败：' . $e->getMessage());
            throw new Exception('微信退款失败：' . $e->getMessage());
        }
    }

    /**
     * 查询退款
     *
     * @param string $outRefundNo 商户退款单号
     * @return array
     * @throws Exception
     */
    public static function queryRefund($outRefundNo)
    {
        if (empty($outRefundNo)) {
            throw new Exception('退款单号不能为空');
        }

        try {
            $instance = self::getInstance();
            $response = $instance
                ->chain('v3/refund/domestic/refunds/{out_refund_no}')
                ->get(['out_refund_no' => $outRefundNo]);

            return json_decode($response->getBody(), true);

        } catch (\Exception $e) {
            Log::error('查询退款失败：' . $e->getMessage());
            throw new Exception('查询退款失败：' . $e->getMessage());
        }
    }
}
