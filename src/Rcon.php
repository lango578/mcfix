<?php

declare(strict_types=1);

namespace MCFix;

/**
 * Minecraft RCON 客户端（Source RCON 协议，Minecraft 服务端原生支持）。
 *
 * 用途：把管理动作（加白名单、解封、踢人、save-all、reload）以游戏官方指令下发，
 * 不需要 SSH、不需要装插件、不需要重启服务端。
 *
 * 打开方式：server.properties 里
 *   enable-rcon=true
 *   rcon.port=25575
 *   rcon.password=一段足够长的随机密码
 */
final class Rcon
{
    private const TYPE_AUTH = 3;

    private const TYPE_AUTH_RESPONSE = 2;

    private const TYPE_COMMAND = 2;

    private const TYPE_RESPONSE = 0;

    /** @var resource|null */
    private $socket = null;

    private $host = '';

    private $port = 25575;

    private $password = '';

    private $timeout = 3.0;

    private $lastRequestId = 0;

    private $authenticated = false;

    /** @var string[] */
    private $transcript = [];

    /**
     * @param array<string,mixed> $config 服务器配置里的 rcon 段
     */
    public function __construct(array $config)
    {
        $this->host = (string) ($config['host'] ?? '');
        $this->port = (int) ($config['port'] ?? 25575);
        $this->password = (string) ($config['password'] ?? '');
        $this->timeout = (float) ($config['timeout'] ?? 3);
        if ($this->timeout <= 0) {
            $this->timeout = 3.0;
        }
    }

    /**
     * @param array<string,mixed> $server
     */
    public static function fromServer(array $server): self
    {
        $config = is_array($server['rcon'] ?? null) ? $server['rcon'] : [];
        if (empty($config['host'])) {
            $config['host'] = $server['host'] ?? '127.0.0.1';
        }

        return new self($config);
    }

    /**
     * @param array<string,mixed> $server
     */
    public static function enabled(array $server): bool
    {
        $rcon = $server['rcon'] ?? null;
        if (!is_array($rcon) || empty($rcon['enabled'])) {
            return false;
        }

        return (string) ($rcon['password'] ?? '') !== '';
    }

    /**
     * @return array{ok:bool,error:string,ms:float}
     */
    public function connect(): array
    {
        if ($this->authenticated) {
            return ['ok' => true, 'error' => '', 'ms' => 0.0];
        }

        if ($this->password === '') {
            return ['ok' => false, 'error' => '未配置 RCON 密码', 'ms' => 0.0];
        }

        $target = Net::normalizeHost($this->host);
        if ($target === '') {
            return ['ok' => false, 'error' => 'RCON 地址非法', 'ms' => 0.0];
        }

        $start = microtime(true);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . $target . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout
        );

        if ($socket === false) {
            $this->note('connect FAILED: ' . ($errstr !== '' ? $errstr : 'errno ' . $errno));

            return [
                'ok'    => false,
                'error' => '无法连接 RCON ' . $this->host . ':' . $this->port . '（' . ($errstr !== '' ? $errstr : 'errno ' . $errno) . '）',
                'ms'    => round((microtime(true) - $start) * 1000, 1),
            ];
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, (int) $this->timeout, (int) (($this->timeout - floor($this->timeout)) * 1000000));

        $this->lastRequestId = random_int(1, 1000000);
        $payload = pack('VV', $this->lastRequestId, self::TYPE_AUTH) . $this->password . "\x00\x00";

        if (@fwrite($this->socket, pack('V', strlen($payload)) . $payload) === false) {
            $this->close();
            $this->note('auth write FAILED');

            return ['ok' => false, 'error' => 'RCON 认证包发送失败', 'ms' => round((microtime(true) - $start) * 1000, 1)];
        }

        $response = $this->readPacket();
        $ms = round((microtime(true) - $start) * 1000, 1);

        if ($response === null) {
            $this->close();
            $this->note('auth no response');

            return ['ok' => false, 'error' => 'RCON 无响应（可能是密码错误或 rcon 未开启）', 'ms' => $ms];
        }

        if ($response['id'] === -1 || $response['id'] !== $this->lastRequestId) {
            $this->close();
            $this->note('auth rejected id=' . $response['id']);

            return ['ok' => false, 'error' => 'RCON 密码错误', 'ms' => $ms];
        }

        $this->authenticated = true;
        $this->note('auth ok');

