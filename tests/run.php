<?php
/**
 * 冒烟测试 —— 纯 PHP 断言，不依赖 PHPUnit，不需要 Composer。
 *
 * 用法（项目根目录）：
 *     php tests/run.php
 *
 * 为什么这么写：
 *   这个项目的部署场景是"上传解压就能跑"，要求零依赖。引入 PHPUnit 就意味着
 *   贡献者得先装 Composer，而很多宝塔用户根本没有。所以测试只用 PHP 自带能力。
 *
 * 覆盖面是**故意收窄**的：只测那些"错了会出安全事故或让玩家看到假结论"的地方，
 * 不去追行覆盖率。真正的语法保证靠 CI 里的 php -l。
 *
 * 退出码：0 = 全过，1 = 有失败。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('本脚本只能在命令行运行');
}

define('MCFIX_ROOT', dirname(__DIR__));

// 测试用一份指向临时目录的假配置，绝不碰真实配置与数据库。
$tmpDir = sys_get_temp_dir() . '/mcfix-tests-' . bin2hex(random_bytes(4));
@mkdir($tmpDir . '/config', 0700, true);
@mkdir($tmpDir . '/storage/data', 0700, true);

$fakeConfig = [
    'app' => [
        'name'           => 'MCFix Tests',
        'base_url'       => 'https://mcfix.test',
        'hmac_secret'    => str_repeat('a', 64),
        'admin_password' => password_hash('test-password', PASSWORD_DEFAULT),
        'timezone'       => 'UTC',
        'debug'          => false,
    ],
    'admin' => ['path' => 'console-test', 'ip_allow' => []],
    'db'    => ['driver' => 'sqlite', 'path' => $tmpDir . '/storage/data/test.sqlite'],
    'servers' => [
        'unit' => [
            'id'           => 'unit',
            'code'         => 'U1',
            'name'         => '测试服',
            'host'         => '127.0.0.1',
            'port'         => 25565,
            'enabled'      => true,
            'agent_token'  => str_repeat('b', 32),
            'share_secret' => str_repeat('c', 48),
            'rcon'         => ['enabled' => false, 'host' => '127.0.0.1', 'port' => 25575, 'password' => '', 'timeout' => 3],
            'guard'        => [
                'type' => 'systemd', 'service' => 'mc', 'session' => '',
                'start_cmd' => '', 'stop_cmd' => '', 'restart_cmd' => '',
                'max_restarts_per_hour' => 3,
            ],
            'recipes' => [],
        ],
    ],
    'feedback' => [
        'token_ttl' => 86400, 'rate_limit_per_hour' => 5, 'verify_per_hour' => 40,
        'sync_wait_seconds' => 8, 'autoclose_days' => 7,
    ],
    'trusted_proxies' => ['127.0.0.1'],
];

$configFile = $tmpDir . '/config/config.php';
file_put_contents($configFile, "<?php\nreturn " . var_export($fakeConfig, true) . ";\n");
putenv('MCFIX_CONFIG=' . $configFile);

require MCFIX_ROOT . '/src/bootstrap.php';

use MCFix\ClientLog;
use MCFix\Config;
use MCFix\Db;
use MCFix\Mail;
use MCFix\Recipe;
use MCFix\Token;

// --------------------------------------------------------------------- 迷你断言框架

$passed = 0;
$failed = 0;

function group(string $name): void
{
    echo PHP_EOL . '── ' . $name . PHP_EOL;
}

function ok(bool $condition, string $description, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo '  ✓ ' . $description . PHP_EOL;
        return;
    }

    $failed++;
    echo '  ✗ ' . $description . PHP_EOL;
    if ($detail !== '') {
        echo '      ' . $detail . PHP_EOL;
    }
}

/** 配方校验返回 {ok, params, error}，这里只关心通过与否。 */
function accepts(string $code, array $params): bool
{
    $out = Recipe::validateParams($code, $params);

    return !empty($out['ok']);
}

/**
 * 剥掉 PHP 源码里的注释，只留代码。
 *
 * 为什么需要：本文件里有一批"源码级守卫"，用 strpos 检查某个写法还在不在。
 * 直接扫原文的话，注释里为了交代历史而引用旧写法会把守卫自己绊倒 ——
 * 比如 resolve() 的注释写着"原来这里只写 status = 'resolved'"，守卫就会
 * 判定成"还在写 resolved"。结果是**写清楚历史的人被惩罚**，后来者只能
 * 把注释删掉才能过测试。所以这类守卫一律先剥注释再匹配。
 */
function php_code_only(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $token[1];
            continue;
        }
        $out .= $token;
    }

    return $out;
}

/** 取某个函数/方法体的源码（从签名到它自己的收尾大括号，不含注释）。 */
function function_body(string $code, string $signature): string
{
    $start = strpos($code, $signature);
    if ($start === false) {
        return '';
    }
    $end = strpos($code, "\n    }", $start);

    return $end === false ? substr($code, $start, 4000) : substr($code, $start, $end - $start);
}

// --------------------------------------------------------------------- 配方参数校验

group('Recipe::validateParams —— 参数注入的第一道闸');

ok(accepts('whitelist_add', ['player' => 'Steve']), '合法玩家名通过');
ok(!accepts('whitelist_add', ['player' => 'ab']), '过短的玩家名被拒');
ok(!accepts('whitelist_add', ['player' => 'Steve; rm -rf /']), '分号注入被拒');
ok(!accepts('whitelist_add', ['player' => 'Steve$(whoami)']), '命令替换被拒');
ok(!accepts('whitelist_add', ['player' => 'Steve`id`']), '反引号被拒');
ok(!accepts('whitelist_add', ['player' => 'Steve || id']), '管道符被拒');
ok(!accepts('whitelist_add', ['player' => 'Steve name']), '空格被拒');
ok(!accepts('whitelist_add', ['player' => str_repeat('a', 17)]), '超长玩家名被拒');
ok(!accepts('whitelist_add', []), '缺参数被拒');
ok(!accepts('并不存在的配方', ['player' => 'Steve']), '未知配方被拒');
ok(accepts('save_world', []), '无参配方通过');
ok(accepts('unmute_player', ['player' => 'Steve']), 'unmute 接受玩家名');

$kick = Recipe::validateParams('kick_player', ['player' => 'Steve', 'reason' => "换\n行"]);
ok(
    !empty($kick['ok']) && strpos((string) $kick['params']['reason'], "\n") === false,
    '踢人理由里的换行被清掉',
    '实际：' . var_export($kick['params'] ?? null, true)
);

$longReason = Recipe::validateParams('kick_player', ['player' => 'Steve', 'reason' => str_repeat('x', 500)]);
ok(
    !empty($longReason['ok']) && mb_strlen((string) $longReason['params']['reason']) <= 80,
    '踢人理由被截断到 80 字'
);

// 关键不变量：玩家可控的文本只会变成游戏指令，不会变成 shell 片段
ok(Recipe::rconCommand('whitelist_add', ['player' => 'Steve']) === 'whitelist add Steve', 'RCON 指令模板正确');
ok(Recipe::rconCommand('unmute_player', ['player' => 'Steve']) === 'unmute Steve', 'unmute 有 RCON 模板');
ok(Recipe::rconCommand('并不存在的配方', []) === '', '未知配方没有 RCON 模板');
ok(count(Recipe::all()) === 11, '配方白名单正好 11 条', '实际：' . count(Recipe::all()));

// --------------------------------------------------------------------- 签名令牌

group('Token —— 签名、过期与跨工单隔离');

$feedback = ['id' => 42, 'server_id' => 'unit', 'token_nonce' => 'nonce-1', 'ticket_no' => 'T-1'];

$verify = Token::forVerify($feedback);
ok($verify !== '', '能签发验证令牌');
ok(strpos($verify, '.') !== false, '令牌是 "载荷.签名" 两段式');

$parsed = Token::parse($verify, Token::SCOPE_VERIFY);
ok(is_array($parsed), '刚签发的令牌能解析');
ok(is_array($parsed) && (int) $parsed['id'] === 42, '令牌里带上了工单 id');
ok(is_array($parsed) && (string) $parsed['server_id'] === 'unit', '令牌里带上了服务器 id');

ok(Token::parse($verify, 'a') === null, '验证令牌不能当 Agent 令牌用（scope 不匹配）');

$tampered = $verify;
$pos = (int) floor(strlen($tampered) / 2);
$tampered[$pos] = $tampered[$pos] === 'A' ? 'B' : 'A';
ok(Token::parse($tampered, Token::SCOPE_VERIFY) === null, '被改过一个字符的令牌无法通过');

ok(Token::parse('', Token::SCOPE_VERIFY) === null, '空令牌被拒');
ok(Token::parse('没有点号的字符串', Token::SCOPE_VERIFY) === null, '没有点号的令牌被拒');
ok(Token::parse('a.b.c', Token::SCOPE_VERIFY) === null, '三段式令牌被拒');

// 已过期的令牌必须拒绝（ttl 传负数等价于立刻过期）
$expired = Token::forVerify($feedback, -10);
ok(Token::parse($expired, Token::SCOPE_VERIFY) === null, '过期令牌被拒');

// 每服务器独立密钥：用 A 的 secret 签的公开链接，换一个 secret 就验不过
$shareSecret = str_repeat('c', 48);
$shareToken = Token::forServerShare('unit', 'share', 3600, $shareSecret);
ok(Token::parse($shareToken, Token::SCOPE_SHARE) !== null, '服务器公开链接可验证（密钥在配置里）');
ok(
    Token::parse(Token::forServerShare('unit', 'share', 3600, str_repeat('9', 48)), Token::SCOPE_SHARE) === null,
    '用一个不在配置里的密钥签发就无法验证'
);

// Agent 令牌的 scope 必须独立
$agentToken = Token::agentToken('unit', 60);
ok(Token::parse($agentToken, Token::SCOPE_AGENT) !== null, 'Agent 令牌可验证');
ok(Token::parse($agentToken, Token::SCOPE_VERIFY) === null, 'Agent 令牌不能当验证令牌用');

// ---- 密钥不可用时必须失败关闭 ----
//
// 以前空密钥会退回到字面量 'mcfix-empty-secret'，而 config.example.php 里的
// 'CHANGE_ME_TO_A_RANDOM_STRING' 直接印在公开仓库里。用这种密钥签出来的令牌
// 谁都能伪造，而 Agent 令牌能让攻击者读走任务队列、伪造执行结果。
// 正确行为是**签出来的东西根本验不过**，让管理员当场发现配置有问题。
// 注意：这里不能把 '' 放进列表 —— 传空串的含义是"用全局密钥"，
// 不是"用一个空密钥"。空全局密钥那种情况由 candidateSecrets() 处理。
foreach (['mcfix-empty-secret', 'CHANGE_ME_TO_A_RANDOM_STRING', 'change-me', 'secret'] as $badSecret) {
    $forgeable = Token::forServerShare('unit', 'share', 3600, $badSecret);
    ok(
        Token::parse($forgeable, Token::SCOPE_SHARE) === null,
        '★ 用公开占位密钥签的令牌验不过：' . $badSecret
    );
}

// 正常密钥不受影响（用配置里真实存在的那个 share_secret）
ok(
    Token::parse(Token::forServerShare('unit', 'share', 3600, str_repeat('c', 48)), Token::SCOPE_SHARE) !== null,
    '换成一个真实密钥仍然能签能验（没有误伤）'
);

// --------------------------------------------------------------------- 客户端日志解析

group('ClientLog —— 崩溃报告与白名单提示');

$crash = implode("\n", [
    '---- Minecraft Crash Report ----',
    '// Why did you do that?',
    '',
    'Time: 2026-01-02 03:04:05',
    'Description: Ticking entity',
    '',
    'java.lang.NoClassDefFoundError: dev/architectury/api/Api',
    "\tat com.example.mymod.MyMod.<init>(MyMod.java:42)",
    '',
    'A detailed walkthrough of the error, its code path and all known details is as follows:',
]);

$result = ClientLog::parse($crash);
ok(is_array($result), '崩溃报告能解析');
ok(($result['kind'] ?? '') === 'crash_report', '识别出这是崩溃报告', '实际：' . (string) ($result['kind'] ?? '?'));

$blob = (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
ok(stripos($blob, 'NoClassDefFoundError') !== false, '识别到 NoClassDefFoundError');
ok(stripos($blob, 'architectury') !== false, '提取出了缺失的类名');

// 白名单提示：这行以前触发过一个真实的语法错误 ——
// 正则里写了 white-?listed，问号紧挨着 l，PHP 把那个两字符序列当成了 PHP 结束标签，
// 字符串被当场截断。这里留两条回归用例盯着它。
$whitelist = ClientLog::parse("You are not whitelisted on this server!\n");
ok(is_array($whitelist), '"not whitelisted" 这类短日志不会让解析器崩掉');
ok(($whitelist['kind'] ?? '') !== '', '短日志也有 kind');

$notWhiteListed = ClientLog::parse("You are not white-listed on this server!\n");
ok(is_array($notWhiteListed), '带连字符的 "white-listed" 也能处理');

// 超长单行 + 超多行都不应该把解析器拖死（正则回溯放大的回归守卫）
$huge = str_repeat('a', 300000) . "\n" . str_repeat("line of log here\n", 20000);
$start = microtime(true);
$parsedHuge = ClientLog::parse($huge);
$elapsed = microtime(true) - $start;

ok(is_array($parsedHuge), '超长/超多行日志仍能返回结果');
ok($elapsed < 5.0, '超长/超多行日志在 5 秒内处理完', sprintf('实际耗时 %.2fs', $elapsed));

// 二进制 / 乱码不应该让解析器抛异常
$binary = ClientLog::parse(random_bytes(4096));
ok(is_array($binary), '纯二进制输入不会让解析器崩掉');

// ---- 真实形状的崩溃报告：System Details 是 **Tab 缩进**的 ----
//
// 这一条是补一个真实的漏解析：正则原来用 ^ 锚定，而真实 crash-report 里
// 那些字段前面都有一个 Tab，于是版本/Java/内存/CPU/系统一条都提取不到。
// 玩家看到的体检结果会缺东西，发给大模型的上下文也变少。
// 之前所有测试用的都是"顶格写"的假日志，所以一直没暴露。
$realCrash = implode("\n", [
    '---- Minecraft Crash Report ----',
    '// Surprise! Haha. Well, this is awkward.',
    '',
    'Time: 2026-01-02 03:04:05',
    'Description: Ticking entity',
    '',
    'java.lang.ArithmeticException: Non-terminating decimal expansion',
    "\tat com.zonko.quantum.QuantumTicker.tick(QuantumTicker.java:212)",
    '',
    'A detailed walkthrough of the error, its code path and all known details is as follows:',
    '---------------------------------------------------------------------------------------',
    '',
    '-- Head --',
    'Stacktrace:',
    "\tat com.zonko.quantum.QuantumTicker.tick(QuantumTicker.java:212)",
    '',
    '-- System Details --',
    'Details:',
    "\tMinecraft Version: 1.20.1",
    "\tMinecraft Version ID: 1.20.1",
    "\tOperating System: Windows 10 (amd64) version 10.0",
    "\tJava Version: 17.0.8, Microsoft",
    "\tJava VM Version: OpenJDK 64-Bit Server VM (mixed mode), Microsoft",
    "\tMemory: 2147483648 bytes (2048 MiB) allocated",
    "\tCPU: 8x Intel(R) Xeon(R) CPU E5-2680 v4",
]);

$realParsed = ClientLog::parse($realCrash);
$realMeta = (array) ($realParsed['meta'] ?? []);

ok(($realMeta['minecraft_version'] ?? '') === '1.20.1', '★ 真实报告（Tab 缩进）能提取出游戏版本', '实际：' . var_export($realMeta['minecraft_version'] ?? null, true));
ok(strpos((string) ($realMeta['java'] ?? ''), '17.0.8') !== false, '★ 能提取出 Java 版本', '实际：' . var_export($realMeta['java'] ?? null, true));
ok(strpos((string) ($realMeta['memory'] ?? ''), '2048 MiB') !== false, '★ 能提取出内存设置', '实际：' . var_export($realMeta['memory'] ?? null, true));
ok(strpos((string) ($realMeta['cpu'] ?? ''), 'Xeon') !== false, '★ 能提取出 CPU', '实际：' . var_export($realMeta['cpu'] ?? null, true));
ok(strpos((string) ($realMeta['os'] ?? ''), 'Windows 10') !== false, '★ 能提取出操作系统', '实际：' . var_export($realMeta['os'] ?? null, true));

// 修的时候不能顺手把整行 trim 掉 —— 堆栈行正是靠前导 Tab 认出来的
$realStack = (array) ($realParsed['stack'] ?? []);
$hasAtLine = false;
foreach ($realStack as $stackLine) {
    if (strpos((string) $stackLine, 'QuantumTicker.tick') !== false) {
        $hasAtLine = true;
        break;
    }
}
ok($hasAtLine, '★ 缩进修复没有破坏堆栈行识别', '堆栈条数：' . count($realStack));

// --------------------------------------------------------------------- 自动加载

group('自动加载 —— 命名空间必须和目录对得上');

// 这条守卫是有来历的：src/Panel/PanelAdapter.php 曾经写成 `namespace MCFix;`
// 而它躺在 src/Panel/ 里，于是 autoloader 按路径找 MCFix\Panel\PanelAdapter、
// 加载到的文件却只定义了 MCFix\PanelAdapter —— 结果就是**整个面板 API 功能直接致命错误**。
// 语法检查查不出来（每一行都是合法 PHP），只有真的把类加载一次才会暴露。
$srcRoot = MCFIX_ROOT . '/src';
$srcFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $srcFiles[] = $file->getPathname();
    }
}
sort($srcFiles);

$mismatched = [];
$unloadable = [];

// 加载类时如果撞上致命错误（命名空间不对、签名不兼容、可见性收窄…），
// PHP 会直接终止整个脚本，测试后面的项一个都跑不到。
// 这里登记一个"正在加载谁"，让 shutdown 钩子能把肇事者点出来，
// 否则只会看到一行莫名其妙的 Fatal error，不知道还有多少项没测。
$loadingClass = '(未开始)';
register_shutdown_function(static function () use (&$loadingClass): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    if (!in_array($err['type'], [E_ERROR, E_COMPILE_ERROR, E_PARSE, E_CORE_ERROR], true)) {
        return;
    }

    fwrite(STDERR, PHP_EOL . '✗ 加载 ' . $loadingClass . ' 时发生致命错误，测试已中断：' . PHP_EOL);
    fwrite(STDERR, '    ' . $err['message'] . PHP_EOL);
    fwrite(STDERR, '    ' . $err['file'] . ':' . $err['line'] . PHP_EOL);
    fwrite(STDERR, '    —— 这类错误 php -l 查不出来，只能靠真的把类加载一次。' . PHP_EOL);
});

foreach ($srcFiles as $file) {
    $rel = str_replace('\\', '/', substr($file, strlen($srcRoot) + 1));
    $dir = dirname($rel);
    // bootstrap.php 和 helpers.php 是过程式加载器，故意不声明命名空间
    if ($dir === '.' && in_array($rel, ['bootstrap.php', 'helpers.php'], true)) {
        continue;
    }
    $expectedNs = $dir === '.' ? 'MCFix' : 'MCFix\\' . str_replace('/', '\\', $dir);

    $text = (string) file_get_contents($file);
    if (!preg_match('/^\s*namespace\s+([^;]+);/m', $text, $nsMatch)) {
        $mismatched[] = $rel . '（没有 namespace，应为 ' . $expectedNs . '）';
        continue;
    }
    $actualNs = trim($nsMatch[1]);
    if ($actualNs !== $expectedNs) {
        $mismatched[] = $rel . '（声明 ' . $actualNs . '，按路径应为 ' . $expectedNs . '）';
        continue;
    }

    if (!preg_match('/(?:^|\s)(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(\w+)/m', $text, $clsMatch)) {
        continue; // 只放函数/常量的文件，跳过
    }

    $fqcn = $expectedNs . '\\' . $clsMatch[1];
    // class_exists 会真的走一次 autoloader，这才是能抓住"命名空间写错"的检查
    $loadingClass = $fqcn;
    if (!class_exists($fqcn) && !interface_exists($fqcn) && !trait_exists($fqcn)) {
        $unloadable[] = $fqcn . '（来自 ' . $rel . '）';
    }
}
$loadingClass = '(已扫完)';

ok($mismatched === [], 'src/ 下每个文件的 namespace 都和目录一致', implode("\n      ", $mismatched));
ok($unloadable === [], 'src/ 下的类都能通过 autoloader 真正加载出来', implode("\n      ", $unloadable));
ok(count($srcFiles) >= 40, '扫描到了足够多的源码文件（防止上面的检查被空转）', '实际：' . count($srcFiles));

// 面板适配器是重灾区，单独再点名确认一遍
foreach (['mcsmanager' => 'McsManagerPanel', 'pterodactyl' => 'PterodactylPanel', 'multicraft' => 'MulticraftPanel', 'bt' => 'BtPanel', 'custom' => 'CustomPanel'] as $type => $class) {
    $fqcn = 'MCFix\\Panel\\' . $class;
    ok(class_exists($fqcn), '面板适配器可加载：' . $type);
}
ok(class_exists('MCFix\\Panel\\PanelAdapter'), '面板适配器基类 MCFix\\Panel\\PanelAdapter 可加载');

// 四个适配器都要能真的 new 出来（构造里会读配置，配置不全时 capabilities() 应为空）
foreach ([
    'mcsmanager'  => 'MCFix\\Panel\\McsManagerPanel',
    'pterodactyl' => 'MCFix\\Panel\\PterodactylPanel',
    'multicraft'  => 'MCFix\\Panel\\MulticraftPanel',
    'bt'          => 'MCFix\\Panel\\BtPanel',
    'custom'      => 'MCFix\\Panel\\CustomPanel',
] as $type => $fqcn) {
    $panel = ['type' => $type, 'api_url' => '', 'api_key' => ''];
    $server = ['id' => 'unit', 'host' => '127.0.0.1', 'port' => 25565, 'mc_dir' => '/srv/mc', 'log_path' => 'logs/latest.log'];
    try {
        /** @var \MCFix\Panel\PanelAdapter $instance */
        $instance = new $fqcn($panel, $server);
        ok(
            $instance->capabilities() === [],
            $type . ' 在没填 api_url / api_key 时能力为空（不会假装能用）',
            '实际：' . implode('、', $instance->capabilities())
        );
    } catch (\Throwable $e) {
        ok(false, $type . ' 能实例化', $e->getMessage());
    }
}

// PanelRegistry 是上层唯一入口，走一遍真实选路
ok(\MCFix\PanelRegistry::adapter(['id' => 'unit', 'panel' => ['type' => 'mcsmanager', 'api_url' => 'http://127.0.0.1:1', 'api_key' => 'k', 'instance_id' => 'i', 'daemon_id' => 'd']]) !== null, '配好参数的 MCSManager 能构造出适配器');
ok(\MCFix\PanelRegistry::adapter(['id' => 'unit', 'panel' => ['type' => 'none']]) === null, 'type=none 时不构造适配器');

// ---- Multicraft ----
//
// 它是唯一需要**三个**凭据的面板（地址 + 用户名 + 密钥），因为签名要用到用户名。
$mcServer = ['id' => 'unit', 'host' => '127.0.0.1', 'port' => 25565, 'mc_dir' => '/srv/mc', 'log_path' => 'logs/latest.log'];
$mcBase = ['type' => 'multicraft', 'api_url' => 'https://panel.example.com/api.php', 'server_id' => '1'];

$mcNoUser = new \MCFix\Panel\MulticraftPanel($mcBase + ['api_key' => 'k'], $mcServer);
ok($mcNoUser->capabilities() === [], '★ Multicraft 少了用户名就不报能力（签名要用它）');

$mcNoKey = new \MCFix\Panel\MulticraftPanel($mcBase + ['username' => 'u'], $mcServer);
ok($mcNoKey->capabilities() === [], 'Multicraft 少了密钥不报能力');

$mcFull = new \MCFix\Panel\MulticraftPanel($mcBase + ['api_key' => 'k', 'username' => 'u'], $mcServer);
$mcCaps = $mcFull->capabilities();
ok($mcCaps !== [], '★ 三个凭据齐了才报能力', '实际：' . implode('、', $mcCaps));

// 能力矩阵必须如实：Multicraft 的 API **没有文件接口**
ok(in_array('console', $mcCaps, true), 'Multicraft 报了下发指令');
ok(in_array('power', $mcCaps, true), 'Multicraft 报了开关机');
ok(in_array('read_log', $mcCaps, true), 'Multicraft 报了读日志');
ok(!in_array('read_file', $mcCaps, true), '★ Multicraft 不报 read_file（API 没有文件接口，不假装支持）');
ok(!in_array('list_dir', $mcCaps, true), '★ Multicraft 不报 list_dir（同上）');

