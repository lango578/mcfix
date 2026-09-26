<?php

declare(strict_types=1);

namespace MCFix;

/**
 * RCON 后端：把 `Rcon` 适配成指令通道。
 *
 * @internal 只给 CommandChannel 用，别在别处直接实例化。
 */
final class RconChannel
{
    private Rcon $rcon;

    public function __construct(Rcon $rcon)
    {
        $this->rcon = $rcon;
    }

    /**
     * @return array{ok:bool,output:string,error:string,ms:float}
     */
    public function command(string $command): array
    {
        return $this->rcon->command($command);
    }

    public function close(): void
    {
        $this->rcon->close();
    }

    public function label(): string
    {
        return 'RCON';
    }
}
