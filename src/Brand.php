<?php

declare(strict_types=1);

namespace MCFix;

/**
 * 站点图标（favicon）与邮件 Logo。
 *
 * 两者是同一类东西 —— "一张属于这个站点的图片" —— 所以共用一套上传、校验和寻址，
 * 只是存放的槽位不同（favicon / logo）。
 *
 * 存哪里：public/brand/<槽位>.<扩展名>
 *
 *   放 public/ 下是因为它必须能被 nginx 直接当静态文件发出去（favicon 每次
 *   页面加载都要取一次，走 PHP 路由既慢又浪费）。这个目录已经在站点根里，
 *   nginx 的 deny 名单不含 brand，所以放行。
 *
 * 为什么不用配置项记文件名：**文件在不在，本身就是"有没有设置"**。
 * 多一个配置项就多一个能对不上的地方（文件删了配置还在），不如直接看文件。
 */
final class Brand
{
    /** 单个文件上限。图标和邮件 Logo 都用不到更大。 */
    public const MAX_BYTES = 262144;   // 256 KB

    /** 像素上限，挡住"上传一张 8000×8000 的图"这种明显不合适的情况。 */
    public const MAX_PIXELS = 2048;

    /**
     * 允许的图片类型。
     *
     * **故意不支持 SVG**：SVG 能内嵌 <script>，而它会被同源地发出去 ——
     * 直接访问那个 .svg 地址就是一次存储型 XSS。图标用 PNG 完全够，
     * 没必要为了一个可有可无的矢量格式开这个口子。
     */
    private const TYPES = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    /** 读取结果的内存缓存（同一请求里可能问好几次） */
    private static $memo = [];

    public static function dir(): string
    {
        return MCFIX_ROOT . '/public/brand';
    }

    public static function webPath(string $file): string
    {
        return '/brand/' . $file;
    }

    /**
     * 找出某个槽位当前的图片。
     *
     * @return array{file:string,path:string,ext:string,mime:string,bytes:int,mtime:int,url:string,width:int,height:int}|null
     */
    public static function get(string $slot): ?array
    {
        if (array_key_exists($slot, self::$memo)) {
            return self::$memo[$slot];
        }

        $dir = self::dir();
        foreach (self::TYPES as $ext => $mime) {
            $path = $dir . '/' . $slot . '.' . $ext;
            if (!is_file($path)) {
                continue;
            }

            $info = @getimagesize($path);
            $mtime = (int) @filemtime($path);

            return self::$memo[$slot] = [
                'file'   => $slot . '.' . $ext,
                'path'   => $path,
                'ext'    => $ext,
                'mime'   => $mime,
                'bytes'  => (int) @filesize($path),
                'mtime'  => $mtime,
                // 带上 mtime，换图标之后不会有人还看到旧的那张
                'url'    => self::webPath($slot . '.' . $ext) . '?v=' . $mtime,
                'width'  => is_array($info) ? (int) $info[0] : 0,
                'height' => is_array($info) ? (int) $info[1] : 0,
            ];
        }

        return self::$memo[$slot] = null;
    }

    public static function has(string $slot): bool
    {
        return self::get($slot) !== null;
    }

