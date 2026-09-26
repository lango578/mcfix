<?php
/**
 * MCFix Agent —— 部署在 MC 服务器所在机器上的小程序。
 *
 * 它做三件事：
 *   1. 心跳：每 8 秒把机器/服务端状态报给面板（进程、TPS、人数、磁盘、日志体积）
 *   2. 领任务：面板把"诊断"和"修复"任务放在队列里，Agent 主动来取（不需要开放端口）
 *   3. 执行：按白名单配方执行，只认固定 code，永远不接受任意 shell 命令
 *
 * 用法：
 *   php mcfix-agent.php                 常驻运行（推荐配合 systemd）
 *   php mcfix-agent.php --once          只跑一轮（适合宝塔计划任务，每分钟一次）
 *   php mcfix-agent.php --once --verbose 带日志输出
 *   php mcfix-agent.php --check         只检查本地环境，不连面板
 *   php mcfix-agent.php --local-task '{...}'  面板本地/SSH 通路调用（内部使用）
 *
 * 配置文件：与本脚本同目录的 config.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('本脚本只能在命令行运行');
}

if (!defined('AGENT_VERSION')) {
    define('AGENT_VERSION', '1.6.4');
}

if (PHP_VERSION_ID < 70400) {
    fwrite(STDERR, "需要 PHP 7.4+\n");
    exit(1);
}

// --------------------------------------------------------------------- 配置

$options = getopt('', ['once', 'verbose', 'check', 'local-task:', 'root:', 'help', 'version']);

if (isset($options['help'])) {
    echo <<<TXT
MCFix Agent —— MC 服务器故障反馈系统的机器侧执行器

  php mcfix-agent.php                      常驻运行
  php mcfix-agent.php --once               只跑一轮（计划任务用）
  php mcfix-agent.php --once --verbose     带详细日志
  php mcfix-agent.php --check              自检本地环境
  php mcfix-agent.php --version            显示版本

配置文件：与本脚本同目录的 config.php（可用 MCFIX_AGENT_CONFIG 环境变量指定）

TXT;
    exit(0);
}

if (isset($options['version'])) {
    echo "mcfix-agent " . AGENT_VERSION . "\n";
    exit(0);
}

$configPath = getenv('MCFIX_AGENT_CONFIG');
if (!is_string($configPath) || $configPath === '') {
    $configPath = __DIR__ . '/config.php';
}
$root = isset($options['root']) ? (string) $options['root'] : __DIR__;

$config = [];
if (is_file($configPath)) {
    $loaded = require $configPath;
    if (is_array($loaded)) {
        $config = $loaded;
    }
}

/** @var array<string,mixed> $config */
$config = array_merge([
    'panel_url'    => '',
    'server_id'    => '',
    'token'        => '',
    'mc_dir'       => '',
    'log_path'     => 'logs/latest.log',
    'listen_port'  => 25565,
    'disk_path'    => '',
    'guard'        => ['type' => 'systemd', 'service' => '', 'session' => '', 'start_cmd' => '', 'stop_cmd' => '', 'restart_cmd' => ''],
    'interval'     => 8,
    'poll_timeout' => 20,
    'log_file'     => $root . '/mcfix-agent.log',
    'log_max_size' => 5242880,
    'cpu_warn'     => 90,
    'mem_warn_mb'  => 0,
    'backup_dir'   => '',
    // 只有面板用了自签证书时才设成 true。设 true 之后面板和 Agent 之间的
    // 令牌、指令、日志片段都不再校验对端身份，任何一跳都能改。
    'insecure'     => false,
], $config);

/**
 * TLS 校验开关。http_post_json() 在全局作用域之外的调用路径里，
 * 这里用一个全局量传递，避免为了一个布尔值去改函数签名。
 */
$GLOBALS['MCFIX_INSECURE_TLS'] = !empty($config['insecure']);

$verbose = isset($options['verbose']);

// --------------------------------------------------------------------- 工具函数

function agent_log(string $level, string $message, array $context = []): void
{
    global $config, $verbose;

    $line = sprintf(
        "[%s] %-5s %s%s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
    );

    if ($verbose || $level !== 'debug') {
        fwrite($level === 'error' ? STDERR : STDOUT, $line);
    }

    $file = (string) ($config['log_file'] ?? '');
    if ($file !== '') {
        $maxSize = (int) ($config['log_max_size'] ?? 5242880);
        if (is_file($file) && $maxSize > 0 && filesize($file) > $maxSize) {
            @rename($file, $file . '.1');
        }
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}

function out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function run_cmd(string $command, int $timeout = 20): array
{
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'code' => -1, 'stdout' => '', 'stderr' => 'proc_open 被禁用'];
    }

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = @proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['ok' => false, 'code' => -1, 'stdout' => '', 'stderr' => '无法启动命令'];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeout;

    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $code = (int) $status['exitcode'];
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($process, 9);
            $code = -1;
            $stderr .= "\n命令超时（{$timeout}s）";
            break;
        }
        usleep(60000);
    }

    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return ['ok' => $code === 0, 'code' => $code, 'stdout' => trim($stdout), 'stderr' => trim($stderr)];
}

function mc_dir(): string
{
    global $config;

    return rtrim((string) $config['mc_dir'], '/');
}

function abs_path(string $path): string
{
    if ($path === '') {
        return '';
    }
    if ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
        return $path;
    }

    return mc_dir() . '/' . ltrim($path, '/');
}

function human_bytes(float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return round($bytes, 1) . $units[$i];
}

function df(string $path): array
{
    $path = $path !== '' ? $path : '/';
    $free = @disk_free_space($path);
    $total = @disk_total_space($path);

    if ($free === false || $total === false || $total <= 0) {
        return ['ok' => false, 'free' => 0, 'total' => 0, 'percent' => 0];
    }

    return [
        'ok'      => true,
        'free'    => (float) $free,
        'total'   => (float) $total,
        'percent' => round((1 - $free / $total) * 100, 1),
    ];
}

// --------------------------------------------------------------------- 服务端状态探测

/**
 * 找到 MC 的 java 进程（不依赖 pidfile）。
 *
 * @return array<int,array<string,mixed>>
 */
function find_mc_processes(): array
{
    $out = [];
    $procDir = '/proc';
    if (!is_dir($procDir)) {
        // 非 Linux：退化为 pgrep
        $r = run_cmd('pgrep -af "java.*server.jar|java.*paper|java.*forge|java.*fabric" 2>/dev/null', 5);
        if ($r['ok'] && $r['stdout'] !== '') {
            foreach (explode("\n", $r['stdout']) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $parts = explode(' ', $line, 2);
                $out[] = ['pid' => (int) $parts[0], 'cmd' => $parts[1] ?? ''];
            }
        }

        return $out;
    }

    $dir = mc_dir();
    foreach ((array) glob($procDir . '/[0-9]*') as $procPath) {
        $pid = (int) basename((string) $procPath);
        if ($pid <= 0) {
            continue;
        }
        $cmdlineFile = $procPath . '/cmdline';
        if (!is_readable($cmdlineFile)) {
            continue;
        }
        $raw = @file_get_contents($cmdlineFile);
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        $cmd = str_replace("\0", ' ', $raw);
        if (stripos($cmd, 'java') === false) {
            continue;
        }
        // 只认与 mc_dir 相关、或明确是 MC 服务端的进程
        $isMc = ($dir !== '' && strpos($cmd, $dir) !== false)
            || preg_match('/server\.jar|paper\.jar|spigot\.jar|forge.*\.jar|fabric.*\.jar|purpur\.jar|velocity|bungeecord/i', $cmd);

        if (!$isMc) {
            continue;
        }

        $entry = ['pid' => $pid, 'cmd' => mb_substr($cmd, 0, 400)];

        // CPU / 内存
        $cwd = @readlink($procPath . '/cwd');
        $entry['cwd'] = is_string($cwd) ? $cwd : '';

        $status = @file_get_contents($procPath . '/stat');
        if (is_string($status) && $status !== '') {
            $parts = explode(' ', $status);
            if (count($parts) > 23) {
                $utime = (int) ($parts[13] ?? 0);
                $stime = (int) ($parts[14] ?? 0);
                $starttime = (int) ($parts[21] ?? 0);
                $entry['cpu_ticks'] = $utime + $stime;
                $entry['start_ticks'] = $starttime;
            }
            if (isset($parts[23])) {
                $rss = (int) $parts[23];
                $entry['rss_kb'] = (int) round($rss * 4); // 页大小按 4K 估算
            }
        }

        $out[] = $entry;
    }

    return $out;
}

