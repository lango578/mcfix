<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 客户端日志解析器。
 *
 * 玩家发来的东西五花八门，实际会遇到这几种：
 *   1. 崩溃报告      crash-reports/crash-2024-01-01_12.00.00-client.txt（最有信息量）
 *   2. 游戏日志      logs/latest.log
 *   3. 启动器日志    HMCL / PCL2 / 官方启动器的控制台输出
 *   4. 一段截图 OCR 出来的报错（只有几行 + 一句"进不去"）
 *
 * 这个类只做一件事：把文本变成**结构化事实**（什么版本、什么加载器、装了哪些 MOD、
 * 报了哪个异常、缺哪个类/哪个 MOD）。至于"这意味着什么、怎么修"交给 ClientAdvisor。
 *
 * 设计原则：
 *   - 只读文本，绝不执行日志里的任何内容
 *   - 严格限长、限量、限并发，防止有人扔一个 500MB 文件上来打满内存
 *   - 解析失败也要返回可用结果（至少能把原始文本片段给管理员看）
 */
final class ClientLog
{
    /** 单条日志最多处理的字节数 */
    public const MAX_BYTES = 262144; // 256 KB

    /** 单行最多保留的字符数（超长单行是正则回溯的主要来源） */
    private const MAX_LINE_CHARS = 600;

    /** 最多解析的行数（超出部分头尾各留一半） */
    private const MAX_LINES = 6000;

    /** 最多保留的堆栈行数 */
    private const MAX_STACK_LINES = 200;

    /** 最多记录的 MOD 数量 */
    private const MAX_MODS = 400;

    /**
     * 主入口：解析一段客户端日志文本。
     *
     * @param string $raw 原始文本（可能是截断过的）
     * @param array<string,mixed> $hints 玩家填的补充信息：client_version / mod_loader / launcher
     * @return array<string,mixed>
     */
    public static function parse(string $raw, array $hints = []): array
    {
        $raw = self::sanitize($raw);
        $lines = self::splitLines($raw);
        $kind = self::detectKind($raw, $lines);

        $result = [
            'kind'          => $kind,
            'kind_label'    => self::kindLabel($kind),
            'truncated'     => false,
            'bytes'         => strlen($raw),
            'lines'         => count($lines),
            'meta'          => [],
            'mods'          => [],
            'problematic'   => [],
            'stack'         => [],
            'log_errors'    => [],
            'signals'       => [],
            'early_lines'   => [],
            'raw_excerpt'   => '',
        ];

        switch ($kind) {
            case 'crash_report':
                self::parseCrashReport($lines, $result);
                break;
            case 'game_log':
                self::parseGameLog($lines, $result);
                break;
            case 'launcher_log':
                self::parseLauncherLog($lines, $result);
                break;
            default:
                self::parseUnknown($lines, $result);
        }

        // 玩家填的补充信息优先级低于日志里读到的，但可以补空缺
        foreach (['client_version' => 'minecraft_version', 'mod_loader' => 'loader', 'launcher' => 'launcher'] as $hintKey => $metaKey) {
            $value = trim((string) ($hints[$hintKey] ?? ''));
            if ($value !== '' && empty($result['meta'][$metaKey])) {
                $result['meta'][$metaKey] = mb_substr($value, 0, 40);
                $result['meta'][$metaKey . '_from'] = 'player';
            }
        }

        // 归一化：加载器大小写、版本号清理
        $result['meta'] = self::normalizeMeta($result['meta']);
        $result['signals'] = array_values(array_unique($result['signals']));
        $result['raw_excerpt'] = self::excerpt($lines);

        return $result;
    }

    /**
     * 把玩家提交的原始文本读出来。
     *
     * 支持两种投递方式：
     *   - 粘贴文本（client_log 字段）
     *   - 上传文件（client_log_file）
     *
     * @param array<string,mixed> $input 请求体
     * @param array<string,mixed> $files $_FILES
     * @return array{ok:bool,text:string,source:string,filename:string,error:string}
     */
    public static function collect(array $input, array $files = []): array
    {
        $pasted = (string) ($input['client_log'] ?? '');
        $file = $files['client_log_file'] ?? null;

        if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $code = (int) $file['error'];
            if ($code !== UPLOAD_ERR_OK) {
                return [
                    'ok'    => false,
                    'text'  => '',
                    'source'=> 'file',
                    'filename' => (string) ($file['name'] ?? ''),
                    'error' => self::uploadErrorText($code),
                ];
            }

            $size = (int) ($file['size'] ?? 0);
            if ($size > self::MAX_BYTES * 2) {
                return [
                    'ok'    => false,
                    'text'  => '',
                    'source'=> 'file',
                    'filename' => (string) ($file['name'] ?? ''),
                    'error' => '文件太大了（' . human_size((float) $size) . '），请只上传崩溃报告或日志的最后 2000 行',
                ];
            }

            $tmp = (string) ($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                return ['ok' => false, 'text' => '', 'source' => 'file', 'filename' => '', 'error' => '上传文件校验失败，请重试'];
            }

            $content = (string) @file_get_contents($tmp, false, null, 0, self::MAX_BYTES * 2);
            if ($content === '') {
                return ['ok' => false, 'text' => '', 'source' => 'file', 'filename' => '', 'error' => '文件是空的'];
            }

            // 有些启动器日志是 UTF-16 或 GBK，做个探测
            $content = self::toUtf8($content);

            return [
                'ok'       => true,
                'text'     => $content,
                'source'   => 'file',
                'filename' => mb_substr((string) ($file['name'] ?? 'upload.txt'), 0, 120),
                'error'    => '',
            ];
        }

