<?php

declare(strict_types=1);

namespace MCFix;

use MCFix\Panel\PanelAdapter;

/**
 * 面板控制台后端：把面板的 `sendCommand()` 适配成指令通道。
 *
 * 只在 `PanelAdapter::commandEcho()` 为 true 时才会被 CommandChannel 创建 ——
 * 也就是说，这个类假定"面板返回的 output 就是控制台回显"。
 *
 * @internal 只给 CommandChannel 用，别在别处直接实例化。
 */
final class PanelChannel
{
    private PanelAdapter $panel;

    public function __construct(PanelAdapter $panel)
    {
        $this->panel = $panel;
    }

    /**
     * @return array{ok:bool,output:string,error:string,ms:float}
     */
    public function command(string $command): array
    {
        $result = $this->panel->sendCommand($command);
        $output = trim((string) ($result['output'] ?? ''));

        /*
         * 这里刻意把"回显为空"当成失败（ok=false），而不是原样透传面板的 ok。
         *
         * 原因：这几项检查全靠解析回显得出结论。若回显为空却报 ok=true，
         * 下游会拿着空串去跑正则 —— 结果是"匹配不到任何东西"，然后可能得出
         * "服务端未启用白名单""玩家不在线"这类**看起来有结论、其实是猜**的判断。
         * 宁可明确报"读不到回显"，让上层说"这项验证跳过"。
         */
        return [
            'ok'     => !empty($result['ok']) && $output !== '',
            'output' => $output,
            'error'  => $output !== ''
                ? ''
                : ((string) ($result['error'] ?? '') ?: '面板控制台没有返回可解析的回显'),
            'ms'     => (float) ($result['ms'] ?? 0.0),
        ];
    }

    public function close(): void
    {
        // 面板走 HTTP，没有需要显式关闭的长连接。
    }

    public function label(): string
    {
        return $this->panel->label() . '控制台';
    }
}