/**
 * 采样两次算 CPU 占用率。
 *
 * @param array<int,array<string,mixed>> $before
 * @return array<string,mixed>
 */
function process_cpu_percent(array $before, float $window = 0.6): array
{
    $ticksPerSecond = 100;
    $hz = @shell_exec('getconf CLK_TCK 2>/dev/null');
    if (is_string($hz) && is_numeric(trim($hz))) {
        $ticksPerSecond = max(1, (int) trim($hz));
    }

    usleep((int) ($window * 1000000)); // 采样窗口
    $after = find_mc_processes();

    $byPid = [];
    foreach ($before as $proc) {
        $byPid[(int) $proc['pid']] = $proc;
    }

    foreach ($after as $proc) {
        $pid = (int) $proc['pid'];
        if (!isset($byPid[$pid])) {
            continue;
        }
        $deltaTicks = (int) ($proc['cpu_ticks'] ?? 0) - (int) ($byPid[$pid]['cpu_ticks'] ?? 0);
        if ($deltaTicks < 0) {
            continue;
        }
        $percent = round(($deltaTicks / $ticksPerSecond) / $window * 100, 1);

        return [
            'pid'     => $pid,
            'cpu'     => $percent,
            'mem_mb'  => round(((int) ($proc['rss_kb'] ?? 0)) / 1024, 1),
            'cmd'     => (string) ($proc['cmd'] ?? ''),
            'cwd'     => (string) ($proc['cwd'] ?? ''),
        ];
    }

    return ['pid' => 0, 'cpu' => 0, 'mem_mb' => 0];
}

/**
 * 进程 / systemd / screen / tmux 综合判断服务端是否在跑。
 *
 * @return array<string,mixed>
 */
function probe_process(): array
{
    global $config;

    $guard = (array) $config['guard'];
    $type = (string) ($guard['type'] ?? 'systemd');
    $procs = find_mc_processes();

    if ($procs) {
        $pid = (int) $procs[0]['pid'];

        return [
            'ok'     => true,
            'pid'    => $pid,
            'count'  => count($procs),
            'method' => 'process-scan',
            'detail' => '发现 ' . count($procs) . ' 个 java 进程，PID ' . $pid . '（' . $type . '）',
        ];
    }

    // 没有 java 进程，再按守护方式确认一次（可能是刚启动、或进程名不匹配）
    if ($type === 'systemd' && !empty($guard['service'])) {
        $r = run_cmd('systemctl is-active ' . escapeshellarg((string) $guard['service']) . ' 2>/dev/null', 8);
        if ($r['ok'] && trim($r['stdout']) === 'active') {
            return ['ok' => true, 'pid' => 0, 'count' => 0, 'method' => 'systemd', 'detail' => 'systemd 单元 active，但没找到 java 进程'];
        }

        return ['ok' => false, 'pid' => 0, 'count' => 0, 'method' => 'systemd', 'detail' => 'systemd 单元状态：' . ($r['stdout'] !== '' ? $r['stdout'] : 'unknown')];
    }

    if (in_array($type, ['screen', 'tmux'], true) && !empty($guard['session'])) {
        $session = escapeshellarg((string) $guard['session']);
        $cmd = $type === 'screen' ? 'screen -ls 2>/dev/null' : 'tmux ls 2>/dev/null';
        $r = run_cmd($cmd, 8);
        $has = $r['ok'] && stripos($r['stdout'], (string) $guard['session']) !== false;

        return [
            'ok'     => $has,
            'pid'    => 0,
            'count'  => 0,
            'method' => $type,
            'detail' => $has ? ($type . ' 会话存在') : ($type . ' 会话不存在（' . trim($r['stdout']) . '）'),
        ];
    }

    return ['ok' => false, 'pid' => 0, 'count' => 0, 'method' => $type, 'detail' => '未找到 MC 服务端进程'];
}

/**
 * 端口是否在监听。
 */
function probe_port(int $port): array
{
    if ($port <= 0) {
        return ['ok' => false, 'listening' => false, 'detail' => '端口未配置'];
    }

    // 优先用 ss / netstat（能看到是谁在监听）
    foreach (['ss -lntp', 'netstat -lntp'] as $cmd) {
        $r = run_cmd($cmd . ' 2>/dev/null', 8);
        if ($r['ok'] && $r['stdout'] !== '') {
            $listening = false;
            foreach (explode("\n", $r['stdout']) as $line) {
                if (preg_match('/[:\.]' . preg_quote((string) $port, '/') . '\s/', $line . ' ')) {
                    $listening = true;
                    break;
                }
            }

            return [
                'ok'        => true,
                'listening' => $listening,
                'detail'    => $listening ? '端口 ' . $port . ' 正在监听' : '端口 ' . $port . ' 没有监听',
            ];
        }
    }

    // 退化为直接连一下
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
    if ($fp !== false) {
        fclose($fp);

        return ['ok' => true, 'listening' => true, 'detail' => '端口 ' . $port . ' 可连接（回环探测）'];
    }

    return ['ok' => true, 'listening' => false, 'detail' => '端口 ' . $port . ' 不可连接（' . $errstr . '）'];
}

/**
 * 读日志尾部。
 */
function tail_log(int $lines = 400): array
{
    global $config;

    $path = abs_path((string) ($config['log_path'] ?? 'logs/latest.log'));
    if ($path === '' || !is_file($path)) {
        // 常见替代位置
        foreach (['logs/latest.log', 'logs/latest.log.gz'] as $candidate) {
            $try = abs_path($candidate);
            if (is_file($try)) {
                $path = $try;
                break;
            }
        }
    }

    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return ['ok' => false, 'path' => $path, 'lines' => [], 'detail' => '日志文件不存在或不可读：' . $path];
    }

    $size = (int) filesize($path);
    $mtime = (int) filemtime($path);
    $handle = @fopen($path, 'r');
    if ($handle === false) {
        return ['ok' => false, 'path' => $path, 'lines' => [], 'detail' => '日志无法打开'];
    }

    // 从尾部读
    $readBytes = min($size, 256 * 1024);
    fseek($handle, max(0, $size - $readBytes));
    $buffer = (string) fread($handle, $readBytes);
    fclose($handle);

    $all = preg_split('/\r?\n/', $buffer) ?: [];
    $tail = array_slice($all, -$lines);

    return [
        'ok'       => true,
        'path'     => $path,
        'size'     => $size,
        'mtime'    => $mtime,
        'age'      => time() - $mtime,
        'lines'    => array_values(array_filter($tail, static function ($l): bool {
            return is_string($l) && trim($l) !== '';
        })),
    ];
}

/**
 * 日志错误分析。返回命中的模式与样例。
 *
 * @param string[] $lines
 * @return array<string,mixed>
 */
function analyze_logs(array $lines): array
{
    $patterns = [
        'OutOfMemoryError'        => '/OutOfMemoryError|GC overhead limit exceeded/i',
        'Watchdog'                => '/A single server tick took|server has not responded|Watchdog Thread/i',
        'CrashReport'             => '/This crash report has been saved|Preparing crash report|Minecraft has crashed/i',
        'AddressInUse'            => '/Address already in use|Failed to bind to port/i',
        'WorldCorrupt'            => '/corrupt|Region file.*(mismatch|corrupt)|Chunk file at .* is missing/i',
        'Exception'               => '/^\s*(java|net|io)\.[\w\.]*Exception|Caused by:/i',
        'PluginError'             => '/\[(Server thread\/ERROR|ERROR)\].*(Exception|error)|Could not load .* plugin|Error occurred while enabling/i',
        'ModError'                => '/Mod .* requires|Missing or unsupported mandatory dependencies|Incompatible mod set/i',
        'DataFixer'               => '/DataFixer|Failed to load chunk|Ignoring chunk/i',
        'AuthFailed'              => '/Failed to verify username|authentication servers are down|com\.mojang\.authlib/i',
        'DiskFull'                => '/No space left on device|Read-only file system/i',
    ];

    $hits = [];
    $samples = [];
    $pluginCounter = [];

    foreach ($lines as $line) {
        foreach ($patterns as $name => $regex) {
            if (preg_match($regex, $line)) {
                $hits[$name] = ($hits[$name] ?? 0) + 1;
                if (count($samples[$name] ?? []) < 3) {
                    $samples[$name][] = mb_substr(trim($line), 0, 220);
                }
            }
        }

        if (preg_match_all('/\[([A-Za-z0-9_\-]{2,24})\]/', $line, $m)) {
            $isError = (bool) preg_match('/ERROR|WARN|Exception|error/i', $line);
            if ($isError) {
                foreach ($m[1] as $tag) {
                    if (in_array(strtolower($tag), ['server', 'main', 'init', 'minecraft', 'paper', 'spigot', 'bukkit', 'forge', 'fabric'], true)) {
                        continue;
                    }
                    $pluginCounter[$tag] = ($pluginCounter[$tag] ?? 0) + 1;
                }
            }
        }
    }

    arsort($hits);
    arsort($pluginCounter);

    return [
        'hits'    => $hits,
        'samples' => $samples,
        'plugins' => array_slice($pluginCounter, 0, 10, true),
        'total'   => count($lines),
    ];
}

