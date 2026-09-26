# BT Panel Deployment Cheat Sheet

> 中文: [宝塔部署指南.md](宝塔部署指南.md) · English (this file)

> Goal: a feedback site running at `https://mc.example.com`, with players submitting feedback and the system verifying and repairing it automatically, inside 5 minutes.
> Throughout this document, replace `mc.example.com` with your own domain or server IP, and `81` with the PHP version you installed.

---

## Step 0: Confirm the environment

In BT Panel, check under "App Store → Installed" (软件商店 → 已安装):

| Item | Requirement | Where to change it |
|---|---|---|
| PHP | 7.4 / 8.0 / 8.1 / 8.2 / 8.3 | Install from the App Store (软件商店) |
| `pdo_sqlite` `mbstring` `json` | Required | PHP settings (PHP 设置) → Install extensions (安装扩展) |
| `openssl` | Strongly recommended (signed tokens, outbound HTTPS) | Same as above |
| `curl` | Optional; without it the system falls back to the stream method | Same as above |
| `sqlite3` (command line) | Optional, but backups need it | `apt install sqlite3` / `yum install sqlite` |
| `proc_open` | Optional in agent mode; must be unblocked for local/ssh | PHP settings (PHP 设置) → Disabled functions (禁用函数) → delete it |

Confirm from the command line (in the BT Panel terminal (终端)):

```bash
which php                                    # write this path down — the scheduled task needs it
php -m | grep -E 'pdo_sqlite|mbstring|openssl'
php -v
```

---

## Step 1: Upload the code