ok(isset(\MCFix\PanelRegistry::types()['multicraft']), '★ PanelRegistry 注册了 multicraft 类型');
ok(
    \MCFix\PanelRegistry::adapter(['id' => 'unit', 'panel' => $mcBase + ['api_key' => 'k', 'username' => 'u']]) !== null,
    '配好三个凭据的 Multicraft 能构造出适配器'
);

// ---- 指令回显闸门：不是所有面板都能拿来做"要解析输出"的检查 ----
//
// 背景：诊断有五项检查（tps / list / whitelist list / banlist）要**解析指令回显**
// 才能得出结论。原来它们写死 Rcon，没开 RCON 就直接跳过 —— 哪怕这台服的面板本来
// 就能下发指令（Executor 里那句"（可考虑用面板控制台通道）"就是这么来的）。
//
// 但面板**不是** RCON 的等价替代：翼龙 / Multicraft / MCSManager 的指令接口只管
// 投递、不返回控制台输出（返回的是"已投递到控制台"这类提示）。拿它去跑
// preg_match 抠 TPS，抠出来的可能是提示文字里的数字 —— 比"跳过"更糟，因为
// 它看起来有结论。所以单独加一个默认 false 的能力位 commandEcho()。
$echoPanelServer = ['id' => 'unit', 'name' => 'unit', 'executor' => 'panel'];

$ptPanel = \MCFix\PanelRegistry::adapter($echoPanelServer + ['panel' => ['type' => 'pterodactyl', 'api_url' => 'https://p.example.com', 'api_key' => 'ptlc_x', 'server_id' => 'abc']]);
ok($ptPanel !== null, '翼龙适配器能构造');
ok($ptPanel->commandEcho() === false,
    '★★ 翼龙不声明能回显（它的 command 接口只投递，回显要靠 readLog）');

$mcsPanel = \MCFix\PanelRegistry::adapter($echoPanelServer + ['panel' => ['type' => 'mcsmanager', 'api_url' => 'http://127.0.0.1:1', 'api_key' => 'k', 'instance_id' => 'i', 'daemon_id' => 'd']]);
ok($mcsPanel !== null && $mcsPanel->commandEcho() === false,
    '★★ MCSManager 不声明能回显（面板接口明确不回显）');

$mcEchoPanel = \MCFix\PanelRegistry::adapter($echoPanelServer + ['panel' => $mcBase + ['api_key' => 'k', 'username' => 'u']]);
ok($mcEchoPanel !== null && $mcEchoPanel->commandEcho() === false,
    '★★ Multicraft 不声明能回显（sendCommand 只回"已下发指令"）');

$btPanel = \MCFix\PanelRegistry::adapter($echoPanelServer + ['panel' => ['type' => 'bt', 'api_url' => 'https://bt.example.com', 'api_key' => 'k']]);
if ($btPanel !== null) {
    ok($btPanel->commandEcho() === false, '★★ 宝塔不声明能回显（它根本没有控制台接口）');
}

// 自定义接口：只有**明确写了 output_path** 才算能回显
$customNoPath = \MCFix\PanelRegistry::adapter($echoPanelServer + ['panel' => [
    'type' => 'custom', 'api_url' => 'http://127.0.0.1:8080/api/console',
]]);
ok($customNoPath !== null, '自定义接口配了 api_url 就能构造');
ok($customNoPath->commandEcho() === false,
    '★★ 自定义接口没写 output_path 时不算能回显（否则会从 message 字段猜出"success"当回显）');

$customWithPath = \MCFix\PanelRegistry::adapter($echoPanelServer + ['panel' => [
    'type' => 'custom', 'api_url' => 'http://127.0.0.1:8080/api/console', 'output_path' => 'data.output',
]]);
ok($customWithPath !== null && $customWithPath->commandEcho() === true,
    '★★ 写了 output_path 才允许把它用于需要解析输出的检查');

// 闸门本身：不回显的面板拿不到检查用的通道
$noRcon = ['id' => 'unit', 'name' => 'unit', 'host' => '127.0.0.1', 'port' => 25565, 'executor' => 'panel'];
ok(\MCFix\CommandChannel::fromPanelOnly($noRcon + ['panel' => ['type' => 'pterodactyl', 'api_url' => 'https://p.example.com', 'api_key' => 'ptlc_x', 'server_id' => 'abc']]) === null,
    '★★ 翼龙拿不到检查通道（会被挡在 fromPanelOnly 外面）');
ok(\MCFix\CommandChannel::fromPanelOnly($noRcon + ['panel' => ['type' => 'custom', 'api_url' => 'http://x/y', 'output_path' => 'data.output']]) !== null,
    '★ 能回显的自定义接口可以拿到检查通道');
ok(\MCFix\CommandChannel::fromPanelOnly($noRcon + ['panel' => ['type' => 'none']]) === null,
    '没有面板时拿不到通道');

// 有 RCON 时优先走 RCON（面板不该抢）
$withRcon = $noRcon + [
    'rcon'  => ['enabled' => true, 'host' => '127.0.0.1', 'port' => 25575, 'password' => 'pw'],
    'panel' => ['type' => 'custom', 'api_url' => 'http://x/y', 'output_path' => 'data.output'],
];
$ch = \MCFix\CommandChannel::forServer($withRcon);
ok($ch !== null && $ch->label() === 'RCON',
    '★★ 配了 RCON 时优先用 RCON，不被面板顶掉', $ch !== null ? '实际：' . $ch->label() : '实际：null');

// 没 RCON 但面板能回显 → 退回面板
$chPanel = \MCFix\CommandChannel::forServer($noRcon + ['panel' => ['type' => 'custom', 'api_url' => 'http://x/y', 'output_path' => 'data.output']]);
ok($chPanel !== null && strpos($chPanel->label(), '控制台') !== false,
    '★★ 没 RCON 时退回面板控制台通道', $chPanel !== null ? '实际：' . $chPanel->label() : '实际：null');

// 两者都没有 → null（上层据此说"跳过"，而不是编一个结论）
ok(\MCFix\CommandChannel::forServer($noRcon + ['panel' => ['type' => 'pterodactyl', 'api_url' => 'https://p.example.com', 'api_key' => 'ptlc_x', 'server_id' => 'abc']]) === null,
    '★★ 都不行时返回 null（上层报"跳过"，不会拿"已投递"当回显去解析）');

// 签名算法：这是整个适配器**最容易静默写错**的地方 —— 拼错了只会收到一句
// 含糊的认证失败，看不出是哪一步。所以在这里把规格独立复述一遍钉住它。
//
// 规格（来自官方客户端的 core/base.py）：
//   message = 把每个参数的「键名 + 值」依次拼接（键名也要拼，无分隔符）
//   签名     = hash_hmac('sha256', message, api_key)
//
// 注意：网上流传的一个 2013 年单文件客户端用的是 md5(key + method + user + 只有值)，
// 那是错的或已过期 —— 少了键名、且摘要算法不同。
$sigMethod = new \ReflectionMethod(\MCFix\Panel\MulticraftPanel::class, 'signature');
$sigMethod->setAccessible(true);
$mcForSig = new \MCFix\Panel\MulticraftPanel($mcBase + ['api_key' => 'secret-key', 'username' => 'u'], $mcServer);

$params = ['server_id' => '1', 'command' => 'say hi', '_MulticraftAPIMethod' => 'sendConsoleCommand', '_MulticraftAPIUser' => 'u'];
$expected = hash_hmac('sha256', 'server_id1commandsay hi_MulticraftAPIMethodsendConsoleCommand_MulticraftAPIUseru', 'secret-key');
ok(
    $sigMethod->invoke($mcForSig, $params) === $expected,
    '★ 签名规格：键名参与拼接 + HMAC-SHA256',
    '实际：' . $sigMethod->invoke($mcForSig, $params)
);
// 反证：如果哪天有人照网上那份旧实现改成 md5 或去掉键名，上面那条就会红
ok(
    $sigMethod->invoke($mcForSig, $params) !== md5('secret-keysendConsoleCommandu1say hi'),
    '★ 不是那份过期的 md5 实现'
);
// 参数顺序有意义（签名按顺序拼），换顺序必须换签名
$reordered = ['command' => 'say hi', 'server_id' => '1', '_MulticraftAPIMethod' => 'sendConsoleCommand', '_MulticraftAPIUser' => 'u'];
ok(
    $sigMethod->invoke($mcForSig, $reordered) !== $sigMethod->invoke($mcForSig, $params),
    '★ 参数顺序会改变签名（所以不能依赖哈希表遍历序）'
);

// --------------------------------------------------------------------- 覆盖可见性

group('继承关系 —— 子类不能把父类方法的可见性收窄');

// 这条同样有来历：PterodactylPanel 曾把 endpoint() 声明成 private，
// 而父类 PanelAdapter::endpoint() 是 protected。PHP 遇到这种情况**在加载类时**就致命错误，
// 于是"翼龙面板"这一整条通路直接不可用 —— 语法检查同样查不出来。
$rank = ['public' => 0, 'protected' => 1, 'private' => 2];
$violations = [];
$pairs = [
    'MCFix\\Panel\\PanelAdapter' => ['McsManagerPanel', 'PterodactylPanel', 'BtPanel', 'CustomPanel'],
    'MCFix\\Channel\\ChannelAdapter' => ['BarkChannel', 'DingTalkChannel', 'DiscordChannel', 'FeishuChannel', 'MailChannel', 'NtfyChannel', 'ServerChanChannel', 'TelegramChannel', 'WeComChannel', 'WebhookChannel'],
];

foreach ($pairs as $parentClass => $children) {
    if (!class_exists($parentClass)) {
        $violations[] = '父类不存在：' . $parentClass;
        continue;
    }
    $parentMethods = [];
    foreach ((new ReflectionClass($parentClass))->getMethods() as $method) {
        $parentMethods[$method->getName()] = $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private');
    }

    $ns = substr($parentClass, 0, (int) strrpos($parentClass, '\\'));
    foreach ($children as $child) {
        $fqcn = $ns . '\\' . $child;
        if (!class_exists($fqcn)) {
            $violations[] = '子类加载失败：' . $fqcn;
            continue;
        }
        foreach ((new ReflectionClass($fqcn))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $fqcn) {
                continue; // 不是自己声明的，跳过
            }
            $name = $method->getName();
            if (!isset($parentMethods[$name])) {
                continue;
            }
            $childVis = $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private');
            if ($rank[$childVis] > $rank[$parentMethods[$name]]) {
                $violations[] = $child . '::' . $name . '() 是 ' . $childVis . '，父类是 ' . $parentMethods[$name];
            }
        }
    }
}

ok($violations === [], '面板与通知适配器都没有收窄父类方法的可见性', implode("\n      ", $violations));

// --------------------------------------------------------------------- 判定器

group('Verdict —— 连不上 MC 端口时的结论要分得清');

$verdictServer = [
    'id' => 'unit', 'host' => '127.0.0.1', 'port' => 25565,
    'executor' => 'none', 'rcon' => ['enabled' => false],
    'panel' => ['type' => 'pterodactyl', 'api_url' => 'https://p.example.com', 'api_key' => 'ptlc_x', 'server_id' => 'abc'],
];

// 场景一：MC 端口连不上，而且没有任何反证 → 应该判"服务端离线"，并建议重启
$offline = \MCFix\Verdict::evaluate($verdictServer, 'cannot_join', [
    'mc_status' => ['status' => 'fail', 'message' => '按 Minecraft 协议连接失败：Connection refused', 'data' => []],
]);
ok(($offline['issue'] ?? '') === 'server_offline', '没有任何反证时判「服务端离线」', '实际：' . (string) ($offline['issue'] ?? ''));

// 场景二：面板的资源接口说进程在跑 → 不能判"离线"，应该判"本机连不上"
// 这是面板服最常见的误判：地址填错 / 只开了 SRV 域名 / 端口没放行，
// 结果系统去重启一台好好的机器。
$unreachable = \MCFix\Verdict::evaluate($verdictServer, 'cannot_join', [
    'mc_status'    => ['status' => 'fail', 'message' => '按 Minecraft 协议连接失败：Connection refused', 'data' => []],
    'process_info' => ['status' => 'pass', 'message' => '面板读数：状态 running，CPU 3.1%', 'data' => []],
]);
ok(($unreachable['issue'] ?? '') === 'mc_unreachable', '有反证时改判「本机连不上 MC 端口」', '实际：' . (string) ($unreachable['issue'] ?? ''));
ok(($unreachable['severity'] ?? '') === 'high', '改判后严重度降到 high（不再当成服务端挂了）');
ok(empty($unreachable['suggestions']), '改判后不会再去重启一台好机器', '实际建议：' . json_encode($unreachable['suggestions'] ?? [], JSON_UNESCAPED_UNICODE));
ok(!empty($unreachable['needs_manual']), '改判后归为人工处理（网络/地址配置问题系统修不了）');

// 场景三：Agent 说进程在 → 同样不能判"离线"
$unreachable2 = \MCFix\Verdict::evaluate($verdictServer, 'cannot_join', [
    'mc_status' => ['status' => 'fail', 'message' => '连接超时', 'data' => []],
    'process'   => ['status' => 'pass', 'message' => '进程 java 存在（PID 1234）', 'data' => []],
]);
ok(($unreachable2['issue'] ?? '') === 'mc_unreachable', 'Agent 确认进程存在时也改判');

// 场景四：一切正常时不应该硬编一个问题出来
$clean = \MCFix\Verdict::evaluate($verdictServer, 'cannot_join', [
    'mc_status' => ['status' => 'pass', 'message' => '握手成功', 'data' => ['players' => 3, 'max_players' => 100]],
    'logs'      => ['status' => 'pass', 'message' => '日志尾部没有异常', 'data' => []],
]);
ok(($clean['issue'] ?? '') === 'no_problem_detected', '全绿时如实说「没验证到服务器侧异常」', '实际：' . (string) ($clean['issue'] ?? ''));

// --------------------------------------------------------------------- 密钥掩码

group('redact_paths —— 玩家侧不该看到服务端目录结构');

ok(redact_paths('/home/steve/minecraft/logs/latest.log') === '…/latest.log', '★ 绝对路径只留文件名', '实际：' . redact_paths('/home/steve/minecraft/logs/latest.log'));
ok(redact_paths('/opt/mc/server/world/level.dat') === '…/level.dat', '深层路径同样处理');
ok(
    strpos(redact_paths('日志文件不存在或不可读：/home/steve/mc/logs/latest.log'), 'steve') === false,
    '★ 服务端用户名不再出现在玩家可见文本里'
);
ok(redact_paths('http://example.com/a/b') === 'http://example.com/a/b', 'HTTP 链接不被误伤', '实际：' . redact_paths('http://example.com/a/b'));
ok(redact_paths('ls -la') === 'ls -la', '普通文本原样返回');
ok(redact_paths('') === '', '空串不炸');
ok(redact_paths('中文说明，没有路径') === '中文说明，没有路径', '中文文本不受影响');

group('mask_secret_url —— 密钥不能进日志/事件表');

// 断言分两层：
//   1. 必须——原文里的密钥片段一个都不能出现在结果里；
//   2. 够用——结果里要留下可辨认的前缀（否则排障时不知道是哪条请求）。
// 不写死"前几位"，因为不同形态保留的长度不同，写死只会让测试变脆。
$maskInputs = [
    'https://panel.example.com/api/overview?apikey=sk_live_abcdef123456',
    'https://panel.example.com/api/x?key=SEC0001&other=1',
    'https://bt.example.com/system?action=GetSystemTotal&request_token=abc123def456&request_sign=deadbeefcafe',
    'https://api.telegram.org/bot123456789:AAFakeTokenHere/sendMessage',
    'https://ntfy.sh/mytopic?token=tk_abcdefghijkl',
];
foreach ($maskInputs as $input) {
    $masked = mask_secret_url($input);
    $leaks = [];

    // 查询串里的密钥值
    if (preg_match_all('/[?&](?:apikey|api_key|key|token|secret|request_token|request_sign)=([^&\s]+)/i', $input, $m)) {
        foreach ($m[1] as $secret) {
            if (strlen($secret) >= 4 && strpos($masked, $secret) !== false) {
                $leaks[] = '查询串密钥 ' . $secret . ' 仍可见';
            }
        }
    }
    // Telegram 路径令牌
    if (preg_match('#/bot\d+:([A-Za-z0-9_\-]+)#', $input, $m) && strpos($masked, $m[1]) !== false) {
        $leaks[] = 'Telegram 令牌仍可见';
    }

    ok($leaks === [], '密钥已抹掉：' . mb_substr($input, 0, 52), implode('；', $leaks) . ' → ' . $masked);
    ok(strpos($masked, '***') !== false, '留下 *** 标记便于辨认：' . mb_substr($input, 0, 40), '实际：' . $masked);
    ok(strpos($masked, 'example.com') !== false || strpos($masked, 'telegram.org') !== false || strpos($masked, 'ntfy.sh') !== false, '主机名仍然保留（排障要知道打给谁）', '实际：' . $masked);
}

// 没有密钥的普通地址不该被改坏
ok(mask_secret_url('https://panel.example.com/api/client/servers/abc123') === 'https://panel.example.com/api/client/servers/abc123', '普通地址原样保留');
ok(mask_secret_url('http://127.0.0.1:23333/api/files/list?target=/logs') === 'http://127.0.0.1:23333/api/files/list?target=/logs', '本地地址与普通参数不受影响');

// 面板适配器必须走同一个掩码函数（否则两边会各自漏掉一种形态）
$reflection = new ReflectionMethod('MCFix\\Panel\\PanelAdapter', 'note');
ok($reflection->isProtected(), 'PanelAdapter::note() 仍是 protected（子类都靠它记轨迹）');
$panelSource = (string) file_get_contents(MCFIX_ROOT . '/src/Panel/PanelAdapter.php');
ok(strpos($panelSource, 'mask_secret_url') !== false, 'PanelAdapter 记轨迹时会调用 mask_secret_url');
$channelSource = (string) file_get_contents(MCFIX_ROOT . '/src/Channel/ChannelAdapter.php');
ok(strpos($channelSource, 'mask_secret_url') !== false, '通知渠道也复用同一个掩码函数');

// --------------------------------------------------------------------- 类引用

group('类引用 —— 无命名空间的文件里必须 use 过才能用短名');

// 这条也有来历：bin/mcfix.php 里写了 TicketMail::drain()，但文件顶部没有
// use MCFix\TicketMail;。PHP 会把它解析成全局的 \TicketMail，于是 cron 一跑到那里就
// "Class not found" —— 而 php -l 完全看不出来，因为语法本身是对的。
// 这类错误只在真正执行到那一行时才炸，是最难在部署前发现的一种。
$mcfixClasses = [];
foreach ($srcFiles as $file) {
    $text = (string) file_get_contents($file);
    if (preg_match('/(?:^|\s)(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(\w+)/m', $text, $m)) {
        $mcfixClasses[$m[1]] = true;
    }
}

// 无命名空间的入口/视图文件（这些文件里的短类名会解析到全局）
$globalScopeFiles = [];
foreach ([
    MCFIX_ROOT . '/bin',
    MCFIX_ROOT . '/public/controllers',
    MCFIX_ROOT . '/admin',
    MCFIX_ROOT . '/views',
] as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    $it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it2 as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $globalScopeFiles[] = $f->getPathname();
        }
    }
}
$globalScopeFiles[] = MCFIX_ROOT . '/public/index.php';

// PHP 自带的、可以直接用短名的类
$builtinOk = array_flip([
    'PDO', 'PDOException', 'Exception', 'Error', 'Throwable', 'Closure', 'Generator',
    'DateTime', 'DateTimeZone', 'DateInterval', 'ArrayObject', 'ArrayIterator', 'stdClass',
    'ReflectionClass', 'ReflectionMethod', 'ReflectionFunction',
    'RecursiveIteratorIterator', 'RecursiveDirectoryIterator', 'FilesystemIterator',
    'SplFileObject', 'SplFileInfo', 'DirectoryIterator', 'LimitIterator', 'RegexIterator',
    'self', 'static', 'parent',
]);

$badRefs = [];
foreach ($globalScopeFiles as $file) {
    $rel = str_replace('\\', '/', substr($file, strlen(MCFIX_ROOT) + 1));
    $text = (string) file_get_contents($file);

    // 这个文件里 use 进来的短名
    $imported = [];
    if (preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $text, $uses, PREG_SET_ORDER)) {
        foreach ($uses as $u) {
            $parts = explode('\\', $u[1]);
            $imported[isset($u[2]) ? $u[2] : end($parts)] = true;
        }
    }
    // 文件自己声明的函数/类不算
    $imported['MCFix'] = true;

    // 扫之前先去掉注释：注释里写 `TicketMail::purgePlayerEmail` 举例说明是常事，
    // 那不是代码引用。之前就被自己的注释绊过一次（断言被散文触发），
    // 行号仍按原文计算，所以先把长度对齐。
    $code = (string) preg_replace_callback(
        '#(//[^\n]*|/\*.*?\*/)#s',
        static function (array $m): string {
            // 保留换行、其余字符换成等长空格，这样偏移量和行号都不变
            return (string) preg_replace('/[^\n]/', ' ', $m[1]);
        },
        $text
    );

    if (preg_match_all('/(?<![\w\\\\$>:])([A-Z]\w*)::(?!:)/', $code, $refs, PREG_OFFSET_CAPTURE)) {
        foreach ($refs[1] as $ref) {
            $name = $ref[0];
            if (isset($imported[$name]) || isset($builtinOk[$name])) {
                continue;
            }
            // 只有"确实存在同名 MCFix 类"才报 —— 说明作者想用的就是这个，只是忘了 use
            if (isset($mcfixClasses[$name])) {
                $line = substr_count(substr($text, 0, (int) $ref[1]), "\n") + 1;
                $badRefs[] = $rel . ':' . $line . ' 用了 ' . $name . ':: 但没有 use MCFix\\' . $name;
            }
        }
    }
}

ok($badRefs === [], '无命名空间的文件里没有"忘了 use"的类引用', implode("\n      ", array_unique($badRefs)));
ok(count($globalScopeFiles) >= 10, '扫描到了足够多的无命名空间文件（防止检查空转）', '实际：' . count($globalScopeFiles));

// --------------------------------------------------------------------- PHP 版本

group('PHP 版本 —— 代码里不能出现 8.0+ 才有的语法');

// README 承诺支持 PHP 7.4，而 CI 只在 8.3 上跑真实语法检查 ——
// 7.4 那一列只能抓语法错误，抓不到"用了新函数"。这里做一遍静态扫描补上。
// 宁可漏报也别误报：下面每条都挑的是不会出现在注释/字符串里的明确写法。
$newerSyntax = [
    'match 表达式（8.0+）'        => '/\bmatch\s*\([^;]{0,200}?\)\s*\{/s',
    'nullsafe 操作符 ?->（8.0+）' => '/\?->/',
    'str_contains()（8.0+）'      => '/(?<![\w>$])str_contains\s*\(/',
    'str_starts_with()（8.0+）'   => '/(?<![\w>$])str_starts_with\s*\(/',
    'str_ends_with()（8.0+）'     => '/(?<![\w>$])str_ends_with\s*\(/',
    'array_is_list()（8.1+）'     => '/(?<![\w>$])array_is_list\s*\(/',
    'enum 声明（8.1+）'           => '/^\s*enum\s+[A-Z]\w*\s*(:|\{)/m',
    'readonly 属性（8.1+）'       => '/^\s*(public|protected|private)\s+readonly\s/m',
    '构造器属性提升（8.0+）'      => '/function\s+__construct\s*\([^)]*\b(public|protected|private)\s+(\??\w+\s+)?\$/s',
];

$versionHits = [];
foreach ($srcFiles as $file) {
    $rel = str_replace('\\', '/', substr($file, strlen($srcRoot) + 1));
    $text = (string) file_get_contents($file);

    foreach ($newerSyntax as $label => $pattern) {
        if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            $line = substr_count(substr($text, 0, (int) $m[0][1]), "\n") + 1;
            $versionHits[] = $rel . ':' . $line . ' 用了 ' . $label;
        }
    }
}

// bin/ 和 agent/ 也在承诺范围内，一并扫
foreach ([MCFIX_ROOT . '/bin/mcfix.php', MCFIX_ROOT . '/agent/mcfix-agent.php'] as $extra) {
    $text = (string) file_get_contents($extra);
    foreach ($newerSyntax as $label => $pattern) {
        if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            $line = substr_count(substr($text, 0, (int) $m[0][1]), "\n") + 1;
            $versionHits[] = basename($extra) . ':' . $line . ' 用了 ' . $label;
        }
    }
}

