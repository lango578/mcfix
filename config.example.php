<?php
/**
 * MC 故障反馈与自动修复系统 —— 配置样例（字段说明用，不要直接拿去部署）
 *
 * ⚠️ 正确做法：浏览器打开 `?r=install` 走安装向导，它会生成一份完整可用的
 *    `config/config.php`（含随机密钥、随机的后台入口路径）。
 *
 * 本文件只用来**查字段含义**。它的密钥是占位符、`admin_password` 是空的、
 * `admin.path` 也没填 —— 直接复制成 config/config.php 会得到一个进不去的后台。
 * 如果你确实要手工写，请务必把下面四处补齐：
 *
 *   1. `app.hmac_secret`  → 至少 64 位随机十六进制（`php -r 'echo bin2hex(random_bytes(32));'`）
 *   2. `app.admin_password` → `php -r 'echo password_hash("你的口令", PASSWORD_DEFAULT);'`
 *   3. `admin.path`       → 一段随机路径，例如 `console-` + 16 位随机字符
 *   4. `app.base_url`     → 你的真实对外地址
 *
 * 本文件是纯 PHP 数组，不要在里面写任何逻辑。
 */

return [

    'app' => [
        // 玩家反馈站的对外地址（玩家收到的反馈链接前缀），不要以 / 结尾
        'base_url'        => 'https://fankui.example.com',

        'name'            => 'MC 服务器故障反馈中心',

        // 后台登录密码，安装向导写入的是 password_hash() 结果，不要手填明文
        'admin_password'  => '',

        // 数据签名密钥：反馈链接、Agent 令牌都靠它，务必随机且保密
        'hmac_secret'     => 'CHANGE_ME_TO_A_RANDOM_STRING',

        'timezone'        => 'Asia/Shanghai',

        'debug'           => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | 两个域名的分工（推荐配置：后台独立域名）
    |--------------------------------------------------------------------------
    | 把后台放在独立域名上，比放在同域名的私有路径里更彻底：
    |   - 同源策略天然隔离，玩家侧页面在另一个源上，拿不到后台的任何东西
    |   - 后台域名可以不对外解析、可以只允许你的 IP 访问、可以单独上客户端证书
    |   - 后台的 cookie 只属于后台域名，与反馈站不可能混在一起
    |
    | feedback_host  玩家反馈站的域名（不带协议和路径）
    | console_host   管理后台的域名；留空则退回"私有路径"模式（同域名 + ?r=<path>）
    |
    | 两个域名都指向同一个站点目录即可。带 www 或其它别名时用逗号分隔，
    | 例如：'feedback_host' => 'fankui.example.com,www.fankui.example.com'
    */
    'domains' => [
        'feedback_host' => '',
        'console_host'  => '',
    ],

    // 单一 SQLite 库，零依赖；路径相对项目根目录
    // 数据库。SQLite 是默认也是唯一认真测过的驱动，路径相对项目根目录。
    // driver 可以填 mysql，但那条分支只是预留、没有充分测试，别在生产上用。
    'db' => [
        'driver' => 'sqlite',
        'path'   => 'storage/data/mcfix.sqlite',
        // 下面几项只有 driver = mysql 时才会被读到
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'mcfix',
        'username' => '',
        'password' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | 服务器列表
    |--------------------------------------------------------------------------
    | id          : 内部标识，只用小写字母数字和短横线
    | code        : 玩家侧展示的「代号」（例如 S1 / 一服）。反馈页的服务器下拉框和
    |               状态卡片只显示「code・name」，**不显示 host/port** ——
    |               地址是连接信息，公开给玩家等于公开可直连入口。
    |               留空则只显示 name，不会显示成空白。
    | name        : 玩家看到的名字
    | host/port   : 服务器连接地址。**只在后台和诊断内部使用，不向玩家展示**
    | rcon        : 游戏内远程控制台。留空或 enabled=false 则跳过所有 RCON 检查，
    |               但仍然可以做日志分析 / 进程守护 / 重启
    | executor    : 主执行器（决定"进程、重启、日志、MOD 清单"这类动作谁来做）
    |     agent   : 推荐。MC 机器上跑 agent/mcfix-agent.php，由它长轮询领取任务
    |     ssh     : 面板机器能 SSH 到 MC 机器时使用（需 php-ssh2 扩展或系统 ssh 客户端）
    |     local   : MC 和面板在同一台机器时使用
    |     none    : 只做面板能直接完成的部分（MC 协议 + RCON + 面板 API 只读）
    | agent_token : 对应 agent/mcfix-agent.php 里的 token，一台服务器一个
    | agent_ip_allow : 只允许这些来源 IP 来领任务（可选）。填了之后 MC 机器换 IP 会 403，
    |             家宽/动态 IP 不要填。支持精确 IP 或 1.2.3.* 通配。
    | require_mc_online : 关服维护期间设为 false，避免误报
    |
    | panel       : 面板 API（可选，可与上面的 executor 叠加使用）
    |     诊断读日志会优先走面板（不用装东西），发指令优先走 RCON，重启优先走 Agent。
    |     类型见 src/PanelRegistry.php：mcsmanager / pterodactyl / bt / custom
    |     endpoints 可以覆盖接口路径，用于适配不同面板版本。
    */
    'servers' => [
        [
            'id'                => 'survival',
            'code'              => 'S1',            // 玩家侧展示的代号；留空则只显示 name
            'name'              => '生存服 1.20.1',
            'host'              => 'mc.example.com',
            'port'              => 25565,
            'enabled'           => true,
            'require_mc_online' => true,
            'max_players'       => 100,

            'rcon' => [
                'enabled'  => true,
                'host'     => 'mc.example.com', // MC 机器内网 IP 也可以
                'port'     => 25575,
                'password' => '',
                'timeout'  => 3,
            ],

            'executor'    => 'agent',
            'agent_token' => 'CHANGE_ME_AGENT_TOKEN',
            // 留空 = 不限制来源 IP
            'agent_ip_allow' => [],

            /*
             * 面板 API（可选）。填了之后即使不装 Agent 也能读日志做诊断；
             * MCSManager / 翼龙 还能直接发游戏指令和开关机。
             * 后台「服务器与 Agent → 配置 → 面板 API」里可视化填写，一般不用手改这里。
             */
            'panel' => [
                'enabled'     => false,
                'type'        => 'none',        // none | mcsmanager | pterodactyl | bt | custom
                'mode'        => 'client',      // 翼龙：client(ptlc_) 或 application(ptla_)
                'api_url'     => '',            // http://127.0.0.1:23333 或 https://panel.example.com
                'api_key'     => '',
                'daemon_id'   => '',            // MCSManager 远程服务 UUID
                'instance_id' => '',            // MCSManager 实例 UUID
                'server_id'   => '',            // 翼龙服务器短 ID
                'process_id'  => '',            // 宝塔进程守护管理器项目 ID
                'mc_dir'      => '',            // 面板内的服务端目录（翼龙一般是 /home/container）
                'timeout'     => 12,
                // 默认校验证书。只有面板用自签证书、不勾就完全连不上时才设为 true
                'insecure'    => false,
                // 接口路径覆盖（不同面板版本差异时用）。后台表单里填，这里留空数组即可
                'endpoints'   => [],
            ],

            'ssh' => [
                'host'       => '10.0.0.21',
                'port'       => 22,
                'user'       => 'root',
                'private_key'=> 'storage/keys/id_rsa', // 相对项目根目录，或绝对路径
                'timeout'    => 15,
            ],

            // MC 服务端根目录（盛放 server.jar / logs / world 的那一层）
            'mc_dir'     => '/www/minecraft/survival',
            // 与 MC 目录同盘的路径，用于磁盘余量检查
            'disk_path'  => '/www/minecraft',
            // 日志文件，相对 mc_dir 或绝对路径
            'log_path'   => 'logs/latest.log',
            // MC 监听端口（可能与 host:port 不同，例如 SRV 转发场景）
            'listen_port'=> 25565,

            // 进程守护方式，决定"重启"怎么做
            'guard' => [
                'type'        => 'systemd',          // systemd | screen | tmux | panel | none
                'service'     => 'minecraft-survival', // systemd 单元名
                'session'     => 'mc-survival',        // screen/tmux 会话名
                'start_cmd'   => '',                   // 其它方式时，写死启动命令
                'stop_cmd'    => '',                   // 留空则用 RCON stop
                'restart_cmd' => '',                   // 留空则按上面方式自动拼装
                'max_restarts_per_hour' => 3,          // 风控：每小时最多自动重启次数
            ],

            /*
             * 允许自动执行的修复配方（白名单）。
             * 语义：**没出现的 code 视为允许**，显式写 false 才是禁用。
             * 所以下面只列要禁掉的那几条；想"只诊断、什么都不修"就把 11 条全写 false。
             * 全部 11 个 code 见 src/Recipe.php 或后台「服务器与 Agent」页的复选框。
             */
            'recipes' => [
                'clear_self_items' => false,
                'reload_plugins'   => false,
                'backup_world'     => false,
            ],

            // 单条修复任务超时（秒），超时自动标记失败
            'task_timeout' => 90,

            // 下面两项一般不用改，列出来是为了你排查问题时知道它们存在：
            // queue_ttl     任务在队列里最多等多久（秒），超时被 cron 回收，默认 900
            // dispatch_wait 下发给 Agent 后，网页请求最多同步等多久（秒），默认 20
            //               等不到就返回"修复中"，由 cron 继续推进 —— 宝塔的
            //               fastcgi_read_timeout 要大于这个值，否则会看到 504
            'queue_ttl'     => 900,
            'dispatch_wait' => 20,
        ],
    ],

    'feedback' => [
        // 反馈链接有效期（秒），默认 24 小时
        'token_ttl'           => 86400,
        // 同一 IP 每小时最多提交几条反馈
        'rate_limit_per_hour' => 5,
        // 同一 IP 每小时最多发起几次验证
        'verify_per_hour'     => 40,
        // 诊断结果给玩家展示的等待秒数（超出则提示稍后查看）
        'sync_wait_seconds'   => 8,
        // 自动关闭：已解决工单多少天后归档
        'autoclose_days'      => 7,
        /*
         | 客户端日志原文的留存天数（在 autoclose 归档之后再算）。
         |
         | 崩溃报告里含玩家的 Windows 用户名、显卡型号、游戏 ID、服务器地址，
         | 属于个人信息，不该无限期留着。归档满 N 天后，cron 会把 client_log
         | 原文和文件名清空，**诊断结论、MOD 列表、客户端分析结果全部保留** ——
         | 留着有用的结论，删掉没必要的原文。
         |
         | 0 = 不清理（旧行为）。默认 30 天。
         | 注意：清掉原文后，这条工单无法再重新解析日志。
         */
        'log_retention_days'  => 30,
    ],

    'automation' => [
        // 诊断发现可修复问题时，是否自动执行修复
        'auto_fix'            => true,

        // 高风险动作（重启服务端）是否仍需管理员点一下确认
        // 想让"玩家点一下就自动重启"就设成 false —— 前提是你信任这些玩家
        'require_approval_for_restart' => true,

        // 单条工单最多自动修复尝试次数，超过转人工
        'max_fix_attempts'    => 2,

        // 修复后回归验证的等待秒数（重启类动作需要更长）
        'verify_delay'        => 5,
        'verify_delay_restart'=> 25,

        // 连续失败多少条工单后，该服务器自动降级为"仅诊断"
        'circuit_breaker_failures' => 3,
    ],

    // 只信任这些反向代理的转发头。
    // 这里的地址必须与 $_SERVER['REMOTE_ADDR'] 完全相等才会采信 X-Forwarded-For /
    // X-Real-IP / CF-Connecting-IP；不在名单里的请求一律以 REMOTE_ADDR 为准。
    // 为什么这么严：后台 IP 白名单、登录锁定、玩家限流全都建立在这个值上面，
    // 而无条件相信请求头等于让攻击者随便伪造来源 IP。
    //
    // 采信时是**从右往左**扫 XFF，跳过在名单里的代理地址，取第一个非代理项。
    //
    // ★ 决定成败的不是"Nginx 怎么写"，而是 **PHP 看到的 REMOTE_ADDR 是什么**：
    //
    //   本项目自带的 Nginx 配置走 `fastcgi_pass unix:...` + `include fastcgi.conf`，
    //   而 fastcgi.conf 里有 `fastcgi_param REMOTE_ADDR $remote_addr;` ——
    //   于是 REMOTE_ADDR 是 Nginx 看到的**真实客户端 IP**，不在下面的名单里，
    //   转发头根本不会被采信。这种情况下名单是空转的，写什么都一样。
    //   （线上实测：伪造 X-Forwarded-For 不会改变记录到的来源 IP。）
    //
    //   只有当 REMOTE_ADDR **真的变成 127.0.0.1** 时才会采信转发头，
    //   典型是 `fastcgi_pass 127.0.0.1:9000` 又没设 REMOTE_ADDR 参数。
    //
    // ★ 前面**确实有**反向代理 / CDN 时，代理那一侧必须是"覆写"而不是"追加"：
    //
    //   ✅ 安全：proxy_set_header X-Forwarded-For $remote_addr;
    //      代理用真实对端地址**覆盖**掉客户端传来的值，整条 XFF 是你自己写的。
    //      然后把代理的出口地址填到下面这个数组里。
    //
    //   ⚠️ 危险：proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    //      这是"追加"：客户端先塞一个假地址，你追加的真实地址在它**右边**。
    //      取值时从右往左扫会拿对，但一旦代理没覆写、而下面又填了回环地址，
    //      就等于把整条 XFF 交给客户端自己填 —— 每次换一个 IP 就能绕过
    //      登录锁定和限流。
    //
    // 后台「系统设置 → 运行信息 → 来源 IP」会直接告诉你当前处在哪一种，
    // 不用靠猜。
    //
    // ★ 结论（1.13.2 起改了默认值，请照这个来）：
    //     没有反向代理 → 保持 [] 不要动。这是最安全的，系统只看 REMOTE_ADDR。
    //     有反向代理   → 填代理自己的出口地址（通常是 127.0.0.1），
    //                    并确认它**覆写**了 X-Forwarded-For（见上面 ✅ 那条）。
    //
    //   ❌ 不要填 ['127.0.0.1', '::1'] 却又不确认代理在覆写：
    //      很多部署里 REMOTE_ADDR 本来就恒为 127.0.0.1，此时任何客户端
    //      自填的 X-Forwarded-For 都会被视为真实来源，IP 白名单和失败锁定
    //      同时失效（实测可绕过后台 IP 白名单并无限爆破密码）。
    'trusted_proxies' => [],

    /*
    |--------------------------------------------------------------------------
    | 管理后台入口（与玩家反馈界面完全分开）
    |--------------------------------------------------------------------------
    | 玩家侧只有一个入口：?r=feedback&t=...（无身份、无状态）
    | 后台走下面这条私有路径，安装向导会自动生成一串随机值。
    |
    | path     后台入口路径。建议保持至少 16 位随机字符，别用 /admin、/manage 这种。
    |          **必须显式填写**：留空会按 hmac_secret 派生一个稳定值，
    |          但那个值算得出来、看不出来，装完你也会找不到自己的后台。
    |          安装向导生成的格式是 console-<16 位十六进制>。
    |          改它等于换锁：旧路径立刻返回 404。
    | ip_allow 允许访问后台的 IP，留空=不限制。
    |          支持单个 IP、192.168.1.* 通配、10.0.0.0/8 这种 CIDR。
    |          **强烈建议填**：这样即使路径泄露，别人也进不来。
    |
    | 隔离措施（见 src/ConsoleAuth.php）：
    |   - 独立会话名（mcfix_console），与玩家侧 mcfix_sid 不同
    |   - 两个会话的 cookie 都带 HttpOnly + SameSite=Lax（HTTPS 下还有 Secure）
    |   - 独立 CSRF 令牌，两边不共用
    |   - 玩家页面不出现任何指向后台的链接
    |   - 登录失败 15 分钟内 6 次即锁定
    |   - 网页端没有"找回口令"入口：只能上服务器跑 bin/mcfix.php reset-password
    */
    'admin' => [
        /*
         * 后台入口路径。
         *
         * 留空的话，路径会由 app.hmac_secret **派生**出来（见 ConsoleAuth::path()）——
         * 也就是说 hmac_secret 还是占位符时，"随机路径"其实由公开字面量决定，
         * 谁都能离线算出后台地址。所以：要么把 hmac_secret 换成真随机串，
         * 要么在这里显式写一段自己的随机路径（安装向导会替你生成）。
         *
         * 不要照抄下面这串占位符 —— 它是写在公开仓库里的，照抄等于没有隐藏路径。
         */
        'path'     => '',
        'ip_allow' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | 通知
    |--------------------------------------------------------------------------
    | 后台【通知设置】页可视化配置，这里只是结构参考。
    |
    | channels      每个渠道一个短名（订阅关系要用这个短名），type 决定用哪个适配器：
    |               dingtalk / wecom / feishu / mail / bark / ntfy
    |               / serverchan / telegram / discord / webhook
    | subscriptions 哪个事件发到哪些渠道。事件名见 src/Notifier.php 的 EVENTS。
    |               '*' 表示该渠道订阅全部事件。
    | burst_limit   风暴保护：同一服务器同一事件 10 分钟内最多发几条，
    |               避免服务端反复重启时把群刷爆。
    */
    'notify' => [
        'enabled'     => false,
        'burst_limit' => 3,
        'channels'    => [
            // 'dingtalk-main' => [
            //     'type'    => 'dingtalk',
            //     'enabled' => true,
            //     'webhook' => 'https://oapi.dingtalk.com/robot/send?access_token=xxx',
            //     'secret'  => 'SECxxx',   // 机器人安全设置选"加签"时填
            // ],
            // 想收邮件提醒的话，一般**不用**在这里建 mail 渠道 ——
            // 下面的 'email' 那一段更省事（一个开关 + 一个地址就完事）。
            // 'ops-mail' => [
            //     'type'      => 'mail',
            //     'enabled'   => true,
            //     'to'        => 'admin@example.com',
            //     'from_name' => 'MC 故障反馈系统',
            // ],
        ],
        'subscriptions' => [
            // 'dingtalk-main' => ['auto_fixed', 'fix.failed', 'need_manual', 'need_approval'],
            // 'ops-mail'      => ['*'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 邮件：玩家回执 + 管理员提醒
    |--------------------------------------------------------------------------
    | 和上面的 'notify' 不是一回事：
    |   notify.channels —— 事件推送（钉钉/企微/飞书/…），给管理员和群看的
    |   email           —— 给**玩家**发「已收到」和「处理结果」，顺带用邮件提醒管理员
    |
    | 这块只要两个东西就能跑：一个开关 + 一个管理员邮箱。不用建渠道、不用勾订阅。
    | 后台【系统设置 → 邮件通知】里可视化配置，这里只是结构参考。
    |
    | enabled        总开关。默认关，避免升级完突然开始往外发信。
    | admin_to       管理员收件人（收"有新反馈"提醒）
    | from           发件人地址。留空自动用 no-reply@<app.base_url 的域名>。
    |                建议填你自己域名的地址并加 SPF，否则 QQ/163 很容易判垃圾邮件。
    | notify_player  给玩家发回执与结果（需要玩家在反馈页填邮箱）
    | notify_admin   给管理员发新工单提醒
    | purge_email    工单结束后把玩家邮箱换成掩码（ab***@qq.com）。
    |                邮件队列里的明文地址在发出/彻底失败后也会立刻清掉。
    |
    | 发送是**异步**的：提交接口只入队，由 `php bin/mcfix.php cron` 每分钟发一批，
    | 失败按 1/3/10/30 分钟退避重试，最多 5 次。所以务必配好计划任务。
    */
    'email' => [
        'enabled'       => false,
        'admin_to'      => '',
        'from'          => '',
        'from_name'     => 'MC 故障反馈系统',
        'notify_player' => true,
        'notify_admin'  => true,
        'purge_email'   => true,

        /*
         * SMTP —— VPS 上唯一能真正发出去的办法。
         *
         * 为什么不靠 PHP 的 mail()：mail() 是"交给本机 MTA 直连对方 25 端口投递"。
         * 而绝大多数 VPS 服务商**封了出网 25 端口**（防垃圾邮件），所以装了 Postfix
         * 也只会把邮件堆在队列里，看起来装好了、实际一封都发不出去。
         * 就算 25 通，VPS 的 IP 没有 SPF/DKIM/反向解析，QQ、163 基本都判垃圾邮件。
         *
         * SMTP 走的是 465/587 这些"提交端口"（VPS 一般不封），登录你自己的邮箱账号，
         * 由服务商帮你转发 —— 送达率、SPF、DKIM 全是现成的。
         *
         * 常用填法：
         *   QQ 邮箱      smtp.qq.com          465 / ssl       密码填 16 位「授权码」
         *   QQ 企业邮    smtp.exmail.qq.com  465 / ssl       密码填邮箱登录密码
         *   163 / 126    smtp.163.com        465 / ssl       密码填「授权码」
         *   Gmail        smtp.gmail.com      587 / tls       密码填「应用专用密码」
         *   阿里云邮     smtp.mxhichina.com  465 / ssl       密码填邮箱登录密码
         *
         * force_from_username：QQ/163 要求发件地址和登录账号完全一致，不一致会被 550 拒。
         * 默认开着，省得你踩这个坑。
         */
        'smtp' => [
            'enabled'             => false,
            'host'                => '',
            'port'                => 465,
            'encryption'          => 'ssl',   // ssl(465) | tls(587, STARTTLS) | none
            'username'            => '',
            'password'            => '',      // QQ/163 填授权码，不是登录密码
            'from'                => '',      // 留空 = 用上面的账号
            'force_from_username' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 大模型兜底（可选）
    |--------------------------------------------------------------------------
    | **默认关闭。这个系统本身不依赖任何大模型** —— 诊断和修复的判断全部来自
    | src/ClientIssue.php 的 37 条规则 + src/ClientLog.php 的 51 条特征正则。
    |
    | 开了之后只有一个作用：**规则库没命中时**（以前直接"转人工"）多问一次模型，
    | 让玩家拿到一个人话结论，而不是一句"需要人工分析"。
    |
    | 用 OpenAI 兼容的 /v1/chat/completions，所以下面这些都是同一套配置：
    |   DeepSeek      https://api.deepseek.com           model=deepseek-chat
    |   OpenAI        https://api.openai.com              model=gpt-4o-mini
    |   通义千问      https://dashscope.aliyuncs.com/compatible-mode/v1
    |   硅基流动      https://api.siliconflow.cn/v1
    |   本地 Ollama   http://127.0.0.1:11434/v1          model=qwen2.5:7b
    |   本地 vLLM     http://127.0.0.1:8000/v1
    |
    | 五条边界（写在 src/AiAdvisor.php 里，不是配置项）：
    |   1. 模型只出结论，**不出命令**。它给的修复建议永远不会被执行；
    |      唯一能动手的路径是它点出了知识库里已有的 code，那时用的是我们自己写的配方。
    |   2. 发出去之前先脱敏：玩家名、IP、绝对路径、启动器令牌都会被替换掉。
    |   3. 有超时、失败就降级回"转人工"，绝不让模型卡住工单。
    |   4. 按日志特征缓存 7 天，同一类报错只问一次。
    |   5. 有闸：每个来源每小时 per_ip_hourly 次，全局每小时 60 次，防账单失控。
    |
    | 注意：这段等待是**玩家在页面上等着的**（诊断链路本来就 5~20 秒），
    | 所以 timeout 别调大；拿不到结果也会正常往下走。
    */
    'ai' => [
        'enabled'       => false,
        'base_url'      => '',        // 例如 https://api.deepseek.com
        'api_key'       => '',
        'model'         => '',        // 例如 deepseek-chat
        'timeout'       => 6,
        'max_tokens'    => 700,
        'temperature'   => 0.2,
        'per_ip_hourly' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | 环境变量覆盖（可选，容器化部署用）
    |--------------------------------------------------------------------------
    | 下面这些环境变量会覆盖同名配置项，宝塔的「网站 → 配置文件」里也能加。
    | 解析顺序：先读 config.php，再用环境变量覆盖。
    |
    |   MCFIX_CONFIG    换个配置文件路径（默认 <项目>/config/config.php）
    |   MCFIX_BASE_URL  → app.base_url
    |   MCFIX_PASSWORD  → app.admin_password（写 password_hash() 的结果，不是明文）
    |   MCFIX_SECRET    → app.hmac_secret
    |   MCFIX_DB_PATH   → db.path
    |   MCFIX_SERVERS   → servers（整段 JSON 数组）
    |   MCFIX_TIMEZONE  → app.timezone
    |
    | Agent 那边对应的是 MCFIX_AGENT_CONFIG（指定 config.php 的位置）。
    */
];
