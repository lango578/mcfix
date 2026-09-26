# Email Notification Configuration Guide

> 中文: [邮件通知指南.md](邮件通知指南.md) · English (this file)

> This document covers three things: how to turn on **player receipts**, how to turn on
> **admin alerts**, and — most importantly — **why you have to use SMTP on a VPS instead of
> installing Postfix**.

---

## 1. What These Emails Do

In the console, under System Settings (系统设置) → Email Notifications (邮件通知), you tick one switch and enter one email address, and everything else is automatic:

| Recipient | When | Content |
|---|---|---|
| **Player** | Immediately after they submit feedback | "We've received your feedback and are checking it now" + a ticket link |
| **Player** | When the issue is fixed / escalated to manual handling / rejected | The outcome + health-check details + **what they need to do themselves** |
| **You** | Every time new feedback arrives | Server, player, category, the player's own words, and a direct link to the ticket in the console |

The two player emails require the player to have entered an email address on the feedback page (optional — submissions work fine without one).

**When a ticket closes, the system masks the player's email address** (`ab***@qq.com`),
and the plaintext address in the mail queue is wiped the moment the message goes out — player
privacy is never kept around long-term.

> This email module is not the same thing as the **notification channels** under
> Notification Settings (通知设置): notification channels (DingTalk / WeCom / Feishu / Telegram…)
> push events to admins and group chats; this module handles email only — and player receipts
> exist nowhere else.

---

## 2. Why You Must Use SMTP (Important)

Many people's first instinct is "just install Postfix." **On a VPS, that road leads nowhere.**

What PHP's built-in `mail()` does is hand the message to the local mail service and tell it to **connect directly to the recipient's port 25** to deliver. But **the vast majority of VPS providers block outbound port 25** (anti-spam). We measured this on an ordinary Hong Kong VPS:

```
connect mx3.qq.com:25             → fails
connect mx1.qq.com:25             → fails
connect Gmail's inbound server:25 → fails
connect smtp.qq.com:465           → works
connect smtp.qq.com:587           → works
```

**So installing Postfix buys you nothing** — it just piles messages up in the queue. The UI looks like it's set up, but not a single message actually goes out.

Even if port 25 happens to be open, a VPS IP with no SPF / DKIM / reverse DNS will almost certainly be filed as spam or rejected outright by QQ and 163.

**The right approach**: enter a real mailbox account and its authorization code. The system signs in to a server like `smtp.qq.com` and lets the provider relay for you — deliverability, SPF and DKIM all come ready-made.

> Self-hosted setups at home or in the office, where port 25 isn't blocked, can still use
> `mail()` + Postfix — that path is kept: leave "Send via SMTP" unchecked and it falls back
> automatically.

---

## 3. How to Configure It (3 Steps)

### Step 1: Get an authorization code from your mailbox

Using QQ Mail as an example:

1. Open **mail.qq.com** in a desktop browser and sign in
2. Click **Settings** → **Account** at the top
3. Scroll down to **"IMAP/SMTP Service"** and click **Enable**
4. Send an SMS from your phone as prompted
5. You get a **16-character** authorization code — **copy it down**

> ⚠️ **The authorization code is not your QQ password**, and it's shown only once — close the page and it's gone.

How this works across providers:

| Mailbox | Where to get it | What you get |
|---|---|---|
| QQ Mail | Settings → Account → enable IMAP/SMTP | 16-character authorization code |
| 163 / 126 | Settings → POP3/SMTP/IMAP | Authorization code |
| QQ Enterprise Mail | Nothing to fetch | Your mailbox login password |
| Gmail | Google Account → Security → App passwords | 16-character app password |
| Aliyun Mail | Nothing to fetch | Your mailbox login password |

### Step 2: Enter it in the console

Console → System Settings (系统设置) → **Email Notifications** (邮件通知) → **Send Method** (发送方式):

| Field | QQ Mail | 163 | Gmail |
|---|---|---|---|
| ✅ Send via SMTP (用 SMTP 发信) | Tick it | Tick it | Tick it |
| SMTP Server (SMTP 服务器) | `smtp.qq.com` | `smtp.163.com` | `smtp.gmail.com` |
| Port (端口) | `465` | `465` | `587` |
| Encryption (加密方式) | SSL (465) | SSL (465) | STARTTLS (587) |
| Email account (邮箱账号) | Your QQ Mail address | Your 163 address | Your Gmail address |
| Password / authorization code (密码 / 授权码) | **the 16-character code from Step 1** | Authorization code | App password |

Then, further down, under **Recipients and switches** (收件人与开关):

- **Admin email** (管理员邮箱) — where you receive alerts (may be the same address as above)
- Tick "notify the admin when a player submits a new ticket" (玩家提交新工单时给管理员发一封)
- Tick "send the player the 'received' and 'result' emails" (给玩家发『已收到』和『处理结果』)
- Tick "replace the player's email with a mask once the ticket closes" (工单结束后把玩家邮箱换成掩码) — **we recommend leaving this on**

**Save.**

### Step 3: Click "Send Test Email" to verify

