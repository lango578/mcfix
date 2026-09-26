# Panel-Hosted Server Setup Guide

> 中文: [面板服接入指南.md](面板服接入指南.md) · English (this file)

> A "panel-hosted server" means a Minecraft server running inside a hosting panel: MCSManager,
> Pterodactyl (翼龙), the process daemon manager in BT Panel (宝塔; sold internationally as aaPanel),
> or a rented panel service like 简幻欢 or 雨云.
>
> These machines usually don't give you SSH, so "just install an agent on the MC box" isn't an option.
> How far you can get without an agent, how to configure it, and where you'll get stuck are covered
> below.

---

## 1. Three Tiers, with Very Different Capabilities

| | Option A: Panel API only | Option B: Panel API + RCON | Option C: Panel API + Agent |
|---|---|---|---|
| What you install on the MC machine | Nothing | Nothing (edit server.properties) | One PHP script |
| Best for | Rented servers; panels that only give you a web console | The best choice for the vast majority of panel-hosted servers | You own the machine and have SSH |
| Reading server logs for diagnostics | ✅ | ✅ | ✅ |
| Connectivity probe from the player's point of view | ✅ | ✅ | ✅ |
| Automatic whitelist add / unban / kick | ✅ (via the MCSManager or Pterodactyl panel console) | ✅ (through RCON, with command output echoed back, so it's more reliable) | ✅ |
| Automatic server restart | ✅ (panel power controls) | ✅ | ✅ |
| TPS / player count / ban list | ❌ | ✅ | ✅ |
| Process, disk and CPU metrics | Pterodactyl yes, others no | ❌ | ✅ |
| Back up worlds / pull mods back from the server | ❌ | ❌ | ✅ |
| Can BT Panel send game commands? | No (BT Panel has no console endpoint) | ✅ | ✅ |

How to pick:

- If you can edit `server.properties`, turn RCON on (Option B). This is the step with the best return
  for the effort.
- A rented server you can't modify, or one whose port isn't reachable: Option A still covers more than
  half of it, and whitelist adds and unbans are still fixed automatically.
- On a BT Panel server where you can enable RCON, do enable it. Otherwise not a single whitelist-style
  fix can be delivered.

---

## 2. Option A: Panel API Only

### 2.1 Common admin-console steps (identical across all four panels)

1. Log into the admin console → on the left, "Servers & Agents" (服务器与 Agent)
2. Find your server and click "Configure" (配置) in the top right to expand the form
3. Set "Executor" (执行器) to `none`. The dropdown shows this option as `none（只诊断）`, i.e. "diagnose only"
   > A panel-hosted server has no agent, so don't pick `agent`, or every check will keep reporting
   > "unable to collect" (无法采集).
4. Scroll down to the "Panel API" (面板 API) section and fill in:

   | Field | What to enter |
   |---|---|
   | Panel type (面板类型) | See the sections for each panel below |
   | Panel URL (面板地址) | The address you use to reach the panel |
   | API key (API 密钥) | The key generated inside the panel |
   | Request timeout in seconds (接口超时（秒）) | Leave it at 12 |
   | Skip HTTPS certificate verification (跳过 HTTPS 证书校验) | Check this only if the panel uses a self-signed certificate and you can't connect without it |

5. Check "Enable Panel API" (启用面板 API)
6. Save → go back to the server card. A "Test Panel API" (测试面板 API) button now appears in the top
   right; click it.

This step has to pass first. It tells you whether the connection succeeded and which capabilities it
detected, and when it fails it prints the exact URL it requested plus the panel's raw response, which
is the fastest way to track down a wrong ID.

You can also do this from the command line:

```bash
php bin/mcfix.php panel              # list every server with a panel configured
php bin/mcfix.php panel survival     # health-check one: connectivity → capabilities → try a log read
```

### 2.2 MCSManager

Enter these:

| Admin console field | What to enter |
|---|---|
| Panel type | `MCSManager` |
| Panel URL | For example `http://127.0.0.1:23333` or `https://panel.example.com` |
| API key | The API key generated under "User Info" in the panel's top right corner (an admin account's key carries admin privileges) |
| Remote service UUID | The node UUID, called `daemonId` in the official docs |
| Instance UUID | Your MC instance's UUID, called `uuid` in the official docs |
| Server directory inside the panel | The instance's file-manager root directory, for example `/opt/mc/survival` |