ok($versionHits === [], '源码里没有 PHP 8.0+ 专属语法（README 承诺支持 7.4）', implode("\n      ", $versionHits));

// --------------------------------------------------------------------- 邮件模块

group('TicketMail —— 玩家邮箱只用于发信，结束后必须抹掉');

ok(\MCFix\TicketMail::maskAddress('abcd@qq.com') === 'ab***@qq.com', '邮箱掩码保留前两位与域名', '实际：' . \MCFix\TicketMail::maskAddress('abcd@qq.com'));
ok(\MCFix\TicketMail::maskAddress('a@qq.com') === 'a***@qq.com', '很短的本地部分也能掩码', '实际：' . \MCFix\TicketMail::maskAddress('a@qq.com'));
ok(\MCFix\TicketMail::maskAddress('') === '', '空地址不炸');
ok(\MCFix\TicketMail::maskAddress('不是邮箱') === '***', '不是邮箱时给 ***，不原样返回');

$text = '我的 QQ 是 123456，邮箱 abc.def+tag@qq.com，也可以发到 x@163.com';
$masked = \MCFix\TicketMail::maskEmailsInText($text);
ok(strpos($masked, 'abc.def+tag@qq.com') === false, '自由文本里的邮箱被抹掉', '实际：' . $masked);
ok(strpos($masked, 'x@163.com') === false, '第二个邮箱也被抹掉');
ok(strpos($masked, '123456') !== false, 'QQ 号（不是邮箱）保持原样');
ok(strpos($masked, 'ab***@qq.com') !== false, '掩码格式符合预期');

ok(\MCFix\TicketMail::isEmail('a@b.com'), '合法邮箱通过校验');
ok(!\MCFix\TicketMail::isEmail('a@b'), '没有顶级域的被拒');
ok(!\MCFix\TicketMail::isEmail('a b@c.com'), '带空格被拒');
ok(!\MCFix\TicketMail::isEmail(''), '空串被拒');

// 默认必须是关的：不能让升级后的站点突然开始往外发信
$defaults = \MCFix\TicketMail::settings();
ok(empty($defaults['enabled']), '邮件模块默认关闭（不能升级完就偷偷发信）');
ok(!empty($defaults['purge_email']), '邮箱清理默认开启');
ok(\MCFix\TicketMail::enabled() === false, '未开启时 enabled() 为 false');

// 发件人兜底：没配 from 就用站点域名的 no-reply
$from = \MCFix\TicketMail::fromAddress();
ok(strpos($from, '@') !== false, '能推出一个发件人地址：' . $from);

// --------------------------------------------------------------------- 开关语义

group('后台复选框 —— 默认值为 true 的开关必须能被关掉');

// 这是个真实踩过的坑：浏览器**不会**提交没有勾选的复选框，所以
// bool_param($input, 'x', true) 在"没勾"的时候会拿到默认值 true ——
// 「允许自动执行修复」「重启需批准」「逐条禁用修复配方」这些开关**根本关不掉**，
// 界面上的复选框形同虚设（配方白名单那条尤其要命，它是安全边界）。
// 修法是在复选框前面加一个同名的 value="0" 隐藏字段。
// 这个测试就是盯着"每个默认 true 的开关都能找到对应的隐藏字段"。
ok(bool_param(['x' => '0'], 'x', true) === false, 'bool_param 认得 value="0"');
ok(bool_param([], 'x', true) === true, 'bool_param 在字段缺失时用默认值（所以才需要隐藏字段）');

// 把后台表单里的 PHP 片段剥掉，只留 HTML 骨架
$formSources = '';
foreach ([
    MCFIX_ROOT . '/admin',
    MCFIX_ROOT . '/views',
] as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    $it3 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it3 as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $formSources .= (string) file_get_contents($f->getPathname()) . "\n";
        }
    }
}
$formSkeleton = (string) preg_replace('/<\?(?:php|=).*?\?>/s', '', $formSources);

// 找出所有"默认 true"的布尔开关（含 'recipe_' . $code 这种拼接写法）
//
// 注意：这些调用分布在**两个文件**里 —— admin.php 是入口与分发，
// admin_actions.php 是动作实现（1.11.0 从 admin.php 拆出去的）。
// 只读其中一个会让下面几条"防止检查空转"的断言失效，所以两个都读。
$adminSourceText = (string) file_get_contents(MCFIX_ROOT . '/public/controllers/admin.php')
    . "\n" . (string) file_get_contents(MCFIX_ROOT . '/public/controllers/admin_actions.php');
$truthySwitches = [];
if (preg_match_all(
    '/bool_param\(\s*\$input\s*,\s*\'([^\']+)\'[^,]*,\s*true\s*\)/',
    $adminSourceText,
    $bp
)) {
    foreach ($bp[1] as $name) {
        $truthySwitches[$name] = true;
    }
}
$truthySwitches = array_keys($truthySwitches);

$missingHidden = [];
foreach ($truthySwitches as $name) {
    // 形如 name="auto_fix" value="0"；拼接出来的会变成 name="recipe_" value="0"
    if (strpos($formSkeleton, 'name="' . $name . '" value="0"') === false) {
        $missingHidden[] = $name;
    }
}

ok($missingHidden === [], '每个默认 true 的后台开关都有配对的隐藏字段（否则关不掉）', implode('、', $missingHidden));

// 这几个是重点：前两个是安全开关，recipe_* 是配方白名单（安全边界）
foreach (['auto_fix', 'require_approval_for_restart', 'recipe_', 'email_purge'] as $must) {
    $found = in_array($must, $truthySwitches, true) || in_array(rtrim($must, '_'), $truthySwitches, true);
    ok($found, '扫到了关键开关：' . $must . '（防止检查空转）', '实际扫到：' . implode('、', $truthySwitches));
}
ok(count($truthySwitches) >= 4, '默认 true 的开关数量合理', '实际：' . count($truthySwitches) . ' 个 → ' . implode('、', $truthySwitches));

// 域名配置：一次不带 domains 字段的保存，不能把双域名模式清掉
// （admin_save_settings 在 admin_actions.php 里，两个文件都读）
$adminSource = (string) file_get_contents(MCFIX_ROOT . '/public/controllers/admin.php')
    . "\n" . (string) file_get_contents(MCFIX_ROOT . '/public/controllers/admin_actions.php');
ok(
    strpos($adminSource, "array_key_exists('console_host', \$input)") !== false,
    '保存设置时，没提交域名字段就不覆盖（避免一次局部 POST 把后台域名冲掉）'
);

// --------------------------------------------------------------------- 大模型兜底

group('AiAdvisor —— 默认关闭、发前脱敏、永不碰执行链');

// 默认必须是关的：升级完不能突然开始往外发玩家日志
$aiDefaults = \MCFix\AiAdvisor::settings();
ok(empty($aiDefaults['enabled']), '大模型兜底默认关闭');
ok(\MCFix\AiAdvisor::enabled() === false, '没配 base_url / model 时 enabled() 为 false');
ok((int) $aiDefaults['timeout'] <= 10, '默认超时不超过 10 秒（玩家在页面上等着）');

// 脱敏：崩溃日志里到处都是这些东西，发出去之前必须换掉
$dirty = implode("\n", [
    '---- Minecraft Crash Report ----',
    '// Oh dear',
    'Time: 2026/01/02 03:04:05',
    'at C:\\Users\\zhangsan\\AppData\\Roaming\\.minecraft\\mods\\mymod.jar',
    'Minecraft Version: 1.20.1',
    'Loaded 156 mods',
    'java.lang.RuntimeException: failed',
    '  at com.example.mymod.MyMod.init(MyMod.java:42)',
    'Server address: mc.example.com/203.0.113.77:25565',
    'Contact: zhangsan@qq.com',
    'accessToken:"eyJhbGciOiJIUzI1NiJ9.abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGH"',
    'home: /home/zhangsan/.minecraft/logs/latest.log',
]);
$clean = \MCFix\AiAdvisor::redact($dirty);

$mustBeGone = [
    'zhangsan'                       => 'Windows 用户名',
    '203.0.113.77'                   => 'IP 地址',
    'zhangsan@qq.com'                => '邮箱',
    '/home/zhangsan'                 => 'Linux 家目录',
    'eyJhbGciOiJIUzI1NiJ9.abc'       => '启动器令牌',
];
foreach ($mustBeGone as $secret => $label) {
    ok(strpos($clean, $secret) === false, '脱敏去掉了' . $label, '仍然含有：' . $secret);
}

// 但技术信息不能一起抹掉，否则模型没东西可分析
foreach (['Minecraft Crash Report', '1.20.1', 'com.example.mymod', 'MyMod.java:42'] as $keep) {
    ok(strpos($clean, $keep) !== false, '脱敏保留了技术细节：' . $keep);
}

ok(\MCFix\AiAdvisor::redact('') === '', '空输入不炸');

// 指纹：同一份日志要稳定，不同日志要不同，且不能带每次都变的东西
$logA = ClientLog::parse("---- Minecraft Crash Report ----\njava.lang.RuntimeException: boom\n  at com.foo.Bar.baz(Bar.java:11)\n");
$logB = ClientLog::parse("---- Minecraft Crash Report ----\njava.lang.RuntimeException: boom\n  at com.foo.Bar.baz(Bar.java:11)\n");
$logC = ClientLog::parse("---- Minecraft Crash Report ----\njava.lang.OutOfMemoryError: Java heap space\n");
$fpA = \MCFix\AiAdvisor::fingerprint($logA);
ok($fpA === \MCFix\AiAdvisor::fingerprint($logB), '同样的日志指纹相同（缓存才命得中）');
ok($fpA !== \MCFix\AiAdvisor::fingerprint($logC), '不同的日志指纹不同');

// ---- 最要紧的一条：模型碰不到执行链 ----
ok(\MCFix\ClientIssue::get('ai_suggestion') !== null, '知识库里必须有 ai_suggestion，否则结论会被丢掉');
ok(
    (array) \MCFix\ClientIssue::get('ai_suggestion')['recipes'] === [],
    '★ ai_suggestion 自己不带任何修复配方（模型给的方案永远不会被执行）'
);

$advisorSrc = (string) file_get_contents(MCFIX_ROOT . '/src/ClientAdvisor.php');
ok(
    strpos($advisorSrc, "self::serverFixPlan(\$server, (array) \$def['recipes'])") !== false,
    '★ 配方计划取自知识库（$def），不是模型返回的内容'
);

$aiSrc = (string) file_get_contents(MCFIX_ROOT . '/src/AiAdvisor.php');
ok(strpos($aiSrc, 'CURLOPT_SSL_VERIFYPEER => true') !== false, '调用大模型时校验证书');
ok(strpos($aiSrc, 'CURLOPT_FOLLOWLOCATION => false') !== false, '调用大模型时不跟随跳转（密钥不会被 302 带走）');
ok(strpos($aiSrc, 'Rate::hit') !== false, '有调用闸（防账单失控）');
ok(strpos($aiSrc, 'Cache::put') !== false, '有结果缓存（同一类报错只问一次）');
ok(
    strpos($aiSrc, "'api_key'") !== false && preg_match('/app_log\([^;]*api_key\s*=>/s', $aiSrc) !== 1,
    '日志里不打印 api_key'
);

// ---- 没开大模型时，行为必须和以前完全一样 ----
$noMatchLog = ClientLog::parse(implode("\n", array_merge(
    ['---- Minecraft Crash Report ----', 'Description: Something brand new'],
    array_fill(0, 30, '  at com.who.knows.WhatEver.doThing(WhatEver.java:1)')
)));
ok(\MCFix\AiAdvisor::enabled() === false, '测试环境里大模型是关闭的');
$analysis = \MCFix\ClientAdvisor::evaluate(
    ['id' => 'unit', 'executor' => 'none'],
    $noMatchLog
);
ok(
    in_array((string) $analysis['primary_code'], ['unknown', 'need_log'], true),
    '关闭大模型时，没命中的日志照旧走 unknown/need_log',
    '实际：' . (string) $analysis['primary_code']
);
ok(empty($analysis['ai_used']), '关闭时 ai_used 为 false');

// ------------------------------------------------- 服务器侧会诊（大模型挑动作）
//
// 这一节测的是本次新增能力的**安全边界**，不是"能不能用"：
// 大模型在服务器侧可以指一个动作，但只能从我们给的清单里指，
// 指的编号还要原封不动地再过一遍完整闸门。

group('服务器侧大模型 —— 只能从白名单里挑，不能自己造动作');

$aiServer = [
    'id' => 'unit', 'host' => '127.0.0.1', 'port' => 25565,
    'executor' => 'agent',
    'rcon'   => ['enabled' => true, 'password' => 'x'],
    'panel'  => ['type' => 'pterodactyl', 'api_url' => 'https://p.example.com', 'api_key' => 'ptlc_x', 'server_id' => 'abc'],
];

// 1. 递给模型的清单，每一项都必须是白名单里真实存在的配方
$selectable = \MCFix\Verdict::selectableRecipes($aiServer, 'Steve');
ok($selectable !== [], '有可用通道时，能挑出至少一个动作', '实际：' . json_encode(array_keys($selectable)));
$notWhitelisted = array_diff(array_keys($selectable), array_keys(\MCFix\Recipe::all()));
ok($notWhitelisted === [], '★ 清单里每一项都在配方白名单里', '不在白名单：' . implode('、', $notWhitelisted));

// 2. 参数来源不明的配方根本不进清单 —— 模型连"填什么参数"的机会都没有
ok(!isset($selectable['pull_mod']), '★ 参数来源不明的配方（pull_mod 的 component）不进清单');

// 3. 没填玩家 ID 时，跟玩家绑定的动作全部不可选
$noPlayer = \MCFix\Verdict::selectableRecipes($aiServer, '');
ok(!isset($noPlayer['whitelist_add']), '★ 没有玩家 ID 时，涉及玩家的动作不进清单');
ok(isset($noPlayer['restart_server']), '没有玩家 ID 也不影响无参动作');

// 4. 分类限制要传导到清单里（"举报玩家"这类分类不允许任何自动动作）
$restricted = \MCFix\Verdict::selectableRecipes($aiServer, 'Steve', ['restart_server']);
ok(array_keys($restricted) === ['restart_server'], '★ 分类白名单会收窄可选项', '实际：' . json_encode(array_keys($restricted)));

// 5. suggestOne 是最后一道闸：模型报什么编号都得在这里过检
ok(\MCFix\Verdict::suggestOne($aiServer, 'restart_server', 'Steve') !== null, '无参配方可以通过');
ok(\MCFix\Verdict::suggestOne($aiServer, 'whitelist_add', 'Steve') !== null, '玩家 ID 取自工单时可以通过');
ok(\MCFix\Verdict::suggestOne($aiServer, 'rm -rf /', 'Steve') === null, '★ 白名单外的编号被拒');
ok(\MCFix\Verdict::suggestOne($aiServer, 'pull_mod', 'Steve') === null, '★ 参数来源不明的配方被拒');
ok(\MCFix\Verdict::suggestOne($aiServer, 'whitelist_add', '') === null, '★ 缺玩家 ID 时被拒');
ok(\MCFix\Verdict::suggestOne($aiServer, 'whitelist_add', 'Steve; rm -rf /') === null, '★ 玩家名里夹带注入时被拒');

// 6b. 服务器禁用名单必须在 Executor 这一层也生效。
//     玩家侧"取回 MOD"那条路（api.mod_request）直接调 Executor::repair，
//     以前只查了白名单和参数，漏掉了后台的每服务器禁用名单 ——
//     管理员关掉的动作，玩家那边照样能排队。
$disabledServer = $aiServer;
$disabledServer['recipes'] = ['restart_server' => false];
$rejected = \MCFix\Executor::repair($disabledServer, 'restart_server', [], null);
ok(
    ($rejected['status'] ?? '') === 'rejected',
    '★ 被服务器禁用的配方在 Executor 层就被拒（不依赖调用方检查）',
    '实际 status：' . (string) ($rejected['status'] ?? '?')
);
ok(
    strpos((string) ($rejected['error'] ?? ''), '禁用') !== false,
    '拒绝原因说明了是"被禁用"，而不是含糊的失败',
    '实际：' . (string) ($rejected['error'] ?? '')
);

// 6. 大模型没开时，结论必须原封不动 —— 不能凭空多出一条建议
$plainVerdict = \MCFix\Verdict::evaluate($aiServer, 'cannot_join', [
    'mc_status' => ['status' => 'pass', 'message' => '握手成功', 'data' => []],
]);
$afterAi = \MCFix\AiAdvisor::augmentServerVerdict(
    $aiServer,
    'cannot_join',
    ['id' => 1, 'player_name' => 'Steve'],
    [],
    $plainVerdict
);
ok($afterAi === $plainVerdict, '★ 大模型关闭时 verdict 原样返回（等于没发生）');

// 7. 源码层面的定点断言：这几句是安全边界的实现，不能被改掉
$aiSrc = (string) file_get_contents(MCFIX_ROOT . '/src/AiAdvisor.php');
ok(strpos($aiSrc, 'FORBIDDEN_RECIPES') !== false, '有「模型不许碰」的配方名单');
ok(strpos($aiSrc, "'unban_player'") !== false, '★ 名单含 unban_player（防止被封禁的玩家诱导模型解封自己）');
ok(strpos($aiSrc, "'clear_self_items'") !== false, '★ 名单含 clear_self_items（防止误判清掉玩家背包）');
ok(strpos($aiSrc, '!isset($selectable[$code])') !== false, '★ 模型返回的编号必须先在清单里查到，否则整条丢弃');
ok(strpos($aiSrc, 'Verdict::suggestOne') !== false, '★ 模型挑中的配方还要再走一遍完整校验');

$diagSrc = (string) file_get_contents(MCFIX_ROOT . '/src/Diagnosis.php');
ok(strpos($diagSrc, 'augmentServerVerdict') !== false, '诊断流程里接上了服务器侧会诊');
ok(strpos($diagSrc, "'no_problem_detected'") !== false, '★ 只有规则库完全没命中时才触发（不推翻确定性规则）');

// --------------------------------------------------------------------- SMTP

group('SMTP —— VPS 上唯一能真正发出去的通路');

// 默认必须是关的，而且没有 host/账号/授权码就不算"配好了"
$smtpDefaults = \MCFix\Mail::smtpSettings();
ok(empty($smtpDefaults['enabled']), 'SMTP 默认关闭');
ok(\MCFix\Smtp::configured($smtpDefaults) === false, '没填 host/账号/授权码时 configured() 为 false');
ok((int) $smtpDefaults['port'] === 465, '默认端口 465（SSL）');
ok((string) $smtpDefaults['encryption'] === 'ssl', '默认加密方式 ssl');
ok(!empty($smtpDefaults['force_from_username']), '默认强制发件地址 = 登录账号（QQ/163 要求）');
ok(
    \MCFix\Smtp::configured(['enabled' => true, 'host' => 'smtp.qq.com', 'username' => 'a@qq.com', 'password' => 'x']) === true,
    '填全了就算配置好了'
);
ok(
    \MCFix\Smtp::configured(['enabled' => true, 'host' => 'smtp.qq.com', 'username' => 'a@qq.com', 'password' => '']) === false,
    '缺授权码不算配置好（不能拿空密码去连）'
);

// MIME 正文：SMTP 和 mail() 共用同一份，格式必须完整
$mime = \MCFix\Mail::buildMessage(
    ['to@example.com'],
    '测试中文主题',
    '<p>HTML 正文</p>',
    '纯文本正文',
    'from@example.com',
    '故障反馈系统'
);
ok(strpos($mime, 'MIME-Version: 1.0') !== false, '正文带 MIME-Version');
ok(strpos($mime, 'Subject: =?UTF-8?B?') !== false, '中文主题做了 MIME 编码', '否则会乱码或被判垃圾');
ok(strpos($mime, 'Content-Type: multipart/alternative') !== false, '是多段正文');
ok(substr_count($mime, 'Content-Type: text/plain') === 1, '有且只有一个 text/plain 段');
ok(substr_count($mime, 'Content-Type: text/html') === 1, '有且只有一个 text/html 段');
ok(strpos($mime, 'boundary="mcfix-') !== false, '带随机 boundary');
ok(strpos($mime, "\r\n\r\n") !== false, '头与体之间有 CRLF 空行');

// ---- 邮件头注入 ----
//
// 玩家提交的标题是匿名可控的，一路流到 Subject:。encodeHeader() 以前只在
// 含非 ASCII 字节时才编码，纯 ASCII 的 CRLF 原样进头块 —— 任何人都能往
// 管理员收到的邮件里插任意头（伪造 Reply-To 等等）。
// 注意 trim() 只去首尾，挡不住中间夹的换行。
//
// ★ 这里必须用**纯 ASCII** 标题：含中文的标题会被整体 MIME 编码，
//   等于顺手把 CRLF 也编码掉了。拿中文标题测这个洞是测不出来的 ——
//   第一版就是这么写的，反向对照时发现它在旧代码上照样"通过"。
$injected = Mail::buildMessage(
    ['admin@example.com'],
    "normal subject\r\nReply-To: attacker@evil.tld\r\nX-Injected: yes",
    '<p>hi</p>',
    'hi',
    'from@example.com',
    'System'
);
ok(
    strpos($injected, "\r\nReply-To: attacker@evil.tld") === false,
    '★ 标题里的 CRLF 不能注入出额外的邮件头',
    '出现了被注入的 Reply-To 行'
);
ok(
    strpos($injected, "\r\nX-Injected") === false,
    '★ 自定义头也注入不进去'
);
// 清理是"换成空格"，不是把原文整段删掉 —— 标题本身还得能看
ok(
    strpos($injected, 'normal subject Reply-To: attacker@evil.tld') !== false,
    '注入内容被清成行内文本（标题没被整段丢掉）'
);
ok(
    substr_count($injected, "\r\nSubject:") === 1,
    '头块里只有一个 Subject'
);

// 注入的换行不该被"整段丢掉"，而是清成空格
$flat = Mail::buildMessage(
    ['admin@example.com'],
    "line one\r\nline two",
    '<p>hi</p>',
    'hi',
    'from@example.com',
    'System'
);
ok(strpos($flat, "\r\nline two") === false, '换行被清掉（不是留着也不是整段丢掉）');
ok(strpos($flat, 'line one line two') !== false, '清成空格后原文仍在');

// 含中文的标题照旧走 MIME 编码（没有误伤）
ok(strpos($mime, 'Subject: =?UTF-8?B?') !== false, '中文标题仍然正常编码');

// 认证信息不能进日志：Smtp::cmd() 里要屏蔽掉 AUTH 和 base64 串
$smtpSrc = (string) file_get_contents(MCFIX_ROOT . '/src/Smtp.php');
ok(
    strpos($smtpSrc, '(已隐藏)') !== false,
    '★ SMTP 对话日志会屏蔽认证行（授权码不进日志）'
);
ok(strpos($smtpSrc, 'verify_peer') !== false, 'SMTP 的 TLS 会校验证书');

// 认证失败必须给出"要填授权码"的提示，而不是只回一句 auth failed
ok(
    strpos($smtpSrc, '授权码') !== false,
    '★ 535 的提示里会告诉用户"要填授权码而不是登录密码"'
);

// mail() 那条路的失败提示必须把用户引向 SMTP
$mailSrc = (string) file_get_contents(MCFIX_ROOT . '/src/Mail.php');
ok(
    strpos($mailSrc, '封了出网 25 端口') !== false,
    '★ mail() 失败时会说明"VPS 封了 25 端口"，并指向 SMTP'
);
ok(
    strpos($mailSrc, 'Smtp::configured') !== false,
    'Mail::send() 会优先走 SMTP'
);

// --------------------------------------------------------------------- 表单结构
//
// 为什么值得单独测：这个坑踩过一次，而且症状很隐蔽 ——
// admin/settings.php 里「大模型兜底」那一段曾经落在 </form> 之后，
// 页面上看得见、能填、能点保存，但提交时浏览器根本不带这些字段，
// 于是永远存不进去，用户看到的是"填了没用"，很容易被当成看漏了。
//
// 规则：视图里 <form> 与 </form> 必须配对，且**不能有输入框落在表单之外**。

group('表单结构');

$viewFiles = array_merge(
    glob(MCFIX_ROOT . '/admin/*.php') ?: [],
    glob(MCFIX_ROOT . '/views/*.php') ?: []
);

$unbalanced = [];
$orphanInputs = [];