Enter a recipient address in the card in the right-hand column → click "Send one now" (立刻发一封).
**If nothing arrives, check the spam folder first.**

On failure it shows you the server's exact response — match it against this table:

| Message | What it means |
|---|---|
| `535` + "enter an authorization code" | You put the login password in the password field; change it to the authorization code |
| `550` + "recipient rejected" | The recipient address is misspelled |
| "Cannot connect to host:port" | Wrong server address or port, or your provider blocks that port |
| "Server does not support STARTTLS" | Port and encryption don't match: 465 goes with SSL, 587 goes with STARTTLS |
| "mail() returned failure" | SMTP isn't configured and the system fell back to `mail()` — go fill in SMTP |

---

## 4. Don't Forget the Scheduled Task

Email is sent **asynchronously**: submitting only enqueues the message. The actual sending is left to cron, which flushes a batch every minute and retries failures with a 1/3/10/30-minute backoff, up to 5 attempts.

**Without a scheduled task, mail just piles up in the queue forever.**

In BT Panel (宝塔面板), under Scheduled Tasks → Shell Script (计划任务 → Shell 脚本), every minute:

```bash
cd /www/wwwroot/mcfix && /www/server/php/83/bin/php bin/mcfix.php cron
```

> ⚠️ **You must use `bin/php`, not `php-fpm`.** Run `which php` over SSH first to confirm the path.
> If you put `php-fpm` there, the script simply never runs — and you get no error at all.

The Email Notifications card in the console shows the queue status (pending / sent / failed),
and you can click "Flush email queue" (催发邮件队列) to send a batch right away instead of waiting for cron.

---

## 5. Advanced: Configuring via `config.php`

Anything you can configure in the console can also be written in `config.php`:

```php
'email' => [
    'enabled'       => true,
    'admin_to'      => 'you@example.com',   // receives new-ticket alerts
    'from_name'     => 'MC 故障反馈系统',
    'notify_player' => true,
    'notify_admin'  => true,
    'purge_email'   => true,                // wipe the player's email when the ticket closes

    'smtp' => [
        'enabled'    => true,
        'host'       => 'smtp.qq.com',
        'port'       => 465,
        'encryption' => 'ssl',              // ssl(465) | tls(587) | none
        'username'   => 'youremail@qq.com',
        'password'   => '你的16位授权码',
        // QQ / 163 require the From address == the login account; aligned automatically by default, nothing to do
        'force_from_username' => true,
    ],
],
```

**The From address is aligned to the login account automatically** — QQ and 163 check this, and a mismatch is rejected with 550. It's an easy trap to miss, so it's handled for you by default (the `From:` header in the body is rewritten too).

---

## 6. FAQ

**Q: Can I use `no-reply@mydomain` as the sender?**

QQ and 163 check that the "From address == login account" and return 550 on a mismatch. If you really want to send from your own domain, apply for an enterprise mailbox, or use a service that supports custom domains such as Aliyun Mail or Tencent Enterprise Mail.

**Q: Can I use SendGrid / Mailgun / Aliyun DirectMail?**

Yes. Any service that offers SMTP will work — just fill in the server address, port, username and password. These services usually deliver better than a personal mailbox and suit servers with a large player base.

**Q: I'm not on a VPS — it's my own machine at home or in the office, and port 25 isn't blocked**

Then you can go the `mail()` + Postfix route: leave "Send via SMTP" unchecked and it falls back automatically. That said, setting up SMTP is usually less hassle and delivers better.

**Q: What happens if a player doesn't enter an email address?**

It doesn't affect submission — they just don't get those two emails. The player page states this explicitly.

**Q: How long is a player's email address stored?**

Only long enough to send those two emails. Once sent, the plaintext address in the queue is cleared immediately; when the ticket closes (fixed / escalated to manual handling / rejected / closed), only the mask `ab***@qq.com` remains in the database. If a player typed an email address into the free-text "contact details" field, that gets wiped too.

**Q: Can I send only to the admin and not to players?**

Yes — just untick "send to players".

**Q: I don't want email at all?**

Untick "Enable email" and nothing is sent; the ticket workflow is unaffected.

---

## 7. Related File Downloads

| File | Purpose |
|---|---|
| [`agent/mcfix-agent.php`](../agent/mcfix-agent.php) | The agent on the Minecraft machine (single file) |
| [`config.example.php`](../config.example.php) | Configuration reference, with full documentation of the `email` section |
| [`bin/mcfix.php`](../bin/mcfix.php) | Command-line tool (`cron` runs through it) |
| [`deploy/install.sh`](install.sh) | One-shot setup script |
| [`.github/workflows/ci.yml`](../.github/workflows/ci.yml) | CI (PHP 7.4 / 8.3 syntax check + smoke tests) |

Where to download the external dependencies:

| What you need | Download |
|---|---|
| Postfix (only needed if you use `mail()`) | Search and install it in the BT Panel App Store (软件商店); or `apt install postfix` / `yum install postfix` |
| Front-end mail testing tool (optional) | [Mailpit](https://github.com/axllent/mailpit) — receives mail locally, for debugging templates |
