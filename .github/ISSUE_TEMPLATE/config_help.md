---
name: 配置问题
about: 装不上 / 连不通 / 后台进不去（大多数情况用这个模板最快）
title: '[配置] '
labels: ['question']
assignees: []
---

## 卡在哪一步

- [ ] 上传解压 / 运行目录没指到 `public/`
- [ ] Nginx 或 PHP-FPM 配置（502、404、403）
- [ ] 安装向导（`?r=install`）
- [ ] 后台登录
- [ ] 添加服务器 / 部署 Agent
- [ ] 计划任务
- [ ] 通知渠道
- [ ] 其它

## 具体现象

<!-- 页面显示什么？命令行输出什么？ -->

## `doctor` 输出

```
# php bin/mcfix.php doctor
```

## Agent 自检输出（涉及 Agent 时）

```
# php /opt/mcfix/mcfix-agent.php --check
```

## 环境

| 项目 | 你的情况 |
|---|---|
| 面板 | 宝塔版本号 |
| 系统 | Ubuntu / CentOS / Debian / 其它 |
| PHP | 版本 + SAPI（FPM / Apache / CLI） |
| Nginx 运行用户 | `ps aux \| grep nginx` |
| FPM 池的 user/group | |
| 网站运行目录 | |

<!-- 请抹掉域名、IP、令牌、口令。贴配置时尤其注意 config/config.php 里的 hmac_secret 与 admin_password -->
