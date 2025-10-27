<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\library\WechatMiniProgram;
use app\api\model\wanlshop\Third;
use fast\Random;
use think\Db;
use think\Exception;
use think\Log;

/**
 * 微信小程序授权登录控制器
 */
class MiniProgramAuth extends Api
{
    // 无需登录的接口
    protected $noNeedLogin = ['login', 'loginByPhone', 'register'];
    // 无需鉴权的接口
    protected $noNeedRight = ['*'];
    
    /**
     * 微信小程序登录
     * 
     * 流程说明：
     * 1. 小程序端调用 wx.login() 获取 code
     * 2. 将 code 发送到此接口
     * 3. 后端调用微信接口换取 openid 和 session_key
     * 4. 根据 openid/unionid 查找或创建用户
     * 5. 生成自定义登录态（token）返回给小程序
     * 
     * @ApiMethod (POST)
     * @param string $code 小程序登录凭证
     * @return void
     */
    public function login()
    {
        $code = $this->request->post('code', '');
        
        if (empty($code)) {
            $this->error('登录凭证code不能为空');
        }
        
        try {
            // 调用微信接口换取 openid 和 session_key
            $wechat = new WechatMiniProgram();
            $sessionData = $wechat->code2Session($code);
            
            $openid = $sessionData['openid'];
            $sessionKey = $sessionData['session_key'];
            $unionid = $sessionData['unionid'] ?? '';
            
            // 开启事务
            Db::startTrans();
            
            try {
                // 查询第三方登录记录
                $thirdQuery = Third::where('platform', 'miniprogram');
                if (!empty($unionid)) {
                    // 优先使用 unionid 查询（同一微信开放平台下唯一）
                    $thirdQuery->where('unionid', $unionid);
                } else {
                    // 使用 openid 查询
                    $thirdQuery->where('openid', $openid);
                }
                $third = $thirdQuery->find();
                
                $time = time();
                $userId = 0;
                $isNewUser = false;
                
                if ($third) {
                    // 已有登录记录，更新 session_key
                    $third->access_token = $sessionKey;
                    $third->logintime = $time;
                    $third->expiretime = $time + 7776000; // 90天
                    $third->save();
                    
                    $userId = $third->user_id;
                    
                    // 检查用户是否存在
                    if ($userId == 0) {
                        // 未绑定用户，需要进一步完善信息
                        Db::commit();
                        $this->success('需要完善用户信息', [
                            'is_new_user' => true,
                            'need_register' => true,
                            'third_token' => $third->token
                        ]);
                        return;
                    }
                } else {
                    // 新用户，创建第三方登录记录
                    $third = new Third();
                    $third->platform = 'miniprogram';
                    $third->openid = $openid;
                    $third->unionid = $unionid;
                    $third->access_token = $sessionKey;
                    $third->expires_in = 7776000;
                    $third->logintime = $time;
                    $third->expiretime = $time + 7776000;
                    $third->token = Random::uuid();
                    $third->user_id = 0; // 暂未绑定用户
                    $third->save();
                    
                    $isNewUser = true;
                    
                    // 返回需要完善信息
                    Db::commit();
                    $this->success('需要完善用户信息', [
                        'is_new_user' => true,
                        'need_register' => true,
                        'third_token' => $third->token
                    ]);
                    return;
                }
                
                // 已绑定用户，直接登录
                $ret = $this->auth->direct($userId);
                
                if ($ret) {
                    Db::commit();
                    $this->success('登录成功', [
                        'is_new_user' => $isNewUser,
                        'need_register' => false,
                        'userinfo' => $this->auth->getUserinfo()
                    ]);
                } else {
                    throw new Exception($this->auth->getError());
                }
                
            } catch (Exception $e) {
                Db::rollback();
                throw $e;
            }
            
        } catch (Exception $e) {
            Log::error('小程序登录失败：' . $e->getMessage());
            $this->error($e->getMessage());
        }
    }
    
