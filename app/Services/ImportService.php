<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ImportService
{
    public static function template(string $type): string
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        if ($type === 'items') {
            $sheet->fromArray([['codigo', 'nome', 'categoria', 'local', 'fornecedor', 'valor_unitario', 'estoque_minimo', 'quantidade_inicial', 'observacoes']], null, 'A1');
        } else {
            $sheet->fromArray([['brinde', 'quantidade', 'valor', 'industria', 'departamento', 'finalidade', 'destinatario', 'chamado_compra', 'data_necessaria', 'observacoes']], null, 'A1');
        }
        $tmp = sys_get_temp_dir() . '/modelo-' . $type . '.xlsx';
        (new Xlsx($ss))->save($tmp);
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }

    public static function preview(string $type, array $file): array
    {
        $rows = self::read($file);
        return $type === 'items' ? self::previewItems($rows) : self::previewRequests($rows);
    }

    public static function commit(string $type, array $file): array
    {
        $preview = self::preview($type, $file);
        $ok = array_values(array_filter($preview['rows'], fn ($r) => $r['ok']));
        $created = 0;
        if ($type === 'items') {
            foreach ($ok as $r) {
                ItemService::create($r['payload']);
                $created++;
            }
        } else {
            foreach ($ok as $r) {
                RequestService::create($r['payload'], true);
                $created++;
            }
        }
        return ['created' => $created, 'skipped' => $preview['errors']];
    }

    private static function read(array $file): array
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw HttpException::validation(['file' => 'Selecione um arquivo Excel.']);
        }
        $ss = IOFactory::load($tmp);
        $sheet = $ss->getActiveSheet();
        $data = $sheet->toArray(null, true, true, false);
        if ($data === []) {
            throw HttpException::validation(['file' => 'Planilha vazia.']);
        }
        $header = array_map(fn ($h) => self::norm((string) $h), array_shift($data));
        $out = [];
        foreach ($data as $i => $row) {
            if (implode('', array_map(fn ($c) => trim((string) $c), $row)) === '') {
                continue;
            }
            $assoc = [];
            foreach ($header as $idx => $key) {
                if ($key !== '') {
                    $assoc[$key] = isset($row[$idx]) ? trim((string) $row[$idx]) : '';
                }
            }
            $out[] = ['line' => $i + 2, 'data' => $assoc];
        }
        return $out;
    }

    private static function previewItems(array $rows): array
    {
        $out = [];
        $errors = 0;
        foreach ($rows as $row) {
            $d = $row['data'];
            $name = $d['nome'] ?? $d['name'] ?? $d['brinde'] ?? '';
            $msgs = [];
            if ($name === '') {
                $msgs[] = 'Nome obrigatório';
            }
            $catName = $d['categoria'] ?? $d['category'] ?? 'Geral';
            $catId = self::lookupId('categories', $catName);
            if (!$catId) {
                $msgs[] = 'Categoria não encontrada: ' . $catName;
            }
            $payload = [
                'code' => $d['codigo'] ?? $d['code'] ?? null,
                'name' => $name,
                'category_id' => $catId,
                'location_id' => self::lookupId('locations', $d['local'] ?? $d['location'] ?? ''),
                'supplier_id' => self::lookupId('suppliers', $d['fornecedor'] ?? $d['supplier'] ?? ''),
                'unit_value' => $d['valor_unitario'] ?? $d['valor'] ?? null,
                'min_stock' => (int) ($d['estoque_minimo'] ?? 0),
                'initial_quantity' => (int) ($d['quantidade_inicial'] ?? $d['quantidade'] ?? 0),
                'notes' => $d['observacoes'] ?? $d['notes'] ?? null,
            ];
            $ok = $msgs === [];
            if (!$ok) {
                $errors++;
            }
            $out[] = ['line' => $row['line'], 'ok' => $ok, 'errors' => $msgs, 'payload' => $payload, 'preview' => $name];
        }
        return ['rows' => $out, 'errors' => $errors, 'total' => count($out)];
    }

    private static function previewRequests(array $rows): array
    {
        $out = [];
        $errors = 0;
        foreach ($rows as $row) {
            $d = $row['data'];
            $itemName = $d['brinde'] ?? $d['item'] ?? $d['nome'] ?? '';
            $qty = (int) ($d['quantidade'] ?? $d['qty'] ?? 0);
            $msgs = [];
            $itemId = self::itemId($itemName);
            if (!$itemId) {
                $msgs[] = 'Brinde não encontrado: ' . $itemName;
            }
            if ($qty < 1) {
                $msgs[] = 'Quantidade inválida';
            }
            $payload = [
                'purpose' => $d['finalidade'] ?? $d['purpose'] ?? 'Importado da planilha',
                'recipient' => $d['destinatario'] ?? $d['recipient'] ?? null,
                'purchase_ticket_no' => $d['chamado_compra'] ?? $d['chamado'] ?? null,
                'needed_date' => self::parseDate($d['data_necessaria'] ?? $d['data'] ?? ''),
                'industry_id' => self::lookupId('industries', $d['industria'] ?? $d['industry'] ?? ''),
                'department_id' => self::lookupId('departments', $d['departamento'] ?? $d['department'] ?? ''),
                'notes' => $d['observacoes'] ?? null,
                'items' => [['item_id' => $itemId, 'qty_requested' => max(1, $qty)]],
            ];
            $ok = $msgs === [];
            if (!$ok) {
                $errors++;
            }
            $out[] = ['line' => $row['line'], 'ok' => $ok, 'errors' => $msgs, 'payload' => $payload, 'preview' => $itemName . ' × ' . $qty];
        }
        return ['rows' => $out, 'errors' => $errors, 'total' => count($out)];
    }

    private static function lookupId(string $table, string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $id = Db::value("SELECT id FROM `{$table}` WHERE name = ? AND deleted_at IS NULL", [$name]);
        return $id ? (int) $id : null;
    }

    private static function itemId(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $id = Db::value('SELECT id FROM items WHERE deleted_at IS NULL AND (code = ? OR name = ?) LIMIT 1', [mb_strtoupper($name), $name]);
        return $id ? (int) $id : null;
    }

    private static function parseDate(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $v, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v;
        }
        return null;
    }

    private static function norm(string $h): string
    {
        $h = mb_strtolower(trim($h));
        $h = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $h) ?: $h;
        return preg_replace('/[^a-z0-9]+/', '_', $h) ?: '';
    }
}
