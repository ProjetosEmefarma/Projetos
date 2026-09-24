<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends e-mail through SMTP (PHPMailer) or writes it to storage/logs/mail.log
 * when MAIL_DRIVER=log (development). Step 2 puts a queue in front of this.
 */
final class Mailer
{
    public static function driver(): string
    {
        return (string) (Config::get('mail.driver') ?? 'log');
    }

    public static function isSmtp(): bool
    {
        return self::driver() === 'smtp';
    }

    /** @param array<string> $attachments absolute file paths */
    public static function send(string $to, string $subject, string $html, array $attachments = []): void
    {
        $cfg = Config::get('mail');
        if (self::driver() !== 'smtp') {
            $line = sprintf(
                "==== %s | to: %s | subject: %s ====\n%s\n%s\n\n",
                date('Y-m-d H:i:s'),
                $to,
                $subject,
                self::toText($html),
                $attachments ? 'attachments: ' . implode(', ', array_map('basename', $attachments)) : ''
            );
            file_put_contents(Config::get('paths.storage') . '/logs/mail.log', $line, FILE_APPEND | LOCK_EX);
            return;
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $cfg['host'];
        $mail->Port = (int) $cfg['port'];
        $mail->SMTPAuth = $cfg['username'] !== '';
        $mail->Username = $cfg['username'];
        $mail->Password = $cfg['password'];
        if ($cfg['encryption'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($cfg['encryption'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Timeout = 20;
        $mail->setFrom($cfg['from_address'], SettingsService::get('company_name') ?: $cfg['from_name']);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = self::toText($html);
        foreach ($attachments as $path) {
            $mail->addAttachment($path);
        }
        $mail->send();
    }

    /** Plain-text version of an HTML e-mail; links become "text (URL)" so they are not lost. */
    public static function toText(string $html): string
    {
        $html = preg_replace('/<a\s[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $html) ?? $html;
        $text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</tr>', '</h2>'], "\n", $html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text) ?? $text) ?? $text);
    }

    /** Simple branded HTML wrapper for transactional e-mails. */
    public static function layout(string $title, string $bodyHtml): string
    {
        $company = e(SettingsService::get('company_name') ?? 'Controle de Brindes');
        $color = e(SettingsService::get('primary_color') ?? '#2563EB');
        return <<<HTML
<!doctype html><html lang="pt-BR"><body style="margin:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px">
<table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden">
<tr><td style="background:{$color};color:#ffffff;padding:16px 24px;font-size:18px;font-weight:bold">{$company}</td></tr>
<tr><td style="padding:24px"><h2 style="margin:0 0 16px;font-size:18px">{$title}</h2>{$bodyHtml}</td></tr>
<tr><td style="padding:12px 24px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb">Mensagem automática. Não responda este e-mail.</td></tr>
</table></td></tr></table></body></html>
HTML;
    }
}