        if (trim($pasted) !== '') {
            return [
                'ok'       => true,
                'text'     => $pasted,
                'source'   => 'paste',
                'filename' => '',
                'error'    => '',
            ];
        }

        return ['ok' => false, 'text' => '', 'source' => 'none', 'filename' => '', 'error' => ''];
    }

    public static function uploadErrorText(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return '文件超过服务器允许的上传大小，请粘贴关键报错段落';
            case UPLOAD_ERR_PARTIAL:
                return '文件只上传了一部分，请重试';
            case UPLOAD_ERR_NO_TMP_DIR:
                return '服务器缺少临时目录，请联系管理员';
            case UPLOAD_ERR_CANT_WRITE:
                return '服务器写入失败，请联系管理员';
            case UPLOAD_ERR_EXTENSION:
                return '上传被 PHP 扩展拦截了';
            default:
                return '上传失败（错误码 ' . $code . '）';
        }
    }

    // ------------------------------------------------------------------ 预处理

    /**
     * 清洗文本：统一换行、去 NUL、去掉 Minecraft 的颜色符号、限长。
     */
    private static function sanitize(string $raw): string
    {
        // 去掉 UTF-8 BOM
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        // NUL 与其它控制字符（保留 \n \r \t）
        $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw) ?? $raw;
        // Minecraft 格式化符号 §x
        $raw = preg_replace('/§[0-9a-fk-or]/i', '', $raw) ?? $raw;
        // 统一换行
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        if (strlen($raw) > self::MAX_BYTES * 2) {
            // 崩溃报告有用的信息在头部和尾部，各留一半
            $raw = substr($raw, 0, self::MAX_BYTES) . "\n...（中间省略）...\n" . substr($raw, -self::MAX_BYTES);
        }

        return $raw;
    }

    /**
     * @return string[]
     */
    private static function splitLines(string $raw): array
    {
        $lines = explode("\n", $raw);

        // 两个上限都是为了防正则回溯放大：
        //   1. 单行截到 MAX_LINE_CHARS —— 有人会把整个 JSON 压成一行贴上来；
        //   2. 行数截到 MAX_LINES —— 256 KB 全是短行时能有十几万行，
        //      每行都要过几十条正则，一个请求就能吃掉好几秒 CPU。
        // 崩溃报告有用的信息在头尾，所以各留一半（对 tail 类日志来说，
        // 截掉的正好是早就滚过去的历史，不影响判断）。
        if (count($lines) > self::MAX_LINES) {
            $half = intdiv(self::MAX_LINES, 2);
            $lines = array_merge(
                array_slice($lines, 0, $half),
                ['...（中间省略 ' . (count($lines) - self::MAX_LINES) . ' 行）...'],
                array_slice($lines, -$half)
            );
        }

        foreach ($lines as $i => $line) {
            if (strlen($line) > self::MAX_LINE_CHARS) {
                $lines[$i] = substr($line, 0, self::MAX_LINE_CHARS) . '…';
            }
        }

        return $lines;
    }

    /**
     * 判断这是哪一类日志。
     *
     * @param string[] $lines
     */
    private static function detectKind(string $raw, array $lines): string
    {
        $head = implode("\n", array_slice($lines, 0, 40));

        if (preg_match('/----\s*Minecraft Crash Report\s*----/i', $head)
            || preg_match('/Time:\s*\d{2}\.\d{2}\.\d{2}\s+\d{2}:\d{2}/', $head)
            || (stripos($head, 'Description:') !== false && stripos($raw, 'Stacktrace:') !== false)) {
            return 'crash_report';
        }

        // 启动器日志特征
        if (preg_match('/(HMCL|PCL2|Plain Craft Launcher|Hello Minecraft! Launcher|MultiMC|PrismLauncher|ATLauncher|GDLauncher)/i', $head)
            || preg_match('/^\s*\[\d{2}:\d{2}:\d{2}\]\s*\[.*?\]\s*(Launching|Detecting)/mi', $head)) {
            return 'launcher_log';
        }

        // 游戏日志特征：[12:00:00] [Render thread/INFO]: ...
        if (preg_match('/^\[\d{2}:\d{2}:\d{2}\]\s*\[[^\]]+\]:/m', $raw)) {
            return 'game_log';
        }

        return 'unknown';
    }

    public static function kindLabel(string $kind): string
    {
        $map = [
            'crash_report' => '崩溃报告（crash-report）',
            'game_log'     => '游戏日志（latest.log）',
            'launcher_log' => '启动器日志',
            'unknown'      => '未识别的文本',
        ];

        return $map[$kind] ?? $kind;
    }

    // ------------------------------------------------------------------ 崩溃报告

    /**
     * @param string[] $lines
     * @param array<string,mixed> $out
     */
    private static function parseCrashReport(array $lines, array &$out): void
    {
        $section = '';
        $stack = [];
        $inStack = false;

        foreach ($lines as $line) {
            // 章节切换
            if (preg_match('/^--\s+(.+?)\s+--\s*$/', $line, $m)) {
                $section = trim($m[1]);
                $inStack = (stripos($section, 'Stacktrace') !== false);
                if (stripos($section, 'Affected level') !== false) {
                    $out['signals'][] = 'affected_level';
                }
                continue;
            }

            // 描述
            if (preg_match('/^\s*Description:\s*(.+)$/', $line, $m)) {
                $out['meta']['description'] = mb_substr(trim($m[1]), 0, 300);
                continue;
            }

            // 头部杂项
            //
            // 注意这里的 \s* ：真实崩溃报告里 System Details 那一段的字段
            // **全部是 Tab 缩进的**，例如
            //     -- System Details --
            //     Details:
            //     \tMinecraft Version: 1.20.1
            //     \tJava Version: 17.0.8, Microsoft
            // 正则原来用 ^ 锚定，于是真实文件一条都匹配不上 —— 版本、Java、
            // 内存、CPU、系统全都提取不到，玩家看到的体检结果和发给模型的
            // 上下文都会缺东西。不能靠"整行 trim"来修：下面判定堆栈行时
            // 正是靠前导空白识别 "\tat ..."，trim 掉就认不出来了。
            if (preg_match('/^\s*Minecraft Version:\s*(.+)$/i', $line, $m)) {
                $out['meta']['minecraft_version'] = trim($m[1]);
                continue;
            }
            if (preg_match('/^\s*(\w*\s?Mod Loader|Loader|Fabric Loader|Forge|NeoForge|Quilt Loader):\s*(.+)$/i', $line, $m)) {
                $out['meta']['loader_raw'][] = trim($m[1]) . ' ' . trim($m[2]);
                continue;
            }
            if (preg_match('/^\s*Java Version:\s*(.+?),?\s*(?:Java VM Version:\s*(.+))?$/i', $line, $m)) {
                $out['meta']['java'] = trim($m[1]);
                if (!empty($m[2])) {
                    $out['meta']['java_vm'] = trim($m[2]);
                }
                continue;
            }
            if (preg_match('/^\s*Memory:\s*(.+)$/i', $line, $m)) {
                $out['meta']['memory'] = trim($m[1]);
                continue;
            }
            if (preg_match('/^\s*CPU:\s*(.+)$/i', $line, $m)) {
                $out['meta']['cpu'] = mb_substr(trim($m[1]), 0, 160);
                continue;
            }
            if (preg_match('/^\s*(Graphics|GPU|OpenGL|Backend):\s*(.+)$/i', $line, $m)) {
                $out['meta']['gpu'] = mb_substr(trim($m[2]), 0, 160);
                continue;
            }
            if (preg_match('/^\s*(Operating System|OS):\s*(.+)$/i', $line, $m)) {
                $out['meta']['os'] = mb_substr(trim($m[2]), 0, 120);
                continue;
            }

            // 问题帧 / 可疑 MOD
            if (stripos($section, 'Problematic frame') !== false) {
                if (preg_match('/^\[[^\]]*\]\s*(.+)$/', $line, $m) && trim($m[1]) !== '') {
                    $out['problematic'][] = mb_substr(trim($m[1]), 0, 240);
                    continue;
                }
                if (preg_match('/^[A-Za-z0-9_\$\.\/]+\(.*\)$/i', $line)) {
                    $out['problematic'][] = mb_substr(trim($line), 0, 240);
                    continue;
                }
            }

            // MOD 列表（崩溃报告里的 -- Mods -- 或 -- Fabric Mods -- 段）
            if (stripos($section, 'Mods') !== false || stripos($section, 'FML') !== false) {
                if (preg_match('/^\s*([A-Za-z0-9_\-\s\.\(\)\[\]]{2,60}?)\s*[:{]?\s*([\w\.\-+]{1,40})?\s*$/', $line, $m)) {
                    $name = trim($m[1]);
                    if ($name !== '' && !preg_match('/^(Mods|Fabric Mods|Name|Version|Forge Mod Loader)$/i', $name)) {
                        $out['mods'][] = [
                            'name'    => mb_substr($name, 0, 60),
                            'version' => isset($m[2]) ? mb_substr(trim($m[2]), 0, 40) : '',
                            'state'   => '',
                        ];
                    }
                }
                continue;
            }

            // 堆栈
            if ($inStack || preg_match('/^\s+at\s+[\w\.\$]+\(/', $line) || preg_match('/^(Caused by|Suppressed):/', $line)) {
                if (count($stack) < self::MAX_STACK_LINES) {
                    $stack[] = mb_substr(trim($line), 0, 300);
                }
                continue;
            }

            // 没有 crash-report 结构，但出现了异常行
            if (preg_match('/(Exception|Error|Throwable)(:|\s|$)/', $line)) {
                if (count($stack) < self::MAX_STACK_LINES) {
                    $stack[] = mb_substr(trim($line), 0, 300);
                }
            }
        }

        // 有些崩溃报告把 MOD 列表放在 "Fabric Mods:" 后跟缩进，逐个再兜一次
        if (!$out['mods']) {
            foreach ($lines as $line) {
                if (preg_match('/^\t([A-Za-z0-9_\-\+\.]{2,60})\s+([\w\.\+\-]{1,40})$/', $line, $m)) {
                    $out['mods'][] = ['name' => $m[1], 'version' => $m[2], 'state' => ''];
                    if (count($out['mods']) >= self::MAX_MODS) {
                        break;
                    }
                }
            }
        }

        $out['stack'] = $stack;
        self::scanSignals($stack, $out);
        // 整份报告再扫一遍：System Details 里的 GLFW / OpenGL 初始化失败
        // 不在堆栈里，只扫堆栈会漏掉。
        self::scanSignals($lines, $out, self::LINE_WIDE_SIGNALS);
        self::scanMeta($out);
    }

    // ------------------------------------------------------------------ 游戏日志

    /**
     * @param string[] $lines
     * @param array<string,mixed> $out
     */
    private static function parseGameLog(array $lines, array &$out): void
    {
        $stack = [];
        $errors = [];
        $seen = [];

        foreach ($lines as $line) {
            // [12:00:00] [Render thread/ERROR]: xxx
            if (preg_match('/^\[(\d{2}:\d{2}:\d{2})\]\s*\[([^\]]+)\]\s*(.*)$/', $line, $m)) {
                $thread = $m[2];
                $message = $m[3];

                if (preg_match('/(ERROR|FATAL|WARN)/', $thread)) {
                    // 同样的错误只留前几条，避免刷屏
                    $key = mb_substr(preg_replace('/\d+/', '#', $message) ?? $message, 0, 160);
                    if (!isset($seen[$key]) || $seen[$key] < 2) {
                        $seen[$key] = ($seen[$key] ?? 0) + 1;
                        $errors[] = [
                            'time'    => $m[1],
                            'thread'  => mb_substr($thread, 0, 60),
                            'level'   => stripos($thread, 'FATAL') !== false ? 'fatal' : (stripos($thread, 'ERROR') !== false ? 'error' : 'warn'),
                            'message' => mb_substr(trim($message), 0, 300),
                        ];
                    }
                }

                // 加载器/版本信息
                if (stripos($message, 'Loading Minecraft') !== false && preg_match('/([\d]+\.[\d]+(\.[\d]+)?)/', $message, $v)) {
                    $out['meta']['minecraft_version'] = $out['meta']['minecraft_version'] ?? $v[1];
                }
                if (preg_match('/Loading (\d+) mods/i', $message, $v)) {
                    $out['meta']['mod_count'] = (int) $v[1];
                }
                if (stripos($message, 'fabricloader') !== false && preg_match('/fabricloader\/([\d\.\+]+)/i', $message, $v)) {
                    $out['meta']['loader_raw'][] = 'Fabric Loader ' . $v[1];
                }
                if (preg_match('/Java is version ([^,]+)/i', $message, $v)) {
                    $out['meta']['java'] = trim($v[1]);
                }
            }

            if (preg_match('/^\s+at\s+[\w\.\$]+\(/', $line) || preg_match('/^(Caused by|Suppressed):/', $line)) {
                if (count($stack) < self::MAX_STACK_LINES) {
                    $stack[] = mb_substr(trim($line), 0, 300);
                }
                continue;
            }

            if (preg_match('/(Exception|Error)(:|\s|$)/', $line) && mb_strlen(trim($line)) < 320) {
                if (count($stack) < self::MAX_STACK_LINES) {
                    $stack[] = mb_substr(trim($line), 0, 300);
                }
            }
        }

        $out['stack'] = $stack;
        $out['log_errors'] = array_slice($errors, -120);
        self::scanSignals(array_merge(array_column($errors, 'message'), $stack), $out);
        // 整份日志再扫一遍：被踢下线的原因常常是 INFO 行（见 LINE_WIDE_SIGNALS）
        self::scanSignals($lines, $out, self::LINE_WIDE_SIGNALS);
        self::scanMeta($out);
    }

    // ------------------------------------------------------------------ 启动器日志

    /**
     * @param string[] $lines
     * @param array<string,mixed> $out
     */
    private static function parseLauncherLog(array $lines, array &$out): void
    {
        $stack = [];
        $errors = [];

        foreach ($lines as $line) {
            if (preg_match('/(HMCL|PCL2|Plain Craft Launcher|MultiMC|Prism)/i', $line, $m)) {
                $out['meta']['launcher'] = $out['meta']['launcher'] ?? $m[1];
            }
            if (preg_match('/(Java|JVM)\D{0,12}(\d+\.\d+[\.\d_]*)/i', $line, $m)) {
                $out['meta']['java'] = $out['meta']['java'] ?? $m[2];
            }
            if (preg_match('/(1\.\d{1,2}(\.\d{1,2})?)/', $line, $m) && stripos($line, 'version') !== false) {
                $out['meta']['minecraft_version'] = $out['meta']['minecraft_version'] ?? $m[1];
            }
            if (preg_match('/forge[^\d]{0,4}([\d\.]+)/i', $line, $m)) {
                $out['meta']['loader_raw'][] = 'Forge ' . $m[1];
            }
            if (preg_match('/neoforge[^\d]{0,4}([\d\.]+)/i', $line, $m)) {
                $out['meta']['loader_raw'][] = 'NeoForge ' . $m[1];
            }
            if (preg_match('/fabric[\s\-]?loader[^\d]{0,4}([\d\.\+]+)/i', $line, $m)) {
                $out['meta']['loader_raw'][] = 'Fabric Loader ' . $m[1];
            }

            $lower = mb_strtolower($line);
            if (strpos($lower, 'exception') !== false || strpos($lower, 'error') !== false || strpos($lower, '错误') !== false || strpos($lower, '失败') !== false) {
                if (count($errors) < 60 && mb_strlen(trim($line)) < 320) {
                    $errors[] = ['time' => '', 'thread' => 'launcher', 'level' => 'error', 'message' => mb_substr(trim($line), 0, 300)];
                }
            }
            if (preg_match('/^\s+at\s+[\w\.\$]+\(/', $line) || preg_match('/^(Caused by|Suppressed):/', $line)) {
                if (count($stack) < self::MAX_STACK_LINES) {
                    $stack[] = mb_substr(trim($line), 0, 300);
                }
            }
        }

        $out['stack'] = $stack;
        $out['log_errors'] = $errors;
        self::scanSignals(array_merge(array_column($errors, 'message'), $stack, $lines), $out);
        self::scanMeta($out);
    }

    /**
     * 什么都不像：把带关键字的行捞出来。
     *
     * @param string[] $lines
     * @param array<string,mixed> $out
     */
    private static function parseUnknown(array $lines, array &$out): void
    {
        $out['early_lines'] = array_slice(array_map(static function ($l): string {
            return mb_substr(trim((string) $l), 0, 200);
        }, $lines), 0, 20);

        $interesting = [];
        foreach ($lines as $line) {
            if ($line === '' || mb_strlen($line) > 400) {
                continue;
            }
            if (preg_match('/(Exception|Error|Caused by|Could not|Failed|Unable|Missing|Incompatible|mixin|mod|whitelist|refused|timed out|UnknownHost|Forge|Fabric)/i', $line)) {
                $interesting[] = mb_substr(trim($line), 0, 300);
            }
            if (count($interesting) >= self::MAX_STACK_LINES) {
                break;
            }
        }

        $out['stack'] = $interesting;
        self::scanSignals(array_merge($interesting, $lines), $out);
        self::scanMeta($out);
    }

    // ------------------------------------------------------------------ 特征扫描

    /**
     * 从堆栈和错误行里提取"信号"：缺陷类、缺失类、加载器、MOD 名。
     *
     * @param string[] $texts
     * @param array<string,mixed> $out
     */
    /**
     * 值得在**整份日志**上再扫一遍的特征。
     *
     * 为什么需要：真实客户端日志里，"你不在白名单 / 你被封禁 / 服务器已满 /
     * 客户端过旧 / 登录会话失效" 这些决定性消息是 **Render 线程按 INFO 打的**
     * （只有 Netty 那层才报 ERROR）。而上面各解析路径只把 ERROR/FATAL/WARN 行
     * 和堆栈喂给 scanSignals，于是这些消息在旧版里完全扫不到 —— 玩家提交一份
     * 明明白白写着"你不在白名单"的日志，系统却回他"日志内容太短，无法定位"。
     *
     * 为什么只挑这一组：它们的正则足够具体（成句的措辞，不是单个词）。
     * 刻意**不含** timed_out / ssl_error —— 那两条的正则里有 `timed out`、
     * `certificate` 这类宽泛词，铺到整份日志上会误报。它们仍照常从
     * ERROR/WARN 行里扫。
     */
    private const LINE_WIDE_SIGNALS = [
        'whitelist_kick',
        'banned_kick',
        'server_full_kick',
        'version_mismatch',
        'auth_failed',
        'connection_refused',
        'unknown_host',
        'reset_by_peer',
        'gpu_init_failed',
    ];

    private static function scanSignals(array $texts, array &$out, array $only = []): void
    {
        // 玩家说的话不是系统状态。
        //
        // 日志里 `[CHAT] <Steve> 白名单怎么加啊` 这种行，和"服务器告诉你被踢了"
        // 在文本上长得一模一样。1.12.0 把扫描范围扩到整份日志之后，聊天内容
        // 直接变成了结论 —— 有人在公屏问一句白名单，系统就告诉他
        // "你不在服务器白名单里"；有人提一句"他昨天被封禁了吧"，系统就告诉他
        // "你的账号处于封禁状态"（还会自动提交解封请求）。
        //
        // 这类误报比漏报糟得多：漏报是"我不知道"，误报是一个明确而错误的结论，
        // 玩家会照着它去做没用的事。所以聊天行在特征扫描里一律不看。
        $clean = [];
        foreach ($texts as $line) {
            $line = (string) $line;
            if (preg_match('/\[CHAT\]/i', $line)) {
                continue;
            }
            // 兜底：有些模组不走 [CHAT] 标记，但仍然是 `<玩家名> 内容` 的形式
            if (preg_match('/<[A-Za-z0-9_]{3,16}>\s/', $line)) {
                continue;
            }
            $clean[] = $line;
        }

        $haystack = implode("\n", array_slice($clean, 0, 600));

        $patterns = [
            'class_not_found'  => '/NoClassDefFoundError|ClassNotFoundException/i',
            'method_not_found' => '/NoSuchMethodError|NoSuchFieldError/i',
            'mixin_failed'     => '/MixinApplyError|MixinTransformerError|InvalidMixinException|Mixin apply failed|Mixin.*(failed|conflict)/i',
            'mod_load_error'   => '/ModLoadingException|LoadingFailedException|Failed to create mod instance|Mod file .* is missing|Error during pre-loading|Could not load mod/i',
            'missing_dep'      => '/Missing or unsupported mandatory dependencies|requires .* which is missing|Missing dependency|MissingDependenciesException/i',
            'mod_incompatible' => '/Incompatible mod set|mod .* is not compatible|requires version .* of/i',
            'loader_mismatch'  => '/forge version|neoforge version|fabric loader|loader version|requires (forge|fabric|neoforge)/i',
            'version_mismatch' => '/Outdated server|Outdated client|Incompatible protocol|server version is not compatible|Failed to log in: Incompatible/i',
            'oom'              => '/OutOfMemoryError|Java heap space|GC overhead limit/i',
            'crash_client'     => '/This crash report has been saved|Minecraft has crashed|The game crashed/i',
            'exit_code_1'      => '/Process crashed with exit code|Exit code: -?\d|游戏非正常退出/i',
            'auth_failed'      => '/Failed to verify username|Invalid session|authentication servers? (are|is) down|Failed to login: Invalid session|AUTHENTICATION/i',
            'connection_refused' => '/Connection refused|ConnectException|Failed to connect to the server|io\.netty.*ConnectException/i',
            'unknown_host'     => '/UnknownHostException|java\.net\.UnknownHost/i',
            'timed_out'        => '/Read timed out|Connection timed out|ConnectTimeoutException|SocketTimeoutException|ReadTimeoutException/i',
            'reset_by_peer'    => '/Connection reset by peer|远程主机强迫关闭|An existing connection was forcibly closed/i',
            // 刻意不含裸的 `certificate`：模组/资源包也会提到证书。
            // 只认真正的 TLS 异常类型。
            'ssl_error'        => '/SSLHandshakeException|SSLException|PKIX path building failed|No subject alternative names|CertificateException|javax\.net\.ssl/i',
            // ★ 只认「服务端在把你挡在门外」的那种具体措辞，不认裸的"白名单"/"封禁"。
            //
            // 裸词会命中日志里**任何**提到这两个字的地方：模组配置里写了个叫
            // "白名单"的选项、别人被封的记录、玩家闲聊被写进普通日志行 ——
            // 全都会变成"你被拒之门外"的结论。
            //
            // 实测（见 CHANGELOG 1.13.2 的 V20）：把一句 "mod config 白名单 related text"
            // 写成普通日志行（不带 [CHAT]、不带 `<name> `），系统就对玩家说
            // "服务端明确提示你不在白名单里"，还给出一键加白名单的动作。
            'whitelist_kick'   => '/(?:You are not white(?:-)?listed|You are not whitelisted on this server|Kicked:\s*.*not white(?:-)?listed|不在服务器的?白名单)/i',
            // 同理：`Banned by an operator` 单独出现时，"被封的人"可能是别人。
            // 要求它是"对你说"的措辞（You are banned / 你…被封禁）。
            // 中文侧覆盖「你已被封禁 / 您的账号已被封禁」这类直接对玩家说的句子；
            // 只说"封禁"两个字的不算（模组配置、别人被记录都会命中）。
            'banned_kick'      => '/(?:You are banned from this server|You have been banned|Banned by an operator:\s*You|(?:你|您)(?:的)?(?:账号|帐号|账户)?已被?(?:服务器)?封禁)/i',
            'server_full_kick' => '/Server is full|server is full/i',
            'upstream_error'   => '/Internal Exception|Unexpected packet|Badly compressed packet|decoder exception/i',
            // 刻意不含裸的 `corrupt`：模组配置文件损坏、存档索引损坏都会命中，
            // 然后告诉玩家"存档区块损坏"。要求 corrupt 与 chunk/region/world/level
            // 出现在一起才算。
            'chunk_corrupt'    => '/Chunk file at .* is missing|Region file .{0,120}(?:corrupt|mismatch)|corrupt(?:ed)? (?:chunk|region|world|level|save)|(?:chunk|region|world|level) .{0,30}corrupt/i',
            'graphics_driver'  => '/OpenGL|GLFW|Pixel format|Failed to create window|GPU/i',
            // 只认"初始化失败"这类明确的措辞。刻意不含 WGL / GLX：
            // 正则不区分大小写，而 `org.lwjgl` 里就含 "wgl"，
            // 那样每一条 LWJGL 堆栈都会被误判成显卡驱动问题。
            'gpu_init_failed'  => '/GLFW error|Failed to create window|Failed to create (?:the )?(?:window|context)|Pixel format not accelerated|does not appear to support OpenGL|no appropriate pixel format|Failed to initialize GLFW|Could not create (?:the )?(?:window|context)|OpenGL is not supported/i',
            'java_version'     => '/UnsupportedClassVersionError|class file version|requires Java|Java 17|Java 21/i',
            'render_error'     => '/Rendering screen|RenderSystem|Tesselator|VertexFormat/i',
            // 刻意不含裸的 `shader`：Minecraft 核心类 ShaderInstance 就会命中，
            // 这个信号要参与定论，误报会直接变成给玩家的错误结论。
            'ccompat_error'    => '/OptiFine|Sodium|Embeddium|Iris|shader ?pack|shaders\.properties/i',
            'datapack_error'   => '/Datapack|data pack|Failed to load datapack/i',
            'server_side_only' => '/This server is running|legacy server list ping|plugins\.yml|spigot/i',
        ];

        foreach ($patterns as $signal => $regex) {
            if ($only !== [] && !in_array($signal, $only, true)) {
                continue;
            }
            if (preg_match($regex, $haystack)) {
                $out['signals'][] = $signal;
            }
        }

        // 同一个信号可能被扫两遍（堆栈一遍、全量行一遍），去重
        $out['signals'] = array_values(array_unique((array) ($out['signals'] ?? [])));

        // 缺失的类名 → 往往能直接定位到是哪个 MOD / 库没装
        if (preg_match_all('/(?:NoClassDefFoundError|ClassNotFoundException)(?::\s*|\s+)([A-Za-z0-9_\$\.\/]+)/', $haystack, $m)) {
            $classes = [];
            foreach (array_slice($m[1], 0, 12) as $class) {
                $class = str_replace('/', '.', trim($class));
                if ($class !== '') {
                    $classes[] = mb_substr($class, 0, 160);
                }
            }
            $out['missing_classes'] = array_values(array_unique($classes));
        }

        // 从堆栈里猜 MOD 名：net.minecraftforge / dev.architectury / 包名前缀
        $modPackages = [];
        if (preg_match_all('/\bat\s+([a-z][a-z0-9_]{1,20}(?:\.[a-z0-9_]{1,24}){1,4})\./i', $haystack, $m)) {
            foreach ($m[1] as $package) {
                if (preg_match('/^(net\.minecraft|java|javax|sun|jdk|com\.mojang|org\.spongepowered|io\.netty|org\.apache|com\.google|it\.unimi|org\.lwjgl|net\.fabricmc\.loader|net\.minecraftforge\.fml|cpw\.mods)/i', $package)) {
                    continue;
                }
                $modPackages[$package] = ($modPackages[$package] ?? 0) + 1;
            }
        }
        arsort($modPackages);
        $out['suspect_packages'] = array_slice(array_keys($modPackages), 0, 8);

        // 出现次数最多的异常类型
        if (preg_match_all('/([A-Za-z0-9_\$\.]+(?:Exception|Error))/', $haystack, $m)) {
            $counter = [];
            foreach ($m[1] as $type) {
                $short = substr($type, (int) strrpos($type, '.') + 1);
                if (in_array($short, ['Exception', 'Error'], true)) {
                    continue;
                }
                $counter[$short] = ($counter[$short] ?? 0) + 1;
            }
            arsort($counter);
            $out['exceptions'] = array_slice($counter, 0, 10, true);
        }
    }

    /**
     * 从元数据里补充推断（版本号、加载器）。
     *
     * @param array<string,mixed> $out
     */
    private static function scanMeta(array &$out): void
    {
        $meta = (array) $out['meta'];
        $raw = implode(' | ', (array) ($meta['loader_raw'] ?? []));

        // 加载器识别
        if ($meta['loader'] ?? false) {
            // 已由玩家填写
        } elseif (preg_match('/neoforge\s*([\d\.]+)/i', $raw, $m)) {
            $meta['loader'] = 'NeoForge';
            $meta['loader_version'] = $m[1];
        } elseif (preg_match('/forge\s*([\d\.]+)/i', $raw, $m)) {
            $meta['loader'] = 'Forge';
            $meta['loader_version'] = $m[1];
        } elseif (preg_match('/fabric\s*(?:loader)?\s*([\d\.\+]+)/i', $raw, $m)) {
            $meta['loader'] = 'Fabric';
            $meta['loader_version'] = $m[1];
        } elseif (preg_match('/quilt/i', $raw)) {
            $meta['loader'] = 'Quilt';
        } elseif (preg_match('/optifine/i', $raw)) {
            $meta['loader'] = 'OptiFine';
        }

        // 从堆栈里兜底识别加载器
        if (empty($meta['loader'])) {
            $stackText = implode("\n", array_slice((array) $out['stack'], 0, 200));
            if (preg_match('/net\.neoforged/i', $stackText)) {
                $meta['loader'] = 'NeoForge';
            } elseif (preg_match('/net\.minecraftforge|cpw\.mods/i', $stackText)) {
                $meta['loader'] = 'Forge';
            } elseif (preg_match('/net\.fabricmc/i', $stackText)) {
                $meta['loader'] = 'Fabric';
            }
        }

        // 从 MOD 列表长度推断
        if (empty($meta['mod_count']) && !empty($out['mods'])) {
            $meta['mod_count'] = count($out['mods']);
        }

        $out['meta'] = $meta;
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private static function normalizeMeta(array $meta): array
    {
        if (!empty($meta['loader_raw'])) {
            $meta['loader_raw'] = array_values(array_unique(array_slice((array) $meta['loader_raw'], 0, 6)));
        }
        if (!empty($meta['minecraft_version'])) {
            $meta['minecraft_version'] = trim(preg_replace('/[^\d\.\w\-]/', '', (string) $meta['minecraft_version']) ?? '');
        }
        if (!empty($meta['java'])) {
            $meta['java'] = mb_substr(trim((string) $meta['java']), 0, 80);
        }
        if (!empty($meta['memory'])) {
            $meta['memory'] = mb_substr(trim((string) $meta['memory']), 0, 80);
        }

        // MOD 去重
        if (!empty($meta['mods']) && is_array($meta['mods'])) {
            $meta['mods'] = array_slice($meta['mods'], 0, self::MAX_MODS);
        }

        return $meta;
    }

    /**
     * 给管理员看的原文摘要（保留最有信息量的部分）。
     *
     * @param string[] $lines
     */
    private static function excerpt(array $lines): string
    {
        $picked = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/(Exception|Error|Caused by|Description:|Minecraft Version|Java Version|Mod Loader|Failed|Missing|Incompatible|at [a-z])/i', $trimmed)) {
                $picked[] = mb_substr($trimmed, 0, 220);
            }
            if (count($picked) >= 40) {
                break;
            }
        }

        if (!$picked) {
            foreach (array_slice($lines, 0, 20) as $line) {
                if (trim($line) !== '') {
                    $picked[] = mb_substr(trim($line), 0, 220);
                }
            }
        }

        return implode("\n", $picked);
    }

    /**
     * 探测编码并转成 UTF-8。
     */
    private static function toUtf8(string $content): string
    {
        // UTF-16 BOM
        if (strncmp($content, "\xFF\xFE", 2) === 0 || strncmp($content, "\xFE\xFF", 2) === 0) {
            if (function_exists('mb_convert_encoding')) {
                return (string) mb_convert_encoding($content, 'UTF-8', 'UTF-16');
            }
        }

        // 不是合法 UTF-8 就按 GBK 处理（国内玩家的启动器日志常见）
        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = @mb_convert_encoding($content, 'UTF-8', 'GB18030');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $content;
    }

    /**
     * 把解析结果压缩成给玩家看的一小段（避免把内部信息泄露出去）。
     *
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    public static function publicFacts(array $parsed): array
    {
        $meta = (array) ($parsed['meta'] ?? []);

        return [
            'kind'         => (string) ($parsed['kind'] ?? 'unknown'),
            'kind_label'   => (string) ($parsed['kind_label'] ?? ''),
            'version'      => (string) ($meta['minecraft_version'] ?? ''),
            'loader'       => trim((string) ($meta['loader'] ?? '') . ' ' . (string) ($meta['loader_version'] ?? '')),
            'java'         => (string) ($meta['java'] ?? ''),
            'memory'       => (string) ($meta['memory'] ?? ''),
            'mod_count'    => (int) ($meta['mod_count'] ?? count((array) ($parsed['mods'] ?? []))),
            'mods'         => array_slice(array_map(static function (array $mod): string {
                return (string) $mod['name'] . ($mod['version'] !== '' ? ' ' . $mod['version'] : '');
            }, (array) ($parsed['mods'] ?? [])), 0, 60),
            'exceptions'   => array_slice(array_keys((array) ($parsed['exceptions'] ?? [])), 0, 6),
            'signal_count' => count((array) ($parsed['signals'] ?? [])),
        ];
    }
}
