#!/bin/bash
# Деплой на seo.magnit365.ru
#
# Синхронизирует код проекта с сервером. НЕ трогает: .env, vendor/, reports/, logs/ —
# на сервере они свои. После новых классов пересобирает автолоадер Composer.
#
# Запуск:  ./deploy.sh          — только изменённые в git файлы (по сравнению с origin/main)
#          ./deploy.sh --all    — весь код целиком

set -u

SERVER="${DEPLOY_SERVER:-root@109.172.30.103}"
REMOTE="${DEPLOY_REMOTE:-/var/www/seo.magnit365.ru}"
LOCAL="$(cd "$(dirname "$0")" && pwd)"

echo "=== Деплой SEO-Аудитора → $SERVER ==="

# api/ на сервере лежит в веб-руте public/api/, локально — в корне проекта
remote_path() {
  case "$1" in
    api/*) echo "$REMOTE/public/${1}" ;;
    *)     echo "$REMOTE/$1" ;;
  esac
}

# Список файлов к отправке
if [ "${1:-}" = "--all" ]; then
  FILES=$(git -C "$LOCAL" ls-files -- 'src/**' 'api/**' 'templates/**' 'public/**' 'jobs/**' 'config/**' 'bin/**' 'sql/**' composer.json composer.lock)
else
  BASE=$(git -C "$LOCAL" rev-parse --verify --quiet origin/main || echo HEAD)
  FILES=$(git -C "$LOCAL" diff --name-only "$BASE" -- 'src/**' 'api/**' 'templates/**' 'public/**' 'jobs/**' 'config/**' 'bin/**' 'sql/**' composer.json composer.lock
          git -C "$LOCAL" diff --name-only --cached -- 'src/**' 'api/**' 'templates/**' 'public/**' 'jobs/**' 'config/**' 'bin/**' 'sql/**')
  FILES=$(echo "$FILES" | sort -u | grep -v '^$' || true)
fi

if [ -z "$FILES" ]; then
  echo "Нечего деплоить — изменений нет. Полная выгрузка: ./deploy.sh --all"
  exit 0
fi

NEW_CLASSES=0
COUNT=0
while IFS= read -r f; do
  [ -z "$f" ] && continue
  [ -f "$LOCAL/$f" ] || { echo "SKIP (нет файла): $f"; continue; }
  DEST=$(remote_path "$f")
  ssh "$SERVER" "mkdir -p '$(dirname "$DEST")'"
  if scp -q "$LOCAL/$f" "$SERVER:$DEST"; then
    echo "OK: $f"
    COUNT=$((COUNT + 1))
    case "$f" in src/*) NEW_CLASSES=1 ;; esac
  else
    echo "ОШИБКА: $f"
  fi
done <<< "$FILES"

echo "--- Отправлено файлов: $COUNT ---"

# Автолоадер оптимизирован (-o): без пересборки новые классы не подхватятся
if [ "$NEW_CLASSES" = "1" ]; then
  echo "Пересборка автолоадера Composer..."
  ssh "$SERVER" "cd '$REMOTE' && composer dump-autoload -o -q && echo 'автолоадер обновлён'"
fi

ssh "$SERVER" "cd '$REMOTE' && chown -R nginx:nginx src templates config bin public jobs 2>/dev/null; php bin/check_env.php | tail -4"

echo "=== Готово ==="