foreach ($viewFiles as $viewFile) {
    $fileName = basename($viewFile);
    $source = (string) file_get_contents($viewFile);

    // HTML 注释里的标签不参与解析，先去掉，避免注释里的示例代码误报
    $source = (string) preg_replace('/<!--.*?-->/s', '', $source);

    $opens  = preg_match_all('/<form\b/i', $source);
    $closes = preg_match_all('#</form>#i', $source);

    if ($opens !== $closes) {
        $unbalanced[] = $fileName . '（开 ' . $opens . ' / 闭 ' . $closes . '）';
    }

    // 把 PHP 代码块挖掉再扫描：属性里常嵌着短标签回显，其中若出现 ">"
    // 会让朴素的标签正则提前截断。用回调保留换行数，行号才不会错位。
    // （注意：这段注释里不能写出短标签的结束符号 —— PHP 的单行注释会被它提前终止，
    //   剩下的代码会整段变成 HTML 输出。踩过一次，见 deploy/check-close-tag.js。）
    $scan = (string) preg_replace_callback(
        '/<\?(?:php|=)?.*?\?>/s',
        static function (array $m): string {
            return str_repeat("\n", substr_count($m[0], "\n")) . 'PHP';
        },
        $source
    );

    // 按出现顺序扫描标签，跟踪所处的表单层级。
    // 只看真正会被提交的控件（input / select / textarea）——
    // <meta name="viewport"> 这类同名属性不是表单字段，不能误报。
    if (preg_match_all('#<[a-zA-Z/][^>]*>#', $scan, $hits, PREG_OFFSET_CAPTURE)) {
        $depth = 0;
        foreach ($hits[0] as $hit) {
            $tag = $hit[0];
            $lower = strtolower($tag);

            if (strpos($lower, '<form') === 0) {
                $depth++;
                continue;
            }
            if (strpos($lower, '</form') === 0) {
                $depth = max(0, $depth - 1);
                continue;
            }

            $isControl = strpos($lower, '<input') === 0
                || strpos($lower, '<select') === 0
                || strpos($lower, '<textarea') === 0;

            if ($isControl && $depth === 0 && preg_match('/\bname\s*=\s*"/i', $tag)) {
                $line = substr_count(substr($scan, 0, (int) $hit[1]), "\n") + 1;
                $orphanInputs[] = $fileName . ':' . $line . ' '
                    . trim((string) preg_replace('/\s+/', ' ', $tag));
            }
        }
    }
}

ok($unbalanced === [], '每个视图的 <form> 与 </form> 数量配对', implode('；', $unbalanced));
ok(
    $orphanInputs === [],
    '★ 没有输入框落在表单之外（否则用户填了也存不进去）',
    implode('；', array_slice($orphanInputs, 0, 8))
);

// 大模型那一段必须真的在表单里 —— 这条是针对上面那个具体事故的定点回归
$settingsSrc = (string) file_get_contents(MCFIX_ROOT . '/admin/settings.php');
$settingsSrc = (string) preg_replace('/<!--.*?-->/s', '', $settingsSrc);
$aiPos = strpos($settingsSrc, 'name="ai_api_key"');
$closePos = strpos($settingsSrc, '</form>');
ok(
    $aiPos !== false && $closePos !== false && $aiPos < $closePos,
    '★ 大模型设置（ai_api_key 等）位于表单闭合之前',
    'ai_api_key 位置 ' . var_export($aiPos, true) . '，首个 </form> 位置 ' . var_export($closePos, true)
);

// --------------------------------------------------------------------- 一致性守卫
//
// 这一组防的是同一类问题：**某条规则在多数地方做对了，漏了一两处**。
// 单看那一处代码都不算错，但"声称的保护"和"实际的保护"对不上。
// 都是源码级断言 —— 这类修复没有可调用的行为入口，只能盯住写法。

group('一致性守卫 —— 别只在一个地方做对');

// 1) 安装向导第 3 步会往 config 里写 RCON 密码和 Agent 令牌，必须校验 CSRF
$installSrc = (string) file_get_contents(MCFIX_ROOT . '/public/controllers/install.php');
ok(
    strpos($installSrc, 'ConsoleAuth::csrfField()') !== false,
    '★ 安装向导第 3 步的表单带 CSRF 字段'
);
ok(
    strpos($installSrc, 'ConsoleAuth::checkCsrf($input)') !== false,
    '★ 安装向导第 3 步的 POST 会校验 CSRF'
);
// 第 2 步不能查 —— 那时还没有后台会话，闸门是"配置文件不存在"。
// 判据：CSRF 校验要落在第 3 步的处理区里（第 3 步门禁之后、第 2 步分支之前）。
$step3Gate = strpos($installSrc, '$formStep === 3 && !$canAddServer');
$step2Body = strpos($installSrc, 'if ($formStep === 2) {');
$csrfPos   = strpos($installSrc, 'ConsoleAuth::checkCsrf($input)');
ok(
    $step3Gate !== false && $step2Body !== false && $csrfPos !== false
        && $csrfPos > $step3Gate && $csrfPos < $step2Body,
    'CSRF 校验落在第 3 步的处理区（第 2 步不受影响）',
    'step3门禁=' . var_export($step3Gate, true)
        . ' csrf=' . var_export($csrfPos, true)
        . ' step2分支=' . var_export($step2Body, true)
);

// 2) 所有面板适配器的 test() 都不能回显未脱敏的 URL
//    （宝塔的 api_url 可能是 /api/<32位密钥>/ 这种形状，翼龙的地址也可能带 token）
$unmasked = [];
foreach (glob(MCFIX_ROOT . '/src/Panel/*.php') ?: [] as $panelFile) {
    $src = (string) file_get_contents($panelFile);
    // 只找 test() 里把地址放进 detail['url'] 的地方
    if (preg_match_all("/'url'\s*=>\s*([^,\n]+)/", $src, $m)) {
        foreach ($m[1] as $expr) {
            $expr = trim($expr);
            if (strpos($expr, 'mask_secret_url') === false) {
                $unmasked[] = basename($panelFile) . ': ' . $expr;
            }
        }
    }
}
ok(
    $unmasked === [],
    '★ 面板适配器的 test() 回显地址前都脱敏了',
    implode('；', $unmasked)
);

// 3) 不带 curl 时的 stream 回退不能跟随跳转
//    PHP 的 http wrapper 默认 follow_location=1，会把请求（含 Authorization 头 /
//    Agent 令牌 body）重发到跳转目标。curl 分支默认不跟随，两边行为必须一致。
foreach ([
    'src/Channel/ChannelAdapter.php' => "通知渠道",
    'src/Panel/PanelAdapter.php'     => "面板适配器",
    'src/AiAdvisor.php'              => "大模型",
] as $file => $label) {
    $src = (string) file_get_contents(MCFIX_ROOT . '/' . $file);
    ok(
        strpos($src, "'follow_location' => 0") !== false,
        '★ ' . $label . '的 stream 回退显式关闭了跳转跟随',
        $file
    );
}
$agentSrc = (string) file_get_contents(MCFIX_ROOT . '/agent/mcfix-agent.php');
ok(
    strpos($agentSrc, "'follow_location' => 0") !== false,
    '★ Agent 的 stream 回退也关了（它的 body 里是 Agent 令牌）'
);

// 4) trusted_proxies 的说明不能把危险场景写反
$exampleSrc = (string) file_get_contents(MCFIX_ROOT . '/config.example.php');
ok(
    strpos($exampleSrc, 'proxy_add_x_forwarded_for') !== false,
    '★ config.example.php 明确写出了安全的 Nginx 写法'
);
ok(
    strpos($exampleSrc, '如果你的 Nginx 是**追加**（而不是覆写）') === false,
    '★ 那段把危险场景写反的旧说明已经删掉'
);

// 5) 登录锁定只能按 IP，不能有全局计数
//
// 以前全局计数到 6 就把整个后台锁上 —— 管理员自己也进不去，连登录表单都看不到；
// 攻击者每 15 分钟重放 6 次就能永久锁死。这是拿可用性换安全，而且换错了方向。
$authSrc = (string) file_get_contents(MCFIX_ROOT . '/src/ConsoleAuth.php');
// 去掉注释再查，避免把说明文字当成代码
$authCode = (string) preg_replace('#^\s*(//|\*|/\*).*$#m', '', $authSrc);

ok(
    strpos($authCode, "lockKey('all')") === false,
    '★ 全局登录计数已移除（只剩注释里的说明）'
);
ok(
    strpos($authCode, '$allKey') === false,
    '★ 没有残留的 $allKey 变量'
);
ok(
    substr_count($authCode, 'self::lockKey(client_ip_hash())') >= 2,
    'gate() 与 login() 都改成按来源 IP 计数',
    '实际出现 ' . substr_count($authCode, 'self::lockKey(client_ip_hash())') . ' 次'
);
ok(
    substr_count($authCode, '$whitelisted') >= 3,
    '★ 白名单地址不受锁（管理员不会被自己人锁在外面）'
);

// ---- 不绑定服务器的工单（纯客户端报错）----
//
// 客户端崩溃（模组冲突、Java 版本、内存不足）跟服务端没关系，所以允许
// server_id 为空。但**绝不能**因此产生任何"服务端动作"：
// Recipe::allowed([]) 在没配禁用名单时会返回 true，一路算下去就会给出
// "重启服务端"之类的建议，而 applyFix() 到那时才发现服务器不存在 ——
// 记一次失败、白白消耗工单的修复次数。
$parsedNoSrv = ClientLog::parse(
    "---- Minecraft Crash Report ----\nTime: 2026-01-02 03:04:05\nDescription: boom\n\n"
    . "java.lang.NoClassDefFoundError: dev/architectury/api/Api\n"
    . "\tat com.example.mymod.MyMod.<init>(MyMod.java:42)\n"
);
$adviceNoSrv = \MCFix\ClientAdvisor::evaluate([], $parsedNoSrv);
$fixNoSrv = (array) ((($adviceNoSrv['issues'][0] ?? [])['server_fix']) ?? []);
ok(
    (array) ($fixNoSrv['codes'] ?? []) === [],
    '★ 没有服务器时不给任何服务端修复配方',
    '实际：' . json_encode($fixNoSrv['codes'] ?? null)
);
ok(empty($fixNoSrv['auto']), '★ 没有服务器时 auto 必须是 false');
ok(empty($fixNoSrv['labels']), '★ 连配方名都不该出现');

// 有服务器时照旧（没有误伤）
$adviceWithSrv = \MCFix\ClientAdvisor::evaluate(
    ['id' => 'unit', 'executor' => 'none', 'recipes' => []],
    $parsedNoSrv
);
ok(
    is_array($adviceWithSrv) && isset($adviceWithSrv['issues']),
    '有服务器时客户端分析照常工作'
);

// ---- 纯客户端工单的「服务器」显示名 ----
//
// 旧写法是 `Config::server($id)['name'] ?? $id`。server_id 为空时
// Config::server('') 返回 null，前半截取到 null，`??` 接住后半截 ——
// 后半截**也是空字符串**，于是玩家邮件里印出「服务器：」、工单页是一段空白。
//
// 这里曾经写成"旧写法会报 PHP 警告"，**是错的**：`??` 自带 isset 语义，
// 对 null 下标访问不产生任何诊断。下面的负向对照就是为了钉死这一点 ——
// 它第一次运行时就证明了那条说法不成立。
$legacyWarn = null;
set_error_handler(function ($no, $str) use (&$legacyWarn) { $legacyWarn = $str; return true; });
$legacyName = (string) (\MCFix\Config::server('')['name'] ?? '');
restore_error_handler();
ok(
    $legacyName === '',
    '★ 负向对照：旧写法确实产出空字符串（这才是真问题）',
    '实际：' . var_export($legacyName, true)
);
ok(
    $legacyWarn === null,
    '★ 负向对照：旧写法并不报 PHP 警告（?? 会吞掉 null 下标诊断）',
    '意外触发了：' . var_export($legacyWarn, true)
);

// 新 helper：可读文案，且同样不报警告
$newWarn = null;
set_error_handler(function ($no, $str) use (&$newWarn) { $newWarn = $str; return true; });
$nameEmpty = ticket_server_name('');
$nameBlank = ticket_server_name('   ');
$nameBogus = ticket_server_name('no-such-server');
restore_error_handler();
ok($newWarn === null, 'ticket_server_name 不产生 PHP 警告', '实际：' . var_export($newWarn, true));
ok($nameEmpty === '不涉及服务器（客户端问题）', '纯客户端工单有可读的服务器名', '实际：' . $nameEmpty);
ok($nameBlank === '不涉及服务器（客户端问题）', '纯空白也当作没有服务器', '实际：' . $nameBlank);
ok($nameBogus === 'no-such-server', '服务器查不到时回落到 id 本身', '实际：' . $nameBogus);

// 邮件和工单页共用这一个 helper，别再各写一份。
// 匹配前先去掉 // 行注释，否则注释里举例说明这段历史写法时会把自己扫挂。
$stripLineComments = function (string $file): string {
    $src = (string) file_get_contents($file);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $src);
};
ok(
    !preg_match('/Config::server\([^)]*\)\[/', $stripLineComments(MCFIX_ROOT . '/src/Workflow.php')),
    '★ Workflow 里不再有 Config::server(...)[...] 直接下标'
);
ok(
    !preg_match('/Config::server\([^)]*\)\[/', $stripLineComments(MCFIX_ROOT . '/src/TicketMail.php')),
    '★ TicketMail 里也不再有同样的写法'
);

// ---- ★★ 玩家侧绝不能出现服务端连接地址 ----
//
// 这是一次真实的泄露：玩家反馈页把每台服务器的 host:port 直接印给了玩家 ——
// 服务器状态卡片一行、下拉框一行。任何人拿到反馈链接就能读到 MC 服务端的
// 可直连地址，然后扫描端口 / 打 DDoS / 绕过白名单直连。
//
// 地址属于连接信息，管理员在后台核对配置时需要它；玩家只需要知道"是哪台服"。
// 所以玩家侧一律用 server_public_label()：只出「代号・名字」。
//
// 下面这组断言分两层：
//   1. helper 的行为（纯函数，好测）
//   2. 源码里玩家可达的那几个文件**不再出现** host/port 的取值写法
//      —— 行为测试覆盖不到模板，只能靠盯写法（和上面两条同样的思路）

// 1) helper 行为
ok(server_public_label('unit') === 'U1・测试服',
    '★★ 玩家侧标签是「代号・名字」', '实际：' . server_public_label('unit'));
ok(strpos(server_public_label('unit'), '25565') === false,
    '★★ 标签里没有端口号');
ok(strpos(server_public_label('unit'), '127.0.0.1') === false,
    '★★ 标签里没有主机地址');
ok(server_public_label('') === '不涉及服务器（客户端问题）',
    '★ 纯客户端工单有可读标签', '实际：' . server_public_label(''));
ok(server_public_label('no-such-server') === 'no-such-server',
    '★ 服务器查不到时回落到 id，不抛错/不空白');

// 没配 code 的老配置必须仍然可用，且不能变成"・名字"这种开头一个点的怪东西
Config::set('servers.unit.code', '');
ok(server_public_label('unit') === '测试服',
    '★★ 代号留空时只显示名字（老配置不炸，也不会印成「・测试服」）',
    '实际：' . server_public_label('unit'));
Config::set('servers.unit.code', 'U1');   // 复原，后面的用例还要用

// 2) 源码级守卫：玩家可达的文件里不能再出现这些取值
$playerFiles = [
    '/views/player-form.php',
    '/views/player-ticket.php',
    '/public/controllers/api.php',
];
$leakPatterns = [
    "#\\\$srv\\['host'\\]#"                    => "模板里直接取 \$srv['host']",
    "#\\\$server\\['host'\\]#"                 => "直接取 \$server['host']",
    "#\\\$srv\\['port'\\]#"                    => "模板里直接取 \$srv['port']",
    "#\\\$server\\['port'\\]#"                 => "直接取 \$server['port']",
    "#['\"]host['\"]\\s*=>#"                   => "JSON 里带 host 字段",
];
foreach ($playerFiles as $rel) {
    $src = $stripLineComments(MCFIX_ROOT . $rel);
    foreach ($leakPatterns as $pattern => $what) {
        ok(!preg_match($pattern, $src),
            '★★ ' . $rel . ' 里没有' . $what . '（玩家侧不能出现连接地址）');
    }
}

// ---- ★★ 专属链接锁定：多台服务器时，提交必须落回链接指定的那台 ----
//
// 现实场景：服主有三台服，把 A 服的链接发到 A 服群里。任何人拿到这条链接后，
// 只要提交时把服务器改成 B 服，系统就会真去连 B 服做诊断、甚至执行修复 ——
// 消耗 B 服的风控配额（重启配额是按 server_id 计的，见 Workflow 的 recentCount），
// 工单还记在 B 服名下，服主查后台会以为 B 服真的出问题了。
//
// 判定收在 resolve_locked_server_id() 里，这里全用假的存在性判断，不碰真实配置。
$anyServer = static function (string $id): bool {
    return in_array($id, ['alpha', 'beta'], true);
};

// 无锁定时：表单说哪台就哪台（首页手选路径，不能被误伤）
ok(resolve_locked_server_id('beta', '', '', $anyServer) === 'beta',
    '★ 没有锁定时，按表单填的服务器走');
ok(resolve_locked_server_id('', '', '', $anyServer) === '',
    '★ 没有锁定时，纯客户端工单照常');

// 有 session 锁定：改表单也要落回锁定那台
ok(resolve_locked_server_id('beta', '', 'alpha', $anyServer) === 'alpha',
    '★★ session 锁定生效：表单改选别的服也落回锁定那台');
ok(resolve_locked_server_id('alpha', '', 'alpha', $anyServer) === 'alpha',
    '★ session 锁定：提交锁定那台本身，原样通过');

// 关键一条：这是"故意不带令牌"的绕法。有 session 仍然挡得住。
ok(resolve_locked_server_id('beta', 'bogus-token', 'alpha', $anyServer) === 'alpha',
    '★★ 令牌无效也不会放行 —— session 锁定仍然是依据');
ok(resolve_locked_server_id('beta', '', 'alpha', $anyServer) !== 'beta',
    '★★ 手搓 POST 不带令牌、把服务器改成别的服 → 不生效');

// 令牌优先级高于 session（换了链接进来，以新链接为准）
ok(resolve_locked_server_id('beta', '', 'beta', $anyServer) === 'beta',
    '★ session 锁在别的服时，提交那台也放行');

// 纯客户端工单在锁定时必须仍然可用 —— 模组冲突/Java 版本跟是哪台服无关
ok(resolve_locked_server_id('', '', 'alpha', $anyServer) === '',
    '★★ 锁定时「不涉及服务器」仍然可用（纯客户端问题不该被挡）');
ok(resolve_locked_server_id('', 'whatever', 'alpha', $anyServer) === '',
    '★★ 同上：带令牌也不能把纯客户端工单变成服务端工单');

// 锁定的服被删掉之后不能把无效 id 写进工单
ok(resolve_locked_server_id('beta', '', 'ghost-server', $anyServer) === 'beta',
    '★★ 锁定的服已不存在时，不强制改写（避免工单指向不存在的服务器）');

// 源码级守卫：判定必须真的在提交路径上被调用。
// 抽出函数只是"可以测"，不接上去就等于没修 —— 这条盯着它有没有被接上。
$apiSrc = (string) file_get_contents(MCFIX_ROOT . '/public/controllers/api.php');
ok(strpos($apiSrc, 'resolve_locked_server_id(') !== false,
    '★★ api.php 的提交路径确实调用了 resolve_locked_server_id()');
ok(strpos($apiSrc, 'share_locked_server') !== false,
    '★★ 提交时读取了 session 里的锁定值（不带令牌的绕法就靠它挡）');
$feedbackSrc = (string) file_get_contents(MCFIX_ROOT . '/public/controllers/feedback.php');
ok(strpos($feedbackSrc, "\$_SESSION['share_locked_server']") !== false,
    '★★ 反馈页确实把锁定值写进了 session');
ok(strpos($feedbackSrc, 'isset($servers[$lockedServerId])') !== false,
    '★★ 反馈页确实把服务器列表收窄到锁定那一台');
/*
 * 光"写进 session"还不够：PHP 不会因为给 $_SESSION 赋值就自动开会话。
 * 没有活动会话时那个数组只活在本次请求里 —— 不落盘、不报错，提交时读到的
 * 一直是空串。所以必须有一条守卫盯着反馈页**真的起了会话**，
 * 而且用的会话名要和 api.php 一致（不然还是读不到同一份）。
 */
ok(strpos($feedbackSrc, "mcfix_start_session('mcfix_sid'") !== false,
    '★★ 反馈页显式起了玩家会话（否则 session 锁定与邮箱回填都是空转）');
ok(strpos($apiSrc, "mcfix_start_session('mcfix_sid'") !== false,
    '★★ （对照）提交侧用的是同一个会话名，两边指的是同一份 session');

// ---- 本机判定引擎：INFO 行 / 死信号 / 兜底阈值 ----
//
// 这一组针对的是"没有大模型时"的主路径。线上 ai.enabled=false，
// 所以下面这些就是玩家实际会得到的结论。
$mkLog = static function (string $level, string $msg): string {
    return "[03:04:05] [Render thread/INFO]: Connecting to mc.example.com, 25565\n"
         . "[03:04:05] [Render thread/" . $level . "]: " . $msg . "\n";
};
$codeOf = static function (string $log): string {
    $a = \MCFix\ClientAdvisor::evaluate([], \MCFix\ClientLog::parse($log));
    return (string) (($a['issues'][0]['code']) ?? '');
};
$codesOf = static function (string $log): array {
    $a = \MCFix\ClientAdvisor::evaluate([], \MCFix\ClientLog::parse($log));
    $out = [];
    foreach ((array) ($a['issues'] ?? []) as $i) { $out[] = (string) $i['code']; }
    return $out;
};
$sigOf = static function (string $log): array {
    $p = \MCFix\ClientLog::parse($log);
    return array_values((array) ($p['signals'] ?? []));
};

// ★ 负向对照：同一句话，只改日志级别。
// 真实客户端日志里这些都打在 INFO 上，而旧版只扫 ERROR/FATAL/WARN 行。
$wl = 'You are not whitelisted on this server!';
ok($codeOf($mkLog('INFO', $wl)) === 'not_whitelisted', '★ 白名单提示按 INFO 打也认得出');
ok($codeOf($mkLog('ERROR', $wl)) === 'not_whitelisted', '★ （对照）按 ERROR 打仍然认得出');
ok($codeOf($mkLog('INFO', 'You are banned from this server!')) === 'banned', '★ 封禁提示按 INFO 打也认得出');
ok($codeOf($mkLog('INFO', 'Server is full')) === 'server_full', '★ 服务器已满按 INFO 打也认得出');
ok($codeOf($mkLog('INFO', 'Outdated client! Please use 1.20.1')) === 'version_mismatch', '★ 客户端过旧按 INFO 打也认得出');
ok($codeOf($mkLog('WARN', 'Failed to verify username! Invalid session')) === 'auth_failed',
    '★ auth_failed 不再是"认得出特征却没有结论"的死信号',
    '实际：' . $codeOf($mkLog('WARN', 'Failed to verify username! Invalid session')));

// ★ 兜底阈值：只有"又短又少行"才算真的没信息
$bigUnmatched = "---- Minecraft Crash Report ----\nTime: 2026-01-02 03:04:05\nDescription: Ticking entity\n\n"
    . "java.lang.IllegalArgumentException: unknown enum constant\n"
    . "\tat com.example.weirdmod.WeirdThing.doIt(WeirdThing.java:51)\n";
for ($i = 0; $i < 30; $i++) {
    $bigUnmatched .= "\tat com.example.filler.Layer" . $i . ".step(Layer" . $i . ".java:" . ($i + 10) . ")\n";
}
$pBig = \MCFix\ClientLog::parse($bigUnmatched);
ok((int) $pBig['lines'] >= 15, '对照日志确实够长（否则下面那条会空转）', '行数 ' . (int) $pBig['lines']);
ok($codeOf($bigUnmatched) === 'unknown',
    '★ 行数够多、字节数不足的崩溃报告不再被判"日志内容太短"',
    '实际：' . $codeOf($bigUnmatched));
ok($codeOf('一进游戏就崩了') === 'need_log', '★ 真的短还是 need_log');

// 短，但确实是崩溃报告 → 玩家已经把报告交上来了，不该叫他把"完整的"再发一次
$shortCrash = "---- Minecraft Crash Report ----\nTime: 2026-01-02 03:04:05\nDescription: Ticking entity\n\n"
    . "java.lang.ArithmeticException: Non-terminating decimal expansion\n"
    . "\tat com.zonko.quantum.QuantumTicker.tick(QuantumTicker.java:212)\n";