        return ['ok' => true, 'error' => '', 'ms' => $ms];
    }

    /**
     * 执行一条指令，返回服务端回显。
     *
     * 支持"服务端刚重启"的场景：第一次发送失败时会自动重连一次再试。
     *
     * @return array{ok:bool,output:string,error:string,ms:float}
     */
    public function command(string $command): array
    {
        $result = $this->attempt($command);
        if ($result['ok']) {
            return $result;
        }

        // 只在"连接层失败"时重连重试；密码错误重试也没用
        if (strpos($result['error'], '密码') !== false || strpos($result['error'], '未配置') !== false) {
            return $result;
        }

        $this->close();
        $this->note('检测到连接失效，尝试重连后重发');
        usleep(300000);

        return $this->attempt($command);
    }

    /**
     * 单次发送（不含重连）。
     *
     * @return array{ok:bool,output:string,error:string,ms:float}
     */
    private function attempt(string $command): array
    {
        if (!$this->authenticated) {
            $auth = $this->connect();
            if (!$auth['ok']) {
                return ['ok' => false, 'output' => '', 'error' => $auth['error'], 'ms' => $auth['ms']];
            }
        }

        $start = microtime(true);
        $this->lastRequestId++;
        $payload = pack('VV', $this->lastRequestId, self::TYPE_COMMAND) . $command . "\x00\x00";

        if (!is_resource($this->socket) || @fwrite($this->socket, pack('V', strlen($payload)) . $payload) === false) {
            $this->authenticated = false;

            return ['ok' => false, 'output' => '', 'error' => 'RCON 连接已断开，指令未发送', 'ms' => round((microtime(true) - $start) * 1000, 1)];
        }

        $output = '';
        $deadline = microtime(true) + $this->timeout;
        while (microtime(true) < $deadline) {
            $packet = $this->readPacket();
            if ($packet === null) {
                break;
            }
            if ($packet['type'] === self::TYPE_RESPONSE && $packet['id'] === $this->lastRequestId) {
                $output .= $packet['body'];
                // 多数服务端一次回包即为完整输出；这里再等一小会儿看有没有续包
                $output .= $this->drainFollowUps();
                break;
            }
        }

        $trimmed = trim($output);
        $this->note('cmd "' . $command . '" -> ' . ($trimmed === '' ? '(空回显)' : mb_substr($trimmed, 0, 200)));

        return [
            'ok'     => true,
            'output' => $trimmed,
            'error'  => '',
            'ms'     => round((microtime(true) - $start) * 1000, 1),
        ];
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->authenticated = false;
    }

    /**
     * @return string[]
     */
    public function transcript(): array
    {
        return $this->transcript;
    }

    private function drainFollowUps(): string
    {
        $extra = '';
        $guard = 0;
        while ($guard++ < 4) {
            $read = [$this->socket];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 120000);
            if ($ready === false || $ready === 0) {
                break;
            }
            $packet = $this->readPacket();
            if ($packet === null) {
                break;
            }
            if ($packet['type'] === self::TYPE_RESPONSE && $packet['id'] === $this->lastRequestId) {
                $extra .= $packet['body'];
            }
        }

        return $extra;
    }

    /**
     * @return array{id:int,type:int,body:string}|null
     */
    private function readPacket(): ?array
    {
        if (!is_resource($this->socket)) {
            return null;
        }

        $header = $this->readBytes(4);
        if ($header === null) {
            return null;
        }

        $length = (int) unpack('V', $header)[1];
        if ($length < 10 || $length > 4 * 1024 * 1024) {
            return null;
        }

        $body = $this->readBytes($length);
        if ($body === null || strlen($body) < 10) {
            return null;
        }

        $id = (int) unpack('V', substr($body, 0, 4))[1];
        $type = (int) unpack('V', substr($body, 4, 4))[1];

        return [
            'id'   => $id,
            'type' => $type,
            'body' => rtrim(substr($body, 8), "\x00"),
        ];
    }

    private function readBytes(int $count): ?string
    {
        $buffer = '';
        $deadline = microtime(true) + $this->timeout;
        while (strlen($buffer) < $count && microtime(true) < $deadline) {
            $chunk = @fread($this->socket, $count - strlen($buffer));
            if ($chunk === false) {
                return null;
            }
            if ($chunk === '') {
                $info = @stream_get_meta_data($this->socket);
                if (is_array($info) && !empty($info['timed_out'])) {
                    return null;
                }
                usleep(1000);
                continue;
            }
            $buffer .= $chunk;
        }

        return strlen($buffer) === $count ? $buffer : null;
    }

    private function note(string $line): void
    {
        if (count($this->transcript) < 60) {
            $this->transcript[] = $line;
        }
    }
}
