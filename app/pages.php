<?php

declare(strict_types=1);

/**
 * Page routes -> view files in resources/views.
 * Each page is only a shell: its JS loads data from /api.
 *   guest => true : only for logged-out users (login, password reset)
 *   perm          : permission needed to open the page (any-of when array)
 * All listed views exist under resources/views/.
 */
return [
    // --- Authentication -------------------------------------------------
    ['path' => '/login', 'view' => 'pages/auth/login', 'title' => 'Entrar', 'guest' => true],
    ['path' => '/esqueci-senha', 'view' => 'pages/auth/forgot', 'title' => 'Esqueci minha senha', 'guest' => true],
    ['path' => '/redefinir-senha', 'view' => 'pages/auth/reset', 'title' => 'Redefinir senha', 'guest' => true],

    // --- Step 1 ---------------------------------------------------------
    ['path' => '/dashboard', 'view' => 'pages/dashboard', 'title' => 'Dashboard', 'perm' => 'dashboard.view'],
    ['path' => '/perfil', 'view' => 'pages/profile', 'title' => 'Meu perfil'],
    ['path' => '/notificacoes', 'view' => 'pages/notifications', 'title' => 'Notificações'],

    ['path' => '/brindes', 'view' => 'pages/items/index', 'title' => 'Brindes', 'perm' => 'items.view'],
    ['path' => '/brindes/novo', 'view' => 'pages/items/form', 'title' => 'Novo brinde', 'perm' => 'items.manage'],
    ['path' => '/brindes/{id:\d+}', 'view' => 'pages/items/show', 'title' => 'Brinde', 'perm' => 'items.view'],
    ['path' => '/brindes/{id:\d+}/editar', 'view' => 'pages/items/form', 'title' => 'Editar brinde', 'perm' => 'items.manage'],

    ['path' => '/estoque', 'view' => 'pages/stock/index', 'title' => 'Livro de movimentações', 'perm' => 'stock.view'],
    ['path' => '/estoque/entrada', 'view' => 'pages/stock/entry', 'title' => 'Registrar entrada', 'perm' => 'stock.entry'],
    ['path' => '/estoque/saida', 'view' => 'pages/stock/exit', 'title' => 'Registrar saída', 'perm' => 'stock.exit', 'deny_cd' => true],
    ['path' => '/estoque/confirmar-saida', 'view' => 'pages/stock/queue', 'title' => 'Confirmar saída', 'perm' => 'stock.exit_confirm'],
    ['path' => '/estoque/ajuste', 'view' => 'pages/stock/adjust', 'title' => 'Ajuste de estoque', 'perm' => 'stock.adjust'],
    ['path' => '/estoque/por-industria', 'view' => 'pages/stock/by-industry', 'title' => 'Estoque por indústria', 'perm' => 'stock.view'],
    ['path' => '/estoque/transferencia', 'view' => 'pages/stock/transfer', 'title' => 'Transferência de estoque', 'perm' => ['stock.transfer', 'stock.exit']],
    ['path' => '/solicitacoes-trade', 'view' => 'pages/trade/index', 'title' => 'Solicitações TRADE', 'perm' => ['requests.view_own', 'requests.view_department', 'requests.view_all']],
    ['path' => '/solicitacoes-trade/nova', 'view' => 'pages/trade/form', 'title' => 'Nova solicitação TRADE', 'perm' => 'requests.create', 'deny_cd' => true],
    ['path' => '/solicitacoes-trade/{id:\d+}', 'view' => 'pages/trade/show', 'title' => 'Solicitação TRADE', 'perm' => ['requests.view_own', 'requests.view_department', 'requests.view_all']],
    ['path' => '/recebimento', 'view' => 'pages/trade/receive', 'title' => 'Recebimento no CD', 'perm' => ['stock.receive', 'stock.entry']],
    ['path' => '/retirada', 'view' => 'pages/trade/scan', 'title' => 'Retirada / QR Code', 'perm' => ['requests.process', 'stock.exit', 'events.withdraw']],

    ['path' => '/cadastros/{type:categorias|departamentos|industrias|locais|fornecedores}', 'view' => 'pages/lookups/index', 'title' => 'Cadastros', 'perm' => 'lookups.view', 'deny_cd' => true],
    ['path' => '/usuarios', 'view' => 'pages/users/index', 'title' => 'Usuários', 'perm' => 'users.view'],
    ['path' => '/perfis', 'view' => 'pages/roles/index', 'title' => 'Perfis e permissões', 'perm' => 'roles.manage'],
    ['path' => '/auditoria', 'view' => 'pages/audit/index', 'title' => 'Auditoria', 'perm' => 'audit.view'],
    ['path' => '/configuracoes', 'view' => 'pages/settings/index', 'title' => 'Configurações', 'perm' => 'settings.manage'],

    // --- Step 2 ---------------------------------------------------------
    ['path' => '/solicitacoes', 'view' => 'pages/requests/index', 'title' => 'Solicitações', 'perm' => ['requests.view_own', 'requests.view_department', 'requests.view_all']],
    ['path' => '/solicitacoes/nova', 'view' => 'pages/requests/form', 'title' => 'Nova solicitação', 'perm' => 'requests.create', 'deny_cd' => true],
    ['path' => '/solicitacoes/{id:\d+}', 'view' => 'pages/requests/show', 'title' => 'Solicitação', 'perm' => ['requests.view_own', 'requests.view_department', 'requests.view_all']],
    ['path' => '/aprovacoes', 'view' => 'pages/approvals/index', 'title' => 'Aprovações', 'perm' => 'requests.approve'],
    ['path' => '/operacao', 'view' => 'pages/operations/index', 'title' => 'Separação e entregas', 'perm' => 'requests.process', 'deny_cd' => true],
    ['path' => '/operacao/{id:\d+}/entrega', 'view' => 'pages/operations/deliver', 'title' => 'Registrar entrega', 'perm' => 'requests.process', 'deny_cd' => true],
    ['path' => '/eventos', 'view' => 'pages/events/index', 'title' => 'Eventos', 'perm' => 'events.view'],
    ['path' => '/eventos/{id:\d+}', 'view' => 'pages/events/show', 'title' => 'Evento', 'perm' => 'events.view'],
    ['path' => '/eventos/{id:\d+}/modo-evento', 'view' => 'pages/events/mode', 'title' => 'Modo Evento', 'perm' => 'events.withdraw'],
    ['path' => '/protocolos', 'view' => 'pages/deliveries/index', 'title' => 'Comprovantes', 'perm' => 'deliveries.view'],
    ['path' => '/protocolos/{id:\d+}', 'view' => 'pages/deliveries/show', 'title' => 'Protocolo', 'perm' => 'deliveries.view'],
    ['path' => '/regras-aprovacao', 'view' => 'pages/rules/index', 'title' => 'Regras de aprovação', 'perm' => 'rules.manage'],

    // --- Step 3 ---------------------------------------------------------
    ['path' => '/relatorios', 'view' => 'pages/reports/index', 'title' => 'Relatórios', 'perm' => 'reports.view'],
    ['path' => '/importar', 'view' => 'pages/import/index', 'title' => 'Importar planilha', 'perm' => 'import.run'],
];