ok((string) \MCFix\ClientLog::parse($shortCrash)['kind'] === 'crash_report', '对照：这份确实被认成崩溃报告');
ok($codeOf($shortCrash) === 'unknown',
    '★ 短但确实是崩溃报告 → 归 unknown，不再叫玩家补发完整报告',
    '实际：' . $codeOf($shortCrash));

// ★ 显卡驱动初始化失败：单独出现也要能定论
$gpuLog = "---- Minecraft Crash Report ----\nTime: 2026-01-02 03:04:05\nDescription: Rendering overlay\n\n"
    . "java.lang.IllegalStateException: Failed to create window\n"
    . "\tat com.mojang.blaze3d.platform.Window.createWindow(Window.java:112)\n";
ok($codeOf($gpuLog) === 'graphics_driver', '★ 建窗口失败单独出现也能定论', '实际：' . $codeOf($gpuLog));

// ★ 光影 / 渲染模组痕迹
$compatLog = "---- Minecraft Crash Report ----\nTime: 2026-01-02 03:04:05\nDescription: Initializing game\n\n"
    . "java.lang.NoSuchMethodError: 'void me.jellysquid.mods.sodium.client.render.chunk.ChunkRenderDispatcher.rebuild()'\n"
    . "\tat net.optifine.shaders.Shaders.loadShaders(Shaders.java:412)\n";
ok(in_array('ccompat_error', $codesOf($compatLog), true), '★ Sodium / OptiFine 痕迹会产出 ccompat_error');
ok(in_array('datapack_error', $codesOf(
    "---- Minecraft Crash Report ----\nTime: 2026-01-02 03:04:05\nDescription: Loading\n\n"
    . "java.lang.RuntimeException: Failed to load datapack\n"
    . "\tat net.minecraft.server.packs.PackResources.getResource(PackResources.java:1)\n"
), true), '★ 数据包加载失败会产出 datapack_error');

// ★★ 负向对照：我自己在写这两条正则时差点引入的误报。
// `WGL` 会命中 org.lwjgl（正则不分大小写），裸 `shader` 会命中核心类 ShaderInstance。
$lwjglLog = "---- Minecraft Crash Report ----\nTime: 2026-01-02 03:04:05\nDescription: Rendering overlay\n\n"
    . "java.lang.NullPointerException: null\n"
    . "\tat org.lwjgl.opengl.GL11.glClear(GL11.java:1421)\n"
    . "\tat com.mojang.blaze3d.platform.GlStateManager._clear(GlStateManager.java:301)\n";
ok(!in_array('gpu_init_failed', $sigOf($lwjglLog), true),
    '★★ 负向对照：org.lwjgl 不被当成显卡驱动初始化失败');
$shaderLog = "---- Minecraft Crash Report ----\nTime: 2026-01-02 03:04:05\nDescription: Rendering overlay\n\n"
    . "java.lang.NullPointerException: null\n"
    . "\tat net.minecraft.client.renderer.ShaderInstance.<init>(ShaderInstance.java:87)\n";
ok(!in_array('ccompat_error', $sigOf($shaderLog), true),
    '★★ 负向对照：核心类 ShaderInstance 不被当成光影冲突');

// ★★ 静态守卫：信号表与知识库不许有"死条目"。
// 这两条守的是同一类毛病 —— 特征写好了、没人接；条目写好了、没人产。
$logSrc = (string) file_get_contents(MCFIX_ROOT . '/src/ClientLog.php');
$advSrc = (string) file_get_contents(MCFIX_ROOT . '/src/ClientAdvisor.php');
preg_match_all("/^\s*'([a-z_]+)'\s*=>\s*'\//m", $logSrc, $mSig);
$signalKeys = array_values(array_unique($mSig[1]));
ok(count($signalKeys) >= 25, '★ 信号表提取成功（否则下面两条会空转）', '提取到 ' . count($signalKeys) . ' 条');
$dead = [];
foreach ($signalKeys as $k) {
    if (strpos($advSrc, "'" . $k . "'") === false) { $dead[] = $k; }
}
ok($dead === [], '★★ 没有"检测得到但没人用"的死信号', '死信号：' . implode(',', $dead));

// 全量行扫描用的名字必须真实存在，否则是静默失效
preg_match('/LINE_WIDE_SIGNALS = \[(.*?)\];/s', $logSrc, $mLw);
preg_match_all("/'([a-z_]+)'/", (string) ($mLw[1] ?? ''), $mLw2);
$lineWide = array_values(array_unique($mLw2[1]));
$badLw = array_values(array_diff($lineWide, $signalKeys));
ok($lineWide !== [] && $badLw === [], '★ LINE_WIDE_SIGNALS 里的名字都是真实信号', '无效：' . implode(',', $badLw));

$issueSrc = (string) file_get_contents(MCFIX_ROOT . '/src/ClientIssue.php');
preg_match_all("/^\s{12}'([a-z_]+)' => \[/m", $issueSrc, $mKb);
$kbCodes = array_values(array_unique($mKb[1]));
ok(count($kbCodes) >= 30, '★ 知识库提取成功（否则下面那条会空转）', '提取到 ' . count($kbCodes) . ' 条');
$producers = $advSrc
    . (string) file_get_contents(MCFIX_ROOT . '/src/AiAdvisor.php')
    . (string) file_get_contents(MCFIX_ROOT . '/src/Verdict.php')
    . (string) file_get_contents(MCFIX_ROOT . '/src/Workflow.php');
$unreachable = [];
foreach ($kbCodes as $c) {
    if (strpos($producers, "'" . $c . "'") === false) { $unreachable[] = $c; }
}
ok($unreachable === [], '★★ 知识库里没有"永远产不出来"的条目', '不可达：' . implode(',', $unreachable));

// ---- ★★ 误报防护 ----
//
// 误报比漏报糟得多：漏报是"我不知道"，误报是一个**明确而错误的结论**，
// 玩家会照着它去做没用的事（甚至被吓一跳）。
//
// 下面每一例都对应一个真实会发生的场景。前三例是聊天内容 —— 1.12.0 把扫描
// 范围扩到整份日志之后，玩家在公屏问一句"白名单怎么加"，系统就回他
// "你不在服务器白名单里"；有人提一句"他昨天被封禁了吧"，系统就告诉他
// "你的账号处于封禁状态"，还会自动提交解封请求。
$fpCases = [
    ['玩家聊天里问白名单', ['not_whitelisted'],
        "[03:04:05] [Render thread/INFO]: [CHAT] <Steve> 管理在吗，白名单怎么加啊\n"
        . "[03:04:06] [Render thread/INFO]: [CHAT] <Alex> 找服主\n"],
    ['玩家聊天里说封禁', ['banned'],
        "[03:04:05] [Render thread/INFO]: [CHAT] <Steve> 他昨天被封禁了吧\n"],
    ['玩家聊天里说服务器满', ['server_full'],
        "[03:04:05] [Render thread/INFO]: [CHAT] <Steve> server is full 进不去\n"],
    ['模组配置文件损坏', ['chunk_corrupt'],
        "[03:04:05] [Render thread/ERROR]: Failed to load config\n"
        . "java.io.IOException: Corrupted config file, regenerating defaults\n"
        . "\tat com.example.configmod.Config.load(Config.java:88)\n"],
    ['区块加载超时（不是网络）', ['timed_out'],
        "[03:04:05] [Render thread/ERROR]: Chunk load timed out, skipping chunk\n"],
    ['资源包签名证书（不是 TLS）', ['ssl_error'],
        "[03:04:05] [Render thread/WARN]: Invalid certificate in resource pack signature, ignoring\n"],
    ['渲染 NPE 不是显卡问题', ['graphics_driver'],
        "---- Minecraft Crash Report ----\nTime: 2026-01-02 03:04:05\nDescription: Rendering screen\n\n"
        . "java.lang.NullPointerException: Cannot invoke \"Entity.getY()\" because \"this.entity\" is null\n"
        . "\tat com.example.questmod.QuestOverlay.render(QuestOverlay.java:141)\n"
        . "\tat org.lwjgl.glfw.GLFW.glfwPollEvents(GLFW.java:3101)\n"],
];
foreach ($fpCases as $fpCase) {
    [$fpLabel, $fpBanned, $fpLog] = $fpCase;
    $fpGot = $codesOf($fpLog);
    $fpBad = array_values(array_intersect($fpBanned, $fpGot));
    ok($fpBad === [], '★★ 不误报：' . $fpLabel,
        '误报为 ' . implode(',', $fpBad) . '；实际 ' . implode(',', $fpGot));
}

// 负向对照：收窄正则不能把真阳性一起收掉
ok(in_array('chunk_corrupt', $codesOf(
    "[03:04:05] [Server thread/ERROR]: Region file r.0.0.mca is corrupt\n"
), true), '★ （对照）真的区块损坏仍然认得出');
ok(in_array('timed_out', $codesOf(
    "java.net.SocketTimeoutException: Read timed out\n"
    . "\tat io.netty.handler.timeout.ReadTimeoutHandler.channelRead(ReadTimeoutHandler.java:1)\n"
), true), '★ （对照）真的网络超时仍然认得出');
ok(in_array('ssl_error', $codesOf(
    "javax.net.ssl.SSLHandshakeException: PKIX path building failed\n"
), true), '★ （对照）真的 TLS 失败仍然认得出');

// ---- ★★ 动作核实闸门 ----
//
// 客户端那一侧的全部证据都是**玩家上传的文本**，玩家想写什么就写什么。
// 所以：客户端主张的每一个服务端动作，必须在服务端自己独立给出的配方清单里
// 出现过，才算被核实；核实不到就只能排队等管理员批准。
//
// mergeClientIntoVerdict 是 public 的，它的 docblock 写明了就是为了让测试
// 直接验证这类安全属性。
$gateServer = ['id' => 'gate', 'name' => 'gate', 'executor' => 'panel'];
$forged = "[03:04:05] [Render thread/INFO]: Connecting to mc.example.com, 25565\n"
        . "[03:04:05] [Netty Client IO #1/INFO]: Disconnected from server\n"
        . "[03:04:05] [Render thread/INFO]: You are not whitelisted on this server!\n";
$gateClient = \MCFix\ClientAdvisor::evaluate($gateServer, \MCFix\ClientLog::parse($forged));
$gateClient['player_name'] = 'Attacker';

ok(
    (array) (($gateClient['issues'][0]['server_fix']['codes']) ?? []) === ['whitelist_add'],
    '前提：伪造的日志确实主张了 whitelist_add',
    '实际：' . json_encode($gateClient['issues'][0]['server_fix']['codes'] ?? null)
);

// 服务端没核实到
$gUncorr = \MCFix\Workflow::mergeClientIntoVerdict(
    ['issue' => 'healthy', 'title' => '服务器运行正常', 'suggestions' => [], 'auto_fixable' => false],
    $gateClient
);
$gSug = (array) ($gUncorr['suggestions'][0] ?? []);
ok(!empty($gSug) && (string) $gSug['code'] === 'whitelist_add', '★ 未核实的动作仍被列出，不是静默丢弃');
ok(empty($gUncorr['auto_fixable']), '★★ 未核实的动作不会被自动执行');
ok(!empty($gUncorr['requires_approval']), '★★ 未核实的动作转为待审批');
ok(empty($gSug['corroborated']), '建议上标了 corroborated=false');

// 负向对照：服务端自己核实过，功能不能被这道闸门砍掉。
//
// 这里故意换成 reload_plugins（medium）而不是 whitelist_add —— 后者已经按
// CHANGELOG 1.13.2 的第一条（V1）提成 high（见下面那条断言），凡是能改变
// "谁能进服"的动作都不允许匿名请求自动执行。负向对照要证明的是"闸门没有把
// 服务端核实过的动作一起砍掉"，所以必须挑一个策略上**允许**自动执行的配方；
// 拿 whitelist_add 当例子的话，这个对照就变成了在断言"漏洞还在"。
$gateClientAuto = $gateClient;
$gateClientAuto['issues'][0]['server_fix']['codes'] = ['reload_plugins'];
$gateClientAuto['issues'][0]['server_fix']['labels'] = ['重载插件'];
$gateClientAuto['issues'][0]['server_fix']['auto'] = true;
$gateClientAuto['issues'][0]['server_fix']['needs_approval'] = false;

$gCorr = \MCFix\Workflow::mergeClientIntoVerdict([
    'issue' => 'plugin_error', 'title' => '插件报错', 'auto_fixable' => true,
    'suggestions' => [[
        'code' => 'reload_plugins', 'label' => '重载插件', 'description' => '',
        'risk' => 'medium', 'exec' => 'panel', 'params' => [],
        'requires_approval' => false,
    ]],
], $gateClientAuto);
$gSug2 = (array) ($gCorr['suggestions'][0] ?? []);
ok(!empty($gSug2['corroborated']), '★ （对照）服务端核实过 → corroborated=true');
ok(empty($gSug2['requires_approval']), '★ （对照）核实过的动作不需要审批');
ok(!empty($gCorr['auto_fixable']), '★ （对照）核实过的动作仍然可以自动执行');

// ---- V21：匿名请求不能改"谁能进服" ----
//
// whitelist_add / unban_player / clear_self_items 是**准入闸门**，而 player
// 参数只是提交者在表单里自己敲的一串字符（没有登录、没有验证码）。风险等级
// 定成 medium 时，needsApproval() 不生效，于是「提交一单 category=permission」
// 就能让服务端真的执行 `whitelist add <任意名字>`。
$gateServerForPolicy = ['id' => 'gate', 'name' => 'gate', 'executor' => 'panel'];
ok(\MCFix\Recipe::needsApproval($gateServerForPolicy, 'whitelist_add') === true,
    '★★ whitelist_add 必须人工审批（匿名表单不能直接改服务端准入）');
ok(\MCFix\Recipe::needsApproval($gateServerForPolicy, 'unban_player') === true,
    '★★ unban_player 也要审批（解封 = 把被踢出去的人放回来）');
ok(\MCFix\Recipe::needsApproval($gateServerForPolicy, 'clear_self_items') === true,
    '★★ clear_self_items 也要审批（清背包是不可逆的）');

// 负向对照：不能借这次收紧把普通动作一起变成"必须审批"，否则自动修复等于关掉
ok(\MCFix\Recipe::needsApproval($gateServerForPolicy, 'reload_plugins') === false,
    '★ （对照）medium 配方仍然不需要审批');
ok(\MCFix\Recipe::needsApproval($gateServerForPolicy, 'save_world') === false,
    '★ （对照）low 配方仍然不需要审批');

// 源码级守卫：whitelist_add 的 risk 必须是 high —— needsApproval 只认这一个字段，
// 谁把它改回 medium，上面那条断言会失败，但这里把"为什么"钉在源码上。
$recipeSrc = (string) file_get_contents(MCFIX_ROOT . '/src/Recipe.php');
$wlAt = strpos($recipeSrc, "'whitelist_add' =>");
$wlBlock = $wlAt !== false ? substr($recipeSrc, $wlAt, 1200) : '';
ok(strpos($wlBlock, "'risk'        => 'high'") !== false,
    '★★ whitelist_add 的 risk 声明就是 high（不是靠别处兜底）');

// 服务端自己坏了 → 客户端结论整个不采纳
$gBroken = \MCFix\Workflow::mergeClientIntoVerdict(
    ['issue' => 'server_offline', 'title' => '没在监听', 'suggestions' => [], 'auto_fixable' => false],
    $gateClient
);
ok((string) $gBroken['issue'] === 'server_offline', '服务端故障时保持服务端结论');
ok((array) $gBroken['suggestions'] === [], '服务端故障时不采纳任何客户端动作');

// ---- ★★ 日志原文留存 ----
//
// 崩溃报告里含玩家的 Windows 用户名（C:\Users\<名字>\...）、显卡型号、游戏 ID、
// 服务器地址 —— 属于个人信息。归档满 N 天后 cron 清掉原文，保留诊断结论。
//
// 这组里最关键的是**负向对照**：没归档的工单，无论多久都不能碰。
// 少了那条，"只清 closed" 这个安全边界就没人守了。
$mkTicket = static function (string $status, string $when, string $log): int {
    return (int) Db::insert('feedback', [
        'ticket_no'       => 'RET-' . bin2hex(random_bytes(4)),
        'server_id'       => 'ret-test',
        'player_name'     => 'RetTest',
        'category'        => 'client_problem',
        'subject'         => '留存测试',
        'status'          => $status,
        'client_log'      => $log,
        'client_log_name' => 'crash-2026-01-02_Steve.txt',
        'client_analysis' => json_encode(['primary_code' => 'oom']),
        'created_at'      => $when,
        'updated_at'      => $when,
        // 终态工单才有 closed_at（归档按它算时间；老数据用 updated_at 兜底）
        'closed_at'       => in_array($status, ['closed', 'resolved', 'rejected'], true) ? $when : null,
    ]);
};
$ago = static function (int $days): string { return gmdate('Y-m-d H:i:s', time() - $days * 86400); };
$logOf = static function (int $id): ?string {
    $r = Db::first('SELECT client_log FROM feedback WHERE id = :id', ['id' => $id]);
    return $r === null ? null : $r['client_log'];
};

$activeId  = $mkTicket('manual',  $ago(60), 'ACTIVE LOG');       // 进行中（转人工），60 天没动
$oldClosed = $mkTicket('closed',  $ago(60), 'OLD CLOSED LOG');   // 归档 60 天
$newClosed = $mkTicket('closed',  $ago(2),  'NEW CLOSED LOG');   // 归档 2 天
// 终态不只 closed 一个：resolved / rejected 同样算"已结束"，日志照样要清。
// （早期实现只认 closed，而没有任何东西会把 resolved 推成 closed，
//   那些工单的日志于是永远清不掉 —— 崩溃报告里含玩家用户名/显卡型号。）
$oldResolved = $mkTicket('resolved', $ago(60), 'OLD RESOLVED LOG');
$oldRejected = $mkTicket('rejected', $ago(60), 'OLD REJECTED LOG');

$purged = \MCFix\Workflow::purgeExpiredLogs(30);
ok($purged === 3, '清理了三条终态且归档满 30 天的工单（closed + resolved + rejected）', '实际清了 ' . $purged . ' 条');

ok($logOf($activeId) === 'ACTIVE LOG',
    '★★ 负向对照：进行中的工单原文没被删（哪怕它 60 天没动）',
    '实际：' . var_export($logOf($activeId), true));
ok($logOf($newClosed) === 'NEW CLOSED LOG', '归档不满 30 天的也没被删');
ok($logOf($oldClosed) === null, '归档满 30 天的原文已清空');
ok($logOf($oldResolved) === null, '★★ 「已解决」的原文也会清（它现在是终态）');
ok($logOf($oldRejected) === null, '★★ 「已驳回」的原文也会清（驳回也是终态）');

// 只删原文，结论要留住 —— 否则清理等于把工单变成废纸
$kept = Db::first('SELECT client_analysis, client_log_name, log_purged_at FROM feedback WHERE id = :id', ['id' => $oldClosed]);
ok(!empty($kept['client_analysis']), '★ 诊断结论保留（清理的只是原文）');
ok(empty($kept['client_log_name']), '文件名也清掉了（它可能含玩家 ID）');
ok(!empty($kept['log_purged_at']), '记录了清理时间戳');

// 幂等
ok(\MCFix\Workflow::purgeExpiredLogs(30) === 0, '重复执行不会再次清理');

// 关闭该功能
$offId = $mkTicket('closed', $ago(99), 'KEEP ME');
ok(\MCFix\Workflow::purgeExpiredLogs(0) === 0, '阈值为 0 时一条都不动');
ok($logOf($offId) === 'KEEP ME', '★ 关闭该功能时，再老的工单原文也完好');

// 收尾：把测试工单连同它的事件记录一起删掉
foreach ([$activeId, $oldClosed, $newClosed, $offId, $oldResolved, $oldRejected] as $tid) {
    Db::delete('events', ['feedback_id' => $tid]);
    Db::delete('feedback', ['id' => $tid]);
}

// ---- ★★ 表单控件外观：不许有"漏网的输入框" ----
//
// 起因是一个真实的显示缺陷：CSS 写的是 `input[type=text]`，而属性选择器匹配的是
// **HTML 属性**，不是 DOM 属性 —— `<input>` 不写 type 时浏览器照样按 text 渲染，
// 但选择器匹配不到，于是这些框完全拿不到样式，只剩浏览器默认的 21px 高，
// 和旁边 42px 的框并排就是"上下不齐"。项目里有 52 处漏写 type，
// 另有 4 处 `type="email"` 不在覆盖列表里。
//
// 这组断言守两件事：
//   1. 兜底选择器和 email 必须在（否则老代码立刻回退到 21px）
//   2. 项目里**新出现**的 input type 必须同时被 CSS 覆盖，否则又是 21px
$css = (string) file_get_contents(MCFIX_ROOT . '/public/assets/app.css');

ok(strpos($css, 'input:not([type])') !== false,
    '★★ CSS 兜住了没有 type 属性的输入框（否则它们只有 21px 高）');
ok(strpos($css, 'input[type=email]') !== false,
    '★ 邮箱输入框也被覆盖（玩家反馈表单用 type="email"）');

// 输入框和下拉框必须等高：select 的内容盒天生比 input 高 2px
preg_match('/input:not\(\[type\]\),\s*\nselect \{ height: (\d+)px; \}/', $css, $mH);
ok(isset($mH[1]), '★ 有把 input 和 select 锁成等高的规则', '没找到 height 规则');
if (isset($mH[1])) {
    ok((int) $mH[1] > 0, '锁定的高度是个正常值（' . $mH[1] . 'px）');
}

// 复制框必须自成一款，不能被上面那条基础规则盖掉
ok(strpos($css, 'input.copy-field {') !== false,
    '★ .copy-field 带上了元素名（特异度要压过基础规则，否则虚线框和等宽字体会丢）');
ok((bool) preg_match('/input\.copy-field \{[^}]*height: auto;/s', $css),
    '★ 复制框显式声明 height: auto，不受统一高度约束');

// 项目里实际用到的 input type，必须全部被 CSS 覆盖或明确豁免
$exempt = ['hidden', 'checkbox', 'radio', 'file', 'submit', 'button', 'reset', 'image', 'range', 'color'];
preg_match_all('/input\[type=([a-z]+)\]/', $css, $mCov);
$covered = array_values(array_unique($mCov[1]));

$usedTypes = [];
$walker = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(MCFIX_ROOT . '/'));
foreach ($walker as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    if (strpos($path, '/tests/') !== false) {
        continue;   // 测试文件里的示例标签不算
    }
    $src = (string) file_get_contents($path);
    if (preg_match_all('/<input\b[^>]*>/i', $src, $tags)) {
        foreach ($tags[0] as $tag) {
            if (preg_match('/\btype\s*=\s*"([^"]*)"/i', $tag, $t)) {
                $usedTypes[strtolower($t[1])] = true;
            }
        }
    }
}
$usedTypes = array_keys($usedTypes);
ok(count($usedTypes) >= 4, '★ 扫到了输入框类型（否则下面那条会空转）', '扫到 ' . count($usedTypes) . ' 种：' . implode(',', $usedTypes));

$uncovered = [];
foreach ($usedTypes as $t) {
    if (in_array($t, $exempt, true)) {
        continue;
    }
    if (!in_array($t, $covered, true)) {
        $uncovered[] = $t;
    }
}
ok($uncovered === [],
    '★★ 每个用到的 input type 都有对应样式（新增类型要同步加 CSS）',
    '没被覆盖：' . implode(',', $uncovered));

// ---- ★★ 样式表：注释必须闭合，且不能把规则吞掉 ----
//
// 这一组是补票。曾经出过一次真实事故：`app.css` 里一段解释性注释漏写了
// 结尾的 `*/`，于是它**一路吞掉**后面 10 行选择器 —— `.side-nav`、`.side-foot`
// 全部变成注释文字。结果桌面端后台的侧栏导航整块失去样式：导航项挤成一行
// 51px 宽、没有内边距，看起来就是"后台被改乱了"。
//
// 为什么上面那几条 CSS 断言没抓到：它们用的是 `strpos($css, 'input.copy-field {')`。
// 字符串查找**不区分"这是真规则"还是"这是注释里的文字"** —— 规则被注释吞掉之后，
// strpos 照样返回 true。所以必须按注释状态来判，光看文本在不在是不够的。
//
// 这一条是整个事故唯一**可靠**的判据，来由要说清楚（我实测过）：
// 注释少一个 `*/` 时，浏览器会把从那个 `/*` 开始直到文件末尾之前**所有内容**
// 都当成注释（实测吞掉了 6729 个字符）。于是：
//   - `substr_count('/*') === substr_count('*/')`  → false  ✅ 抓得到
//   - 用 `~/\*.*?\*/~s` 非贪婪摘注释后数花括号     → 仍然"配平" ❌ 抓不到
//     （非贪婪会把那个没闭合的 `/*` 跟**后面**某个 `*/` 配上，吞掉的文本又"回来"了）
//   - 摘完注释再 `strpos('.side-nav {')`            → 仍然 true ❌ 抓不到
// 所以别依赖"摘掉注释再看选择器在不在"，一定要直接查注释是否成对。
// 下面那两条选择器断言只作为辅助提示，真正的闸门是这一条。
ok(substr_count($css, '/*') === substr_count($css, '*/'),
    '★★ app.css 的注释成对闭合（少一个 */ 会把后面整段 CSS 吞成注释）',
    '/* × ' . substr_count($css, '/*') . ' vs */ × ' . substr_count($css, '*/'));

