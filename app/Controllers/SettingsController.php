<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Audit;
use App\Services\ImageService;
use App\Services\SettingsService;

final class SettingsController
{
    /** GET /api/settings/public - branding for the login page (no login required). */
    public function public(Request $request): Response
    {
        return Response::ok(SettingsService::branding());
    }

    /** GET /api/settings/logo */
    public function logo(Request $request): Response
    {
        $path = SettingsService::get('logo_path');
        if (!$path || !is_file(ImageService::absolute($path))) {
            throw HttpException::notFound('Logo não configurado.');
        }
        return Response::file(ImageService::absolute($path), 'image/png', 86400);
    }

    /** GET /api/settings - editable settings (admin). */
    public function index(Request $request): Response
    {
        $all = SettingsService::all();
        return Response::ok([
            'company_name' => $all['company_name'],
            'primary_color' => $all['primary_color'],
            'logo_url' => SettingsService::branding()['logo_url'],
            'stalled_days' => (int) $all['stalled_days'],
            'event_email_mode' => $all['event_email_mode'],
            'alert_emails' => $all['alert_emails'] === '' ? [] : explode(',', $all['alert_emails']),
            'mail_driver' => \App\Services\Mailer::driver(),
            'mail_smtp' => \App\Services\Mailer::isSmtp(),
        ]);
    }

    /** PUT /api/settings  (any subset of the editable keys) */
    public function update(Request $request): Response
    {
        $input = $request->all();
        if (isset($input['alert_emails']) && is_array($input['alert_emails'])) {
            $input['alert_emails'] = implode(',', $input['alert_emails']);
        }
        $data = Validator::validate($input, SettingsService::EDITABLE);

        if (array_key_exists('alert_emails', $data)) {
            $emails = array_values(array_filter(array_map(
                fn ($e) => mb_strtolower(trim($e)),
                preg_split('/[,;\s]+/', (string) $data['alert_emails']) ?: []
            )));
            foreach ($emails as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw HttpException::validation(['alert_emails' => "E-mail inválido: {$email}"]);
                }
            }
            $data['alert_emails'] = implode(',', array_unique($emails));
        }
        if (isset($data['primary_color'])) {
            $data['primary_color'] = strtoupper($data['primary_color']);
        }
        SettingsService::set($data);
        return $this->index($request);
    }

    /** POST multipart/form-data with field "logo" (PNG/JPG/WEBP). */
    public function uploadLogo(Request $request): Response
    {
        $file = $request->file('logo');
        if ($file === null) {
            throw HttpException::validation(['logo' => 'Selecione uma imagem.']);
        }
        $old = SettingsService::get('logo_path');
        $path = ImageService::storeLogo($file);
        SettingsService::set(['logo_path' => $path]);
        ImageService::delete($old);
        return $this->index($request);
    }

    public function deleteLogo(Request $request): Response
    {
        $old = SettingsService::get('logo_path');
        if ($old) {
            SettingsService::set(['logo_path' => '']);
            ImageService::delete($old);
        }
        return $this->index($request);
    }

    public function testEmail(Request $request): Response
    {
        $user = \App\Core\Auth::user();
        \App\Services\Mailer::send(
            (string) $user['email'],
            'Teste de e-mail — Controle de Brindes',
            \App\Services\Mailer::layout('Teste de e-mail', '<p>Se você recebeu esta mensagem, o envio de e-mail está configurado corretamente.</p>')
        );
        $smtp = \App\Services\Mailer::isSmtp();
        $msg = $smtp
            ? 'E-mail de teste enviado para ' . $user['email'] . '.'
            : 'SMTP ainda não está configurado neste ambiente. O teste foi registrado internamente; no servidor de vocês, com host/usuário/senha SMTP, o comprovante sai de verdade para ' . $user['email'] . '.';
        return Response::ok(['message' => $msg]);
    }

    public function backup(Request $request): Response
    {
        $path = \App\Services\BackupService::create();
        return Response::file($path, 'application/sql', 0, basename($path));
    }
}
