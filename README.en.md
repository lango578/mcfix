# ⛏ MCFix — Minecraft Server Issue Reports with Automatic Repair

[![License: Apache 2.0](https://img.shields.io/badge/License-Apache_2.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B%20%7C%208.x-777bb4.svg)](https://www.php.net/)
[![Dependencies](https://img.shields.io/badge/dependencies-0-brightgreen.svg)](#why-zero-dependencies)
[![Database](https://img.shields.io/badge/database-SQLite-003b57.svg)](https://www.sqlite.org/)

> 中文文档：[README.md](README.md) · English (this file)

> ### ⚠️ Security notice
>
> **Releases before 1.10.1 have a fixed vulnerability: anonymous email header injection.**
> An unauthenticated visitor could inject arbitrary mail headers into the admin
> notification (forging `Reply-To`, inserting custom headers, and so on) through the
> ticket title field. **Use 1.10.1 or newer.**
>
> Details in [CHANGELOG](CHANGELOG.md). The historical tag `v1.9.0` still points at the
> unfixed commit — don't check it out unless you're doing archaeology.

A player taps a link in your group chat → the system **actually connects to the server once**
to verify → whatever it can fix (whitelist, false ban, crash, plugin error) it
**fixes on the spot** → re-verifies → notifies your phone.

**Who it's for:** solo owners, small teams, and panel-hosted servers
(MCSManager / Pterodactyl / BT Panel) — especially anyone who **can't watch the group chat 24/7**.

**Deployment shape:** the web app runs on BT Panel (PHP + SQLite, upload and go). The
Minecraft server sits on another machine and reaches back through a single-file Agent that
polls for work — **no inbound ports on the Minecraft host**.

> **Read this first.** What it does and what it deliberately does **not** do is in
> [Known limitations](#known-limitations). Check that section before deploying — especially
> "the Agent is Linux-only".

---

## Documents and configuration files

### Documents

| Document | Contents |
|---|---|
| [`deploy/宝塔部署指南.md`](deploy/宝塔部署指南.md) · [EN](deploy/BT-PANEL-DEPLOYMENT.en.md) | From zero: environment, Nginx, FPM, certificates, cron, the traps we hit |
| [`deploy/面板服接入指南.md`](deploy/面板服接入指南.md) · [EN](deploy/PANEL-SERVERS.en.md) | How to connect panel-hosted servers (MCSManager / Pterodactyl / BT Panel / rented), capability matrix and troubleshooting |
| [`deploy/主机商接入索引.md`](deploy/主机商接入索引.md) · [EN](deploy/HOSTS-INDEX.en.md) | "I rent from XXX — will it work?": provider matrix (verified / unverified) + a three-minute checklist for identifying your panel type |
| [`deploy/BisectHosting接入指南.md`](deploy/BisectHosting接入指南.md) | BisectHosting step by step (usable as a template for any Pterodactyl provider) |
| [`deploy/简幻欢接入指南.md`](deploy/简幻欢接入指南.md) | SimpFun / SFE4 step by step: in-house panel, no open API, the real bar for RCON vs Agent |
| [`deploy/邮件通知指南.md`](deploy/邮件通知指南.md) · [EN](deploy/EMAIL-NOTIFICATIONS.en.md) | Player receipts + admin alerts; why a VPS must use SMTP instead of Postfix |
| [`deploy/安全说明.md`](deploy/安全说明.md) · [EN](deploy/SECURITY-NOTES.en.md) | Threat model, boundaries, deployment checklist |
| [`SECURITY.md`](SECURITY.md) | Security policy and how to report a vulnerability |
| [`CHANGELOG.md`](CHANGELOG.md) | What changed in each release, and why |

### Ready-to-use config files

Change the domains and paths and they work:

| File | Purpose |
|---|---|
| [`deploy/nginx.conf.example`](deploy/nginx.conf.example) | Nginx site config: both domains, HTTPS redirect, security headers, source-directory deny |
| [`deploy/php-fpm-pool.conf.example`](deploy/php-fpm-pool.conf.example) | PHP-FPM pool (including the BT Panel `www` vs `www-data` 502 trap) |
| [`deploy/mcfix-agent.service`](deploy/mcfix-agent.service) | systemd unit for the Agent, plus a least-privilege sudoers recipe |
| [`deploy/crontab.example`](deploy/crontab.example) | Cron: routine maintenance, WAL-safe backup, certificate renewal |
| [`config.example.php`](config.example.php) | Every configuration key documented, in Chinese (`email` / `ai` / `panel` / `domains` / `admin` / `notify`) |
| [`deploy/install.sh`](deploy/install.sh) | One-shot setup: create directories, fix permissions, print the cron command |

### Program files

| File | Purpose |
|---|---|
| [`agent/mcfix-agent.php`](agent/mcfix-agent.php) | The single-file Agent for the Minecraft host (this is what you upload there) |
| [`bin/mcfix.php`](bin/mcfix.php) | CLI: `doctor` / `servers` / `panel` / `link` / `diagnose` / `tickets` / `test-feedback` / `cron` / `reset-password` / `rotate-all` |
| [`tests/run.php`](tests/run.php) | 158 smoke tests, zero dependencies: `php tests/run.php` |
| [`deploy/check-alt-syntax.js`](deploy/check-alt-syntax.js) · [`deploy/check-close-tag.js`](deploy/check-close-tag.js) | Offline structural checks (**not** a substitute for `php -l`) |

> Download the whole thing from this repo's **Releases** page, or just `git clone`.

---

## What the UI looks like

There are no screenshots (the author can't record the live environment and won't ship
mockups). The three pages, in words:

**Player feedback page** — a single dark-themed form. Pick a server → enter your game ID →
pick a category (can't join / crashed / lag / rollback / banned / plugin error / report) →
describe it, optionally attach a crash log. The same page then turns into live progress: a
list of checks flipping from "checking" to ✅/⚠️/❌, with a plain-language verdict underneath.

**Ticket detail page** — the check breakdown, the verdict, what the system repaired, the
re-verification result, and (if a log was uploaded) the client crash analysis: missing
classes, suspect mods, Java/memory advice, downloadable mod files.

**Admin console** — fixed left nav (Tickets / Ticket detail / Servers & Agent / Mod
distribution / Notifications / Event log / Settings / Setup & troubleshooting), content on
the right. Every automated action is written to the event log.

---

## What it actually solves

### When a player says "I can't join", it's usually not what you think

| Player says | What the system actually does | Auto-fixable? |
|---|---|---|
| Can't join | Full Minecraft protocol handshake (identical to the player clicking "Join Server") + whitelist / banlist check + server log read | ✅ whitelist add, unban, restart |
| Server crashed | Process check, port listening, crash log, free disk space | ✅ restart (with hourly quota) |
| Lag / low FPS | RCON `tps` + sampled process CPU/RAM + disk + logs | ✅ force world save / restart |
| Rollback / lost items | Region file write times, chunk errors, TPS | ⚠️ save + backup world; the data itself needs a human |
| Banned / muted | RCON `banlist players`, whitelist contents | ✅ unban, whitelist add |
| Plugin errors | Aggregates plugin exceptions from the log and counts them | ✅ reload plugins / restart |
| Reporting a player | Collects information only, takes no action | ❌ escalated to a human |

**Players are not reliable narrators.** "I can't join" might mean they aren't whitelisted,
they're banned, the port isn't up yet — or the server is fine and their client version is
wrong. **The system's first job is turning the player's claim into an objective fact**, and
only then deciding whether to touch anything.

### Client crash logs are analysed too

These are the majority of real reports and can't be diagnosed from a text description. The
player uploads the crash report directly:

```
Player uploads crash-report / latest.log (or pastes the error)
        ↓
ClientLog parser: game version, loader, Java, memory, mod list, exception stack, missing classes
        ↓
ClientAdvisor knowledge base: 40+ signatures → plain-language verdict + exact steps for the player
        ↓
Cross-check against the server's mod list: what's extra, what's missing
        ↓
Server-fixable (whitelist/unban/restart) → handed to the Fixer; client-only → precise steps
        ↓
Missing mods: the Agent fetches the file from the server's mods/ directory
so the player downloads the exact matching version
```

Common errors it recognises and answers:

| Seen in the log | Verdict | Resolution |
|---|---|---|
| `NoClassDefFoundError` | A class is missing (mod not installed / wrong version) | Lists the missing class names |
| `Missing or unsupported mandatory dependencies` | Missing a library mod | Extracts dependency names, lists common ones |
| `MixinApplyError` | Mod doesn't match the game version | Points at the suspect mod from the Mixin error |
| `ModLoadingException` | Mod failed during loading | Names the suspect mod; hints about server-only mods |
| `Incompatible mod set` | Two mods conflict | Flags known conflicts (OptiFine vs Sodium, etc.) |
| `OutOfMemoryError` | Not enough heap | Version-aware memory + Java recommendations |
| `UnsupportedClassVersionError` | Wrong Java version | Derives the required Java from the class file version |
| Forge / NeoForge / Fabric version check failure | Loader version mismatch | Loader-specific fix instructions |
| `Connection refused` | Server port isn't listening | **Triggers a server restart**, tells the player to wait 1–2 min |
| Whitelist / banned / server-full messages | The server rejected the connection | **Auto whitelist add / unban** |
| Server-side plugin exception | Not the player's fault | Escalates, **attempts a plugin reload** |
| OpenGL / GLFW errors | GPU driver or render environment | Driver update, dual-GPU selection, RDP limitations |
| Log too short / no known features | Not enough information | Tells the player exactly which file to upload (full paths) |

---

## Quick start (BT Panel, about 10 minutes)

### 1. Requirements

| Item | Requirement |
|---|---|
| PHP | 7.4 – 8.3 (8.1+ recommended). No 8.0+ syntax is used anywhere |
| Required extensions | `pdo_sqlite` `mbstring` `json` |
| Recommended extensions | `openssl` (signing tokens, outbound HTTPS), `curl` (falls back to streams without it) |
| Database | None needed — SQLite, zero configuration |
| Composer / npm | **Not needed** |
| OS | The web app runs on Linux or Windows. **The Agent is Linux-only** (see below) |

Install missing extensions in BT Panel under *Software Store → PHP Settings → Extensions*.
If you use the `local` or `ssh` executor, also remove `proc_open` from *Disabled Functions*
(the `agent` mode doesn't need it).

> The `sqlite3` CLI is not a runtime dependency, but you **do** need it for backups
> (`sqlite3 ... ".backup ..."`). The database runs in WAL mode, so a plain `cp` can produce
> an inconsistent snapshot. On BT Panel: `yum install sqlite` / `apt install sqlite3`.

### 2. Upload and point the site at `public/`

```bash
cd /www/wwwroot
mkdir mcfix && cd mcfix
unzip -o ~/mcfix.zip          # you should see public/ src/ agent/ bin/
bash deploy/install.sh        # creates runtime dirs, fixes permissions, self-checks
```

`deploy/install.sh` does exactly four things: create
`storage/{data,logs,cache,locks,ratelimit,keys,mods}`, fix permissions, find a usable PHP
CLI, print the cron command. It does **not** write `config/config.php` — that's the
browser installer's job.

> Before installation the script calls `doctor` once, which prints "not installed yet" and
> exits non-zero. That's expected, not a failure.

Then set *Website → Site settings → Website directory → Running directory* to **`/public`**.

> ⚠️ **Do not skip this.** The source, `config/config.php` (containing the signing secrets)
> and the SQLite database all live one level up. Only rooting the web server at `public/`
> actually protects them. Nginx does not read `.htaccess` — this is path isolation, not file
> rules.

With the root at `public/`, a correctly configured Nginx needs **no** extra deny rules. If
you can't change the running directory, write the equivalent by hand:

```nginx
location ~ ^/(config|src|storage|admin|views|bin|agent|deploy)/ { deny all; }
location ~ /\.(?!well-known) { deny all; }
```

The `.htaccess` files in the repo (root / `config/` / `storage/`) only apply to
Apache / LiteSpeed; Nginx ignores them outright — don't count them as protection.

### 3. Configure Nginx and PHP-FPM

See [`deploy/宝塔部署指南.md`](deploy/宝塔部署指南.md) for the full Nginx config, the
php-fpm pool config, and the traps.

> ⚠️ **The single most common failure: the FPM pool user doesn't match the Nginx user.**
> BT Panel's Nginx runs as `www`; configure the pool as `www-data` out of distro habit and
> you get **502 Bad Gateway**, with the Nginx error log saying only
> `connect() to unix:... failed (13: Permission denied)`. Set `user`, `group`,
> `listen.owner` and `listen.group` to `www`.
> Templates: [`deploy/nginx.conf.example`](deploy/nginx.conf.example),
> [`deploy/php-fpm-pool.conf.example`](deploy/php-fpm-pool.conf.example).

### 4. Run the installer

Open `https://your-domain/?r=install`:

1. Environment self-check
2. Admin password + site URL (this also generates a random private console path)
3. (Optional) Add your first Minecraft server

When it finishes you get:

- ✅ **Player feedback link** (share it in your group)
- ✅ **Admin console URL** (bookmark it)
- ✅ The Agent's `config.php` contents
- ✅ systemd deployment commands

### 5. Deploy the Agent (if the MC server is on another machine)

```bash
# On the Minecraft machine
mkdir -p /opt/mcfix
# Upload agent/mcfix-agent.php to /opt/mcfix/
# Put the generated config.php next to it

php /opt/mcfix/mcfix-agent.php --check      # self-check
php /opt/mcfix/mcfix-agent.php --once       # one poll, verify it reaches the panel

# Run it as a service (deploy/mcfix-agent.service is a ready-made systemd unit)
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

> **The Agent is optional.** With RCON configured you can already fix whitelists, bans,
> world saves and plugin reloads. Add a panel API (MCSManager / Pterodactyl / BT Panel) and
> the system can read logs too. The Agent is what unlocks **process-level** actions:
> restarts, backups, disk checks, and pulling mod files.

### 6. Add the cron job

BT Panel → *Scheduled Tasks → Shell script*, **every minute**:

```bash
cd /www/wwwroot/mcfix && /www/server/php/81/bin/php bin/mcfix.php cron
```

> **This must be the PHP CLI binary** (`/www/server/php/83/bin/php` and the like) — run
> `which php` over SSH first. The web-side `PHP_BINARY` is
> `/www/server/php/83/sbin/php-fpm`; pasting that starts an FPM process that exits
> immediately with no error message, so the script never runs and neither queued repairs nor
> emails move.

It handles: reclaiming stuck tasks, advancing tickets awaiting re-verification, clearing
caches, retrying failed notifications, and sending the daily digest.

### 7. Verify

```bash
php bin/mcfix.php doctor          # config + environment self-check
php bin/mcfix.php servers         # servers, agents, available channels
php bin/mcfix.php test-feedback survival Steve "can't connect"
```

The last one runs the whole loop (submit → verify → repair → re-verify) and prints what
actually happened at each step. **All green means the chain works.**

---

## Architecture

```
┌─────────────────────────┐  HTTPS   ┌──────────────────────────────┐
│  BT Panel (this app)    │ ◀─────── │  Machine running Minecraft    │
│                         │  long    │  mcfix-agent.php (one file)   │
│  Player feedback site   │  poll    │   ├ heartbeat + claim task    │
│  Admin console (own     │          │   ├ run diagnostics           │
│   domain, recommended)  │          │   └ report results            │
│  SQLite + diagnosis     │          │                              │
│  and repair engine      │   RCON   │                              │
│                         │ ───────▶ │  Minecraft server             │
│                         │  TCP     └──────────────────────────────┘
│                         │   HTTP   ┌──────────────────────────────┐
│  Panel API adapters     │ ───────▶ │  MCSManager / Pterodactyl /   │
└─────────────────────────┘          │  BT Panel                     │
                                     └──────────────────────────────┘
```

### Six execution channels, selected by capability with automatic fallback

One codebase handles both panel-hosted and self-hosted servers through
**capability declaration + graceful degradation**:

| Channel | Can do | Requires |
|---|---|---|
| **RCON** | Game commands: whitelist, unban, unmute, kick, clear inventory, save, reload | `enable-rcon=true`; has echo, most reliable |
| **Panel API** | Read logs, send commands, power, list dirs, read files, resource usage | MCSManager / Pterodactyl / BT Panel / custom HTTP |
| **Agent** | Everything: process, port, disk, CPU, logs, mod list, restart | A small script on the MC host; **no inbound ports** |
| **SSH** | Same as Agent | Panel can SSH into the MC host |
| **local** | MC and panel on the same machine | `proc_open` available |
| **none** | Direct probing only (Minecraft protocol + RCON) | — |

Routing lives in `src/Executor.php`:

```
Diagnostics (logs)  → Panel API (no install needed) → Agent → explain why it was skipped
Game commands       → RCON (has echo) → Panel console
Process / power     → RCON stop + guard restart → Panel power → Agent
Process/port/disk   → Agent / SSH / local (panel APIs can't see these)
```

**Why "Agent polls the panel" instead of "panel SSHes in"?**

1. Home/NAT-hosted MC servers aren't reachable from the panel; the reverse direction works
2. No extra ports, no SSH keys, no root access handed to the panel
3. Most importantly: **repairs are a fixed whitelist of recipes on the panel side.**
   The Agent only accepts known codes and never executes an arbitrary shell command
   (see [SECURITY.md](SECURITY.md))

### Two fully independent entry points

| | Player feedback site | Admin console |
|---|---|---|
| Entry | `https://fankui.example.com/` | `https://mc.example.com/` (separate domain recommended) |
| Session | Remembers which tickets this browser filed; carries no identity | Separate session `mcfix_console` |
| CSRF | Not needed (no identity, no write authority) | Its own token; enforced on every write |
| Cross-links | **No link to the admin console anywhere** | — |
| Access control | Per-IP rate limit + signed tokens | IP allowlist + login lockout + secret path |
| When scanned | A normal feedback page | Anything unexpected returns a bare 404 |

Two deployment shapes: **separate domains** (recommended — real same-origin isolation) or
**same domain + random private path** (leave `domains.console_host` empty).

---

## Why zero dependencies

No third-party package is pulled in: no Composer, no npm, no framework, no ORM. Only the PHP
standard library and extensions.

The cost is reinventing some wheels: a hand-written Source RCON client, a hand-written
Minecraft Server List Ping, a hand-written SMTP client, and hand-written webhook signing.

The payoff is that **deployment really is "unzip and go"**:

- No `composer install` (plenty of panel users don't have Composer at all)
- No supply-chain surface
- This will still run in five years; no package can be unpublished out from under it
- Roughly 21k lines of PHP you can actually read

---

## Supported platforms

### Minecraft servers

Paper / Spigot / Purpur / CraftBukkit / Folia / Vanilla / Forge / NeoForge /
Fabric / Quilt / Velocity / BungeeCord / Waterfall

### Hosting panels

| Panel | Coverage |
|---|---|
| **MCSManager** | Commands, power, log read, dir listing, file read |
| **Pterodactyl / Pelican** | Commands, power, log read, dir listing, file read, resource usage |
| **BT Panel** | Log read, dir listing, process start/stop (game commands go over RCON) |
| Rented panels (SimpFun, RainYun, …) | Use the "custom HTTP" adapter if it has an HTTP API; otherwise RCON |
| Self-hosted | Install the Agent for full capability |

You usually can't install anything on a panel host, so here is how far the panel API alone
gets you:

| | Panel API only | Panel API + RCON | Panel API + Agent |
|---|---|---|---|
| Install anything on the MC host | **No** | No (edit `server.properties`) | Yes |
| Read logs for diagnosis | ✅ | ✅ | ✅ |
| Player-perspective connectivity probe | ✅ | ✅ | ✅ |
| Auto whitelist / unban / kick | ✅ (MCSManager, Pterodactyl via panel console) | ✅ (RCON, with echo) | ✅ |
| Auto restart | ✅ (panel power) | ✅ | ✅ |
| TPS / player count / ban list | ❌ | ✅ | ✅ |
| Process, disk, CPU sampling | Pterodactyl only | ❌ | ✅ |
| Backup world / fetch mod files | ❌ | ❌ | ✅ |

> **Is your MC server inside a panel (rented, MCSManager, Pterodactyl, BT Panel)?** Read
> **[`deploy/PANEL-SERVERS.en.md`](deploy/PANEL-SERVERS.en.md)** —
> it covers exactly how far you get without an Agent, what to fill in for each panel, where
> it stalls, and the classic panel-host traps (an address typo read as "server offline",
> RCON port unreachable, endpoint path differences between versions).

> Panel API paths drift between versions. Every adapter supports overriding endpoint paths
> from the admin console, and **failures state exactly which endpoint failed and why**
> instead of failing silently. Diagnose with `php bin/mcfix.php panel <server_id>`: it
> prints the connectivity result, the detected capabilities, and **the exact HTTP requests
> it made plus the panel's raw response** — which is what you want when a UUID was typed wrong.

### Notification channels

DingTalk · WeCom · Feishu / Lark · Email · Bark · ntfy · ServerChan · Telegram ·
Discord · Generic webhook

> **Players and you can both get mail without configuring a channel.** In the console, tick
> the switch under *Settings → Email notifications* and set one admin address: the player
> gets an automatic "received" mail on submission and a result mail on resolution or
> escalation, and you get an alert for every new report. See
> [Email notifications](#email-notifications).

---

## Does it use an LLM?

**No. This system does not require any AI service.**

Every verdict comes from hard-coded rules you can read to see why it decided that way:

| Source | Size |
|---|---|
| Client issue knowledge base `src/ClientIssue.php` | 37 entries |
| Log feature regexes `src/ClientLog.php` | 51 patterns |
| Server check specs `src/Catalog.php` | 23 checks |
| Verdict branches `src/Verdict.php` | 14 rules |

The system makes outbound connections to exactly six kinds of destination, all of them
yours: panel APIs, your notification webhooks, Agent→panel, Minecraft protocol handshakes,
RCON, and the local `mail()`. Nothing else.

That's deliberate: no API key, no cost, **works offline**, **player logs never leave your
machine**, and the same error always produces the same verdict.

### If you want one anyway (off by default)

When the rule base doesn't match, the system can ask a model once. Two paths:

| Case | Triggered when | What the model may do |
|---|---|---|
| **Client error** | the player attached a crash log and none of the 37 rules matched | write a plain-language verdict and steps the player can take; it **cannot** trigger any action |
| **Server fault** | server diagnosis ran and classified *no* issue at all | pick one entry from the list of recipes **this server can actually execute**; anything else falls back to human review |

Both paths are "rules first, model only when the rules come up empty". Common errors never
reach the model.

It speaks the **OpenAI-compatible `/v1/chat/completions` API**, so local and third-party are
the same configuration with a different base URL:

```php
'ai' => [
    'enabled'  => true,
    'base_url' => 'https://api.deepseek.com',   // or http://127.0.0.1:11434/v1 (Ollama)
    'api_key'  => 'sk-...',
    'model'    => 'deepseek-chat',
],
```

Configure it under *Settings → LLM fallback*; the **Test connection** button reports latency
and tokens used.

Six hard rules, enforced in `src/AiAdvisor.php` and covered by tests:

1. **Client side — the model produces verdicts, never commands.** Its repair suggestions are
   *never* executed. The only way it can influence an action is by naming an existing
   knowledge-base code, and then *our* vetted recipe list is used.
2. **Server side — the model may only *pick one* from a list, never invent one.** We hand it
   the recipes this particular server can actually execute. The code it returns must be found
   in that list; if not, the whole answer is discarded as if the call never happened.
3. **Picking a valid code is still not enough.** That code is re-validated through the full
   gate chain: recipe allowlist → is this server allowed to run it → parameter provenance →
   approval requirements. Parameters come from exactly two sources — `player` from the ticket,
   `reason` from a fixed string. Any other parameter name means the action is dropped.
4. **Redaction before transmission**: player names, home directories, IPs, emails and
   launcher tokens are replaced with placeholders.
5. **6-second timeout, then degrade** back to "needs human analysis". Never blocks a ticket.
   Cached by log fingerprint for 7 days — the same error class is only asked once.
6. **Rate-limited**: 5/hour per source, 60/hour globally, to cap your bill.

> **Two server-side actions are hard-excluded from the model**, because picking them wrongly
> doesn't just fail to fix things — it breaks them, or opens a door:
>
> - `unban_player` — otherwise a banned player could file a ticket and talk the model into
>   unbanning them
> - `clear_self_items` — a misjudgement here destroys player inventory data
>
> Both remain available on the rule-base path (a deterministic decision). The model simply
> cannot reach them.

> Leave it off at first and watch how many entries on the *Client issues* page come back as
> `unknown`. If that's rare, the rule base is enough and there's no reason to pay for this.

---

## Data retention

Player-uploaded crash reports contain the **Windows username** (`C:\Users\<name>\AppData\...`),
the GPU model, the game ID, the server address and the full mod list — personal data that
should not be kept indefinitely.

| Data | Cleared when | Cleared to |
|---|---|---|
| Player email | Ticket reaches a conclusion | Masked to `ab***@qq.com` (`purge_email`, on by default) |
| **Raw log text** | Ticket in a terminal state for `log_retention_days` days | `client_log` / `client_log_name` set to NULL — **diagnosis, mod list and analysis are all kept** |

```php
'feedback' => [
    'autoclose_days'     => 7,   // days before terminal tickets in legacy data collapse to "closed"
    'log_retention_days' => 30,  // days after archiving before the raw text goes; 0 = keep
],
```

**Also editable in the console**: *Settings → Player feedback → Log retention (days, 0 = keep)*.
That page shows how many tickets still hold a raw log and how many have been purged, and the
Maintenance tools card has a **Purge log text** button so you don't have to wait for cron.

Purging runs from `php bin/mcfix.php cron` and **only touches tickets in a terminal state
(`closed` / `resolved` / `rejected`)** — anything still open may need re-analysis, which is
impossible once the raw text is gone. Each purge records a `log.purged` event, visible on the
player's ticket page, so logs don't just silently disappear.

> Earlier builds recognised only `status = 'closed'`, on the reasoning that "a `resolved`
> ticket may still need re-analysis". But nothing except the once-a-day cron ever advanced
> `resolved` to `closed`, so those tickets' raw text was in practice **never purged**.
> All three terminal states are now treated alike.

> If you need raw logs long-term for statistics, set `log_retention_days` to `0`. Check your
> local compliance requirements first. Analysis verdicts are unaffected — turning retention
> off changes nothing outside the raw text itself.

---

## Email notifications

Players can optionally leave an email address on the feedback form. If they do:

1. On submission → "we've received your report, running checks"
2. On resolution → the result, with the check breakdown and **what they need to do**
3. When the ticket closes → the stored address is masked (`ab***@qq.com`), and the plaintext
   address in the queue is wiped as soon as it has been sent

For you: set one admin address and you get a mail per new report — no notification channel
to configure, no event subscriptions to tick.

Configure it under **Settings → Email notifications**, or in the `email` section of `config.php`:

```php
'email' => [
    'enabled'       => true,
    'admin_to'      => 'you@example.com',   // receives new-ticket alerts
    'from'          => '',                   // empty = no-reply@your-domain
    'notify_player' => true,
    'notify_admin'  => true,
    'purge_email'   => true,                 // wipe the player address when the ticket ends
],
```

**Sending is asynchronous**: the submit endpoint only enqueues; `php bin/mcfix.php cron`
sends a batch every minute and retries failures at 1/3/10/30-minute backoff. **So the cron
job is mandatory** — otherwise mail piles up in the queue.

### The key point: on a VPS you must use SMTP, not mail()

The first instinct is "just install Postfix". **On a VPS that road is closed:**

```
direct to mx3.qq.com:25     → unreachable   ← the provider blocks outbound port 25
smtp.qq.com:465 / :587      → works
```

PHP's `mail()` hands the message to the local MTA to deliver straight to port 25 on the
recipient's server. Port 25 blocked = nothing is ever sent; Postfix just queues forever while
the UI says "installed". (Even with port 25 open, a VPS IP has no SPF/DKIM/reverse DNS, so
QQ and 163 will file it as spam anyway.)

**The right way**: use a real mailbox plus its app password and relay through the provider.

Tick **Use SMTP** under *Settings → Email notifications → Delivery method*, then:

| Mailbox | SMTP host | Port / crypto | Password field |
|---|---|---|---|
| QQ Mail | `smtp.qq.com` | 465 / SSL | **16-char authorization code** (not the QQ password) |
| QQ Enterprise Mail | `smtp.exmail.qq.com` | 465 / SSL | mailbox login password |
| 163 / 126 | `smtp.163.com` | 465 / SSL | **authorization code** |
| Gmail | `smtp.gmail.com` | 587 / STARTTLS | **app-specific password** |
| Aliyun Mail | `smtp.mxhichina.com` | 465 / SSL | mailbox login password |

> **Getting the QQ Mail authorization code**: QQ Mail web → Settings → Account → find
> "IMAP/SMTP service" → enable → send the SMS it asks for → you get a 16-character code.
> It is shown once; copy it in.

The matching `config.php`:

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
        'password'   => 'your 16-char authorization code',
        // QQ/163 require the From address to equal the login account; aligned automatically
        'force_from_username' => true,
    ],
],
```

**Save first**, then hit **Send test email**. On failure it quotes the server verbatim:

| Message | Cause |
|---|---|
| `535` + "authorization code required" | You used the login password. Generate an authorization code in the mailbox settings |
| `550` + "recipient rejected" | The recipient address is wrong |
| "cannot connect to host:port" | Wrong host/port, or the provider blocks that port |
| "server does not support STARTTLS" | Port and encryption don't match — SSL for 465, STARTTLS for 587 |
| Queue stuck at `queued` | **The cron job isn't running** |
| Status `sent` but nothing arrived | Filed as spam. Send from a real mailbox account, not a fake domain like `no-reply@` |

> A home or office setup with port 25 open can still use `mail()` + Postfix — that path is
> retained, and leaving SMTP unset falls back to it.

---

## Security design

The core invariant: **a compromised panel ≠ a compromised Minecraft host.**

The Agent executes only recipes from a fixed whitelist. There is **no code path that
executes an arbitrary command supplied by the panel**. If the panel sends a `recipe` that
isn't a known code, the Agent refuses it.

```
whitelist_add  unban_player  unmute_player  kick_player  clear_self_items
save_world     reload_plugins  restart_server  backup_world  monitor_server  pull_mod
```

**`unmute_player`** — vanilla Minecraft has no mute; it comes from plugins like Essentials,
so this recipe issues `unmute <player>` and reports failure honestly if the plugin is absent.
**`monitor_server`** — a read-only health check you can trigger manually; the system does
**not** schedule it, and does not restart anything based on its output.

Read [SECURITY.md](SECURITY.md) and
[`deploy/SECURITY-NOTES.en.md`](deploy/SECURITY-NOTES.en.md)
(中文: [`deploy/安全说明.md`](deploy/安全说明.md)) for the threat model and deployment checklist.

---

## Directory structure

```
public/                    web root (point the running directory here)
├── index.php              single entry point + routing (including dual-domain split)
├── assets/app.css|app.js  front-end assets
└── controllers/           feedback / admin / api / agent / mod / install
src/                       core classes (autoloaded, zero dependencies)
├── Config.php             config read/write and self-check
├── Db.php                 SQLite layer (idempotent schema)
├── ConsoleAuth.php        admin auth, sessions, domain split, IP allowlist
├── Net.php                Minecraft protocol handshake / TCP probe
├── Rcon.php               RCON client (with reconnect)
├── Executor.php           executor routing (rcon / panel API / agent / ssh / local)
├── Panel/                 panel adapters
├── Catalog.php            server issue categories and checks
├── Diagnosis.php          diagnosis engine (automatic verification)
├── Verdict.php            verdicts (check results → conclusion + repair suggestion)
├── ServerLog.php          server log analysis (shared by panel and Agent)
├── ClientLog.php          client log parsing
├── ClientAdvisor.php      client verdicts
├── ClientIssue.php        client issue knowledge base (40+ entries)
├── ServerMods.php         server mod list and cross-check
├── ModLibrary.php         mod file library and signed downloads
├── Notifier.php           notification engine (dedupe / storm protection / retry)
├── Channel/               notification channel adapters
├── Recipe.php             repair recipe whitelist (the security core)
├── Workflow.php           orchestration (submit→verify→repair→re-verify→notify)
├── Task.php               task queue (long-poll leases)
└── Token.php / Share.php  signed tokens
admin/                     console pages
views/                     player-facing templates
agent/mcfix-agent.php      MC-side Agent (single file, deployed separately)
bin/mcfix.php              CLI
deploy/                    deployment scripts and docs
storage/                   runtime data (database / logs / cache / rate limit / keys / mod library)
config/                    installer-generated config.php (mode 640)
tests/run.php              smoke tests without PHPUnit (plain PHP assertions)
.github/                   issue templates + CI (full `php -l` syntax check on 7.4 and 8.3)
```

---

## CLI tools

```bash
php bin/mcfix.php doctor          # config + environment self-check
php bin/mcfix.php servers         # servers, agents, available channels
php bin/mcfix.php panel <id>      # panel API diagnosis: connectivity, capability, real requests
php bin/mcfix.php link survival   # generate a player link for one server
php bin/mcfix.php diagnose survival Steve cannot_join   # diagnose without creating a ticket
php bin/mcfix.php tickets manual  # list tickets awaiting a human
php bin/mcfix.php test-feedback survival Steve "can't connect"   # full loop
php bin/mcfix.php cron            # maintenance (for the scheduler)
php bin/mcfix.php rotate-all      # rotate every public share link secret
php bin/mcfix.php reset-password  # reset the admin password (the only way back in)
```

**Forgot the admin password?** Don't delete `admin_password` from `config/config.php`.
The installer permanently closes its password-setting branch once a config file exists —
that gate *is* the security design (see [SECURITY.md](SECURITY.md)). Use
`reset-password`; it rehashes and invalidates every logged-in session.

---

## Automation safeguards

| Setting | Default | Meaning |
|---|---|---|
| Allow automatic repair | on | Off = verify only, everything escalates to a human |
| Restart requires admin approval | **on** | Turn off for "player reports it, it restarts itself" |
| Max auto-fix attempts per ticket | 2 | Then escalates to a human, so the server isn't thrashed |
| Post-fix re-verification delay | 5s | For whitelist / unban style actions |
| Restart re-verification delay | 25s | Servers need time to come back |
| Circuit breaker threshold | 3 | After 3 consecutive failures, that server drops to diagnose-only |
| Max auto-restarts per hour | 3 | Per server |
| Submissions per IP per hour | 5 | Anti-spam |
| Notification burst protection | 3 per 10 min | A flapping server won't flood your group |

**Suggested rollout:**

1. Week 1 — leave "restart requires approval" on, watch diagnostic accuracy
2. Week 2 — once the Agent is stable, turn it off
3. If abused — lower the submission limit, shorten link lifetime, or disable
   `restart_server` for a specific server

---

## FAQ

<details>
<summary>Logged in but it bounces straight back to the login page</summary>

Most likely a **cookie scope mismatch** (a real bug this project shipped and fixed): the
console entry is `/index.php?r=<path>`, so the browser's request path is `/index.php` — a
cookie scoped to `/<path>` is never sent. Session isolation comes from **different session
names**, not cookie paths.

If you changed the deployment shape (subdirectory, running directory), check:

- `app.base_url` matches the address you actually visit
- `bin/mcfix.php doctor` reports no configuration problems

Browser F12 → Application → Cookies: confirm the session cookie really arrives after login.
</details>

<details>
<summary>502 Bad Gateway</summary>

**Nginx and PHP-FPM are running as different users.** BT Panel's Nginx runs as `www`; a pool
configured as `www-data` out of distro habit has mismatched socket permissions.

Fix: set `user`, `group`, `listen.owner` and `listen.group` in the pool to `www`.
See [`deploy/宝塔部署指南.md`](deploy/宝塔部署指南.md) and
[`deploy/php-fpm-pool.conf.example`](deploy/php-fpm-pool.conf.example).
</details>

<details>
<summary>Agent shows "never seen"</summary>

1. Can the MC host reach the panel? `curl -I 'https://your-feedback-domain/?r=api.agent.poll'`
2. Does the token match? The token in the admin server card must equal `token` in the Agent's
   `config.php`
3. Check the Agent log: `php /opt/mcfix/mcfix-agent.php --once --verbose`
4. If `agent_ip_allow` is set (there's a field for it in the admin server form), confirm the
   MC host's egress IP is listed
</details>

<details>
<summary>Repair tasks stay "pending"</summary>

1. `systemctl status mcfix-agent` — is the Agent running?
2. Is the panel cron job running? (Check the event log for cron entries)
3. **Is the scheduled task using `php-fpm` instead of `bin/php`?** It must be the CLI binary
4. After 3–5 minutes the system escalates to a human automatically; it won't hang forever
</details>

<details>
<summary>Notifications sent but the group never got them</summary>

First check the event log for `notify.skip` entries. Those mean the channel itself is
unusable (disabled / unknown type / a required field is empty), and the log says which. If
there are none, work through the rest.

Click **Send test** under *Notifications*; it tells you the reason directly:

| Symptom | Cause |
|---|---|
| DingTalk returns `310000` | The bot's keyword security setting doesn't include `MCFix`; or switch to signed mode |
| DingTalk / Feishu signature error | Wrong signing secret, or **the server clock is off** (signing depends on a timestamp) |
| Telegram `chat not found` | Wrong `chat_id`, or the bot was never added to the group |
| Email fails | BT Panel has no mail service → install Postfix from *Software Store* |
| Everything times out | The server has no outbound network, or a security group is blocking it |
| Certificate verification failure | The peer's HTTPS certificate is bad. Verification is on by default — don't switch it off to get around this |
</details>

<details>
<summary>Emails aren't going out / the player never got one</summary>

**First: on a VPS, `mail()` sends nothing.** Almost every provider blocks outbound port 25,
so Postfix just queues. **Use SMTP** (mailbox account + authorization code) — see
[Email notifications](#email-notifications).

The *Settings → Email notifications* card shows the queue state and **the last failure
reason**. Check in this order:

| Message | Cause |
|---|---|
| `535` / "authorization code required" | The password is the login password. QQ/163 need an **authorization code** (generate it in the mailbox settings) |
| `550` / "recipient rejected" | The recipient address is wrong |
| "cannot connect to host:port" | Wrong SMTP host/port, or the provider blocks it |
| "server does not support STARTTLS" | Port and encryption don't match: SSL with 465, STARTTLS with 587 |
| "mail() returned failure" | No SMTP configured, so it fell back to `mail()` — fill in SMTP |
| Queue stuck at `queued` | **The cron job isn't running.** `bin/mcfix.php cron` every minute is mandatory |
| Status is `sent` but nothing arrived | Filed as spam. Send from a real account; the From address is aligned to the login account automatically |
| The player's mail never went | They left no address, or the format was wrong (a bad format is rejected at submit time with a prompt) |

**Send test email** verifies a single delivery; **Flush mail queue** sends a batch without
waiting for cron.

</details>

<details>
<summary>Backups and migration</summary>

**Back up** with SQLite's own `.backup`, never a plain `cp` — the database runs in WAL mode,
so a hot copy can be inconsistent (the `-wal` / `-shm` sidecars aren't copied with it):

```bash
sqlite3 /www/wwwroot/mcfix/storage/data/mcfix.sqlite \
  ".backup '/www/backup/mcfix-$(date +%F).sqlite'"
```

**Migrate** to a new machine: set up the environment → restore the code → drop in the
backed-up `.sqlite` → restore `config/config.php`.
</details>

<details>
<summary>I edited the source — how do I know I didn't break the syntax?</summary>

```bash
find . -name '*.php' -not -path './storage/*' -print0 | xargs -0 -n1 php -l
```

`.github/workflows/ci.yml` runs the same command on PHP 7.4 and 8.3. The two
`deploy/check-*.js` Node scripts are helpers only (template `endif` pairing, a stray `?>`
inside a string) and are **not** a substitute for `php -l`.
</details>

---

## Known limitations

Read this section. Every item below is a deliberate boundary, not unfinished work.

### Platform limits

| Item | Reality |
|---|---|
| **Agent** | **Linux only.** It depends on `systemctl` / `screen` / `tmux` / `/proc` / `getconf` / `tar` / `pgrep` and won't run on Windows |
| Web app | Runs on Linux and Windows. Only the `local` / `ssh` executors are Linux-oriented (`Executor::findBinary()` looks in `/usr/bin` and the like) |
| PHP | 7.4 – 8.3. CI runs `php -l` and the smoke tests on both 7.4 and 8.3 |
| Database | SQLite. The `db.driver` MySQL branch is a placeholder, **not** properly tested — don't use it in production |

### Things it won't do

- **Nothing schedules `monitor_server`.** It's a manual read-only health check; "server went
  down, bring it back" is `restart_server` plus the safeguards.
- **`agent_ip_allow` is configured only in the admin form**, nowhere else. Enabling it means
  the MC host is refused with a 403 the moment its IP changes — don't turn it on for
  home/dynamic IPs.
- **Panel adapters can't cover every version.** Override endpoint paths in the console when
  they differ; if that fails, fall back to RCON or the Agent.
- **Diagnosis is feature matching, not omniscience.** An uncovered error says "needs a human"
  rather than inventing a verdict.
- **Chinese UI only.** (These English docs exist; the interface itself is Chinese.)
- **No built-in 2FA.**

### Engineering

- **No conventional test suite.** This is the biggest piece of technical debt. `tests/run.php`
  is a set of dependency-free smoke assertions with deliberately narrow coverage; the real
  syntax guarantee is `php -l` in CI.
- **No screenshots or GIFs.** "What the UI looks like" above is prose.
- **Single maintainer.** Panel APIs and mod-loader log formats keep changing, and the
  adapters need the community to help fill the gaps.

### Security trade-offs (intentional)

- **Password recovery is CLI-only** (`bin/mcfix.php reset-password`). The web side
  deliberately has no recovery entry point — any web-reachable password reset becomes an
  unauthenticated privilege escalation the moment a config is broken.
- **Outbound requests verify TLS by default.** A self-signed BT Panel certificate requires
  explicitly ticking "skip HTTPS certificate verification" per server — and once you do,
  nothing protects the panel secret or log contents from a man in the middle.
- **Forwarded headers are trusted only from `trusted_proxies`.** If BT Panel's Nginx
  *appends* rather than overwrites `X-Forwarded-For`, leave `trusted_proxies` empty so the
  system looks only at `REMOTE_ADDR`.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: please use a private channel, see
[SECURITY.md](SECURITY.md).

**Especially welcome:** new client crash signatures (a regex in `src/ClientLog.php` plus a
verdict in `src/ClientIssue.php`), new panel adapters, and real deployment gotchas.

## License

[Apache License 2.0](LICENSE); copyright and attribution notices are in [NOTICE](NOTICE).

Plain-language summary (not legal advice — the [LICENSE](LICENSE) text governs):

| You may | You must |
|---|---|
| Use it, modify it, run it commercially, redistribute it **closed-source** | Keep `LICENSE`, `NOTICE` and the copyright notices (§4) |
| Charge for a hosted service without publishing your changes | Mark any file you changed as changed (§4b) — MIT did not require this |
| Rely on an explicit patent grant | Not use the "MCFix" name to imply endorsement (§6 grants no trademark rights) |

**Why Apache 2.0 instead of MIT:** §3 grants an explicit patent licence. MIT grants
copyright rights only and says nothing about patents; Apache 2.0 spells it out, which
matters for company and team users.

**One trade-off to know up front:** Apache 2.0 is **incompatible with GPLv2** (GPLv3 is
fine). If you wanted to merge your changes into a GPLv2 project, MIT was the licence that
allowed it. Otherwise this costs you nothing.

> Nothing third-party is bundled — the JS and CSS under `public/assets/` are hand-written
> (`app.js` opens with "no dependencies, plain vanilla JS"), so this relicensing involves
> no third-party grants and needs no extra third-party notices.

## Credits

Thanks to everyone running this on a real server and reporting what broke. Every changelog
entry notes which problem was found in a live deployment — those were worth more than any
unit test.
