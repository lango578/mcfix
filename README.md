# ⛏ MCFix — Minecraft 服务器故障反馈与自动修复系统

[![License: Apache 2.0](https://img.shields.io/badge/License-Apache_2.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B%20%7C%208.x-777bb4.svg)](https://www.php.net/)
[![Dependencies](https://img.shields.io/badge/dependencies-0-brightgreen.svg)](#为什么是零依赖)
[![SQLite](https://img.shields.io/badge/database-SQLite-003b57.svg)](https://www.sqlite.org/)

> English: [README.en.md](README.en.md) · 中文（本文件）

> ### ⚠️ 安全提示
>
> **1.10.1 之前的版本有一个已修复的漏洞：匿名可达的邮件头注入。**
> 未登录的访客在提交反馈时，可以通过标题字段往管理员收到的通知邮件里注入任意邮件头
> （伪造 `Reply-To`、插入自定义头等）。**请使用 1.10.1 或更新版本。**
>
> 修复详情见 [CHANGELOG](CHANGELOG.md)。
> 仓库里的历史 tag `v1.9.0` 仍指向未修复的提交 —— 除非你在做考古，否则不要 checkout 它。

> 玩家在群里点一条链接反馈问题 → 系统**真的连一次服务器**做验证 → 能修的（白名单、误封、卡崩、插件报错）
> **当场自动修好** → 修完自动复验 → 结果通知到你的手机。

**给谁用**：个人服主、小团队服、面板服（MCSManager / 翼龙 / 宝塔），尤其是**没时间 7×24 盯群**的那种。

**部署形态**：反馈网站跑在宝塔面板（PHP + SQLite，上传即用）；MC 服务端在另一台机器上，
靠一个单文件 Agent 主动领任务 —— **不需要在 MC 机器上开放任何端口**。

> **先看这里**：这个项目能做什么、**不能**做什么，都写在下面的
> [现状与已知限制](#现状与已知限制) 里。尤其是"Agent 只支持 Linux"这一条，
> 先看完再决定要不要部署，能省掉很多来回。

---

## 文档与配置文件下载

### 文档

| 文档 | 内容 |
|---|---|
| [`deploy/宝塔部署指南.md`](deploy/宝塔部署指南.md) · [EN](deploy/BT-PANEL-DEPLOYMENT.en.md) | 从零部署：环境、Nginx、FPM、证书、计划任务、踩过的坑 |
| [`deploy/面板服接入指南.md`](deploy/面板服接入指南.md) · [EN](deploy/PANEL-SERVERS.en.md) | 面板服（MCSManager / 翼龙 / 宝塔 / 租用服）怎么接，能力对照与排查表 |
| [`deploy/主机商接入索引.md`](deploy/主机商接入索引.md) · [EN](deploy/HOSTS-INDEX.en.md) | **「我用 XXX 主机商，能不能接」** —— 已查证/待查证主机商矩阵，以及三分钟自己判断面板类型的办法 |
| [`deploy/BisectHosting接入指南.md`](deploy/BisectHosting接入指南.md) | BisectHosting 具体接入步骤（Pterodactyl 系主机商可当模板用） |
| [`deploy/简幻欢接入指南.md`](deploy/简幻欢接入指南.md) | 简幻欢（SimpFun / SFE4）具体接入步骤 —— 自研面板、无开放 API，RCON 与 Agent 各自的真实门槛 |
| [`deploy/邮件通知指南.md`](deploy/邮件通知指南.md) · [EN](deploy/EMAIL-NOTIFICATIONS.en.md) | 玩家回执 + 管理员提醒；为什么 VPS 上必须用 SMTP 而不是 Postfix |
| [`deploy/安全说明.md`](deploy/安全说明.md) · [EN](deploy/SECURITY-NOTES.en.md) | 威胁模型、边界、部署检查清单 |
| [`SECURITY.md`](SECURITY.md) | 安全策略与漏洞报告方式 |
| [`CHANGELOG.md`](CHANGELOG.md) | 每个版本改了什么、为什么改 |

### 可直接拿去用的配置文件

改掉域名和路径就能用：

| 文件 | 用途 |
|---|---|
| [`deploy/nginx.conf.example`](deploy/nginx.conf.example) | Nginx 站点配置：双域名、HTTPS 跳转、安全响应头、源码目录 deny |
| [`deploy/php-fpm-pool.conf.example`](deploy/php-fpm-pool.conf.example) | PHP-FPM 池（含宝塔 `www` vs `www-data` 导致 502 的坑） |
| [`deploy/mcfix-agent.service`](deploy/mcfix-agent.service) | Agent 的 systemd 单元，附最小权限 sudoers 配方 |
| [`deploy/crontab.example`](deploy/crontab.example) | 计划任务：例行维护、WAL 安全备份、证书续期 |
| [`config.example.php`](config.example.php) | 全部配置项的中文说明（`email` / `ai` / `panel` / `domains` / `admin` / `notify`） |
| [`deploy/install.sh`](deploy/install.sh) | 一键初始化：建目录、修权限、打印计划任务命令 |

### 程序文件

| 文件 | 用途 |
|---|---|
| [`agent/mcfix-agent.php`](agent/mcfix-agent.php) | MC 机器上的单文件 Agent（这就是你要上传到 MC 机器的东西） |
| [`bin/mcfix.php`](bin/mcfix.php) | 命令行工具：`doctor` / `servers` / `panel` / `link` / `diagnose` / `cron` / `reset-password` … |
| [`tests/run.php`](tests/run.php) | 158 项冒烟测试，零依赖：`php tests/run.php` |
| [`deploy/check-alt-syntax.js`](deploy/check-alt-syntax.js) · [`deploy/check-close-tag.js`](deploy/check-close-tag.js) | 离线结构检查（**不能**替代 `php -l`） |

> 整包下载：本仓库的 **Releases** 页面，或者直接 `git clone`。

---

## 界面长什么样

仓库里暂时没有截图（作者的环境不方便录屏，不想放伪造的示意图）。
三个页面用文字描述一遍，方便你先判断合不合用：

**玩家反馈页** —— 单页表单，深色主题。选服务器 → 填游戏 ID → 选问题类别
（进不去 / 崩了 / 卡顿 / 回档 / 被封 / 插件报错 / 举报）→ 描述 + 可选上传崩溃日志。
提交后同一页变成实时进度：一串体检项逐条从"检测中"变成 ✅/⚠️/❌，底下是给玩家看的人话结论。

**工单详情页** —— 体检明细、判定结论、系统做了什么修复、复验结果，
以及（如果上传过日志）客户端崩溃分析：缺失的类、可疑 MOD、Java/内存建议、可下载的 MOD 文件。

**管理后台** —— 左侧固定导航（工单 / 工单详情 / 服务器与 Agent / MOD 分发 / 通知设置 /
操作日志 / 系统设置 / 接入与排查），右边是内容区。所有自动动作在「操作日志」里逐条留痕。

---

## 它到底解决什么问题

### 玩家说"进不去"，八成不是你以为的那个原因

| 玩家说 | 系统真的会做的事 | 能自动修吗 |
|---|---|---|
| 进不去服 | 按 Minecraft 协议握手一次（等于玩家点"加入服务器"）+ 查白名单 / banlist + 读服务端日志 | ✅ 加入白名单、解封、重启 |
| 服务器崩了 | 查进程、端口监听、崩溃日志、磁盘余量 | ✅ 重启服务端（有配额风控） |
| 卡顿 / 掉帧 | RCON 取 TPS + 采样进程 CPU/内存 + 磁盘 + 日志 | ✅ 强制保存世界 / 重启 |
| 回档 / 丢物品 | 存档写入时间、区块异常、TPS | ⚠️ 保存世界 + 备份存档，数据本身人工处理 |
| 被封 / 被禁言 | RCON `banlist players`、白名单列表 | ✅ 解封、白名单加入 |
| 插件报错 | 从日志归集插件异常并统计次数 | ✅ 重载插件 / 重启 |
| 举报玩家 | 只收集信息，不做任何自动动作 | ❌ 转人工 |

**关键设计：玩家说得不一定准。** 他说"进不去"，真实原因可能是白名单里没他、被 ban 了、
端口还没起来，也可能服务端活得好好的只是他客户端版本不对。
**系统的第一件事是把"玩家描述"变成"客观事实"，再决定动不动手。**

### 客户端崩溃也能自动分析

这类问题占实际反馈的一大半，而且光靠文字描述根本定位不了。玩家可以直接上传崩溃报告：

```
玩家上传 crash-report / latest.log（或直接粘贴报错）
        ↓
ClientLog 解析器：读出游戏版本、加载器、Java、内存、MOD 列表、异常栈、缺失的类
        ↓
ClientAdvisor 知识库：40+ 条错误特征 → 人话结论 + 玩家自己该怎么点
        ↓
与服务端 MOD 清单交叉比对：多装了什么、少装了什么
        ↓
能自动解决的（白名单/解封/重启）交给 Fixer；只能在客户端解决的给精确步骤
        ↓
玩家缺的 MOD：Agent 从服务端 mods/ 目录取回来，玩家直接点下载（版本一定对）
```

能识别并给出解决办法的典型报错：

| 日志里出现 | 系统给出的结论 | 解决方式 |
|---|---|---|
| `NoClassDefFoundError` | 缺少某个类（MOD 没装全或版本不对） | 列出缺失的类名 |
| `Missing or unsupported mandatory dependencies` | 缺少前置库 | 抽出依赖名，列常见前置清单 |
| `MixinApplyError` | MOD 与游戏版本对不上 | 从 Mixin 报错点出可疑 MOD |
| `ModLoadingException` | MOD 加载阶段失败 | 点名可疑 MOD，提示排查服务端专用 MOD |
| `Incompatible mod set` | MOD 之间冲突 | 提示 OptiFine / Sodium 互斥等坑 |
| `OutOfMemoryError` | 内存不足 | 按版本分档给出内存与 Java 建议 |
| `UnsupportedClassVersionError` | Java 版本不对 | 从 class 版本号反推需要 Java 几 |
| Forge / NeoForge / Fabric 版本校验失败 | 加载器版本不一致 | 按加载器分别给改法 |
| `Connection refused` | 服务器端口没在监听 | **直接触发服务端重启**，告诉玩家等 1~2 分钟 |
| 白名单 / 封禁 / 满员提示 | 服务端拒绝了连接 | **自动加白名单 / 解封** |
| 服务端插件异常 | 不是玩家的问题 | 转管理员，**自动尝试重载插件** |
| OpenGL / GLFW 报错 | 显卡驱动或渲染环境 | 给驱动更新、双显卡指定、远程桌面限制等步骤 |
| 日志太短 / 没特征 | 信息不足 | 明确告诉玩家该传哪个文件（路径都写清楚） |

---

## 快速开始（宝塔面板，约 10 分钟）

### 1. 环境要求

| 项目 | 要求 |
|---|---|
| PHP | 7.4 ~ 8.3（推荐 8.1+）。代码里没有用到 8.0+ 语法 |
| 必须的扩展 | `pdo_sqlite` `mbstring` `json` |
| 建议的扩展 | `openssl`（生成/校验签名令牌，HTTPS 出网也要）、`curl`（没有它自动退回 stream 方式） |
| 数据库 | 不需要。用 SQLite，零配置 |
| Composer / npm | **不需要** |
| 操作系统 | 面板侧 Linux / Windows 都能跑；**Agent 只支持 Linux**（见下文） |

宝塔【软件商店 → PHP 设置 → 安装扩展】里勾上缺的扩展即可。
用 `local` / `ssh` 执行器时还需要在【禁用函数】里删掉 `proc_open`（用 `agent` 模式则不必）。

> `sqlite3` CLI 不是运行依赖，但**备份时要用它**（`sqlite3 ... ".backup ..."`），
> 因为数据库开了 WAL，直接 `cp` 可能拿到不一致的快照。宝塔上 `yum install sqlite` / `apt install sqlite3` 即可。

### 2. 上传并指向 public

```bash
cd /www/wwwroot
mkdir mcfix && cd mcfix
unzip -o ~/mcfix.zip          # 解压后应看到 public/ src/ agent/ bin/
bash deploy/install.sh        # 建运行时目录、修权限、环境自检
```

`deploy/install.sh` 只做四件事：建 `storage/{data,logs,cache,locks,ratelimit,keys,mods}`、
修权限、找一个可用的 php 命令行、打印计划任务命令。它**不写** `config/config.php`，
那一步留给浏览器里的安装向导。

> 脚本在还没安装时会调一次 `doctor`，那时会看到 "尚未完成安装" 并返回非 0 ——
> 这是预期行为，不是失败。

然后在宝塔【网站 → 站点设置 → 网站目录 → 运行目录】里选择 **`/public`**。

> ⚠️ **这一步不能省。** 源码、`config/config.php`（含签名密钥）与 SQLite 数据库都在
> 上一级目录，只有把网站根目录收进 `public/` 才能真正挡住它们。
> Nginx 不读 `.htaccess`，靠路径隔离而不是靠文件规则。

站点根目录指到 `public/` 之后，正确配置的 Nginx **不需要**任何额外的 deny 规则。
但如果你因为某些原因没法改运行目录，必须在 Nginx 里手写等价规则，例如：

```nginx
location ~ ^/(config|src|storage|admin|views|bin|agent|deploy)/ { deny all; }
location ~ /\.(?!well-known) { deny all; }
```

仓库里的 `.htaccess`（根目录 / `config/` / `storage/`）只对 Apache / LiteSpeed 生效，
Nginx 会直接忽略它们 —— 这一点经常被忽略，别把它当成防护。

### 3. 配好 Nginx 与 PHP-FPM

参考 [`deploy/宝塔部署指南.md`](deploy/宝塔部署指南.md)，里面有完整的 Nginx 配置、
php-fpm 池配置，以及**踩过的坑**（宝塔的 Nginx 以 `www` 用户运行，
FPM 池必须用同一个用户，否则 502）。

### 4. 跑安装向导

浏览器打开 `https://你的域名/?r=install`：

1. 环境自检
2. 设置管理员口令 + 站点地址（会同时随机生成后台入口）
3. （可选）添加第一台 MC 服务器

完成后页面会给你：

- ✅ **玩家反馈链接**（发到群里）
- ✅ **管理后台地址**（存进书签）
- ✅ Agent 的 `config.php` 内容
- ✅ systemd 部署命令

### 5. 部署 MC 侧 Agent（如果 MC 不在本机）

```bash
# 在 MC 机器上
mkdir -p /opt/mcfix
# 上传 agent/mcfix-agent.php 到 /opt/mcfix/
# 把安装向导生成的 config.php 也放进去

php /opt/mcfix/mcfix-agent.php --check      # 环境自检
php /opt/mcfix/mcfix-agent.php --once       # 跑一轮，确认能连上面板

# 常驻
cat > /etc/systemd/system/mcfix-agent.service <<'EOF'
[Unit]
Description=MC Fix Agent
After=network-online.target

[Service]
Type=simple
ExecStart=/usr/bin/php /opt/mcfix/mcfix-agent.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload && systemctl enable --now mcfix-agent
```

> **不想装 Agent 也行。** 配好 RCON 就能修白名单 / 解封 / 保存世界 / 重载插件；
> 再配上你面板的 API（MCSManager / 翼龙 / 宝塔）就能读日志做诊断。
> Agent 的价值在于**进程级动作**（重启、备份、读磁盘、取 MOD 文件）。

### 6. 加一条计划任务

宝塔【计划任务 → Shell 脚本】，**每分钟**：

```bash
cd /www/wwwroot/mcfix && /www/server/php/81/bin/php bin/mcfix.php cron
```

> **这里必须填 php 命令行可执行文件**（`/www/server/php/83/bin/php` 这种），
> 先在 SSH 里 `which php` 确认一下。网页里 `PHP_BINARY` 的值是
> `/www/server/php/83/sbin/php-fpm`，填进去只会起一个 FPM 进程，脚本根本不会跑。

它负责：回收卡住的任务、推进待复验工单、清理缓存、重试失败的通知、发每日汇总。

### 7. 验收

```bash
php bin/mcfix.php doctor          # 配置与环境自检
php bin/mcfix.php servers         # 服务器与 Agent 状态
php bin/mcfix.php test-feedback survival Steve "进不去服了"
```

最后一条会跑完整闭环（提交 → 验证 → 修复 → 复验）并打印每一步的真实结果。
**全绿就说明整条链路通了。**

---

## 整体架构

```
┌─────────────────────────┐  HTTPS   ┌──────────────────────────────┐
│  宝塔面板（本系统）        │ ◀─────── │   MC 服务器所在机器            │
│                         │  长轮询   │  mcfix-agent.php（单文件）     │
│  玩家反馈站               │  领任务   │   ├ 每 8 秒心跳 + 领任务        │
│  管理后台（独立域名）      │          │   ├ 执行诊断（进程/端口/日志）   │
│  SQLite + 诊断/修复引擎   │          │   └ 回报结果                   │
│                         │   RCON   │                              │
│                         │ ───────▶ │   Minecraft 服务端             │
│                         │  TCP     └──────────────────────────────┘
│                         │   HTTP   ┌──────────────────────────────┐
│  面板 API 执行器          │ ───────▶ │  MCSManager / 翼龙 / 宝塔      │
└─────────────────────────┘          └──────────────────────────────┘
```

### 六条执行通道，按能力自动选路

一份代码同时吃下"面板服"和"普通服"，靠的是**能力声明 + 自动降级**：

| 通道 | 能做什么 | 前提 |
|---|---|---|
| **RCON** | 发游戏指令：白名单、解封、解禁言、踢人、清背包、保存世界、重载插件 | 服务端开 `enable-rcon`；有回显，最可靠 |
| **面板 API** | 读日志、发指令、开关机、列目录、读文件、资源占用 | MCSManager / 翼龙 / 宝塔 / 自定义 HTTP |
| **Agent** | 全部：进程、端口、磁盘、CPU、日志、MOD 清单、重启 | MC 机器跑一个小程序，**不需要入站端口** |
| **SSH** | 同 Agent | 面板要能 SSH 到 MC 机器 |
| **local** | MC 与面板同机时直接执行 | `proc_open` 可用 |
| **none** | 只做面板直连探测（MC 协议 + RCON） | — |

选路规则写在 `src/Executor.php` 里，一眼能看懂：

```
诊断读日志     → 面板 API（能读就行，不用装东西）→ Agent → 说明为什么跳过
游戏指令       → RCON（有回显）→ 面板控制台
进程开关机     → RCON stop + 守护重启 → 面板 power → Agent
进程/端口/磁盘  → Agent / SSH / local（面板 API 拿不到这些）
```

**为什么是"Agent 主动领任务"而不是"面板 SSH 进去"？**

1. MC 机器通常在家宽 / NAT 后面，面板连不进去；反过来 MC 机器能访问面板就行
2. 不用在 MC 机器上多开端口，不用配 SSH 密钥、不用给面板 root 权限
3. 最关键的：**修复动作是面板侧的白名单配方**。Agent 只认固定 code，
   永远不接受下发的任意 shell 命令（见 [SECURITY.md](SECURITY.md)）

### 两个完全独立的入口

| | 玩家反馈站 | 管理后台 |
|---|---|---|
| 入口 | `https://fankui.example.com/` | `https://mc.example.com/`（推荐独立域名） |
| 会话 | 只记"这台浏览器提交过哪些工单"，不承载身份 | 独立会话 `mcfix_console` |
| CSRF | 不需要（无身份、无写权限） | 后台自己一套，所有写操作强制校验 |
| 页面互链 | **没有任何指向后台的链接** | — |
| 访问控制 | 每 IP 限流 + 签名令牌 | IP 白名单 + 失败锁定 + 路径保密 |
| 被扫时 | 正常反馈页 | 路径不对一律 404 |

后台支持两种部署形态：**独立域名**（推荐，同源策略天然隔离）或
**同域名 + 随机私有路径**（`domains.console_host` 留空即退回此模式）。

---

## 为什么是零依赖

整个项目**不引入任何第三方包**：没有 Composer，没有 npm，没有框架，没有 ORM。
只用 PHP 标准库和扩展。

代价是有些轮子自己造：手写 Source RCON 协议客户端、手写 Minecraft Server List Ping、
手写各平台 webhook 签名。

收益是**部署真的是"上传解压就能跑"**：

- 不用 `composer install`（很多宝塔用户根本没有 Composer）
- 不怕依赖供应链投毒
- 五年后这个项目还能跑，不会因为某个包被删除而失效
- 代码量可控（约 2 万行 PHP），能通读

---

## 支持的平台

### Minecraft 服务端

Paper / Spigot / Purpur / CraftBukkit / Folia / Vanilla / Forge / NeoForge /
Fabric / Quilt / Velocity / BungeeCord / Waterfall

### 托管面板

| 面板 | 适配程度 |
|---|---|
| **MCSManager** | 发指令、开关机、读日志、列目录、读文件 |
| **翼龙 / Pterodactyl**（含 Pelican） | 发指令、开关机、读日志、列目录、读文件、资源占用 |
| **宝塔面板** | 读日志、列目录、进程启停（发游戏指令走 RCON） |
| 简幻欢 / 雨云等租用服 | 有 HTTP 接口时用「自定义接口」适配器；否则用 RCON |
| 自建 / 独立主机 | 装 Agent 拿到全部能力 |

> **MC 跑在面板里（租用服、MCSManager、翼龙、宝塔）？** 看
> **[`deploy/面板服接入指南.md`](deploy/面板服接入指南.md)** ——
> 那份文档专门讲"不装 Agent 能做到哪一步、每个面板怎么填、哪里会卡住"，
> 以及面板服最容易踩的几个坑（地址填错被判成服务器离线、RCON 端口不通、接口路径版本差异）。

> 面板 API 的路径在不同版本间偶有差异。所有适配器都支持在后台覆盖接口路径，
> 并且**失败时会把具体是哪个接口、什么错误说清楚**，而不是静默失败。
> 排障用 `php bin/mcfix.php panel <server_id>`。

### 通知渠道

钉钉 · 企业微信 · 飞书 / Lark · 邮件 · Bark · ntfy · Server 酱 · Telegram ·
Discord · 通用 Webhook

> **想让玩家收到结果、自己收到提醒，不用配渠道。**
> 后台「系统设置 → 邮件通知」里勾一下开关、填一个管理员邮箱就行：
> 玩家提交后自动发一封「已收到」，修好或转人工时再发一封结果；
> 你自己的邮箱每条新反馈都会收到提醒。详见下面的[邮件通知](#邮件通知)。

---

## 它用不用大模型？

**不用。这个系统不依赖任何 AI 服务。**

所有判断都来自写死的规则，你可以直接翻代码看它为什么这么判：

| 来源 | 规模 |
|---|---|
| 客户端问题知识库 `src/ClientIssue.php` | 37 条 |
| 日志特征正则 `src/ClientLog.php` | 51 条 |
| 服务端检查项 `src/Catalog.php` | 23 项 |
| 判定分支 `src/Verdict.php` | 14 条 |

整个系统会主动连出去的地方只有六个，**全都是你自己的东西**：
面板 API、你配的通知 webhook、Agent→面板、MC 协议握手、RCON、本机 `mail()`。没有第七个。

这么设计是有意的：不用 API Key、不花钱、**离线也能修**、
**玩家日志不出你的机器**、同一个报错永远给同一个答案。

### 但可以可选地接一个（默认关闭）

规则库**没命中**的时候会多问一次大模型，分两条路：

| 场景 | 触发条件 | 模型能做什么 |
|---|---|---|
| **客户端报错** | 玩家传了崩溃日志、但 37 条规则一条都没命中 | 给出人话结论和玩家自己能做的步骤；**不能**触发任何动作 |
| **服务器故障** | 服务端诊断跑完、但一条问题都没分类出来 | 从**当前服务器可执行的配方清单**里挑一个；挑不中或挑了清单外的就转人工 |

两种情况都是"规则库先跑，跑不出结论才轮到模型"。常见报错根本不会走模型。

用的是 **OpenAI 兼容接口**，所以本地和第三方是同一套配置，只换地址：

```php
'ai' => [
    'enabled'  => true,
    'base_url' => 'https://api.deepseek.com',   // 或 http://127.0.0.1:11434/v1（Ollama）
    'api_key'  => 'sk-...',
    'model'    => 'deepseek-chat',
],
```

配上之后有六条硬边界（写在 `src/AiAdvisor.php` 里，有测试盯着）：

1. **客户端侧：模型只出结论，不出命令。** 它给的修复建议**永远不会被执行** ——
   唯一能让系统动手的路径是它点出了知识库里已有的 code，那时用的是我们自己审过的配方
2. **服务器侧：模型只能从白名单里"挑一个"，不能自己造。** 诊断没命中规则库时，
   我们会递给它一份**当前这台服务器真能执行的配方清单**，它返回的编号
   必须先在清单里查到 —— 查不到就整条丢弃，跟没问过一样
3. **挑中了也不算数**：那个编号还要再走一遍完整闸门
   （配方白名单 → 这台服务器是否允许 → 参数来源 → 审批判定）。
   参数只认两种来源：`player` 取自工单、`reason` 用固定文案，
   出现任何别的参数名就直接放弃该动作
4. **发出去之前先脱敏**：玩家名、用户目录、IP、邮箱、启动器令牌全部换成占位符
5. **超时 6 秒就降级**回"转人工"，绝不让模型卡住工单。
   按日志特征缓存 7 天，同一类报错只问一次
6. **有闸**：每来源每小时 5 次、全局 60 次，防账单失控

> **服务器侧还有两条动作被硬性排除**，因为挑错的后果不是"没修好"而是"修坏了"
> 或"开了不该开的口子"：
>
> - `unban_player`（解除封禁）—— 否则被封禁的玩家提交一张工单，就可能诱导模型把自己解封
> - `clear_self_items`（清空背包）—— 误判等于直接毁掉玩家数据
>
> 这两条在规则库那条路上仍然可用（那是确定性的判断），只是**模型碰不到**。

> 建议先不开，跑一段时间看「客户端问题」页里有多少条是 `unknown`。
> 很少就说明规则库够用，没必要花这个钱。

---

## 数据留存

玩家上传的崩溃报告里含 **Windows 用户名**（`C:\Users\<名字>\AppData\...`）、显卡型号、
游戏 ID、服务器地址、完整 MOD 列表 —— 这些属于个人信息，不该无限期留着。

| 数据 | 什么时候清 | 清成什么 |
|---|---|---|
| 玩家邮箱 | 工单有结论时 | 换成掩码 `ab***@qq.com`（`purge_email`，默认开）|
| **日志原文** | 工单归档满 `log_retention_days` 天 | `client_log` / `client_log_name` 置空，**诊断结论、MOD 列表、分析结果全部保留** |

```php
'feedback' => [
    'autoclose_days'     => 7,   // 老数据里的终态工单几天后收敛成"已关闭"
    'log_retention_days' => 30,  // 归档再满几天清掉日志原文；0 = 不清理
],
```

**后台也能改**：「系统设置 → 玩家反馈 → 日志原文留存（天，0=不删）」。
那一页还会显示"当前库里 N 条还留着日志原文，已清理 M 条"，
「维护工具」里也有一个**清理日志原文**按钮可以立刻跑一次，不用等计划任务。

清理由 `php bin/mcfix.php cron` 执行，**只动终态工单（`已关闭` / `已解决` / `已驳回`）** ——
进行中的工单可能还要重新分析，原文删了就分析不了。每清一条会记一条
`log.purged` 事件，玩家在工单页也能看到"原文已按留存设置清理"。

> 早期版本只认 `status = 'closed'`，理由是"resolved 可能还要重新分析"。
> 但除了每天一次的 cron，没有任何东西会把 `resolved` 推成 `closed` ——
> 于是这些工单的原文实际上**永远清不掉**。现在三个终态一视同仁。

> 如果你需要长期保留日志原文做统计（比如分析哪类崩溃最多），把
> `log_retention_days` 设成 `0` 关掉它 —— 但请先确认你所在地区的合规要求。
> 分析结论不受影响，关掉留存不影响任何统计口径之外的用法。

---

## 邮件通知

玩家在反馈页可以留一个邮箱（选填）。留了的话：

1. 提交成功 → 自动发一封「已经收到你的反馈，正在检测」
2. 工单有结论 → 再发一封「修好了 / 需要人工处理」，附上体检结果和**他需要自己做的事**
3. 工单结束 → 库里的邮箱被换成掩码（`ab***@qq.com`），队列里的明文地址发出去后也立刻清掉

你自己那边：填一个管理员邮箱，每收到一条新反馈都会收到提醒 ——
不用建通知渠道、不用勾事件订阅，一个开关就够。

配置在后台「**系统设置 → 邮件通知**」，也可以写在 `config.php` 的 `email` 段：

```php
'email' => [
    'enabled'       => true,
    'admin_to'      => 'you@example.com',   // 收新工单提醒
    'from'          => '',                   // 留空自动用 no-reply@你的域名
    'notify_player' => true,
    'notify_admin'  => true,
    'purge_email'   => true,                 // 工单结束后抹掉玩家邮箱
],
```

**发送是异步的**：提交接口只入队，由 `php bin/mcfix.php cron` 每分钟发一批，
失败按 1/3/10/30 分钟退避重试。**所以计划任务一定要配好**，否则邮件会一直堆在队列里。

### 关键：VPS 上必须用 SMTP，不能靠 mail()

很多人第一反应是"装个 Postfix 就行"。**在 VPS 上这条路走不通：**

```
直连 mx3.qq.com:25          → 连不上        ← 服务商封了出网 25 端口
smtp.qq.com:465 / :587      → 通
```

PHP 的 `mail()` 干的事情是"交给本机 MTA 直连对方 25 端口投递"。
25 端口被封 = 一封都发不出去，Postfix 只会把邮件堆在队列里，
看界面上"装好了"，实际永远发不出去。（就算 25 通，VPS 的 IP 没有 SPF/DKIM/反向解析，
QQ、163 基本也会判垃圾邮件。）

**正确做法**：填一个真实邮箱账号 + 它的授权码，登录邮箱服务商投递。

后台「系统设置 → 邮件通知 → 发送方式」里勾上「用 SMTP 发信」，然后：

| 邮箱 | SMTP 服务器 | 端口 / 加密 | 密码填什么 |
|---|---|---|---|
| QQ 邮箱 | `smtp.qq.com` | 465 / SSL | **16 位授权码**（不是 QQ 密码） |
| QQ 企业邮 | `smtp.exmail.qq.com` | 465 / SSL | 邮箱登录密码 |
| 163 / 126 | `smtp.163.com` | 465 / SSL | **授权码** |
| Gmail | `smtp.gmail.com` | 587 / STARTTLS | **应用专用密码** |
| 阿里云邮 | `smtp.mxhichina.com` | 465 / SSL | 邮箱登录密码 |

> **QQ 邮箱授权码怎么拿**：网页版 QQ 邮箱 → 设置 → 账户 → 找到「IMAP/SMTP服务」
> → 开启 → 按提示发一条短信 → 得到 16 位授权码。只显示一次，复制下来填进去。

对应的 `config.php` 配置：

```php
'email' => [
    'enabled'  => true,
    'admin_to' => 'you@example.com',
    'smtp' => [
        'enabled'    => true,
        'host'       => 'smtp.qq.com',
        'port'       => 465,
        'encryption' => 'ssl',            // ssl(465) | tls(587) | none
        'username'   => 'youremail@qq.com',
        'password'   => '你的16位授权码',
        // QQ/163 要求发件地址 == 登录账号，默认自动对齐，不用管
        'force_from_username' => true,
    ],
],
```

填完**先保存**，再点「测试邮件」发一封验证。失败时它会告诉你服务器原话：

| 提示 | 原因 |
|---|---|
| `535` + 「要填授权码」 | 填成登录密码了。去邮箱设置里生成授权码 |
| `550` + 「收件人被拒绝」 | 收件地址写错了 |
| 「连不上 host:port」 | 主机名/端口填错，或者服务商封了这个端口 |
| 「服务器不支持 STARTTLS」 | 端口和加密方式对不上，465 用 SSL、587 用 STARTTLS |
| 队列一直 `queued` | **计划任务没在跑** |
| 状态 `sent` 但对方没收到 | 判垃圾邮件了。用真实邮箱账号发、别用 `no-reply@` 之类的假域名 |

> 家宽/公司自建、25 端口没被封的环境仍然可以走 `mail()` + Postfix，
> 那条通路保留了；不填 SMTP 就自动回退到它。

---

## 安全设计

核心不变量：**面板被攻破 ≠ MC 机器被攻破。**

Agent 只执行固定白名单里的配方，代码里**没有任何"执行面板传来的任意命令"的路径**。
面板下发的 `recipe` 若不是白名单内的 code，Agent 直接拒绝。

```
whitelist_add  unban_player  unmute_player  kick_player  clear_self_items
save_world     reload_plugins  restart_server  backup_world  monitor_server  pull_mod
```

**关于 `unmute_player`**：原版 Minecraft 没有禁言功能，禁言来自 Essentials 之类的插件，
所以这条配方实际下发的是 `unmute <玩家>` —— 服务端没装对应插件时会失败并如实报错。
`monitor_server` 是一个**只读体检**配方，可以人工触发，系统不会定时调它、也不会因为它的结果自动重启。

详细的安全边界、威胁模型与部署清单见 **[SECURITY.md](SECURITY.md)** 与
[`deploy/安全说明.md`](deploy/安全说明.md)。

---

## 目录结构

```
public/                    网站根目录（运行目录指向这里）
├── index.php              唯一入口 + 路由（含双域名分流）
├── assets/app.css|app.js  前端资源
└── controllers/           feedback / admin / api / agent / mod / install
src/                       核心类（自动加载，零依赖）
├── Config.php             配置读写与自检
├── Db.php                 SQLite 数据层（幂等建表）
├── ConsoleAuth.php        后台认证、会话、域名分流、IP 白名单
├── Net.php                Minecraft 协议握手 / TCP 探测
├── Rcon.php               RCON 客户端（含断线重连）
├── Executor.php           执行器路由（rcon / 面板 API / agent / ssh / local）
├── Panel/                 面板适配器
├── Catalog.php            服务端问题分类与检查项
├── Diagnosis.php          诊断引擎（自动验证）
├── Verdict.php            判定器（检查结果 → 结论 + 修复建议）
├── ServerLog.php          服务端日志分析（面板与 Agent 共用）
├── ClientLog.php          客户端日志解析
├── ClientAdvisor.php      客户端判定器
├── ClientIssue.php        客户端问题知识库（40+ 条）
├── ServerMods.php         服务端 MOD 清单与交叉比对
├── ModLibrary.php         MOD 文件库与签名下载
├── Notifier.php           通知引擎（去重 / 风暴保护 / 失败重试）
├── Channel/               通知渠道适配器
├── Recipe.php             修复配方白名单（安全核心）
├── Workflow.php           业务编排（提交→验证→修复→复验→通知）
├── Task.php               任务队列（长轮询租约）
└── Token.php / Share.php  签名令牌
admin/                     后台页面
views/                     玩家侧模板
agent/mcfix-agent.php      MC 侧 Agent（单文件，独立部署）
bin/mcfix.php              命令行工具
deploy/                    部署脚本与文档
storage/                   运行时数据（数据库 / 日志 / 缓存 / 限流 / 密钥 / MOD 库）
config/                    安装向导生成 config.php（权限 640）
tests/run.php              不需要 PHPUnit 的冒烟测试（纯 PHP 断言）
.github/                   Issue 模板 + CI（php -l 全量语法检查，7.4 与 8.3 两个版本）
```

---

## 命令行工具

```bash
php bin/mcfix.php doctor          # 配置与环境自检
php bin/mcfix.php servers         # 服务器与 Agent 状态、可用通道
php bin/mcfix.php link survival   # 生成某台服务器的玩家链接
php bin/mcfix.php diagnose survival Steve cannot_join   # 直接诊断，不建工单
php bin/mcfix.php tickets manual  # 看待人工的工单
php bin/mcfix.php test-feedback survival Steve "进不去服了"  # 跑完整闭环
php bin/mcfix.php cron            # 例行维护（计划任务用）
php bin/mcfix.php rotate-all      # 轮换全部公开链接密钥
php bin/mcfix.php reset-password  # 重设后台口令（忘记密码只能走这里）
```

**忘记后台口令**：不要删 `config/config.php` 里的 `admin_password`。
安装向导在配置文件存在之后**永久关闭了写密码的分支**（这条闸门本身就是安全设计，
详见 [SECURITY.md](SECURITY.md)）。用上面的 `reset-password` 重设，它会重新算哈希，
让所有已登录设备的会话立刻失效。

---

## 自动化风控

| 开关 | 默认 | 说明 |
|---|---|---|
| 允许自动执行修复 | 开 | 关掉后只验证不改动，全部转人工 |
| 重启服务端需要管理员批准 | **开** | 想做到"玩家点一下就自动重启"，把这个关掉 |
| 单工单最大自动修复次数 | 2 | 超过转人工，避免反复折腾服务端 |
| 修复后复验等待 | 5s | 白名单 / 解封这类动作 |
| 重启类复验等待 | 25s | 服务端重启需要时间 |
| 连续失败熔断阈值 | 3 | 同服连续失败 3 次后自动降级为"仅诊断" |
| 每小时最多自动重启 | 3 | 每台服务器独立配额 |
| 每 IP 每小时提交上限 | 5 | 防刷 |
| 通知风暴保护 | 10 分钟 3 条 | 服务端反复重启时不会刷爆你的群 |

**建议的渐进路线**：

1. 第一周：保留"重启需批准"，观察诊断准确率
2. 第二周：确认 Agent 稳定后，关闭"重启需批准"
3. 若被恶意刷：调低提交上限、缩短链接有效期、或给特定服务器关掉 `restart_server`

---

## 常见问题

<details>
<summary>后台登录后什么都没发生 / 一直回到登录页</summary>

最可能是 **cookie 作用域与真实请求路径不匹配**（这是本项目修过的一个真实 bug）。

如果你改过部署结构（比如把站点放到子目录、改了运行目录），确认：

- `app.base_url` 与真实访问地址一致
- 用 `bin/mcfix.php doctor` 看有没有配置问题

浏览器 F12 → Application → Cookies，确认登录后确实收到了会话 cookie。
</details>

<details>
<summary>502 Bad Gateway</summary>

**Nginx 与 PHP-FPM 的用户不一致。** 宝塔的 Nginx 以 `www` 运行，
如果你按 Ubuntu 惯例把 FPM 池配成 `www-data`，socket 权限就不匹配。

修法：FPM 池里的 `user` / `group` / `listen.owner` / `listen.group` 全部改成 `www`。
详见 [`deploy/宝塔部署指南.md`](deploy/宝塔部署指南.md)。
</details>

<details>
<summary>Agent 显示"从未上线"</summary>

1. MC 机器能访问面板吗：`curl -I 'https://你的反馈域名/?r=api.agent.poll'`
2. 令牌对不对：后台服务器卡片里的令牌必须和 MC 机器 `config.php` 里的 `token` 完全一致
3. 看 Agent 日志：`php /opt/mcfix/mcfix-agent.php --once --verbose`
4. 若打开了 `agent_ip_allow`（后台服务器配置里有这个输入框），确认 MC 机器出口 IP 在名单里
</details>

<details>
<summary>修复任务一直"待执行"</summary>

1. `systemctl status mcfix-agent` 看 Agent 是否在跑
2. 面板计划任务是否在跑（【操作日志】里能看到 cron 记录）
3. 检查计划任务里填的是不是 **php-fpm** —— 必须是 `bin/php`
4. 超过 3~5 分钟没执行，系统会**自动转人工**，不会永远卡在"修复中"
</details>

<details>
<summary>通知发了但群里没收到</summary>

先看后台【操作日志】里有没有 `notify.skip` 记录。有的话说明渠道本身不可用
（被关闭 / 类型未知 / 必填项没填），日志里会写明是哪一种。没有的话再按下面排查。

点后台【通知设置】里的「发送测试」，它会直接告诉你原因：

| 现象 | 原因 |
|---|---|
| 钉钉报 `310000` | 机器人的"关键词"安全设置没包含 `MCFix`，或改用加签方式 |
| 钉钉 / 飞书报签名错误 | 加签密钥错，或**服务器时间不准**（签名依赖时间戳） |
| Telegram `chat not found` | `chat_id` 不对，或机器人没被拉进群 |
| 邮件失败 | 宝塔没装邮件服务 → 【软件商店】装 Postfix |
| 全部超时 | 服务器出不了网，或被安全组拦了 |
| 提示证书校验失败 | 对方的 HTTPS 证书有问题。系统默认校验证书，不要为了绕过去把它关掉 |
</details>

<details>
<summary>邮件发不出去 / 玩家没收到</summary>

**先确认一件事：VPS 上用 `mail()` 是发不出去的。** 绝大多数服务商封了出网 25 端口，
装 Postfix 只会把邮件堆在队列里。**必须用 SMTP**（填邮箱账号 + 授权码）——
见 [邮件通知](#邮件通知) 那一节。

后台「系统设置 → 邮件通知」卡片上会显示队列状态和**最近一次失败原因**。按这个顺序查：

| 提示 | 原因 |
|---|---|
| `535` / 「要填授权码」 | 密码填成了登录密码。QQ/163 要用**授权码**（邮箱设置里生成） |
| `550` / 「收件人被拒绝」 | 收件地址写错了 |
| 「连不上 host:port」 | SMTP 主机名/端口填错，或服务商封了这个端口 |
| 「服务器不支持 STARTTLS」 | 端口和加密方式对不上：465 配 SSL、587 配 STARTTLS |
| 「mail() 返回失败」 | 没配 SMTP，系统回退到了 `mail()` —— 去把 SMTP 填上 |
| 队列里一直 `queued` | **计划任务没在跑**。`bin/mcfix.php cron` 每分钟一次是必须的 |
| 状态是 `sent` 但对方没收到 | 被判垃圾邮件了。用真实邮箱账号发，发件地址会被自动对齐到登录账号 |
| 玩家那条没发 | 玩家没填邮箱，或填的格式不对（格式不对提交时就会拦下来并提示） |

点「测试邮件」可以单独验证一次投递；点「催发邮件队列」可以不等 cron 立刻发一批。

</details>

<details>
<summary>数据怎么备份 / 迁移</summary>

**备份**用 SQLite 自己的 `.backup`，不要直接 `cp` —— 数据库开了 WAL，
热拷贝可能拿到不一致的快照（`-wal` / `-shm` 两个附属文件不会被一起复制）：

```bash
sqlite3 /www/wwwroot/mcfix/storage/data/mcfix.sqlite \
  ".backup '/www/backup/mcfix-$(date +%F).sqlite'"
```

**迁移**到新机器：装好环境 → 放回代码 → 放回备份出来的 `.sqlite` → 放回 `config/config.php`。
</details>

<details>
<summary>我自己改了源码，怎么保证没写出语法错误</summary>

```bash
find . -name '*.php' -not -path './storage/*' -print0 | xargs -0 -n1 php -l
```

仓库里的 `.github/workflows/ci.yml` 会在 PHP 7.4 和 8.3 上跑同一条命令。
`deploy/check-*.js` 那两个 Node 脚本只是辅助（模板 `endif` 配对、字符串里混进 `?>`），
**不能替代 `php -l`**。

---

## 现状与已知限制

这一节是**必读**的。宁可你现在就知道哪些不能用，也别部署完才发现。

### 平台限制

| 项目 | 现状 |
|---|---|
| **Agent** | **只支持 Linux。** 它依赖 `systemctl` / `screen` / `tmux` / `/proc` / `getconf` / `tar` / `pgrep`，在 Windows 上跑不起来 |
| 面板侧（网站） | Linux 和 Windows 都能跑。只有 `local` / `ssh` 执行器是 Linux 向的（`Executor::findBinary()` 找的是 `/usr/bin` 这类路径） |
| PHP | 7.4 ~ 8.3。CI 在 7.4 和 8.3 上各跑一遍 `php -l` |
| 数据库 | SQLite。`db.driver` 的 MySQL 分支只是预留，**没有认真测过**，别在生产上用 |

### 功能边界（不会自动做的）

- **不会定时调 `monitor_server`。** 它是一个可以人工触发的只读体检；"发现掉线自动拉起"
  是 `restart_server` 配风控做的事。
- **`agent_ip_allow` 只在后台表单里配**，没有别的入口。开了之后 MC 机器换 IP 会直接 403，
  家宽/动态 IP 别开。
- **面板适配器覆盖不了所有版本。** API 路径有差异时要在后台覆盖接口路径；
  覆盖不了就只能退回 RCON 或 Agent。
- **诊断是特征匹配，不是万能。** 没覆盖的报错会明说"需要人工"，不会硬编一个结论给你。
- **只有中文界面。**
- **没有内置 2FA。**

### 工程质量

- **没有自动化测试。** 这是目前最大的技术债。`tests/run.php` 是一组不依赖 PHPUnit
  的冒烟断言，覆盖面很窄；真正的语法保证靠 CI 里的 `php -l`。
- **没有截图/GIF。** 上面「界面长什么样」是文字描述。
- **单人维护。** 面板 API、各种 MOD 加载器的日志格式都在变，适配器需要社区一起补。

### 安全上的取舍（有意为之，不是疏漏）

- **忘记口令只能上服务器跑 `bin/mcfix.php reset-password`。** 网页端刻意不留找回入口 ——
  任何"能通过网页重设口令"的路径，在配置被写坏时都会变成一条无鉴权的提权链。
- **出网请求默认校验证书。** 宝塔那种自签证书的面板需要在服务器配置里显式勾选
  "跳过 HTTPS 证书校验"，勾了之后面板密钥和日志内容就没法防中间人了。
- **`trusted_proxies` 里的地址才会被采信转发头。** 如果宝塔的 Nginx 是**追加**而不是覆写
  `X-Forwarded-For`，请把 `trusted_proxies` 留空，让系统只看 `REMOTE_ADDR`。

---

## 参与贡献

见 [CONTRIBUTING.md](CONTRIBUTING.md)。安全漏洞请走私密渠道，见 [SECURITY.md](SECURITY.md)。

**特别欢迎的 PR**：新的客户端报错特征（往 `src/ClientLog.php` 的特征表里加一条正则 +
往 `src/ClientIssue.php` 的知识库里加一条结论）、新的面板适配器、以及真实部署踩到的坑。

## 许可

[Apache License 2.0](LICENSE)，版权与归属声明见 [NOTICE](NOTICE)。

**白话版**（不构成法律意见，以 [LICENSE](LICENSE) 原文为准）：

| 你可以 | 同时你要 |
|---|---|
| 自己用、商用、修改、**闭源**二次分发 | 保留 `LICENSE`、`NOTICE` 和版权声明（§4） |
| 拿它搭收费服务，不必公开你的改动 | **改过的文件要注明你改过**（§4b）—— 这条 MIT 没有 |
| 在专利上更放心 | 不能用 "MCFix" 这个名字暗示官方背书（§6，不含商标授权） |

**为什么从 MIT 改成 Apache 2.0**：主要是 §3 那条**明确的专利授权**。
MIT 只给版权许可，对专利只字未提；Apache 2.0 把这件事写清楚了，对公司/团队用户更友好。

**一个要提前知道的代价**：Apache 2.0 **与 GPLv2 不兼容**（只能合进 GPLv3）。
如果你打算把自己的改动合进某个 GPLv2 项目，那 MIT 才是能用的那个。不涉及这种情况就无所谓。

> 仓库里**没有捆绑任何第三方代码** —— `public/assets/` 下的 JS 和 CSS 都是手写的
> （`app.js` 开头就写着"无依赖，纯原生 JS"），所以这次换许可证不牵扯任何第三方授权，
> 也不需要额外的第三方声明。

## 致谢

感谢所有把这套东西用在真实服务器上、并把踩到的坑反馈回来的人。
本项目的每个版本更新日志里都记着"哪个问题是在真实部署中发现的"——
那些问题比任何单元测试都值钱。
