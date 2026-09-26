# Security Design Notes

> 中文: [安全说明.md](安全说明.md) · English (this file)

This document explains why the code is written the way it is, so you can judge the risk boundaries when auditing it or building on it.

---

## 1. Trust Model

| Role | Can do | Cannot do |
|---|---|---|
| Player (holding a link) | Submit feedback, upload crash reports, trigger one verification, view the progress of their own ticket, download MODs that "the analysis decided they're missing" | Cannot specify which repair action runs, cannot see other tickets, cannot read any server-internal information, cannot use the download endpoint as a file browser, and **cannot find or get into the admin console** |
| Panel (this BT Panel machine) | Decide which **predefined** recipes run, dispatch parameters, send MOD files to players | **Cannot** execute arbitrary commands on the Minecraft machine |
| Agent on the Minecraft side | Run whitelisted recipes, collect read-only diagnostic data, upload files from the server's `mods` directory | Cannot execute arbitrary commands sent by the panel; unrecognized codes are rejected outright |
| Admin | Everything, including running recipes manually, approving high-risk actions and uploading MODs | — |

**The core invariant: a compromised panel ≠ a compromised Minecraft machine.** The worst an attacker can do is restart the server a few times (and there's an hourly quota on that); they cannot get a shell.

### Isolation of the two entry points (`src/ConsoleAuth.php`)

The player feedback page and the admin console are **two separate entry points**. The isolation measures, one by one:

| Measure | Implementation |
|---|---|
| Separate entry points | The player side has only `?r=feedback&t=...`; the console lives at a random private path from the config, generated at install time |
| Path secrecy | The console path looks like `console-<16 random chars>`; `?r=admin` returns a 404 carrying no information |
| Session isolation | Different session names (`mcfix_console` vs `mcfix_sid`); neither reads the other |
| Cookie attributes | Both sessions use `HttpOnly` + `SameSite=Lax` + `Secure` under HTTPS (`mcfix_start_session()`) |
| CSRF isolation | The console uses its own token (`console_csrf`) and never reuses the player side's |
| No cross-links in the front end | The player page contains no link to the console; when signed out, the console shows only "access verification" and gives away nothing internal |
| IP whitelist | `admin.ip_allow`, supporting single IPs / wildcards / CIDR; empty means unrestricted (we recommend filling it in) |
| Failure lockout | 6 failures within 15 minutes triggers a lockout, **counted per source IP only**; addresses in `admin.ip_allow` are exempt |
| Session fingerprint | The session stores a fingerprint of the password hash, so changing the password immediately invalidates sessions on other devices |
| No web-based recovery | A forgotten password can only be reset with `php bin/mcfix.php reset-password`; there is no reset entry point on the web |

**About the cookie `path`**: an earlier version tried to isolate the console by scoping its cookie to the console's private path, but the console address takes the form `/index.php?r=<path>`, so the browser sends the request with a path of `/index.php` — which doesn't line up with the cookie `path` at all. The result was that **you'd sign in and immediately appear signed out again**. Both sessions now use the site's base directory as their `path`, and isolation rests on **different session names** (plus the natural same-origin isolation you get from a separate domain).

**Note**: path secrecy only "reduces the exposed surface". What actually stops people is the combination of **password strength + IP whitelist + failure lockout**. In deployment, please do at least the first two.

> **Why the failure lockout no longer uses a global counter** (changed in 1.10.4 — please don't add it back):
> there used to be a global counter alongside the per-IP one, and hitting 6 locked the **entire console** —
> including the administrator, who could not even reach the login form. An attacker replaying 6 bad
> logins every 15 minutes could lock the admin out **permanently**. That traded availability for security
> in the wrong direction: what needs defending is "one source is brute-forcing", not "the whole world is".
> The per-IP counter is sufficient (a new IP starts from zero), and exempting whitelisted addresses means
> an administrator cannot be locked out by their own automation.

---

## 2. Repair Recipe Whitelist (`src/Recipe.php`)

Only these codes are valid, and `params` are type-checked field by field:

| code | Channel | Parameters | Risk | Action |
|---|---|---|---|---|
| `whitelist_add` | Panel RCON | `player` (3-16 chars of `\w`) | Medium | `whitelist add <player>` |
| `unban_player` | Panel RCON | `player` | High | `pardon <player>` |
| `kick_player` | Panel RCON | `player` + `reason` (≤80, control characters stripped) | Medium | `kick <player> <reason>` |
| `clear_self_items` | Panel RCON | `player` | High | `clear <player>` |
| `save_world` | Panel RCON | — | Low | `save-all flush` |
| `reload_plugins` | Panel RCON | — | Medium | `reload confirm` |
| `unmute_player` | Panel RCON / Agent | `player` | Medium | `unmute <player>` (mutes come from a plugin; vanilla has none) |
| `restart_server` | Agent | — | High | Restart according to the `guard` configuration (`systemctl` / `screen` / `tmux` / custom) |
| `backup_world` | Agent | — | Low | `tar -czf` to archive the world |
| `monitor_server` | Agent | — | Low | Read-only health check (process/port/disk), triggered manually — the system never calls it on a schedule |
| `pull_mod` | Agent | `component` (restricted character set) | Low | Locate a file under the server's `mods/plugins` and upload it to the site |

Key points:

1. **On the agent side, `run_recipe()` is a `switch` whose default branch returns failure immediately.** Pushing `rm -rf /` from the panel does nothing
2. Parameter validation happens on **both sides** — in the panel (`Recipe::validateParams`) and on the agent — and player input always goes through validation first
3. Custom commands like `guard.restart_cmd` / `start_cmd` can only be configured by an admin in the console; players never reach them
4. Recipes can be switched off per server, item by item (checkboxes in the console); a VIP server can keep only "diagnose"

---

## 3. Signed Tokens (`src/Token.php`)

A token is a self-contained `HMAC-SHA256(payload) + payload` format that needs no storage:

| Purpose | scope | Signing key | Validity | Revocable |
|---|---|---|---|---|
| Public player link | `f` + nonce=`share` | Per-server `share_secret` | 30 days | Click "Regenerate" in the console to invalidate old links |
| Ticket link | `f` + ticket nonce | Global `hmac_secret` | 24 hours | Admin clicks "Reset feedback link" |
| In-page verification | `v` + random nonce | Global `hmac_secret` | 30 minutes | **Single-use** — spent on first use |
| MOD download | `d` + filename | Global `hmac_secret` | 7 days | Renaming the file or switching servers makes it unusable |
| Agent token | `a` | Global `hmac_secret` | Long-lived | Regenerated in the console |

Design considerations:

- The token binds `feedback_id` + `server_id`, so it cannot be used across tickets or servers
- Public links use a **per-server key**, so rotating it leaves agent tokens and ticket links untouched
- The nonce of a verification token is recorded in the file cache, and reuse of *that* token is rejected.
  **To be precise:** each token is spent on first use, but that is **not** a "one verification per person" limit —
  the `progress` endpoint hands out a **fresh** usable token every call, so a ticket-link holder can trigger
  diagnoses repeatedly. The real gate is the **per-source-IP rate limit** (40/hour by default,
  `feedback.verify_per_hour`). The tokens themselves are unforgeable (HMAC), so this is not an
  authentication bypass — the point is simply not to describe a rate limit as "single-use".
- Validation accepts the global key and every server's `share_secret` as candidates, so during a key rotation there's a brief compatibility window for old tokens

---

## 4. Rate Limiting and Risk Controls

| Location | Rule | Implementation |
|---|---|---|
| Submitting feedback | 5 per IP per hour | `Rate::hit()`, file buckets + flock |
| Triggering verification | 40 per IP per hour | Same as above |
| Fetching a MOD file | 20 per IP per hour | Same as above |
| Agent polling | 900 per server per hour | Same as above (the normal 8-second interval = 450) |
| Console login | 6 failures → 15-minute lockout | Same as above |
| Auto-repair | At most 2 per ticket | `automation.max_fix_attempts` |
| Restarting the server | At most 3 per server per hour | `guard.max_restarts_per_hour` |
| Consecutive failures | 3 trips the breaker to "diagnose only" | `automation.circuit_breaker_failures` |

Why rate limiting uses files instead of the database: hammering an endpoint makes SQLite take write locks constantly, whereas file buckets avoid touching the database entirely. Bucket files are sharded by time window, and `Rate::gc()` cleans them up in cron.

---

## 5. Safe Handling of Client Logs

Player-uploaded logs are **the most dangerous class of untrusted input**: hundreds of KB of arbitrary text, uploaded from the public internet. The handling constraints are as follows:

| Risk | Handling |
|---|---|
| Huge files exhausting memory | 512 KB per-file cap, anything larger is rejected outright; past that only the first and last 256 KB are kept (`ClientLog::MAX_BYTES = 256 KB`) |
| ReDoS (regex backtracking blowup) | Lines truncated to 600 characters and total lines to 6000; every detection regex is a simple character class with no nested quantifiers |
| Binary / encoding bombs | UTF-16 / GBK are detected and converted to UTF-8 first; NUL and control characters are stripped |
| Path traversal (writing filenames inside logs) | Log content is only ever parsed and is **never used to build any file path** |
| Instructions smuggled in logs | Parse results are treated purely as data; all copy shown to players comes from the server-side knowledge base, and log content is displayed only after `e()` escaping |
| Extracting server information via logs | The player side returns only fields that concern the player themselves (version, loader, MOD names) — it **never returns the server's MOD list, internal paths, or suspicious package names** |
| Real logs as social-engineering material | Raw logs are visible only to admins (in the console's ticket detail, truncated to 20000 characters when displayed) |
| **Raw text kept forever** | Once a ticket has been closed for `feedback.log_retention_days` days (default 30), cron clears `client_log` / `client_log_name` — **the raw text goes, the conclusions stay** |

### Log retention period (since 1.12.4)

A crash report carries the player's Windows username, GPU model, game ID and server
address. Before 1.12.4 **nothing ever deleted it** — the only `DELETE` in the whole
project targeted `client_issues`, so tickets and logs were kept forever. Email had a
purge mechanism all along (`TicketMail::purgePlayerEmail`); that asymmetry was an
oversight, and it is now closed.

The safety boundaries (each has an assertion in `tests/run.php`):

- **Only tickets in a terminal state (`closed` / `resolved` / `rejected`) are touched.**
  An in-flight ticket may still need re-analysis, and re-analysis is impossible once
  the raw text is gone — a dedicated negative control guards this boundary.
  Earlier builds only recognised `status = 'closed'`, on the reasoning that "a `resolved`
  ticket may still need re-analysis". But nothing ever advanced `resolved` to `closed`
  except a daily cron pass, so those logs were in practice **never purged**. Treating
  all three terminal states alike closes that hole.
- `log_retention_days = 0` disables the feature entirely; nothing is touched.
- Already-purged rows (`log_purged_at` set) are not processed twice.
- Every purge is recorded in the event stream (`log.purged`) and is visible to the
  player, so logs don't just silently vanish.

`php bin/mcfix.php cron` reports how many were cleared (`清理日志原文 N`).

### Our own logs must not contain credentials

Everything above is about player logs. **The logs we generate are an exposure surface
too** — they get backed up, shipped to log collectors, and casually `cat`-ed. Three
places that were leaking:

| Where | Problem | Fix |
|---|---|---|
| nginx access log | The feedback link carries its signed token in the query string (`?r=feedback&t=…`), and the default log format records the whole thing — anyone who can read the log can open that player's ticket for as long as the token lives | The feedback site now uses a format without the query string (`$uri` instead of `$request`); see `deploy/nginx.conf.example`. The console site has no tokens in its URLs and is unchanged |
| `events` table | The "test email" action wrote the recipient address in full, permanently | Recorded events pass through `TicketMail::maskEmailsInText()`; the immediate on-screen notice still shows the full address |
| `storage/logs/app-*.log` | Upstream errors often echo the API key back (`Invalid API key: sk-…`), and relying on each call site to remember is not a control | `app_log()` runs everything through `redact_secrets()` |

`redact_secrets()` **masks credentials only and keeps IPs and emails intact**, deliberately:
"which IP was rejected by the allowlist" and "which IP failed to log in" are the most
useful lines when debugging. Full redaction belongs at the call site (`TicketMail` uses
`maskAddress` for its own logs), not as a blanket rule here. An assertion in
`tests/run.php` pins that trade-off so nobody "improves" it into a blunt tool.

> **Upgrade note**: if you are coming from 1.12.7 or earlier, the log format in
> `deploy/nginx.conf.example` is **not** applied to existing sites automatically.
> Add the `mcfix_noquery` format to the feedback site's `access_log` by hand (snippet at
> the top of that file). Until then, tokens already recorded in historical logs remain
> usable for the rest of their lifetime.

**The fourfold restriction on MOD distribution** (the only channel that sends files to players):

1. Files can only be **uploaded by an admin** or **pulled by the agent from the server's mods directory** — players cannot specify an arbitrary path
2. On the agent side, only `.jar` files under `mods/` are allowed, matched by normalized name, at most 64 MB per file. (Earlier builds also scanned `plugins/`; that was wrong — plugins are server-side modules that a client never needs, and listing them tells players what the server runs. The agent only reads `mods/` now.)
3. The component name a player requests must **appear in their own ticket's analysis results**, otherwise the request is refused — `mod_request` in `api.php` checks `client_analysis.needs.components` against the `suspects` list, so this endpoint can't be used as an arbitrary file downloader
4. Download links are **signed tokens** (`Token::SCOPE_DOWNLOAD`, bound to the filename with a 7-day validity), so renaming a file can't be used to enumerate what's in the library

On top of that, ingestion verifies the `PK\x03\x04` magic number (confirming it's a zip/jar), and the agent compares sha256 when it reports back.

### The feedback page does not expose the server's connection address (since 1.13.1)

The feedback page used to print each server's `host:port` straight to the player:
once in the server-status card, once in the "which server is affected" dropdown.
The anonymous `?r=api&action=bootstrap` endpoint returned `host` / `port` in its
JSON as well.

That hands the MC server's **directly connectable address** to anyone holding a
feedback link. Port scanning, DDoS, and direct-connect attempts that bypass the
whitelist all start from that address — and the whole point of a feedback link is
to be posted in a group chat for players to click, so its reach is unbounded. An
address is connection information; it is not something a player needs.

The rules now:

| Surface | What it shows |
|---|---|
| Player feedback page (status card, dropdown) | `code・name`, e.g. `S1・Survival 1.20.1`. The code comes from `servers[].code`; if left blank only the name is shown |
| `?r=api&action=bootstrap` | Only `id` / `code` / `name` / `label` — **no** `host` / `port` |
| `?r=api&action=ping` | Only `online` / `players` / `max`. It used to return a `message` that, on failure, was the raw `stream_socket_client()` error — which carries the connection target. That field is gone |
| The `feedback.diagnosis` column | Stores only `id` / `code` / `name` / `label`, never `host` / `port`. This column feeds the ticket, so if a "show the player the raw diagnosis" view is ever added, the address will not leak along with it |
| Admin server-config page | Still shows the full address — an administrator needs it to reconcile against the hosting panel |

Two assertions in `tests/run.php` guard this: the behaviour of `server_public_label()`,
and a source-level check that the three player-reachable files
(`views/player-form.php`, `views/player-ticket.php`, `public/controllers/api.php`)
**no longer contain** any `host` / `port` value expression. The latter is a
source-level guard — template output is hard to assert on directly, so the guard
watches the syntax instead.

### Per-server links do not lock the server when multiple servers exist (since 1.13.1)

An owner running several servers gets one public feedback link per server. The link
used to merely **pre-select** that server in the dropdown; every other server was
still listed, and the backend only ever looked at the submitted form value.

So a link shared in server A's group could be submitted with the server changed to
B — and the system would genuinely go and diagnose B, even run repairs there. That
is not privilege escalation (B belongs to the same owner), but it burns B's risk
budget (restart quotas are counted per `server_id`) and files the ticket under B,
which misleads the owner when they review the console.

The fix has two layers:

| Layer | What it does |
|---|---|
| Frontend | Arriving via server A's link, the dropdown lists only "not server-specific" plus server A |
| Backend | On submit, `resolve_locked_server_id()` re-checks: the server decoded from the token > the one held in session > no lock |

**Why the session layer is required**: the token alone cannot stop a deliberate
bypass — someone holding A's link can hand-craft a POST with no `share_token` and
`server_id` set to B, and the backend has no way to tell them apart from a player
who legitimately picked B from the homepage.

Two exceptions must be preserved: "not server-specific" (an empty value) is still
accepted while locked (a client crash has nothing to do with which server), and a
locked server that has since been deleted from the config is not treated as a lock.

Known boundary: players with cookies disabled get no session and fall back to the
pre-fix behaviour. This degrades silently and does not error.

---

## 6. Input Handling

- All output goes through `e()` (`htmlspecialchars` + `ENT_QUOTES`)
- Every database query is a PDO prepared statement with no string concatenation (the only concatenated part is `LIMIT`, forced to an integer via `min`/`max`)
- Player ID whitelist regex: `^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{2,16}$`
- Description text is truncated with `mb_substr` before newlines and control characters are processed
- JSON request bodies are capped at 256KB; client logs have their own separate 256KB cap
- Every write operation in the console enforces CSRF token validation (`ConsoleAuth::checkCsrf()`, compared with `hash_equals`).
  **The one exception is step 2 of the install wizard** (setting the admin password) — there is no console session
  at that point, and its gate is "`config/config.php` does not exist"; once installed the step is unreachable.
  Step 3 (writing server config, which includes the RCON password and the agent token) **does** validate CSRF
- Player-side write operations require `X-Requested-With: XMLHttpRequest`, used together with signed tokens
  (the player side has no identity, so it needs no CSRF token — the hidden `csrf` field on the page in earlier versions was never actually validated and has been removed)

---

## 7. SSRF and Outbound Requests

There are only three cases where the system connects outward, and every target comes from **admin configuration**, never from player input:

1. Minecraft protocol handshake / TCP probe → `server.host:port`
2. RCON → `server.rcon.host:port`
3. Agent → `panel_url` (configured by the admin on the Minecraft machine)

`Net::normalizeHost()` rejects any hostname carrying a scheme, a path or special characters, allowing only domains / IPv4 / IPv6.

**Note**: `host` may be an intranet address (this is a necessary feature — for instance when the panel and Minecraft sit on the same intranet). If a console account leaks, an attacker can add a "server" in the admin console pointing at some intranet port to run a port scan. That's why the console password strength matters more than anything else — and why login failure lockout is on by default.

---

## 8. Agent-Side Security

- Runs only in `--once` or resident mode, with no HTTP listening port
- Executes commands from **three sources** via `proc_open` only: admin-configured `guard.*_cmd`, internally assembled `systemctl` / `screen` / `tmux` commands, and the `tar` packaging command
- Every path interpolated into a command goes through `escapeshellarg()`
- Task claiming uses optimistic locking (`UPDATE ... WHERE status='queued'`), so two agents racing for the same task won't execute it twice
- Tasks carry leases and timeouts, so an agent going offline won't wedge a ticket (`Task::reclaim()` recovers them)
- Uploads are checked for sha256 and size cap; reading `mods.toml` / `fabric.mod.json` inside a jar is used only to identify the mod id
- We recommend running the agent as a **dedicated user** with permission only to restart the server (a sudoers whitelist), rather than as root

Minimal-privilege example (`/etc/sudoers.d/mcfix`):

```
mcfix ALL=(root) NOPASSWD: /bin/systemctl restart minecraft-survival, /bin/systemctl status minecraft-survival
```

---

## 9. Known Boundaries (Deliberately Not Solved)

1. **The panel machine is the single root of trust.** Once the panel is fully controlled, an attacker can change configuration and read every ticket and log in the database. Harden it as you would any web application (HTTPS, strong passwords, regular PHP updates).
2. **A leaked agent token = the ability to repeatedly trigger whitelisted actions and to read jars from the server's mods directory.** We recommend setting `agent_ip_allow` in the console's server configuration to restrict source IPs; to rotate the token, click "switch to a new token" and enter `__new__`.
3. **Opening a public link means opening submissions to everyone.** If it gets abused, respond with rate limiting + a shorter validity window + key rotation when necessary.
4. **Diagnostic accuracy is not 100%.** For instance, if a player says the server is laggy but TPS is normal, the system will honestly tell you "no anomaly detected" and then escalate the ticket to manual handling — this is intentional: better to do nothing than to act blindly.
5. **Client log parsing is feature matching, not omniscience.** For errors it doesn't cover, it will say plainly that "a full crash report is needed" or escalate to manual handling, rather than forcing out a conclusion.
6. **TLS verification for outbound requests is on by default.** It is disabled only when an admin explicitly ticks "skip HTTPS certificate verification" in the server configuration; once ticked, the panel API key and log contents may be visible to, or altered by, a man in the middle.
7. **`guard.*_cmd` is the escape hatch for "trusted local configuration."** The agent executes custom commands the admin wrote in the Minecraft machine's `config.php` verbatim; nothing the panel dispatches can ever reach this path.

---

## 10. Deployment Checklist

Tick these off before going live:

- [ ] The site's document root points at `public/` (otherwise the SQLite database and `config.php` may be downloadable)
- [ ] HTTPS is in use with a valid certificate
- [ ] The console password is ≥ 16 random characters and has never appeared in chat logs or tickets
- [ ] `admin.ip_allow` is filled in (at minimum your own usual network range)
- [ ] `trusted_proxies` matches your Nginx configuration (leave it empty if you're unsure)
- [ ] The scheduled task uses `bin/php`, not `php-fpm`
- [ ] `config/config.php` has permissions 640, owned by the same user FPM runs as
- [ ] `php bin/mcfix.php doctor` is all green
- [ ] The notification channel's "Send Test" was clicked and the message actually arrived
- [ ] The backup task uses `sqlite3 ... ".backup ..."` rather than `cp`
