<?php
$cdnurl = function_exists('config') ? config('view_replace_str.__CDN__') : '';
$publicurl = function_exists('config') ? (config('view_replace_str.__PUBLIC__')?:'/') : '/';
$debug = function_exists('config') ? config('app_debug') : false;

$lang = [
    'An error occurred' => '发生错误',
    'Home' => '返回主页',
    'Previous Page' => '返回上一页',
    'The page you are looking for is temporarily unavailable' => '你所浏览的页面暂时无法访问',
    'You can return to the previous page and try again' => '你可以返回上一页重试'
];

$langSet = '';

if (isset($_GET['lang'])) {
    $langSet = strtolower($_GET['lang']);
} elseif (isset($_COOKIE['think_var'])) {
    $langSet = strtolower($_COOKIE['think_var']);
} elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
preg_match('/^([a-z\d\-]+)/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $matches);
    $langSet     = strtolower($matches[1] ?? '');
}
$langSet = $langSet && in_array($langSet, ['zh-cn', 'en']) ? $langSet : 'zh-cn';
$langSet == 'en' && $lang = array_combine(array_keys($lang), array_keys($lang));

?>
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html;charset=UTF-8">
    <title><?=$lang['An error occurred']?></title>
    <meta name="robots" content="noindex,nofollow" />
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
    <link rel="shortcut icon" href="<?php echo $cdnurl;?>/assets/img/favicon.ico" />
    <style>
        * {
            margin: 0;
            padding: 0;
            border: 0;
            outline: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "微软雅黑", sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        html, body {
            width: 100%;
            height: 100%;
        }
        
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f5f7fa;
            padding: 20px;
            position: relative;
            overflow: auto;
        }
        
        .error-page-wrapper {
            width: 100%;
            max-width: 600px;
            position: relative;
        }
        
        .content-container {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            padding: 60px 50px;
            text-align: center;
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .head-line {
            margin-bottom: 30px;
        }
        
        .head-line img {
            width: 100px;
            height: 100px;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.1));
        }
        
        .subheader {
            font-size: 24px;
            font-weight: 600;
            color: #2c3e50;
            line-height: 1.5;
            margin-bottom: 25px;
            word-wrap: break-word;
        }
        
        .hr {
            height: 1px;
            background: linear-gradient(90deg, transparent, #e4e7ed 20%, #e4e7ed 80%, transparent);
            margin: 30px 0;
            border: none;
        }
        
        .context {
            font-size: 15px;
            line-height: 1.8;
            color: #8492a6;
            margin: 20px 0;
        }
        
        .context p {
            margin: 12px 0;
        }
        
        .buttons-container {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 40px;
        }
        
        .buttons-container a {
            flex: 1;
            max-width: 180px;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
            display: inline-block;
        }
        
        .buttons-container a:nth-child(1) {
            background: #f7f8fa;
            color: #8492a6;
        }
        
        .buttons-container a:nth-child(1):hover {
            background: #e4e7ed;
            color: #606266;
        }
        
        .buttons-container a:nth-child(2) {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .buttons-container a:nth-child(2):hover {
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
            transform: translateY(-2px);
        }
        
        @media screen and (max-width: 640px) {
            body {
                padding: 15px;
            }
            
            .content-container {
                padding: 40px 30px;
            }
            
            .subheader {
                font-size: 20px;
            }
            
            .buttons-container {
                flex-direction: column;
                gap: 12px;
            }
            
            .buttons-container a {
                max-width: 100%;
            }
        }
        
        @media screen and (max-width: 480px) {
            .content-container {
                padding: 35px 25px;
            }
            
            .head-line img {
                width: 80px;
                height: 80px;
            }
            
            .subheader {
                font-size: 18px;
            }
            
            .context {
                font-size: 14px;
            }
        }
    </style>
</head>
<body class="error-page-wrapper">
<div class="content-container">
    <div class="head-line">
        <img src="<?=$cdnurl?>/assets/img/error.svg" alt="" width="120"/>
    </div>
    <div class="subheader">
        <?=$debug?$message:$lang['The page you are looking for is temporarily unavailable']?>
    </div>
    <div class="hr"></div>
    <div class="context">

        <p>
            <?=$lang['You can return to the previous page and try again']?>
        </p>

    </div>
    <div class="buttons-container">
        <a href="<?=$publicurl?>"><?=$lang['Home']?></a>
        <a href="javascript:" onclick="history.go(-1)"><?=$lang['Previous Page']?></a>
    </div>
</div>
</body>
</html>
