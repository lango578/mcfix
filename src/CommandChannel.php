<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 指令通道：把"发一条游戏指令、拿回显"这件事从具体实现里抽出来。
 *
 * 为什么需要它：
 *
 * 诊断里有五项检查（tps / list / whitelist list / banlist）要靠**解析指令回显**
 * 才能得出结论。原来它们写死了 `Rcon::fromServer($server)`，于是没开 RCON 就只能
 * 跳过 —— 哪怕这台服配的面板本来就能下发指令。`Executor` 里那句
 * 「（可考虑用面板控制台通道）」就是在这个背景下写的，但当时面板通道只用在
 * **修复**上，检查根本没接。
 *
 * 两个后端返回结构一致（`ok` / `output` / `error` / `ms`），所以这里只做一层薄适配，
 * 不去动那五项检查的解析逻辑：
 *
 *   - RconChannel   → 真 RCON，回显是服务端的原样输出
 *   - PanelChannel  → 面板控制台，**只在该面板能返回可解析回显时**才允许使用
 *
 * 特别注意：面板通道不是 RCON 的等价替代。翼龙 / Multicraft / MCSManager 的
 * 指令接口只管投递、不返回控制台输出（拿回来的是"已投递到控制台"这类提示）。
 * 拿这种东西去跑 `preg_match('/(\d+\.\d+)/')` 抠 TPS，抠出来的可能是提示文字里
 * 的数字。所以 `fromPanelOnly()` 会检查 `commandEcho()`，不满足直接拒绝。
 */
final class CommandChannel
{
    /** @var RconChannel|PanelChannel */
    private $backend;

    /**
     * @param RconChannel|PanelChannel $backend
     */
    private function __construct($backend)
    {
        $this->backend = $backend;
    }

    /**
     * 优先用 RCON；没开 RCON 时，只有在面板确实能返回可解析回显的情况下
     * 才退回面板控制台。两者都不可用则返回 null，由调用方决定怎么说"跳过"。
     *
     * @param array<string,mixed> $server
     */
    public static function forServer(array $server): ?self
    {
        if (Rcon::enabled($server)) {
            return new self(new RconChannel(Rcon::fromServer($server)));
        }

        return self::fromPanelOnly($server);
    }

    /**
     * 只用面板控制台开一条通道，不可用时返回 null。
     *
     * @param array<string,mixed> $server
     */
    public static function fromPanelOnly(array $server): ?self
    {
        $panel = PanelRegistry::adapter($server);
        if ($panel === null) {
            return null;
        }

        // 关键闸门：不回显的面板不能拿来做"要解析输出"的检查。
        if (!$panel->commandEcho()) {
            return null;
        }

        return new self(new PanelChannel($panel));
    }

    /**
     * 发一条指令。
     *
     * @return array{ok:bool,output:string,error:string,ms:float}
     */
    public function command(string $command): array
    {
        return $this->backend->command($command);
    }

    /**
     * 释放连接。指令通道通常是"用完即关"，这里保持和 Rcon 一致的名字。
     */
    public function close(): void
    {
        $this->backend->close();
    }

    /**
     * 这条通道背后是什么，用于说明数据来源（"RCON" 还是 "XX 控制台"）。
     */
    public function label(): string
    {
        return $this->backend->label();
    }
}
