# Hosting Provider Index

> This document answers one question: **"I use XXX host — can MCFix connect to it, and how?"**
>
> For the underlying mechanics (execution channels, capability degradation, troubleshooting)
> see [PANEL-SERVERS.en.md](PANEL-SERVERS.en.md). This file only collects **provider-specific facts**.

---

## First, how much to trust this document

Every row is tagged with its **source**. Judge accordingly:

| Tag | Meaning |
|---|---|
| ✅ **Verified** | Someone actually configured it and ran it |
| 📄 **Third-party docs** | From a third-party integration guide — **not** tested by me or by a user. Please confirm yourself |
| ❓ **Unverified** | Not checked yet. Do not guess from this |

**Why so careful:** hosting providers change panels and close API pages all the time, and a
**wrong tutorial is worse than no tutorial** — the user assumes they mis-filled a field and
wastes an afternoon on it.

---

## Providers we have checked

### BisectHosting

| Item | Value | Source |
|---|---|---|
| Panel | Starbase (**built on Pterodactyl**) | 📄 [DeployHQ](https://www.deployhq.com/guides/bisecthosting) |
| MCFix panel type | `翼龙 / Pterodactyl（含 Pelican）` | Follows from the row above |
| SFTP | Port **2022** (not 22) | 📄 Same |
| SSH | ❌ Not offered | 📄 Same (SFTP only is mentioned) |
| RCON | Enable it yourself in `server.properties` | ❓ Panel toggle location unconfirmed |
| Full guide | [BisectHosting guide](BisectHosting接入指南.md) (Chinese) | ✅ Written |

### PebbleHost

| Item | Value | Source |
|---|---|---|
| Panel | Game Panel (**built on Pterodactyl**) | 📄 [DeployHQ](https://www.deployhq.com/guides/pebblehost) |
| MCFix panel type | `翼龙 / Pterodactyl（含 Pelican）` | Follows from the row above |
| SFTP | Port **2022** (not 22) | 📄 Same |
| SSH | ❌ Not offered | 📄 Same (SFTP only is mentioned) |
| RCON | ❓ Unconfirmed | — |
| Full guide | Not written yet — the BisectHosting steps apply directly | — |

> 📌 **The pattern is already visible:** Pterodactyl-based providers work almost identically —
> the `/server/<short-id>` segment in the panel URL becomes the "server short ID"; create a
> `ptlc_` key under `/account/api`; container directory is `/home/container`; set the executor to `none`.
> **So the BisectHosting guide works as a template.**

---

## Pterodactyl-based hosts: the shared setup steps

**BisectHosting / PebbleHost / Sparked Host / GGServers are all Pterodactyl-based, and the flow is identical.**
Below is the short version; the full version (with per-step explanations and a troubleshooting table) is in the
[BisectHosting guide](BisectHosting接入指南.md) (Chinese) — use it as the template.

**Step 1 · Get the server short ID**

Look at the panel URL: `https://<panel-domain>/server/3f2a1b9c` → `3f2a1b9c`.

**Step 2 · Create an API key**

Open `https://<panel-domain>/account/api` → Create API Key → tick everything (**it only affects your own server**)
→ copy the `ptlc_…` key (**shown once**).

> If that page 404s, the host disabled the API → RCON is your only route. See
> [PANEL-SERVERS.en.md](PANEL-SERVERS.en.md), option B.

**Step 3 · Fill it into MCFix**

Console → Servers & Agent → edit the server → Panel API:

| Field | Value |
|---|---|
| Type | `翼龙 / Pterodactyl（含 Pelican）` |
| Key mode | `client（ptlc_，权限小）` |
| Panel URL | `https://<panel-domain>` (no trailing slash, no `/server/xxx`) |
| API key | the `ptlc_…` from step 2 |
| Server short ID | the segment from step 1 |
| Server directory | `/home/container` |

**Step 4 · Set the executor to `none` (the step people get wrong)**

Pick **`none（面板服选这个：用面板 API 修）`**.

**Getting this wrong produces no error at all** — choose `agent` and tasks sit at "queued" forever while the UI
looks fine, and players just see "it said it fixed it but nothing happened".
See [section 1](BisectHosting接入指南.md) for why.

**Step 5 · Click "Test panel API"**

If you see `power` in the capability list, **automatic restart works**.

**Step 6 · Consider also configuring RCON**

So that a panel-API outage still leaves you a fallback. See
[PANEL-SERVERS.en.md](PANEL-SERVERS.en.md), option B.

---

## Providers we have not checked yet

Ordered by how often Minecraft server owners actually use them. Checking each one only requires
answering four questions:

1. What panel is it? (Pterodactyl-based, or in-house?)
2. Is there an API-key page (`/account/api` or similar)?
3. Can RCON be enabled, and where in the panel?
4. Is SSH offered?

| Provider | Panel | Works with MCFix out of the box? | Status |
|---|---|---|---|
| **GGServers** | ✅ **Pterodactyl** (their own knowledge base says "your Pterodactyl server") | ✅ Pick `翼龙 / Pterodactyl（含 Pelican）` | 📄 Confirmed by vendor docs |
| **Sparked Host** | ✅ **Pterodactyl** (help centre publishes "How to use the Pterodactyl API") | ✅ Same as above | 📄 Confirmed by vendor docs |
| **Apex Hosting** | **Multicraft** | ✅ **Now supported** (pick the `Multicraft` type) | 📄 Confirmed by vendor docs |
| **Nitrado** | ⚠️ **In-house panel + its own API** | ❌ No adapter; would need a custom interface | 📄 Third-party libraries exist that call its API |
| **Nodecraft** | ⚠️ Most likely **in-house** ("the core behind Nodecraft") | ❓ TBC | ❓ |
| **Shockbyte** | ❓ Has migrated panels (control-panel migration announcement) | ❓ TBC | ❓ |
| **GPORTAL** | ❓ | ❓ | ❓ |

### ✅ A Multicraft adapter now exists (1.11.0)

**Multicraft — which is what Apex Hosting runs — can now be connected directly**, no fallback to RCON needed.

**What to fill in:**

| Field | Value |
|---|---|
| Type | `Multicraft（Apex Hosting 等，也见于部分 BisectHosting）` |
| Panel URL | `https://<panel-domain>/api.php` — **the `/api.php` part is required**; the bare domain returns HTML, not JSON |
| Username | Your Multicraft panel login name (**Multicraft is the only panel needing both a username and a key**, because the signature uses it) |
| API key | The key generated in the panel |
| Server ID | Multicraft's **numeric** server ID (not a UUID) |
| Server directory | Ignored — see the capability limits below |

**Capability limits (stated honestly):**

| Capability | Supported |
|---|---|
| Console commands (whitelist / unban / kick / save / reload) | ✅ |
| Power actions, **including automatic restart** | ✅ |
| Reading the server log | ✅ |
| CPU / memory / player count | ✅ |
| **Listing the mods directory / reading files** | ❌ **Multicraft's API has no file endpoints** |
| **Pulling a mod for a player to download** | ❌ Same reason |

So Multicraft users get the **complete repair loop** (everything that fixes things works), but not the
mod-related features. The adapter does not pretend otherwise — "Test panel API" spells out the limits.

> **Note for BisectHosting**: it runs *both* Multicraft and Pterodactyl.
> Open `https://<panel-domain>/api.php` — an API response means Multicraft, a 404 means Pterodactyl.
> Both are now supported.

### ⚠️ A protocol trap worth recording (for future maintainers)

Multicraft does not send the key as-is; it computes a signature:

```
message = concatenate "key + value" for every parameter, in order (keys included, no separator)
signature = hash_hmac('sha256', message, api_key)
```

**A widely-circulated 2013 single-file client uses `md5(key + method + user + values-only)` — that is wrong
or outdated** (it omits the key names and uses a different digest). Writing to that spec fails authentication
forever while the panel only returns a vague error.

The working implementation is in `src/Panel/MulticraftPanel.php`, with tests pinning the spec.

### Other leads

> **Where the Sparked Host / GGServers conclusions come from**: both are **the hosts' own docs**,
> not third-party integration guides, so they carry more weight. Sparked teaches customers to call
> the Pterodactyl API directly; GGServers' KB article is literally titled "Deploy to Your
> Pterodactyl Server".
>
> **Nitrado**: it has an API of its own (the community npm package `dayz_nitrado_api` calls it),
> so it is not Pterodactyl-based, but it **is programmatically reachable** — in principle usable
> through MCFix's "custom HTTP interface".
>
> **Shockbyte**: they have published a control-panel migration announcement, so older tutorials
> about it may be stale. Trust what you see when you log in today.

> **If you use one of these**, the fastest path is to walk through the checklist below in your own
> panel and tell me what you see (or open an issue). I'll fill in that row from your actual panel —
> far more accurate than me guessing through a layer of documentation.

---

## How to figure it out yourself (three minutes)

Don't wait for a guide. Open your panel and check these in order:

### Step 1 — Look at the address bar

```
https://<panel-domain>/server/3f2a1b9c
                            ^^^^^^^^
```

**If you see `/server/<short-id>` → almost certainly Pterodactyl-based.**
In MCFix pick `翼龙 / Pterodactyl（含 Pelican）`, and that short ID is the "server short ID".

### Step 2 — Try `/account/api`

Open `https://<panel-domain>/account/api` in your browser.

- **It loads and shows "Create API Key"** → use the panel-API route; it is the most capable
  (it includes automatic restart)
- **404, or no such page** → the provider disabled it. **Use the RCON route**
  ([PANEL-SERVERS.en.md](PANEL-SERVERS.en.md), option B)

### Step 3 — Find RCON

In the panel, find the editor for `server.properties` (usually under "Configuration Files" or
"Startup Parameters") and check whether you can change:

```properties
enable-rcon=true
rcon.port=25575
rcon.password=generate-your-own-random-string
```

**If you can change them → RCON is available** (restart the server afterwards).
**If the panel won't let you touch those lines → you only have the panel-API route.**

### Step 4 — Check for SSH

Look for "SSH" or "Console/Terminal" in the panel. **SFTP without SSH is the norm** —
which means the **Agent route is unavailable**, and the executor must be set to `none`.

---

## The four routes and how they fare at commercial hosts

Whatever the provider, MCFix has exactly these four routes. See which ones you have:

| Route | Requires | At commercial hosts, typically |
|---|---|---|
| **Panel API** | Provider exposes an API-key page | Hit or miss. Pterodactyl-based ones often do |
| **RCON** | You can edit `server.properties` | Usually yes |
| **Agent** | A long-running process on the MC machine | ❌ **Basically impossible** — no SSH, no way to keep it running |
| **SSH** | Provider grants SSH | ❌ Almost never |

**Bottom line: at a commercial host you get "panel API and/or RCON".**
Configuring both is best: RCON has echo-back and is fastest, while the panel API covers what RCON
cannot — **especially restarting a server that has gone down**.

---

## Related documents

| Document | Contents |
|---|---|
| [PANEL-SERVERS.en.md](PANEL-SERVERS.en.md) | General mechanics: four panel types field by field, three tiers of setup, capability matrix, troubleshooting, panel-specific pitfalls |
| [BisectHosting guide](BisectHosting接入指南.md) | One specific provider; works as a template for Pterodactyl-based hosts (Chinese) |
| [BT-PANEL-DEPLOYMENT.en.md](BT-PANEL-DEPLOYMENT.en.md) | Deploying the MCFix site itself onto BT Panel |
| [EMAIL-NOTIFICATIONS.en.md](EMAIL-NOTIFICATIONS.en.md) | Getting repair results delivered to a human |

---

## How this document will change

The plan is to fill in the "Unverified" rows one by one, **each with a cited source** — no guessing.

If you use one of these providers, send a screenshot or the page text and I'll write it from what
you actually see. That beats any third-party document.
