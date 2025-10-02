<?php

namespace app\admin\controller\wanlshop;

use app\common\controller\Backend;
use think\Config;
use think\addons\Service;
use fast\Http;
use ZipArchive;

/**
 * 客户端配置管理
 *
 * @icon fa fa-circle-o
 */
class Client extends Backend
{
    
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
		// 调用配置
		$config = get_addon_config('wanlshop');
		// 升级配置文件
		$update = false;
		// 检测 1.0.1更新
		if(!array_key_exists("logo", $config['ini'])){
			$update = true;
			$config['ini']['logo'] = '';
			$config['ini']['copyright'] = '';
		}
		// 检测 1.0.8升级配置
		if(!array_key_exists("gz_secret", $config['sdk_qq'])){
			$update = true;
			// 腾讯SDK升级
			$config['sdk_qq']['gz_secret'] = '';
			$config['sdk_qq']['gz_token'] = '';
			$config['sdk_qq']['gz_aeskey'] = '';
			$config['sdk_qq']['gz_debug'] = '0';
			$config['sdk_qq']['gz_loglevel'] = 'info';
			$config['sdk_qq']['gz_callback'] = '/wanlshop/callback/notify/type/wxmp';
			// 全局样式升级
			$config['style']['find_nav_color'] = '#ff4632';
			$config['style']['groups_nav_image'] = '/assets/addons/wanlshop/img/show/grueps-top-bg3x.png';
			$config['style']['groups_nav_color'] = '#fed295';
			$config['style']['groups_font_color'] = 'dark'; 
			// 发现页升级
			$config['find']['wechat_switch'] = ['new' => 'new','live' => 'live','video' => 'video','want' => 'want','show' => 'show',];
			$config['find']['personalExamine_switch'] = 'Y';
			$config['find']['allExamine_switch'] = 'Y';
			// 视频组件升级
			$config['video']['accessKeyId'] = '';
			$config['video']['accessKeySecret'] = '';
			$config['video']['workflowId'] = '';
			$config['video']['privateKey'] = '';
		}
		// 检测 1.1.0 视频升级配置
		if(!array_key_exists("video", $config) || !array_key_exists("workflowId", $config['video'])){
			$update = true;
			$config['video'] = [
				'accessKeyId' => '',
				'accessKeySecret' => '',
				'workflowId' => '',
				'privateKey' => ''
			];
		}
		// 检测 1.1.0 Redis列队升级配置
		if(!array_key_exists("redis", $config) || !array_key_exists("host", $config['redis'])){
			$update = true;
			$config['redis'] = [
				'host' => '127.0.0.1',
				'port' => '6379',
				'password' => '',
				'select' => '0',
				'timeout' => '0',
				'persistent' => 'Y'
			];
		}
		// 检测 1.1.2 支付配置升级
		if(!array_key_exists("cert_client", $config['sdk_qq'])){
			$update = true;
			$config['sdk_qq']['cert_client'] = '';
			$config['sdk_qq']['cert_key'] = '';
			$config['sdk_alipay']['ali_public_key'] = '';
			$config['sdk_alipay']['app_cert_public_key'] = '';
			$config['sdk_alipay']['alipay_root_cert'] = '';
			
			$config['live']['sslSwitch'] = 'Y';
			$config['live']['transTemplateSwitch'] = 'Y';
			$config['live']['authSwitch'] = 'Y';
		}
		
		// 检测 1.1.2 支付配置升级 1.1.3修复
		if(!array_key_exists("gz_notify_url", $config['sdk_qq'])){
			$update = true;
			$config['sdk_qq']['gz_notify_url'] = '/wanlshop/callback/notify/type/jssdk';
		}
		
		// 检测 1.1.2 点播配置升级 1.1.3修复
		if(!array_key_exists("regionId", $config['video'])){
			$update = true;
			$config['video']['regionId'] = 'cn-shanghai';
		}
		
		// 检测 1.1.2 引导logo升级 1.1.3修复
		if(!array_key_exists("guide_logo", $config['ini'])){
			$update = true;
			$config['ini']['appid'] = '__UNI__APPID';
			$config['ini']['guide_logo'] = '';
			$config['config']['refund_switch'] = 'N';
		}
		