To find the two UUIDs, open this instance's console page and look at the browser's address bar: two
UUIDs appear there. The one that matches the entry in the "Remote Services / Nodes" (远程服务 / 节点)
list is the node UUID; the other one is the instance UUID.

If you're not sure, two methods always work:

1. Press F12 in the browser → Network, then send a command from the panel's console input box and look
   at the request the panel itself sends: check what `uuid` and `daemonId` are in it. This is reliable
   across versions.
2. Enter anything, then click "Test Panel API". On failure it prints the panel's raw response, which
   usually says outright which parameter is missing or which UUID doesn't exist.

About panel versions: over the years MCSManager has changed its endpoint paths and parameter names
several times (`remote_uuid` → `daemonId`, commands moved from the POST body to the query string, file
reads changed from `/api/files/download` to `PUT /api/files/`). The adapter tries both forms, so old
and new versions both work; it decides based on the `status` field the panel returns. If neither form
matches, override the paths using the method in 2.6 below.

Once it's configured you can read logs, send game commands (whitelist / unban / kick / save world /
reload plugins), power the server on and off, list directories and read files. What you can't get:
process lists, free disk space, TPS, CPU or memory (MCSManager's API doesn't expose these to external
callers).

### 2.3 Pterodactyl (翼龙), including Pelican

Enter these:

| Field | Value |
|---|---|
| Panel type | `翼龙 / Pterodactyl（含 Pelican）`, i.e. Pterodactyl including Pelican |
| Panel URL | For example `https://panel.example.com` |
| API key | `ptlc_...` (client key) or `ptla_...` (application key) |
| Key mode | See below |
| Server short ID | The segment in `/server/xxxxxxxx` in the panel's address bar |
| Server directory inside the panel | On Pterodactyl this is usually `/home/container` |

Which key type to use:

| | `ptlc_` (Client, recommended) | `ptla_` (Application) |
|---|---|---|
| Scope | This one server only | Panel-admin level |
| Leak risk | Small | Large |
| Capabilities | Send commands, power on/off, read logs, list directories, read resource usage | Same as the left column |

Prefer `ptlc_`. Create it in the panel under "Account → API Credentials" (账号 → API Credentials) and
grant it access to this one server only.

About the log path: Pterodactyl's file API is relative to the server root directory, so the "Log path"
(日志路径) field should be `logs/latest.log`, not `/home/container/logs/latest.log`. BT Panel is
different here and wants the full absolute path.

Once configured you can read logs, send game commands, power the server on and off, list directories,
and read CPU, memory and disk usage (unique to Pterodactyl). With those resource readings the system
can also recognize the case where the process is running but the port isn't reachable, instead of
wrongly concluding that the server is down.

### 2.4 BT Panel

Enter these:

| Field | Value |
|---|---|
| Panel type | `宝塔面板（MC 跑在本机）`, i.e. BT Panel with MC running on this machine |
| Panel URL | For example `https://127.0.0.1:8888` |
| API key | The key from BT Panel's "Settings → API Interface" (设置 → API 接口) |
| Process ID | The ID of that MC project in the process daemon manager |
| Server directory inside the panel | For example `/www/minecraft/survival` |
| Skip HTTPS certificate verification | BT Panel uses a self-signed certificate by default, so you'll usually need to check this |

BT Panel has no endpoint for sending a game command, so:

- What it can do: read logs, list directories, read files, start and stop processes
- What it cannot do: whitelist, unban, kick. Those have to go through RCON

So the right setup for a BT Panel server is "BT Panel API + RCON": the panel handles log reading and
process start/stop, RCON handles game commands. See the next section.

### 2.5 Rented servers / homegrown panels (custom HTTP endpoints)

For panels like 简幻欢 or 雨云, or a forwarding script you wrote yourself, use the "custom HTTP
endpoints" (自定义 HTTP 接口) adapter.

In the "Panel API" section, expand "Custom endpoint parameters" (自定义接口参数) and describe how
requests should be sent, using placeholders:

| Field | Example |
|---|---|
| Command endpoint URL (指令接口 URL) | `http://127.0.0.1:8080/api/console` |
| Power endpoint URL (开关机 URL) | `http://127.0.0.1:8080/api/power` |
| Log endpoint URL (日志接口 URL) | `http://127.0.0.1:8080/api/log?tail=2000` |
| Directory listing URL (列目录 URL) | `http://127.0.0.1:8080/api/files` |
| HTTP method (请求方法) | `POST` |
| Request body template (请求体模板) | `{"cmd":"{command}"}` |
| Power request body (开关机请求体) | `{"action":"{action}"}` |
| Output field path (回显字段路径) | `data.output` |
| Custom headers (自定义请求头) | One `Key: Value` per line, or a single JSON block |

