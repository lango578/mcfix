<?php

declare(strict_types=1);

namespace MCFix;

/**
 * MOD 文件库 + 分发。
 *
 * 玩家缺 MOD 是客户端问题里最常见的一类。只告诉他"你缺 journeymap"没用 ——
 * 他大概率会下错版本。所以这里做两件事：
 *
 *   1. 找得到就**直接给下载链接**：优先用管理员上传/服务端自动送来的文件（版本一定对）
 *   2. 找不到就给**带精确文件名的搜索链接**，并且告诉管理员"把 MOD 放进服务器 mods 目录"
 *
 * 文件存在 storage/mods/ 下，文件名形如 `journeymap__<sha256前16位>.jar`，
 * 下载走 ?r=mod.download&f=<签名令牌>，令牌里绑定文件名 + 服务器 + 有效期，
 * 因此不能被用来遍历目录。
 */
final class ModLibrary
{
    /** 单个文件大小上限（MOD 一般几 MB，留足余量） */
    public const MAX_FILE_BYTES = 67108864; // 64 MB

    public const DIR = 'mods';

    public static function dir(): string
    {
        return storage_path(self::DIR);
    }

    /**
     * 把上传/接收到的文件存进库。
     *
     * @param string $sourcePath 临时文件路径
     * @param string $originalName 原始文件名
     * @param array<string,mixed> $meta 额外信息：server_id / source / version
     * @return array{ok:bool,id:string,filename:string,size:int,sha256:string,error:string}
     */
    public static function store(string $sourcePath, string $originalName, array $meta = []): array
    {
        $fail = static function (string $error): array {
            return ['ok' => false, 'id' => '', 'filename' => '', 'size' => 0, 'sha256' => '', 'error' => $error];
        };

        if (!is_file($sourcePath)) {
            return $fail('文件不存在');
        }

        $size = (int) filesize($sourcePath);
        if ($size <= 0) {
            return $fail('文件是空的');
        }
        if ($size > self::MAX_FILE_BYTES) {
            return $fail('文件太大（' . human_size((float) $size) . '），上限 ' . human_size((float) self::MAX_FILE_BYTES));
        }

        // jar/zip 是 zip 格式，校验一下，防止有人上传奇怪的东西
        $head = (string) @file_get_contents($sourcePath, false, null, 0, 4);
        if ($head !== "PK\x03\x04" && $head !== "PK\x05\x06") {
            return $fail('这不是一个有效的 jar/zip 文件');
        }

        $sha256 = (string) hash_file('sha256', $sourcePath);
        $safeName = self::safeFilename($originalName);
        if ($safeName === '') {
            $safeName = 'mod.jar';
        }

        $key = self::modKey($safeName);
        $stored = ($key !== '' ? $key : 'mod') . '__' . substr($sha256, 0, 16) . '.jar';

        $dir = self::dir();
        if (!ensure_dir($dir)) {
            return $fail('无法创建 MOD 库目录，请检查 storage 目录权限');
        }

        $target = $dir . '/' . $stored;
        if (!is_file($target)) {
            if (!@copy($sourcePath, $target)) {
                return $fail('写入 MOD 库失败');
            }
            @chmod($target, 0644);
        }

        // 索引：文件名 → 元信息
        $index = self::index();
        $index[$stored] = [
            'file'         => $stored,
            'original'     => $safeName,
            'key'          => $key,
            'size'         => $size,
            'sha256'       => $sha256,
            'server_id'    => (string) ($meta['server_id'] ?? ''),
            'source'       => (string) ($meta['source'] ?? 'manual'),
            'added_at'     => now(),
            'added_by'     => (string) ($meta['added_by'] ?? 'admin'),
        ];
        self::saveIndex($index);

        record_event(null, 'system', 'mod.store', '入库 MOD：' . $safeName . '（' . human_size((float) $size) . '）', [
            'file'   => $stored,
            'source' => (string) ($meta['source'] ?? 'manual'),
            'sha256' => substr($sha256, 0, 16),
        ]);

        return [
            'ok'       => true,
            'id'       => $stored,
            'filename' => $safeName,
            'size'     => $size,
            'sha256'   => $sha256,
            'error'    => '',
        ];
    }

