<?php
/**
 * УСТАРЕЛО. Используйте bin/regen_pdf.php — он принимает UUID аудита,
 * обновляет путь в базе и не требует правки путей под конкретный сервер.
 *
 * Файл оставлен для совместимости со старыми инструкциями.
 */
$replacement = __DIR__ . '/bin/regen_pdf.php';

fwrite(STDERR, "Скрипт устарел. Запустите вместо него:\n");
fwrite(STDERR, "  php bin/regen_pdf.php last        — последний завершённый аудит\n");
fwrite(STDERR, "  php bin/regen_pdf.php <uuid>      — конкретный аудит\n\n");

if (is_file($replacement)) {
    fwrite(STDERR, "Перенаправляю на bin/regen_pdf.php last...\n\n");
    $argv[1] = $argv[1] ?? 'last';
    require $replacement;
    exit;
}

exit(1);