// 辅助：注释成对时，摘掉注释后花括号应当依然配平（能挡掉"注释吃掉半个规则体"这种写法）。
$cssNoComments = (string) preg_replace('~/\*.*?\*/~s', '', $css);
$openBraces  = substr_count($cssNoComments, '{');
$closeBraces = substr_count($cssNoComments, '}');
ok($openBraces === $closeBraces,
    '★ 摘掉注释后花括号配平',
    "{$openBraces} 个 { vs {$closeBraces} 个 }");

// 辅助：这三条是这次被吞掉的选择器，列出来方便出事时一眼定位。
foreach (['.side-nav {', '.side-foot {', '.admin-main {'] as $sel) {
    ok(strpos($cssNoComments, $sel) !== false, '★ ' . $sel . ' 在样式表里');
}

// ---- ★★ 日志留存设置：能配、且不能被静默改动 ----
//
// 这组测的是"界面和保存逻辑"，不是留存本身（那组在上面）。
// 之所以能直接调 admin_save_settings：本文件的 MCFIX_CONFIG 指向临时文件，
// 保存只会写临时配置，碰不到真实 config.php 和数据库。
require_once MCFIX_ROOT . '/public/controllers/admin_actions.php';

$retention = static fn (): int => (int) Config::get('feedback.log_retention_days', 30);

admin_save_settings(['log_retention_days' => '7']);
ok($retention() === 7, '★ 后台能改日志留存天数', '实际 ' . $retention());

admin_save_settings(['log_retention_days' => '0']);
ok($retention() === 0, '★ 可以设成 0（不清理）', '实际 ' . $retention());

admin_save_settings(['log_retention_days' => '-5']);
ok($retention() === 0, '负数被夹到 0，不会变成"删得更快"', '实际 ' . $retention());

admin_save_settings(['log_retention_days' => '99999']);
ok($retention() === 3650, '超大值被夹到上限 3650 天', '实际 ' . $retention());

// ★★ 最关键的一条：一次**不带该字段**的局部提交，不能把它重置成默认值。
// 否则用 curl 单独提交某几个字段时，系统会在管理员毫不知情的情况下
// 开始按 30 天自动删日志 —— 静默的破坏性变更。
admin_save_settings(['log_retention_days' => '3']);
admin_save_settings(['site_name' => '只提交了站点名']);
ok($retention() === 3,
    '★★ 局部提交（不带该字段）不会重置留存天数 —— 防止静默开始删日志',
    '实际 ' . $retention());

// 界面上确实有这一项，否则上一组测的是一条管理员够不着的路
$settingsSrc = (string) file_get_contents(MCFIX_ROOT . '/admin/settings.php');
ok(strpos($settingsSrc, 'name="log_retention_days"') !== false,
    '★ 设置页里有"日志原文留存"输入框');
ok(strpos($settingsSrc, 'log_purged_at IS NOT NULL') !== false,
    '★ 设置页会显示当前清理情况（看得见效果）');

// 维护工具里能立刻触发一次，不用干等 cron
$adminSrc2 = (string) file_get_contents(MCFIX_ROOT . '/public/controllers/admin_actions.php');
ok(strpos($adminSrc2, "'logretention'") !== false, '★ 维护工具里有「清理日志原文」任务');
ok(strpos($settingsSrc, 'value="logretention"') !== false, '★ 设置页上有对应的按钮');

// ---- ★★ 大模型报错提示：别把上游说的话切一半 ----
//
// 真实踩到的例子：接口返回 400，body 是 OpenAI 兼容格式，里面明明白白写着
// 「支持的模型名是 A / B，你传的是 C」。
// 但旧代码把 body 硬截到 200 字，界面上只剩
//   HTTP 400：{"error":{"message":"The supported API model names are example-llm-01, example-llm-002, but you passed Example-LLM-001-Pro. (request_id: 00000000-0000-4000-8000-000000000000)","type":"invalid_request_
// 这种断在中间的 JSON 碎片 —— 答案就在里面，管理员却要自己去猜。
//
// 下面这份 body 是照那条报错**等长**造的（模型名 14 / 15 / 19 字符、request_id 36 字符），
// 所以「截断正好切在 invalid_request_」这个位置能原样复现。
$describe = new ReflectionMethod(\MCFix\AiAdvisor::class, 'describeHttpError');
$describe->setAccessible(true);
$err = static fn (int $code, string $body): array => (array) $describe->invoke(null, $code, $body);

$llmBody = '{"error":{"message":"The supported API model names are example-llm-01, '
    . 'example-llm-002, but you passed Example-LLM-001-Pro. '
    . '(request_id: 00000000-0000-4000-8000-000000000000)","type":"invalid_request_error",'
    . '"param":null,"code":"invalid_request_error"}}';
$got = $err(400, $llmBody);
ok(!empty($got['specific']), '★ 能从 JSON 里认出上游给的具体原因');
ok(strpos($got['text'], 'example-llm-01') !== false,
    '★★ 报错里保留了接口给出的可用模型名（旧代码截断后这句会丢）',
    '实际：' . $got['text']);
ok(strpos($got['text'], 'you passed Example-LLM-001-Pro') !== false,
    '★ 正文完整，没有断在中间');
ok(strpos($got['text'], 'invalid_request_error') === false,
    '★ 只取 message，不再把整个 JSON 糊在界面上');
ok(strpos($got['text'], 'HTTP 400') === 0, '★ 仍然标明 HTTP 状态码');

// 其它常见形状
$got2 = $err(401, '{"error":"Invalid API key"}');
ok(!empty($got2['specific']) && strpos($got2['text'], 'Invalid API key') !== false,
    '★ error 是字符串时也认得出', '实际：' . $got2['text']);

$got3 = $err(429, '{"message":"Rate limit reached"}');
ok(!empty($got3['specific']) && strpos($got3['text'], 'Rate limit reached') !== false,
    '★ 顶层 message 也认得出');

// 不是 JSON（网关的错误页、反代的 HTML）：退回原文，但要剥标签、且必须标成"不具体"
$html = '<html><head><title>502 Bad Gateway</title></head><body><h1>502 Bad Gateway</h1></body></html>';
$got4 = $err(502, $html);
ok(empty($got4['specific']), '★ HTML 错误页被标成"没有具体原因"（调用方会补通用提示）',
    '实际 specific=' . var_export($got4['specific'], true));
ok(strpos($got4['text'], '<html>') === false, '★ HTML 标签被剥掉', '实际：' . $got4['text']);
ok(strpos($got4['text'], '502 Bad Gateway') !== false, '★ 关键内容还在');

$got5 = $err(500, '');
ok(empty($got5['specific']) && strpos($got5['text'], '上游没有返回内容') !== false,
    '★ 空响应体给出可读的说明');

// 报错里不能带出密钥（redact 兜底）
$got6 = $err(401, '{"error":{"message":"bad key sk-abcdef1234567890abcdef"}}');
ok(strpos($got6['text'], 'sk-abcdef1234567890abcdef') === false,
    '★ 报错文案里不会回显密钥', '实际：' . $got6['text']);

// listModels 在没配地址时不该发请求，直接给出可读提示
$noBase = \MCFix\AiAdvisor::listModels();
ok(is_array($noBase) && array_key_exists('ok', $noBase) && array_key_exists('models', $noBase),
    'listModels 返回固定结构（ok / message / models）');

// ---- ★★ 日志里不许出现凭据 ----
//
// 背景：上游服务经常把密钥原样回显在报错里（"Invalid API key: sk-..."），
// 这类文本会被一路带进 app 日志和 events 表。靠每个调用点自觉是守不住的，
// 所以在写日志那一层兜一道。
$rs = static fn (string $t): string => redact_secrets($t);

ok(strpos($rs('Invalid API key: sk-abcdef1234567890abcdef'), 'sk-abcdef') === false,
    '★★ 抹掉 sk- 形态的密钥', '实际：' . $rs('Invalid API key: sk-abcdef1234567890abcdef'));
ok(strpos($rs('token ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ012345'), 'ghp_') === false,
    '★ 抹掉 GitHub 令牌');
ok(strpos($rs('Authorization: Bearer eyJhbGciOiJIUzI1NiJ9payload'), 'eyJhbGciOiJIUzI1NiJ9') === false,
    '★ 抹掉 Bearer 后面的令牌');
ok(strpos($rs('{"api_key":"abcdef1234567890xyz"}'), 'abcdef1234567890xyz') === false,
    '★ 抹掉 api_key= 后面的值');
$longTok = str_repeat('a1b2c3d4', 6);   // 48 位：本项目 HMAC 令牌就是这种形态
ok(strpos($rs('token=' . $longTok), $longTok) === false, '★ 抹掉长随机串（>=40 位）');

// ★ 但**必须保留 IP 和邮箱**。
// 日志里"哪个 IP 被白名单拒了""哪个 IP 登录失败"是排查时最有用的一条信息，
// 抹掉日志就没法用了。全面脱敏应该发生在调用点。
// 这条断言是防止有人把 redact_secrets() "顺手改成"全面脱敏。
ok(strpos($rs('后台访问被 IP 白名单拒绝：203.0.113.9'), '203.0.113.9') !== false,
    '★★ 保留 IP（否则"哪个 IP 被拒"就没法查了）',
    '实际：' . $rs('后台访问被 IP 白名单拒绝：203.0.113.9'));
ok(strpos($rs('投递失败 admin@example.com'), 'admin@example.com') !== false,
    '★ 保留邮箱（邮箱脱敏由调用点用 maskAddress 做，不在这里搞一刀切）');

// app_log 必须真的用它 —— 光有函数不接上去等于没有。
//
// storage_path() 指向项目真实的 storage/（没有环境变量可覆盖），所以这里
// 先记下文件长度，只读新追加的那一段，测完截回原长度 —— 不留痕迹。
$logFile = storage_path('logs') . '/app-' . gmdate('Ymd') . '.log';
$logBefore = is_file($logFile) ? (int) filesize($logFile) : 0;

app_log('warn', '调用失败', ['error' => 'HTTP 401：Invalid API key sk-secret1234567890abcd', 'ip' => '203.0.113.9']);

$logAll = is_file($logFile) ? (string) file_get_contents($logFile) : '';
$appended = substr($logAll, $logBefore);
ok($appended !== '', 'app_log 写出了内容（否则下面几条会空转）');
ok(strpos($appended, 'sk-secret1234567890abcd') === false,
    '★★ app_log 真的把密钥抹掉了（不只是有个没接上的函数）',
    '实际：' . mb_substr($appended, 0, 160));
ok(strpos($appended, '203.0.113.9') !== false, '★ app_log 保留了 IP 便于排查');

// 还原：截回原来的长度（原文件不存在就删掉）
if ($logBefore === 0) {
    @unlink($logFile);
} else {
    $fh = @fopen($logFile, 'r+');
    if ($fh !== false) {
        @ftruncate($fh, $logBefore);
        @fclose($fh);
    }
}
// filesize() 有 stat 缓存，不清理的话读到的还是截断前的大小
clearstatcache(true, $logFile);
ok(!is_file($logFile) || (int) filesize($logFile) === $logBefore,
    '测试没有留下日志痕迹',
    '实际 ' . (is_file($logFile) ? (string) filesize($logFile) : '（文件已删）') . '，期望 ' . $logBefore);

// events 表里不留完整邮箱：邮件测试的收件地址要打码
$mailMsg = '测试邮件：已通过 SMTP 投递给 player@example.com。没收到的话先翻一下垃圾邮件箱。';
$maskedMsg = \MCFix\TicketMail::maskEmailsInText($mailMsg);
ok(strpos($maskedMsg, 'player@example.com') === false,
    '★★ 事件记录里的邮箱被打码（旧行为是把完整地址永久写进 events 表）',
    '实际：' . $maskedMsg);
ok(strpos($maskedMsg, 'pl***@example.com') !== false,
    '★ 打码保留了首 2 字和域名，仍能认出是哪个服务商');

$adminSrc3 = (string) file_get_contents(MCFIX_ROOT . '/public/controllers/admin.php');
ok((bool) preg_match('/email\.test.*maskEmailsInText/s', $adminSrc3)
    || (bool) preg_match('/maskEmailsInText.*email\.test/s', $adminSrc3),
    '★ 邮件测试写事件时确实调用了脱敏（不是只在别处定义了函数）');

// ---- ★★ 日志留存：独立卡片 + 独立动作 ----
//
// 背景：这个设置原本是「玩家反馈」六项里的最后一项，管理员连着两轮都说找不到。
// 现在它有自己的卡片（右下角「日志留存」）和自己的保存动作。
//
// 给它独立动作还有个更硬的理由：admin_save_settings() 是"整表单覆盖"语义，
// 缺字段就取默认值 —— 一个只带 log_retention_days 的局部提交会把别的字段一起重置。
require_once MCFIX_ROOT . '/public/controllers/admin_actions.php';

// 先造一个"其它字段都不该被动"的局面
admin_save_settings([
    'site_name'          => '别动我',
    'console_ip_allow'   => '203.0.113.9, 198.51.100.7',
    'token_ttl'          => '3600',
    'log_retention_days' => '30',
]);
ok((int) Config::get('feedback.token_ttl') === 3600, '准备工作：token_ttl 已设成 3600');
ok(Config::get('admin.ip_allow') === ['203.0.113.9', '198.51.100.7'], '准备工作：白名单已设两项',
    '实际：' . json_encode(Config::get('admin.ip_allow')));

// 用独立动作只改留存天数
$ret = admin_save_log_retention(['log_retention_days' => '14']);
ok(!empty($ret['ok']), '独立动作保存成功', (string) ($ret['message'] ?? ''));
ok((int) Config::get('feedback.log_retention_days') === 14, '★ 留存天数已改成 14');

// ★★ 关键：别的字段一个都不能动
ok((string) Config::get('app.name') === '别动我', '★★ 独立动作没有碰站点名');
ok((int) Config::get('feedback.token_ttl') === 3600, '★★ 独立动作没有碰链接有效期');
ok(Config::get('admin.ip_allow') === ['203.0.113.9', '198.51.100.7'],
    '★★ 独立动作没有把后台 IP 白名单清空',
    '实际：' . json_encode(Config::get('admin.ip_allow')));

// 缺字段要拒绝，而不是"当成 0"
$bad = admin_save_log_retention([]);
ok(empty($bad['ok']), '★ 没收到字段时明确报错（0 是有含义的值，不能跟"没填"混淆）');
ok((int) Config::get('feedback.log_retention_days') === 14, '被拒绝的请求没有改动任何东西');

// ★★ 顺带修的：局部提交不能清空后台白名单
// 这是 admin_save_settings 里一直存在的隐患 —— 它是"整表单覆盖"语义。
admin_save_settings(['site_name' => '只提交了站点名']);
ok(Config::get('admin.ip_allow') === ['203.0.113.9', '198.51.100.7'],
    '★★ 不带白名单字段的局部提交不会把后台白名单清空（那等于关掉访问控制）',
    '实际：' . json_encode(Config::get('admin.ip_allow')));

// 界面上确实有独立卡片和按钮，否则前面测的是一条管理员够不着的路
$settingsSrc2 = (string) file_get_contents(MCFIX_ROOT . '/admin/settings.php');
ok(strpos($settingsSrc2, '<h2>日志留存</h2>') !== false, '★ 设置页有独立的「日志留存」卡片');
ok(strpos($settingsSrc2, 'value="save_log_retention"') !== false, '★ 卡片里有保存按钮');
ok(strpos($settingsSrc2, 'name="log_retention_days"') !== false, '★ 卡片里有留存天数输入框');
ok(substr_count($settingsSrc2, 'name="log_retention_days"') === 1,
    '★ 这个字段只出现一次（不会出现两个输入框互相覆盖）',
    '出现 ' . substr_count($settingsSrc2, 'name="log_retention_days"') . ' 次');
ok(strpos($settingsSrc2, 'value="logretention"') !== false, '★ 卡片里有「立即清理一次」');

// ---- ★★ 审计回合：几处加固 + 两条被否掉的"高危" ----
//
// 两条被实测否掉的结论也写成断言 —— 它们描述的**确实是**危险情形，
// 只是当前实现不成立。留着断言，以后谁把 fail-closed 改掉会立刻被发现。

// (1) 密钥不可用时，令牌必须一律拒绝。
// 审计意见说"hmac() 返回空串 → hash_equals('', 签名) 对空签名成立 →
// 任意 1 字符签名即可通过"。前半段的机制是真的，但结论不成立：
// 空签名在 Token.php:124 就被拒了，而 hash_equals('', 'x') 是 false。
ok(hash_equals('', '') === true, '前提：hash_equals 对两个空串返回 true');
ok(hash_equals('', 'x') === false, '★ 但空串和非空串不相等 —— 所以"任意 1 字符签名可过"不成立');

$realSecret = (string) Config::get('app.hmac_secret', '');
$realServers = (array) Config::get('servers', []);
// 把全局密钥和所有服务器密钥都变成不可用的占位值
Config::set('app.hmac_secret', 'CHANGE_ME_TO_A_RANDOM_STRING');
Config::set('servers', []);

$forgedPayload = rtrim(strtr(base64_encode('{"s":"a","d":["survival"],"t":9999999999}'), '+/', '-_'), '=');
foreach (['x', '0', 'AAAA', $forgedPayload] as $sig) {
    ok(Token::parse($forgedPayload . '.' . $sig, 'a') === null,
        '★★ 密钥不可用时，伪造签名被拒（' . mb_substr($sig, 0, 8) . '）');
}
ok(Token::parse($forgedPayload . '.', 'a') === null, '空签名同样被拒（第 124 行）');
// 用占位密钥真签一个也不行 —— sign() 是私有的，用反射调它
$signMethod = new ReflectionMethod(\MCFix\Token::class, 'sign');
$signMethod->setAccessible(true);
$signedWithPlaceholder = (string) $signMethod->invoke(null, Token::SCOPE_AGENT, ['survival', time() + 9999], 'CHANGE_ME_TO_A_RANDOM_STRING');
ok($signedWithPlaceholder !== '' && Token::parse($signedWithPlaceholder, 'a') === null,
    '用公开占位密钥签出来的令牌被拒（fail closed 生效）');

Config::set('app.hmac_secret', $realSecret);
Config::set('servers', $realServers);
ok(Token::parse(Token::forVerify(['id' => 7, 'server_id' => 'unit', 'token_nonce' => 'n']), 'v') !== null,
    '恢复密钥后令牌又能正常校验（确认上面的配置改动没有污染后续测试）');

// (2) 来源 IP 取自哪里，必须能看出来
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.5';
Config::set('trusted_proxies', []);
$src = client_ip_source();
ok($src['source'] === 'remote' && $src['ip'] === '203.0.113.9',
    '★ REMOTE_ADDR 不在可信代理里时，转发头被忽略', json_encode($src, JSON_UNESCAPED_UNICODE));

Config::set('trusted_proxies', ['127.0.0.1', '::1']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$src2 = client_ip_source();
ok($src2['source'] === 'xff' && $src2['ip'] === '198.51.100.5',
    '★ 只有 REMOTE_ADDR 真的落在可信代理里才采信 XFF（后台会把这个结论显示出来）');
ok(strpos($src2['note'], '伪造') !== false, '★ 采信 XFF 时明确提示它可被伪造');

// (3) 后台会话必须有绝对有效期
// 老代码里 console_since 只写不读，等于永不过期。
unset($_SESSION);
$_SESSION = [];
$login = \MCFix\ConsoleAuth::login('test-password');
ok(!empty($login['ok']), '测试准备：能登录', (string) ($login['error'] ?? ''));
ok($_SESSION['console_since'] > 0, '★ 登录时记下了会话起始时间');
ok(\MCFix\ConsoleAuth::isAuthed() === true, '刚登录时是已认证状态');
$_SESSION['console_since'] = time() - (8 * 86400);   // 8 天前
ok(\MCFix\ConsoleAuth::isAuthed() === false,
    '★★ 超过 7 天的会话被判定为未认证（旧代码永不过期）');
ok(empty($_SESSION['console_authed']), '并且确实清掉了登录态（不是只返回 false）');

// 老会话（升级前建立的）不该被一脚踢掉
unset($_SESSION);
$_SESSION = [];
$_SESSION['console_authed'] = true;
$_SESSION['console_fp'] = null;   // 下面登录补上
$login2 = \MCFix\ConsoleAuth::login('test-password');
unset($_SESSION['console_since']);            // 模拟老会话：没有这个键
ok(\MCFix\ConsoleAuth::isAuthed() === true, '★ 缺 console_since 的老会话补记时间后仍然有效（升级不踢人）');
ok(!empty($_SESSION['console_since']), '并且把时间补上了');

// (4) 登出必须 POST + CSRF；Agent 上报必须带租约令牌
$adminSrc4 = (string) file_get_contents(MCFIX_ROOT . '/public/controllers/admin.php');
ok((bool) preg_match("/adminPage === 'logout'.*?!is_post\(\)/s", $adminSrc4),
    '★★ 登出改为只接受 POST（GET 会被 <img src> 拿来做 CSRF 登出）');
ok((bool) preg_match("/adminPage === 'logout'.*?checkCsrf/s", $adminSrc4),
    '★ 登出前校验 CSRF 令牌');
ok(strpos($adminSrc4, 'side-link-btn') !== false, '★ 侧栏的登出改成了表单按钮');
ok(strpos($adminSrc4, "p=logout')) \">退出登录</a>") === false, '★ 老的 GET 登出链接已经不在了');

$agentSrc = (string) file_get_contents(MCFIX_ROOT . '/public/controllers/agent.php');
ok((bool) preg_match("/leaseToken === ''/", $agentSrc),
    '★★ Agent 上报必须带租约令牌（空租约会跳过 Task::report 里的校验）');

// (5) 下载响应的文件名不能再拼进 header
$modSrc = (string) file_get_contents(MCFIX_ROOT . '/src/ModLibrary.php');
ok(strpos($modSrc, 'filename="\' . str_replace(\'"\', \'\', $filename)') === false,
    '★ Content-Disposition 不再只做引号替换（要连控制字符一起去掉）');
ok((bool) preg_match('/preg_replace\(.*\\\\r\\\\n.*\$filename/s', $modSrc),
    '★ 文件名进 header 前过滤了 CR/LF');

// ---- ★★ 审批闸门必须罩住**所有**通道 ----
//
// 这次审计最严重的一条：$needsApproval 只传给了 dispatchToAgent()，
// RCON / 面板控制台 / 面板电源 / local / ssh 五条通道全都无视它直接执行。
// 后果是玩家提交一句"我被误封了"，走 RCON 的服务器上 `pardon <玩家>` 立刻发出去，
// 而工单上写着"已提交管理员确认"、管理员的待批准列表里空空如也。
//
// 这里用**真实执行路径**验证：造一台配了 RCON 的服务器，要求带审批执行
// unban_player，断言它只排队、不执行。
$gateServer = [
    'id'       => 'gate-test',
    'name'     => '审批闸门测试',
    'executor' => 'agent',            // 以前只有这条通道带审批
    'rcon'     => ['enabled' => true, 'host' => '127.0.0.1', 'port' => 25575, 'password' => 'x', 'timeout' => 1],
    'recipes'  => [],
];

$before = (int) Db::scalar('SELECT COUNT(*) FROM tasks');
$gated = \MCFix\Executor::repair($gateServer, 'unban_player', ['player' => 'TestPlayer'], null, true);
$after = (int) Db::scalar('SELECT COUNT(*) FROM tasks');

ok((string) $gated['status'] === \MCFix\Task::STATUS_AWAITING_APPROVAL,
    '★★ 要求审批的动作返回"等待批准"状态', '实际 ' . $gated['status']);
ok(!empty($gated['response']['awaiting_approval']),
    '★★ 响应里明确标了 awaiting_approval');
ok($after === $before + 1, '★★ 它只排了一条任务（没有立刻执行）', "任务数 $before -> $after");

$queued = Db::first('SELECT * FROM tasks ORDER BY id DESC LIMIT 1');
ok((int) $queued['requires_approval'] === 1, '★★ 排进去的任务标了 requires_approval=1');
ok(!in_array((string) $queued['status'], [\MCFix\Task::STATUS_DONE, \MCFix\Task::STATUS_FAILED], true),
    '★★ 任务没有被执行（不能是 done/failed）', '实际 ' . $queued['status']);
ok((string) $queued['recipe'] === 'unban_player', '排的是正确的配方');

// 反面：不需要审批时，闸门不能把正常动作也拦下来。
// 注意数量不能写死 +1 —— 这条配方会先走 RCON 通道（失败后也留一条任务记录），
// 再落到 agent 通道，所以可能 +2。这里只断言"确实下发了"。
$before2 = (int) Db::scalar('SELECT COUNT(*) FROM tasks');
$notGated = \MCFix\Executor::repair($gateServer, 'save_world', [], null, false);
$after2 = (int) Db::scalar('SELECT COUNT(*) FROM tasks');
ok($after2 > $before2, '★ （对照）不需要审批的动作照常下发', "任务数 $before2 -> $after2");
ok((string) $notGated['status'] !== \MCFix\Task::STATUS_AWAITING_APPROVAL,
    '★ （对照）它不会被误标成"等待批准"', '实际 ' . $notGated['status']);

// 收尾
foreach (Db::all("SELECT id FROM tasks WHERE server_id = 'gate-test'") as $t) {
    Db::delete('tasks', ['id' => (int) $t['id']]);
}

// ---- ★★ Agent 令牌不能是公开占位值 ----
ok(\MCFix\Token::isPlaceholderSecret('CHANGE_ME_AGENT_TOKEN'),
    '★★ 公开仓库里的 Agent 占位令牌被认出来');
ok(\MCFix\Token::isPlaceholderSecret('CHANGE_ME_TO_A_RANDOM_STRING'), '密钥占位值同样被认出来');
ok(!\MCFix\Token::isPlaceholderSecret(str_repeat('k', 32)), '随机令牌不会被误判');

// 真的拿占位令牌去认证，必须失败
Config::set('servers', ['ph' => ['id' => 'ph', 'agent_token' => 'CHANGE_ME_AGENT_TOKEN']]);
ok(\MCFix\Agent::auth('ph', 'CHANGE_ME_AGENT_TOKEN') === false,
    '★★ 用占位令牌认证 Agent 被拒（旧代码只拒绝空串）');
Config::set('servers', $realServers);

// ---- ★★ pull_mod 不能变成任意文件下载器 ----
$apiSrc = (string) file_get_contents(MCFIX_ROOT . '/public/controllers/api.php');
ok(strpos($apiSrc, 'stripos($component, $name)') === false,
    '★★ 取回文件的名单不再做双向子串匹配（2 字符的名字能匹配走任意 jar）');
ok((bool) preg_match('/mb_strlen\(\$name\) < 4/', $apiSrc),
    '★★ 名单里过短的名字被跳过（"ab" 这种不是有效的模组名）');
ok(strpos($apiSrc, 'client_missing') !== false,
    '★★ 有服务端清单时只认服务端来源的名字（不是玩家在日志里随手写的）');

// ---- ★★ 站点图标 / 邮件 Logo ----

// 没上传过时 favicon 要有内置默认（inline SVG），不能是空的 link
$favTag = \MCFix\Brand::faviconTag();
ok(strpos($favTag, '<link rel="icon"') !== false, '★ 没上传图标时也输出 link 标签');
ok(strpos($favTag, 'data:image/svg+xml') !== false, '★ 默认回退是内联 SVG（零额外请求）');
ok(\MCFix\Brand::get('favicon') === null || is_array(\MCFix\Brand::get('favicon')),
    '★ Brand::get 对未知槽位/空槽位返回 null 而不是抛错');

// 上传校验：这条路走的是"用户给的字节"，每一条拒绝都要有理由。
//
// 注意这里测的是 Brand::inspect()，不是 store() —— store() 第一句是
// is_uploaded_file()，那个函数只对真正的 multipart 上传返回 true，
// 测试里造不出来。校验逻辑必须能被单独调用，否则这几条根本测不到。
$svg = MCFIX_ROOT . '/storage/cache/probe.svg';
$txt = MCFIX_ROOT . '/storage/cache/probe.txt';
$png = MCFIX_ROOT . '/storage/cache/probe.png';
$big = MCFIX_ROOT . '/storage/cache/probe-big.png';
@mkdir(dirname($svg), 0777, true);
file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
file_put_contents($txt, 'this is definitely not an image');
// 1×1 的真 PNG（能被 getimagesize 认出来）
file_put_contents($png, base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
));

