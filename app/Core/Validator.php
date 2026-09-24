<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use LogicException;

/**
 * Input validation with PT-BR messages. Returns only the validated fields,
 * already cast (int, float, bool, trimmed strings, null for empty values).
 *
 * Rules (in order): required, sometimes, nullable, string, int, numeric, bool,
 * email, date, in:a,b, min:n, max:n, regex:/.../, exists:table, array
 *   - sometimes: validate only when the key is present (partial updates)
 *   - numeric accepts "12,50" and "1.234,56" (Brazilian format)
 *   - min/max compare values for int/numeric and length for strings
 *   - exists ignores soft-deleted rows
 */
final class Validator
{
    private const SOFT_DELETE_TABLES = [
        'users', 'departments', 'categories', 'locations', 'suppliers', 'industries',
        'items', 'events', 'approval_rules', 'requests',
    ];

    /** @throws HttpException 422 VALIDATION_ERROR */
    public static function validate(array $input, array $rules): array
    {
        $errors = [];
        $out = [];

        foreach ($rules as $field => $spec) {
            $ruleList = is_array($spec) ? $spec : explode('|', $spec);
            $names = array_map(fn (string $r) => explode(':', $r, 2)[0], $ruleList);
            $present = array_key_exists($field, $input);

            if (in_array('sometimes', $names, true) && !$present) {
                continue;
            }

            $value = $present ? $input[$field] : null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $empty = $value === null || $value === '' || $value === [];

            if ($empty) {
                if (in_array('required', $names, true)) {
                    $errors[$field] = 'Campo obrigatório.';
                } elseif ($present || in_array('nullable', $names, true)) {
                    $out[$field] = null;
                }
                continue;
            }

            $numeric = in_array('int', $names, true) || in_array('numeric', $names, true);
            $error = null;

            foreach ($ruleList as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                switch ($name) {
                    case 'required':
                    case 'nullable':
                    case 'sometimes':
                        break;

                    case 'string':
                        if (is_string($value) || is_int($value) || is_float($value)) {
                            $value = (string) $value;
                        } else {
                            $error = 'Texto inválido.';
                        }
                        break;

                    case 'int':
                        if (is_int($value) || (is_string($value) && preg_match('/^-?\d{1,10}$/', $value))) {
                            $value = (int) $value;
                        } elseif (is_float($value) && floor($value) === $value) {
                            $value = (int) $value;
                        } else {
                            $error = 'Informe um número inteiro.';
                        }
                        break;

                    case 'numeric':
                        $parsed = self::parseNumber($value);
                        if ($parsed === null) {
                            $error = 'Informe um valor numérico.';
                        } else {
                            $value = $parsed;
                        }
                        break;

                    case 'bool':
                        $bool = is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($bool === null) {
                            $error = 'Valor inválido.';
                        } else {
                            $value = $bool;
                        }
                        break;

                    case 'email':
                        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $error = 'E-mail inválido.';
                        } else {
                            $value = mb_strtolower($value);
                        }
                        break;

                    case 'date':
                        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                        if (!$date || $date->format('Y-m-d') !== $value) {
                            $error = 'Data inválida (use AAAA-MM-DD).';
                        }
                        break;

                    case 'in':
                        if (!in_array((string) (is_bool($value) ? (int) $value : $value), explode(',', (string) $arg), true)) {
                            $error = 'Valor inválido.';
                        }
                        break;

                    case 'min':
                        if ($numeric ? $value < (float) $arg : mb_strlen((string) $value) < (int) $arg) {
                            $error = $numeric ? "Deve ser no mínimo {$arg}." : "Mínimo de {$arg} caracteres.";
                        }
                        break;

                    case 'max':
                        if ($numeric ? $value > (float) $arg : mb_strlen((string) $value) > (int) $arg) {
                            $error = $numeric ? "Deve ser no máximo {$arg}." : "Máximo de {$arg} caracteres.";
                        }
                        break;

                    case 'regex':
                        if (!is_string($value) || !preg_match((string) $arg, $value)) {
                            $error = 'Formato inválido.';
                        }
                        break;

                    case 'exists':
                        $table = (string) $arg;
                        $sql = "SELECT 1 FROM `{$table}` WHERE id = ?"
                            . (in_array($table, self::SOFT_DELETE_TABLES, true) ? ' AND deleted_at IS NULL' : '');
                        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                            $error = 'Registro selecionado inválido.';
                        } elseif (!Db::value($sql, [(int) $value])) {
                            $error = 'Registro selecionado não existe.';
                        } else {
                            $value = (int) $value;
                        }
                        break;

                    case 'array':
                        if (!is_array($value)) {
                            $error = 'Lista inválida.';
                        }
                        break;

                    default:
                        throw new LogicException("Unknown validation rule: {$name}");
                }
                if ($error !== null) {
                    break;
                }
            }

            if ($error !== null) {
                $errors[$field] = $error;
            } else {
                $out[$field] = $value;
            }
        }

        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        return $out;
    }

    /** "1.234,56" | "1234.56" | 12 → float, or null when not a number. */
    public static function parseNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $v = str_replace([' ', 'R$'], '', $value);
        if (str_contains($v, ',')) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        }
        return is_numeric($v) ? (float) $v : null;
    }

    /** Shared password policy (min 8 chars, uppercase, lowercase, digit, special char). */
    public static function password(string $field, mixed $value): void
    {
        $value = is_string($value) ? $value : '';
        $len = mb_strlen($value);
        if ($len < 8) {
            throw HttpException::validation([$field => 'A senha deve ter no mínimo 8 caracteres.']);
        }
        if ($len > 72) {
            throw HttpException::validation([$field => 'A senha deve ter no máximo 72 caracteres.']);
        }
        if (!preg_match('/[A-Z]/', $value)) {
            throw HttpException::validation([$field => 'A senha deve conter ao menos uma letra maiúscula.']);
        }
        if (!preg_match('/[a-z]/', $value)) {
            throw HttpException::validation([$field => 'A senha deve conter ao menos uma letra minúscula.']);
        }
        if (!preg_match('/\d/', $value)) {
            throw HttpException::validation([$field => 'A senha deve conter ao menos um número.']);
        }
        if (!preg_match('/[^A-Za-z0-9]/', $value)) {
            throw HttpException::validation([$field => 'A senha deve conter ao menos um caractere especial (ex: @, #, !, $).']);
        }
    }
}
