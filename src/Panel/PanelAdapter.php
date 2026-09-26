<?php

declare(strict_types=1);

namespace MCFix\Panel;

/**
 * 面板 API 执行器。
 *
 * 支持的形态：
 *   mcsmanager   MCSManager（国内自建服最常用）
 *   pterodactyl  翼龙 / Pterodactyl（含 Pelican 等同源面板）
 *   bt           宝塔面板的"进程守护管理器"（MC 直接跑在宝塔这台机器上）
 *   webconsole    只有 WebSocket/HTTP 控制台、没有标准 API 的面板（简幻欢、雨云等租用服）
 *   custom        自定义 HTTP 接口（用 {command} 占位符拼 URL / body）
 *
 * 设计原则：
 *   1. **能力声明** —— 每个面板能做什么写清楚（capabilities），上层按能力决策，不做假设
 *   2. **不传 shell** —— 面板只收游戏指令、开关机信号、日志读取。绝不把任意命令拼进 URL
 *   3. **失败可解释** —— 每个方法都返回 {ok, error}，错误信息直接给管理员看
 */
abstract class PanelAdapter
{
    /** @var array<string,mixed> */
    protected $config = [];

    /** 服务器配置（含 mc_dir、guard 等） */
    protected $server = [];

    /** 最近一次请求的调试信息，失败时给管理员看 */
    protected $transcript = [];

    /** 单次请求超时 */
    protected $timeout = 12;

    /**
     * 是否跳过 TLS 证书校验。
     *
     * 默认**校验**。面板 API 的密钥、下发的游戏指令、读回来的日志都在这些请求里，
     * 关掉校验等于让它们裸奔在网络上，任何一跳都能被改。只有像宝塔那种用自签证书的
     * 本地面板，才由管理员在服务器配置里显式打开（panel.insecure = true），
     * 后台界面上会写明这个开关的代价。
     */
    protected $insecure = false;

    /**
     * @param array<string,mixed> $panel  配置里的 panel 段
     * @param array<string,mixed> $server 整个服务器配置
     */
    public function __construct(array $panel, array $server)
    {
        $this->config = $panel;
        $this->server = $server;
        $this->timeout = max(3, (int) ($panel['timeout'] ?? 12));
        $this->insecure = !empty($panel['insecure']);
    }

    // ---------------------------------------------------------------- 能力声明

    /**
     * 支持的能力清单。
     *
     * console      下发游戏指令（等价于 RCON，不需要开 enable-rcon）
     * power        开 / 关 / 重启实例
     * read_log     读取服务端日志（诊断的关键）
     * stat_log     读取日志文件的元信息（大小、修改时间）
     * list_dir     列目录（用于 MOD 清单比对）
     * read_file    读任意文件（MOD 清单等，只读）
     *
     * @return string[]
     */
    abstract public function capabilities(): array;

    /** 面板显示名 */
    abstract public function label(): string;

    /**
     * 读实例的资源占用（CPU / 内存 / 磁盘）。
     *
     * 只有部分面板提供（翼龙有），所以这里给一个默认实现：
     * 不支持的面板直接返回 ok=false，调用方不用再写 method_exists 探测。
     *
     * @return array<string,mixed>
     */
    public function resources(): array
    {
        return ['ok' => false, 'error' => '该面板未提供资源查询接口'];
    }

    // ---------------------------------------------------------------- 核心动作

    /**
     * 下发一条游戏指令（不含斜杠）。
     *
     * @return array{ok:bool,output:string,error:string,ms:float}
     */
    abstract public function sendCommand(string $command): array;

    /**
     * 这个面板下发指令时**能不能拿回控制台回显**？
     *
     * 和"能不能发指令"是两件事，而它们的用途正好相反：
     *
     *   - **修复动作**（加白名单、解封、save-all…）只要"发出去了"就行，
     *     不需要回显。所以翼龙 / Multicraft / MCSManager 这些只管投递、
     *     不回显的面板，跑修复完全没问题 —— `capabilities()` 里有 `console`
     *     指的就是这件事。
     *   - **验证检查**（tps / list / whitelist list / banlist）要**解析回显文本**
     *     才能得出结论。拿"已投递到控制台"去跑正则，运气好什么都匹配不到
     *     （报一个假的 warn），运气不好从中文提示里抠出个数字当成 TPS。
     *
     * 所以这里单独给一个默认返回 false 的能力位：**默认谁都不许拿它做检查**，
     * 只有确实返回可解析回显、并且调用方明确配置了取哪个字段的面板才覆盖它。
     *
     * 别用面板类型硬编码来判断 —— 同一家面板换个接口版本回显行为就可能变，
     * 由适配器自己声明才靠得住。
     */
    public function commandEcho(): bool
    {
        return false;
    }

