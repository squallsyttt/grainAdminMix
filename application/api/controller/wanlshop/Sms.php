<?php

namespace app\api\controller\wanlshop;

use addons\wanlshop\library\WanlSdk\Captcha;

use app\common\controller\Api;
use app\common\library\Sms as Smslib;
use app\common\model\User;
use think\Hook;
use think\Exception;

/**
 * 手机短信接口 
 */
class Sms extends Api
{
    protected $noNeedLogin = ['*'];
    protected $noNeedRight = ['*'];
    
	public function _initialize()
	{
		//跨域检测
		check_cors_request();
		// 验证码
	    $this->captcha = new Captcha;
		// 后续1.2.0使用更安全AES传输，Sign下架防君子不防小人没太大意义
		parent::_initialize();
	}
	
    /**
     * 发送验证码
     *
     * @param string $mobile 手机号
     * @param string $event 事件名称
     */
    public function send()
    {
		if ($this->request->isPost()) 
		{
			$mobile = $this->request->post("mobile");
			$event = $this->request->post("event");
			$event = $event ? $event : 'register';
			if (!$mobile || !\think\Validate::regex($mobile, "^1\d{10}$")) {
			    $this->error(__('手机号不正确'));
			}
			$last = Smslib::get($mobile, $event);
			if ($last && time() - $last['createtime'] < 60) {
			    $this->error(__('发送频繁'));
			}
			$ipSendTotal = \app\common\model\Sms::where(['ip' => $this->request->ip()])->whereTime('createtime', '-1 hours')->count();
			if ($ipSendTotal >= 5) {
			    $this->error(__('发送频繁'));
			}
			if ($event) {
			    $userinfo = User::getByMobile($mobile);
			    if ($event == 'register' && $userinfo) {
			        //已被注册
			        $this->error(__('已被注册'));
			    } elseif (in_array($event, ['changemobile']) && $userinfo) {
			        //被占用
			        $this->error(__('已被占用'));
			    } elseif (in_array($event, ['changepwd', 'resetpwd']) && !$userinfo) {
			        //未注册
			        $this->error(__('未注册'));
			    }
			}
			if (!Hook::get('sms_send')) {
			    $this->error(__('请在后台插件管理安装短信验证插件'));
			}
			
			// 人机验证 1.1.7升级
			if(!$this->captcha->check()){
				$this->error(__('请先进行人机效验'),'',4);
			}
			
			// 发送短信
			$ret = Smslib::send($mobile, mt_rand(100000, 999999), $event);
			if ($ret) {
			    $this->success(__('发送成功'));
			} else {
			    $this->error(__('发送失败，请检查短信配置是否正确'));
			}
		}else{
			$this->error(__('非法访问'));
		}
        
    }

    /**
     * 人机验证
     */
    public function captcha()
    {
		if ($this->request->isPost()) {
			$result = $this->captcha->image();
			if($result['code'] == 0){
				// header('Set-Cookie: ' . session_name() . '=' . session_id() . '; SameSite=None; Secure');
				$this->success($result['msg'], ['captchaSrc' => $result['data'], 'captchaCookie' => session_name() . '=' . session_id()]);
			}else{
				$this->error($result['msg'], null, $result['code']);
			}
			$this->error('生成人机效验图错误');
		}else{
			$this->error('非法访问');
		}
    	
    }
    
    /**
     * 检测人机验证
     * @param string $rotationAngle 旋转角度
     * @param string $mouseTrackList 滑动轨迹
     * @param string $dragUseTime 拖动用时
     * @param string $dragStartTime 拖动开始时间
	 * @param string $append 附加信息
     */
    public function check()
    {
		if ($this->request->isPost()) {
			$rotationAngle = $this->request->post("rotationAngle");
			$mouseTrackList = $this->request->post("mouseTrackList");
			$dragUseTime = $this->request->post("dragUseTime");
			$dragStartTime = $this->request->post("dragStartTime");
			$append = $this->request->post("append");
			$result = $this->captcha->checkCaptcha($rotationAngle, $mouseTrackList, $dragUseTime, $dragStartTime);
			if($result['code'] == 0){
				if($append){
					$append = json_decode(htmlspecialchars_decode($append), true); // 1.1.9升级
					// 发送短信
					$ret = Smslib::send($append['mobile'], mt_rand(100000, 999999), $append['event']);
					if ($ret) {
					    $this->success(__('发送成功'));
					} else {
					    $this->error(__('发送失败，请检查短信配置是否正确'));
					}
				}else{
					$this->success(__('数据异常，未获取到手机号码'));
				}
				$this->success($result['msg']);
			}else{
				$this->error($result['msg'], ['getNewCaptcha' => $result['data']], $result['code']);
			}
			$this->error('人机验证失败');
		}else{
			$this->success('非法访问');
		}
    }
}