// 标题左边的图标（.brand-mark）和标签页图标必须出自同一张图。
//
// 用户反馈原话是"站点图标是故障反馈中心左边这个图标" —— 之前只改了
// <link rel="icon">（浏览器标签页），标题左边那个 ⛏ 还是写死在模板里的。
// 所以两个方向都要测：没传时是内置的 ⛏，传了之后必须换成那张图。
$markTag = \MCFix\Brand::markTag();
ok($markTag !== '', '★ 没传过图标时 brand-mark 有内置内容（⛏）');
ok(strpos($markTag, '<img') === false, '★ （对照）没传过时不会凭空输出一张图');

$brandDir = \MCFix\Brand::dir();
$stray = $brandDir . '/favicon.png';
$createdStray = false;
// Brand::get() 有请求内缓存，换过文件必须让它重读，否则测的是缓存
$bustBrandMemo = static function (): void {
    $prop = new ReflectionProperty(\MCFix\Brand::class, 'memo');
    $prop->setAccessible(true);
    $prop->setValue(null, []);
};

if (\MCFix\Brand::get('favicon') === null && !is_file($stray)) {
    @mkdir($brandDir, 0777, true);
    $createdStray = @copy($png, $stray);
}

if ($createdStray) {
    $bustBrandMemo();
    $markWithIcon = \MCFix\Brand::markTag();
    ok(strpos($markWithIcon, '<img') !== false, '★★ 传过站点图标后 brand-mark 显示那张图');
    ok(strpos($markWithIcon, '/brand/favicon.png') !== false,
        '★★ 图片指向 /brand/ 静态文件（图标每次加载都要取，不该走 PHP 路由）');
    ok(strpos(\MCFix\Brand::faviconTag(), '/brand/favicon.png') !== false,
        '★★ 标签页图标和标题图标是同一张（管理员传一次，两处一起变）');

    // 收尾：测试绝不能把图留在站点上 —— 留了的话下次跑会以为"本来就传过"，
    // 而且真部署上去就凭空多出一个图标。
    @unlink($stray);
    $bustBrandMemo();
    ok(\MCFix\Brand::get('favicon') === null, '（收尾）临时图标已删除，站点回到"没传过"');
} else {
    ok(true, '（跳过）public/brand 不可写，跳过"传过图标"那一组 —— 不假装测过');
}

$r = \MCFix\Brand::inspect($svg);
ok(empty($r['ok']), '★★ SVG 被拒绝（SVG 能内嵌脚本，会被同源地发出去）');
ok(strpos((string) $r['error'], 'SVG') !== false,
    '★★ 拒绝 SVG 时点名说了 SVG 和原因（不然管理员会以为文件坏了，反复重传）',
    '实际：' . (string) $r['error']);

$r = \MCFix\Brand::inspect($txt);
ok(empty($r['ok']), '★★ 非图片文件被拒绝（按文件头判断，不信任扩展名和 Content-Type）');
ok(strpos((string) $r['error'], 'PNG') !== false, '★ 拒绝非图片时列出了支持的格式',
    '实际：' . (string) $r['error']);

// 真图片必须放行，否则前面那些"拒绝"可能只是拒绝了一切
$r = \MCFix\Brand::inspect($png);
ok(!empty($r['ok']), '★★ 真 PNG 通过校验（反向对照：不是把什么都拒了）',
    '实际：' . (string) ($r['error'] ?? ''));
ok(($r['ext'] ?? '') === 'png' && ($r['mime'] ?? '') === 'image/png',
    '★ 识别出的扩展名和 MIME 正确');
ok((int) ($r['width'] ?? 0) === 1 && (int) ($r['height'] ?? 0) === 1,
    '★ 尺寸读对了（1×1）');

// 超大文件：造一个真的超过上限的文件，走的是真实 filesize 而不是 $_FILES
$fh = @fopen($big, 'wb');
if ($fh) {
    fwrite($fh, str_repeat('A', \MCFix\Brand::MAX_BYTES + 1024));
    fclose($fh);
}
$r = \MCFix\Brand::inspect($big);
ok(empty($r['ok']) && strpos((string) $r['error'], 'KB') !== false,
    '★ 超过体积上限被拒绝，并告诉管理员上限是多少', '实际：' . (string) $r['error']);

$r = \MCFix\Brand::store('nonsense', ['error' => UPLOAD_ERR_OK, 'tmp_name' => $png]);
ok(empty($r['ok']), '★ 未知槽位被拒绝');

$r = \MCFix\Brand::store('favicon', null);
ok(empty($r['ok']), '★ 没收到文件时返回失败而不是警告');