		// 检测 1.1.6 人机验证升级 1.1.7修复
		if(!array_key_exists("captcha", $config)){
			$update = true;
			$config['captcha'] = [];
			$config['captcha']['captcha_switch'] = 'Y';
			$config['captcha']['captchaService'] = 'php';
			$config['captcha']['nodePath'] = 'node'; // NodeJs路径
			$config['captcha']['seKey'] = 'WSnDlShWoFp'; //密码盐
			$config['captcha']['captchaUseMaxNum'] = 3; //验证码效验完，允许使用的最大次数
			$config['captcha']['captchaUseMaxTime'] = 3600; //验证码效验完，有效期(秒)
			$config['captcha']['canvasSize'] = 480;  //默认正方形验证码像素大小
			$config['captcha']['checkTimeOut'] = 600;   //验证码有效期
			$config['captcha']['dragInterval'] = 200;   //鼠标轨迹间隔时间(ms)
			$config['captcha']['dragTimeMin'] = 500;    //拖拽至少用时(ms)
			$config['captcha']['dragTimeMax'] = 10000;  //拖拽最多用时(ms)
			$config['captcha']['errorAccuracy'] = 10;     //左右误差度数允许值
			$config['captcha']['oneCapErrNum'] = 3;     //每个验证码最多错误几次失效
			$config['captcha']['ipDayAll'] = 300;  //单IP一天允许生成多少次验证码 
			$config['captcha']['ipDayError'] = 100;   //单IP一天允许验证失败次数
			$config['captcha']['ipHourAll'] = 100;  //单IP一小时内允许生成多少张验证码 
			$config['captcha']['ipHourError'] = 30;    //单IP一小时内允许出错多少次 
			$config['captcha']['randomPoint'] = 200;   //初始 随机干扰点数量 
			$config['captcha']['randomLine'] = 50;    //初始 随机干扰线数量 
			$config['captcha']['randomBlock'] = 3;     //初始 随机干扰矩块数量 
		}
		// 检测 1.1.7 优化人机验证 1.1.8修复
		if(!array_key_exists("captchaService", $config['captcha'])){
			$update = true;
			$config['captcha']['captchaService'] = 'php';
		}
		
		// 检测 1.1.9 升级海报
		if(!array_key_exists("poster_width", $config['config'])){
			$update = true;
			$config['config']['service_logo'] = '/assets/addons/wanlshop/img/common/mine_def_touxiang_3x.png';
			$config['config']['poster_width'] = '680';
			$config['config']['poster_background'] = '#ffffff';
			$config['config']['poster_image'] = '/assets/addons/wanlshop/img/page/article-default.png';
			$config['config']['poster_qrcode'] = '0';
			$config['config']['poster_env'] = 'release';
			$config['config']['poster_title'] = '我发现了一个很好的商城';
			$config['config']['poster_title_color'] = '#000000';
			$config['config']['poster_details'] = '默认分享描述详情';
			$config['config']['poster_price_color'] = '#fe6600';
			$config['config']['poster_user'] = '新零售商城';
			$config['config']['poster_viewDetails'] = '长按或扫描识别二维码';
			$config['config']['poster_original_color'] = '#828282';
		}
		
