{__NOLAYOUT__}<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>{:__('Warning')}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="__CDN__/assets/img/favicon.ico" />
    <style type="text/css">
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "微软雅黑", sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f5f7fa;
            padding: 20px;
        }
        
        .system-message {
            position: relative;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            padding: 60px 50px;
            max-width: 500px;
            width: 100%;
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
        
        .system-message .image {
            margin-bottom: 30px;
        }
        
        .system-message .image img {
            width: 100px;
            height: 100px;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.1));
        }
        
        .system-message h1 {
            font-size: 24px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 20px;
            line-height: 1.4;
        }
        
        .system-message .jump {
            font-size: 14px;
            color: #8492a6;
            margin: 25px 0;
            line-height: 1.6;
        }
        
        .system-message .jump #wait {
            display: inline-block;
            color: #667eea;
            font-weight: 600;
            min-width: 20px;
        }
        
        .button-group {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 35px;
        }
        
        .btn {
            flex: 1;
            max-width: 160px;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            outline: none;
        }
        
        .btn-grey {
            background: #f7f8fa;
            color: #8492a6;
        }
        
        .btn-grey:hover {
            background: #e4e7ed;
            color: #606266;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:hover {
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
            transform: translateY(-2px);
        }
        
        .success h1 { color: #52c41a; }
        .error h1 { color: #ff4d4f; }
        .info h1 { color: #1890ff; }
        
        @media (max-width: 576px) {
            body {
                padding: 15px;
            }
            
            .system-message {
                padding: 40px 30px;
            }
            
            .system-message h1 {
                font-size: 20px;
            }
            
            .button-group {
                flex-direction: column;
                gap: 10px;
            }
            
            .btn {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
{php}$codeText=$code == 1 ? 'success' : ($code == 0 ? 'error' : 'info');{/php}
<div class="system-message {$codeText}">
    <div class="image">
        <img src="__CDN__/assets/img/{$codeText}.svg" alt="" width="100" />
    </div>
    <h1>{$msg}</h1>
    {if $url}
        <p class="jump">
            {:__('This page will be re-directed in %s seconds', '<span id="wait">' . $wait . '</span>')}
        </p>
    {/if}
    <div class="button-group">
        <a href="__PUBLIC__" class="btn btn-grey">{:__('Go back')}</a>
        {if $url}
            <a id="href" href="{$url|htmlentities}" class="btn btn-primary">{:__('Jump now')}</a>
        {/if}
    </div>
</div>
{if $url}
    <script type="text/javascript">
        (function () {
            var wait = document.getElementById('wait'),
                href = document.getElementById('href').href;
            var interval = setInterval(function () {
                var time = --wait.innerHTML;
                if (time <= 0) {
                    location.href = href;
                    clearInterval(interval);
                }
            }, 1000);
        })();
    </script>
{/if}
</body>
</html>