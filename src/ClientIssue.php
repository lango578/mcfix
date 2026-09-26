<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 客户端问题目录。
 *
 * 每一条都是"玩家日志里出现的特征 → 一句人话结论 → 玩家自己能做的步骤（可选带上服务端要配合做的事）"。
 *
 * 分两类修复：
 *   fix.steps  —— 玩家在自己电脑上照做就能解决（不需要管理员参与）
 *   fix.server —— 需要服务端配合（我们系统能自动执行的，会去跑对应配方）
 *
 * 这就是"自动解决问题"的落点：能在服务端修的自动修，只能在客户端修的给出精确步骤而不是一句"重装试试"。
 */
final class ClientIssue
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return [
            // ------------------------------------------------------------ MOD 缺失
            'missing_client_mod' => [
                'title'    => '你缺少服务端要求的 MOD',
                'severity' => 'high',
                'cause'    => '服务器装了你没装的模组，进入时会因为找不到对应内容被踢出或崩溃。',
                'steps'    => [
                    '在服务器 QQ 群 / 公告里找到「整合包」或「MOD 清单」',
                    '把缺失的 MOD 放进 .minecraft/mods 目录',
                    '确认 MOD 版本与服务端一致（版本不一致同样进不去）',
                    '客户端与服务端的模组列表必须**完全一致**，多一个少一个都会出问题',
                ],
                'recipes'  => [],
            ],
            'missing_dependency' => [
                'title'    => '缺少前置库（依赖 MOD 没装）',
                'severity' => 'high',
                'cause'    => '某个 MOD 需要的前置库不在你的 mods 目录里。日志里通常写着 "requires ... which is missing"。',
                'steps'    => [
                    '看下面「缺少的组件」列表，逐个补装',
                    '常见前置：Fabric API、Architectury API、Cloth Config、GeckoLib、Kotlin for Forge、Collective',
                    '前置库也要选对游戏版本和加载器，装错版本等于没装',
                ],
                'recipes'  => [],
            ],
            'mod_incompatible' => [
                'title'    => '两个 MOD 之间不兼容',
                'severity' => 'high',
                'cause'    => '日志里出现了 "Incompatible mod set" 或某个 MOD 要求特定版本，当前组合互相冲突。',
                'steps'    => [
                    '先看下面的「可疑 MOD」列表，把最后装的那个 MOD 移除试试',
                    '同类功能 MOD 不要装两个（例如两个小地图、两个优化模组）',
                    'OptiFine 与 Sodium/Embeddium 属于互斥关系，只能留一个',
                    '把整合包恢复成服主提供的原版整合包，是成功率最高的做法',
                ],
                'recipes'  => [],
            ],
            'mod_load_error' => [
                'title'    => 'MOD 在加载阶段就失败了',
                'severity' => 'high',
                'cause'    => '某个 MOD 无法完成加载（ModLoadingException / LoadingFailedException），通常伴随具体的 MOD 名。',
                'steps'    => [
                    '从下面的「可疑 MOD」里找到报错的 MOD，先删掉它再启动',
                    '如果是整合包里的 MOD，去整合包发布页找对应版本更新',
                    '检查是否误装了「服务端专用」MOD（名字里常带 server / bukkit / spigot）',
                    '检查是否为该 MOD 装了错误的前置库版本',
                ],
                'recipes'  => [],
            ],
            'mixin_failed' => [
                'title'    => 'Mixin 注入失败（MOD 与游戏版本对不上）',
                'severity' => 'high',
                'cause'    => 'MixinApplyError 说明某个 MOD 想改游戏代码但找不到目标，最常见的原因是 MOD 版本与 MC 版本不匹配。',
                'steps'    => [
                    '把日志里报 Mixin 错误的 MOD 换成与游戏版本匹配的版本',
                    '在 HMCL/PCL2 里核对「游戏版本 + 加载器版本 + MOD 版本」三者是否一致',
                    '不要混用 Forge 版和 Fabric 版的 MOD（两者完全不通用）',
                    '如果刚升级过游戏版本，把所有 MOD 全部更新一遍',
                ],
                'recipes'  => [],
            ],
            'class_not_found' => [
                'title'    => '缺少某个类（MOD 没装全或版本不对）',
                'severity' => 'high',
                'cause'    => 'NoClassDefFoundError / ClassNotFoundException：程序要用的代码在你的客户端里不存在。',
                'steps'    => [
                    '看「缺少的组件」列表，里面的包名通常能直接对应到某个 MOD',
                    '补装对应 MOD 后重启客户端',
                    '如果已经装了，说明版本不对，换成和服务端相同的版本',
                ],
                'recipes'  => [],
            ],
            'method_not_found' => [
                'title'    => 'MOD 版本不匹配（找不到方法）',
                'severity' => 'high',
                'cause'    => 'NoSuchMethodError 几乎总是版本问题：MOD 编译时针对的是另一个版本的游戏或前置库。',
                'steps'    => [
                    '把报错的 MOD 更新到与服务端一致的最新版',
                    '检查前置库（Fabric API / Forge）版本是否也需要同步更新',
                    '整合包用户：直接换回服主提供的整合包',
                ],
                'recipes'  => [],
            ],

            // ------------------------------------------------------------ 加载器 / 版本
            'forge_mismatch' => [
                'title'    => 'Forge 版本与服务器不一致',
                'severity' => 'high',
                'cause'    => '服务器要求的 Forge 版本和你客户端装的不是同一个（Forge 对版本非常敏感）。',
                'steps'    => [
                    '打开启动器，找到该整合包的版本设置',
                    '把 Forge 版本改成服务器公告里写的版本号',
                    '改完后重新安装一次版本（启动器一般有「重新安装」按钮）',
                ],
                'recipes'  => [],
            ],
            'neoforge_mismatch' => [
                'title'    => 'NeoForge 版本与服务器不一致',
                'severity' => 'high',
                'cause'    => '1.20.2 之后很多整合包迁移到了 NeoForge，它和 Forge 是两个不同的东西，不能混用。',
                'steps'    => [
                    '确认服务器用的是 NeoForge 还是 Forge（公告里会写）',
                    '用对应的加载器重新安装版本，版本号必须完全一致',
                    'NeoForge 需要 Java 21，注意别用 Java 8',
                ],
                'recipes'  => [],
            ],
            'fabric_loader_mismatch' => [
                'title'    => 'Fabric Loader 版本不一致',
                'severity' => 'normal',
                'cause'    => 'Fabric Loader 版本与服务器不同步，部分 MOD 会因此报错。',
                'steps'    => [
                    '在启动器里把 Fabric Loader 更新到服务器要求的版本',
                    'Fabric API 是独立 MOD，装在 mods 目录里，别和 Loader 混淆',
                ],
                'recipes'  => [],
            ],
            'loader_mismatch' => [
                'title'    => '模组加载器类型或版本不一致',
                'severity' => 'high',
                'cause'    => '服务端与客户端使用的加载器（Forge / NeoForge / Fabric / Quilt / 原版）不同，或者版本号不同。',
                'steps'    => [
                    '先向服主确认服务器的加载器和精确版本号',
                    '在启动器里重新安装一个与之完全一致的版本',
                    '原版服不能装任何 MOD；模组服必须用相同加载器',
                ],
                'recipes'  => [],
            ],
            'version_mismatch' => [
                'title'    => '游戏版本与服务器不一致',
                'severity'    => 'high',
                'cause'    => 'Outdated client / Outdated server：客户端和服务端的 Minecraft 版本不同。',
                'steps'    => [
                    '看服务器列表里显示的版本号，或者问服主',
                    '在启动器里切换到对应版本再进服',
                    '如果是转服（例如 Velocity/BungeeCord），主服版本才是要匹配的那个',
                ],
                'recipes'  => [],
            ],

            // ------------------------------------------------------------ 运行环境
            'java_version' => [
                'title'    => 'Java 版本不对',
                'severity' => 'high',
                'cause'    => 'UnsupportedClassVersionError 表示游戏要求的 Java 版本和你当前用的不同。',
                'steps'    => [
                    '1.17~1.20.4 需要 Java 17',
                    '1.20.5 及以后、以及 NeoForge 需要 Java 21',
                    '1.16.5 及以前一般用 Java 8',
                    '在启动器「设置 → Java 路径」里选择对应的 JDK（推荐用 Adoptium / Liberica 的完整 JDK）',
                ],
                'recipes'  => [],
            ],
            'oom' => [
                'title'    => '内存不足（游戏被 Java 强制结束）',
                'severity' => 'high',
                'cause'    => 'OutOfMemoryError / Java heap space：分配的最大内存不够，或被 MOD 撑爆了。',
                'steps'    => [
                    '在启动器里把最大内存调到 4G~8G（不要超过物理内存的一半）',
                    '同时检查是否开了太多后台程序、或用了 32 位 Java（32 位最多只能给 1.5G）',
                    '如果内存明明够大还是爆，通常是某个 MOD 内存泄漏，先移除最后装的那个 MOD',
                    '开启「优化类 MOD」（Sodium / Embeddium）能显著降低内存压力',
                ],
                'recipes'  => [],
            ],
            'graphics_driver' => [
                'title'    => '显卡 / OpenGL 初始化失败',
                'severity' => 'normal',
                'cause'    => '游戏无法创建渲染窗口，通常是显卡驱动太旧或缺少 OpenGL 支持。',
                'steps'    => [
                    '更新显卡驱动到最新版（NVIDIA / AMD 官网，不要用系统自带的通用驱动）',
                    '笔记本双显卡机型：在显卡控制面板里把 Java 指定为「高性能显卡」',
                    '远程桌面 / 虚拟机环境可能不支持 OpenGL，请在物理机上运行游戏',
                ],
                'recipes'  => [],
            ],
            'render_error' => [
                'title'    => '渲染阶段崩溃',
                'severity' => 'normal',
                'cause'    => '崩溃发生在渲染线程，常见于光影、优化类 MOD、显卡驱动三者冲突。',
                'steps'    => [
                    '先关掉光影包再启动，确认是不是光影引起',
                    'OptiFine 与 Sodium/Embeddium 只能留一个',
                    '更新显卡驱动',
                    '降低视频设置里的渲染距离与图形等级',
                ],
                'recipes'  => [],
            ],
            'ccompat_error' => [
                'title'    => '光影 / 渲染类模组互相冲突',
                'severity' => 'normal',
                'cause'    => '日志里出现了 OptiFine / Sodium / Iris / Embeddium 这类渲染模组。它们功能重叠，同时装会互相覆盖游戏的渲染代码，是崩溃的常见原因。',
                'steps'    => [
                    'OptiFine 和 Sodium（或 Iris、Embeddium）不能同时装，先移除其中一个再启动',
                    '如果用的是 Iris 光影，确认它配的是 Sodium 而不是 OptiFine',
                    '确认光影包本身和游戏版本匹配（光影包版本不对也会崩）',
                    '移除后仍然崩溃，就再逐个移除其它渲染优化类模组排查',
                ],
                'recipes'  => [],
            ],
            'datapack_error' => [
                'title'    => '数据包加载失败',
                'severity' => 'normal',
                'cause'    => '日志里出现了数据包（datapack）加载失败。数据包的格式随游戏版本变化，版本对不上就会整个加载不了。',
                'steps'    => [
                    '检查存档目录下的 datapacks 文件夹，以及 .minecraft 里的全局数据包',
                    '把数据包换成与游戏版本匹配的版本',
                    '不确定是哪个的话，先把最近新加的数据包移出去再启动',
                ],
                'recipes'  => [],
            ],

            // ------------------------------------------------------------ 网络
            'connection_refused' => [
                'title'    => '连接被拒绝（服务器没在监听）',
                'severity' => 'critical',
                'cause'    => 'Connection refused 表示地址能到达，但那个端口没有程序在听 —— 通常是服务端没起来或刚崩。',
                'steps'    => [
                    '先等 1~2 分钟再试（服务器可能正在重启）',
                    '换个时间点再试，或在群里问一下别人是不是也进不去',
                    '如果只有你连不上，检查是不是开了代理/VPN 影响了连接',
                ],
                'recipes'  => ['restart_server'],
            ],
            'unknown_host' => [
                'title'    => '域名解析失败（找不到这个服务器）',
                'severity' => 'normal',
                'cause'    => 'UnknownHostException：你的电脑无法把服务器域名解析成 IP。',
                'steps'    => [
                    '检查地址有没有打错（多余的空格、中文标点都会导致失败）',
                    '把电脑 DNS 改成 223.5.5.5 / 119.29.29.29 再试',
                    '如果你用了加速器或 VPN，先关掉再连',
                    '在命令行执行 ping 服务器地址，看能不能解析出 IP',
                ],
                'recipes'  => [],
            ],
            'timed_out' => [
                'title'    => '连接超时（数据到不了服务器）',
                'severity' => 'normal',
                'cause'    => '连接建立后长时间没有响应，通常是网络链路问题或服务端严重卡顿。',
                'steps'    => [
                    '先关掉加速器 / VPN / 代理再试一次',
                    '换个网络环境测试（手机热点能进说明是本地网络问题）',
                    '检查防火墙 / 安全软件是否拦截了 javaw.exe 的出站连接',
                    '如果所有人都超时，那是服务端卡了，交给管理员处理',
                ],
                'recipes'  => ['restart_server'],
            ],
            'reset_by_peer' => [
                'title'    => '连接被远程主机中断',
                'severity' => 'normal',
                'cause'    => 'Connection reset by peer：链路上的某一端主动断开了连接。',
                'steps'    => [
                    '关掉加速器 / VPN 后重试（这是最常见的原因）',
                    '检查本地防火墙、公司/学校网络的深度包检测',
                    '如果是校园网，试试换手机热点',
                ],
                'recipes'  => [],
            ],
            'ssl_error' => [
                'title'    => '加密连接失败（HTTPS 证书问题）',
                'severity' => 'normal',
                'cause'    => 'SSLHandshakeException / PKIX：登录或资源下载时证书校验不通过。',
                'steps'    => [
                    '校准系统时间（时间不对会导致证书校验失败）',
                    '关闭系统里的 HTTPS 拦截类软件（部分杀毒软件、抓包工具）',
                    '在启动器设置里改用「离线登录」或更换登录方式',
                ],
                'recipes'  => [],
            ],
            'upstream_error' => [
                'title'    => '网络包异常（连接被中断）',
                'severity' => 'normal',
                'cause'    => 'Internal Exception / 解码异常，通常是网络不稳定或服务端在处理你的连接时出错。',
                'steps'    => [
                    '重新进服一次（临时性错误占多数）',
                    '关闭加速器 / VPN',
                    '降低客户端网络设置（如将「数据包压缩阈值」恢复默认）',
                ],
                'recipes'  => [],
            ],
            'auth_failed' => [
                'title'    => '登录验证失败',
                'severity' => 'normal',
                'cause'    => 'Failed to verify username / Invalid session：正版验证没通过。',
                'steps'    => [
                    '重新登录一次启动器账号（会话过期了）',
                    '确认这个服务器是不是离线服（离线服要用离线登录，且 ID 不能和别人重复）',
                    '正版账号异常可到 minecraft.net 检查账号状态',
                ],
                'recipes'  => [],
            ],

            // ------------------------------------------------------------ 服务端原因
            'not_whitelisted' => [
                'title'    => '你不在服务器白名单里',
                'severity' => 'high',
                'cause'    => '服务器开启了白名单，你的游戏 ID 不在名单中，所以被拒绝进入。',
                'steps'    => [
                    '通常不需要你做什么，系统会自动把你加进白名单',
                    '如果提交后仍然进不去，请确认游戏 ID 填写是否有误（区分大小写）',
                ],
                'recipes'  => ['whitelist_add'],
            ],
            'banned' => [
                'title'    => '你的账号处于封禁状态',
                'severity' => 'high',
                'cause'    => '服务器封禁名单里有你的游戏 ID。',
                'steps'    => [
                    '如果是误封，本次反馈会自动提交解封请求给管理员',
                    '封禁通常有原因，建议同时在群里联系管理员说明情况',
                ],
                'recipes'  => ['unban_player'],
            ],
            'server_full' => [
                'title'    => '服务器已满员',
                'severity' => 'normal',
                'cause'    => '当前在线人数达到上限，新玩家会被拒绝进入。',
                'steps'    => [
                    '等一会儿再试，或者看群里有没有人退出',
                    'VIP / 优先通道一般不受人数限制，可以咨询服主',
                ],
                'recipes'  => [],
            ],
            'server_offline' => [
                'title'    => '服务器当前是离线状态',
                'severity' => 'critical',
                'cause'    => '服务端进程或端口不正常，所有人都连不上（不只是你）。',
                'steps'    => [
                    '这属于服务端故障，你不需要改任何东西',
                    '系统已经通知管理员并尝试自动恢复，稍等几分钟再试',
                ],
                'recipes'  => ['restart_server'],
            ],
            'server_side_error' => [
                'title'    => '错误发生在服务端（不是你的问题）',
                'severity' => 'high',
                'cause'    => '日志里出现的是服务端的异常（插件 / 服务端 MOD），你的客户端只是被动报错。',
                'steps'    => [
                    '你不需要修改客户端，反馈已经转给管理员',
                    '如需临时绕过，可以在群里问一下管理员什么时候能修好',
                ],
                'recipes'  => ['reload_plugins', 'restart_server'],
            ],
            'plugin_error' => [
                'title'    => '服务端插件报错',
                'severity' => 'normal',
                'cause'    => '服务端插件异常影响了你的操作。',
                'steps'    => [
                    '稍后重试，或换个操作方式',
                    '管理员已收到这条反馈',
                ],
                'recipes'  => ['reload_plugins', 'restart_server'],
            ],
            'chunk_corrupt' => [
                'title'    => '存档区块损坏',
                'severity' => 'high',
                'cause'    => '日志里出现了坏区块 / 坏区域文件的特征。',
                'steps'    => [
                    '如果你卡在某个位置无法移动，请告知管理员具体坐标',
                    '在管理员处理前不要反复尝试进入该区域，可能加重损坏',
                    '客户端缓存可尝试删除 .minecraft/versions/<版本>/ 下的临时文件后重进',
                ],
                'recipes'  => ['save_world', 'backup_world'],
            ],
            'client_crash' => [
                'title'    => '客户端发生了崩溃',
                'severity' => 'high',
                'cause'    => '游戏进程异常退出，日志里没有更具体的原因。',
                'steps'    => [
                    '先看下面的「可疑 MOD」，把最后安装的 MOD 移除后重试',
                    '启动器里点一次「修复 / 重新安装」该版本',
                    '删除 .minecraft/config 下的相关配置文件（有些配置损坏会导致启动崩溃）',
                    '如果装了光影，先移除光影包再试',
                ],
                'recipes'  => [],
            ],
            'affected_level' => [
                'title'    => '崩溃发生在世界加载阶段',
                'severity' => 'high',
                'cause'    => '崩溃报告里包含 Affected level 段落，说明问题与存档 / 区块有关，而不是 MOD 本身。',
                'steps'    => [
                    '把「单人存档」与「进服务器」分开测试：单人正常则问题在服务端',
                    '删除 .minecraft/saves 下对应存档的 session.lock 文件后再试',
                    '如果单人存档也崩，请把该存档备份后交给管理员分析',
                ],
                'recipes'  => ['save_world', 'backup_world'],
            ],
            'modpack_conflict_server' => [
                'title'    => '客户端额外装了服务端没有的 MOD',
                'severity' => 'high',
                'cause'    => '你本地 MOD 列表比服务端多出了一些。',
                'steps'    => [
                    '把多出来的 MOD 从 mods 目录移走（下面的列表里会标出可疑项）',
                    '只保留客户端专用 MOD（小地图、光影、性能优化等）',
                ],
                'recipes'  => [],
            ],
            'need_log' => [
                'title'    => '需要完整的崩溃报告才能定位',
                'severity' => 'low',
                'cause'    => '这次提交的内容里没有足够的信息（日志太短或只是截图文字）。',
                'steps'    => [
                    '打开 .minecraft/crash-reports/ 目录，把最新的那个 .txt 文件整个上传',
                    '如果没有 crash-reports 目录，就上传 .minecraft/logs/latest.log',
                    '重新提交一次反馈，系统会自动分析',
                ],
                'recipes'  => [],
            ],
            'unknown' => [
                'title'    => '暂时无法从日志中定位到明确原因',
                'severity' => 'low',
                'cause'    => '日志里没有匹配到已知的错误特征，需要人工分析。',
                'steps'    => [
                    '把完整的崩溃报告（crash-reports 里的 txt）补发一次',
                    '同时说明：什么时候开始出现、做了什么操作、是否只在特定位置发生',
                ],
                'recipes'  => [],
            ],
            /*
             * 大模型兜底（可选功能，见 src/AiAdvisor.php）。
             *
             * 这一条是**模板**：title / cause / steps 会在运行时被模型给出的内容替换掉。
             * 所以这里的文字只在"模型没给出这些字段"时才会被玩家看到。
             *
             * recipes 故意留空 —— 这是安全边界：**模型给的方案永远不会被执行**。
             * 唯一能让系统动手的路径是模型点出了知识库里已有的 code，
             * 那时用的是**这条知识库自己的 recipes**（我们写的、审过的），模型碰不到执行链。
             */
            'ai_suggestion' => [
                'title'    => '日志里出现了知识库没覆盖的错误',
                'severity' => 'normal',
                'cause'    => '这条报错不在我们已知的问题清单里，系统用大模型读了一遍日志给出的结论。',
                'steps'    => [
                    '先按下面的说明逐条试一下',
                    '如果还是不行，把完整的 crash-report 文件发给管理员',
                ],
                'recipes'  => [],
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get(string $code): ?array
    {
        $all = self::all();

        return $all[$code] ?? null;
    }

    public static function exists(string $code): bool
    {
        return self::get($code) !== null;
    }

    public static function severityRank(string $severity): int
    {
        $rank = ['critical' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];

        return $rank[$severity] ?? 9;
    }

    /**
     * 所有问题码的简短列表，给后台做筛选。
     *
     * @return array<string,string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::all() as $code => $def) {
            $options[(string) $code] = (string) $def['title'];
        }

        return $options;
    }
}