    /**
     * 通过手机号登录/注册
     * 
     * 场景：小程序获取用户手机号授权后，使用手机号进行登录或注册
     * 
     * @ApiMethod (POST)
     * @param string $code 小程序登录凭证（用于获取session_key）
     * @param string $encryptedData 加密的手机号数据
     * @param string $iv 加密算法的初始向量
     * @return void
     */
    public function loginByPhone()
    {
        $jsCode       = $this->request->post('code', '');           // wx.login() 返回的 code（用于拿 openid）
        $phoneCode    = $this->request->post('phone_code', '');      // getPhoneNumber 返回的一次性 code（新推荐）
        $encryptedData = $this->request->post('encryptedData', '');  // 旧方式（可选）
        $iv            = $this->request->post('iv', '');             // 旧方式（可选）

        if (empty($phoneCode) && (empty($jsCode) || empty($encryptedData) || empty($iv))) {
            $this->error('参数不完整');
        }

        try {
            // 1) 获取 openid/unionid（两种路径均需要 openid 以进行第三方记录绑定）
            $wechat = new WechatMiniProgram();
            if (empty($jsCode)) {
                $this->error('code 不能为空');
            }
            $sessionData = $wechat->code2Session($jsCode);
            $openid     = $sessionData['openid'];
            $sessionKey = $sessionData['session_key'];
            $unionid    = $sessionData['unionid'] ?? '';

            // 2) 获取手机号
            if (!empty($phoneCode)) {
                // 新推荐：通过一次性 code 走服务端接口换手机号
                $phoneInfo = $wechat->getPhoneNumberByCode($phoneCode);
                $mobile = $phoneInfo['phoneNumber'] ?? '';
            } else {
                // 兼容旧方式：解密 encryptedData
                $phoneData = $wechat->decryptData($encryptedData, $iv, $sessionKey);
                $mobile = $phoneData['phoneNumber'] ?? '';
            }
            
            if (empty($mobile)) {
                $this->error('获取手机号失败');
            }
            
            // 开启事务
            Db::startTrans();
            
            try {
                // 3. 查找或创建用户
                $user = \app\common\model\User::where('mobile', $mobile)->find();
                
                if (!$user) {
                    // 自动注册新用户
                    $ret = $this->auth->register(
                        'u_' . Random::alnum(8), // 随机用户名
                        Random::alnum(16), // 随机密码
                        '', // 邮箱
                        $mobile, // 手机号
                        []
                    );
                    
                    if (!$ret) {
                        throw new Exception($this->auth->getError() ?: '注册失败');
                    }
                    
                    $user = $this->auth->getUser();
                } else {
                    // 已存在用户，直接登录
                    if ($user->status != 'normal') {
                        throw new Exception('账号已被锁定');
                    }
                    
                    $ret = $this->auth->direct($user->id);
                    if (!$ret) {
                        throw new Exception($this->auth->getError() ?: '登录失败');
                    }
                }
                
                // 4. 绑定或更新第三方登录记录
                $thirdQuery = Third::where('platform', 'miniprogram');
                if (!empty($unionid)) {
                    $thirdQuery->where('unionid', $unionid);
                } else {
                    $thirdQuery->where('openid', $openid);
                }
                $third = $thirdQuery->find();
                
                $time = time();
                
                if ($third) {
                    // 更新记录
                    $third->user_id = $user->id;
                    $third->access_token = $sessionKey;
                    $third->logintime = $time;
                    $third->expiretime = $time + 7776000;
                    $third->save();
                } else {
                    // 创建新记录
                    $third = new Third();
                    $third->platform = 'miniprogram';
                    $third->openid = $openid;
                    $third->unionid = $unionid;
                    $third->user_id = $user->id;
                    $third->access_token = $sessionKey;
                    $third->expires_in = 7776000;
                    $third->logintime = $time;
                    $third->expiretime = $time + 7776000;
                    $third->token = Random::uuid();
                    $third->save();
                }
                
                Db::commit();
                
                $this->success('登录成功', [
                    'userinfo' => $this->auth->getUserinfo()
                ]);
                
            } catch (Exception $e) {
                Db::rollback();
                throw $e;
            }
            
        } catch (Exception $e) {
            Log::error('手机号登录失败：' . $e->getMessage());
            $this->error($e->getMessage());
        }
    }
    
    /**
     * 完善用户信息（新用户注册）
     * 
     * 场景：用户首次登录，使用小程序授权信息完成注册
     * 
     * @ApiMethod (POST)
     * @param string $third_token 第三方登录临时token
     * @param string $nickname 昵称
     * @param string $avatar 头像URL
     * @param int $gender 性别：0=未知，1=男，2=女
     * @return void
     */
    public function register()
    {
        $thirdToken = $this->request->post('third_token', '');
        $nickname = $this->request->post('nickname', '');
        $avatar = $this->request->post('avatar', '');
        $gender = $this->request->post('gender', 0);
        
        if (empty($thirdToken)) {
            $this->error('third_token不能为空');
        }
        
        try {
            // 查找第三方登录记录
            $third = Third::where('token', $thirdToken)
                ->where('platform', 'miniprogram')
                ->find();
            
            if (!$third) {
                $this->error('无效的third_token');
            }
            
            if ($third->user_id != 0) {
                $this->error('该账号已绑定用户');
            }
            
            Db::startTrans();
            
            try {
                // 注册新用户
                $ret = $this->auth->register(
                    'u_' . Random::alnum(8), // 随机用户名
                    Random::alnum(16), // 随机密码
                    '', // 邮箱
                    '', // 手机号（可选）
                    [
                        'nickname' => $nickname ?: '微信用户',
                        'avatar' => $avatar,
                        'gender' => $gender
                    ]
                );
                
                if (!$ret) {
                    throw new Exception($this->auth->getError() ?: '注册失败');
                }
                
                // 更新第三方登录记录
                $third->user_id = $this->auth->id;
                $third->openname = $nickname;
                $third->save();
                
                Db::commit();
                
                $this->success('注册成功', [
                    'userinfo' => $this->auth->getUserinfo()
                ]);
                
            } catch (Exception $e) {
                Db::rollback();
                throw $e;
            }
            
        } catch (Exception $e) {
            Log::error('注册失败：' . $e->getMessage());
            $this->error($e->getMessage());
        }
    }
}