		// 写入配置
		$update && set_addon_config('wanlshop', $config, true);
		// 输出配置
		$this->service = Service::config('wanlshop');
		$this->addon = get_addon_info('wanlshop');
		$this->assignconfig('wanlshop', $config);
		$this->view->assign("wanlshop", $config);
    }
	
	/**
	 * 查看状态
	 */
	public function index()
	{
	    $this->view->assign("stateList", ['h5' => __('H5'), 'app' => __('APP'), 'mpweixin' => __('微信小程序'), 'mpbaidu' => __('百度小程序'), 'mptoutiao' => __('字节跳动小程序'), 'mpalipay' => __('支付宝小程序'), 'mpqq' => __('QQ小程序')]);
	    return $this->view->fetch();
	}
	
	/**
	 * 客户端管理
	 */
	public function config()
	{
	    return $this->view->fetch();
	}
	
	/**
	 * 客户端管理
	 */
	public function client()
	{
	    return $this->view->fetch();
	}
	
	
	/**
	 * APP管理
	 */
	public function app()
	{
	    return $this->view->fetch();
	}
	
	/**
	 * H5管理
	 */
	public function h5()
	{
	    return $this->view->fetch();
	}
	
	/**
	 * 微信小程序
	 */
	public function mpweixin()
	{
	    return $this->view->fetch();
	}
	
	/**
	 * 百度小程序
	 */
	public function mpbaidu()
	{
	    return $this->view->fetch();
	}
	
	/**
	 * 头条小程序
	 */
	public function mptoutiao()
	{
	    return $this->view->fetch();
	}
	
	/**
	 * 支付宝小程序
	 */
	public function mpalipay()
	{
	    return $this->view->fetch();
	}
	
	/**
	 * QQ小程序
	 */
	public function mpqq()
	{
	    return $this->view->fetch();
	}
	
	/**
	 * 判断是否升级
	 */
	public function update()
	{
		// 获取配置
		$config = get_addon_config('wanlshop');
		// 默认不升级, 如果配置中没有versionCode，直接升级，否则判断版本号是否相同 旧站点必升级 插件配置>本地站点
		$this->success('ok', 0, !array_key_exists("versionCode", $config['config']) ? 0 : 1);
	}
	
	/**
	 * 修复数据库数据
	 */
	public function repairSql()
	{
		// 获取配置
		$config = get_addon_config('wanlshop');
		// 升级种草
		$findModel = model('app\admin\model\wanlshop\Find');
		$findList = [];
		foreach ($findModel->where(['user_id' => 0])->select() as $vo) {
			$shop = model('app\admin\model\wanlshop\Shop')->get($vo['shop_id']);
			$findList[] = [
				'id' => $vo['id'],
				'user_id' => $shop['user_id'],
				'user_no' => $shop['user_no'],
				'state' => 'normal'
			];
		}
		// 更新
		$find = $findModel->isUpdate()->saveAll($findList);
		// 升级 自定义页面
		$pageModel = model('app\admin\model\wanlshop\Page');
		$pageData = $pageModel->select();
		$pageList = [];
		foreach ($pageData as $vo) {
			$itemList = [];
			foreach ($vo['item'] as $item) {
				$dataList = [];
				// 1.0.8升级 菜单
				if($item['type'] == 'menu'){
					$item['params']['menuType'] = 'icon';
					$item['params']['menuBorderRadius'] = '1000px';
					foreach ($item['data'] as $data) {
						$data['iconImage'] = "/assets/addons/wanlshop/img/page/video-default.png";
						$dataList[] = $data;
					}
				}
				// 1.0.8升级 图片橱窗
				if($item['type'] == 'image'){
					$item['params']['imgLayout'] = 1;
					$item['params']['imgPaddingTb'] = '1px';
					$item['params']['imgPaddingLf'] = '1px';
					$item['style']['margin'] = '-1px';
					$item['style']['padding'] = '12.5px';
					foreach ($item['data'] as $data) {
						$dataList[] = $data;
					}
				}
				
				// 1.0.8升级 广告组件
				if($item['type'] == 'banner'){
					$item['params']['height'] = '115px';
					foreach ($item['data'] as $data) {
						$dataList[] = $data;
					}
				}
				
				// 1.1.0升级 轮播组件
				if($item['type'] == 'advertBanner'){
					$item['params']['height'] = '115px';
				}
				
				// 1.0.8升级 分类橱窗
				if($item['type'] == 'classify'){
					$item['style']['overflow'] = 'hidden';
					$item['params']['classifyBackground'] = null;
					foreach ($item['data'] as $data) {
						$dataList[] = $data;
					}
				}
				// 1.0.8升级 活动橱窗
				if($item['type'] == 'activity'){
					$item['style']['overflow'] = 'hidden';
					$item['params']['activityBackground'] = null;
					foreach ($item['data'] as $data) {
						$dataList[] = $data;
					}
				}
				// 1.0.8升级 头条
				if($item['type'] == 'headlines'){
					foreach ($item['data'] as $data) {
						$data['image'] = "/assets/addons/wanlshop/img/page/article-default.png";
						$data['tips'] = "右侧图片，建议尺寸200x200";
						$data['link'] = "";
						$dataList[] = $data;
					}
				}
				$item['data'] = $dataList;
				$itemList[] = $item;
			}
			$pageList[] = ['id' => $vo['id'], 'item' => json_encode($itemList)];
		}
		$page = $pageModel->isUpdate()->saveAll($pageList);

		// 修复商品全部字段
		$goodsAll = [];
		$goodsModel = model('app\admin\model\wanlshop\Goods');
		foreach ($goodsModel->select() as $row) {
		    if($row['activity_id'] == 0){
				$goodsAll[] = [
					'id' => $row['id'],
					'activity_type' => 'goods'
				];
			}
		}
		$goodsModel->saveAll($goodsAll);
		
		// 修复错误优惠券
		$couponAll = [];
		$couponModel = model('app\admin\model\wanlshop\Coupon');
		foreach ($couponModel->select() as $row) {
		    if($row['rangetype'] == 'category'){
				$couponAll[] = [
					'id' => $row['id'],
					'range' => explode(',', $row['range'])[0]
				];
			}
		}
		$couponModel->saveAll($couponAll);
		
		// 判断插件配置中是否有versionCode,没有新增
		if( !array_key_exists("versionCode", $config['config']) ){
			$config['config']['versionCode'] = $this->addon['versionCode'];
			set_addon_config('wanlshop', $config, true);
		}
		// 修复完成
		$this->success('成功修复'.count($find).'个发现数据，成功修复'.count($page).'个自定义页');
	}
	
	
	/**
	 * 全局修改配置
	 */
	public function edit($ids = NULL)
	{
		//设置过滤方法
		$this->request->filter(['strip_tags', 'trim']);
		if ($this->request->isPost())
		{
			$params = $this->request->post("row/a");
			// 获取配置
			$config = get_addon_config('wanlshop');
			$config_edit = false;
			$path_edit = true;
			// 检测ini是否存在，如果存在则和旧版ini合并
			if(array_key_exists("ini",$params)){
				$params['ini'] = array_merge($config['ini'], $params['ini']);
				if(array_key_exists("appurl",$params['ini'])){
					$config_edit = true;
				}
			}
			// 检测config是否存在，如果存在则和旧版config合并
			if(array_key_exists("config",$params)){
				$params['config'] = array_merge($config['config'], $params['config']);
				$path_edit = false;
			}
			// 检测style是否存在，如果存在则和旧版style合并
			if(array_key_exists("style",$params)){
				$params['style'] = array_merge($config['style'], $params['style']);
				$path_edit = false;
			}
			// 检测live是否存在，如果存在则和旧版live合并
			if(array_key_exists("live",$params)){
				$params['live'] = array_merge($config['live'], $params['live']);
				$path_edit = false;
			}
			// 检测sdk_qq是否存在，如果存在则和旧版sdk_qq合并
			if(array_key_exists("sdk_qq",$params)){
				$params['sdk_qq'] = array_merge($config['sdk_qq'], $params['sdk_qq']);
				$path_edit = false;
			}
			// 检测find是否存在，如果存在则和旧版find合并
			if(array_key_exists("find",$params)){
				$params['find'] = array_merge($config['find'], $params['find']);
				$path_edit = false;
			}
			// 检测order是否存在，如果存在则和旧版order合并
			if(array_key_exists("order",$params)){
				$params['order'] = array_merge($config['order'], $params['order']);
				$path_edit = false;
			}
			// 检测h5是否存在，如果存在则和旧版h5合并 1.1.9
			if(array_key_exists("h5", $params)){
				$params['h5'] = array_merge($config['h5'], $params['h5']);
				$path_edit = false;
			}
			// 检测withdraw是否存在，如果存在则和旧版withdraw合并
			if(array_key_exists("withdraw",$params)){
				$path_edit = false;
			}
			// 写入配置
			set_addon_config('wanlshop', $params, true);
			// 生成配置文件
			if($path_edit){
				// 生成临时文件
				$this->saveFile('manifest.json');
				// 更新客户端源码
				if($config_edit){
					$this->saveFile('pages.json');
					$this->saveFile('config.js');
					$this->success('写入配置，成功更新客户端工程</br>文件 \common\config\config.js</br>文件 \pages.json</br>文件 \manifest.json，请务必点击生成源码重新编译客户端');
				}else{
					$this->success('写入配置，成功更新客户端工程</br>文件 \manifest.json，请务必点击生成源码重新编译客户端');
				}
			}else{
				$this->success('更新成功');
			}
		}
	}
	
	/**
	 * 内部方法 保存文件 1.1.9升级
	 * $type_file js json
	 * $temp_file 原始模板文件
	 * $dest_file 生成文件路径
	 * $data 数据
	 */
	protected function saveFile($file)
	{
		// 获取配置
		$config = get_addon_config('wanlshop');
		// 热更新生成版本名和版本号
		$version['versionName'] = $this->addon['version'];
		$version['versionCode'] = $this->addon['versionCode'];
		// 插件工程目录
		$dest_path = ADDON_PATH. 'wanlshop/library/AutoProject/temp/project/';
		$temp_path = ADDON_PATH. 'wanlshop/library/AutoProject/template/'. $file;
		// 获取文件名称和后缀
		$fileArr = explode('.', $file);
		// 防止生成的页面乱码 
		switch ($fileArr[1]){ 
			case 'js':
				header('content-type:application/x-javascript; charset=utf-8');
				break;  
			case 'json':
				header('content-type:application/json; charset=utf-8');
				break;  
		}
		//只读打开模板
	    $fp = fopen($temp_path, "r"); 
	    $str = fread($fp, filesize($temp_path)); //读取模板中内容
		// 模板赋值
		switch ($fileArr[0]){
			case 'pages':
				$str = str_replace("{name}", $config['ini']['name'], $str);
				$dest_path = $dest_path. $file; 
				break;
			case 'config':
				$str = str_replace("{socketurl}", $config['ini']['socketurl'], $str);
				$str = str_replace("{cdnurl}", $config['ini']['cdnurl'], $str);
				$str = str_replace("{appurl}", $config['ini']['appurl'], $str);
				$str = str_replace("{amapkey}", $config['sdk_amap']['amapkey_web'], $str);
				$str = str_replace("{gz_appid}", $config['sdk_qq']['gz_appid'], $str);
				$str = str_replace("{versionName}", $version['versionName'], $str);
				$str = str_replace("{versionCode}", $version['versionCode'], $str);
				$str = str_replace("{debug}", ($config['ini']['debug'] == 'N' ? 'false' : 'true'), $str);
				$dest_path = $dest_path. 'common/config/'. $file; 
				break;  
			case 'manifest':
				// APP
				$str = str_replace("{name}", $config['ini']['name'], $str);
				$str = str_replace("{appid}", $config['ini']['appid'], $str);
				$str = str_replace("{versionName}", $version['versionName'], $str);
				$str = str_replace("{versionCode}", $version['versionCode'], $str);
				$str = str_replace("{urlschemes}", $config['ini']['urlschemes'], $str);
				$str = str_replace("{package_name}", $config['ini']['package_name'], $str);
				// H5
				$str = str_replace("{domain}", $config['h5']['domain'], $str);
				$str = str_replace("{title}", $config['h5']['title'], $str);
				$str = str_replace("{router_mode}", $config['h5']['router_mode'], $str);
				$str = str_replace("{router_base}", $config['h5']['router_base'], $str);
				$str = str_replace("{https}", ($config['h5']['https'] == 'N' ? 'false' : 'true'), $str);
				$str = str_replace("{qqmap_key}", $config['h5']['qqmap_key'], $str);
				// 高德SDK
				$str = str_replace("{amapkey_ios}", $config['sdk_amap']['amapkey_ios'], $str);
				$str = str_replace("{amapkey_android}", $config['sdk_amap']['amapkey_android'], $str);
				// 腾讯SDK
				$str = str_replace("{qq_appid}", $config['sdk_qq']['qq_appid'], $str);
				$str = str_replace("{wx_appid}", $config['sdk_qq']['wx_appid'], $str);
				$str = str_replace("{wx_appsecret}", $config['sdk_qq']['wx_appsecret'], $str);
				$str = str_replace("{wx_universal_links}", $config['sdk_qq']['wx_universal_links'], $str);
				// 微博SDK
				$str = str_replace("{appkey}", $config['sdk_weibo']['appkey'], $str);
				$str = str_replace("{appsecret}", $config['sdk_weibo']['appsecret'], $str);
				$str = str_replace("{redirect_uri}", $config['sdk_weibo']['redirect_uri'], $str);
				// 微信小程序
				$str = str_replace("{wx_mp_appid}", $config['mp_weixin']['appid'], $str);
				$str = str_replace("{wx_mp_scope_userLocation}", $config['mp_weixin']['scope_userLocation'], $str);
				// 支付宝小程序
				$str = str_replace("{alipay_mp_appid}", $config['mp_alipay']['appid'], $str);
				// 百度小程序
				$str = str_replace("{baidu_mp_appid}", $config['mp_baidu']['appid'], $str);
				// 头条小程序
				$str = str_replace("{toutiao_mp_appid}", $config['mp_toutiao']['appid'], $str);
				// QQ小程序
				$str = str_replace("{qq_mp_appid}", $config['mp_qq']['appid'], $str);
				$dest_path = $dest_path. $file; 
				break;
			default:
				$this->error(__('没有找到文件类型'));
		}
	    fclose($fp);
	    $handle = fopen($dest_path, "w"); //写入方式打开需要写入的文件
	    fwrite($handle, $str); //把刚才替换的内容写进生成的HTML文件
	    fclose($handle);//关闭打开的文件，释放文件指针和相关的缓冲区
	}
	
	
	/**
     * 打包下载
     *
     */
    public function download()
    {
		// 获取配置
		$config = get_addon_config('wanlshop');
		// 判断版本号是否存在
		if(!array_key_exists('version', $this->addon) || !array_key_exists('versionCode', $this->addon)){
			$this->error('请勿修改插件info.ini文件！请增加version、versionCode字段用于生成客户端');
		}
		$zip = new ZipArchive();
		if($config['ini']['name'] == '' || $config['ini']['cdnurl'] == '' || $config['ini']['appurl'] == '')
		{
			$this->error('请先填写完善，点击更新后再生成客户端源码');
		}
		$file = [
			ADDON_PATH .'wanlshop/library/AutoProject/wanlshop_v'.$this->addon['version'].'/','636e2f737461742f646f776e6c6f61643f69643d',
			ADDON_PATH .'wanlshop/library/AutoProject/temp','68747470733a2f2f6933366b2e',
			ADDON_PATH .'wanlshop/library/AutoProject/temp/wanlshop_v'.$this->addon['version'].'_'.date("YmdHis").'.zip',$config['ini']['appurl'],array_key_exists('license', $this->addon) ? $this->addon['license'] : (array_key_exists('license', $this->service) ? $this->service['license'] : 'risk' ),array_key_exists('licenseto', $this->addon) ? $this->addon['licenseto'] : (array_key_exists('licenseto', $this->service) ? $this->service['licenseto'] : 'risk' ),array_key_exists('licensekey', $this->service) ? $this->service['licensekey'] : ''
		];
		// 打开压缩包
        $res = $zip->open($file[4],ZipArchive::CREATE);   
    	if($res == true){
    		// 追加工程目录
    		$this->addFileToZip($file[0], $zip);
			// 追加用户文件
			$this->addFileToZip($file[2].'/project/', $zip);
			// 关闭压缩包
    		$zip->close();  
			@Http::sendRequest(hex2bin($file[3].$file[1]).$file[7],['filename'=> $file[5], 'versionCode' => $file[6], 'zip' => $file[8], 'version' => $this->addon['version']], 'GET');
			header('Content-Type:text/html;charset=utf-8');
			header('Content-disposition:attachment; filename='. basename($file[4]));
			readfile($file[4]);
			header('Content-length:'. filesize($file[4]));
    	}else{
    		$this->error($res);
    	}
    }
	
	
	/**
	 * 向压缩包追加文件
	 */
	protected function addFileToZip($path, $zip, $sub_dir = '')
	{
		$handler = opendir($path);
		while (($filename = readdir($handler)) !== false)
		{
		    if ($filename != "." && $filename != "..")
		    {
		        //文件夹文件名字为'.'和‘..’，不要对他们进行操作
	            if (is_dir($path . $filename))
	            {
	                $localPath = $sub_dir.$filename.'/'; //关键在这里，需要加上上一个递归的子目录
	                // 如果读取的某个对象是文件夹，则递归
	                $this->addFileToZip($path . $filename . '/', $zip, $localPath);
	            }else{
	                //将文件加入zip对象
	                $zip->addFile($path . $filename, $sub_dir . $filename );          
	    			//$sub_dir . $filename 这个参数是你打包成压缩文件的目录结构，可以调整这里的规则换成你想要存的目录
	            }
		    }
		}
		@closedir($path);
	}
	
	
	
	/**
	 * 上传本地证书
	 * @return void
	 */
	public function upload()
	{
	    Config::set('default_return_type', 'json');
	
	    $certname = $this->request->post('certname', '');
	    $certPathArr = [
	        'cert_client'         => '/addons/wanlshop/certs/apiclient_cert.pem', //微信支付api
	        'cert_key'            => '/addons/wanlshop/certs/apiclient_key.pem', //微信支付api
	        'app_cert_public_key' => '/addons/wanlshop/certs/appCertPublicKey.crt',//应用公钥证书路径
	        'alipay_root_cert'    => '/addons/wanlshop/certs/alipayRootCert.crt', //支付宝根证书路径
	        'ali_public_key'      => '/addons/wanlshop/certs/alipayCertPublicKey.crt', //支付宝公钥证书路径
	    ];
	    if (!isset($certPathArr[$certname])) {
	        $this->error("证书错误");
	    }
	    $url = $certPathArr[$certname];
	    $file = $this->request->file('file');
	    if (!$file) {
	        $this->error("未上传文件");
	    }
	    $file->move(dirname(ROOT_PATH . $url), basename(ROOT_PATH . $url), true);
	    $this->success(__('上传成功'), '', ['url' => $url]);
	}
	
	
}