# 贡献指南

感谢愿意帮忙。这个项目是个人作品，代码风格比较统一，请尽量跟随现有写法。

## 许可：你提交的代码怎么授权

本项目以 [Apache License 2.0](LICENSE) 发布（版权与归属见 [NOTICE](NOTICE)）。

按该许可证 **§5**，除非你明确声明 otherwise，**你提交的 PR 默认就以同一许可证授权**
—— 不需要签 CLA，也不要求你把版权转让给谁。提交即表示你同意这一点，并且你确实有权这么做
（比如这段代码不是从某个不兼容的项目里抄来的）。

**如果你不同意**，请在 PR 里直接说，我们就不合这段代码 —— 这比事后扯皮好。
如果你引入的代码来自别处，**必须在 PR 描述里写明来源和它的许可证**。

> 一个实际后果：Apache 2.0 **与 GPLv2 不兼容**。所以**不要**从这里复制代码去合进
> GPLv2 项目，也不要把 GPLv2 的代码贴进来。（GPLv3 没这个问题。）

## 提交之前

1. 确认没有语法错误。CI 会在 PHP 7.4 与 8.3 上跑同一条命令，本地也请跑一遍：

   ```bash
   # 最准确的一道（推荐）
   find . -name '*.php' -not -path './storage/*' -exec php -l {} \;

   # 冒烟测试（纯 PHP 断言，不需要 PHPUnit）
   php tests/run.php

   # 没有 PHP 环境时的离线辅助检查，能力有限
   node deploy/check-alt-syntax.js     # 模板 if/endif 配对
   node deploy/check-close-tag.js      # 字符串内的 ?> （真实语法错误的常见来源）
   ```

   > 注意：`deploy/` 下的检查脚本是结构级检查，不能替代 `php -l`。
   > 作者在开发中曾因过度信任自制检查器而漏掉真实语法错误，请以真实 PHP 为准。

2. 不要提交任何环境相关内容：真实域名、IP、密钥、口令、服务器路径、
   部署脚本的一次性产物。`.gitignore` 已覆盖 `config/config.php` 与 `storage/`，
   仍请自查 `git diff --cached`。

3. 改动安全边界时请在 PR 描述里说明。以下文件属于安全核心，改动需要格外谨慎：

   - `src/Recipe.php`：修复配方白名单。任何放宽参数校验的改动都必须解释理由。
   - `agent/mcfix-agent.php`：MC 机器上执行的代码。新增配方时必须同时实现
     Agent 侧处理，且不得引入「执行任意命令」的路径。
   - `src/ConsoleAuth.php`：后台认证、会话、IP 白名单。
   - `src/Token.php` / `src/Share.php`：签名令牌。

## 代码风格

- PHP 7.4 兼容（不要用 `match`、命名参数、`?->`、构造器属性提升、`str_contains` 等 8.0+ 特性）
- 缩进 4 空格，`declare(strict_types=1)`，文件内 `namespace MCFix;`
- 类名 `StudlyCase`，方法 `camelCase`，常量 `UPPER_SNAKE`
- 所有面向玩家的输出必须过 `e()`（`htmlspecialchars`）
- 所有 SQL 必须用绑定参数，不拼接字符串
- 注释写「为什么」而不是「是什么」。中文注释是常态，英文也可以
- 面向玩家的文案用中文；面向开发者的注释中英不限

## 新增一个修复配方

配方是「能对 MC 服务器做的事」的白名单。新增时：

1. 在 `src/Recipe.php` 的 `all()` 里加一条，填 `exec`（`panel` / `agent`）、
   `params`（类型见 `validateParams`）、`risk`、`description`
2. 面板侧配方：实现 `Recipe::rconCommand()` 里的指令映射
3. Agent 侧配方：在 `agent/mcfix-agent.php` 的 `run_recipe()` 里加 `case`，
   只允许拼装固定的命令结构，所有外部输入过 `escapeshellarg()`
4. 如需在诊断里推荐它，更新 `src/Verdict.php` 的 `recipe_hints`
5. 更新 `README.md` 的功能表与 `deploy/安全说明.md` 的配方表

## 新增一个通知渠道

1. 在 `src/Channel/` 下新建类，继承 `ChannelAdapter`
2. 实现 `key()` / `label()` / `requiredFields()` / `send()`
3. 在 `src/Channel/ChannelRegistry.php` 的 `types()` 与 `fieldHints()` 里注册
4. 在 `README.md` 的渠道表里加一行

`ChannelAdapter` 已提供 `http()` / `json()` / `judgeJson()` / `clamp()` 等工具，
以及 `maskUrl()`（打日志时抹掉密钥）。

## 新增一个面板适配器

1. 在 `src/Panel/` 下新建类，继承 `PanelAdapter`
2. 在 `capabilities()` 里如实声明支持的能力，不要声明做不到的
3. 在 `src/PanelRegistry.php` 的 `types()` 里注册
4. 接口路径尽量走 `endpointNamed()`，让使用者在后台就能覆盖路径

> 面板 API 各版本路径常有差异。作者无法逐一验证所有面板版本，
> 因此适配器必须把失败原因说清楚（哪个接口、什么错误、怎么覆盖路径），
> 而不是静默失败。

## 报 Bug 时请附上

- 版本号、PHP 版本、部署形态（面板 / 裸机 / 容器）
- 执行器类型（agent / ssh / local / none）以及是否配了 RCON / 面板 API
- 相关工单号与【操作日志】里的记录
- 若涉及 Agent，附 `php mcfix-agent.php --check` 的输出

请抹掉：域名、IP、令牌、口令、真实玩家 ID。

## 关于测试

项目的测试覆盖很薄，这是目前最大的技术债。`tests/run.php` 是一组纯 PHP 断言
（不引入 PHPUnit，保持零依赖），跑 `php tests/run.php` 即可。

想补的话，按价值排序：

- `Recipe::validateParams()`（参数注入的第一道闸）
- `Token` / `Share` 的签名、过期与"一个工单的令牌读不到另一个工单"
- `ClientLog` 的解析（用脱敏的真实崩溃报告做样本）
- `Workflow` 的状态机流转

新增用例直接加进 `tests/run.php`，或另建 `tests/*.php` 后在 `run.php` 里 `require`。
