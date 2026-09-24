<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Expected error returned to the client as
 * {"ok":false,"error":{"code":"...","message":"...","fields":{...}}}
 */
class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $code, string $message): self
    {
        return new self(400, $code, $message);
    }

    public static function unauthenticated(): self
    {
        return new self(401, 'UNAUTHENTICATED', 'Sua sessão expirou. Faça login para continuar.');
    }

    public static function forbidden(string $message = 'Você não tem permissão para esta ação.', string $code = 'FORBIDDEN'): self
    {
        return new self(403, $code, $message);
    }

    public static function notFound(string $message = 'Registro não encontrado.'): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self(409, $code, $message, [], $details);
    }

    /** Business rule violation (422). */
    public static function rule(string $code, string $message, array $details = []): self
    {
        return new self(422, $code, $message, [], $details);
    }

    /** @param array<string,string> $fields field => message */
    public static function validation(array $fields, string $message = 'Verifique os campos destacados.'): self
    {
        return new self(422, 'VALIDATION_ERROR', $message, $fields);
    }
}
