<?php

declare(strict_types=1);

namespace MCFix\Channel;

use MCFix\Config;

/**
 * 通知渠道基类。
 *
 * 每个渠道要做的事很一致：拿一段消息 → 按平台要求的格式包装 → 发出去 → 告诉调用者成没成。
 * 差异只在"包装格式"和"怎么发"，所以基类把通用部分（校验、HTTP、截断）都做好。
 */
abstract class ChannelAdapter
{
    /** @var array<string,mixed> */
    protected $config = [];

    protected $timeout = 10;

    /** 最近一次请求的调试信息 */
    protected $transcript = [];

    /**
     * @param array<string,mixed> $config 单个渠道的配置段
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->timeout = max(3, (int) ($config['timeout'] ?? 10));
    }

    /** 渠道类型标识（dingtalk / wecom / ...） */
    abstract public function key(): string;

    /** 界面显示名 */
    abstract public function label(): string;

    /** 这个渠道需要哪些配置字段（用于校验与提示） */
    abstract public function requiredFields(): array;

    /**
     * 发送消息。
     *
     * @param array<string,mixed> $message Notifier::buildMessage 的结果
     * @return array{ok:bool,error:string,detail:array<string,mixed>}
     */
    abstract public function send(array $message): array;

    /**
     * @return array<string,mixed>
     */
    public function transcript(): array
    {
        return array_slice($this->transcript, -10);
    }

    /**
     * 缺哪些必填字段。
     *
     * @return string[]
     */
    public function missingFields(): array
    {
        $missing = [];
        foreach ($this->requiredFields() as $field => $label) {
            $value = $this->config[$field] ?? null;
            if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                $missing[] = is_string($label) ? $label : (string) $field;
            }
        }