$r = \MCFix\Brand::store('favicon', ['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '']);
ok(empty($r['ok']) && strpos((string) $r['error'], '没有选择文件') !== false,
    '★ 没选文件时给的是"没有选择文件"，不是笼统失败');

$r = \MCFix\Brand::inspect(MCFIX_ROOT . '/storage/cache/does-not-exist.png');
ok(empty($r['ok']), '★ 文件不存在时不会抛警告');

@unlink($svg);
@unlink($txt);
@unlink($png);
@unlink($big);

// 邮件 MIME：没有 Logo 时结构必须与加这个功能之前完全一致
$plain = \MCFix\Mail::buildMessage(['a@example.com'], 's', '<p>hi</p>', 'hi', 'f@example.com', 'F');
ok(strpos($plain, 'multipart/alternative') !== false, '★★ 没有 Logo 时仍然是 multipart/alternative（行为不变）');
ok(strpos($plain, 'multipart/related') === false, '★★ 没有 Logo 时不引入 multipart/related');

// 有 Logo 时：外层 related，内层 alternative，并且带 Content-ID
$img = ['mime' => 'image/png', 'data' => "\x89PNG\r\n\x1a\nFAKE", 'name' => 'logo.png'];
$withLogo = \MCFix\Mail::buildMessage(
    ['a@example.com'],
    's',
    '<img src="cid:' . \MCFix\Mail::LOGO_CID . '">',
    'hi',
    'f@example.com',
    'F',
    '',
    [\MCFix\Mail::LOGO_CID => $img]
);
ok(strpos($withLogo, 'multipart/related') !== false, '★ 带 Logo 时外层是 multipart/related');
ok(strpos($withLogo, 'multipart/alternative') !== false, '★ 内层仍保留 multipart/alternative（纯文本兜底还在）');
ok(strpos($withLogo, 'Content-ID: <' . \MCFix\Mail::LOGO_CID . '>') !== false, '★★ Logo 用 Content-ID 内嵌（不是远程地址）');
ok(strpos($withLogo, 'Content-Disposition: inline') !== false, '★ 内嵌图标记为 inline');
ok(strpos($withLogo, base64_encode($img['data'])) !== false, '★ 图片内容确实进了邮件体');

// 两个 boundary 不能是同一个，否则客户端解析必然出错
preg_match_all('/boundary="([^"]+)"/', $withLogo, $bm);
ok(count($bm[1]) === 2 && $bm[1][0] !== $bm[1][1],
    '★★ related 和 alternative 用的是两个不同的 boundary',
    '实际：' . implode(' / ', $bm[1]));

// 邮件 HTML 里引用 Logo 的条件：只有真的传过图才插 <img>
$tplSrc = (string) file_get_contents(MCFIX_ROOT . '/src/TicketMail.php');
ok(strpos($tplSrc, 'cid:') !== false, '★ 邮件模板用 cid: 引用 Logo');
ok((bool) preg_match('/Brand::get\(.logo.\).*?\$logoHtml/s', $tplSrc),
    '★ 只有存在 Logo 时才生成那段 HTML（没传过不会出现空 img）');

// =====================================================================
// 暴力安全测试报告的 P0 修复（2026-09 那一轮）
//
// 这组断言对应报告里的 5 个高危。每条都先确认过报告结论与真实源码一致，
// 再改代码 —— 报告给的行号有几处对不上，直接照抄会改错地方。
// =====================================================================

// ---- V1：匿名不能把任意 MC 账号加进白名单 ----
//
// 报告实测：匿名 POST 一单 category=permission，服务端真的执行了
// `whitelist add GrieferAlt01`，响应里还是 "awaiting_approval": false。
//
// 根因是 whitelist_add 被标成 medium，而 needsApproval() 只对 high 生效。
$wlRecipe = \MCFix\Recipe::get('whitelist_add');
ok((string) ($wlRecipe['risk'] ?? '') === 'high',
    '★★ V1：whitelist_add 是 high 风险（白名单=准入闸门，不能匿名触发）',
    '实际：' . (string) ($wlRecipe['risk'] ?? '(缺失)'));

// 行为断言：必须真的要走审批。这一条才是"漏洞被堵住"的判据，
// 光看 risk 字符串是间接证据。
$plainServer = ['id' => 'unit', 'executor' => 'none'];
ok(\MCFix\Recipe::needsApproval($plainServer, 'whitelist_add') === true,
    '★★ V1：whitelist_add 现在需要管理员批准（匿名请求改不动白名单）');

// 负向对照：别把整个自动化都关掉 —— 低风险动作仍应自动执行
ok(\MCFix\Recipe::needsApproval($plainServer, 'save_world') === false,
    '★ V1 对照：低风险动作（保存世界）仍然自动执行，没有被一起锁住');

// ---- V2：默认不信任转发头 ----
//
// 报告实测：配置 ip_allow=['203.0.113.7']，攻击者 REMOTE_ADDR=127.0.0.1，
// 只要加一个 X-Forwarded-For: 203.0.113.7 就拿到了后台登录页；
// 并且每次换一个 XFF 就能无限爆破密码（失败锁定永不触发）。
//
// 根因：trusted_proxies 默认信任回环，而很多部署下 REMOTE_ADDR 恒为 127.0.0.1。
$exampleSrc = (string) file_get_contents(MCFIX_ROOT . '/config.example.php');
ok((bool) preg_match("/'trusted_proxies'\s*=>\s*\[\s*\]/", $exampleSrc),
    '★★ V2：示例配置里 trusted_proxies 默认为空数组');

$installSrc = (string) file_get_contents(MCFIX_ROOT . '/public/controllers/install.php');
ok((bool) preg_match("/'trusted_proxies'\s*=>\s*\[\s*\]/", $installSrc),
    '★★ V2：安装向导写出的也是空数组（否则新装站点照样中招）');

$helperSrc = (string) file_get_contents(MCFIX_ROOT . '/src/helpers.php');
ok(!preg_match("/Config::get\('trusted_proxies',\s*\['127\.0\.0\.1'/", $helperSrc),
    '★★ V2：client_ip()/client_ip_source() 的兜底默认值不再是回环地址');

// 行为断言：默认配置下，伪造 XFF 不能改变来源判断
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '127.0.0.1';
Config::set('trusted_proxies', []);
ok(client_ip() === '203.0.113.9',
    '★★ V2：未配置可信代理时，X-Forwarded-For 被完全忽略',
    '实际：' . client_ip());

// 负向对照：显式配置了可信代理，仍要能正常工作（别把功能修坏）
Config::set('trusted_proxies', ['203.0.113.9']);
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
ok(client_ip() === '198.51.100.7',
    '★ V2 对照：显式信任代理时，仍然采信转发头（反向代理部署不受影响）',
    '实际：' . client_ip());
Config::set('trusted_proxies', []);
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// ---- V5：JSON 参数不能拼进 shell 命令行 ----
//
// 报告实测（Windows）：escapeshellarg() 会把 JSON 里的双引号全部删掉，
// Agent 收到 `{ action : diag ... }` → json_decode 失败 → 一律回
// "任务 JSON 解析失败"。而失败被降级成 unknown，Verdict 对 unknown 不分类，
// 对外表现为"没有验证到异常" —— 故障被伪装成了正常结论。
$execSrc = (string) file_get_contents(MCFIX_ROOT . '/src/Executor.php');

// local 通路：必须用 argv 数组，且不能再用 escapeshellarg(json_encode(...))
ok((bool) preg_match('/--local-task\',\s*\n\s*\(string\)\s*json_encode/', $execSrc)
    || strpos($execSrc, "'--local-task',") !== false,
    '★★ V5：local 通路用 argv 数组传任务 JSON（Windows 上才不会被删引号）');
ok(strpos($execSrc, 'escapeshellarg((string) json_encode(') === false,
    '★★ V5：没有任何地方还在用 escapeshellarg(json_encode(...))');

// executeViaShell 必须接受数组
ok((bool) preg_match('/@param\s+string\|array<int,string>\s+\$command/', $execSrc),
    '★ V5：executeViaShell 的注释声明支持数组');
ok((bool) preg_match('/function executeViaShell\([^)]*\$command[^)]*\)/', $execSrc)
    && strpos($execSrc, '$command') !== false,
    '★ V5：executeViaShell 接受数组命令');

// 失败分支不能把数组直接当字符串拼（会触发 Array to string notice）
ok(strpos($execSrc, "is_array(\$command) ? implode(' ', \$command) : \$command") !== false,
    '★★ V5：启动失败时不会对数组命令做字符串拼接');

// 存在取原始路径的方法（数组形式不能传已经转义过的路径）
ok(strpos($execSrc, 'function phpBinaryPath()') !== false,
    '★ V5：提供未转义的 PHP 路径（数组形式不能带 shell 引号）');

// ---- V15：熔断必须能自愈 ----
//
// 报告给了四步死锁证明：计数无时间窗 → 清零需要一次成功记录 →
// 熔断在写记录之前 return → 那条成功记录永远不可能产生。
$wfSrc = (string) file_get_contents(MCFIX_ROOT . '/src/Workflow.php');
ok(strpos($wfSrc, 'circuit_window_seconds') !== false,
    '★★ V15：熔断计数加了时间窗（否则"上个月连爆 3 次"会永久锁死自动修复）');
ok(strpos($wfSrc, 'function resetCircuitBreaker') !== false,
    '★★ V15：提供显式"解除熔断"的方法');
ok(strpos($wfSrc, 'circuit-reset-') !== false,
    '★ V15：解除时间点有落地（缓存文件），判定时能读到');

$adminActionsSrc = (string) file_get_contents(MCFIX_ROOT . '/public/controllers/admin.php');
ok(strpos($adminActionsSrc, "case 'reset_circuit_breaker':") !== false,
    '★★ V15：后台有解除熔断的动作入口（不然管理员只能改库）');

$serversSrc = (string) file_get_contents(MCFIX_ROOT . '/admin/servers.php');
ok(strpos($serversSrc, 'reset_circuit_breaker') !== false,
    '★ V15：后台服务器页有解除熔断的按钮');
ok(strpos($serversSrc, 'consecutiveFailures') !== false,
    '★ V15：后台能看见熔断状态（否则和"功能坏了"无法区分）');

// 熔断分支必须留下事件痕迹，且 error 里带可识别关键词
ok(strpos($wfSrc, "'fix.breaker'") !== false,
    '★★ V15：熔断时记录事件（以前只 escalate，玩家侧看不出为什么没修）');
ok(strpos($wfSrc, '已熔断') !== false,
    '★ V15：错误文案里带「已熔断」关键词，前端才能和普通失败区分');

// ---- V18：玩家侧不回传服务端 mod 文件清单 ----
//
// 报告实测：日志章节名由提交者自编，写一个假 mod 就能让服务端清单里
// **每一个**文件（11/11，精确到构建号）都进"你缺少"列表。
$playerWfSrc = $wfSrc;
ok(!preg_match("/'missing'\s*=>\s*array_slice\(/", $playerWfSrc),
    '★★ V18：玩家侧 cross.missing 不再回传文件名清单');
ok(strpos($playerWfSrc, "'missing_count'") !== false,
    '★ V18：改为只给数量（missing_count）');

$caSrc = (string) file_get_contents(MCFIX_ROOT . '/src/ClientAdvisor.php');
ok(strpos($caSrc, "'extra'  => [],") !== false
    && !preg_match("/'extra'\s*=>\s*array_slice\(\\\$cross\['client_missing'\]/", $caSrc),
    '★★ V18：issues[].extra 不再携带服务端文件名');
ok(!preg_match('/foreach \(\(array\) \(\\\$cross\[.client_missing.\] \?\? \[\]\) as \\\$file\)/', $caSrc)
    || strpos($caSrc, '★ 这里**不能**把 client_missing') !== false,
    '★★ V18：components 不再把服务端清单当成"玩家要装的 MOD"来解析');

// ---- V19：不能一边说"进程不存在"一边说"服务器在线" ----
//
// 报告实测：13/15 个分类产出 process_missing/critical，其中 11 个
// 同时报告"服务器在线：3/100 人"，并且挂上重启建议。
$verdictSrc = (string) file_get_contents(MCFIX_ROOT . '/src/Verdict.php');
ok(strpos($verdictSrc, 'function guardAgainstSelfContradiction') !== false,
    '★★ V19：有"自相矛盾"的通用不变式（不只补一个 case）');
ok(strpos($verdictSrc, 'guardAgainstSelfContradiction($checks, $issue)') !== false,
    '★★ V19：不变式接在 evaluate() 的汇总层（每个 case 都过一遍）');
ok(strpos($verdictSrc, "'process_unverifiable'") !== false,
    '★★ V19：process 看不到进程但有反证时，降级为待确认');

// evidenceServerAlive 必须把 mc_status 也算作反证 ——
// 报告给的行级补丁只在 case 'process' 里调它，但当时它并不看 mc_status，
// 于是"玩家能连上"这个最强证据根本进不了判定，补丁形同虚设。
//
// 取函数体时先剥注释：这个函数里解释"为什么要补 mc_status"的注释比代码还长，
// 按固定字节数切片会让守卫随注释增删而忽真忽假 —— 那测的就是注释长度了。
$aliveFn = function_body(php_code_only($verdictSrc), 'function evidenceServerAlive');
ok(strpos($aliveFn, "checks['mc_status']") !== false,
    '★★ V19：evidenceServerAlive 采信 MC 协议握手成功（报告原补丁没覆盖这点）');
ok(function_body(php_code_only($verdictSrc), 'function evidenceServerAlive') !== '',
    '（前置）确实取到了 evidenceServerAlive 的函数体');

// ---- 顺带：报告确认"设计行为、不是漏洞"的那条别改坏 ----
// server_id="" 表示纯客户端问题，是设计，不是漏洞。
ok(resolve_locked_server_id('', '', '', static fn(string $id): bool => in_array($id, ['unit'], true)) === '',
    '★ 报告确认的"空 server_id 是设计行为"仍然成立（纯客户端工单不被挡）');

// ---- V20：不能把日志里"提到某词"当成"对你下了某个结论" ----
//
// 报告实测：把 `mod config 白名单 related text` 写成普通日志行（不带 [CHAT]、
// 不带 `<name> ` 形式），系统就对玩家说"服务端明确提示你不在白名单里"。
// `Steve was banned by an operator`（别人被封）→ 告诉提交者"你的账号已被封禁"。
//
// 根因：正则里认裸的「白名单」「封禁」两字，而且 detail 用断言式措辞。
$clientLogRaw = (string) file_get_contents(MCFIX_ROOT . '/src/ClientLog.php');
ok((bool) preg_match("/'whitelist_kick'\\s*=>\\s*'\\/\\(\\?:/", $clientLogRaw),
    '★★ V20：whitelist_kick 用的是具体措辞（不认裸的"白名单"两字）');
ok(!preg_match("/'whitelist_kick'\\s*=>\\s*'\\/[^']*\\|白名单\\/i'/", $clientLogRaw),
    '★★ V20：whitelist_kick 里没有裸的 |白名单 分支');
ok(!preg_match("/'banned_kick'\\s*=>\\s*'\\/[^']*\\|封禁\\/i'/", $clientLogRaw),
    '★★ V20：banned_kick 里没有裸的 |封禁 分支');

// 行为断言：真正该认的措辞仍然认（别把功能一刀切掉）
$wlRe = '/(?:You are not white(?:-)?listed|You are not whitelisted on this server|Kicked:\s*.*not white(?:-)?listed|不在服务器的?白名单)/i';
$bnRe = '/(?:You are banned from this server|You have been banned|Banned by an operator:\s*You|(?:你|您)(?:的)?(?:账号|帐号|账户)?已被?(?:服务器)?封禁)/i';
ok((bool) preg_match($wlRe, 'You are not whitelisted on this server!'),
    '★ V20 对照：真正的白名单踢出消息仍能被识别');
ok((bool) preg_match($bnRe, 'You are banned from this server!'),
    '★ V20 对照：真正的封禁消息仍能被识别');
ok(!preg_match($wlRe, 'mod config 白名单 related text'),
    '★★ V20 行为：报告里那句假日志不再被判成"你不在白名单"');
ok(!preg_match($bnRe, 'Steve was banned by an operator'),
    '★★ V20 行为：别人被封不再被判成"你的账号已被封禁"');

// 措辞层面：detail 不能再把文本匹配讲成"服务端明确提示"
$caSrc2 = (string) file_get_contents(MCFIX_ROOT . '/src/ClientAdvisor.php');
ok(strpos($caSrc2, '服务端明确提示你不在白名单里') === false,
    '★★ V20：不再用「服务端明确提示」这种断言式措辞（那只是文本匹配）');
ok(strpos($caSrc2, '服务端提示你的账号已被封禁') === false,
    '★★ V20：封禁结论的措辞同样改掉了');

// ---- V3：后台"隐藏路径"不能是公开字面量的纯函数 ----
//
// 报告实测：离线（零请求）算出了真实后台地址 /console-56be008f366b，
// 访问返回 2131 字节的登录页；控制组的随机路径返回的是 10574 字节的反馈页。
// 根因：路径 = sha256('console-path|' + hmac_secret) 前 12 位，
// 而 ConsoleAuth 只特判了 1 个占位符，Token 里那份名单有 6 个。
$caRaw = (string) file_get_contents(MCFIX_ROOT . '/src/ConsoleAuth.php');
ok(strpos($caRaw, 'Token::isPlaceholderSecret($seed)') !== false,
    '★★ V3：ConsoleAuth 复用 Token 那份统一占位符名单（不再自己维护子集）');
ok(strpos($caRaw, "\$seed === 'CHANGE_ME_TO_A_RANDOM_STRING'") === false,
    '★★ V3：不再只特判一个字面量（"名单有两份，就一定会漏"）');
ok(strpos($caRaw, 'PLACEHOLDER_SECRETS') === false,
    '★ V3：ConsoleAuth 里没有第二份占位符名单');

$configRaw = (string) file_get_contents(MCFIX_ROOT . '/src/Config.php');
ok(strpos($configRaw, "get('admin.path'") !== false,
    '★★ V3：Config::diagnose() 现在检查 admin.path（原来唯独漏了它）');
ok(strpos($configRaw, '后台地址可被离线推算') !== false,
    '★ V3：给出"地址可被离线推算"的明确告警');

$exampleRaw2 = (string) file_get_contents(MCFIX_ROOT . '/config.example.php');
ok((bool) preg_match("/'path'\\s*=>\\s*'',/", $exampleRaw2),
    '★★ V3：示例配置不再发一个公开的字面量后台路径');
ok(strpos($exampleRaw2, 'console-CHANGE_ME_16_HEX') === false,
    '★★ V3：那个照抄就能算出后台的字面量已从示例里移除');

// 行为断言：占位符 secret 必须让路径退回 base_url 派生（即不再由 secret 决定）
$mkPath = static function (string $hmac, string $baseUrl): string {
    $seed = $hmac;
    $placeholder = ['mcfix-empty-secret', 'CHANGE_ME_TO_A_RANDOM_STRING', 'CHANGE_ME_AGENT_TOKEN', 'change-me', 'changeme', 'secret'];
    if ($seed === '' || in_array(trim($seed), $placeholder, true)) {
        $seed = $baseUrl;
    }

    return 'console-' . substr(hash('sha256', 'console-path|' . $seed), 0, 12);
};
$realPath = $mkPath(str_repeat('a', 64), 'https://mc.example.com');
ok($mkPath('change-me', 'https://mc.example.com') !== $realPath,
    '★★ V3 行为：hmac_secret=change-me 时路径不再由它派生');
ok($mkPath('change-me', 'https://mc.example.com') === $mkPath('secret', 'https://mc.example.com'),
    '★★ V3 行为：所有占位符都退化成同一个（公开的）seed');

// ---- V6：静默失效的防线 + 别把 plugins 发给客户端 ----
//
// 报告指出 api.php 检查的是 $cross['available']，而 cross_check 里
// 从来只返回 possible —— 条件恒假，那道"只认服务端清单里的名字"的限制
// 从未生效，$pool 永远退回玩家日志解析出的名字。
//
// 诚实标注：报告说的是"死代码/防线失效"，不是"现成的洞" ——
// 因为同一段里第 241 行本来就做精确匹配（strcasecmp === 0）而非子串匹配。
$apiRaw = (string) file_get_contents(MCFIX_ROOT . '/public/controllers/api.php');
ok(strpos($apiRaw, "!empty(\$cross['available'])") === false,
    '★★ V6：api.php 不再检查不存在的 $cross[\'available\']（那个键恒为假）');
ok(strpos($apiRaw, "!empty(\$cross['possible'])") !== false,
    '★★ V6：改成检查 cross_check 真正返回的 possible 键');
ok(strpos($apiRaw, 'strcasecmp($name, $component) === 0') !== false,
    '★ V6：取回文件名仍然是精确匹配（不是子串 —— 那才是任意文件下载器）');

$smRaw = (string) file_get_contents(MCFIX_ROOT . '/src/ServerMods.php');
ok(strpos($smRaw, "'available'    => !empty(\$serverMods['available'])") !== false,
    '★ V6：crossCheck() 补上 available 键，调用方不用再猜');

$agentRaw = (string) file_get_contents(MCFIX_ROOT . '/agent/mcfix-agent.php');
ok(strpos($agentRaw, "foreach (['mods', 'plugins'] as \$folder)") === false,
    '★★ V6：agent 不再从 plugins 目录取文件分发给客户端');
ok(strpos($agentRaw, "foreach (['mods'] as \$folder)") !== false,
    '★★ V6：只分发 mods（plugins 是服务端专属，且付费插件不该被再分发）');

// ---- V7：事件表和日志表必须同一套脱敏标准 ----
//
// 报告指出同一个项目两套标准：app_log() 脱敏，record_event() 不脱敏。
// 而 PanelAdapter 会把上游响应体（可能含面板凭据）塞进错误信息。
$helperRaw2 = (string) file_get_contents(MCFIX_ROOT . '/src/helpers.php');
ok((bool) preg_match("/'message'\\s*=>\\s*mb_substr\\(redact_secrets\\(\\\$message\\)/", $helperRaw2),
    '★★ V7：record_event() 的 message 先脱敏再截断');
ok(strpos($helperRaw2, 'json_encode(redact_secrets_deep($payload)') !== false,
    '★★ V7：record_event() 的 payload 递归脱敏（数组里也不能漏）');
ok(strpos($helperRaw2, "'message'     => mb_substr(\$message, 0, 500),") === false,
    '★★ V7：没有残留的"未脱敏 message"写法');

// ---- V9/V10/V11/V12/V13/V14 + F1/F2/F3：报告里"当前不可达但值得加固"的一批 ----
//
// 报告对这几条都做了诚实的分级：V13/V10 明确标注"当前不可达"，
// V8/V14 是"防御缺口/语义问题"。这里一并钉住，免得以后回退。
$taskRaw = (string) file_get_contents(MCFIX_ROOT . '/src/Task.php');
$agentCtlRaw = (string) file_get_contents(MCFIX_ROOT . '/public/controllers/agent.php');
$dbRaw = (string) file_get_contents(MCFIX_ROOT . '/src/Db.php');
$notifyRaw = (string) file_get_contents(MCFIX_ROOT . '/admin/notify.php');
$notifyActRaw = (string) file_get_contents(MCFIX_ROOT . '/public/controllers/admin_actions.php');
$helpRaw = (string) file_get_contents(MCFIX_ROOT . '/admin/help.php');
$idxRaw = (string) file_get_contents(MCFIX_ROOT . '/public/index.php');
$clientRaw = (string) file_get_contents(MCFIX_ROOT . '/admin/client.php');

// V9：Agent 的 log 动作要和 report 一样校验租约
ok(strpos($agentCtlRaw, '请先领取任务再上报进度') !== false,
    '★★ V9：Agent log 动作要求租约令牌（和 report 分支一致）');
ok(strpos($agentCtlRaw, '租约校验失败（任务可能已被回收或已完成，请忽略）') !== false,
    '★★ V9：log 动作处理租约不匹配');
ok(strpos($taskRaw, 'public static function progress(int $id, string $leaseToken, string $message') !== false,
    '★★ V9：Task::progress 接受并校验租约，返回 bool');
ok(strpos($taskRaw, "mb_substr(\n                (string) json_encode(\$payload") !== false
    || (bool) preg_match('/progress_payload.*?mb_substr/s', $taskRaw),
    '★ V9：progress payload 有长度上限（不然能把 tasks.result 撑爆）');

// V10：空租约不该等于特权 —— 至少要用 hash_equals 做常数时间比较
ok(strpos($taskRaw, 'hash_equals') !== false,
    '★ V10：租约比较用 hash_equals（常数时间，避免时序侧信道）');

// V11：通知渠道的凭据字段不回显
ok(strpos($notifyRaw, '已配置，留空表示不改') !== false,
    '★★ V11：通知渠道的密钥字段不回显（placeholder 提示"留空表示不改"）');
ok(strpos($notifyRaw, 'cfg_clear_') !== false && strpos($notifyActRaw, "'cfg_clear_' . \$type") !== false,
    '★★ V11：提供显式"清除"勾选框，并在保存时处理');
// 这条最关键：渠道配置是**整包替换**，留空若不沿用旧值，改个超时就会静默删掉密钥
ok(strpos($notifyActRaw, 'existingChannel') !== false,
    '★★ V11：留空时沿用已保存的密钥（渠道配置是整包替换，不沿用会静默丢密钥）');

// V12：GET 渲染路径不写配置
ok(!preg_match('/\n\s*\\\\MCFix\\\\Share::ensureSecret\(/', $helpRaw),
    '★★ V12：后台接入页渲染时不再写配置（GET 应当只读）');
ok(strpos($helpRaw, '\MCFix\Share::link($server)') !== false,
    '★ V12：改为直接按派生值算链接（share_secret 为空时 link() 会自动降级）');

// ---- ★★ 公开链接「重新生成」必须真的让旧链接失效 ----
//
// V12 让接入页（GET）不再写配置，但顺手把签名密钥退回全局 hmac_secret 的话，
// 页面上那句"点「重新生成」旧链接立刻失效"就变成了假话：全局密钥**永远**是
// Token 的验签候选，用全局密钥签出来的链接无论生成多少把新 share_secret 都
// 换不掉它（旧链接一直有效到 30 天过期）。
//
// 所以这里用一台**没有** share_secret 的服务器走完整条路：
//   派生密钥签出链接 → 能打开 → 点「重新生成」→ 同一个令牌必须打不开。
// 临时改的是测试自己的临时配置目录，跑完原样写回。
$shareProbeId = 'share-probe';
$configBefore = (array) Config::get('', []);
$probeItems = $configBefore;
$probeItems['servers'][] = [
    'id'          => $shareProbeId,
    'code'        => 'SP',
    'name'        => '分享链接测试服',
    'host'        => '127.0.0.1',
    'port'        => 25565,
    'enabled'     => true,
    'executor'    => 'none',
    'agent_token' => str_repeat('e', 32),
    'guard'       => ['type' => 'none', 'service' => '', 'session' => ''],
    'recipes'     => [],
];
ok(\MCFix\Config::save($probeItems), '（前置）测试服务器写进了临时配置');
$probeServer = (array) \MCFix\Config::server($shareProbeId);
ok((string) ($probeServer['share_secret'] ?? '') === '',
    '（前置）这台服务器确实没有 share_secret（后台新增的服务器就是这个状态）');

$probeToken = (string) \MCFix\Share::link($probeServer)['token'];
ok(\MCFix\Share::parse($probeToken) !== null,
    '★ 没有独立密钥时链接照常可用（用这台服务器专属的派生密钥签名）');

$probeRotate = \MCFix\Share::rotate($shareProbeId);
ok(!empty($probeRotate['ok']), '（前置）「重新生成」成功');

ok(\MCFix\Share::parse($probeToken) === null,
    '★★ 重新生成之后旧链接立刻失效（页面上就是这么写的）');
$probeNewToken = (string) \MCFix\Share::link((array) \MCFix\Config::server($shareProbeId))['token'];
ok(\MCFix\Share::parse($probeNewToken) !== null,
    '★ （对照）作废旧链接之后，新生成的链接是能用的 —— 别把功能一起砍掉');
ok(\MCFix\Share::parse($probeNewToken)['server_id'] === $shareProbeId,
    '★ （对照）新链接仍然绑在这台服务器上');

// 收尾：把测试服务器从配置里去掉
\MCFix\Config::save($configBefore);
ok(\MCFix\Config::server($shareProbeId) === null, '（收尾）测试服务器已从配置里移除');

// V13：标识符转义
//
// 注意：quoteIdentifier 这个名字在 Db.php 里出现 14 次（13 处调用 + 1 处定义），
// 所以断言必须锚在**函数定义**上，不能只搜名字 —— 否则窗口会落在调用点上测了个寂寞。
ok(strpos($dbRaw, "str_replace('\"', '\"\"', \$identifier)") !== false,
    '★★ V13：quoteIdentifier 双写引号（原实现是"删掉引号"，那不是转义）');
$quoteFnAt = strpos($dbRaw, 'private static function quoteIdentifier');
$quoteFnBody = $quoteFnAt !== false ? substr($dbRaw, $quoteFnAt, 1600) : '';
ok(strpos($quoteFnBody, '[A-Za-z_]') !== false,
    '★★ V13：quoteIdentifier 先过白名单，非法标识符直接抛异常');
ok(strpos($quoteFnBody, "str_replace('\"', '\"\"', \$identifier)") !== false,
    '★★ V13：双写转义就在函数体里（不是只写在别处）');

// V14：LIKE 通配符转义
foreach (['admin/tickets.php', 'admin/events.php'] as $likeFile) {
    // 先剥注释：这两个文件的注释里正是在解释"为什么不能用反斜杠"，
    // 直接扫原文会把那句解释当成"还在用反斜杠"。
    $likeSrc = php_code_only((string) file_get_contents(MCFIX_ROOT . '/' . $likeFile));
    ok(strpos($likeSrc, "ESCAPE '!'") !== false,
        '★ V14：' . $likeFile . ' 的 LIKE 带 ESCAPE（否则搜 % 等于不筛选）');
    // 转义符不能是反斜杠：PHP 里 "ESCAPE '\\'" 落到 SQL 是 ESCAPE '\'，
    // SQLite 认、MySQL 不认（1064），后台搜索页会 500。
    ok(strpos($likeSrc, "ESCAPE '\\'") === false,
        '★★ V14：' . $likeFile . ' 不用反斜杠当转义符（MySQL 下那是语法错误）');
    ok(strpos($likeSrc, "str_replace(['!', '%', '_'], ['!!', '!%', '!_']") !== false,
        '★★ V14：' . $likeFile . ' 把 % 和 _ 用同一个转义符转掉（搜 % 才不会退化成不筛选）');
}

// F1：「已处理」计数不能恒为 0
ok(strpos($clientRaw, 'resolvedWhere') !== false,
    '★★ F1：客户端问题的"已处理"计数用独立条件（原写法在勾选"只看未处理"时恒为 0）');
ok(strpos($clientRaw, "'SELECT COUNT(*) FROM client_issues' . \$whereSql . ' AND resolved = 1'") === false,
    '★★ F1：不再复用带 resolved=0 的 \$whereSql 拼 resolved=1');

// F2：后台筛选表单不能把 r 硬编码成会被 404 的路由
//
// 只看"可见 HTML"（PHP 块之外），避免把说明用的注释算进去。
// 注意：这里刻意用 '?' . '>' 拼出 PHP 结束标记，不写出它的字面形式 ——
// 项目自带的 check-close-tag.js 会把源码里任何字面的 PHP 结束标记
// （哪怕在注释或字符串里）报出来，因为历史上真被这种东西坑过。
$phpBlock = '<' . '?php';
$phpEnd = '?' . '>';
foreach ([['admin/client.php', 'client'], ['admin/events.php', 'events'], ['admin/tickets.php', 'tickets']] as [$f2file, $f2label]) {
    $f2src = (string) file_get_contents(MCFIX_ROOT . '/' . $f2file);
    $f2html = (string) preg_replace(
        '/' . preg_quote($phpBlock, '/') . '[\s\S]*?' . preg_quote($phpEnd, '/') . '/',
        '',
        $f2src
    );
    ok(strpos($f2html, 'name="r"') === false,
        '★★ F2：' . $f2label . '.php 的筛选表单不再硬编码 ?r=admin…（那会被 404）');
}

// F3：?r=api.agent.<action> 这种文档写明的形态要真的生效
ok(strpos($idxRaw, "implode('.', array_slice(\$parts, 1))") !== false,
    '★★ F3：路由按 "." 全部切开（原来只取 $parts[1]，?r=api.agent.report 会静默落到 poll）');
ok(strpos($idxRaw, "substr(\$action, strlen('agent.'))") !== false,
    '★ F3：agent 前缀剥离逻辑保留（配合上面的切片，report/log 才能正确还原）');

// ---- 玩家反馈页的"上传日志"教程不能写死某一种启动器/系统的路径 ----
//
// 原来写的是「按 Win+R，粘贴 %appdata%\.minecraft\crash-reports」——
// 那是按 Windows + 官方启动器写的：用 HMCL / PCL2 / 整合包启动器的玩家对不上，
// 而 %appdata% 还是个隐藏目录。玩家本来就知道自己的文件在哪，
// 该教的是"从启动器里打开游戏目录"这个通用办法。
$playerFormRaw = (string) file_get_contents(MCFIX_ROOT . '/views/player-form.php');
ok(strpos($playerFormRaw, '%appdata%') === false,
    '★★ 玩家页不再写死 %appdata% 路径（只对 Windows + 官方启动器成立）');
ok(strpos($playerFormRaw, '<kbd>Win</kbd>') === false,
    '★★ 玩家页不再要求玩家按 Win+R（macOS/Linux 玩家无从下手）');
ok(strpos($playerFormRaw, '打开游戏目录') !== false && strpos($playerFormRaw, 'crash-reports') !== false,
    '★ 改成"从启动器打开游戏目录 → 找 crash-reports / logs"的通用讲法');
ok(strpos($playerFormRaw, 'HMCL') !== false && strpos($playerFormRaw, 'PCL2') !== false,
    '★ 常见启动器各给一段（玩家可选自己那个，不必对号入座）');
ok(strpos($playerFormRaw, '待查证') !== false,
    '★ 启动器的按钮叫法标了「待查证」（项目既有规矩：写错的教程比没有更糟）');
ok(strpos($playerFormRaw, '方式二') !== false,
    '★ 保留"直接粘贴报错内容"这条后路（找不到文件时不用卡住）');

// ---- 工单终态：驳回要显示"已驳回"，解决要顺手关闭 ----
//
// 用户反馈：驳回之后工单还显示"待处理"；标记已解决之后工单没关闭。
// 查下来**不是显示层的问题**（status_label / status_tone / 列表 / 详情页都
// 一直是按真实 status 渲染的），而是落库的状态机缺了两个动作：
//   - reject 只写 status='rejected'，不写 closed_at → 既不算"已结束"，
//     又被归档任务跳过，日志原文永久留着（崩溃报告含用户名/显卡型号）
//   - resolve 只写 status='resolved'，靠 cron 每天收敛成 closed，
//     管理员点完"已解决"在界面上看不到关闭
$wfRaw = (string) file_get_contents(MCFIX_ROOT . '/src/Workflow.php');

// 取函数体切片，避免匹配到别处同名变量。
// 先剥注释再取体：resolve() 里那段解释"为什么不再写 resolved、以及由此导致的
// 日志永不清除"的注释会引用旧写法，扫原文就会把守卫自己绊倒。
$wfCode = php_code_only($wfRaw);
$resolveBody = function_body($wfCode, 'public static function resolve(');
ok(strpos($resolveBody, "'status'          => 'closed'") !== false,
    '★★ 标记已解决直接落到 closed（不再留一个没人收敛的 resolved）');
ok((bool) preg_match("/'closed_at'\\s*=>\\s*now\\(\\)/", $resolveBody),
    '★★ 解决时写 closed_at（归档按它算留存期）');
ok(strpos($resolveBody, "'resolved'") === false,
    '★★ resolve 不再写 status = resolved');
ok(strpos($resolveBody, 'auto_fixed') !== false,
    '★ 保留 auto_fixed 语义（别把"人工标记"冒充成"系统自动修好"）');

$rejectAt = strpos($wfCode, "case 'reject':");
$rejectBody = $rejectAt !== false ? substr($wfCode, $rejectAt, 1500) : '';
ok(strpos($rejectBody, "'status'          => 'rejected'") !== false,
    '★ 驳回仍然写 rejected（状态名保留，用来区分"为什么结束"）');
ok((bool) preg_match("/'closed_at'\\s*=>\\s*now\\(\\)/", $rejectBody),
    '★★ 驳回也写 closed_at（驳回是终态，要参与归档）');

// 归档查询必须覆盖三种终态
ok(strpos($wfRaw, "AND status IN ('closed', 'resolved', 'rejected')") !== false,
    '★★ 日志归档认三种终态（原来只认 closed，resolved/rejected 的原文永远清不掉）');

// 显示层本来就对，加断言防回归
ok(status_label('rejected') === '已驳回', '★★ 驳回后显示"已驳回"（不是"待处理"）');
ok(status_label('closed') === '已关闭', '★ 解决后显示"已关闭"');
ok(status_tone('rejected') === 'muted', '★ 驳回用弱化色（它是终态，不是待办）');

// 行为断言：真的建一条工单，跑一遍 resolve，看库里是什么
$stateId = (int) Db::insert('feedback', [
    'ticket_no'   => 'MCFIX-STATE-' . bin2hex(random_bytes(3)),
    'server_id'   => 'unit',
    'player_name' => 'StateTest',
    'category'    => 'server_down',
    'subject'     => '终态测试',
    'status'      => 'manual',
    'created_at'  => now(),
    'updated_at'  => now(),
]);
\MCFix\Workflow::resolve($stateId, '管理员手动标记', 'admin', false);
$stateRow = Db::first('SELECT status, closed_at, auto_fixed FROM feedback WHERE id = :id', ['id' => $stateId]);
ok(($stateRow['status'] ?? '') === 'closed',
    '★★ 行为：resolve() 之后库里 status 就是 closed', '实际：' . var_export($stateRow['status'] ?? null, true));
ok(!empty($stateRow['closed_at']),
    '★★ 行为：resolve() 之后 closed_at 有值', '实际：' . var_export($stateRow['closed_at'] ?? null, true));
ok((int) ($stateRow['auto_fixed'] ?? 1) === 0,
    '★ 行为：管理员手动标记不算"系统自动修复"');

// 收尾：清掉这条测试工单
Db::delete('events', ['feedback_id' => $stateId]);
Db::delete('feedback', ['id' => $stateId]);

// 日报的"自动修复成功"不能按 status 名来数 —— 工单现在解决即关闭，
// 按名字数会永远是 0，日报会静默谎报"一条都没修好"。要看 auto_fixed。
$notifierRaw = (string) file_get_contents(MCFIX_ROOT . '/src/Notifier.php');
// 同样先剥注释：日报那段注释正是在解释"为什么不能按 status 名来数"
$notifierCode = php_code_only($notifierRaw);
ok(strpos($notifierCode, "status = 'resolved'") === false,
    '★★ 日报不再按 status=resolved 数自动修复（那个状态名已经不再出现）');
ok(strpos($notifierCode, 'auto_fixed = 1') !== false,
    '★★ 日报改按 auto_fixed = 1 数（这才是"系统自己修好的"的真凭据）');

// --------------------------------------------------------------------- 收尾

echo PHP_EOL . str_repeat('─', 56) . PHP_EOL;
printf('通过 %d 项，失败 %d 项%s', $passed, $failed, PHP_EOL);

// 清理临时目录
foreach (glob($tmpDir . '/storage/data/*') ?: [] as $f) {
    @unlink($f);
}
@unlink($configFile);
@rmdir($tmpDir . '/storage/data');
@rmdir($tmpDir . '/storage');
@rmdir($tmpDir . '/config');
@rmdir($tmpDir);

if ($failed > 0) {
    echo PHP_EOL . '有失败项，请看上面的 ✗。' . PHP_EOL;
    exit(1);
}

echo PHP_EOL . '全部通过。' . PHP_EOL;
exit(0);