/**
 * 从日志里读 TPS（很多服务端会把 TPS 打进日志）。
 */
function tps_from_log(array $lines): ?float
{
    $best = null;
    foreach (array_reverse($lines) as $line) {
        if (preg_match('/TPS[^0-9]{0,12}(\d{1,3}\.\d)/i', $line, $m)) {
            $value = (float) $m[1];
            if ($value > 0 && $value <= 20.1) {
                $best = $value;
                break;
            }
        }
    }

    return $best;
}

/**
 * 世界目录信息。
 */
function world_info(): array
{
    $dir = mc_dir();
    $info = ['world_size' => 0, 'newest' => 0, 'path' => ''];

    foreach (['world', 'world_nether', 'world_the_end'] as $name) {
        $path = $dir . '/' . $name;
        if (!is_dir($path)) {
            continue;
        }
        $info['path'] = $path;
        $size = 0;
        $newest = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $count = 0;
        foreach ($iterator as $file) {
            if (++$count > 20000) {
                break;
            }
            if ($file instanceof SplFileInfo) {
                $size += (int) $file->getSize();
                $newest = max($newest, (int) $file->getMTime());
            }
        }
        $info['world_size'] += $size;
        $info['newest'] = max($info['newest'], $newest);
    }

    return $info;
}

// --------------------------------------------------------------------- 任务执行

/**
 * 扫描服务端的 mods / plugins 目录。
 *
 * 目的是让面板能做"客户端 MOD ↔ 服务端 MOD"的交叉比对：
 *   客户端有、服务端没有 → 玩家多装了
 *   服务端有、客户端没有 → 玩家少装了（面板会主动把文件发给玩家）
 *
 * @return array<string,mixed>
 */
function scan_server_mods(): array
{
    global $config;

    $dir = mc_dir();
    $out = [
        'source'       => 'agent',
        'generated_at' => date('c'),
        'mods'         => [],
        'plugins'      => [],
    ];

    foreach ([['mods', 'mods'], ['plugins', 'plugins']] as [$group, $folder]) {
        $path = $dir . '/' . $folder;
        if (!is_dir($path)) {
            continue;
        }

        $files = @scandir($path);
        if (!is_array($files)) {
            continue;
        }

        $count = 0;
        foreach ($files as $file) {
            if ($count >= 500) {
                break;
            }
            if ($file === '.' || $file === '..') {
                continue;
            }
            if (!preg_match('/\.jar$/i', $file)) {
                continue;
            }
            $full = $path . '/' . $file;
            if (!is_file($full)) {
                continue;
            }

            $entry = [
                'file' => $file,
                'id'   => mod_id_from_jar($full),
                'size' => (int) filesize($full),
            ];
            $out[$group][] = $entry;
            $count++;
        }
    }

    $out['count'] = count($out['mods']) + count($out['plugins']);

    return $out;
}

/**
 * 从 jar 里读出 mod id。
 *
 * 支持三种：
 *   META-INF/mods.toml          （Forge / NeoForge）
 *   META-INF/neoforge.mods.toml （NeoForge 1.20.5+）
 *   fabric.mod.json             （Fabric / Quilt）
 *   plugin.yml                  （Bukkit 系插件）
 *
 * 读不出来就返回空，交给面板用文件名兜底。
 */
function mod_id_from_jar(string $jarPath): string
{
    if (!class_exists('ZipArchive')) {
        return '';
    }

    $zip = @new ZipArchive();
    if ($zip->open($jarPath) !== true) {
        return '';
    }

    $id = '';

    // Fabric：fabric.mod.json 里的 "id"
    $json = $zip->getFromName('fabric.mod.json');
    if (is_string($json) && $json !== '') {
        $data = json_decode($json, true);
        if (is_array($data) && !empty($data['id'])) {
            $id = (string) $data['id'];
        }
    }

    // Forge / NeoForge：mods.toml / neoforge.mods.toml 里的 modId
    if ($id === '') {
        foreach (['META-INF/neoforge.mods.toml', 'META-INF/mods.toml'] as $toml) {
            $raw = $zip->getFromName($toml);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            if (preg_match('/^\s*modId\s*=\s*["\']([^"\']+)["\']/mi', $raw, $m)) {
                $id = trim($m[1]);
                break;
            }
        }
    }

    // Bukkit 插件：plugin.yml 里的 name
    if ($id === '') {
        $yaml = $zip->getFromName('plugin.yml');
        if (is_string($yaml) && $yaml !== '' && preg_match('/^\s*name\s*:\s*["\']?([A-Za-z0-9_\-\.]+)/mi', $yaml, $m)) {
            $id = trim($m[1]);
        }
    }

    $zip->close();

    return mb_substr($id, 0, 64);
}

/**
 * 在服务端 mods 目录里找与组件名匹配的 jar。
 *
 * ★ 只扫 mods，不扫 plugins。
 *
 * plugins/ 是纯服务端组件（反作弊、权限、经济插件），客户端玩家永远不需要它。
 * 把 plugins 也纳进来有两个后果：
 *   1. 等于告诉玩家"这台服装了哪些反作弊/权限插件" —— 那是绕过它们的路线图；
 *   2. 付费插件能被这么取回去再分发。
 *
 * 而 mods/ 不同：那些是客户端也必须装的，玩家取回自己缺的那个是正常需求。
 * （本函数唯一的调用方是 pull_mod，它的错误提示本来就写着"请管理员把这个 MOD
 *   放到服务端 mods 目录"，所以限制在 mods 反而与既有意图一致。）
 *
 * @return array<string,mixed>  [ok, path, file, reason]
 */
function find_mod_file(string $component): array
{
    global $config;

    $component = trim($component);
    if ($component === '') {
        return ['ok' => false, 'path' => '', 'file' => '', 'reason' => '组件名为空'];
    }

    $key = mod_key($component);
    $dir = mc_dir();
    $candidates = [];

    // 只发 mods（见上面 docblock：plugins 是服务端专属，不该给到客户端）
    foreach (['mods'] as $folder) {
        $path = $dir . '/' . $folder;
        if (!is_dir($path)) {
            continue;
        }
        $files = @scandir($path);
        if (!is_array($files)) {
            continue;
        }
        foreach ($files as $file) {
            if (!preg_match('/\.jar$/i', $file)) {
                continue;
            }
            $full = $path . '/' . $file;
            if (!is_file($full)) {
                continue;
            }

            $fileKey = mod_key($file);
            $jarId = mod_key(mod_id_from_jar($full));

            $score = 0;
            if ($key !== '') {
                if ($fileKey === $key || ($jarId !== '' && $jarId === $key)) {
                    $score = 100;                       // 精确命中
                } elseif ($fileKey !== '' && (strpos($fileKey, $key) !== false || strpos($key, $fileKey) !== false)) {
                    $score = 60;                        // 包含关系
                } elseif ($jarId !== '' && (strpos($jarId, $key) !== false || strpos($key, $jarId) !== false)) {
                    $score = 50;
                } elseif (stripos($file, $component) !== false) {
                    $score = 40;                        // 原始文件名匹配
                }
            }

            if ($score > 0) {
                $candidates[] = ['path' => $full, 'file' => $file, 'score' => $score, 'size' => (int) filesize($full)];
            }
        }
    }

    if (!$candidates) {
        return ['ok' => false, 'path' => '', 'file' => '', 'reason' => '服务端 mods/plugins 目录里没有找到匹配的文件'];
    }

    // 分数高的优先；同分取体积大的（通常是完整版而非 API 壳）
    usort($candidates, static function (array $a, array $b): int {
        if ($a['score'] === $b['score']) {
            return $b['size'] <=> $a['size'];
        }

        return $b['score'] <=> $a['score'];
    });

    $best = $candidates[0];
    if ((int) $best['size'] > 67108864) {
        return ['ok' => false, 'path' => '', 'file' => '', 'reason' => '文件超过 64MB，不适合自动分发'];
    }

    return ['ok' => true, 'path' => (string) $best['path'], 'file' => (string) $best['file'], 'reason' => ''];
}

