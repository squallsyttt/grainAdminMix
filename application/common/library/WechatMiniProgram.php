<?php

namespace app\common\library;

use think\Exception;
use think\Log;

/**
 * 微信小程序登录服务类
 * 实现微信小程序登录流程，不依赖第三方库
 */
class WechatMiniProgram
{
    // 微信API接口地址
    const CODE2SESSION_URL = 'https://api.weixin.qq.com/sns/jscode2session';
    
    protected $appId;
    protected $appSecret;
    
    /**
     * 构造函数
     * @param string $appId 小程序AppID
     * @param string $appSecret 小程序AppSecret
     */
    public function __construct($appId = '', $appSecret = '')
    {
        $this->appId = $appId ?: config('wechat_miniprogram.app_id');
        $this->appSecret = $appSecret ?: config('wechat_miniprogram.app_secret');
        
        if (empty($this->appId) || empty($this->appSecret)) {
            throw new Exception('微信小程序配置未设置：app_id 或 app_secret');
        }
    }
    
    /**
     * 通过code换取用户session_key和openid
     * @param string $code 小程序登录凭证
     * @return array 包含openid、session_key、unionid等信息
     * @throws Exception
     */
    public function code2Session($code)
    {
        if (empty($code)) {
            throw new Exception('登录凭证code不能为空');
        }
        
        $params = [
            'appid' => $this->appId,
            'secret' => $this->appSecret,
            'js_code' => $code,
            'grant_type' => 'authorization_code'
        ];
        
        $url = self::CODE2SESSION_URL . '?' . http_build_query($params);
        
        $result = $this->httpGet($url);
        
        if (isset($result['errcode']) && $result['errcode'] != 0) {
            $errorMsg = $this->getErrorMessage($result['errcode']);
            Log::error('微信code2Session失败：' . json_encode($result, JSON_UNESCAPED_UNICODE));
            throw new Exception($errorMsg);
        }
        
        return [
            'openid' => $result['openid'] ?? '',
            'session_key' => $result['session_key'] ?? '',
            'unionid' => $result['unionid'] ?? '',
        ];
    }
    
    /**
     * 解密微信加密数据（如手机号）
     * @param string $encryptedData 加密数据
     * @param string $iv 初始向量
     * @param string $sessionKey 会话密钥
     * @return array 解密后的数据
     * @throws Exception
     */
    public function decryptData($encryptedData, $iv, $sessionKey)
    {
        $aesKey = base64_decode($sessionKey);
        $aesIV = base64_decode($iv);
        $aesCipher = base64_decode($encryptedData);
        
        $result = openssl_decrypt($aesCipher, 'AES-128-CBC', $aesKey, OPENSSL_RAW_DATA, $aesIV);
        
        if ($result === false) {
            throw new Exception('数据解密失败');
        }
        
        $data = json_decode($result, true);
        
        if (!$data) {
            throw new Exception('数据解密后格式错误');
        }
        
        // 验证appid
        if (isset($data['watermark']['appid']) && $data['watermark']['appid'] !== $this->appId) {
            throw new Exception('数据appid不匹配');
        }
        
        return $data;
    }
    
    /**
     * 发送HTTP GET请求
     * @param string $url 请求URL
     * @return array
     * @throws Exception
     */
    protected function httpGet($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false || $httpCode != 200) {
            throw new Exception('HTTP请求失败：' . $error);
        }
        
        $result = json_decode($response, true);
        
        if (!is_array($result)) {
            throw new Exception('微信接口返回数据格式错误');
        }
        
        return $result;
    }
    
    /**
     * 获取微信错误码对应的错误信息
     * @param int $errcode 错误码
     * @return string
     */
    protected function getErrorMessage($errcode)
    {
        $errorMessages = [
            -1 => '系统繁忙，此时请稍候再试',
            40029 => 'code无效',
            45011 => 'API调用太频繁，请稍后再试',
            40163 => 'code已被使用',
            40226 => '高风险等级用户，小程序登录拦截',
        ];
        
        return $errorMessages[$errcode] ?? "微信接口错误：{$errcode}";
    }
}
