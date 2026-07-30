#!/bin/bash
# Деплой изменений на seo.magnit365.ru
SERVER="root@109.172.30.103"
REMOTE="/var/www/seo.magnit365.ru"
LOCAL="D:/seo_project"

echo "=== Деплой SEO-аудитора ==="

FILES=(
  "src/Checks/AiReadinessCheck.php"
  "src/Checks/CommercialFactorsCheck.php"
  "src/Checks/LinksCheck.php"
  "src/Checks/ResourceCheck.php"
  "src/Checks/FZ152Check.php"
  "src/Checks/SpeedCheck.php"
  "src/Report/Priority.php"
  "src/Report/ReportBuilder.php"
  "src/Audit/AuditManager.php"
  "templates/report.php"
  "templates/report_pdf.php"
  "api/report.php"
)

for f in "${FILES[@]}"; do
  scp "$LOCAL/$f" "$SERVER:$REMOTE/$f" && echo "OK: $f"
done

# Новые классы требуют пересборки оптимизированного автолоадера
ssh "$SERVER" "cd $REMOTE && composer dump-autoload -o && chown -R nginx:nginx src templates public/api"

echo "=== Готово ==="
