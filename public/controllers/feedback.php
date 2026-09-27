<?php
/**
 * 玩家侧页面控制器：?r=feedback&t=<签名令牌>
 *
 * 三种打开方式：
 *   1. 服务器级公开链接（?t=share 令牌）—— 群里发的通用入口，任何玩家都能提交
 *   2. 工单链接（?t=工单令牌）—— 某条反馈的专属链接，打开就是这条工单的状态
 *   3. 直接访问首页 —— 也能提交，只是要手选服务器
 */

declare(strict_types=1);

use MCFix\Catalog;
use MCFix\Config;
use MCFix\Share;
use MCFix\Token;
use MCFix\Workflow;

$token = param($_GET, 't', param($_GET, 'token'));
$notice = '';
$share = null;
$feedback = null;

/*
 * 玩家侧会话必须**显式**起，而且要和 api.php 用同一个会话名与作用域。
 *
 * 下面有两处依赖它，而它们以前是**静默失效**的：
 *   - 写入 $_SESSION['share_locked_server']（专属链接锁定服务器，见下）；
 *   - 渲染表单时读 $_SESSION['my_email']（回填玩家上次填过的邮箱）。
 * PHP 不会因为"给 $_SESSION 赋值"就自动开会话 —— 没有活动会话时那个数组
 * 只存在于本次请求里，既不落盘也不报错。于是提交时读到的一直是空串，
 * 防线看着在、其实没接上。
 *
 * 它只承载"同一浏览器里记住的东西"，不含任何身份信息；真正校验分享令牌的
 * 仍然是 HMAC 签名，不依赖会话。
 */
if (PHP_SAPI !== 'cli' && function_exists('mcfix_start_session')) {
    mcfix_start_session('mcfix_sid', \MCFix\ConsoleAuth::scope());
}

if ($token !== '') {
    $share = Share::parse($token);
}

if ($token === '' && $share === null) {
    // 没有令牌：也能用。若只有一台服务器就直接进表单，避免把玩家挡在门外
    $all = Config::servers();
    if (count($all) === 1) {
        $only = reset($all);
        if (is_array($only)) {
            $share = ['server' => $only, 'server_id' => (string) $only['id'], 'expires' => 0];
            $notice = '这是「' . (string) $only['name'] . '」的公开反馈入口。';
        }
    } else {
        $notice = '你是直接打开首页的。如果管理员给了你专属反馈链接，请使用那条链接（带 ?t=… 参数）；'
            . '下面的表单也可以直接提交，请手动选择出问题的服务器。';
    }
} elseif ($share !== null) {
    $notice = '这是「' . (string) $share['server']['name'] . '」的公开反馈入口，提交后会立刻开始验证。';
} else {
    $authorized = Workflow::authorize($token);

    if ($authorized['feedback'] === null) {
        render_layout([
            'title'    => '链接无效 · ' . (string) Config::get('app.name', 'MC 故障反馈中心'),
            'siteName' => (string) Config::get('app.name', 'MC 故障反馈中心'),
            'headNav'  => [['label' => '返回首页', 'href' => url(''), 'active' => false]],
        ], function () use ($authorized): void {
            ?>
            <div class="card empty-state">
              <h2>😕 这条反馈链接不可用了</h2>
              <p><?= e((string) $authorized['error']) ?></p>
              <p class="hint">请回到群里找管理员重新生成一条反馈链接，或直接访问首页手动提交。</p>
              <a class="btn btn-primary" href="<?= e(url('')) ?>">去首页提交反馈</a>
            </div>
            <?php
        });
        exit;
    }

    $feedback = $authorized['feedback'];

    if (in_array((string) $feedback['status'], ['resolved', 'closed', 'rejected'], true)) {
        // 已结束的工单：直接展示进度，避免重复诊断
        $progress = Workflow::progress($feedback);
        render_layout([
            'title'    => '工单 ' . $progress['ticket_no'] . ' · ' . (string) Config::get('app.name'),
            'siteName' => (string) Config::get('app.name', 'MC 故障反馈中心'),
            'headNav'  => [['label' => '返回首页', 'href' => url(''), 'active' => false]],
        ], function () use ($progress, $feedback): void {
            require MCFIX_ROOT . '/views/player-ticket.php';
        });
        exit;
    }
}

$servers = Config::servers();
$categories = Catalog::categories();

