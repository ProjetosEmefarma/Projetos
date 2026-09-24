<?php

declare(strict_types=1);

use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\DeliveriesController;
use App\Controllers\EventsController;
use App\Controllers\HealthController;
use App\Controllers\ImportController;
use App\Controllers\ItemsController;
use App\Controllers\LookupController;
use App\Controllers\NotificationsController;
use App\Controllers\ReportsController;
use App\Controllers\RequestsController;
use App\Controllers\RolesController;
use App\Controllers\RulesController;
use App\Controllers\SearchController;
use App\Controllers\SettingsController;
use App\Controllers\StockController;
use App\Controllers\TradeController;
use App\Controllers\UsersController;
use App\Core\Router;
use App\Support\LookupRegistry;

/**
 * API routes. Full contract with examples: docs/api-contract.md
 */
return static function (Router $r): void {
    $id = '{id:\d+}';

    // --- Health -------------------------------------------------------
    $r->get('/api/health', [HealthController::class, 'show'], ['public' => true]);

    // --- Auth ---------------------------------------------------------
    $r->get('/api/auth/csrf', [AuthController::class, 'csrf'], ['public' => true]);
    $r->post('/api/auth/login', [AuthController::class, 'login'], ['public' => true]);
    $r->post('/api/auth/forgot', [AuthController::class, 'forgot'], ['public' => true]);
    $r->post('/api/auth/reset', [AuthController::class, 'reset'], ['public' => true]);
    $r->post('/api/auth/logout', [AuthController::class, 'logout'], ['password_change_ok' => true]);
    $r->get('/api/auth/me', [AuthController::class, 'me'], ['password_change_ok' => true]);
    $r->post('/api/auth/change-password', [AuthController::class, 'changePassword'], ['password_change_ok' => true]);
    $r->put('/api/auth/profile', [AuthController::class, 'updateProfile']);

    // --- Settings / branding ------------------------------------------
    $r->get('/api/settings/public', [SettingsController::class, 'public'], ['public' => true]);
    $r->get('/api/settings/logo', [SettingsController::class, 'logo'], ['public' => true]);
    $r->get('/api/settings', [SettingsController::class, 'index'], ['perm' => 'settings.manage']);
    $r->put('/api/settings', [SettingsController::class, 'update'], ['perm' => 'settings.manage']);
    $r->post('/api/settings/logo', [SettingsController::class, 'uploadLogo'], ['perm' => 'settings.manage']);
    $r->delete('/api/settings/logo', [SettingsController::class, 'deleteLogo'], ['perm' => 'settings.manage']);
    $r->post('/api/settings/test-email', [SettingsController::class, 'testEmail'], ['perm' => 'settings.manage']);
    $r->get('/api/admin/backup', [SettingsController::class, 'backup'], ['perm' => 'settings.manage']);

    // --- Users --------------------------------------------------------
    $r->get('/api/users/options', [UsersController::class, 'options']);
    $r->get('/api/users', [UsersController::class, 'index'], ['perm' => 'users.view']);
    $r->post('/api/users', [UsersController::class, 'store'], ['perm' => 'users.manage']);
    $r->get("/api/users/{$id}", [UsersController::class, 'show'], ['perm' => 'users.view']);
    $r->put("/api/users/{$id}", [UsersController::class, 'update'], ['perm' => 'users.manage']);
    $r->post("/api/users/{$id}/activate", [UsersController::class, 'activate'], ['perm' => 'users.manage']);
    $r->post("/api/users/{$id}/deactivate", [UsersController::class, 'deactivate'], ['perm' => 'users.manage']);
    $r->delete("/api/users/{$id}", [UsersController::class, 'destroy'], ['perm' => 'users.manage']);
    $r->post("/api/users/{$id}/restore", [UsersController::class, 'restore'], ['perm' => 'users.manage']);
    $r->post("/api/users/{$id}/purge", [UsersController::class, 'purge'], ['perm' => 'users.manage']);

    // --- Roles & permissions ------------------------------------------
    $r->get('/api/roles', [RolesController::class, 'index'], ['perm' => ['users.view', 'roles.manage']]);
    $r->get('/api/permissions', [RolesController::class, 'permissions'], ['perm' => 'roles.manage']);
    $r->put("/api/roles/{$id}/permissions", [RolesController::class, 'updatePermissions'], ['perm' => 'roles.manage']);

    // --- Lookups: categories | departments | industries | locations | suppliers
    $r->get('/api/lookups/schema', [LookupController::class, 'schema'], ['perm' => 'lookups.view']);
    $type = '{type:' . implode('|', LookupRegistry::types()) . '}';
    $r->get("/api/{$type}", [LookupController::class, 'index'], ['perm' => 'lookups.view']);
    $r->post("/api/{$type}", [LookupController::class, 'store'], ['perm' => 'lookups.manage']);
    $r->get("/api/{$type}/{$id}", [LookupController::class, 'show'], ['perm' => 'lookups.view']);
    $r->put("/api/{$type}/{$id}", [LookupController::class, 'update'], ['perm' => 'lookups.manage']);
    $r->post("/api/{$type}/{$id}/activate", [LookupController::class, 'activate'], ['perm' => 'lookups.manage']);
    $r->post("/api/{$type}/{$id}/deactivate", [LookupController::class, 'deactivate'], ['perm' => 'lookups.manage']);
    $r->delete("/api/{$type}/{$id}", [LookupController::class, 'destroy'], ['perm' => 'lookups.delete']);
    $r->post("/api/{$type}/{$id}/restore", [LookupController::class, 'restore'], ['perm' => 'lookups.delete']);
    $r->post("/api/{$type}/{$id}/purge", [LookupController::class, 'purge'], ['perm' => 'lookups.purge']);

    // --- Items (brindes) ----------------------------------------------
    $r->get('/api/items', [ItemsController::class, 'index'], ['perm' => 'items.view']);
    $r->get('/api/items/options', [ItemsController::class, 'options'], ['perm' => 'items.view']);
    $r->get('/api/items/next-code', [ItemsController::class, 'nextCode'], ['perm' => 'items.manage']);
    $r->post('/api/items', [ItemsController::class, 'store'], ['perm' => 'items.manage']);
    $r->get("/api/items/{$id}", [ItemsController::class, 'show'], ['perm' => 'items.view']);
    $r->put("/api/items/{$id}", [ItemsController::class, 'update'], ['perm' => 'items.manage']);
    $r->get("/api/items/{$id}/photo", [ItemsController::class, 'photo'], ['perm' => 'items.view']);
    $r->post("/api/items/{$id}/photo", [ItemsController::class, 'uploadPhoto'], ['perm' => 'items.manage']);
    $r->delete("/api/items/{$id}/photo", [ItemsController::class, 'deletePhoto'], ['perm' => 'items.manage']);
    $r->post("/api/items/{$id}/activate", [ItemsController::class, 'activate'], ['perm' => 'items.manage']);
    $r->post("/api/items/{$id}/deactivate", [ItemsController::class, 'deactivate'], ['perm' => 'items.manage']);
    $r->delete("/api/items/{$id}", [ItemsController::class, 'destroy'], ['perm' => 'items.delete']);
    $r->post("/api/items/{$id}/restore", [ItemsController::class, 'restore'], ['perm' => 'items.delete']);
    $r->post("/api/items/{$id}/purge", [ItemsController::class, 'purge'], ['perm' => 'items.purge']);
    $r->get("/api/items/{$id}/history", [ItemsController::class, 'history'], ['perm' => 'items.view']);

    // --- Stock --------------------------------------------------------
    $r->get('/api/stock/summary', [StockController::class, 'summary'], ['perm' => 'stock.view']);
    $r->post('/api/stock/reconcile', [StockController::class, 'reconcile'], ['perm' => 'stock.adjust']);
    $r->get('/api/stock/movements', [StockController::class, 'movements'], ['perm' => 'stock.view']);
    $r->get('/api/stock/movements/{id:\d+}', [StockController::class, 'showMovement'], ['perm' => 'stock.view']);
    $r->get('/api/stock/movements/{id:\d+}/attachment', [StockController::class, 'attachment'], ['perm' => 'stock.view']);
    $r->post('/api/stock/entries', [StockController::class, 'entry'], ['perm' => 'stock.entry', 'idempotent' => true]);
    $r->post('/api/stock/exits', [StockController::class, 'exit'], ['perm' => 'stock.exit', 'idempotent' => true]);
    $r->get('/api/stock/exit-orders', [StockController::class, 'exitOrders'], ['perm' => ['stock.exit', 'stock.exit_confirm']]);
    $r->get('/api/stock/exit-orders/lookup', [StockController::class, 'lookupExitOrder'], ['perm' => ['stock.exit', 'stock.exit_confirm']]);
    $r->get("/api/stock/exit-orders/{$id}", [StockController::class, 'showExitOrder'], ['perm' => ['stock.exit', 'stock.exit_confirm']]);
    $r->get("/api/stock/exit-orders/{$id}/qr", [StockController::class, 'exitOrderQr'], ['perm' => ['stock.exit', 'stock.exit_confirm']]);
    $r->get("/api/stock/exit-orders/{$id}/attachment", [StockController::class, 'exitOrderAttachment'], ['perm' => ['stock.exit', 'stock.exit_confirm']]);
    $r->post("/api/stock/exit-orders/{$id}/confirm", [StockController::class, 'confirmExit'], ['perm' => 'stock.exit_confirm', 'idempotent' => true]);
    $r->post('/api/stock/adjustments', [StockController::class, 'adjust'], ['perm' => 'stock.adjust', 'idempotent' => true]);
    $r->get('/api/stock/by-industry', [TradeController::class, 'byIndustry'], ['perm' => 'stock.view']);
    $r->get('/api/stock/positions', [TradeController::class, 'positions'], ['perm' => 'stock.view']);
    $r->post('/api/stock/transfers', [TradeController::class, 'transfer'], ['perm' => ['stock.transfer', 'stock.exit'], 'idempotent' => true]);
    $r->post('/api/stock/reversals', [TradeController::class, 'reverse'], ['perm' => 'stock.adjust', 'idempotent' => true]);

    // --- TRADE (purchase → CD receive → withdraw) -----------------------
    $r->post('/api/trade/requests', [TradeController::class, 'store'], ['perm' => 'requests.create']);
    $r->post("/api/trade/requests/{$id}/approve", [TradeController::class, 'approve'], ['perm' => 'requests.approve']);
    $r->post("/api/trade/requests/{$id}/reject", [TradeController::class, 'reject'], ['perm' => 'requests.approve']);
    $r->post("/api/trade/requests/{$id}/purchase", [TradeController::class, 'purchase'], ['perm' => 'requests.approve']);
    $r->post("/api/trade/requests/{$id}/await-receipt", [TradeController::class, 'awaitReceipt'], ['perm' => 'requests.create']);
    $r->post("/api/trade/requests/{$id}/receive", [TradeController::class, 'receive'], ['perm' => ['stock.receive', 'stock.entry'], 'idempotent' => true]);
    $r->get("/api/trade/requests/{$id}/invoice", [TradeController::class, 'invoice'], ['perm' => ['requests.view_own', 'requests.view_department', 'requests.view_all']]);
    $r->post("/api/trade/requests/{$id}/withdraw", [TradeController::class, 'withdraw'], ['perm' => ['requests.process', 'stock.exit', 'events.withdraw'], 'idempotent' => true]);
    $r->post("/api/trade/requests/{$id}/delivered", [TradeController::class, 'delivered'], ['perm' => 'requests.process']);
    $r->get("/api/trade/requests/{$id}/qr", [TradeController::class, 'qr'], ['perm' => ['requests.view_own', 'requests.view_department', 'requests.view_all']]);
    $r->get('/api/trade/lookup', [TradeController::class, 'lookup']);

    // --- Dashboard ----------------------------------------------------
    $r->get('/api/dashboard', [DashboardController::class, 'show'], ['perm' => 'dashboard.view']);

    // --- Audit --------------------------------------------------------
    $r->get('/api/audit', [AuditController::class, 'index'], ['perm' => 'audit.view']);
    $r->get("/api/audit/{$id}", [AuditController::class, 'show'], ['perm' => 'audit.view']);

    // --- Requests -----------------------------------------------------
    $r->get('/api/requests', [RequestsController::class, 'index'], ['perm' => ['requests.view_own', 'requests.view_department', 'requests.view_all']]);
    $r->post('/api/requests', [RequestsController::class, 'store'], ['perm' => 'requests.create']);
    $r->post('/api/requests/check-availability', [RequestsController::class, 'checkAvailability'], ['perm' => 'requests.create']);
    $r->get("/api/requests/{$id}", [RequestsController::class, 'show'], ['perm' => ['requests.view_own', 'requests.view_department', 'requests.view_all']]);
    $r->put("/api/requests/{$id}", [RequestsController::class, 'update'], ['perm' => 'requests.create']);
    $r->post("/api/requests/{$id}/submit", [RequestsController::class, 'submit'], ['perm' => 'requests.create']);
    $r->post("/api/requests/{$id}/cancel", [RequestsController::class, 'cancel']);
    $r->post("/api/requests/{$id}/approve", [RequestsController::class, 'approve'], ['perm' => 'requests.approve']);
    $r->post("/api/requests/{$id}/reject", [RequestsController::class, 'reject'], ['perm' => 'requests.approve']);
    $r->post("/api/requests/{$id}/start-picking", [RequestsController::class, 'startPicking'], ['perm' => 'requests.process']);
    $r->post("/api/requests/{$id}/ready", [RequestsController::class, 'ready'], ['perm' => 'requests.process']);
    $r->post("/api/requests/{$id}/deliver", [RequestsController::class, 'deliver'], ['perm' => 'requests.process', 'idempotent' => true]);

    // --- Approval rules -----------------------------------------------
    $r->get('/api/approval-rules', [RulesController::class, 'index'], ['perm' => 'rules.manage']);
    $r->post('/api/approval-rules', [RulesController::class, 'store'], ['perm' => 'rules.manage']);
    $r->put("/api/approval-rules/{$id}", [RulesController::class, 'update'], ['perm' => 'rules.manage']);
    $r->delete("/api/approval-rules/{$id}", [RulesController::class, 'destroy'], ['perm' => 'rules.manage']);

    // --- Events -------------------------------------------------------
    $r->get('/api/events', [EventsController::class, 'index'], ['perm' => 'events.view']);
    $r->post('/api/events', [EventsController::class, 'store'], ['perm' => 'events.manage']);
    $r->get("/api/events/{$id}", [EventsController::class, 'show'], ['perm' => 'events.view']);
    $r->put("/api/events/{$id}", [EventsController::class, 'update'], ['perm' => 'events.manage']);
    $r->put("/api/events/{$id}/allocations", [EventsController::class, 'allocations'], ['perm' => 'events.manage']);
    $r->post("/api/events/{$id}/open", [EventsController::class, 'open'], ['perm' => 'events.manage']);
    $r->post("/api/events/{$id}/close", [EventsController::class, 'close'], ['perm' => 'events.manage']);
    $r->post("/api/events/{$id}/returns", [EventsController::class, 'returnToCd'], ['perm' => ['events.manage', 'stock.transfer'], 'idempotent' => true]);
    $r->post("/api/events/{$id}/withdrawals", [EventsController::class, 'withdraw'], ['perm' => 'events.withdraw', 'idempotent' => true]);
    $r->get("/api/events/{$id}/balance", [EventsController::class, 'balance'], ['perm' => 'events.view']);

    // --- Protocols ----------------------------------------------------
    $r->get('/api/deliveries', [DeliveriesController::class, 'index'], ['perm' => 'deliveries.view']);
    $r->get("/api/deliveries/{$id}", [DeliveriesController::class, 'show'], ['perm' => 'deliveries.view']);
    $r->get("/api/deliveries/{$id}/pdf", [DeliveriesController::class, 'pdf'], ['perm' => 'deliveries.view']);
    $r->get("/api/deliveries/{$id}/signature", [DeliveriesController::class, 'signature'], ['perm' => 'deliveries.view']);
    $r->post("/api/deliveries/{$id}/resend", [DeliveriesController::class, 'resend'], ['perm' => 'deliveries.view']);

    // --- Notifications / search / reports / import --------------------
    $r->get('/api/notifications', [NotificationsController::class, 'index']);
    $r->post("/api/notifications/{$id}/read", [NotificationsController::class, 'read']);
    $r->post('/api/notifications/read-all', [NotificationsController::class, 'readAll']);
    $r->get('/api/search', [SearchController::class, 'show']);
    $r->get('/api/reports', [ReportsController::class, 'types'], ['perm' => 'reports.view']);
    $r->get('/api/reports/{type}', [ReportsController::class, 'show'], ['perm' => 'reports.view']);
    $r->get('/api/import/template/{type}', [ImportController::class, 'template'], ['perm' => 'import.run']);
    $r->post('/api/import/{type}/preview', [ImportController::class, 'preview'], ['perm' => 'import.run']);
    $r->post('/api/import/{type}/commit', [ImportController::class, 'commit'], ['perm' => 'import.run']);
};