Available placeholders: `{command}` (game command), `{action}` (start/stop/restart/kill), `{path}`
(file path).

Fill in only the ones you can: configure just the command endpoint and all you get is sending
commands; configure just the log endpoint and you can only diagnose.

> ⚠️ This adapter only ever sends game commands and power signals. It never splices player input
> into a shell, and it does not support arbitrary file writes. That's a design boundary, not an
> unimplemented feature.

### 2.6 When the endpoint paths don't match

With this many panel versions around, some built-in endpoint paths are bound to be wrong. When that
happens you don't need to change any code:

1. Open your panel in the browser and press F12 → Network, with the `Fetch/XHR` filter checked
2. In the panel, perform the action you want MCFix to perform (for example, send `list` from the console)
3. Look at that request's URL, method and request body
4. Go back to the MCFix admin console, expand "Endpoint path overrides" (接口路径覆盖) in the "Panel
   API" section, enter one `name=path` per line, and save

Available names (enter only the ones you want to override; leaving one out = use the built-in default):

| Name | Default path | Purpose |
|---|---|---|
| `command` | `/api/protected_instance/command` | Send a game command (new version) |
| `command_legacy` | `/api/instance/command` | Send a game command (legacy fallback) |
| `power_open` / `power_stop` / `power_restart` / `power_kill` | `/api/protected_instance/open` and so on | Power on/off (new version) |
| `power` | `/api/instance` | Power on/off (legacy fallback) |
| `outputlog` | `/api/protected_instance/outputlog` | Read the instance's console output (the main log-reading path) |
| `read_file` | `/api/files/` | Read file contents |
| `list_dir` | `/api/files/list` | List a directory |
| `download` | `/api/files/download` | Legacy download |
| `server` | `/api/client/servers/<id>` | Pterodactyl: connectivity test |
| `resources` | `/api/client/servers/<id>/resources` | Pterodactyl: resource usage |

After changing them, click "Test Panel API" to verify. If it still doesn't work, run
`php bin/mcfix.php panel <server_id>` to see exactly what was sent.

---

## 3. Option B: Panel API + RCON (recommended)

RCON is the Minecraft server's own remote console and has nothing to do with the panel. As long as you
can edit `server.properties`, you can turn it on.

### 3.1 Editing server.properties from the panel

Use the panel's file manager to open `server.properties` in the server root directory and add or
change:

```properties
enable-rcon=true
rcon.port=25575
rcon.password=replace-this-with-a-long-random-password
```

Then restart the server once from the panel; this setting only takes effect after a restart.

### 3.2 Entering the RCON settings in the MCFix admin console

Go back to "Servers & Agents → Configure" and, in the "RCON" section:

| Field | What to enter |
|---|---|
| Enable RCON (启用 RCON) | Check it |
| RCON host (RCON 地址) | See below |
| RCON port (RCON 端口) | `25575` (the same value you entered above) |
| RCON password (RCON 密码) | Exactly the same as in `server.properties`, with no leading or trailing spaces |

What you put in "RCON host" depends on where MCFix is installed:

| Situation | What to enter |
|---|---|
| MCFix and MC on the same machine (common with BT Panel) | `127.0.0.1` |
| MCFix on another machine, and the panel gives you a dedicated IP | The MC server IP the panel gives you |
| The panel keeps MC inside a container and only maps the game port | Most likely it won't connect. See the pitfalls below |

Save it, then on the server card click the diagnostics next to "Test Panel API", or just use the
command line:

```bash
php bin/mcfix.php diagnose survival Steve cannot_join
```

The output includes a `[✓] whitelist` / `[✗] whitelist` line, which tells you straight away whether
RCON is reachable.

### 3.3 What enabling RCON gets you

- Diagnostics: TPS, player count, whether the whitelist is on, whether a player is whitelisted,
  whether they're banned or muted
- Repairs: whitelist add, unban, unmute, kick, clear inventory, save world, reload plugins. You get
  the command output back, so the system can see directly whether the action really succeeded, which
  is more reliable than the panel console.

---

## 4. Option C: Panel API + Agent (the most complete)