Option A (recommended: BT Panel's file manager):

1. "Files" (文件) → go into `/www/wwwroot/`
2. Create a new directory named `mcfix`
3. Upload the project archive into it → right-click "Extract" (解压) → confirm the structure is `/www/wwwroot/mcfix/public/index.php`

Option B (terminal):

```bash
cd /www/wwwroot
mkdir -p mcfix && cd mcfix
# after uploading mcfix.zip:
unzip -o mcfix.zip
ls -la    # you should see public/ src/ agent/ bin/ config.example.php
```

---

## Step 2: Create the site and point it at public

"Website" (网站) → "Add site" (添加站点):

- Domain: `mc.example.com`
- Root directory: `/www/wwwroot/mcfix`
- PHP version: the one you installed
- Database: do not create one

Once the site exists → click its name to open the settings:

1. "Site directory" (网站目录) → "Running directory" (运行目录) → select `/public` → Save ← the single most important step
2. "SSL" → Let's Encrypt → Apply (申请) → turn on "Force HTTPS" (强制 HTTPS)
3. In "Config file" (配置文件), confirm this block is present (BT Panel normally adds it by default):

```nginx
location ~ ^/(\.user.ini|\.htaccess|\.git|\.env|\.svn|\.project|LICENSE|NOTICE|README.md) {
    return 404;
}
```

> Why does the root have to point at `public`? Because `config/config.php` holds the signing key and the Agent token,
> and `storage/data/mcfix.sqlite` holds every ticket. Nginx never reads `.htaccess`,
> so pulling the site root down into `public/` is the only thing that genuinely keeps those files out of reach.

---

## Step 3: Permissions

In the BT Panel terminal (终端):

```bash
cd /www/wwwroot/mcfix
bash deploy/install.sh
```

This script automatically: creates every runtime directory under `storage/`, changes the owner to `www`,
sets `storage` to 775, locates the PHP path you installed, and runs an environment self-check plus doctor.

> Don't want to run the script? Here is the manual equivalent:
> cd /www/wwwroot/mcfix
> mkdir -p storage/{data,logs,cache,locks,ratelimit,keys} config
> chown -R www:www storage config
> chmod -R 755 .
> chmod -R 775 storage
> ```

Self-check:

```bash
sudo -u www test -w /www/wwwroot/mcfix/storage && echo "storage 可写 ✓" || echo "storage 不可写 ✗"
```

If BT Panel has "Anti-cross-site attack (open_basedir)" (防跨站攻击) enabled, confirm the allowed paths include
`/www/wwwroot/mcfix` (the site root is included by default, so you are fine).

---

## Step 4: The installation wizard

Open this in your browser:

```
https://mc.example.com/?r=install
```

1. **Environment check**: everything green → continue
2. **Admin password**: at least 8 characters; the site URL is filled in for you, so just confirm it starts with `https://` → save
   (this step also randomly generates the private admin entry path)
3. **Add your first MC server**:

| Field | Example | Notes |
|---|---|---|
| Display name (显示名) | Survival 1.20.1 | The name players see |
| Server ID (服务器ID) | survival | Leave it blank to generate one automatically |
| Address (地址) | mc.example.com | The address players connect to (you can also use the MC machine's internal IP) |
| Port (端口) | 25565 | |
| MC server directory (MC 服务端目录) | /www/minecraft/survival | The level that holds server.jar and logs |
| Log path (日志路径) | logs/latest.log | Relative to the MC directory |
| RCON password (RCON 密码) | (fill it in) | Strongly recommended — it lets many problems be fixed instantly |
| Guard method (守护方式) | systemd | Determines how "restart" is carried out |
| systemd unit name (systemd 单元名) | minecraft-survival | For screen/tmux, enter the session name |

When you finish, the page gives you four things:

- ✅ **Player feedback link** (post it in your group chat)
- ✅ **Admin panel URL** (of the form `https://mc.example.com/?r=console-a1b2c3d4e5f6a7b8`) — **bookmark it**
- ✅ The Agent's `config.php` contents
- ✅ The systemd deployment command

> **About the admin URL**: it and the player page are **two independent entry points**. Nothing on the player page links to it,
> and the admin's own login cookie applies only to that path. Treat this URL like a password — don't put it in announcements or screenshots.
> If you lose it, search `config/config.php` for `'path' =>` to get it back.

### Two things worth doing right away

1. Open the admin panel → "System settings → Admin entry and access control" (系统设置 → 后台入口与访问控制) → fill in the **IP allowlist**
   (only your own IP may get in, so even if the path leaks, nobody else can)
2. While you're there, change the admin path to a string you can actually remember (at least 10 characters); after saving you'll log in again at the new address

---

## Step 5: Deploy the Agent on the MC side

Do this on **the machine that runs the MC server** (not the BT Panel machine).

```bash
mkdir -p /opt/mcfix
cd /opt/mcfix
```

Upload the project's `agent/mcfix-agent.php` to `/opt/mcfix/`, then create `config.php`
with the block the installation wizard gave you in step 3 (it looks like this):

```php
<?php
return [
    'panel_url'  => 'https://mc.example.com/index.php?r=api.agent&action=poll',
    'server_id'  => 'survival',
    'token'      => '刚才生成的令牌',
    'mc_dir'     => '/www/minecraft/survival',
    'log_path'   => 'logs/latest.log',
    'listen_port'=> 25565,
    'disk_path'  => '/www/minecraft',
    'guard'      => [
        'type'    => 'systemd',
        'service' => 'minecraft-survival',
        'session' => '',
    ],
    'interval'     => 8,
    'poll_timeout' => 20,
];
```

Self-check plus a trial run:

```bash
php /opt/mcfix/mcfix-agent.php --check
php /opt/mcfix/mcfix-agent.php --once --verbose
```

`--check` prints every item one by one: PHP version, proc_open, whether mc_dir exists, the log file, panel connectivity, the process, the port, and the disk.
**Panel connectivity must read "正常 ✓" (OK)** — if it doesn't, go back and check `panel_url` and `token`.

Run it as a persistent service:

```bash
cat > /etc/systemd/system/mcfix-agent.service <<'EOF'
[Unit]
Description=MC Fix Agent
After=network-online.target

[Service]
Type=simple
ExecStart=/usr/bin/php /opt/mcfix/mcfix-agent.php
Restart=always
RestartSec=5
User=root

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now mcfix-agent
journalctl -u mcfix-agent -f      # watch the live log; Ctrl+C to exit
```

Back in the panel's "Servers and Agents" (服务器与 Agent) page, that server's card should now read **Agent online** (green dot).

---

## Step 6: Scheduled tasks on the panel side

BT Panel "Scheduled tasks" (计划任务) → "Add task" (添加任务):

- Task type (任务类型): Shell script (Shell 脚本)
- Task name (任务名称): MCFix maintenance
- Execution cycle (执行周期): **every minute** (每分钟)
- Script content (脚本内容):

```bash
cd /www/wwwroot/mcfix && /www/server/php/81/bin/php bin/mcfix.php cron
```

Then add a daily backup task (optional):

- Execution cycle (执行周期): every day at 04:00 (每天 04:00)
- Script content (脚本内容):

```bash
# use sqlite3's own .backup: the database runs in WAL mode, so a plain cp can give you an inconsistent snapshot
# (keep the inner single quotes — they are part of the .backup command's argument)
sqlite3 /www/wwwroot/mcfix/storage/data/mcfix.sqlite \
  ".backup '/www/backup/mcfix-$(date +\%F).sqlite'"

find /www/backup -name 'mcfix-*.sqlite' -mtime +30 -delete
```

If the `sqlite3` command is missing, install it first: `apt install sqlite3` (Debian/Ubuntu) or `yum install sqlite` (CentOS).

---

## Step 7: Turn on RCON (strongly recommended)

On the **MC machine**, edit `server.properties`:

```properties
enable-rcon=true
rcon.port=25575
rcon.password=用 openssl rand -hex 16 生成一串
broadcast-rcon-to-ops=false
```

```bash
# generate a password
openssl rand -hex 16

# restart the server (however you normally do it)
systemctl restart minecraft-survival
# or screen -S mc-survival -X stuff "stop\n" and let it come back up on its own

# confirm the port is listening
ss -lntp | grep 25575
```

Enter that password in the admin panel under "Servers and Agents (服务器与 Agent) → the server in question → Configuration (配置) → RCON password (RCON 密码)", tick "Enable RCON" (启用 RCON), and save.

> **Allow only the panel's IP** (run this on the MC machine; replace `面板IP` — "panel IP" — with your BT Panel server's public IP):
> ```bash
> iptables -I INPUT -p tcp --dport 25575 -s 面板IP -j ACCEPT
> iptables -A INPUT -p tcp --dport 25575 -j DROP
> ```

---

## Step 8: Acceptance check

In the BT Panel terminal:

```bash
cd /www/wwwroot/mcfix

# 1. overall self-check
/www/server/php/81/bin/php bin/mcfix.php doctor

# 2. check server and Agent status
/www/server/php/81/bin/php bin/mcfix.php servers

# 3. run a diagnosis directly (no ticket created)
/www/server/php/81/bin/php bin/mcfix.php diagnose survival Steve cannot_join

# 4. simulate a player submission and run the whole loop end to end
/www/server/php/81/bin/php bin/mcfix.php test-feedback survival Steve "进不去服了，提示不在白名单"
```

Step 4 prints: ticket number → verification verdict → the real result of every check → repair actions → re-verification results.
**If this step comes back all green, the entire chain works.**

Finally, take the **player feedback link** from the admin panel's "Onboarding and troubleshooting" (接入与排查) page, post it in your group chat,
and have a player open it on their phone and give it a try.

---

## Appendix: How to configure different server setups

The system has **6 execution channels**. They are all configured inside one server's configuration and can be stacked.
Diagnosis and repair **pick a route automatically based on the capabilities available**:

| Channel | What it can do | What it needs |
|---|---|---|
| **RCON** | Send game commands (whitelist, unban, kick, save world, reload) | `enable-rcon` on the server |
| **Panel API** | Read logs, send commands, power on/off, list directories, read files | The panel's API key (see below) |
| **Agent** | Everything: process, port, disk, CPU, logs, MOD list, restart | A small program running on the MC machine |
| **SSH** | Same as Agent, but the panel must be able to SSH into the MC machine | A key or a password |
| **local** | Execute directly when MC and the panel share a machine | `proc_open` not disabled |
| **none** | Panel-only direct probing (MC protocol + RCON) | — |

### Routing rules (know these and you won't misconfigure anything)

```
Reading logs for diagnosis → Panel API (read access is enough; nothing to install) → Agent → skip and explain why
Game commands              → RCON (it echoes back; most reliable) → Panel console
Process start/stop         → RCON stop + guard restart → Panel power → Agent
Process/port/disk          → Agent / SSH / local (the Panel API cannot see these)
```

### Scenario A: An MCSManager panel-hosted server

Admin panel → "Servers and Agents (服务器与 Agent) → Configuration (配置) → Panel API (面板 API)":

| Field | What to enter | Where to find it |
|---|---|---|
| Panel type (面板类型) | MCSManager | |
| Panel address (面板地址) | `http://127.0.0.1:23333` | The address you open the panel at |
| API key (API 密钥) | A 32-character string | Generate it in the panel under "Settings → API" (设置 → API 接口) |
| Remote service UUID (远程服务 UUID) | `xxxxxxxx-xxxx-...` | The UUID of that record in the panel's "Remote services" (远程服务) list |
| Instance UUID (实例 UUID) | `xxxxxxxx-xxxx-...` | The address bar after you enter the instance, or the instance settings |
| Server directory inside the panel (面板内的服务端目录) | something like `/home/container` | The instance root directory in the panel's file manager |

When you're done, click "Test panel API" (测试面板 API). Once it passes, you can — **without installing an Agent** — read logs for diagnosis, send whitelist/unban commands, and restart the instance.
If you also want the finer details (process, disk, MOD list), stack an Agent on top.

> If the test fails with a 404, it's most likely because this version uses different paths:
> override them in the config's `endpoints`, for example change the download endpoint to
> `{"download":"/api/files/download","outputlog":"/api/protected_instance/outputlog"}`.

### Scenario B: Pterodactyl (翼龙), including Pelican

| Field | What to enter |
|---|---|
| Panel type (面板类型) | Pterodactyl (翼龙) |
| Panel address (面板地址) | `https://panel.example.com` |
| API key (API 密钥) | `ptlc_...` (recommended — a server subuser key) or `ptla_...` (an admin key) |
| Key mode (密钥模式) | client / application |
| Server short ID (服务器短 ID) | the 8 characters in `/server/xxxxxxxx` in the panel's address bar |
| Server directory inside the panel (面板内的服务端目录) | `/home/container` |

**Prefer client mode**: the permission scope is narrower and the leak risk is lower. It can still send commands, power the server on and off, and read logs.
Only use application when you need to see resource usage (the `resources` endpoint).

### Scenario C: Running MC directly on BT Panel (panel and MC on the same machine)

This is the least hassle — use two channels together:

1. **BT Panel API** (read logs / list directories / start and stop processes)
   For "Panel API" (面板 API), choose the type "BT Panel" (宝塔面板), then fill in the panel address, the `API key` (API 密钥) (generate it in BT Panel under "Settings → API" (设置 → API 接口)),
   and the `Process ID` (进程 ID) (the ID of that MC project in Process Guardian (进程守护管理器) — you can see it in the list)
2. **RCON** (send game commands)
   Turn on `enable-rcon` in `server.properties` and enter the password in the RCON field

BT Panel's API signature scheme is fairly unusual (`request_token` + `request_sign`); it is already implemented in the code.
If a different BT Panel version makes the signature fail, the error message shows up directly in the "Test panel API" result.

### Scenario D: A rented server / web console only

简幻欢, 雨云, and all kinds of homegrown panels — usually no standard API, but they may have an HTTP endpoint that can send commands.

**Case 1: there is an HTTP endpoint** → for "Panel API" (面板 API), choose the type "Custom HTTP endpoint" (自定义 HTTP 接口), and fill in:

```
Command endpoint URL (指令接口 URL)     http://127.0.0.1:8080/api/console
Request body template (请求体模板)      {"cmd":"{command}"}          ← {command} is replaced with the game command
Echo field path (回显字段路径)          data.output
Log endpoint URL (日志接口 URL)         http://127.0.0.1:8080/api/log?tail=2000   ← required for diagnosis; with this, logs are analyzed automatically
Power endpoint URL (开关机 URL)         http://127.0.0.1:8080/api/power
Power request body (开关机请求体)       {"action":"{action}"}        ← {action} = start|stop|restart|kill
```

**Case 2: no endpoint at all** → all you can do is:
- Turn on RCON (most rented servers let you edit `server.properties`) to gain the ability to "send commands + query the whitelist"
- Or ask your provider to run the Agent on the machine (a few providers support this)
- If neither is available, the system can still produce a **complete client-side log analysis** — you'll just have to do the server-side repairs by hand

### Scenario E: A machine you manage yourself / a standalone host

Configure an Agent to get every capability; see Step 5 above. This is the most fully featured setup.

> **They stack**: configuring "Panel API + RCON + Agent" on the same server at once is best practice —
> the panel reads logs (fast), RCON sends commands (it echoes back), and the Agent handles the process and restarts (most controllable).

---

## Appendix: Nginx site configuration reference

If you don't want to use BT Panel's default template, or you want to add security response headers, you can write the site config as:

```nginx
server {
    listen 443 ssl http2;
    server_name mc.example.com;
    root /www/wwwroot/mcfix/public;   # key: point at public
    index index.php;

    ssl_certificate     /www/server/panel/vhost/cert/mc.example.com/fullchain.pem;
    ssl_certificate_key /www/server/panel/vhost/cert/mc.example.com/privkey.pem;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    # block sensitive files that get uploaded by mistake
    location ~ ^/(config|storage|src|bin|views|admin|deploy)/ {
        deny all;
        return 404;
    }
    location ~ /\.(?!well-known) {
        deny all;
        return 404;
    }

    # long cache for static assets
    location ~* \.(css|js|png|jpg|svg|ico|woff2?)$ {
        expires 7d;
        access_log off;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/tmp/php-cgi-81.sock;
        fastcgi_index index.php;
        include fastcgi.conf;
        fastcgi_read_timeout 120;   # diagnosis / waiting for the server to recover can exceed the 60s default
    }
}
```

We recommend relaxing `fastcgi_read_timeout` to 120 seconds: after a server restart the system has to wait for the port to come back,
and the 60-second default can land right on the boundary.

---

## Appendix: Upgrade procedure

```bash
# 1. back up (use .backup for the database, not cp — a hot copy under WAL mode can be inconsistent)
sqlite3 /www/wwwroot/mcfix/storage/data/mcfix.sqlite \
  ".backup '$HOME/mcfix-backup-$(date +%F).sqlite'"
cp /www/wwwroot/mcfix/config/config.php ~/mcfix-config-$(date +%F).php
tar czf ~/mcfix-code-$(date +%F).tar.gz -C /www/wwwroot mcfix     # keep a full copy of the code too

# 2. overwrite the code (keeping config/ and storage/)
cd /www/wwwroot/mcfix
unzip -o ~/mcfix-new.zip -x 'config/*' 'storage/*'

# 3. fix permissions + self-check
chown -R www:www . && chmod -R 775 storage
/www/server/php/81/bin/php bin/mcfix.php doctor

# 4. also update the Agent on the MC machine (if it changed)
systemctl restart mcfix-agent
```

The database schema is created idempotently: after you overwrite the code, the first request performs the migration automatically — no manual SQL needed.
