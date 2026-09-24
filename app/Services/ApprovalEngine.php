<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** First matching active rule (by priority ASC) defines who must approve. */
final class ApprovalEngine
{
    /**
     * @param array $request row with department_id, requester role via $requesterRoleId
     * @param array $items   lines with qty_requested, unit_value, category_id
     */
    public static function match(array $request, array $items, int $requesterRoleId): ?array
    {
        $totalQty = 0;
        $totalValue = 0.0;
        $categories = [];
        foreach ($items as $line) {
            $qty = (int) $line['qty_requested'];
            $totalQty += $qty;
            $totalValue += $qty * (float) ($line['unit_value'] ?? 0);
            if (!empty($line['category_id'])) {
                $categories[] = (int) $line['category_id'];
            }
        }
        $rules = Db::fetchAll(
            'SELECT * FROM approval_rules WHERE active = 1 AND deleted_at IS NULL ORDER BY priority ASC, id ASC'
        );
        foreach ($rules as $rule) {
            if (self::matches($rule, $totalQty, $totalValue, $categories, (int) ($request['department_id'] ?? 0), $requesterRoleId)) {
                return $rule;
            }
        }
        return null;
    }

    private static function matches(array $rule, int $qty, float $value, array $categories, int $departmentId, int $roleId): bool
    {
        $raw = (string) $rule['value'];
        $op = (string) $rule['operator'];
        $subject = match ($rule['criterion']) {
            'quantidade' => $qty,
            'valor' => $value,
            'categoria' => $categories,
            'departamento' => $departmentId,
            'perfil' => $roleId,
            default => null,
        };
        if ($subject === null) {
            return false;
        }
        if (is_array($subject)) {
            $needles = array_map('intval', preg_split('/\s*,\s*/', $raw) ?: []);
            return (bool) array_intersect($subject, $needles);
        }
        $threshold = (float) str_replace(',', '.', $raw);
        return match ($op) {
            '>' => $subject > $threshold,
            '>=' => $subject >= $threshold,
            '=' => abs((float) $subject - $threshold) < 0.0001,
            'in' => in_array((int) $subject, array_map('intval', preg_split('/\s*,\s*/', $raw) ?: []), true),
            default => false,
        };
    }
}