/**
 * 把文件上传到面板的 MOD 库。
 *
 * @return array<string,mixed>
 */
function upload_mod_file(string $path, string $originalName, string $component): array
{
    global $config;

    $url = (string) $config['panel_url'];
    if ($url === '') {
        return ['ok' => false, 'error' => 'panel_url 未配置'];
    }

    $size = (int) @filesize($path);
    $bytes = @file_get_contents($path);
    if (!is_string($bytes) || $bytes === '') {
        return ['ok' => false, 'error' => '读取文件失败'];
    }

    $payload = [
        'server_id' => (string) $config['server_id'],
        'token'     => (string) $config['token'],
        'action'    => 'mod_upload',
        'filename'  => $originalName,
        'component' => $component,
        'sha256'    => hash('sha256', $bytes),
    ];

    $response = http_post_json($url, array_merge($payload, [
        'content_b64' => base64_encode($bytes),
    ]), max(60.0, $size / 262144));

    if (empty($response['ok'])) {
        return ['ok' => false, 'error' => (string) ($response['error'] ?? '上传失败')];
    }
    $data = (array) ($response['data'] ?? []);
    if (empty($data['ok'])) {
        return ['ok' => false, 'error' => (string) ($data['error'] ?? '面板拒绝了文件')];
    }

    return [
        'ok'     => true,
        'id'     => (string) ($data['id'] ?? ''),
        'size'   => $size,
        'notice' => '文件已入库，玩家页面会出现下载按钮',
    ];
}

/**
 * 归一化 MOD 名（与面板侧 ServerMods::modKey 保持一致）。
 */
function mod_key(string $name): string
{
    $name = strtolower(trim($name));
    if ($name === '') {
        return '';
    }
    $name = preg_replace('/\.(jar|zip|disabled|old|bak)$/i', '', $name) ?? $name;
    $name = preg_replace('/[-_\.\s]+(?:mc)?1\.\d{1,2}(?:\.\d{1,2})?.*$/', '', $name) ?? $name;
    $name = preg_replace('/[-_\.\s]+v?\d+[\d\.\+]*.*$/', '', $name) ?? $name;
    $name = preg_replace('/[-_\s]+(forge|fabric|neoforge|quilt|common|universal|api|mod)$/i', '', $name) ?? $name;

    return preg_replace('/[^a-z0-9]/', '', $name) ?? $name;
}

/**
 * 执行一条诊断检查，返回统一结构。
 *
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function run_check(string $code, array $context): array
{
    global $config;

    $started = microtime(true);
    $result = ['status' => 'unknown', 'message' => '未实现', 'data' => []];

    switch ($code) {
        case 'process':
            $probe = probe_process();
            $result = [
                'status'  => $probe['ok'] ? 'pass' : 'fail',
                'message' => (string) $probe['detail'],
                'data'    => $probe,
            ];
            break;

        case 'process_info':
            $before = find_mc_processes();
            if (!$before) {
                $result = ['status' => 'fail', 'message' => '没有找到运行中的 MC 进程', 'data' => []];
                break;
            }
            $info = process_cpu_percent($before);
            $disk = df((string) ($config['disk_path'] ?: mc_dir()));
            $status = 'pass';
            $msg = sprintf('PID %d，CPU %.1f%%，内存 %.1f MB', (int) $info['pid'], (float) $info['cpu'], (float) $info['mem_mb']);
            $cpuWarn = (float) ($config['cpu_warn'] ?? 90);
            if ($cpuWarn > 0 && (float) $info['cpu'] > $cpuWarn) {
                $status = 'warn';
                $msg .= ' —— CPU 长期高占用，服务端在硬扛';
            }
            $result = [
                'status'  => $status,
                'message' => $msg,
                'data'    => [
                    'pid'      => (int) $info['pid'],
                    'cpu'      => (float) $info['cpu'],
                    'mem_mb'   => (float) $info['mem_mb'],
                    'cmd'      => (string) ($info['cmd'] ?? ''),
                    'cwd'      => (string) ($info['cwd'] ?? ''),
                    'disk'     => $disk,
                    'disk_free'=> $disk['ok'] ? human_bytes($disk['free']) : '',
                    'disk_total'=> $disk['ok'] ? human_bytes($disk['total']) : '',
                ],
            ];
            break;

        case 'port':
            $port = (int) ($config['listen_port'] ?? 25565);
            $probe = probe_port($port);
            $result = [
                'status'  => $probe['listening'] ? 'pass' : 'fail',
                'message' => (string) $probe['detail'],
                'data'    => array_merge($probe, ['port' => $port]),
            ];
            break;

        case 'disk':
            $path = (string) ($config['disk_path'] ?: mc_dir());
            $disk = df($path);
            if (!$disk['ok']) {
                $result = ['status' => 'unknown', 'message' => '无法读取磁盘信息：' . $path, 'data' => []];
                break;
            }
            $freeGb = $disk['free'] / 1073741824;
            $status = 'pass';
            $msg = sprintf('%s 剩余 %s / %s（已用 %.1f%%）', $path, human_bytes($disk['free']), human_bytes($disk['total']), $disk['percent']);
            if ($freeGb < 1) {
                $status = 'fail';
                $msg .= ' —— 剩余不足 1GB，服务端随时会崩';
            } elseif ($freeGb < 5) {
                $status = 'warn';
                $msg .= ' —— 剩余空间偏少，注意爆盘';
            }
            $result = [
                'status'  => $status,
                'message' => $msg,
                'data'    => ['path' => $path, 'free' => human_bytes($disk['free']), 'total' => human_bytes($disk['total']), 'percent' => $disk['percent'], 'free_bytes' => $disk['free']],
            ];
            break;

        case 'logs':
            $log = tail_log(500);
            if (!$log['ok']) {
                $result = ['status' => 'unknown', 'message' => (string) $log['detail'], 'data' => $log];
                break;
            }
            $analysis = analyze_logs((array) $log['lines']);
            $tps = tps_from_log((array) $log['lines']);
            $critical = ['OutOfMemoryError', 'CrashReport', 'AddressInUse', 'DiskFull', 'WorldCorrupt'];
            $errors = ['Watchdog', 'Exception', 'PluginError', 'ModError', 'DataFixer'];

            $topPattern = '';
            $criticalHits = 0;
            $errorHits = 0;
            foreach ($analysis['hits'] as $name => $count) {
                if ($topPattern === '') {
                    $topPattern = (string) $name;
                }
                if (in_array($name, $critical, true)) {
                    $criticalHits += (int) $count;
                }
                if (in_array($name, $errors, true)) {
                    $errorHits += (int) $count;
                }
            }

            $data = [
                'path'        => (string) $log['path'],
                'size'        => human_bytes((float) $log['size']),
                'age_seconds' => (int) $log['age'],
                'hits'        => $analysis['hits'],
                'samples'     => $analysis['samples'],
                'plugins'     => $analysis['plugins'],
                'tps_from_log'=> $tps,
                'top_pattern' => $topPattern,
            ];

            if ($criticalHits > 0) {
                $result = [
                    'status'  => 'fail',
                    'message' => sprintf('日志里有 %d 处致命错误（%s）', $criticalHits, implode('、', array_slice(array_keys($analysis['hits']), 0, 3))),
                    'data'    => $data,
                ];
            } elseif ($errorHits > 0) {
                $result = [
                    'status'  => 'warn',
                    'message' => sprintf('日志里有 %d 处异常（%s）', $errorHits, implode('、', array_slice(array_keys($analysis['hits']), 0, 3))),
                    'data'    => $data,
                ];
            } else {
                $result = [
                    'status'  => 'pass',
                    'message' => '最近 ' . count((array) $log['lines']) . ' 行日志没有发现异常',
                    'data'    => $data,
                ];
            }

            // 日志长时间不更新 = 服务端可能卡死
            if ((int) $log['age'] > 900) {
                $result['status'] = $result['status'] === 'pass' ? 'warn' : $result['status'];
                $result['message'] .= '；日志已 ' . round(((int) $log['age']) / 60) . ' 分钟没有更新';
                $result['data']['log_stale'] = true;
            }
            break;

        case 'plugin_errors':
            $log = tail_log(800);
            if (!$log['ok']) {
                $result = ['status' => 'unknown', 'message' => (string) $log['detail'], 'data' => []];
                break;
            }
            $analysis = analyze_logs((array) $log['lines']);
            $plugins = (array) $analysis['plugins'];
            $total = array_sum($plugins);

            if ($total === 0) {
                $result = ['status' => 'pass', 'message' => '日志里没有归集到插件报错', 'data' => ['plugins' => []]];
                break;
            }

            $top = array_key_first($plugins);
            $result = [
                'status'  => $total > 5 ? 'fail' : 'warn',
                'message' => sprintf('归集到 %d 条插件报错，最可疑的是 %s（%d 条）', $total, (string) $top, (int) $plugins[$top]),
                'data'    => ['plugins' => $plugins, 'samples' => $analysis['samples']],
            ];
            break;

        case 'tps':
            $log = tail_log(500);
            $tps = $log['ok'] ? tps_from_log((array) $log['lines']) : null;
            if ($tps === null) {
                $result = ['status' => 'unknown', 'message' => '日志里没有 TPS 记录（建议开启 RCON，用 tps 指令更准）', 'data' => []];
                break;
            }
            $status = $tps >= 19 ? 'pass' : ($tps >= 15 ? 'warn' : 'fail');
            $result = [
                'status'  => $status,
                'message' => sprintf('日志读数 TPS %.1f', $tps),
                'data'    => ['tps' => $tps, 'source' => 'log'],
            ];
            break;

        case 'world_info':
            $info = world_info();
            if ($info['world_size'] === 0) {
                $result = ['status' => 'unknown', 'message' => '没找到 world 目录，请检查 mc_dir 配置', 'data' => $info];
                break;
            }
            $age = $info['newest'] > 0 ? time() - $info['newest'] : 0;
            $status = 'pass';
            $msg = sprintf('存档 %s，最近写入 %s', human_bytes((float) $info['world_size']), $age > 0 ? round($age / 60) . ' 分钟前' : '刚刚');
            if ($age > 1800) {
                $status = 'warn';
                $msg .= ' —— 半小时没有写入，可能已卡死';
            }
            $result = [
                'status'  => $status,
                'message' => $msg,
                'data'    => [
                    'world_size'   => human_bytes((float) $info['world_size']),
                    'world_bytes'  => $info['world_size'],
                    'last_write_ago' => $age,
                ],
            ];
            break;

        case 'player_online':
            $player = (string) ($context['player'] ?? '');
            $log = tail_log(400);
            $online = null;
            if ($log['ok']) {
                // 从日志里追踪 join/leave
                $joined = false;
                foreach ((array) $log['lines'] as $line) {
                    if ($player !== '' && stripos($line, $player) !== false) {
                        if (preg_match('/joined the game|logged in with entity id/i', $line)) {
                            $joined = true;
                        }
                        if (preg_match('/left the game|lost connection|Disconnecting/i', $line)) {
                            $joined = false;
                        }
                    }
                }
                $online = $joined;
            }

            $result = [
                'status'  => $online === true ? 'pass' : 'warn',
                'message' => $online === true
                    ? '日志显示 ' . $player . ' 最近一次是进入游戏，应在线'
                    : '日志里没有 ' . $player . ' 在线记录（可能已掉线，或服务端未记录）',
                'data'    => ['player' => $player, 'online_by_log' => $online],
            ];
            break;

        case 'whitelist_player':
        case 'ban_player':
        case 'whitelist':
            // 这几项优先走面板 RCON；Agent 侧只能读文件
            [$status, $msg, $data] = whitelist_file_check($code, (string) ($context['player'] ?? ''));
            $result = ['status' => $status, 'message' => $msg, 'data' => $data];
            break;

        default:
            $result = ['status' => 'unknown', 'message' => '不支持的检查项：' . $code, 'data' => []];
    }

    $result['code'] = $code;
    $result['ms'] = round((microtime(true) - $started) * 1000, 1);

    return $result;
}

/**
 * 读服务器自带的 json 文件（白名单 / 封禁列表）。
 *
 * @return array{0:string,1:string,2:array<string,mixed>}
 */
