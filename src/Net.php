<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 网络工具：带超时的 TCP 探测、读取一行、以及端口归属判断。
 */
final class Net
{
    /**
     * 尝试建立 TCP 连接，返回 ['ok'=>bool,'ms'=>float,'error'=>string]。
     *
     * @return array{ok:bool,ms:float,error:string}
     */
    public static function tcpPing(string $host, int $port, float $timeout = 3.0): array
    {
        $start = microtime(true);
        $errno = 0;
        $errstr = '';

        $target = self::normalizeHost($host);
        if ($target === '' || $port < 1 || $port > 65535) {
            return ['ok' => false, 'ms' => 0.0, 'error' => '地址或端口非法'];
        }

        $fp = @stream_socket_client(
            'tcp://' . $target . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );

        $ms = round((microtime(true) - $start) * 1000, 1);

        if ($fp === false) {
            return ['ok' => false, 'ms' => $ms, 'error' => $errstr !== '' ? $errstr : ('连接失败 (errno ' . $errno . ')')];
        }

        @fclose($fp);

        return ['ok' => true, 'ms' => $ms, 'error' => ''];
    }

    /**
     * Minecraft Java 版 Server List Ping（协议 1.7+ / handshake 0x00）。
     * 返回在线人数、最大人数、版本、MOTD —— 这是"自动验证服务器是否真的挂了"最可靠的一步，
     * 因为它和玩家客户端走的是同一条协议。
     *
     * @return array{ok:bool,latency_ms:float,error:string,players:int,max_players:int,version:string,motd:string,sample:array<int,string>}
     */
    public static function mcStatus(string $host, int $port, float $timeout = 3.0): array
    {
        $fail = static function (string $error, float $ms = 0.0) : array {
            return [
                'ok' => false, 'latency_ms' => $ms, 'error' => $error,
                'players' => 0, 'max_players' => 0, 'version' => '', 'motd' => '', 'sample' => [],
            ];
        };

        $target = self::normalizeHost($host);
        if ($target === '') {
            return $fail('地址非法');
        }

        $start = microtime(true);
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client('tcp://' . $target . ':' . $port, $errno, $errstr, $timeout);
        if ($fp === false) {
            return $fail($errstr !== '' ? $errstr : '无法建立连接', round((microtime(true) - $start) * 1000, 1));
        }

        stream_set_timeout($fp, (int) $timeout, (int) (($timeout - floor($timeout)) * 1000000));

        // handshake: protocol=-1(??) 用 0 表示"只问状态"，next state = 1
        $handshake = self::packVarInt(0)
            . self::packVarInt(0)              // protocol version
            . self::packString($host)
            . pack('n', $port)
            . self::packVarInt(1);

        $packet = self::packVarInt(strlen($handshake)) . $handshake;
        if (@fwrite($fp, $packet) === false) {
            @fclose($fp);

            return $fail('握手包发送失败', round((microtime(true) - $start) * 1000, 1));
        }

        // status request: 空包
        @fwrite($fp, self::packVarInt(1) . self::packVarInt(0));

        $length = self::readVarInt($fp);
        if ($length === null || $length <= 0 || $length > 1048576) {
            @fclose($fp);

            return $fail('服务端未返回状态数据（可能不是 Java 版或仍在启动）', round((microtime(true) - $start) * 1000, 1));
        }

        $payload = '';
        $deadline = microtime(true) + $timeout;
        while (strlen($payload) < $length && microtime(true) < $deadline) {
            $chunk = @fread($fp, $length - strlen($payload));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $payload .= $chunk;
        }
        @fclose($fp);
        $latency = round((microtime(true) - $start) * 1000, 1);

        if (strlen($payload) < $length) {
            return $fail('状态数据读取不完整', $latency);
        }

        $offset = 0;
        $packetId = self::unpackVarInt($payload, $offset);
        if ($packetId !== 0) {
            return $fail('响应包类型异常（' . var_export($packetId, true) . '）', $latency);
        }

        $jsonLength = self::unpackVarInt($payload, $offset);
        if ($jsonLength === null) {
            return $fail('响应解析失败', $latency);
        }

        $json = substr($payload, $offset, $jsonLength);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $fail('MOTD 不是合法 JSON', $latency);
        }

        $players = is_array($data['players'] ?? null) ? $data['players'] : [];
        $version = is_array($data['version'] ?? null) ? (string) ($data['version']['name'] ?? '') : '';
        $motd = '';
        if (isset($data['description'])) {
            $motd = is_string($data['description'])
                ? $data['description']
                : self::flattenChat($data['description']);
        }

        $sample = [];
        foreach ((array) ($players['sample'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $sample[] = (string) $entry['name'];
            }
        }

        return [
            'ok'          => true,
            'latency_ms'  => $latency,
            'error'       => '',
            'players'     => (int) ($players['online'] ?? 0),
            'max_players' => (int) ($players['max'] ?? 0),
            'version'     => $version,
            'motd'        => mb_substr(preg_replace('/\s+/', ' ', $motd) ?? '', 0, 200),
            'sample'      => array_slice($sample, 0, 60),
        ];
    }

    /**
     * 探测来源 IP 是否属于本机/内网（用于识别"同机房"场景）。
     */
    public static function isPrivateAddress(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * 把主机名规整成可用于 stream_socket_client 的形式。
     * 允许域名、IPv4、IPv6（IPv6 需要加方括号）。
     */
    public static function normalizeHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }

        if (preg_match('/^\[(.+)\]$/', $host, $m)) {
            $host = $m[1];
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return '[' . $host . ']';
        }

        if (!preg_match('/^[A-Za-z0-9\.\-_]+$/', $host)) {
            return '';
        }

        return $host;
    }

    /**
     * Minecraft 聊天组件转纯文本。
     *
     * @param mixed $node
     */
    public static function flattenChat($node): string
    {
        if (is_string($node)) {
            return $node;
        }
        if (!is_array($node)) {
            return '';
        }

        $text = isset($node['text']) ? (string) $node['text'] : '';
        foreach ((array) ($node['extra'] ?? []) as $child) {
            $text .= self::flattenChat($child);
        }

        return $text;
    }

    private static function packVarInt(int $value): string
    {
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
    }

    private static function packString(string $value): string
    {
        return self::packVarInt(strlen($value)) . $value;
    }

    /**
     * @param resource $stream
     */
    private static function readVarInt($stream): ?int
    {
        $result = 0;
        $shift = 0;
        for ($i = 0; $i < 5; $i++) {
            $byte = @fread($stream, 1);
            if ($byte === false || $byte === '') {
                return null;
            }
            $value = ord($byte);
            $result |= ($value & 0x7F) << $shift;
            if (($value & 0x80) === 0) {
                return $result;
            }
            $shift += 7;
        }

        return null;
    }

    private static function unpackVarInt(string $buffer, int &$offset): ?int
    {
        $result = 0;
        $shift = 0;
        for ($i = 0; $i < 5; $i++) {
            if (!isset($buffer[$offset])) {
                return null;
            }
            $value = ord($buffer[$offset++]);
            $result |= ($value & 0x7F) << $shift;
            if (($value & 0x80) === 0) {
                return $result;
            }
            $shift += 7;
        }

        return null;
    }
}
