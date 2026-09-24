<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Duplicate-submission protection for stock and delivery writes.
 *
 * The client sends "Idempotency-Key: <uuid>" (one per form opening). The key row is
 * inserted FIRST, inside the same transaction as the business write:
 *  - a concurrent duplicate blocks on the unique index and then fails -> gets the stored response
 *  - a later duplicate finds the stored response and receives it again (no second movement)
 *  - if the business write fails, the key row is rolled back and the user may retry
 */
final class Idempotency
{
    public static function run(Request $request, callable $handler): Response
    {
        $key = $request->header('Idempotency-Key');
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{8,80}$/', $key)) {
            throw HttpException::badRequest(
                'IDEMPOTENCY_KEY_REQUIRED',
                'Cabeçalho Idempotency-Key ausente ou inválido (use um UUID por envio de formulário).'
            );
        }
        $userId = (int) Auth::id();
        $hash = hash('sha256', $request->method . ' ' . $request->path . ' ' . json_encode($request->all()));

        $stored = self::find($userId, $key);
        if ($stored !== null) {
            return self::replay($stored, $hash);
        }

        try {
            return Db::transaction(function () use ($request, $handler, $userId, $key, $hash) {
                $rowId = Db::insert('idempotency_keys', [
                    'user_id' => $userId,
                    'idem_key' => $key,
                    'scope' => $request->method . ' ' . $request->path,
                    'request_hash' => $hash,
                    'created_at' => now(),
                ]);
                /** @var Response $response */
                $response = $handler();
                Db::update('idempotency_keys', [
                    'status_code' => $response->status,
                    'response_body' => $response->body,
                ], ['id' => $rowId]);
                return $response;
            });
        } catch (Throwable $e) {
            if (Db::isDuplicateKey($e) && ($stored = self::find($userId, $key)) !== null) {
                return self::replay($stored, $hash);
            }
            throw $e;
        }
    }

    private static function find(int $userId, string $key): ?array
    {
        return Db::fetch('SELECT * FROM idempotency_keys WHERE user_id = ? AND idem_key = ?', [$userId, $key]);
    }

    private static function replay(array $stored, string $hash): Response
    {
        if (!hash_equals($stored['request_hash'], $hash)) {
            throw HttpException::rule(
                'IDEMPOTENCY_KEY_REUSED',
                'Esta chave de envio já foi usada com outros dados. Recarregue o formulário.'
            );
        }
        if ((int) $stored['status_code'] === 0) {
            throw HttpException::conflict('REQUEST_IN_PROGRESS', 'Este envio ainda está sendo processado. Aguarde.');
        }
        return (new Response((int) $stored['status_code'], ['Content-Type' => 'application/json; charset=utf-8'], (string) $stored['response_body']))
            ->withHeader('Idempotent-Replayed', 'true');
    }
}
