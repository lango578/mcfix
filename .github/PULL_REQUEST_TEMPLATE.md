## 这个 PR 做了什么

<!-- 一句话说清楚。如果是修 bug，请附上 Issue 号：Fixes #123 -->

## 类型

- [ ] 修 bug
- [ ] 新功能 / 新适配器 / 新崩溃特征
- [ ] 文档
- [ ] 重构（不改变行为）

## 自测

- [ ] `find . -name '*.php' -not -path './storage/*' -exec php -l {} \;` 全部通过
- [ ] `php tests/run.php` 全部通过
- [ ] 在真实环境里跑过（请说明环境与验证方式）

## 安全边界检查

涉及 `src/Recipe.php`、`agent/mcfix-agent.php`、`src/ConsoleAuth.php`、`src/Token.php` 的改动请逐条回答：

- [ ] 我没有引入"执行任意命令"的代码路径
- [ ] 我没有放宽参数校验（或：放宽了，理由写在下面）
- [ ] 我没有把密钥、令牌、口令写进日志或错误信息
- [ ] 新增的出网请求保留了 TLS 证书校验

## 需要 reviewer 留意的改动

<!-- 可选：你觉得有风险、或者拿不准的改动 -->
