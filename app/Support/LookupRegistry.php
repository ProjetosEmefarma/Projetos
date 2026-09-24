<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\HttpException;

/**
 * Definition of the auxiliary registers served by the generic LookupController.
 * 'fields'     => validation rules (store; update uses the same rules with "sometimes")
 * 'labels'     => PT-BR labels (also returned by GET /api/lookups/schema for the UI)
 * 'references' => where the record is used; blocks permanent deletion while in use
 */
final class LookupRegistry
{
    private const CONTACT_FIELDS = [
        'name' => 'required|string|max:150',
        'cnpj' => ['nullable', 'string', 'max:20', 'regex:/^[0-9.\/\- ]+$/'],
        'contact_name' => 'nullable|string|max:120',
        'contact_email' => 'nullable|email|max:190',
        'contact_phone' => 'nullable|string|max:30',
        'notes' => 'nullable|string|max:2000',
    ];

    private const CONTACT_LABELS = [
        'name' => 'Nome',
        'cnpj' => 'CNPJ',
        'contact_name' => 'Contato',
        'contact_email' => 'E-mail do contato',
        'contact_phone' => 'Telefone',
        'notes' => 'Observações',
    ];

    private const SIMPLE_FIELDS = [
        'name' => 'required|string|max:100',
        'description' => 'nullable|string|max:255',
    ];

    private const SIMPLE_LABELS = ['name' => 'Nome', 'description' => 'Descrição'];

    public static function all(): array
    {
        return [
            'categories' => [
                'table' => 'categories', 'entity' => 'category', 'page' => 'categorias',
                'label' => 'Categoria', 'label_plural' => 'Categorias',
                'fields' => self::SIMPLE_FIELDS, 'labels' => self::SIMPLE_LABELS,
                'search' => ['name', 'description'],
                'references' => [['items', 'category_id', 'brinde(s)']],
            ],
            'departments' => [
                'table' => 'departments', 'entity' => 'department', 'page' => 'departamentos',
                'label' => 'Departamento', 'label_plural' => 'Departamentos',
                'fields' => self::SIMPLE_FIELDS, 'labels' => self::SIMPLE_LABELS,
                'search' => ['name', 'description'],
                'references' => [
                    ['users', 'department_id', 'usuário(s)'],
                    ['requests', 'department_id', 'solicitação(ões)'],
                    ['stock_movements', 'department_id', 'movimentação(ões)'],
                ],
            ],
            'industries' => [
                'table' => 'industries', 'entity' => 'industry', 'page' => 'industrias',
                'label' => 'Indústria', 'label_plural' => 'Indústrias',
                'fields' => self::CONTACT_FIELDS, 'labels' => self::CONTACT_LABELS,
                'search' => ['name', 'cnpj', 'contact_name', 'contact_email'],
                'references' => [
                    ['requests', 'industry_id', 'solicitação(ões)'],
                    ['event_allocations', 'industry_id', 'cota(s) de evento'],
                    ['deliveries', 'industry_id', 'protocolo(s)'],
                    ['stock_movements', 'industry_id', 'movimentação(ões)'],
                ],
            ],
            'locations' => [
                'table' => 'locations', 'entity' => 'location', 'page' => 'locais',
                'label' => 'Local de armazenamento', 'label_plural' => 'Locais de armazenamento',
                'fields' => [
                    'name' => 'required|string|max:100',
                    'description' => 'nullable|string|max:255',
                    'kind' => 'required|in:cd,evento,outro',
                ],
                'labels' => [
                    'name' => 'Nome',
                    'description' => 'Descrição',
                    'kind' => 'Tipo',
                ],
                'options' => [
                    'kind' => [
                        'cd' => 'CD / depósito',
                        'evento' => 'Evento / feirão',
                        'outro' => 'Escritório / outro local',
                    ],
                ],
                'search' => ['name', 'description'],
                'references' => [['items', 'location_id', 'brinde(s)'], ['stock_positions', 'location_id', 'posição(ões) de estoque']],
            ],
            'suppliers' => [
                'table' => 'suppliers', 'entity' => 'supplier', 'page' => 'fornecedores',
                'label' => 'Fornecedor', 'label_plural' => 'Fornecedores',
                'fields' => self::CONTACT_FIELDS, 'labels' => self::CONTACT_LABELS,
                'search' => ['name', 'cnpj', 'contact_name', 'contact_email'],
                'references' => [
                    ['items', 'supplier_id', 'brinde(s)'],
                    ['stock_movements', 'supplier_id', 'movimentação(ões)'],
                ],
            ],
        ];
    }

    /** @return string[] */
    public static function types(): array
    {
        return array_keys(self::all());
    }

    public static function get(string $type): array
    {
        $all = self::all();
        if (!isset($all[$type])) {
            throw HttpException::notFound('Cadastro não encontrado.');
        }
        return $all[$type] + ['type' => $type];
    }

    /** Public schema for the generic "Cadastros" UI page. */
    public static function schema(): array
    {
        $out = [];
        foreach (self::all() as $type => $def) {
            $fields = [];
            foreach ($def['fields'] as $name => $rules) {
                $list = is_array($rules) ? $rules : explode('|', $rules);
                $max = null;
                foreach ($list as $rule) {
                    if (str_starts_with($rule, 'max:')) {
                        $max = (int) substr($rule, 4);
                    }
                }
                $fields[] = [
                    'name' => $name,
                    'label' => $def['labels'][$name] ?? $name,
                    'type' => isset($def['options'][$name]) ? 'select' : (in_array('email', $list, true) ? 'email' : ($name === 'notes' ? 'textarea' : 'text')),
                    'required' => in_array('required', $list, true),
                    'max' => $max,
                    'options' => $def['options'][$name] ?? null,
                ];
            }
            $out[] = [
                'type' => $type,
                'page' => $def['page'],
                'label' => $def['label'],
                'label_plural' => $def['label_plural'],
                'endpoint' => '/api/' . $type,
                'fields' => $fields,
            ];
        }
        return $out;
    }
}