If your panel can give you a shell (Pterodactyl lets you change the startup command on the Startup
page, BT Panel can open a terminal, or you already have SSH), then running an agent on the MC machine
fills in every remaining capability:

- Process, disk and CPU metrics
- World backups (`tar` up the world directory)
- Pull mod files out of the server's `mods/` directory and send them to players
- Restart via systemd / screen / tmux / a custom command

In this case set "Executor" to `agent`; the Panel API can stay enabled at the same time. The system
picks a route automatically based on capabilities: log reading prefers the panel, game commands prefer
RCON, restarts use the agent.

For the exact deployment steps, see the "Setup & Troubleshooting" (接入与排查) page in the admin
console, or step 5 of `README.md`.

---

## 5. Capability Matrix by Option (based on what the code actually does)

| Check / repair action | Panel API only | Panel API + RCON | + Agent |
|---|---|---|---|
| Player-side connectivity (MC protocol handshake) | ✅ | ✅ | ✅ |
| Server log analysis | ✅ requires `read_log` | ✅ | ✅ |
| Plugin error aggregation | ✅ requires `read_log` | ✅ | ✅ |
| Process CPU / memory | Pterodactyl ✅ / others ❌ | Same as the left column | ✅ |
| Port listening, process alive, free disk space | ❌ | ❌ | ✅ |
| TPS, player count | ❌ | ✅ | ✅ |
| Whitelist / ban / mute status | ❌ | ✅ | ✅ |
| Whitelist add / unban / unmute / kick / clear inventory | MCSManager, Pterodactyl ✅ / BT Panel ❌ | ✅ | ✅ |
| Save world / reload plugins | MCSManager, Pterodactyl (no output echoed) | ✅ with output echoed | ✅ |
| Restart the server | ✅ if it has the `power` capability | ✅ | ✅ most controllable |
| Back up worlds / retrieve mods / read-only health check | ❌ | ❌ | ✅ |