function whitelist_file_check(string $code, string $player): array
{
    $dir = mc_dir();
    $whitelistFile = $dir . '/whitelist.json';
    $bannedFile = $dir . '/banned-players.json';

    if ($code === 'ban_player') {
        if (!is_file($bannedFile)) {
            return ['pass', '没有 banned-players.json，说明没人被封禁', ['banned' => false]];
        }
        $list = json_decode((string) @file_get_contents($bannedFile), true);
        $names = [];
        foreach ((array) $list as $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $names[] = (string) $entry['name'];
            }
        }
        $banned = $player !== '' && in_array(strtolower($player), array_map('strtolower', $names), true);

        return [
            $banned ? 'fail' : 'pass',
            $banned
                ? sprintf('banned-players.json 里有 %s，属于封禁状态', $player)
                : ($names ? sprintf('封禁名单里有 %d 人，不含 %s', count($names), $player) : '封禁名单为空'),
            ['names' => $names, 'banned' => $banned],
        ];
    }

    if (!is_file($whitelistFile)) {
        return ['skipped', '没有 whitelist.json，说明白名单未启用', ['whitelist_enabled' => false]];
    }

    $list = json_decode((string) @file_get_contents($whitelistFile), true);
    $names = [];
    foreach ((array) $list as $entry) {
        if (is_array($entry) && isset($entry['name'])) {
            $names[] = (string) $entry['name'];
        }
    }

    if ($code === 'whitelist') {
        if (!$names) {
            return ['fail', 'whitelist.json 存在但里面一个人都没有 —— 除了 OP 谁都进不来', ['count' => 0]];
        }

        return ['pass', sprintf('白名单文件里有 %d 个名额', count($names)), ['count' => count($names), 'names' => array_slice($names, 0, 50)]];
    }

    $inList = $player !== '' && in_array(strtolower($player), array_map('strtolower', $names), true);

    return [
        $inList ? 'pass' : 'fail',
        $inList
            ? sprintf('%s 已经在 whitelist.json 里', $player)
            : sprintf('%s 不在 whitelist.json 里（白名单共 %d 人）', $player, count($names)),
        ['in_whitelist' => $inList, 'count' => count($names)],
    ];
}