    /** 上传失败时的文案，尽量说清楚"哪里不对"而不是"操作失败" */
    private static function uploadErrorText(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return '文件超过服务器允许的上传大小，请换一张更小的图';
            case UPLOAD_ERR_PARTIAL:
                return '文件只上传了一半，请重试';
            case UPLOAD_ERR_NO_FILE:
                return '没有选择文件';
            case UPLOAD_ERR_NO_TMP_DIR:
                return '服务器缺少临时目录，联系主机商';
            case UPLOAD_ERR_CANT_WRITE:
                return '服务器写入失败（磁盘满或权限不足）';
            default:
                return '上传失败（错误码 ' . $code . '）';
        }
    }

    /**
     * 校验一个本地文件能不能当图片用。
     *
     * 刻意**不**检查"它是不是 HTTP 上传来的" —— 那是 store() 的职责。
     * 拆开的直接原因是可测性：`is_uploaded_file()` 只对真正的 multipart 上传
     * 返回 true，测试里造不出那个条件，不拆开的话"SVG 被拒绝""非图片被拒绝"
     * 这两条**永远测不到**（第一版就是这样，测试跑出来是"上传校验失败"，
     * 因为它先撞上了 is_uploaded_file）。
     *
     * @return array{ok:bool,error:string,ext:string,mime:string,width:int,height:int}
     */
    public static function inspect(string $path): array
    {
        $fail = static function (string $msg): array {
            return ['ok' => false, 'error' => $msg, 'ext' => '', 'mime' => '', 'width' => 0, 'height' => 0];
        };

        if ($path === '' || !is_file($path)) {
            return $fail('没收到文件');
        }

        // 用真实文件大小，不用 $_FILES['size'] —— 那个字段是客户端说了算的
        $bytes = (int) @filesize($path);
        if ($bytes <= 0) {
            return $fail('文件是空的');
        }
        if ($bytes > self::MAX_BYTES) {
            return $fail('图片不能超过 ' . (int) (self::MAX_BYTES / 1024) . ' KB，当前 '
                . (int) round($bytes / 1024) . ' KB');
        }

        /*
         * SVG 单独给一句。
         *
         * 它是唯一一个"看起来是图片、但我们不收"的格式（getimagesize 不支持
         * SVG，会落到下面那句笼统的提示里）。只说"不是能识别的图片"，管理员
         * 会以为是文件坏了，然后把同一张 SVG 再传一次。
         */
        $head = (string) @file_get_contents($path, false, null, 0, 1024);
        if (stripos($head, '<svg') !== false) {
            return $fail('不支持 SVG：它可以内嵌 <script>，而这个文件会被同源地发出去，'
                . '等于开了一个存储型 XSS 的口子。请改用 PNG。');
        }

        /*
         * 用 getimagesize 而不是信任扩展名或 Content-Type：
         * 后两者都是客户端说了算的，只有真正解析一遍文件头才知道它是不是图。
         */
        $info = @getimagesize($path);
        if (!is_array($info) || empty($info['mime'])) {
            return $fail('这不是一张能识别的图片（支持 PNG / JPG / GIF / WebP）');
        }

        $mime = (string) $info['mime'];
        $ext = array_search($mime, self::TYPES, true);
        if ($ext === false) {
            return $fail('不支持的图片格式：' . $mime . '。请用 PNG / JPG / GIF / WebP');
        }

        $w = (int) $info[0];
        $h = (int) $info[1];
        if ($w <= 0 || $h <= 0) {
            return $fail('图片尺寸读不出来，可能已损坏');
        }
        if ($w > self::MAX_PIXELS || $h > self::MAX_PIXELS) {
            return $fail('图片太大了（' . $w . '×' . $h . '），请先缩到 ' . self::MAX_PIXELS . 'px 以内');
        }

        return ['ok' => true, 'error' => '', 'ext' => $ext, 'mime' => $mime, 'width' => $w, 'height' => $h];
    }

    /**
     * 保存一个槽位的图片。
     *
     * @param array<string,mixed> $file $_FILES 里的单项
     * @return array{ok:bool,error:string}
     */
    public static function store(string $slot, $file): array
    {
        if (!in_array($slot, ['favicon', 'logo'], true)) {
            return ['ok' => false, 'error' => '未知的槽位'];
        }

        if (!is_array($file)) {
            return ['ok' => false, 'error' => '没有收到文件'];
        }

        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => self::uploadErrorText($code)];
        }

        // 只有这一句依赖"真的是一次 HTTP 上传"，其余校验都在 inspect() 里
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => '上传校验失败，请重试'];
        }

        $check = self::inspect($tmp);
        if (empty($check['ok'])) {
            return ['ok' => false, 'error' => (string) $check['error']];
        }
        $ext = (string) $check['ext'];

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => '创建 public/brand 目录失败，检查 public/ 是否可写'];
        }

        // 先清掉同槽位的其它扩展名，避免"换了格式但旧的还在，读到的还是旧的那张"
        self::remove($slot);

        $target = $dir . '/' . $slot . '.' . $ext;
        if (!@move_uploaded_file($tmp, $target)) {
            return ['ok' => false, 'error' => '保存失败，检查 public/brand 目录权限（应归 web 用户所有）'];
        }
        @chmod($target, 0644);

        self::$memo = [];
        Clearstatcache();

        return ['ok' => true, 'error' => ''];
    }

    /** 删除某个槽位的图片（退回内置默认） */
    public static function remove(string $slot): void
    {
        $dir = self::dir();
        foreach (array_keys(self::TYPES) as $ext) {
            $path = $dir . '/' . $slot . '.' . $ext;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        self::$memo = [];
        Clearstatcache();
    }

    /** 把整张图读出来，给邮件做内嵌附件用 */
    public static function bytes(string $slot): ?array
    {
        $info = self::get($slot);
        if ($info === null) {
            return null;
        }
        $data = @file_get_contents($info['path']);
        if ($data === false || $data === '') {
            return null;
        }

        return ['mime' => $info['mime'], 'data' => $data, 'name' => $info['file']];
    }

    /**
     * favicon 的 <link> 标签。没上传过就退回内置那个方块 emoji。
     *
     * 内置版是个 inline SVG，好处是零请求；上传版走静态文件，带 mtime 防缓存。
     */
    public static function faviconTag(): string
    {
        $icon = self::get('favicon');
        if ($icon === null) {
            return '<link rel="icon" href="data:image/svg+xml,'
                . "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'>"
                . "<text y='.9em' font-size='90'>🧱</text></svg>\">";
        }

        return '<link rel="icon" type="' . e($icon['mime']) . '" href="' . e($icon['url']) . '">'
            . "\n" . '<link rel="apple-touch-icon" href="' . e($icon['url']) . '">';
    }

    /**
     * 页面标题左边那个方块图标（.brand-mark 的内容）。
     *
     * 和 favicon 共用同一张图：管理员传一次，浏览器标签页和页面标题左边一起变。
     * 分成两个槽位才会出怪事 —— 传两遍、还可能传成两张不一样的图，而用户心里
     * 这就是"这个站点的图标"一件事。
     *
     * 没传过就退回内置的 ⛏（和 faviconTag() 的内置方块是同一套零请求思路）。
     */
    public static function markTag(): string
    {
        $icon = self::get('favicon');
        if ($icon === null) {
            return '⛏';
        }

        // alt 留空：右边紧跟着站点名，读屏软件再念一遍图标里的字是噪音。
        return '<img src="' . e($icon['url']) . '" alt="" width="38" height="38" decoding="async">';
    }
}
