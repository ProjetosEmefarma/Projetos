<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Paginator;
use App\Core\Request;
use Throwable;

final class NotificationService
{
    public static function queueInApp(?int $userId, string $subject, string $body, ?string $link = null, ?string $dedupe = null, ?string $type = null, ?int $relatedId = null): void
    {
        self::insert([
            'user_id' => $userId,
            'channel' => 'in_app',
            'to_email' => null,
            'subject' => $subject,
            'body' => $body,
            'link_url' => $link,
            'related_type' => $type,
            'related_id' => $relatedId,
            'status' => 'enviado',
            'sent_at' => now(),
            'dedupe_key' => $dedupe,
        ]);
    }

    public static function queueEmail(?int $userId, string $to, string $subject, string $html, ?string $attachment = null, ?string $dedupe = null, ?string $type = null, ?int $relatedId = null): void
    {
        self::insert([
            'user_id' => $userId,
            'channel' => 'email',
            'to_email' => $to,
            'subject' => $subject,
            'body' => $html,
            'attachment_path' => $attachment,
            'related_type' => $type,
            'related_id' => $relatedId,
            'status' => 'fila',
            'dedupe_key' => $dedupe,
        ]);
    }

    public static function notifyUsersByPermission(string $permission, string $subject, string $body, ?string $link = null, ?string $type = null, ?int $relatedId = null): void
    {
        $users = Db::fetchAll(
            "SELECT DISTINCT u.id FROM users u
               JOIN role_permissions rp ON rp.role_id = u.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE u.deleted_at IS NULL AND u.active = 1 AND (p.slug = ? OR u.role_id = 1)",
            [$permission]
        );
        foreach ($users as $u) {
            self::queueInApp((int) $u['id'], $subject, $body, $link, null, $type, $relatedId);
        }
    }

    public static function requestSubmitted(array $request): void
    {
        $code = $request['code'];
        $link = '/solicitacoes/' . $request['id'];
        if (($request['status'] ?? '') === 'aguardando_aprovacao') {
            self::notifyUsersByPermission('requests.approve', 'Nova solicitação aguardando aprovação', "A solicitação {$code} precisa da sua aprovação.", $link, 'request', (int) $request['id']);
        }
        self::notifyUsersByPermission('requests.process', 'Nova solicitação', "A solicitação {$code} foi enviada.", $link, 'request', (int) $request['id']);
    }

    public static function requestDecided(array $request, string $decision, ?string $justification): void
    {
        $code = $request['code'];
        $ok = $decision === 'aprovado';
        $subject = $ok ? "Solicitação {$code} aprovada" : "Solicitação {$code} reprovada";
        $body = $ok
            ? "Sua solicitação {$code} foi aprovada."
            : "Sua solicitação {$code} foi reprovada." . ($justification ? " Motivo: {$justification}" : '');
        $requesterId = (int) ($request['requester']['id'] ?? $request['requester_id'] ?? 0);
        self::queueInApp($requesterId ?: null, $subject, $body, '/solicitacoes/' . $request['id'], null, 'request', (int) $request['id']);
        $email = $requesterId ? Db::value('SELECT email FROM users WHERE id = ?', [$requesterId]) : null;
        if ($email) {
            self::queueEmail($requesterId, (string) $email, $subject, Mailer::layout($subject, '<p>' . e($body) . '</p>'), null, null, 'request', (int) $request['id']);
        }
    }

    public static function requestReady(array $request): void
    {
        $code = $request['code'];
        $subject = "Brindes prontos para retirada — {$code}";
        $body = "A solicitação {$code} está pronta para retirada/entrega.";
        $requesterId = (int) ($request['requester']['id'] ?? $request['requester_id'] ?? 0);
        self::queueInApp($requesterId ?: null, $subject, $body, '/solicitacoes/' . $request['id'], null, 'request', (int) $request['id']);
    }