/**
 * 执行一个修复配方。只认白名单里的 code，参数均已由面板校验过。
 *
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function run_recipe(string $recipe, array $params): array
{
    global $config;

    $started = microtime(true);
    $guard = (array) $config['guard'];

    $respond = static function (bool $ok, string $message, array $data = []) use ($started): array {
        return array_merge([
            'ok'      => $ok,
            'message' => $message,
            'elapsed' => round(microtime(true) - $started, 2),
        ], $data);
    };

    switch ($recipe) {
        case 'restart_server':
            // 1) 尽力保存世界
            $save = rcon_command('save-all flush');
            // 2) 按守护方式重启
            $type = (string) ($guard['type'] ?? 'systemd');
            $restartCmd = trim((string) ($guard['restart_cmd'] ?? ''));
            $steps = [];

            if ($restartCmd !== '') {
                $cmd = $restartCmd;
            } elseif ($type === 'systemd' && !empty($guard['service'])) {
                $cmd = 'systemctl restart ' . escapeshellarg((string) $guard['service']);
            } elseif ($type === 'screen' && !empty($guard['session'])) {
                $session = (string) $guard['session'];
                $start = trim((string) ($guard['start_cmd'] ?? ''));
                if ($start === '') {
                    $start = 'cd ' . escapeshellarg(mc_dir()) . ' && screen -dmS ' . escapeshellarg($session)
                        . ' java -Xms2G -Xmx4G -jar server.jar nogui';
                }
                $cmd = 'screen -S ' . escapeshellarg($session) . ' -X quit 2>/dev/null; sleep 3; ' . $start;
            } elseif ($type === 'tmux' && !empty($guard['session'])) {
                $session = (string) $guard['session'];
                $start = trim((string) ($guard['start_cmd'] ?? ''));
                if ($start === '') {
                    $start = 'cd ' . escapeshellarg(mc_dir()) . ' && tmux new-session -d -s ' . escapeshellarg($session)
                        . ' java -Xms2G -Xmx4G -jar server.jar nogui';
                }
                $cmd = 'tmux kill-session -t ' . escapeshellarg($session) . ' 2>/dev/null; sleep 3; ' . $start;
            } elseif ($type === 'panel' && !empty($guard['start_cmd'])) {
                $cmd = (string) $guard['start_cmd'];
            } else {
                return $respond(false, '守护方式未正确配置（systemd 需要单元名，screen/tmux 需要会话名），无法自动重启', [
                    'channel' => 'agent',
                    'guard'   => $guard,
                ]);
            }

            agent_log('info', '执行重启', ['cmd' => $cmd]);
            $result = run_cmd($cmd, 90);
            $steps[] = ['cmd' => $cmd, 'ok' => $result['ok'], 'code' => $result['code'], 'stderr' => mb_substr($result['stderr'], 0, 300)];

            // 3) 等待端口重新可连（最多 90 秒）
            $port = (int) ($config['listen_port'] ?? 25565);
            $waitStart = microtime(true);
            $up = false;
            while (microtime(true) - $waitStart < 90) {
                sleep(3);
                $probe = probe_port($port);
                if (!empty($probe['listening'])) {
                    $up = true;
                    break;
                }
            }

            return $respond($result['ok'], $up
                ? '重启完成，端口 ' . $port . ' 已恢复监听（耗时 ' . round(microtime(true) - $waitStart) . ' 秒）'
                : '重启命令已执行，但 ' . $port . ' 端口在 90 秒内仍未监听，请检查服务端日志', [
                    'channel'  => 'agent',
                    'save_rcon'=> $save,
                    'steps'    => $steps,
                    'port_up'  => $up,
                    'guard'    => $type,
                ]);

        case 'save_world':
        case 'whitelist_add':
        case 'unban_player':
        case 'kick_player':
        case 'clear_self_items':
        case 'reload_plugins':
        case 'unmute_player':
            // 这些本质是 RCON 指令；面板侧失败过才会落到这里
            $command = recipe_command($recipe, $params);
            if ($command === '') {
                return $respond(false, '无法把配方转换为指令：' . $recipe);
            }

            $rcon = rcon_command($command);
            if (!empty($rcon['ok'])) {
                return $respond(true, 'RCON 执行成功：' . $command, [
                    'channel' => 'agent-rcon',
                    'command' => $command,
                    'output'  => (string) ($rcon['output'] ?? ''),
                ]);
            }

            return $respond(false, 'RCON 执行失败：' . (string) ($rcon['error'] ?? '未知错误') . '；请在面板上开启 RCON 或改用控制台方式', [
                'channel' => 'agent-rcon',
                'command' => $command,
            ]);

        case 'backup_world':
            $dir = mc_dir();
            $backupDir = (string) ($config['backup_dir'] ?? '');
            if ($backupDir === '') {
                $backupDir = $dir . '/../mcfix-backups';
            }
            if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
                return $respond(false, '无法创建备份目录：' . $backupDir);
            }

            $stamp = date('Ymd-His');
            $target = rtrim($backupDir, '/') . '/world-' . $stamp . '.tar.gz';
            if (!function_exists('proc_open')) {
                return $respond(false, 'proc_open 被禁用，无法打包备份');
            }

            $worlds = [];
            foreach (['world', 'world_nether', 'world_the_end', 'server.properties', 'ops.json', 'whitelist.json'] as $item) {
                if (file_exists($dir . '/' . $item)) {
                    $worlds[] = escapeshellarg($item);
                }
            }
            if (!$worlds) {
                return $respond(false, '在 ' . $dir . ' 里没找到可备份的内容');
            }

            $cmd = 'cd ' . escapeshellarg($dir) . ' && tar -czf ' . escapeshellarg($target) . ' ' . implode(' ', $worlds) . ' 2>&1';
            $result = run_cmd($cmd, 300);
            if (!$result['ok'] || !is_file($target)) {
                return $respond(false, '备份失败：' . mb_substr($result['stderr'] . $result['stdout'], 0, 300), ['channel' => 'agent']);
            }

            return $respond(true, '备份完成：' . $target . '（' . human_bytes((float) filesize($target)) . '）', [
                'channel' => 'agent',
                'file'    => $target,
                'size'    => human_bytes((float) filesize($target)),
            ]);

        case 'monitor_server':
            $probe = probe_process();
            $port = (int) ($config['listen_port'] ?? 25565);
            $portProbe = probe_port($port);
            $healthy = $probe['ok'] && !empty($portProbe['listening']);

            return $respond($healthy, $healthy
                ? '守护检查通过：进程与端口都正常'
                : '守护检查发现异常：' . $probe['detail'] . '；' . $portProbe['detail'], [
                    'channel' => 'agent',
                    'process' => $probe,
                    'port'    => $portProbe,
                    'healthy' => $healthy,
                ]);

        case 'pull_mod':
            // 玩家缺 MOD → 在服务端目录里找同名文件 → 上传到站点 → 玩家直接下载
            $component = (string) ($params['component'] ?? '');
            $found = find_mod_file($component);
            if (empty($found['ok'])) {
                return $respond(false, '取回失败：' . (string) $found['reason'], [
                    'channel'   => 'agent',
                    'component' => $component,
                    'hint'      => '请管理员把这个 MOD 放到服务端 mods 目录，或在后台手工上传',
                ]);
            }

            $upload = upload_mod_file((string) $found['path'], (string) $found['file'], $component);
            if (empty($upload['ok'])) {
                return $respond(false, '上传失败：' . (string) $upload['error'], [
                    'channel'   => 'agent',
                    'component' => $component,
                    'file'      => (string) $found['file'],
                ]);
            }

            return $respond(true, '已取回 ' . (string) $found['file'] . '（' . human_bytes((float) $upload['size']) . '），玩家可以下载了', [
                'channel'   => 'agent',
                'component' => $component,
                'file'      => (string) $found['file'],
                'mod_id'    => (string) ($upload['id'] ?? ''),
                'size'      => (int) $upload['size'],
                'notice'    => (string) ($upload['notice'] ?? ''),
            ]);

        default:
            return $respond(false, '未知修复配方：' . $recipe . '（Agent 只执行白名单内的配方）');
    }
}

/**
 * 配方 → 控制台指令。
 *
 * @param array<string,mixed> $params
 */
function recipe_command(string $recipe, array $params): string
{
    $player = (string) ($params['player'] ?? '');

    switch ($recipe) {
        case 'save_world':
            return 'save-all flush';
        case 'whitelist_add':
            return $player !== '' ? 'whitelist add ' . $player : '';
        case 'unban_player':
            return $player !== '' ? 'pardon ' . $player : '';
        case 'unmute_player':
            // 原版没有禁言，禁言由插件提供；这里优先用最常见的两个指令
            return $player !== '' ? 'unmute ' . $player : '';
        case 'kick_player':
            return $player !== '' ? 'kick ' . $player . ' ' . (string) ($params['reason'] ?? '服务器维护中') : '';
        case 'clear_self_items':
            return $player !== '' ? 'clear ' . $player : '';
        case 'reload_plugins':
            return 'reload confirm';
        default:
            return '';
    }
}

/**
 * 通过 RCON 发指令（Agent 自己连本地 RCON，不依赖面板）。
 *
 * @return array<string,mixed>
 */
function rcon_command(string $command): array
{
    global $config;

    $rcon = (array) ($config['rcon'] ?? []);
    if (empty($rcon['enabled']) || empty($rcon['password'])) {
        return ['ok' => false, 'output' => '', 'error' => 'Agent 侧未配置 RCON'];
    }

    $host = (string) ($rcon['host'] ?? '127.0.0.1');
    $port = (int) ($rcon['port'] ?? 25575);
    $password = (string) $rcon['password'];
    $timeout = (float) ($rcon['timeout'] ?? 3);

    $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout);
    if ($socket === false) {
        return ['ok' => false, 'output' => '', 'error' => '无法连接 RCON ' . $host . ':' . $port . '（' . $errstr . '）'];
    }
    stream_set_timeout($socket, (int) $timeout);

    $id = random_int(1, 100000);
    $auth = pack('VV', $id, 3) . $password . "\x00\x00";
    fwrite($socket, pack('V', strlen($auth)) . $auth);

    $response = rcon_read($socket);
    if ($response === null || $response['id'] === -1) {
        fclose($socket);

        return ['ok' => false, 'output' => '', 'error' => 'RCON 认证失败（密码错误？）'];
    }

    $id++;
    $packet = pack('VV', $id, 2) . $command . "\x00\x00";
    fwrite($socket, pack('V', strlen($packet)) . $packet);

    $output = '';
    for ($i = 0; $i < 4; $i++) {
        $response = rcon_read($socket, 0.6);
        if ($response === null) {
            break;
        }
        if ($response['id'] === $id) {
            $output .= $response['body'];
            if ($i > 0) {
                break;
            }
        }
    }
    fclose($socket);

    return ['ok' => true, 'output' => trim($output), 'error' => ''];
}

/**
 * @param resource $socket
 * @return array{id:int,type:int,body:string}|null
 */
