<?php
namespace SeoAuditor\Email;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use SeoAuditor\Core\Config;

class Mailer
{
    public function sendReport(string $to, string $uuid, string $siteUrl, string $pdfPath): void
    {
        $mail = new PHPMailer(true);
        $cfg  = Config::get('mail');
        $appUrl = Config::get('app.url');

        if (!empty($cfg['host']) && $cfg['host'] !== 'localhost') {
            $mail->isSMTP();
            $mail->Host       = $cfg['host'];
            $mail->Port       = $cfg['port'];
            $mail->SMTPAuth   = !empty($cfg['username']);
            $mail->Username   = $cfg['username'];
            $mail->Password   = $cfg['password'];
            $mail->SMTPSecure = $cfg['port'] == 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->isMail();
        }

        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->setFrom($cfg['from'], $cfg['from_name']);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = "SEO Аудит: $siteUrl";

        $reportUrl = "$appUrl/audit.php?id=$uuid";
        $mail->Body = $this->buildEmailHtml($siteUrl, $reportUrl);
        $mail->AltBody = "Аудит сайта $siteUrl завершён. Отчёт: $reportUrl";

        if ($pdfPath && file_exists($pdfPath)) {
            $mail->addAttachment($pdfPath, 'seo-audit-' . parse_url($siteUrl, PHP_URL_HOST) . '.pdf');
        }

        $mail->send();
    }

    private function buildEmailHtml(string $siteUrl, string $reportUrl): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><style>
body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
.wrap { max-width: 600px; margin: 30px auto; background: #fff; border-radius: 8px; overflow: hidden; }
.header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; color: #fff; text-align: center; }
.header h1 { margin: 0; font-size: 24px; }
.body { padding: 30px; }
.btn { display: inline-block; background: #667eea; color: #fff; text-decoration: none; padding: 14px 30px; border-radius: 6px; font-size: 16px; font-weight: bold; margin: 20px 0; }
.footer { background: #f9f9f9; padding: 20px; text-align: center; color: #999; font-size: 13px; }
</style></head>
<body>
<div class="wrap">
  <div class="header"><h1>SEO Аудитор</h1><p>Аудит завершён</p></div>
  <div class="body">
    <p>Аудит сайта <strong>{$siteUrl}</strong> успешно завершён.</p>
    <p>Полный отчёт с рекомендациями прикреплён к письму в виде PDF-файла.</p>
    <p>Также вы можете просмотреть отчёт онлайн:</p>
    <a href="{$reportUrl}" class="btn">Открыть отчёт онлайн</a>
    <p style="color:#999;font-size:13px;">Если кнопка не работает, перейдите по ссылке:<br><a href="{$reportUrl}">{$reportUrl}</a></p>
  </div>
  <div class="footer">SEO Аудитор © 2025 | <a href="https://seo.magnit365.ru">seo.magnit365.ru</a></div>
</div>
</body>
</html>
HTML;
    }
}