/*
 * 用某台服务器的专属链接进来时，把表单**锁在这台服**上。
 *
 * 为什么：链接的语义是"这是 A 服的反馈入口"。以前只是把 A 服设为下拉框的默认值，
 * 而选项里仍然列着 B 服、C 服，提交时后端也只看表单里的 server_id —— 于是任何
 * 拿到链接的人都能把服务器改成 B 服，系统就真去连 B 服做诊断、甚至执行修复，
 * 消耗的是 B 服的风控配额，工单也记到 B 服名下。服主查后台会以为 B 服出问题了。
 *
 * 现在前端只渲染这一台（外加"不涉及服务器"），后端再独立校验一次
 * （见 api.php 的 submit 分支与 Workflow::submit 的 $lockedServerId）。
 *
 * $lockedServerId 为空 = 直接访问首页，没有链接约束，照旧列出全部服务器。
 */
$lockedServerId = ($share !== null && (string) ($share['server_id'] ?? '') !== '')
    ? (string) $share['server_id']
    : '';

if ($lockedServerId !== '' && isset($servers[$lockedServerId])) {
    // 只留这一台。注意必须按原键名保留，后续 $sid 就是它。
    $servers = [$lockedServerId => $servers[$lockedServerId]];
}

/*
 * 把"这个会话是从哪台服的链接进来的"记到 session 里。
 *
 * 为什么需要：光靠提交时带令牌挡不住一种绕法 —— 拿着 A 服链接的人，手工构一个
 * POST、**故意不带** share_token，server_id 写成 B 服。后端那时没有任何依据
 * 区分他和"从首页手选 B 服"的正常玩家，只能采信表单。
 *
 * 记在 session 里之后就有依据了：这个会话是从 A 服链接进来的，那它提交时
 * 就不该出现 A 服以外的服务器（空值除外 —— 纯客户端问题仍然要允许）。
 *
 * 只在带令牌访问时写入，所以"直接开首页"的会话不会有这个约束，手选照旧。
 * 换一台服的链接进来会覆盖成新的那台。
 */
if ($lockedServerId !== '' && isset($servers[$lockedServerId])) {
    $_SESSION['share_locked_server'] = $lockedServerId;
}

$prefill = [
    'player_name' => (string) ($feedback['player_name'] ?? ''),
    'server_id'   => (string) ($feedback['server_id'] ?? ($share['server_id'] ?? '')),
    // 上次在这台浏览器上填过的邮箱，省得每次重打（只存在他自己的会话里）
    'player_email'=> (string) ($_SESSION['my_email'] ?? ''),
];

render_layout([
    'title'    => (string) Config::get('app.name', 'MC 服务器故障反馈中心'),
    'siteName' => (string) Config::get('app.name', 'MC 服务器故障反馈中心'),
    // 玩家侧不出现任何指向后台的链接 —— 后台入口是独立的私有路径
    'headNav'  => [],
], function () use ($servers, $categories, $prefill, $notice, $feedback, $lockedServerId): void {
    require MCFIX_ROOT . '/views/player-form.php';

    if ($feedback !== null) {
        // 先把已有工单信息塞给前端，玩家点按钮就是"继续这条工单的验证"
        $bootstrap = [
            'ticket'       => (string) $feedback['ticket_no'],
            'id'           => (int) $feedback['id'],
            'share_url'    => Token::feedbackUrl($feedback),
            'verify_token' => Token::forVerify($feedback),
            'player_name'  => (string) $feedback['player_name'],
            'server_id'    => (string) $feedback['server_id'],
            'category'     => (string) $feedback['category'],
        ];
        // JSON_HEX_TAG 等标志不能省：这段 JSON 是直接塞进 <script> 里的，
        // 一旦哪个字段将来能带上 "</script>"，页面结构就会被提前截断。
        // 现在 player_name 有严格白名单，但标志留着不花什么代价。
        echo '<script>window.MCFIX_BOOTSTRAP = ' . json_encode(
            $bootstrap,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) . ';</script>';
    }

    /*
     * 把"锁定在哪台服"和"分享令牌"给前端。
     *
     * 令牌要带的原因是：提交时后端要拿它重新解出 server_id 做校验。
     * 只靠前端禁用下拉框挡不住绕行 —— 直接 POST 一个改过的 server_id 就绕过去了。
     * 令牌本身是 HMAC 签名的，前端拿不到伪造能力，所以可以安全地交给它回传。
     */
    if ($lockedServerId !== '') {
        echo '<script>window.MCFIX_LOCKED_SERVER = ' . json_encode(
            ['server_id' => $lockedServerId, 'token' => $token],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) . ';</script>';
    }
});