    public static function protocolEmail(array $delivery, array $items, ?string $industryEmail, ?int $remaining = null): array
    {
        $tos = [];
        foreach ([$delivery['received_by_email'] ?? null, $industryEmail] as $candidate) {
            $email = is_string($candidate) ? trim($candidate) : '';
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !in_array($email, $tos, true)) {
                $tos[] = $email;
            }
        }
        if ($tos === []) {
            if (Auth::id()) {
                self::queueInApp(
                    Auth::id(),
                    'Protocolo ' . $delivery['code'],
                    'Retirada registrada. Informe um e-mail na próxima retirada para enviar o comprovante.',
                    '/protocolos/' . $delivery['id'],
                    null,
                    'delivery',
                    (int) $delivery['id']
                );
            }
            return [];
        }
        $rows = '';
        foreach ($items as $it) {
            $name = e($it['item']['name'] ?? $it['name'] ?? '');
            $qty = (int) ($it['qty'] ?? $it['quantity'] ?? 0);
            $rows .= "<tr><td style=\"padding:6px;border-bottom:1px solid #eee\">{$name}</td><td style=\"padding:6px;border-bottom:1px solid #eee\">{$qty}</td></tr>";
        }
        $saldo = $remaining !== null ? "<p>Saldo restante nesta cota: <b>{$remaining}</b>.</p>" : '';
        $html = Mailer::layout(
            'Protocolo ' . $delivery['code'],
            '<p>Olá, ' . e($delivery['received_by_name']) . '.</p>'
            . '<p>Confirmamos a retirada dos brindes abaixo (protocolo <b>' . e($delivery['code']) . '</b>).</p>'
            . '<table width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>'
            . $saldo
        );
        $attachment = !empty($delivery['pdf_path']) ? ImageService::absolute($delivery['pdf_path']) : null;
        $userId = $delivery['user_id'] ?? Auth::id();
        foreach ($tos as $i => $to) {
            self::queueEmail(is_numeric($userId) ? (int) $userId : null, (string) $to, 'Protocolo ' . $delivery['code'] . ' — comprovante de retirada', $html, $attachment, 'protocol-' . $delivery['id'] . '-' . $i, 'delivery', (int) $delivery['id']);
        }
        if (Auth::id()) {
            self::queueInApp(
                Auth::id(),
                'Protocolo ' . $delivery['code'],
                'Comprovante da retirada gerado' . (Mailer::isSmtp() ? ' e e-mail enviado para ' . implode(', ', $tos) . '.' : '. Configure SMTP para o comprovante sair por e-mail.'),
                '/protocolos/' . $delivery['id'],
                null,
                'delivery',
                (int) $delivery['id']
            );
        }
        return $tos;
    }

    public static function stockAlert(int $itemId, string $code, string $name, string $level): void
    {
        $dedupe = 'stock-' . $level . '-' . $itemId . '-' . today();
        $subject = $level === 'zero' ? "Sem estoque: {$code}" : "Estoque mínimo: {$code}";
        $body = $level === 'zero'
            ? "O brinde {$code} — {$name} está sem estoque."
            : "O brinde {$code} — {$name} atingiu o estoque mínimo.";
        $users = Db::fetchAll(
            "SELECT DISTINCT u.id, u.email FROM users u
               JOIN role_permissions rp ON rp.role_id = u.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE u.deleted_at IS NULL AND u.active = 1 AND p.slug IN ('alerts.stock','settings.manage')"
        );
        foreach ($users as $u) {
            self::queueInApp((int) $u['id'], $subject, $body, '/brindes/' . $itemId, $dedupe . '-u' . $u['id'], 'item', $itemId);
            if (!empty($u['email'])) {
                self::queueEmail((int) $u['id'], $u['email'], $subject, Mailer::layout($subject, '<p>' . e($body) . '</p>'), null, $dedupe . '-e' . $u['id'], 'item', $itemId);
            }
        }
    }

    public static function inbox(Request $request): array
    {
        $uid = (int) Auth::id();
        $where = 'user_id = ? AND channel = \'in_app\'';
        $params = [$uid];
        if ($request->queryBool('unread')) {
            $where .= ' AND read_at IS NULL';
        }
        [$rows, $meta] = Paginator::run($request, 'SELECT *', "FROM notifications WHERE {$where}", $params, 'ORDER BY created_at DESC', 25);
        $unread = (int) Db::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND channel = 'in_app' AND read_at IS NULL", [$uid]);
        $meta['unread'] = $unread;
        $data = array_map(fn ($n) => [
            'id' => (int) $n['id'],
            'subject' => $n['subject'],
            'body' => $n['body'],
            'link_url' => $n['link_url'],
            'related_type' => $n['related_type'],
            'related_id' => $n['related_id'] !== null ? (int) $n['related_id'] : null,
            'read_at' => $n['read_at'],
            'created_at' => $n['created_at'],
        ], $rows);
        return [$data, $meta];
    }

    public static function markRead(int $id): void
    {
        Db::query('UPDATE notifications SET read_at = COALESCE(read_at, ?) WHERE id = ? AND user_id = ?', [now(), $id, Auth::id()]);
    }

    public static function markAllRead(): void
    {
        Db::query("UPDATE notifications SET read_at = ? WHERE user_id = ? AND channel = 'in_app' AND read_at IS NULL", [now(), Auth::id()]);
    }

    public static function processOutbox(int $limit = 15): int
    {
        $rows = Db::fetchAll("SELECT * FROM notifications WHERE channel = 'email' AND status = 'fila' ORDER BY id ASC LIMIT {$limit}");
        $sent = 0;
        foreach ($rows as $row) {
            try {
                $abs = $row['attachment_path'] ? ImageService::absolute($row['attachment_path']) : null;
                Mailer::send((string) $row['to_email'], (string) $row['subject'], (string) $row['body'], ($abs && is_file($abs)) ? [$abs] : []);
                Db::update('notifications', ['status' => 'enviado', 'sent_at' => now(), 'attempts' => (int) $row['attempts'] + 1], ['id' => (int) $row['id']]);
                $sent++;
            } catch (Throwable $e) {
                $attempts = (int) $row['attempts'] + 1;
                Db::update('notifications', [
                    'status' => $attempts >= 5 ? 'falhou' : 'fila',
                    'attempts' => $attempts,
                    'last_error' => mb_substr($e->getMessage(), 0, 500),
                ], ['id' => (int) $row['id']]);
            }
        }
        return $sent;
    }

    public static function flush(): void
    {
        try {
            self::processOutbox(8);
        } catch (Throwable) {
        }
    }

    private static function insert(array $row): void
    {
        $row += ['created_at' => now(), 'attempts' => 0];
        if (!empty($row['dedupe_key'])) {
            $exists = Db::value('SELECT 1 FROM notifications WHERE dedupe_key = ?', [$row['dedupe_key']]);
            if ($exists) {
                return;
            }
        }
        try {
            Db::insert('notifications', $row);
        } catch (Throwable $e) {
            if (!Db::isDuplicateKey($e)) {
                throw $e;
            }
        }
    }
}
