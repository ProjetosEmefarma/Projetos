<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Auth;

/**
 * Sidebar menu filtered by the user's permissions. Icons are Bootstrap Icons names.
 */
final class Menu
{
    private const GROUPS = [
        ['group' => 'Principal', 'items' => [
            ['label' => 'Dashboard', 'icon' => 'speedometer2', 'url' => '/dashboard', 'perm' => 'dashboard.view'],
        ]],
        ['group' => 'TRADE', 'items' => [
            ['label' => 'Solicitações TRADE', 'icon' => 'clipboard-check', 'url' => '/solicitacoes-trade', 'perm' => ['requests.view_own', 'requests.view_department', 'requests.view_all']],
            ['label' => 'Registrar saída', 'icon' => 'box-arrow-up', 'url' => '/estoque/saida', 'perm' => 'stock.exit', 'hide_cd' => true],
            ['label' => 'Recebimento no CD', 'icon' => 'box-arrow-in-down', 'url' => '/recebimento', 'perm' => ['stock.receive', 'stock.entry']],
            ['label' => 'Estoque por indústria', 'icon' => 'building', 'url' => '/estoque/por-industria', 'perm' => 'stock.view'],
            ['label' => 'Transferência', 'icon' => 'arrow-left-right', 'url' => '/estoque/transferencia', 'perm' => ['stock.transfer', 'stock.exit']],
            ['label' => 'Retirada / QR Code', 'icon' => 'qr-code', 'url' => '/retirada', 'perm' => ['requests.process', 'stock.exit', 'events.withdraw']],
            ['label' => 'Eventos / Feirões', 'icon' => 'calendar-event', 'url' => '/eventos', 'perm' => 'events.view'],
            ['label' => 'Comprovantes', 'icon' => 'file-earmark-check', 'url' => '/protocolos', 'perm' => 'deliveries.view'],
        ]],
        ['group' => 'Solicitações', 'items' => [
            ['label' => 'Solicitações internas', 'icon' => 'inbox', 'url' => '/solicitacoes', 'perm' => ['requests.view_own', 'requests.view_department', 'requests.view_all']],
            ['label' => 'Aprovações', 'icon' => 'check2-square', 'url' => '/aprovacoes', 'perm' => 'requests.approve'],
            ['label' => 'Separação e entregas', 'icon' => 'box-seam', 'url' => '/operacao', 'perm' => 'requests.process', 'hide_cd' => true],
        ]],
        ['group' => 'Estoque', 'items' => [
            ['label' => 'Brindes', 'icon' => 'gift', 'url' => '/brindes', 'perm' => 'items.view'],
            ['label' => 'Livro de movimentações', 'icon' => 'journal-text', 'url' => '/estoque', 'perm' => 'stock.view'],
            ['label' => 'Registrar entrada', 'icon' => 'box-arrow-in-down', 'url' => '/estoque/entrada', 'perm' => 'stock.entry'],
            ['label' => 'Registrar saída', 'icon' => 'box-arrow-up', 'url' => '/estoque/saida', 'perm' => 'stock.exit', 'hide_cd' => true],
            ['label' => 'Confirmar saída', 'icon' => 'qr-code', 'url' => '/estoque/confirmar-saida', 'perm' => 'stock.exit_confirm'],
            ['label' => 'Ajuste / estorno', 'icon' => 'sliders', 'url' => '/estoque/ajuste', 'perm' => 'stock.adjust'],
        ]],
        ['group' => 'Gestão', 'items' => [
            ['label' => 'Relatórios', 'icon' => 'bar-chart-line', 'url' => '/relatorios', 'perm' => 'reports.view'],
            ['label' => 'Importar planilha', 'icon' => 'file-earmark-spreadsheet', 'url' => '/importar', 'perm' => 'import.run'],
        ]],
        ['group' => 'Cadastros', 'hide_cd' => true, 'items' => [
            ['label' => 'Categorias', 'icon' => 'tags', 'url' => '/cadastros/categorias', 'perm' => 'lookups.view'],
            ['label' => 'Departamentos', 'icon' => 'diagram-3', 'url' => '/cadastros/departamentos', 'perm' => 'lookups.view'],
            ['label' => 'Indústrias', 'icon' => 'building', 'url' => '/cadastros/industrias', 'perm' => 'lookups.view'],
            ['label' => 'Locais', 'icon' => 'geo-alt', 'url' => '/cadastros/locais', 'perm' => 'lookups.view'],
            ['label' => 'Fornecedores', 'icon' => 'truck', 'url' => '/cadastros/fornecedores', 'perm' => 'lookups.view'],
        ]],
        ['group' => 'Administração', 'items' => [
            ['label' => 'Usuários', 'icon' => 'people', 'url' => '/usuarios', 'perm' => 'users.view'],
            ['label' => 'Perfis e permissões', 'icon' => 'shield-lock', 'url' => '/perfis', 'perm' => 'roles.manage'],
            ['label' => 'Regras de aprovação', 'icon' => 'signpost-split', 'url' => '/regras-aprovacao', 'perm' => 'rules.manage'],
            ['label' => 'Auditoria', 'icon' => 'journal-text', 'url' => '/auditoria', 'perm' => 'audit.view'],
            ['label' => 'Configurações', 'icon' => 'gear', 'url' => '/configuracoes', 'perm' => 'settings.manage'],
        ]],
    ];

    public static function build(string $currentPath): array
    {
        $menu = [];
        foreach (self::GROUPS as $group) {
            if (!empty($group['hide_cd']) && Auth::isCdOperations()) {
                continue;
            }
            if (!empty($group['deny_roles']) && in_array(Auth::roleSlug(), $group['deny_roles'], true)) {
                continue;
            }
            $items = [];
            foreach ($group['items'] as $item) {
                if (!empty($item['hide_cd']) && Auth::isCdOperations()) {
                    continue;
                }
                if (!Auth::can($item['perm'])) {
                    continue;
                }
                $active = $currentPath === $item['url']
                    || ($item['url'] !== '/dashboard' && str_starts_with($currentPath, $item['url'] . '/')
                        && !self::hasMoreSpecific($currentPath, $item['url']));
                $items[] = [
                    'label' => $item['label'],
                    'icon' => $item['icon'],
                    'url' => url($item['url']),
                    'path' => $item['url'],
                    'active' => $active,
                    'soon' => $item['soon'] ?? false,
                ];
            }
            if ($items !== []) {
                $menu[] = ['group' => $group['group'], 'items' => $items];
            }
        }
        return $menu;
    }

    /** /estoque/entrada should highlight "Registrar entrada", not "Movimentações". */
    private static function hasMoreSpecific(string $path, string $base): bool
    {
        foreach (self::GROUPS as $group) {
            foreach ($group['items'] as $item) {
                if ($item['url'] !== $base && strlen($item['url']) > strlen($base)
                    && ($path === $item['url'] || str_starts_with($path, $item['url'] . '/'))) {
                    return true;
                }
            }
        }
        return false;
    }
}
