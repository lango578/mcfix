<?php

declare(strict_types=1);

namespace MCFix\Channel;

/**
 * 渠道注册表。
 */
final class ChannelRegistry
{
    /**
     * @return array<string,class-string<ChannelAdapter>>
     */
    public static function types(): array
    {
        return [
            'dingtalk'   => DingTalkChannel::class,
            'wecom'      => WeComChannel::class,
            'feishu'     => FeishuChannel::class,
            'mail'       => MailChannel::class,
            'bark'       => BarkChannel::class,
            'ntfy'       => NtfyChannel::class,
            'serverchan' => ServerChanChannel::class,
            'telegram'   => TelegramChannel::class,
            'discord'    => DiscordChannel::class,
            'webhook'    => WebhookChannel::class,
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::types() as $type => $class) {
            /** @var ChannelAdapter $instance */
            $instance = new $class([]);
            $labels[$type] = $instance->label();
        }

        return $labels;
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function make(string $type, array $config): ?ChannelAdapter
    {
        $types = self::types();
        if (!isset($types[$type])) {
            return null;
        }

        $class = $types[$type];

        return new $class($config);
    }

    /**
     * @param array<string,mixed> $config
     * @return string[]
     */
    public static function missingFields(string $type, array $config): array
    {
        $adapter = self::make($type, $config);

        return $adapter !== null ? $adapter->missingFields() : ['未知渠道类型：' . $type];
    }

    /**
     * 每个渠道需要哪些字段（后台表单用）。
     *
     * @return array<string,array<string,string>>
     */
    public static function fieldHints(): array
    {
        return [
            'dingtalk' => [
                'webhook'   => 'Webhook 地址（https://oapi.dingtalk.com/robot/send?access_token=…）',
                'secret'    => '加签密钥（机器人安全设置选"加签"时填，强烈建议）',
                'at_mobile' => '@ 谁的手机号，逗号分隔（可选）',
            ],
            'wecom' => [
                'webhook'       => 'Webhook 地址（https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=…）',
                'mentioned'     => '@ 谁的手机号，逗号分隔（可选）',
                'mentioned_all' => '是否 @ 所有人（填 1 开启）',
            ],
            'feishu' => [
                'webhook' => 'Webhook 地址（https://open.feishu.cn/open-apis/bot/v2/hook/…）',
                'secret'  => '签名校验密钥（开启"签名校验"时填）',
            ],
            'mail' => [
                'to'        => '收件人，多个用逗号分隔',
                'from'      => '发件人地址（SMTP 模式下会被自动对齐到登录账号）',
                'from_name' => '发件人显示名',
            ],
            'bark' => [
                'server_url' => '服务地址（默认 https://api.day.app，自建填自己的）',
                'device_key' => 'Device Key（App 首页那串）',
                'group'      => '分组名（可选）',
                'sound'      => '提示音（可选，如 alarm）',
            ],
            'ntfy' => [
                'server_url' => '服务地址（默认 https://ntfy.sh）',
                'topic'      => 'Topic 主题名（相当于密码，取个猜不到的）',
                'token'      => '访问令牌（自建服务端开启鉴权时填）',
                'priority'   => '默认优先级 1-5（可选）',
            ],
            'serverchan' => [
                'send_key' => 'SendKey（SCT… 或 sctp… 开头）',
            ],
            'telegram' => [
                'bot_token' => 'Bot Token（找 @BotFather 要）',
                'chat_id'   => 'Chat ID（个人/群/频道，群一般是负数）',
            ],
            'discord' => [
                'webhook'  => 'Webhook 地址（频道设置 → 整合 → Webhook）',
                'username' => '显示名（可选）',
                'mention'  => '需要 @ 的角色 ID，如 <@&123456>（仅失败类消息会 @）',
            ],
            'webhook' => [
                'url'      => '目标地址',
                'method'   => 'POST / PUT / GET',
                'format'   => 'json / form / text',
                'headers'  => '自定义请求头（JSON 或每行 Key: Value）',
                'template' => '自定义请求体模板，占位符见说明',
            ],
        ];
    }
}