    /**
     * 直接存字节内容（Agent 上传时用）。
     */
    public static function storeBytes(string $bytes, string $originalName, array $meta = []): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mcfix');
        if ($tmp === false) {
            return ['ok' => false, 'id' => '', 'filename' => '', 'size' => 0, 'sha256' => '', 'error' => '无法创建临时文件'];
        }
        @file_put_contents($tmp, $bytes);

        $result = self::store($tmp, $originalName, $meta);
        @unlink($tmp);

        return $result;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function index(): array
    {
        $file = self::dir() . '/index.json';
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string,array<string,mixed>> $index
     */
    private static function saveIndex(array $index): void
    {
        $dir = self::dir();
        ensure_dir($dir);
        $tmp = $dir . '/index.json.tmp';
        @file_put_contents($tmp, (string) json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
        @rename($tmp, $dir . '/index.json');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function listAll(): array
    {
        $index = self::index();
        $out = [];
        foreach ($index as $entry) {
            $path = self::dir() . '/' . (string) ($entry['file'] ?? '');
            if (!is_file($path)) {
                continue;
            }
            $entry['exists'] = true;
            $out[] = $entry;
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp((string) ($b['added_at'] ?? ''), (string) ($a['added_at'] ?? ''));
        });

        return $out;
    }

    public static function forget(string $storedFile): bool
    {
        $index = self::index();
        $storedFile = basename($storedFile);
        if (!isset($index[$storedFile])) {
            return false;
        }
        @unlink(self::dir() . '/' . $storedFile);
        unset($index[$storedFile]);
        self::saveIndex($index);

        return true;
    }

    /**
     * 尝试为"玩家缺的这个组件"找到一个可下载的文件。
     *
     * @param string $name 组件名（可能是文件名、MOD 名或包名）
     * @param array<string,mixed> $serverMods ServerMods::forServer() 的结果
     * @return array{found:bool,is_local:bool,filename:string,size:int,sha256:string,download:string,note:string}
     */
    public static function resolve(string $name, array $serverMods = []): array
    {
        $miss = [
            'found' => false, 'is_local' => false, 'filename' => '', 'size' => 0,
            'sha256' => '', 'download' => '', 'note' => '',
        ];

        $key = self::modKey($name);
        if ($key === '') {
            $miss['note'] = '名字无法识别';

            return $miss;
        }

        // 1. 本地库里找（管理员上传过，或 Agent 从服务端送来过）
        $best = null;
        foreach (self::index() as $stored => $entry) {
            $candidateKey = (string) ($entry['key'] ?? self::modKey((string) ($entry['original'] ?? '')));
            if ($candidateKey === '' || $candidateKey !== $key) {
                // 也允许原始文件名匹配（玩家的名字可能是完整文件名）
                if (stripos((string) ($entry['original'] ?? ''), (string) $name) === false) {
                    continue;
                }
            }
            if (!is_file(self::dir() . '/' . $stored)) {
                continue;
            }
            $best = $entry;
            break;
        }

        if ($best !== null) {
            $stored = (string) $best['file'];

            return [
                'found'     => true,
                'is_local'  => true,
                'filename'  => (string) ($best['original'] ?? $stored),
                'size'      => (int) ($best['size'] ?? 0),
                'sha256'    => (string) ($best['sha256'] ?? ''),
                'download'  => url('?r=mod.download&f=' . self::downloadToken($stored)),
                'note'      => '服务器提供的文件，版本一定匹配',
            ];
        }

        // 2. 服务端 mods 目录里有 → 可以自动送来（玩家点一下按钮）
        if (!empty($serverMods['available'])) {
            foreach ((array) ($serverMods['mods'] ?? []) as $candidateKey => $info) {
                if ((string) $candidateKey === $key) {
                    $miss['note'] = '服务端的 mods 目录里有这个文件，可以自动取回';

                    return $miss;
                }
            }
        }

        $miss['note'] = '服务器暂时没有这个文件，请向服主索取';

        return $miss;
    }

    /**
     * 下载令牌：绑定文件名 + 过期时间，不能用来遍历目录。
     */
    public static function downloadToken(string $storedFile, int $ttlSeconds = 604800): string
    {
        $storedFile = basename($storedFile);

        return Token::signCustom(Token::SCOPE_DOWNLOAD, [$storedFile, time() + $ttlSeconds]);
    }

    /**
     * @return array{ok:bool,path:string,filename:string,error:string}
     */
    public static function resolveDownload(string $token): array
    {
        $parsed = Token::parseCustom($token, Token::SCOPE_DOWNLOAD);
        if ($parsed === null) {
            return ['ok' => false, 'path' => '', 'filename' => '', 'error' => '下载链接已失效，请重新打开工单页面'];
        }

        $stored = basename((string) ($parsed['data'][0] ?? ''));
        if ($stored === '' || strpos($stored, '..') !== false) {
            return ['ok' => false, 'path' => '', 'filename' => '', 'error' => '文件名非法'];
        }

        $path = self::dir() . '/' . $stored;
        if (!is_file($path)) {
            return ['ok' => false, 'path' => '', 'filename' => '', 'error' => '文件不存在或已被清理'];
        }

        $index = self::index();
        $original = (string) ($index[$stored]['original'] ?? $stored);

        return ['ok' => true, 'path' => $path, 'filename' => $original, 'error' => ''];
    }

    /**
     * 把文件流式发给浏览器。
     */
    public static function stream(string $path, string $filename): void
    {
        if (headers_sent()) {
            return;
        }

        $size = (int) filesize($path);
        // 这是全项目唯一一个没做控制字符过滤的 header() 参数。
        // 文件名来自 mods 索引（Agent 上报的内容），safeFilename() 已经会去掉控制字符，
        // 但那是别处的保证 —— 这里自己再兜一道，不依赖上游。
        // PHP 7.4+ 的 header() 遇到 CR/LF 会拒绝整条并告警，届时下载会静默变成坏响应，
        // 所以过滤在这里做比依赖 header() 的行为更稳。
        $safeName = (string) preg_replace('/[\r\n\t\x00-\x1f\x7f]+/', '', str_replace('"', '', $filename));
        header('Content-Type: application/java-archive');
        header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName));
        header('Content-Length: ' . $size);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, must-revalidate');

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return;
        }
        while (!feof($handle)) {
            $chunk = fread($handle, 262144);
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            flush();
        }
        fclose($handle);
    }

    /**
     * 归一化 MOD 名（和 ServerMods 保持一致）。
     */
    public static function modKey(string $name): string
    {
        return ServerMods::modKey($name);
    }

    /**
     * 生成安全的存储文件名。
     */
    private static function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\w\.\-\+\(\)\[\]\x{4e00}-\x{9fa5} ]/u', '_', $name) ?? $name;
        $name = trim($name, " .\t");

        return mb_substr($name, 0, 120);
    }

    /**
     * 清理超过保留期的文件（cron 调用）。
     */
    public static function gc(int $keepDays = 180): int
    {
        $index = self::index();
        $deadline = time() - $keepDays * 86400;
        $removed = 0;

        foreach ($index as $stored => $entry) {
            $addedAt = ts((string) ($entry['added_at'] ?? ''));
            if ($addedAt > 0 && $addedAt < $deadline) {
                @unlink(self::dir() . '/' . $stored);
                unset($index[$stored]);
                $removed++;
            }
        }

        if ($removed > 0) {
            self::saveIndex($index);
        }

        return $removed;
    }
}
