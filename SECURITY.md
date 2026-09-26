# 安全策略

## 报告漏洞

**请不要在公开 Issue 里披露安全问题。**

优先用 GitHub 的[私密漏洞报告](https://docs.github.com/zh/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability)功能
（仓库页面 → Security → Report a vulnerability）。如果不可用，请开一个只说「有安全问题，
请提供联系方式」的 Issue，我会私信你。

请尽量包含：

- 受影响的版本（`src/bootstrap.php` 里的 `MCFIX_VERSION`，或后台「系统设置 → 运行信息」里显示的版本号）
- 复现步骤或 PoC
- 你判断的影响范围
- 你的部署形态（有无独立后台域名、有无 IP 白名单、执行器类型）

我会在确认后 7 天内回复，并在修复发布后于 CHANGELOG 中致谢（除非你希望匿名）。

## 支持范围

只维护最新发布版本。安全修复会以补丁版本发布，不回移植到旧版本。

## 设计上的安全边界

理解这些边界有助于判断一个发现是不是真正的漏洞。

### 1. 面板是唯一信任根

面板（跑 PHP 的那台机器）一旦被完全控制，攻击者可以改配置、读数据库里的全部工单与
客户端日志、拿到 Agent 令牌。**这是设计上的边界，不是缺陷。** 请把它当普通 Web 应用加固：
HTTPS、强口令、及时更新 PHP、限制后台来源 IP。

### 2. 面板攻破 ≠ Minecraft 机器攻破

这是本项目的核心不变量。Agent（`agent/mcfix-agent.php`）只执行固定白名单里的配方：

```
whitelist_add  unban_player  unmute_player  kick_player  clear_self_items
save_world     reload_plugins  restart_server  backup_world  monitor_server  pull_mod
```

面板下发的 `recipe` 字段若不是其中之一，Agent 直接拒绝。**Agent 内没有任何
「执行面板传来的任意命令」的代码路径。** 因此即使面板被攻破，攻击者能做的上限是：
让服务端重启几次（受每小时配额限制）、读取服务端 `mods/` 目录下的 `.jar`
（只读 `mods/`，不含 `plugins/` —— 插件是纯服务端组件，客户端永远不需要，
列出来等于告诉玩家这台服装了什么）。

如果你能构造出一个让 Agent 执行任意命令的输入，那是 CRITICAL，请立刻报告。

### 3. 玩家侧没有身份，因此没有 CSRF 问题

玩家不需要账号密码。反馈链接是 HMAC-SHA256 签名的自包含令牌，绑定
`feedback_id` + `server_id` + 随机 nonce + 到期时间。令牌无法跨工单或跨服务器使用，
工单链接可由管理员重置以作废。

玩家能做的事仅限：提交反馈、上传崩溃报告、为自己的工单触发一次验证、查看自己工单的进度、
下载「分析结果里认定他缺的」MOD 文件。

浏览器里唯一存的东西是"这台机器提交过哪些工单"，不承载任何身份，也不授予任何权限。
后台是另一套独立会话（`mcfix_console`）+ 独立 CSRF 令牌，两边不共用。

### 4. 已知的、不打算修的东西

| 事项 | 说明 |
|---|---|
| 后台地址可被暴力扫描 | 靠随机路径 + IP 白名单 + 失败锁定缓解，不追求「绝对隐藏」。**建议务必配置 IP 白名单。** |
| 诊断准确率非 100% | 特征匹配有上限。系统遇到不认识的问题会明说「需要人工」，不会硬编一个结论。 |
| 无内置 2FA | 单管理员场景，未实现。若你需要，欢迎提交 PR。 |
| 面板出站请求无 SSRF 防护 | 面板会连接管理员配置的 MC 地址 / RCON 地址 / 面板 API。这些地址**只能由管理员填写**，不来自玩家输入。若后台账号泄露，攻击者可借此探测内网 —— 所以后台口令强度比什么都重要。 |
| SQLite 并发上限 | 单文件数据库，适合个人 / 小服。高并发场景请改用 MySQL（`db.driver` 已预留，但未充分测试）。 |
| Agent 令牌不可单独吊销 | 令牌是自包含的 HMAC，签发后无法逐个作废。泄露了就重新生成（后台「换成新令牌」填 `__new__`），并建议同时配 `agent_ip_allow`。 |
| 面板被攻破后 Agent 仍可被驱动 | 面板持有 Agent 令牌，因此面板失守 = 攻击者能反复触发白名单内的配方（重启受配额限制、能读 `mods/` 下的 jar）。这是"面板是信任根"的直接推论，隔离的是**任意命令**而不是"什么都不做"。 |

## 部署安全清单

上线前请逐条确认：

- [ ] 网站运行目录指向 `public/`，浏览器访问不到 `config/`、`storage/`
      （Nginx **不读** `.htaccess`，别指望仓库里那几个文件）
- [ ] 已开启 HTTPS（反馈链接带签名令牌，明文 HTTP 会泄露）
- [ ] `config/config.php` 权限 640，属主为 Web 用户
- [ ] 后台口令 ≥ 16 位随机字符且未在任何聊天/工单里出现过
- [ ] **后台配置了 IP 白名单**
- [ ] 若用私有路径模式，后台路径 ≥ 16 位随机字符
- [ ] `trusted_proxies` 与实际 Nginx 行为匹配（不确定就留空）
- [ ] RCON 端口只对面板 IP 开放，密码是随机长串
- [ ] 面板 API 用 HTTPS；只有自签证书时才勾"跳过证书校验"
- [ ] MC 侧 Agent 用最小权限用户运行，`guard` 配置里没有不必要的自定义命令
- [ ] 已配置计划任务（`bin/mcfix.php cron`，每分钟；**必须用 `bin/php` 而不是 `php-fpm`**）
- [ ] 已配置定期备份，且用的是 `sqlite3 ... ".backup ..."` 而不是 `cp`
- [ ] 定期检查【操作日志】里的登录失败记录
