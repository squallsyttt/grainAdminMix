<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\library\WechatMiniProgram;
use app\admin\model\wanlshop\Shop;
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
                        // 未绑定用户，自动注册并绑定
                        $ret = $this->auth->register(
                            'u_' . Random::alnum(8),
                            Random::alnum(16),
                            '',
                            '',
                            []
                        );
                        if (!$ret) {
                            throw new Exception($this->auth->getError() ?: '注册失败');
                        }
                        $user = $this->auth->getUser();
                        // 绑定第三方记录到新用户
                        $third->user_id = $user->id;
                        $third->save();
                        
                        $isNewUser = true;
                        $bindShop = $this->getBindShop($user->id);
                        Db::commit();
                        $this->success('登录成功', [
                            'is_new_user' => $isNewUser,
                            'need_register' => false,
                            'userinfo' => $this->auth->getUserinfo(),
                            'bind_shop' => $bindShop
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
                    
                    // 自动注册并绑定
                    $ret = $this->auth->register(
                        'u_' . Random::alnum(8),
                        Random::alnum(16),
                        '',
                        '',
                        []
                    );
                    if (!$ret) {
                        throw new Exception($this->auth->getError() ?: '注册失败');
                    }
                    $user = $this->auth->getUser();
                    $third->user_id = $user->id;
                    $third->save();
                    
                    $isNewUser = true;
                    $bindShop = $this->getBindShop($user->id);
                    
                    Db::commit();
                    $this->success('登录成功', [
                        'is_new_user' => $isNewUser,
                        'need_register' => false,
                        'userinfo' => $this->auth->getUserinfo(),
                        'bind_shop' => $bindShop
                    ]);
                    return;
                }
                
                // 已绑定用户，直接登录
                $ret = $this->auth->direct($userId);
                
                if ($ret) {
                    $bindShop = $this->getBindShop($userId);
                    Db::commit();
                    $this->success('登录成功', [
                        'is_new_user' => $isNewUser,
                        'need_register' => false,
                        'userinfo' => $this->auth->getUserinfo(),
                        'bind_shop' => $bindShop
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
     * @param string $code 小程序登录凭证（wx.login 返回，用于获取 session_key/openid）
     * @param string $phone_code getPhoneNumber 事件返回的一次性 code（推荐且仅保留该方式）
     * @return void
     */
    public function loginByPhone()
    {
        $jsCode    = $this->request->post('code', '');        // wx.login() 返回的 code（用于拿 openid）
        $phoneCode = $this->request->post('phone_code', '');  // getPhoneNumber 返回的一次性 code（保留的唯一方式）

        if (empty($jsCode) || empty($phoneCode)) {
            $this->error('code 与 phone_code 均不能为空');
        }

        try {
            // 1) 获取 openid/unionid（用于第三方记录绑定）
            $wechat = new WechatMiniProgram();
            $sessionData = $wechat->code2Session($jsCode);
            $openid     = $sessionData['openid'];
            $sessionKey = $sessionData['session_key'];
            $unionid    = $sessionData['unionid'] ?? '';

            // 2) 通过一次性 phone_code 获取手机号（唯一保留方式）
            $phoneInfo = $wechat->getPhoneNumberByCode($phoneCode);
            $mobile = $phoneInfo['phoneNumber'] ?? '';
            
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
                
                $bindShop = $this->getBindShop($user->id);
                Db::commit();
                
                $this->success('登录成功', [
                    'userinfo' => $this->auth->getUserinfo(),
                    'bind_shop' => $bindShop
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
                
                $bindShop = $this->getBindShop($this->auth->id);
                Db::commit();
                
                $this->success('注册成功', [
                    'userinfo' => $this->auth->getUserinfo(),
                    'bind_shop' => $bindShop
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

    /**
     * 绑定邀请码
     *
     * @ApiSummary  (绑定邀请人邀请码)
     * @ApiMethod   (POST)
     * @param string $invite_code 邀请码
     */
    public function bindInviteCode()
    {
        if (!$this->request->isPost()) {
            $this->error(__('非法请求'));
        }
        
        $inviteCode = strtoupper($this->request->post('invite_code', ''));
        if (empty($inviteCode)) {
            $this->error(__('请输入邀请码'));
        }
        
        // 验证邀请码格式
        if (!preg_match('/^[A-Z0-9]{8}$/', $inviteCode)) {
            $this->error(__('邀请码格式不正确'));
        }

        $userId = $this->auth->id;
        $currentUser = Db::name('user')->where('id', $userId)->field('id, inviter_id')->find();
        if (!$currentUser) {
            $this->error(__('用户不存在'));
        }
        if (!empty($currentUser['inviter_id'])) {
            $this->error(__('您已绑定过邀请码'));
        }
        
        // 查询邀请码对应用户
        $inviter = Db::name('user')
            ->where('invite_code', $inviteCode)
            ->field('id, nickname, invite_code, bonus_level, bonus_ratio')
            ->find();
        if (!$inviter) {
            $this->error(__('邀请码无效'));
        }
        
        // 不能绑定自己
        if ((int)$inviter['id'] === (int)$userId) {
            $this->error(__('不能绑定自己的邀请码'));
        }

        $now = time();

        Db::startTrans();
        try {
            $updated = Db::name('user')
                ->where('id', $userId)
                ->update([
                    'inviter_id' => $inviter['id'],
                    'invite_bind_time' => $now
                ]);

            if ($updated === false) {
                throw new Exception('绑定邀请码失败');
            }

            Db::commit();

            $ratios = $this->getInviteRatios();
            $inviterLevel = (int)$inviter['bonus_level'];
            $rebateRate = isset($ratios[$inviterLevel]) ? (float)$ratios[$inviterLevel] : (float)$inviter['bonus_ratio'];

            $this->success('绑定成功', [
                'inviteCode' => $inviter['invite_code'],
                'inviterLevel' => $inviterLevel,
                'rebateRate' => $rebateRate,
                'boundAt' => date('Y-m-d H:i:s', $now),
                'isFirstBind' => true,
                'upgradeHint' => '核销后可为邀请人升级，最多2级'
            ]);
        } catch (Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }

    /**
     * 个人邀请信息
     *
     * @ApiMethod   (GET)
     */
    public function inviteInfo()
    {
        try {
            $userId = $this->auth->id;
            $user = Db::name('user')
                ->where('id', $userId)
                ->field('id, invite_code, bonus_level, bonus_ratio, inviter_id, invite_bind_time')
                ->find();

            if (!$user) {
                $this->error('用户不存在');
            }

            $ratios = $this->getInviteRatios();
            $level = (int)$user['bonus_level'];
            $rebateRate = isset($ratios[$level]) ? (float)$ratios[$level] : (float)$user['bonus_ratio'];

            $invitedTotal = (int)Db::name('user')->where('inviter_id', $userId)->count();
            $verifiedInvitees = (int)Db::name('wanlshop_voucher_verification')
                ->alias('vv')
                ->join('__USER__ u', 'u.id = vv.user_id')
                ->where('u.inviter_id', $userId)
                ->count('DISTINCT vv.user_id');
            $pendingInvitees = max($invitedTotal - $verifiedInvitees, 0);

            $nextLevel = $level < 2 ? $level + 1 : null;
            $nextRebateRate = $nextLevel !== null && isset($ratios[$nextLevel]) ? (float)$ratios[$nextLevel] : null;
            $upgradeRule = '被邀请人核销触发升级，最多2次升级';

            // 查询当前用户绑定的邀请人信息
            $boundInviter = null;
            if (!empty($user['inviter_id'])) {
                $inviter = Db::name('user')
                    ->where('id', $user['inviter_id'])
                    ->field('id, nickname, avatar, invite_code, bonus_level, bonus_ratio')
                    ->find();
                if ($inviter) {
                    $inviterLevel = (int)$inviter['bonus_level'];
                    $inviterRebateRate = isset($ratios[$inviterLevel]) ? (float)$ratios[$inviterLevel] : (float)$inviter['bonus_ratio'];
                    $boundInviter = [
                        'userId' => (int)$inviter['id'],
                        'nickname' => $inviter['nickname'],
                        'avatar' => $inviter['avatar'],
                        'inviteCode' => $inviter['invite_code'],
                        'level' => $inviterLevel,
                        'rebateRate' => $inviterRebateRate,
                        'boundAt' => !empty($user['invite_bind_time']) ? date('Y-m-d H:i:s', $user['invite_bind_time']) : null
                    ];
                }
            }

            // 【新增】店铺邀请统计（简化版：只统计邀请数和已触发升级数）
            $shopInvitedTotal = (int)Db::name('wanlshop_shop')
                ->where('inviter_id', $userId)
                ->count();

            // 已触发升级的店铺数（通过升级日志表判断）
            $shopUpgradedCount = (int)Db::name('shop_invite_upgrade_log')
                ->where('user_id', $userId)
                ->count();

            // 【新增】用户邀请返利统计
            $userRebatePending = (int)Db::name('user_invite_pending')
                ->where('inviter_id', $userId)
                ->where('state', 0)
                ->count();

            $userRebateGranted = (int)Db::name('user_invite_rebate_log')
                ->where('inviter_id', $userId)
                ->count();

            $userRebateTotal = (float)Db::name('user_invite_rebate_log')
                ->where('inviter_id', $userId)
                ->sum('rebate_amount');

            $this->success('ok', [
                'inviteCode' => $user['invite_code'],
                'level' => $level,
                'levelName' => 'Level ' . $level,
                'rebateRate' => $rebateRate,
                'rebateText' => sprintf('Level %d 返利 %.2f%%', $level, $rebateRate),
                'invitedTotal' => $invitedTotal,
                'verifiedInvitees' => $verifiedInvitees,
                'pendingInvitees' => $pendingInvitees,
                'nextLevel' => $nextLevel,
                'nextRebateRate' => $nextRebateRate,
                'upgradeRule' => $upgradeRule,
                'recentRebates' => [],
                'boundInviter' => $boundInviter,
                // 【简化】店铺邀请统计：只保留总数和已升级数
                'shopInvitedTotal' => $shopInvitedTotal,
                'shopUpgradedCount' => $shopUpgradedCount,
                // 【新增】用户邀请返利统计
                'userRebateStats' => [
                    'pendingCount' => $userRebatePending,
                    'grantedCount' => $userRebateGranted,
                    'totalAmount' => round($userRebateTotal, 2)
                ]
            ]);
        } catch (Exception $e) {
            Log::error('获取邀请信息失败：' . $e->getMessage());
            $this->error($e->getMessage());
        }
    }

    /**
     * 兼容旧路径
     */
    public function getInviteInfo()
    {
        $this->inviteInfo();
    }

    /**
     * 邀请列表
     *
     * @ApiMethod   (GET)
     */
    public function inviteeList()
    {
        $page = (int)$this->request->get('page', 1);
        $limit = (int)$this->request->get('limit', 10);
        $page = $page > 0 ? $page : 1;
        $limit = $limit > 0 ? $limit : 10;

        try {
            $userId = $this->auth->id;

            // 如果没有邀请任何人,直接返回空列表
            $invitedCount = Db::name('user')->where('inviter_id', $userId)->count();
            if ($invitedCount == 0) {
                $this->success('ok', [
                    'page' => $page,
                    'perPage' => $limit,
                    'total' => 0,
                    'list' => []
                ]);
                return;
            }

            $currentUser = Db::name('user')
                ->where('id', $userId)
                ->field('invite_code, bonus_level, bonus_ratio')
                ->find();
            if (!$currentUser) {
                $this->error('用户不存在');
            }

            $ratios = $this->getInviteRatios();
            $currentLevel = (int)$currentUser['bonus_level'];
            $defaultRebateRate = isset($ratios[$currentLevel]) ? (float)$ratios[$currentLevel] : (float)$currentUser['bonus_ratio'];
            $currentInviteCode = $currentUser['invite_code'];

            $baseQuery = Db::name('user')->where('inviter_id', $userId);
            $total = (int)(clone $baseQuery)->count();

            $rows = (clone $baseQuery)
                ->alias('u')
                ->field('u.id,u.nickname,u.avatar,u.invite_bind_time,u.jointime')
                ->order('u.invite_bind_time desc,u.id desc')
                ->page($page, $limit)
                ->select();

            if ($rows instanceof \think\Collection) {
                $rows = $rows->toArray();
            }

            $inviteeIds = array_column($rows, 'id');

            $writeoffCountMap = [];
            $lastWriteoffMap = [];
            $upgradeRatioMap = [];

            if (!empty($inviteeIds)) {
                // 统计每个被邀请人的核销次数
                $writeoffData = Db::name('wanlshop_voucher_verification')
                    ->field('user_id, COUNT(*) as count')
                    ->where('user_id', 'in', $inviteeIds)
                    ->group('user_id')
                    ->select();
                foreach ($writeoffData as $item) {
                    $writeoffCountMap[$item['user_id']] = (int)$item['count'];
                }

                // 获取每个被邀请人的最后核销时间
                $lastWriteoffData = Db::name('wanlshop_voucher_verification')
                    ->field('user_id, MAX(createtime) as last_time')
                    ->where('user_id', 'in', $inviteeIds)
                    ->group('user_id')
                    ->select();
                foreach ($lastWriteoffData as $item) {
                    $lastWriteoffMap[$item['user_id']] = (int)$item['last_time'];
                }

                // 获取升级后的返利比例
                $upgradeRatioMap = Db::name('user_invite_upgrade_log')
                    ->where('user_id', $userId)
                    ->where('invitee_id', 'in', $inviteeIds)
                    ->column('after_ratio', 'invitee_id');
            }

            $list = [];
            foreach ($rows as $row) {
                $inviteeId = (int)$row['id'];
                $writeoffCount = isset($writeoffCountMap[$inviteeId]) ? (int)$writeoffCountMap[$inviteeId] : 0;
                $lastWriteoffAt = isset($lastWriteoffMap[$inviteeId]) ? (int)$lastWriteoffMap[$inviteeId] : 0;
                $upgradeRatio = isset($upgradeRatioMap[$inviteeId]) ? (float)$upgradeRatioMap[$inviteeId] : null;
                $bindTime = !empty($row['invite_bind_time']) ? (int)$row['invite_bind_time'] : (!empty($row['jointime']) ? (int)$row['jointime'] : 0);

                $list[] = [
                    'userId' => $inviteeId,
                    'nickname' => $row['nickname'],
                    'avatar' => $row['avatar'],
                    'inviteCode' => $currentInviteCode,
                    'bindAt' => $bindTime ? date('Y-m-d H:i:s', $bindTime) : null,
                    'writeoffCount' => $writeoffCount,
                    'lastWriteoffAt' => $lastWriteoffAt ? date('Y-m-d H:i:s', $lastWriteoffAt) : null,
                    'status' => $writeoffCount > 0 ? 'verified' : 'pending',
                    'rebateRate' => $upgradeRatio !== null ? $upgradeRatio : $defaultRebateRate,
                    'upgradeCount' => $upgradeRatio !== null ? 1 : 0
                ];
            }

            $this->success('ok', [
                'page' => $page,
                'perPage' => $limit,
                'total' => $total,
                'list' => $list
            ]);
        } catch (Exception $e) {
            Log::error('获取邀请列表失败：' . $e->getMessage());
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取返利比例配置
     *
     * @return array
     * @throws Exception
     */
    protected function getInviteRatios()
    {
        $ratios = [
            0 => config('site.invite_base_ratio'),
            1 => config('site.invite_level1_ratio'),
            2 => config('site.invite_level2_ratio'),
        ];

        foreach ($ratios as $level => $ratio) {
            if (!is_numeric($ratio)) {
                throw new Exception('返利比例配置异常');
            }
            $ratios[$level] = (float)$ratio;
        }

        return $ratios;
    }

    /**
     * 获取用户绑定的店铺信息
     * 
     * @param int $userId
     * @return array|null
     */
    protected function getBindShop($userId)
    {
        // 从 user 表获取 bind_shop 字段（存储的是 shop_id）
        $bindShopId = Db::name('user')->where('id', $userId)->value('bind_shop');
        if (empty($bindShopId)) {
            return null;
        }

        // 直接用 Db 查询，避免触发模型的 append 属性
        $shop = Db::name('wanlshop_shop')
            ->where('id', $bindShopId)
            ->field('id, shopname, avatar, state')
            ->find();

        return $shop ?: null;
    }

    /**
     * 解绑店铺
     *
     * @ApiSummary  (解绑当前用户绑定的店铺)
     * @ApiMethod   (POST)
     */
    public function unbindShop()
    {
        if (!$this->request->isPost()) {
            $this->error(__('非法请求'));
        }

        Db::name('user')->where('id', $this->auth->id)->update(['bind_shop' => null]);

        $this->success('解绑成功');
    }

    /**
     * 获取邀请的店铺列表
     *
     * @ApiSummary  (获取当前用户邀请的店铺列表及升级状态)
     * @ApiMethod   (GET)
     * @param int $page 页码
     * @param int $limit 每页数量
     */
    public function invitedShops()
    {
        $userId = $this->auth->id;
        $page = (int)$this->request->get('page', 1);
        $limit = (int)$this->request->get('limit', 10);
        $page = $page > 0 ? $page : 1;
        $limit = $limit > 0 ? $limit : 10;

        try {
            // 查询邀请的店铺列表（简化版：只关联升级记录）
            $list = Db::name('wanlshop_shop')
                ->alias('s')
                ->join('shop_invite_upgrade_log u', 'u.shop_id = s.id AND u.user_id = ' . $userId, 'LEFT')
                ->where('s.inviter_id', $userId)
                ->field('s.id, s.shopname, s.avatar, s.city, s.createtime as bind_time,
                         u.after_level as upgrade_level, u.createtime as upgrade_time,
                         CASE WHEN u.id IS NOT NULL THEN 1 ELSE 0 END as is_upgraded')
                ->order('s.createtime DESC')
                ->page($page, $limit)
                ->select();

            $total = Db::name('wanlshop_shop')
                ->where('inviter_id', $userId)
                ->count();

            // 格式化返回数据
            $formattedList = [];
            foreach ($list as $shop) {
                $formattedList[] = [
                    'id' => (int)$shop['id'],
                    'shopname' => $shop['shopname'],
                    'avatar' => $shop['avatar'],
                    'city' => $shop['city'],
                    'bindTime' => $shop['bind_time'] ? (int)$shop['bind_time'] : null,
                    'isUpgraded' => (bool)$shop['is_upgraded'], // 是否已触发升级
                    'upgradeLevel' => $shop['upgrade_level'] ? (int)$shop['upgrade_level'] : null,
                    'upgradeTime' => $shop['upgrade_time'] ? (int)$shop['upgrade_time'] : null
                ];
            }

            $this->success('ok', [
                'list' => $formattedList,
                'total' => (int)$total,
                'page' => $page,
                'limit' => $limit
            ]);
        } catch (Exception $e) {
            Log::error('获取邀请店铺列表失败：' . $e->getMessage());
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取邀请用户列表（带返利状态）
     *
     * @ApiSummary  (获取当前用户邀请的用户列表及返利状态)
     * @ApiMethod   (GET)
     * @param int $page 页码
     * @param int $limit 每页数量
     */
    public function inviteeRebateList()
    {
        $userId = $this->auth->id;
        $page = (int)$this->request->get('page', 1);
        $limit = (int)$this->request->get('limit', 10);
        $page = $page > 0 ? $page : 1;
        $limit = $limit > 0 ? $limit : 10;

        try {
            // 查询邀请的用户列表
            $list = Db::name('user')
                ->alias('u')
                ->where('u.inviter_id', $userId)
                ->field('u.id, u.nickname, u.avatar, u.invite_bind_time')
                ->order('u.invite_bind_time DESC')
                ->page($page, $limit)
                ->select();

            $total = Db::name('user')
                ->where('inviter_id', $userId)
                ->count();

            if ($list instanceof \think\Collection) {
                $list = $list->toArray();
            }

            // 获取被邀请人ID列表
            $inviteeIds = array_column($list, 'id');

            // 获取返利待审核记录
            $pendingMap = [];
            if (!empty($inviteeIds)) {
                $pendingRecords = Db::name('user_invite_pending')
                    ->where('invitee_id', 'in', $inviteeIds)
                    ->field('invitee_id, state, rebate_amount, verify_time')
                    ->select();
                foreach ($pendingRecords as $record) {
                    $pendingMap[$record['invitee_id']] = $record;
                }
            }

            // 获取已发放返利记录
            $grantedMap = [];
            if (!empty($inviteeIds)) {
                $grantedRecords = Db::name('user_invite_rebate_log')
                    ->where('invitee_id', 'in', $inviteeIds)
                    ->field('invitee_id, rebate_amount, createtime')
                    ->select();
                foreach ($grantedRecords as $record) {
                    $grantedMap[$record['invitee_id']] = $record;
                }
            }

            // 获取核销记录（判断是否已核销）
            $verifyMap = [];
            if (!empty($inviteeIds)) {
                $verifyRecords = Db::name('wanlshop_voucher_verification')
                    ->alias('vv')
                    ->where('vv.user_id', 'in', $inviteeIds)
                    ->field('vv.user_id, MIN(vv.createtime) as first_verify_time')
                    ->group('vv.user_id')
                    ->select();
                foreach ($verifyRecords as $record) {
                    $verifyMap[$record['user_id']] = $record['first_verify_time'];
                }
            }

            // 格式化返回数据
            $formattedList = [];
            foreach ($list as $user) {
                $inviteeId = (int)$user['id'];
                $pending = isset($pendingMap[$inviteeId]) ? $pendingMap[$inviteeId] : null;
                $granted = isset($grantedMap[$inviteeId]) ? $grantedMap[$inviteeId] : null;
                $verifyTime = isset($verifyMap[$inviteeId]) ? (int)$verifyMap[$inviteeId] : null;

                // 确定返利状态
                $rebateStatus = 'none'; // 未核销
                $rebateAmount = null;

                if ($granted) {
                    $rebateStatus = 'granted'; // 已发放
                    $rebateAmount = (float)$granted['rebate_amount'];
                } elseif ($pending) {
                    if ($pending['state'] == 0) {
                        $rebateStatus = 'pending'; // 待发放
                        $rebateAmount = (float)$pending['rebate_amount'];
                    } elseif ($pending['state'] == 2) {
                        $rebateStatus = 'cancelled'; // 已取消
                    }
                }

                $formattedList[] = [
                    'userId' => $inviteeId,
                    'nickname' => $user['nickname'],
                    'avatar' => $user['avatar'],
                    'bindTime' => $user['invite_bind_time'] ? (int)$user['invite_bind_time'] : null,
                    'verifyTime' => $verifyTime,
                    'rebateStatus' => $rebateStatus,
                    'rebateAmount' => $rebateAmount
                ];
            }

            $this->success('ok', [
                'list' => $formattedList,
                'total' => (int)$total,
                'page' => $page,
                'limit' => $limit
            ]);
        } catch (Exception $e) {
            Log::error('获取邀请用户返利列表失败：' . $e->getMessage());
            $this->error($e->getMessage());
        }
    }
}
