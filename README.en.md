# ⛏ MCFix — Minecraft Server Issue Reports with Automatic Repair

[![License: Apache 2.0](https://img.shields.io/badge/License-Apache_2.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B%20%7C%208.x-777bb4.svg)](https://www.php.net/)
[![Dependencies](https://img.shields.io/badge/dependencies-0-brightgreen.svg)](#why-zero-dependencies)
[![Database](https://img.shields.io/badge/database-SQLite-003b57.svg)](https://www.sqlite.org/)

> 中文文档：[README.md](README.md) · English (this file)

> A player taps a link in your Discord/QQ group → the system **actually connects to your
> Minecraft server** to verify the problem → whatever it can fix (whitelist, false ban,
> crash, plugin error) it **fixes on the spot** → re-verifies → notifies you.

**Who it's for:** solo server owners and small teams — especially anyone who can't sit in
the group chat 24/7.

**Deployment shape:** the web app runs on your panel box (PHP + SQLite, upload-and-go).
The Minecraft server sits on another machine and reaches back through a single-file
Agent that polls for work. **No inbound ports needed on the Minecraft host.**

> **Read this first.** What this project does — and what it deliberately does **not** do —
> is documented in [Known limitations](#known-limitations). Check that section before
> deploying; it will save you a round trip.

---

## Table of contents

- [What it actually solves](#what-it-actually-solves)
- [Client crash log analysis](#client-crash-log-analysis)
- [What the UI looks like](#what-the-ui-looks-like)
- [Quick start](#quick-start-bt-panel-about-10-minutes)
- [Architecture](#architecture)
- [Panel-hosted servers](#panel-hosted-servers)
- [Email notifications](#email-notifications)
- [Optional: LLM fallback](#optional-llm-fallback)
- [Configuration files](#configuration-files)
- [Why zero dependencies](#why-zero-dependencies)
- [Supported platforms](#supported-platforms)
- [Security design](#security-design)
- [CLI tools](#cli-tools)
- [Automation safeguards](#automation-safeguards)
- [FAQ](#faq)
- [Known limitations](#known-limitations)

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

**The core idea: players are not reliable narrators.** "I can't join" might mean they
aren't whitelisted, they're banned, the port isn't up yet — or the server is perfectly
fine and their client version is wrong.

**The system's first job is turning a player's claim into an objective fact.**
Only then does it decide whether to touch anything.

---

## Client crash log analysis

Client-side crashes are the majority of real-world reports and are impossible to diagnose
from a text description. Players can upload the crash report directly:

```
Player uploads crash-report / latest.log (or pastes the error)
        ↓
ClientLog parser: game version, mod loader, Java, memory, mod list,
                  exception stack, missing classes
        ↓
ClientAdvisor knowledge base: 37 rules → plain-language verdict
                              + exact steps the player should take
        ↓
Cross-check against the server's mod list: what's extra, what's missing
        ↓
Fixable server-side (whitelist/unban/restart) → handed to the executor
Client-side only → precise instructions instead of "try reinstalling"
        ↓
Missing mods: the Agent pulls the file from the server's mods/ directory
so the player downloads the exact matching version
```

Errors it recognises and explains out of the box:

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

## What the UI looks like

There are no screenshots in this repo (the author can't record the live environment, and
would rather not ship mockups). Three pages, described:

**Player feedback page** — a single dark-themed form. Pick your server → enter your game
ID → pick a category (can't join / crashed / lag / rollback / banned / plugin error /
report) → describe it, optionally attach a crash log. After submitting, the same page
turns into live progress: a list of checks flipping from "checking…" to ✅/⚠️/❌, with a
plain-language verdict underneath.

**Ticket detail page** — the full check breakdown, the verdict, what the system repaired,
the re-verification result, and (if a log was uploaded) the client crash analysis:
missing classes, suspect mods, Java/memory advice, and downloadable mod files.

**Admin console** — fixed left nav (Tickets / Ticket detail / Servers & Agent / Mod
distribution / Notifications / Event log / Settings / Setup & troubleshooting) with the
content on the right. Every automated action is written to the event log.

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
> (`sqlite3 ... ".backup ..."`). The database runs in WAL mode, so a plain `cp` can
> produce an inconsistent snapshot. `apt install sqlite3` / `yum install sqlite`.

### 2. Upload and point the site at `public/`

```bash
cd /www/wwwroot
mkdir mcfix && cd mcfix
unzip -o ~/mcfix.zip          # you should see public/ src/ agent/ bin/
bash deploy/install.sh        # creates runtime dirs, fixes permissions, self-checks
```

Then in BT Panel set *Website → Site settings → Website directory → Running directory* to
**`/public`**.

> ⚠️ **Do not skip this.** The source, `config/config.php` (containing your signing
> secrets) and the SQLite database all live one level up. Only rooting the web server at
> `public/` actually protects them. Nginx does not read `.htaccess` — this is path
> isolation, not file rules.

### 3. Configure Nginx and PHP-FPM

Ready-to-use templates (change the domains and paths):

- [`deploy/nginx.conf.example`](deploy/nginx.conf.example)
- [`deploy/php-fpm-pool.conf.example`](deploy/php-fpm-pool.conf.example)

> ⚠️ **The single most common failure: the FPM pool user doesn't match the Nginx user.**
> BT Panel's Nginx runs as `www`, while distro PHP packages default to `www-data`.
> The result is a **502 Bad Gateway**, and the nginx error log only says
> `connect() to unix:... failed (13: Permission denied)`.
> Set `user`, `group`, `listen.owner` and `listen.group` to `www`.

Full walkthrough: [`deploy/BT-PANEL-DEPLOYMENT.en.md`](deploy/BT-PANEL-DEPLOYMENT.en.md)
(中文: [`deploy/宝塔部署指南.md`](deploy/宝塔部署指南.md))

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

# Run it as a service
#   deploy/mcfix-agent.service  is a ready-made systemd unit
```

> **An Agent is optional.** With RCON configured you can already fix whitelists, bans,
> world saves and plugin reloads. Add a panel API (MCSManager / Pterodactyl / BT Panel)
> and the system can read logs too. The Agent is what unlocks **process-level** actions:
> restarts, backups, disk checks, and pulling mod files.

### 6. Add the cron job

**This is required** — without it, queued repairs never advance and emails never send.

```bash
# BT Panel → Scheduled Tasks → Shell script, every minute
cd /www/wwwroot/mcfix && /www/server/php/83/bin/php bin/mcfix.php cron
```

> ⚠️ It must be the **CLI** `php` binary, not `php-fpm`. Run `which php` first.
> Pasting `php-fpm` in produces a daemon that exits immediately with no error message.

See [`deploy/crontab.example`](deploy/crontab.example) for the full set (maintenance,
backups, certificate renewal).

### 7. Verify

```bash
php bin/mcfix.php doctor          # config + environment self-check
php bin/mcfix.php servers         # servers, agents, available channels
php bin/mcfix.php test-feedback survival Steve "can't connect"
```

The last one runs the entire loop (submit → verify → repair → re-verify) and prints what
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
| **RCON** | Game commands: whitelist, unban, unmute, kick, save, reload | `enable-rcon=true`; has echo, most reliable |
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

Two deployment shapes are supported: **separate domains** (recommended — real same-origin
isolation) or **same domain + random private path** (leave `domains.console_host` empty).

---

## Panel-hosted servers

If your Minecraft server runs inside a hosting panel (MCSManager, Pterodactyl, BT Panel, or
a rented panel with only a web console), you usually **can't install anything** on the
machine. The panel API channel still covers most of the loop.

Full guide: [`deploy/PANEL-SERVERS.en.md`](deploy/PANEL-SERVERS.en.md)
(中文: [`deploy/面板服接入指南.md`](deploy/面板服接入指南.md))

**"I rent from XXX host — will it work?"** See
[`deploy/HOSTS-INDEX.en.md`](deploy/HOSTS-INDEX.en.md)
(中文: [`deploy/主机商接入索引.md`](deploy/主机商接入索引.md)) —
a provider-by-provider matrix (each row tagged with its source: verified / third-party docs /
unverified), plus a three-minute checklist for figuring out your own panel without waiting for a guide.

Quick summary:

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

**Diagnose a panel config with one command:**

```bash
php bin/mcfix.php panel <server_id>
```

It prints the connectivity result, the detected capabilities, and **the exact HTTP requests
it made plus the panel's raw response** — which is what you want when a UUID was typed wrong.

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

**Also editable in the console**: Settings → Player feedback → "Log retention (days, 0 = keep)".
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

> If you need raw logs long-term for statistics, set `log_retention_days` to `0`.
> Check your local compliance requirements first.

---

## Email notifications

Players can optionally leave an email address on the feedback form. If they do:

1. On submission → "we've received your report, running checks"
2. On resolution / escalation → the result, with the check breakdown and **what they need to do**
3. When the ticket closes → the stored address is replaced with a mask (`ab***@qq.com`)

For you: set one admin address and you get a mail per new report — no notification channel
to configure, no event subscriptions to tick.

**On a VPS you must use SMTP, not `mail()`.** Most hosting providers block outbound port
25, so installing Postfix just queues mail forever. Use a real mailbox + its app password:

| Mailbox | SMTP host | Port / crypto | Password field |
|---|---|---|---|
| QQ Mail | `smtp.qq.com` | 465 / SSL | **16-char authorization code** |
| 163 / 126 | `smtp.163.com` | 465 / SSL | authorization code |
| Gmail | `smtp.gmail.com` | 587 / STARTTLS | app-specific password |
| Aliyun Mail | `smtp.mxhichina.com` | 465 / SSL | mailbox password |

Full guide: [`deploy/EMAIL-NOTIFICATIONS.en.md`](deploy/EMAIL-NOTIFICATIONS.en.md)
(中文: [`deploy/邮件通知指南.md`](deploy/邮件通知指南.md))

---

## Optional: LLM fallback

**This system does not require any AI service.** Every verdict comes from hard-coded rules
you can read and audit:

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

When the rule base doesn't match, the system can optionally ask a model. There are two paths:

| Case | Triggered when | What the model may do |
|---|---|---|
| **Client error** | the player attached a crash log and none of the 37 rules matched | write a plain-language verdict and steps the player can take; it **cannot** trigger any action |
| **Server fault** | server diagnosis ran and classified *no* issue at all | pick one entry from the list of recipes **this server can actually execute**; anything else falls back to human review |

Both paths are "rules first, model only when the rules come up empty". Common errors never
reach the model.

It speaks the **OpenAI-compatible `/v1/chat/completions`** protocol, so local and
third-party are the same configuration:

```php
'ai' => [
    'enabled'  => true,
    'base_url' => 'https://api.deepseek.com',   // or http://127.0.0.1:11434/v1 (Ollama)
    'api_key'  => 'sk-...',
    'model'    => 'deepseek-chat',
],
```

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

Configure it under *Settings → LLM fallback*. There's a "test connection" button that
reports latency and tokens used.

---

## Configuration files

Everything you can download and adapt:

| File | What it is |
|---|---|
| [`deploy/nginx.conf.example`](deploy/nginx.conf.example) | Nginx site config: both domains, HTTPS redirect, security headers, deny rules |
| [`deploy/php-fpm-pool.conf.example`](deploy/php-fpm-pool.conf.example) | PHP-FPM pool (including the `www` vs `www-data` 502 trap) |
| [`deploy/mcfix-agent.service`](deploy/mcfix-agent.service) | systemd unit for the Agent, with a least-privilege sudoers recipe |
| [`deploy/crontab.example`](deploy/crontab.example) | Cron entries (maintenance, WAL-safe backups, cert renewal) |
| [`config.example.php`](config.example.php) | Every configuration key, documented — including `email`, `ai`, `panel`, `domains`, `admin`, `notify` |
| [`deploy/install.sh`](deploy/install.sh) | Creates runtime directories, fixes permissions, prints the cron command |
| [`agent/mcfix-agent.php`](agent/mcfix-agent.php) | The single-file Agent that runs on the Minecraft host |
| [`bin/mcfix.php`](bin/mcfix.php) | CLI: `doctor`, `servers`, `panel`, `link`, `diagnose`, `tickets`, `test-feedback`, `cron`, `reset-password`, `rotate-all` |
| [`tests/run.php`](tests/run.php) | 158 smoke assertions, zero dependencies (`php tests/run.php`) |
| [`deploy/check-alt-syntax.js`](deploy/check-alt-syntax.js) · [`deploy/check-close-tag.js`](deploy/check-close-tag.js) | Offline structural checkers (not a substitute for `php -l`) |

---

## Why zero dependencies

No Composer, no npm, no framework, no ORM — only the PHP standard library and extensions.

The cost is reinventing some wheels: a hand-written Source RCON client, a hand-written
Minecraft Server List Ping implementation, hand-written webhook signing, and a hand-written
SMTP client.

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
| Rented panels | Use the "custom HTTP" adapter if it has an HTTP API; otherwise RCON |
| Self-hosted | Install the Agent for full capability |

Panel API paths drift between versions. Every adapter supports overriding endpoint paths
from the admin console, and **failures state exactly which endpoint failed and why** instead
of failing silently. Diagnose with `php bin/mcfix.php panel <server_id>`.

### Notification channels

DingTalk · WeCom · Feishu / Lark · Email · Bark · ntfy · ServerChan · Telegram ·
Discord · Generic webhook

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

Notes:

- **`unmute_player`** — vanilla Minecraft has no mute; it comes from plugins like Essentials,
  so this recipe issues `unmute <player>` and reports failure honestly if the plugin is absent.
- **`monitor_server`** — a read-only health check you can trigger manually. The system does
  **not** schedule it, and does not restart anything based on its output.

Read [SECURITY.md](SECURITY.md) and
[`deploy/SECURITY-NOTES.en.md`](deploy/SECURITY-NOTES.en.md)
(中文: [`deploy/安全说明.md`](deploy/安全说明.md)) for the threat model and deployment checklist.

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
php bin/mcfix.php reset-password  # reset the admin password
php bin/mcfix.php rotate-all      # rotate every public share link secret
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
| Max auto-fix attempts per ticket | 2 | Then escalates to a human |
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
<summary>502 Bad Gateway</summary>

**Nginx and PHP-FPM are running as different users.** BT Panel's Nginx runs as `www`; if
you configured the FPM pool as `www-data` out of Ubuntu habit, the socket permissions don't
match.

Fix: set `user`, `group`, `listen.owner` and `listen.group` in the pool to `www`.
See [`deploy/php-fpm-pool.conf.example`](deploy/php-fpm-pool.conf.example).
</details>

<details>
<summary>Logged in but it bounces straight back to the login page</summary>

Most likely a **cookie path mismatch**. This was a real bug in this project: the admin
console URL is `/index.php?r=<path>`, so the browser's request path is `/index.php` — a
cookie scoped to `/<path>` is never sent. Session isolation is achieved through
*different session names*, not cookie paths.

Check `app.base_url` matches the address you actually visit, and run
`php bin/mcfix.php doctor`.
</details>

<details>
<summary>Agent shows "never seen"</summary>

1. Can the MC host reach the panel? `curl -I 'https://your-feedback-domain/?r=api.agent.poll'`
2. Does the token match? The token in the admin server card must equal `token` in the
   Agent's `config.php`
3. Check the Agent log: `php /opt/mcfix/mcfix-agent.php --once --verbose`
4. If `agent_ip_allow` is set, confirm the MC host's egress IP is listed
</details>

<details>
<summary>Repair tasks stay "pending"</summary>

1. `systemctl status mcfix-agent` — is the Agent running?
2. Is the panel cron job running? (Check the event log for cron entries)
3. **Is the scheduled task using `php-fpm` instead of `bin/php`?** It must be the CLI binary
4. After 3–5 minutes the system escalates to a human automatically; it won't hang forever
</details>

<details>
<summary>Emails aren't arriving</summary>

**First: on a VPS you cannot send via `mail()`.** Most providers block outbound port 25,
so Postfix just queues forever. **Use SMTP.**

The email card under *Settings* shows queue state and the last failure. See the table in
[Email notifications](#email-notifications) and the full guide at
[`deploy/EMAIL-NOTIFICATIONS.en.md`](deploy/EMAIL-NOTIFICATIONS.en.md).
</details>

<details>
<summary>Backups</summary>

Use SQLite's own `.backup`, not `cp` — WAL mode means a hot copy can be inconsistent, and
the `-wal` / `-shm` sidecar files won't come along:

```bash
sqlite3 /www/wwwroot/mcfix/storage/data/mcfix.sqlite \
  ".backup '/www/backup/mcfix-$(date +%F).sqlite'"
```
</details>

---

## Known limitations

**Read this before deploying.** These are deliberate, documented boundaries — not
unfinished work.

### Platform limits

| Item | Reality |
|---|---|
| **Agent** | **Linux only.** It depends on `systemctl`, `screen`, `tmux`, `/proc`, `getconf`, `tar` and `pgrep` |
| Web app | Runs on Linux and Windows. Only `local`/`ssh` executors are Linux-oriented |
| PHP | 7.4 – 8.3. CI runs `php -l` and the smoke tests on both 7.4 and 8.3 |
| Database | SQLite. The MySQL branch exists but is **not** properly tested — don't use it in production |

### Things it won't do

- **Nothing schedules `monitor_server`.** It's a manual read-only health check; automatic
  restart is `restart_server` plus the safeguards.
- **`agent_ip_allow` is configured only in the admin form.** Enabling it breaks Agents on
  dynamic IPs.
- **Panel adapters can't cover every version.** Override endpoint paths when they differ.
- **Diagnosis is feature matching, not omniscience.** Unrecognised errors say "needs a human"
  rather than inventing a verdict.
- **Chinese UI only.** (These English docs exist; the interface itself is Chinese.)
- **No built-in 2FA.**

### Engineering

- **No conventional test suite.** `tests/run.php` is 158 dependency-free smoke assertions
  with deliberately narrow coverage; the real syntax guarantee is `php -l` in CI.
- **No screenshots or GIFs.**
- **Single maintainer.** Panel APIs and mod-loader log formats change constantly.

### Security trade-offs (intentional)

- **Password recovery is CLI-only** (`bin/mcfix.php reset-password`). Any web-accessible
  reset path becomes an unauthenticated privilege escalation the moment a config is broken.
- **Outbound requests verify TLS by default.** Self-signed panel certificates require
  explicitly opting out per server.
- **Forwarded headers are trusted only from `trusted_proxies`.** If your proxy appends
  rather than overwrites `X-Forwarded-For`, leave `trusted_proxies` empty.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: please use a private channel, see
[SECURITY.md](SECURITY.md).

**Especially welcome:** new client crash signatures (a regex in `src/ClientLog.php` plus a
verdict in `src/ClientIssue.php`), new panel adapters, and real-world deployment gotchas.

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