    /**
     * 电源操作。
     *
     * @param string $action start|stop|restart|kill
     * @return array{ok:bool,output:string,error:string,ms:float}
     */
    abstract public function power(string $action): array;

    /**
     * 读取日志尾部。
     *
     * @return array{ok:bool,content:string,size:int,mtime:int,error:string}
     */
    public function readLog(int $maxBytes = 262144): array
    {
        return ['ok' => false, 'content' => '', 'size' => 0, 'mtime' => 0, 'error' => $this->label() . ' 不支持读取日志'];
    }

    /**
     * 日志文件元信息（不下载整个文件）。
     *
     * @return array{ok:bool,size:int,mtime:int,error:string}
     */
    public function statLog(): array
    {
        $log = $this->readLog(4096);
        if (empty($log['ok'])) {
            return ['ok' => false, 'size' => 0, 'mtime' => 0, 'error' => (string) $log['error']];
        }

        return ['ok' => true, 'size' => (int) $log['size'], 'mtime' => (int) $log['mtime'], 'error' => ''];
    }

    /**
     * 列目录（用于拿 MOD 清单）。
     *
     * @return array{ok:bool,files:array<int,array<string,mixed>>,error:string}
     */
    public function listDir(string $path): array
    {
        return ['ok' => false, 'files' => [], 'error' => $this->label() . ' 不支持列目录'];
    }

    /**
     * 读取一个文本文件（只读，用于 mods.toml 之类）。
     *
     * @return array{ok:bool,content:string,error:string}
     */
    public function readFile(string $path, int $maxBytes = 65536): array
    {
        return ['ok' => false, 'content' => '', 'error' => $this->label() . ' 不支持读取文件'];
    }

    /**
     * 连通性自检，后台「测试连接」按钮用。
     *
     * @return array{ok:bool,message:string,detail:array<string,mixed>}
     */
    public function test(): array
    {
        $caps = $this->capabilities();

        return [
            'ok'      => $caps !== [],
            'message' => $this->label() . ' 适配器已就绪，支持：' . implode('、', $caps),
            'detail'  => ['capabilities' => $caps],
        ];
    }

    // ---------------------------------------------------------------- 公共工具

    /**
     * @return string[]
     */
    public function transcript(): array
    {
        return array_slice($this->transcript, -20);
    }

    /**
     * 记一条请求轨迹，失败时给管理员看。
     *
     * 这里**必须**抹掉密钥：transcript 会被写进 `events` 表（后台「操作日志」页面）
     * 和 `storage/logs`，而面板密钥就在 URL 的查询串或路径里。
     */
    protected function note(string $line): void
    {
        $this->transcript[] = function_exists('mask_secret_url') ? mask_secret_url($line) : $line;
    }

    /**
     * 取配置项，带默认值。
     *
     * @param mixed $default
     * @return mixed
     */
    protected function cfg(string $key, $default = '')
    {
        $value = $this->config[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * 取接口路径，支持在配置里覆盖。
     *
     * 面板各版本的接口路径偶有差异（特别是 MCSManager 2.x / 3.x 与宝塔插件）。
     * 与其让你改代码，不如把路径做成可配置：后台「面板 API → 自定义接口参数」里填一行即可。
     * 例如 endpoints 配置里写 {"command":"/api/instance/command","log":"/api/files/download"}。
     */
    protected function endpoint(string $name, string $default): string
    {
        $overrides = $this->config['endpoints'] ?? null;
        if (is_string($overrides) && $overrides !== '') {
            $decoded = json_decode($overrides, true);
            $overrides = is_array($decoded) ? $decoded : null;
        }

        if (is_array($overrides) && isset($overrides[$name]) && is_string($overrides[$name]) && $overrides[$name] !== '') {
            return (string) $overrides[$name];
        }

        return $default;
    }

    /**
     * 统一失败返回。
     *
     * @return array{ok:bool,output:string,error:string,ms:float}
     */
    protected function fail(string $error, float $ms = 0.0): array
    {
        return ['ok' => false, 'output' => '', 'error' => $error, 'ms' => $ms];
    }

    /**
     * 统一成功返回。
     *
     * @return array{ok:bool,output:string,error:string,ms:float}
     */
    protected function done(string $output, float $ms = 0.0): array
    {
        return ['ok' => true, 'output' => $output, 'error' => '', 'ms' => $ms];
    }

    /**
     * 发一个 HTTP 请求（curl 优先，回退到 stream）。
     *
     * @param array<string,string> $headers
     * @param array<string,mixed>|string|null $body
     * @return array{ok:bool,status:int,body:string,error:string,ms:float}
     */
    protected function http(string $method, string $url, array $headers = [], $body = null, ?string $userAgent = null): array
    {
        $start = microtime(true);
        $method = strtoupper($method);
        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }
        $headerLines[] = 'User-Agent: ' . ($userAgent ?? 'mcfix/' . MCFIX_VERSION);
        $headerLines[] = 'Accept: application/json, text/plain, */*';

        $payload = null;
        if ($body !== null) {
            $payload = is_string($body) ? $body : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($body)) {
                $headerLines[] = 'Content-Type: application/json';
            }
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headerLines,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => min(8, $this->timeout),
                CURLOPT_SSL_VERIFYPEER => !$this->insecure,
                CURLOPT_SSL_VERIFYHOST => $this->insecure ? 0 : 2,
                // 不跟随跳转：面板 API 不该 302，跟着跳会把带密钥的请求发到别的主机上去
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
            $raw = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $ms = round((microtime(true) - $start) * 1000, 1);

            if ($raw === false) {
                $this->note($method . ' ' . $url . ' → curl 错误：' . $error);

                return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $error !== '' ? $error : '请求失败', 'ms' => $ms];
            }

            $this->note($method . ' ' . $url . ' → HTTP ' . $status . '（' . strlen((string) $raw) . ' 字节）');

            return ['ok' => $status >= 200 && $status < 400, 'status' => $status, 'body' => (string) $raw, 'error' => '', 'ms' => $ms];
        }

