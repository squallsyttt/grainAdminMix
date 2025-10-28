<?php

/**
 * 微信小程序配置
 * 
 * 使用说明：
 * 1. 前往微信公众平台 https://mp.weixin.qq.com/ 注册小程序
 * 2. 在【开发】->【开发管理】->【开发设置】中获取 AppID 和 AppSecret
 * 3. 将获取到的 AppID 和 AppSecret 填写到下面的配置中
 * 
 * 安全提示：
 * - AppSecret 是敏感信息，请妥善保管
 * - 生产环境建议使用环境变量或独立配置文件
 * - 不要将此配置文件提交到公开的代码仓库
 */

return [
    // 小程序 AppID
    'app_id' => \think\Env::get('wechat_miniprogram.app_id', ''),

    // 小程序 AppSecret
    'app_secret' => \think\Env::get('wechat_miniprogram.app_secret', ''),

    // session_key 有效期（秒）默认90天
    'session_expire' => 7776000,
];
