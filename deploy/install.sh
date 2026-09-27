#!/bin/bash
# ============================================================================
# MCFix 一键初始化脚本（在宝塔终端里以 root 运行）
#
# 作用：创建运行时目录、修正权限、做一次环境自检。
# 用法：
#   cd /www/wwwroot/mcfix
#   bash deploy/install.sh
#
# 可选参数：把网站用户传进去（默认 www）
#   bash deploy/install.sh www
# ============================================================================

set -e

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB_USER="${1:-www}"

echo "== MCFix 初始化 =="
echo "项目目录：$APP_DIR"
echo "网站用户：$WEB_USER"
echo

# ---------------------------------------------------------------- 1. 建目录
echo "[1/4] 创建运行时目录"
mkdir -p "$APP_DIR/storage"/{data,logs,cache,locks,ratelimit,keys,mods}
mkdir -p "$APP_DIR/config"
for d in data logs cache locks ratelimit keys mods; do
    touch "$APP_DIR/storage/$d/index.html"
done
echo "      storage/{data,logs,cache,locks,ratelimit,keys,mods} 就绪"

# ---------------------------------------------------------------- 2. 权限
echo "[2/4] 设置权限"
if id "$WEB_USER" >/dev/null 2>&1; then
    chown -R "$WEB_USER:$WEB_USER" "$APP_DIR/storage" "$APP_DIR/config"
else
    echo "      警告：用户 $WEB_USER 不存在，跳过 chown（Windows/其它环境请手工设置）"
fi
chmod -R 755 "$APP_DIR"
chmod -R 775 "$APP_DIR/storage"
chmod 775 "$APP_DIR/config"
[ -f "$APP_DIR/config/config.php" ] && chmod 640 "$APP_DIR/config/config.php"
echo "      storage 775，config 775，config.php 640"

# ---------------------------------------------------------------- 3. 找 PHP
echo "[3/4] 查找 PHP"
PHP_BIN=""
for candidate in \
    /www/server/php/*/bin/php \
    /usr/bin/php \
    /usr/local/bin/php \
    /opt/remi/php*/root/usr/bin/php
do
    if [ -x "$candidate" ]; then
        PHP_BIN="$candidate"
    fi
done

if [ -z "$PHP_BIN" ]; then
    echo "      未找到 php 可执行文件，请手工指定路径后运行："
    echo "      /path/to/php bin/mcfix.php doctor"
else
    echo "      使用 $PHP_BIN（$($PHP_BIN -r 'echo PHP_VERSION;')）"

    echo
    echo "[4/4] 环境自检"
    "$PHP_BIN" "$APP_DIR/bin/mcfix.php" doctor || true

    echo
    echo "== 计划任务命令（宝塔 → 计划任务 → Shell 脚本，每分钟）=="
    echo "cd $APP_DIR && $PHP_BIN bin/mcfix.php cron"
fi

echo
echo "== 下一步 =="
echo "1. 宝塔【网站 → 站点设置 → 网站目录 → 运行目录】选 /public"
echo "2. 浏览器打开 https://你的域名/?r=install 完成安装向导"
echo "3. 按安装向导第 3 步的提示，把 Agent 部署到 MC 机器上"
echo
