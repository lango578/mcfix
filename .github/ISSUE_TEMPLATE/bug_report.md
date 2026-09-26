---
name: Bug 报告
about: 部署或使用中遇到的问题（安全漏洞请走 SECURITY.md 里的私密渠道）
title: '[Bug] '
labels: ['bug']
assignees: []
---

<!--
请先花一分钟读 README 的「常见问题」与「现状与已知限制」两节。
有些现象是已知行为（比如 Agent 只支持 Linux），不是 bug。
-->

## 现象

<!-- 你期望发生什么，实际发生了什么 -->

## 复现步骤

1.
2.
3.

## 环境

| 项目 | 你的情况 |
|---|---|
| 版本（后台「系统设置 → 运行信息」，或 `src/bootstrap.php` 里的 `MCFIX_VERSION`） | |
| PHP 版本 | |
| 部署形态 | 宝塔 / 裸机 Nginx / 其它 |
| 执行器 | agent / ssh / local / none（可多选） |
| 是否配了 RCON | 是 / 否 |
| 是否配了面板 API | 否 / MCSManager / 翼龙 / 宝塔 / 自定义 |
| 后台是独立域名还是私有路径 | |

## 自检输出

```
# php bin/mcfix.php doctor
# php bin/mcfix.php servers
# php /opt/mcfix/mcfix-agent.php --check      ← 涉及 Agent 时请附上
```

## 相关日志

<!--【操作日志】里的记录、storage/logs 下的片段，或 Agent 的 --once --verbose 输出 -->

## 我排查过的

<!-- 可选：你已经试过什么、排除了什么，能省很多来回 -->