function rcon_read($socket, float $timeout = 3.0): ?array
{
    stream_set_timeout($socket, (int) $timeout, (int) (($timeout - floor($timeout)) * 1000000));
    $header = '';
    $deadline = microtime(true) + $timeout;
    while (strlen($header) < 4 && microtime(true) < $deadline) {
        $chunk = fread($socket, 4 - strlen($header));
        if ($chunk === false) {
            return null;
        }
        if ($chunk === '') {
            $info = stream_get_meta_data($socket);
            if (!empty($info['timed_out'])) {
                return null;
            }
            usleep(2000);
            continue;
        }
        $header .= $chunk;
    }
    if (strlen($header) < 4) {
        return null;
    }

    $length = (int) unpack('V', $header)[1];
    if ($length < 10 || $length > 4194304) {
        return null;
    }

    $body = '';
    while (strlen($body) < $length && microtime(true) < $deadline) {
        $chunk = fread($socket, $length - strlen($body));
        if ($chunk === false) {
            return null;
        }
        if ($chunk === '') {
            usleep(2000);
            continue;
        }
        $body .= $chunk;
    }
    if (strlen($body) < $length) {
        return null;
    }

    return [
        'id'   => (int) unpack('V', substr($body, 0, 4))[1],
        'type' => (int) unpack('V', substr($body, 4, 4))[1],
        'body' => rtrim(substr($body, 8), "\x00"),
    ];
}

/**
 * 执行一条面板下发的任务。
 *
 * @param array<string,mixed> $task
 * @return array<string,mixed>
 */
function execute_task(array $task): array
{
    $params = (array) ($task['params'] ?? []);
    $action = (string) ($task['action'] ?? '');
    $recipe = (string) ($task['recipe'] ?? '');

    if ($action === 'diag') {
        $context = (array) ($params['context'] ?? []);
        $checks = (array) ($params['checks'] ?? []);
        $results = [];

        foreach ($checks as $code) {
            $code = (string) $code;
            agent_log('info', '执行检查：' . $code);
            try {
                $results[$code] = run_check($code, $context);
            } catch (Throwable $e) {
                $results[$code] = [
                    'status'  => 'unknown',
                    'message' => '检查执行异常：' . $e->getMessage(),
                    'data'    => [],
                ];
            }
        }

        return ['ok' => true, 'checks' => $results];
    }

    if ($action === 'run') {
        agent_log('info', '执行修复配方：' . $recipe, $params);
        try {
            $result = run_recipe($recipe, $params);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => '配方执行异常：' . $e->getMessage(), 'recipe' => $recipe];
        }

        return array_merge($result, ['recipe' => $recipe]);
    }

    return ['ok' => false, 'error' => '未知任务类型：' . $action];
}

// --------------------------------------------------------------------- 心跳数据

function collect_status(): array
{
    global $config;

    $process = probe_process();
    $port = (int) ($config['listen_port'] ?? 25565);
    $portProbe = probe_port($port);
    $disk = df((string) ($config['disk_path'] ?: mc_dir()));
    $log = tail_log(200);

    $tps = null;
    $motd = '';
    $version = '';
    $players = 0;
    $maxPlayers = 0;

    if ($log['ok']) {
        $tps = tps_from_log((array) $log['lines']);
    }

    // 顺便用 Minecraft 协议问一次人数（本地回环，很快）
    $status = mc_ping('127.0.0.1', $port, 2.0);
    if (!empty($status['ok'])) {
        $players = (int) $status['players'];
        $maxPlayers = (int) $status['max_players'];
        $version = (string) $status['version'];
        $motd = (string) $status['motd'];
    }

    $cpu = '';
    $mem = '';
    if ($process['ok']) {
        $before = find_mc_processes();
        if ($before) {
            $info = process_cpu_percent($before);
            $cpu = (string) round((float) $info['cpu'], 1);
            $mem = round((float) $info['mem_mb'], 1) . 'MB';
        }
    }

    $expectedMin = 0;
    $issues = [];
    if (!$process['ok']) {
        $issues[] = '进程未运行';
    }
    if (empty($portProbe['listening'])) {
        $issues[] = '端口未监听';
    }

    return [
        'agent_version' => AGENT_VERSION,
        'hostname'      => function_exists('gethostname') ? (string) gethostname() : php_uname('n'),
        'mc_online'     => $process['ok'] && !empty($portProbe['listening']),
        'players'       => $players,
        'max_players'   => $maxPlayers,
        'tps'           => $tps !== null ? (string) $tps : '',
        'cpu'           => $cpu,
        'mem'           => $mem,
        'disk_free'     => $disk['ok'] ? human_bytes($disk['free']) : '',
        'disk_total'    => $disk['ok'] ? human_bytes($disk['total']) : '',
        'motd'          => $motd,
        'version'       => $version,
        'java'          => PHP_VERSION,
        'load'          => (string) (@file_get_contents('/proc/loadavg') ?: ''),
        'uptime'        => (string) (@file_get_contents('/proc/uptime') ?: ''),
        'log_size'      => $log['ok'] ? human_bytes((float) $log['size']) : '',
        'mc_dir'        => mc_dir(),
        'mods'          => scan_server_mods(),
        'error'         => implode('；', $issues),
        'at'            => date('c'),
    ];
}

/**
 * 轻量 Minecraft 协议探测（只看人数/版本，不需要完整实现）。
 *
 * @return array<string,mixed>
 */
function mc_ping(string $host, int $port, float $timeout = 2.0): array
{
    $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout);
    if ($fp === false) {
        return ['ok' => false, 'error' => $errstr];
    }
    stream_set_timeout($fp, (int) $timeout);

    $pack = static function (int $value): string {
        $out = '';
        $value &= 0xFFFFFFFF;
        do {
            $temp = $value & 0x7F;
            $value = ($value >> 7) & 0x1FFFFFF;
            if ($value !== 0) {
                $temp |= 0x80;
            }
            $out .= chr($temp);
        } while ($value !== 0);

        return $out;
    };

    $handshake = $pack(0) . $pack(0) . $pack(strlen($host)) . $host . pack('n', $port) . $pack(1);
    fwrite($fp, $pack(strlen($handshake)) . $handshake);
    fwrite($fp, $pack(1) . $pack(0));

    $lenBytes = '';
    for ($i = 0; $i < 5; $i++) {
        $b = fread($fp, 1);
        if ($b === false || $b === '') {
            fclose($fp);

            return ['ok' => false, 'error' => '无响应'];
        }
        $lenBytes .= $b;
        if ((ord($b) & 0x80) === 0) {
            break;
        }
    }
    $length = 0;
    $shift = 0;
    for ($i = 0; $i < strlen($lenBytes); $i++) {
        $length |= (ord($lenBytes[$i]) & 0x7F) << $shift;
        $shift += 7;
    }

    $payload = '';
    $deadline = microtime(true) + $timeout;
    while (strlen($payload) < $length && microtime(true) < $deadline) {
        $chunk = fread($fp, max(1, $length - strlen($payload)));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $payload .= $chunk;
    }
    fclose($fp);

    $offset = 0;
    $readVarInt = static function () use (&$offset, $payload): ?int {
        $result = 0;
        $shift = 0;
        for ($i = 0; $i < 5; $i++) {
            if (!isset($payload[$offset])) {
                return null;
            }
            $byte = ord($payload[$offset++]);
            $result |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                return $result;
            }
            $shift += 7;
        }

        return null;
    };

    $readVarInt(); // packet id
    $jsonLen = $readVarInt();
    if ($jsonLen === null) {
        return ['ok' => false, 'error' => '解析失败'];
    }
    $json = substr($payload, $offset, $jsonLen);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'MOTD 非法'];
    }

    return [
        'ok'          => true,
        'players'     => (int) ($data['players']['online'] ?? 0),
        'max_players' => (int) ($data['players']['max'] ?? 0),
        'version'     => (string) ($data['version']['name'] ?? ''),
        'motd'        => is_string($data['description'] ?? null) ? (string) $data['description'] : '',
    ];
}

// --------------------------------------------------------------------- 与面板通信

/**
 * 轮询面板。
 *
 * @return array<string,mixed>
 */
function poll_panel(): array
{
    global $config;

    $url = (string) $config['panel_url'];
    if ($url === '') {
        return ['ok' => false, 'error' => 'config.php 里没有配置 panel_url'];
    }

    $payload = [
        'server_id' => (string) $config['server_id'],
        'token'     => (string) $config['token'],
        'action'    => 'poll',
        'report'    => collect_status(),
    ];

    $timeout = (float) ($config['poll_timeout'] ?? 20);
    $response = http_post_json($url, $payload, $timeout);

    if (empty($response['ok'])) {
        return ['ok' => false, 'error' => (string) ($response['error'] ?? '请求失败')];
    }

    $data = $response['data'] ?? [];
    if (empty($data['ok'])) {
        return ['ok' => false, 'error' => (string) ($data['error'] ?? '面板返回错误')];
    }

    return ['ok' => true, 'data' => $data];
}

