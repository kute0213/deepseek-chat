#!/usr/bin/env sh
# 清理旧版本遗留在站点根目录的目录与文件。
# 最新版本的入口、后台、安装与建表脚本都已在 app/ 内，根目录不应再有这些目录。
# 用法：在项目根目录执行 sh scripts/cleanup-legacy.sh
set -eu
root=$(cd "$(dirname "$0")/.." && pwd)

for name in database admin install config; do
  if [ -e "$root/$name" ]; then
    echo "remove  $name/"
    rm -rf "$root/$name"
  fi
done

if [ -f "$root/.user.ini" ]; then
  echo "remove  .user.ini（示例已移动到 scripts/user.ini）"
  rm -f "$root/.user.ini"
fi

echo "done: 根目录已清理，app / static / uploads / scripts 未改动"