        return $missing;
    }

    public function isConfigured(): bool
    {
        return $this->missingFields() === [];
    }

    // ---------------------------------------------------------------- 通用工具

    /**
     * @param mixed $default
     * @return mixed
     */
    protected function cfg(string $key, $default = '')
    {
        $value = $this->config[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * 消息正文：优先 markdown，没有就用纯文本。
     *
     * @param array<string,mixed> $message
     */
    protected function body(array $message): string
    {
        $body = (string) ($message['markdown'] ?? '');
        if (trim($body) === '') {
            $body = (string) ($message['text'] ?? '');
        }

        return $body;
    }

    /**
     * 标题 + 正文 + 链接，拼成一段完整文本（很多渠道只接受一段文字）。
     *
     * @param array<string,mixed> $message
     */
    protected function fullText(array $message, bool $withTitle = true): string
    {
        $parts = [];
        if ($withTitle && !empty($message['title'])) {
            $parts[] = '【' . (string) $message['title'] . '】';
        }
        $parts[] = $this->body($message);
        if (!empty($message['link'])) {
            $parts[] = "\n查看详情：" . (string) $message['link'];
        }

        return trim(implode("\n", array_filter($parts, static function ($p): bool {
            return trim((string) $p) !== '';
        })));
    }

    /**
     * 按平台字数上限截断（避免超长被拒）。
     */
    protected function clamp(string $text, int $max = 3500): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 20) . "\n…（内容过长已截断）";
    }

    /**
     * 统一的成功/失败返回。
     *
     * @param array<string,mixed> $detail
     * @return array{ok:bool,error:string,detail:array<string,mixed>}
     */
    protected function ok(array $detail = []): array
    {
        return ['ok' => true, 'error' => '', 'detail' => $detail];
    }

    /**
     * @param array<string,mixed> $detail
     * @return array{ok:bool,error:string,detail:array<string,mixed>}
     */
    protected function err(string $error, array $detail = []): array
    {
        return ['ok' => false, 'error' => $error, 'detail' => $detail + ['transcript' => $this->transcript()]];
    }

    protected function note(string $line): void
    {
        $this->transcript[] = $line;
    }

    /**
     * 发 HTTP 请求（curl 优先，回退 stream）。
     *
     * @param array<string,string> $headers
     * @param array<string,mixed>|string|null $body
     * @return array{ok:bool,status:int,body:string,error:string}
     */
    protected function http(string $method, string $url, array $headers = [], $body = null): array
    {
        $method = strtoupper($method);
        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }
        $headerLines[] = 'User-Agent: mcfix-notify/' . (defined('MCFIX_VERSION') ? MCFIX_VERSION : '1');

        $payload = null;
        if ($body !== null) {
            if (is_string($body)) {
                $payload = $body;
            } else {
                $payload = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $headerLines[] = 'Content-Type: application/json; charset=utf-8';
            }
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headerLines,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => min(6, $this->timeout),
                // 通知内容里有玩家名、工单正文和日志片段，默认必须校验证书
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
            $raw = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($raw === false) {
                $this->note($method . ' ' . $this->maskUrl($url) . ' → ' . $error);

                return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $error];
            }

            $this->note($method . ' ' . $this->maskUrl($url) . ' → HTTP ' . $status . ' ' . mb_substr((string) $raw, 0, 160));

            return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string) $raw, 'error' => ''];
        }

        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headerLines) . "\r\n",
                'content'       => $payload ?? '',
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
                // PHP 的 http wrapper 默认 follow_location=1（会跟随 302），
                // 而 curl 那条分支的默认是不跟随 —— 两条路行为不一致。
                // 跟随的后果是：请求连同 Authorization 头一起被重发到跳转目标，
                // 而通知渠道的 URL 是管理员填的，跳转到哪里不可控。
                // 这里显式关掉，和 PanelAdapter / AiAdvisor 保持一致。
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => '请求失败（无 curl 扩展）'];
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', (string) $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        $this->note($method . ' ' . $this->maskUrl($url) . ' → HTTP ' . $status);

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $raw, 'error' => ''];
    }

    /**
     * 打日志时把密钥抹掉。
     *
     * 实现统一放在 helpers.php 的 mask_secret_url() 里，
     * 面板适配器（MCSManager 的 ?apikey=、宝塔的 request_token）用的是同一个函数，
     * 免得两边各写一份、其中一边漏掉某种形态。
     */
    protected function maskUrl(string $url): string
    {
        return function_exists('mask_secret_url') ? mask_secret_url($url) : $url;
    }

    /**
     * 从 JSON 响应里判断平台是否真的接受了消息。
     * 国内三家（钉钉/企微/飞书）都是 HTTP 200 + body 里带 errcode。
     *
     * @return array{ok:bool,error:string}
     */
    protected function judgeJson(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['ok' => true, 'error' => ''];
        }

        // 钉钉
        if (isset($decoded['errcode']) && (int) $decoded['errcode'] !== 0) {
            return ['ok' => false, 'error' => '平台返回 errcode=' . (int) $decoded['errcode'] . '：' . (string) ($decoded['errmsg'] ?? '')];
        }
        // 企业微信
        if (isset($decoded['errcode']) && (int) $decoded['errcode'] !== 0) {
            return ['ok' => false, 'error' => '平台返回 errcode=' . (int) $decoded['errcode'] . '：' . (string) ($decoded['errmsg'] ?? '')];
        }
        // 飞书
        if (isset($decoded['code']) && (int) $decoded['code'] !== 0) {
            return ['ok' => false, 'error' => '平台返回 code=' . (int) $decoded['code'] . '：' . (string) ($decoded['msg'] ?? '')];
        }
        // Telegram
        if (isset($decoded['ok']) && $decoded['ok'] === false) {
            return ['ok' => false, 'error' => 'Telegram 返回：' . (string) ($decoded['description'] ?? 'unknown')];
        }
        // Server 酱等
        if (isset($decoded['code']) && (int) $decoded['code'] !== 0) {
            return ['ok' => false, 'error' => '接口返回 code=' . (int) $decoded['code'] . '：' . (string) ($decoded['message'] ?? $decoded['msg'] ?? '')];
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * 邮箱地址 / URL 之类的轻量校验。
     */
    protected function looksLikeEmail(string $value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
    }
}