        // 回退：stream
        $context = stream_context_create([
            'http' => [
                'method'          => $method,
                'header'          => implode("\r\n", $headerLines) . "\r\n",
                'content'         => $payload ?? '',
                'timeout'         => $this->timeout,
                'ignore_errors'   => true,
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer'      => !$this->insecure,
                'verify_peer_name' => !$this->insecure,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        $ms = round((microtime(true) - $start) * 1000, 1);

        if ($raw === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => '请求失败（无 curl 扩展，stream 也失败）', 'ms' => $ms];
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', (string) $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        $this->note($method . ' ' . $url . ' → HTTP ' . $status);

        return ['ok' => $status >= 200 && $status < 400, 'status' => $status, 'body' => $raw, 'error' => '', 'ms' => $ms];
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>|null $body
     * @return array{ok:bool,data:array<string,mixed>,error:string,status:int}
     */
    protected function json(string $method, string $url, array $headers = [], ?array $body = null)
    {
        $response = $this->http($method, $url, $headers, $body);
        if (!$response['ok']) {
            $hint = $response['status'] > 0 ? '（HTTP ' . $response['status'] . '）' : '';
            /*
             * 响应体先脱敏再拼进错误串（V7）。
             *
             * 面板在报错时把请求凭据/令牌原样回显是常见做法（自建面板尤其如此），
             * 而这段错误串会一路流到工单时间线 —— 持有反馈链接的玩家都能看到。
             * record_event() 那边现在也会兜一道，但错误信息会在多个地方被拼装、
             * 记录、展示，在**源头**脱敏更稳妥。
             */
            $snippet = redact_secrets(mb_substr(trim($response['body']), 0, 200));
            $error = $response['error'] !== '' ? $response['error'] : ('接口返回 ' . $response['status'] . $hint);

            return [
                'ok'     => false,
                'data'   => [],
                'error'  => $error . ($snippet !== '' ? '：' . $snippet : ''),
                'status' => (int) $response['status'],
            ];
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            return [
                'ok'     => false,
                'data'   => [],
                'error'  => '返回的不是 JSON：' . mb_substr(trim($response['body']), 0, 200),
                'status' => (int) $response['status'],
            ];
        }

        return ['ok' => true, 'data' => $decoded, 'error' => '', 'status' => (int) $response['status']];
    }

    /**
     * 从面板配置里取服务端目录（供 readLog / listDir 拼路径）。
     */
    protected function mcDir(): string
    {
        return rtrim((string) ($this->cfg('mc_dir', $this->server['mc_dir'] ?? '')), '/');
    }

    /**
     * 日志相对路径（面板内通常就是 logs/latest.log）。
     */
    protected function logPath(): string
    {
        $path = (string) ($this->server['log_path'] ?? 'logs/latest.log');

        return ltrim($path, '/');
    }

    /**
     * 拼一个面板内的绝对路径。
     */
    protected function remotePath(string $relative): string
    {
        $relative = ltrim($relative, '/');
        $dir = $this->mcDir();

        return $dir !== '' ? $dir . '/' . $relative : $relative;
    }

    /**
     * 由 MC 指令回显推断"这条指令到底成功没有"。
     * 面板控制台一般不给退出码，只能看回显。
     */
    public function looksFailed(string $output): bool
    {
        if (trim($output) === '') {
            return false;
        }
        $lower = mb_strtolower($output);
        foreach ([
            'unknown command', 'unknown or incomplete', 'incorrect argument',
            'no player was found', 'that player does not exist', 'player not found',
            'you do not have permission', 'permission denied', 'error:', 'failed',
            'not whitelisted', 'does not exist',
        ] as $needle) {
            if (mb_strpos($lower, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