/**
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function http_post_json(string $url, array $payload, float $timeout = 20.0): array
{
    $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Requested-With: XMLHttpRequest'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) ceil($timeout),
            CURLOPT_CONNECTTIMEOUT => 8,
            // 默认校验证书。任务内容（玩家名、指令、日志片段）都会经过这条连接，
            // 只有面板用自签证书、且本地配置里显式写了 'insecure' => true 才跳过。
            CURLOPT_SSL_VERIFYPEER => empty($GLOBALS['MCFIX_INSECURE_TLS']),
            CURLOPT_SSL_VERIFYHOST => empty($GLOBALS['MCFIX_INSECURE_TLS']) ? 2 : 0,
            CURLOPT_USERAGENT      => 'mcfix-agent/' . AGENT_VERSION,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => 'curl：' . $err];
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => '面板返回非 JSON（HTTP ' . $code . '）：' . mb_substr((string) $raw, 0, 200)];
        }

        return ['ok' => true, 'data' => $decoded];
    }

    // 没有 curl 时用流
    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nX-Requested-With: XMLHttpRequest\r\nUser-Agent: mcfix-agent/" . AGENT_VERSION . "\r\n",
            'content'       => $body,
            'timeout'       => $timeout,
            'ignore_errors' => true,
            // 默认 follow_location=1 会把这次 POST **连 body 一起**重发到跳转目标，
            // 而 body 里装着 Agent 令牌。面板域名写错或被人做跳转就等于把令牌送出去。
            // 显式关掉：跟随跳转不是这里想要的行为。
            'follow_location' => 0,
        ],
        'ssl' => [
            'verify_peer'      => empty($GLOBALS['MCFIX_INSECURE_TLS']),
            'verify_peer_name' => empty($GLOBALS['MCFIX_INSECURE_TLS']),
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        return ['ok' => false, 'error' => '请求失败（file_get_contents）'];
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => '面板返回非 JSON：' . mb_substr((string) $raw, 0, 200)];
    }

    return ['ok' => true, 'data' => $decoded];
}

/**
 * 上报任务结果。
 *
 * @param array<string,mixed> $task
 * @param array<string,mixed> $result
 */
function report_task(array $task, array $result): void
{
    global $config;

    $payload = [
        'server_id'   => (string) $config['server_id'],
        'token'       => (string) $config['token'],
        'action'      => 'report',
        'task_id'     => (int) ($task['id'] ?? 0),
        'lease_token' => (string) ($task['lease_token'] ?? ''),
        'ok'          => !empty($result['ok']),
        'result'      => $result,
        'error'       => (string) ($result['error'] ?? ''),
    ];

    $response = http_post_json((string) $config['panel_url'], $payload, 25);
    if (empty($response['ok'])) {
        agent_log('error', '上报任务结果失败', ['task' => $task['id'] ?? 0, 'error' => $response['error'] ?? '']);
        return;
    }
    if (empty($response['data']['ok'])) {
        agent_log('warn', '面板拒绝任务结果', ['task' => $task['id'] ?? 0, 'error' => $response['data']['error'] ?? '']);
    }
}

// --------------------------------------------------------------------- 自检

if (isset($options['check'])) {
    echo "MCFix Agent 自检\n";
    echo str_repeat('-', 52), "\n";
    $checks = [
        'PHP 版本'        => PHP_VERSION . (PHP_VERSION_ID >= 70400 ? ' ✓' : ' ✗ 需要 7.4+'),
        'proc_open'       => function_exists('proc_open') ? '可用 ✓' : '被禁用 ✗',
        'curl'            => function_exists('curl_init') ? '可用 ✓' : '不可用（将用 stream 回退）',
        'openssl'         => extension_loaded('openssl') ? '可用 ✓' : '缺失 ✗（HTTPS 面板会失败）',
        'mc_dir'          => mc_dir() . (is_dir(mc_dir()) ? ' ✓' : ' ✗ 目录不存在'),
        '日志文件'        => abs_path((string) $config['log_path']) . (is_file(abs_path((string) $config['log_path'])) ? ' ✓' : ' ✗ 找不到'),
        'panel_url'       => (string) $config['panel_url'] ?: '✗ 未配置',
        'server_id'       => (string) $config['server_id'] ?: '✗ 未配置',
        'token'           => $config['token'] !== '' ? '已配置 ✓' : '✗ 未配置',
        '面板连通性'      => '检测中…',
    ];

    $probe = poll_panel();
    $checks['面板连通性'] = !empty($probe['ok'])
        ? '正常 ✓（面板时间：' . (string) ($probe['data']['server_time'] ?? '') . '）'
        : '失败 ✗ ' . (string) ($probe['error'] ?? '');

    $process = probe_process();
    $checks['服务端进程'] = (string) $process['detail'];
    $portProbe = probe_port((int) ($config['listen_port'] ?? 25565));
    $checks['端口监听'] = (string) $portProbe['detail'];
    $disk = df((string) ($config['disk_path'] ?: mc_dir()));
    $checks['磁盘'] = $disk['ok'] ? human_bytes($disk['free']) . ' 可用 / ' . human_bytes($disk['total']) : '读取失败';

    foreach ($checks as $label => $value) {
        printf("  %-16s %s\n", $label, $value);
    }
    echo str_repeat('-', 52), "\n";

    exit(!empty($probe['ok']) ? 0 : 1);
}

// --------------------------------------------------------------------- 本地任务（面板 local/ssh 通路）

if (isset($options['local-task'])) {
    $raw = (string) $options['local-task'];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        out(['ok' => false, 'error' => '任务 JSON 解析失败']);

        return; // 被 require 时直接返回
    }

    // 本地通路下，mc_dir / guard 来自面板下发的 params.root 或本机 config
    $result = execute_task([
        'action' => (string) ($decoded['action'] ?? ''),
        'recipe' => (string) ($decoded['recipe'] ?? ''),
        'params' => (array) ($decoded['params'] ?? []),
    ]);

    out($result);

    return;
}

// --------------------------------------------------------------------- 主循环

$once = isset($options['once']);

agent_log('info', 'MCFix Agent 启动', [
    'version' => AGENT_VERSION,
    'server'  => (string) $config['server_id'],
    'once'    => $once,
]);

if ((string) $config['panel_url'] === '' || (string) $config['server_id'] === '' || (string) $config['token'] === '') {
    agent_log('error', 'config.php 不完整：需要 panel_url / server_id / token');
    fwrite(STDERR, "配置文件不完整，请先运行 php mcfix-agent.php --check 查看问题\n");
    exit(1);
}

$interval = max(3, (int) $config['interval']);
$failures = 0;

do {
    $cycleStart = microtime(true);

    $poll = poll_panel();

    if (empty($poll['ok'])) {
        $failures++;
        agent_log('error', '轮询失败（连续 ' . $failures . ' 次）', ['error' => (string) ($poll['error'] ?? '')]);
        // 指数退避，最长 60 秒，避免面板挂掉时把 MC 机器打满
        $sleep = min(60, $interval * (1 + $failures));
        if (!$once) {
            sleep($sleep);
        }
        continue;
    }

    $failures = 0;
    $data = (array) $poll['data'];
    $tasks = (array) ($data['tasks'] ?? []);

    if ($tasks) {
        agent_log('info', '领取到 ' . count($tasks) . ' 个任务');
    }

    foreach ($tasks as $task) {
        if (!is_array($task)) {
            continue;
        }
        $result = execute_task($task);
        report_task($task, $result);

        // 重启类任务会给服务端时间恢复，这里不额外 sleep
        agent_log(!empty($result['ok']) ? 'info' : 'warn', sprintf(
            '任务 #%d %s %s',
            (int) ($task['id'] ?? 0),
            (string) ($task['recipe'] ?? ''),
            !empty($result['ok']) ? '成功' : ('失败：' . (string) ($result['error'] ?? ''))
        ));
    }

    if (!$once) {
        $elapsed = microtime(true) - $cycleStart;
        $sleep = max(1, (int) round($interval - $elapsed));
        sleep($sleep);
    }
} while (!$once);

agent_log('info', 'MCFix Agent 退出');
exit(0);