> With "Panel API only", checks it can't perform don't pretend to pass. The UI shows
> `[?] 无法在 MC 机器上采集该项数据：该服务器未配置执行器（只在面板侧诊断）` ("cannot collect this
> data on the MC machine; this server has no executor configured, panel-side diagnostics only"),
> while RCON-type checks show `[-] 未开启 RCON，无法用控制台指令验证` ("RCON is not enabled; cannot
> verify with console commands"). Both messages are normal, not a configuration mistake.

---

## 6. Troubleshooting Guide

Start with these two commands; they let you pin down 90% of problems yourself:

```bash
php bin/mcfix.php panel <server_id>       # Panel API: connectivity, capabilities, the actual request and response
php bin/mcfix.php diagnose <server_id> <player_name> cannot_join   # run a full diagnostic pass
```

| Symptom | Cause and fix |
|---|---|
| Clicking "Test Panel API" returns `Failed to connect` | The machine running MCFix can't reach the panel URL. The panel URL is wrong, the port isn't open, or the panel only listens on the internal network |
| Returns `401` / `403` / `apikey 无效` ("invalid apikey") | The key is wrong or lacks permissions. On Pterodactyl, make sure the key mode is right (`ptlc_` → client, `ptla_` → application) |
| Returns `404` | The panel version and the adapter disagree on endpoint paths → override the paths with the fields under "Custom endpoint parameters" |
| Certificate error | The panel uses a self-signed certificate. Switch to a trusted certificate if you can; only check "Skip HTTPS certificate verification" if you really can't |
| Returns `MCSManager 指令下发失败` ("failed to deliver command") | Usually a wrong instance UUID or remote service UUID; the error message includes the panel's raw response |
| Shows "未配置远程服务 UUID，无法通过面板读取文件" ("remote service UUID not configured; cannot read files through the panel") | Reading logs on MCSManager requires `daemon_id`; get it from the panel's "Remote Services" list |
| The log was read but it's empty | The "Log path" is wrong. BT Panel needs an absolute path; Pterodactyl and MCSManager need a path relative to the server directory |
| The UI is full of `[?] 无法在 MC 机器上采集` ("cannot collect on the MC machine") | Normal. The panel API can't get machine-side data like processes and disk; install an agent if you want it |
| It keeps saying "未开启 RCON，无法用控制台指令验证" ("RCON is not enabled; cannot verify with console commands") | Normal. Either go enable RCON, or accept that these checks can't be verified |
| It keeps reporting "服务端当前处于离线状态" ("the server is currently offline"), but the panel shows it running | See pitfall 1 below |
| The automatic whitelist fix shows "已投递" ("delivered") but there's no output | Normal. The panel console endpoint doesn't echo command output. Enable RCON to see the result, or go dig through the panel console |

---

## 7. Pitfalls Specific to Panel-Hosted Servers

### Pitfall 1: A wrong address mistaken for a dead server

The system decides whether the server is up by sending a single Minecraft protocol handshake from the
machine running MCFix, which is the equivalent of a player clicking "Join Server". So:

- The `host:port` you enter must be an address that is reachable from the MCFix machine
- If players connect through a domain with an SRV record, enter the real host:port. This system does
  not do SRV lookups, so it will not look at the `_minecraft._tcp` record
- If you enter the panel node's internal address, or a port that's only opened for game traffic, the
  handshake will fail

The system tells the difference itself. If the panel can provide process readings (Pterodactyl's
resources endpoint), or an agent reports that the process is running, it changes its verdict to

> Verdict (结论): cannot reach the MC port from this machine, but the server appears to still be running
> Problem code (问题码): `mc_unreachable` Severity (严重级别): high

and it will not restart a healthy machine. If you see this, check the address, the firewall and your
SRV record.

It only reports `server_offline` when there is genuinely no counter-evidence at all.

### Pitfall 2: Panel-hosted servers usually can't expose RCON externally

RCON is a TCP port the server itself listens on. With panel-hosted servers there are two common cases:

- The port is bound only inside the container or node, so MCFix can't reach it from outside and you're
  limited to sending commands through the panel console
- The panel lets you open it in the firewall: allow the MCFix machine's IP through, and don't forget
  to restrict the source while you're at it

MCSManager and Pterodactyl both have console endpoints, so "no RCON" does not mean "no automatic
whitelist fixes". BT Panel is the exception: it has no console endpoint, so without RCON you can't send
a single game command.

### Pitfall 3: Panel API keys are more powerful than you think

- An MCSManager API key is essentially panel-admin equivalent
- Pterodactyl's `ptla_` is panel-admin level; `ptlc_` works for a single server only
- A BT Panel API key lets whoever holds it read files on the machine

So: **use `ptlc_` whenever you can instead of `ptla_`**; use HTTPS for the panel URL wherever
possible; and don't check "Skip certificate verification" for a non-self-signed certificate. Once it's
checked, your key and log contents travel the network in plaintext and can be tampered with.

### Pitfall 4: The panel console gives no output, so "delivered" isn't "succeeded"

Both MCSManager's and Pterodactyl's console endpoints return as soon as the command is delivered; you
can't get the command's output. So the system can only say "delivered to the console". If you want a
definitive success/failure verdict, enable RCON.

(This is also why the "diagnose → repair → re-verify" chain matters: after a repair the system connects
once more to see whether the problem is still there, instead of taking the panel's "received" as
success.)

**There is one exception.** A handful of self-built or proxy endpoints really do echo back. For those,
pick the "Custom HTTP endpoint" (自定义 HTTP 接口) panel type and **fill in `output_path`** to say which
field of the JSON response carries the echo:

```php
'panel' => [
    'type'        => 'custom',
    'api_url'     => 'http://127.0.0.1:8080/api/console',
    'body'        => '{"cmd":"{command}"}',
    'output_path' => 'data.output',   // <- only this makes it usable for checks that parse output
],
```

With that set, the checks on this server (`list` / `tps` / `whitelist list` / `banlist`) can run over the
panel console when RCON is off — they no longer have to be skipped just because RCON isn't enabled.

Leave it unset and the endpoint is still treated as non-echoing. That is a deliberate trade-off: the
system would rather report "this check was skipped" than parse `success` out of
`{"message":"success"}` and present "the whitelist is empty" or "the player is offline" as a finding
when it was really a guess.

> So when a check reports "未开启 RCON，这项验证跳过", confirm two things: whether this server's panel
> endpoint echoes at all, and whether `output_path` is filled in correctly. `php bin/mcfix.php panel
> <server_id>` prints the actual request and response.

---

## 8. Each Tier Covers One Part

> The Panel API lets you see, RCON lets you act, and an agent lets you reach the machine.
>
> The reality of panel-hosted servers is that the first two are almost always available and the third
> usually isn't, and the first two already cover the whole main loop: "player can't get in →
> automatically whitelist/unban/restart → re-verify → notify you".
