<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Core\Db;
use App\Core\Log;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Database installation used by bin/install.php and by the test suite.
 */
final class Installer
{
    /** Creates the database when the DB user is allowed to (local/VPS). */
    public static function createDatabaseIfMissing(): void
    {
        if (Db::isSqlite()) {
            return;
        }
        $c = Config::get('db');
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']),
            $c['user'],
            $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $name = str_replace('`', '', (string) $c['name']);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    /** @return string[] */
    public static function tables(): array
    {
        if (Db::isSqlite()) {
            return Db::query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
        }
        return Db::query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function dropAllTables(): void
    {
        if (Db::isSqlite()) {
            Db::pdo()->exec('PRAGMA foreign_keys = OFF');
            foreach (self::tables() as $table) {
                Db::pdo()->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', $table) . '`');
            }
            Db::pdo()->exec('PRAGMA foreign_keys = ON');
            return;
        }
        Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::tables() as $table) {
            Db::pdo()->exec('DROP TABLE `' . str_replace('`', '', $table) . '`');
        }
        Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Executes every statement of a .sql file. Returns the number of statements. */
    public static function runFile(string $path): int
    {
        if (!is_file($path)) {
            throw new RuntimeException("SQL file not found: {$path}");
        }
        $count = 0;
        $sql = (string) file_get_contents($path);
        if (Db::isSqlite()) {
            $sql = SqliteSchema::convert($sql);
        }
        foreach (self::split($sql) as $statement) {
            Db::pdo()->exec($statement);
            $count++;
        }
        return $count;
    }

    /** Splits SQL into statements, respecting quotes and comments. */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $current .= $ch;
                if ($ch === '\\' && $quote !== '`') {
                    $current .= $next;
                    $i++;
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '-' && $next === '-' && in_array($sql[$i + 2] ?? ' ', [' ', "\t", "\n", "\r"], true)) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $current .= "\n";
                continue;
            }
            if ($ch === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $current .= "\n";
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $current .= $ch;
                continue;
            }
            if ($ch === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        return $statements;
    }

    /**
     * @param callable(string):void $out progress output
     */
    public static function install(bool $fresh, callable $out): void
    {
        try {
            self::createDatabaseIfMissing();
            $out('Banco de dados: ' . Config::get('db.name') . ' (ok)');
        } catch (\Throwable $e) {
            $out('Aviso: não foi possível criar o banco automaticamente (' . $e->getMessage() . '). Usando o banco existente.');
        }
        Db::disconnect();

        if ($fresh) {
            self::dropAllTables();
            $out('Tabelas anteriores removidas (--fresh).');
        }
        if (in_array('users', self::tables(), true)) {
            $out('O banco já está instalado. Aplicando atualizações pendentes…');
            self::upgrade($out);
            return;
        }
        $n = self::runFile(BASE_PATH . '/database/schema.sql');
        $out("schema.sql aplicado ({$n} comandos).");
        $n = self::runFile(BASE_PATH . '/database/seed.sql');
        $out("seed.sql aplicado ({$n} comandos).");
    }

    /** Applies TRADE v1.1 columns/roles when the database was installed before this release. */
    public static function upgrade(callable $out): void
    {
        if (!in_array('users', self::tables(), true)) {
            return;
        }
        $has = Db::hasColumn('requests', 'flow');
        if (!$has) {
            $n = self::runFile(BASE_PATH . '/database/migrate_trade.sql');
            $out("migrate_trade.sql aplicado ({$n} comandos).");
        }
        if (!Db::hasColumn('requests', 'invoice_path')) {
            if (Db::isSqlite()) {
                Db::pdo()->exec('ALTER TABLE requests ADD COLUMN invoice_path TEXT NULL');
            } else {
                Db::pdo()->exec('ALTER TABLE requests ADD COLUMN invoice_path VARCHAR(255) NULL AFTER invoice_no');
            }
            $out('Coluna requests.invoice_path adicionada (anexo da NF no CD).');
        }
        self::grantGestorEventAccess();
        self::ensureStockMovementAttachment();
        self::ensureDeliverySignaturePng();
        self::ensureExitOrders();
        self::restrictCdOperations();
        $out('Perfil TRADE/Gestor atualizado: eventos, cadastros e retiradas.');
    }

    /**
     * Vercel demo: the bundled SQLite may be missing and Blob can hydrate an
     * empty file. PDO still opens (SELECT 1 works) but login then 500s because
     * users / login_attempts do not exist. Rebuild schema + demo accounts.
     */
    public static function ensureVercelDemo(): void
    {
        $noop = static function (string $_line): void {
        };
        $hasUsers = false;
        try {
            $hasUsers = in_array('users', self::tables(), true);
        } catch (Throwable) {
            $hasUsers = false;
        }

        if (!$hasUsers) {
            self::install(true, $noop);
        } else {
            self::upgrade($noop);
        }

        self::ensureDemoAccounts();

        $itemCount = 0;
        try {
            $itemCount = (int) Db::value('SELECT COUNT(*) FROM items');
        } catch (Throwable) {
            $itemCount = 0;
        }
        if ($itemCount === 0) {
            try {
                DemoSeeder::run($noop);
            } catch (Throwable $e) {
                Log::error('Demo seeder failed after schema install', ['error' => $e->getMessage()]);
            }
        }
    }

    /** Demo logins used on the public Vercel walkthrough (password Demo@123). */
    public static function ensureDemoAccounts(): void
    {
        if (!in_array('users', self::tables(), true)) {
            return;
        }
        $admin = Db::fetch("SELECT id, must_change_password FROM users WHERE email = 'admin@brindes.local' AND deleted_at IS NULL");
        $needHash = $admin !== null && (int) ($admin['must_change_password'] ?? 0) === 1;
        $accounts = [
            ['Gestor Comercial', 'gestor@brindes.local', 2],
            ['Operação CD', 'operacao@brindes.local', 3],
            ['Solicitante Trade', 'solicitante@brindes.local', 4],
        ];
        foreach ($accounts as [$name, $email, $roleId]) {
            if (Db::fetch('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL', [$email]) === null) {
                $needHash = true;
                break;
            }
        }
        if (Db::fetch("SELECT id FROM users WHERE email = 'industria@brindes.local' AND deleted_at IS NULL") === null) {
            $needHash = true;
        }
        if (!$needHash) {
            return;
        }

        $hash = password_hash(DemoSeeder::PASSWORD, PASSWORD_DEFAULT);
        if ($admin !== null && (int) ($admin['must_change_password'] ?? 0) === 1) {
            Db::update('users', [
                'password_hash' => $hash,
                'must_change_password' => 0,
                'active' => 1,
            ], ['id' => (int) $admin['id']]);
        }

        foreach ($accounts as [$name, $email, $roleId]) {
            $exists = Db::fetch('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL', [$email]);
            if ($exists !== null) {
                continue;
            }
            Db::insert('users', [
                'name' => $name,
                'email' => $email,
                'password_hash' => $hash,
                'role_id' => $roleId,
                'active' => 1,
                'must_change_password' => 0,
                'session_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $industryEmail = 'industria@brindes.local';
        if (Db::fetch('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL', [$industryEmail]) === null) {
            $industryId = (int) (Db::value('SELECT id FROM industries ORDER BY id LIMIT 1') ?? 0);
            if ($industryId === 0 && in_array('industries', self::tables(), true)) {
                $industryId = Db::insert('industries', [
                    'name' => 'Indústria Alfa',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            if ($industryId > 0) {
                Db::insert('users', [
                    'name' => 'Portal Indústria Alfa',
                    'email' => $industryEmail,
                    'password_hash' => $hash,
                    'role_id' => 5,
                    'industry_id' => $industryId,
                    'active' => 1,
                    'must_change_password' => 0,
                    'session_version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public static function ensureDeliverySignaturePng(): void
    {
        if (Db::hasColumn('deliveries', 'signature_png')) {
            return;
        }
        if (Db::isSqlite()) {
            Db::pdo()->exec('ALTER TABLE deliveries ADD COLUMN signature_png TEXT NULL');
        } else {
            Db::pdo()->exec('ALTER TABLE deliveries ADD COLUMN signature_png MEDIUMTEXT NULL AFTER signature_path');
        }
    }

    /** CD/Estoque: no nova solicitação, no cadastro menu, no entregas internas. */
    public static function restrictCdOperations(): void
    {
        $roleIds = Db::query(
            "SELECT id FROM roles WHERE slug = 'operations' OR LOWER(name) LIKE '%cd / estoque%'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        if ($roleIds === []) {
            $roleIds = [3];
        }
        $confirmId = self::ensurePermission(
            'stock.exit_confirm',
            'Confirmar saídas autorizadas no CD',
            'Estoque'
        );
        foreach ($roleIds as $roleId) {
            foreach (['requests.create', 'requests.process', 'stock.exit'] as $slug) {
                $pid = Db::value('SELECT id FROM permissions WHERE slug = ?', [$slug]);
                if (!$pid) {
                    continue;
                }
                Db::query(
                    'DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?',
                    [(int) $roleId, (int) $pid]
                );
            }
            $has = Db::fetch(
                'SELECT role_id FROM role_permissions WHERE role_id = ? AND permission_id = ?',
                [(int) $roleId, $confirmId]
            );
            if ($has === null) {
                Db::insert('role_permissions', [
                    'role_id' => (int) $roleId,
                    'permission_id' => $confirmId,
                ]);
            }
        }
    }

    public static function ensureExitOrders(): void
    {
        if (in_array('stock_exit_orders', self::tables(), true)) {
            self::ensurePermission('stock.exit_confirm', 'Confirmar saídas autorizadas no CD', 'Estoque');
            return;
        }
        if (Db::isSqlite()) {
            Db::pdo()->exec(
                'CREATE TABLE stock_exit_orders (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    code TEXT NOT NULL UNIQUE,
                    item_id INTEGER NOT NULL,
                    qty INTEGER NOT NULL,
                    purpose TEXT NOT NULL,
                    recipient TEXT NULL,
                    industry_id INTEGER NULL,
                    department_id INTEGER NULL,
                    requester_id INTEGER NULL,
                    document_ref TEXT NULL,
                    notes TEXT NULL,
                    attachment_path TEXT NULL,
                    status TEXT NOT NULL DEFAULT \'autorizada\',
                    authorized_by INTEGER NOT NULL,
                    authorized_at TEXT NOT NULL,
                    confirmed_by INTEGER NULL,
                    confirmed_at TEXT NULL,
                    movement_id INTEGER NULL,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )'
            );
        } else {
            Db::pdo()->exec(
                "CREATE TABLE stock_exit_orders (
                  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  code VARCHAR(20) NOT NULL,
                  item_id INT UNSIGNED NOT NULL,
                  qty INT NOT NULL,
                  purpose VARCHAR(255) NOT NULL,
                  recipient VARCHAR(150) NULL,
                  industry_id INT UNSIGNED NULL,
                  department_id INT UNSIGNED NULL,
                  requester_id INT UNSIGNED NULL,
                  document_ref VARCHAR(60) NULL,
                  notes TEXT NULL,
                  attachment_path VARCHAR(255) NULL,
                  status ENUM('autorizada','confirmada','cancelada') NOT NULL DEFAULT 'autorizada',
                  authorized_by INT UNSIGNED NOT NULL,
                  authorized_at DATETIME NOT NULL,
                  confirmed_by INT UNSIGNED NULL,
                  confirmed_at DATETIME NULL,
                  movement_id BIGINT UNSIGNED NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  UNIQUE KEY uq_stock_exit_orders_code (code),
                  KEY idx_stock_exit_orders_status (status, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        self::ensurePermission('stock.exit_confirm', 'Confirmar saídas autorizadas no CD', 'Estoque');
    }

    public static function ensurePermission(string $slug, string $name, string $module): int
    {
        $id = Db::value('SELECT id FROM permissions WHERE slug = ?', [$slug]);
        if ($id) {
            return (int) $id;
        }
        return Db::insert('permissions', [
            'slug' => $slug,
            'name' => $name,
            'module' => $module,
        ]);
    }

    public static function ensureStockMovementAttachment(): void
    {
        if (Db::hasColumn('stock_movements', 'attachment_path')) {
            return;
        }
        if (Db::isSqlite()) {
            Db::pdo()->exec('ALTER TABLE stock_movements ADD COLUMN attachment_path TEXT NULL');
        } else {
            Db::pdo()->exec('ALTER TABLE stock_movements ADD COLUMN attachment_path VARCHAR(255) NULL AFTER notes');
        }
    }

    /** Gestor can run the event flow and maintain gift/industry catalogs. */
    public static function grantGestorEventAccess(): void
    {
        $slugs = [
            'events.manage', 'events.withdraw', 'items.manage', 'lookups.manage',
            'stock.entry', 'stock.exit', 'stock.transfer',
        ];
        foreach ($slugs as $slug) {
            $pid = Db::value('SELECT id FROM permissions WHERE slug = ?', [$slug]);
            if (!$pid) {
                continue;
            }
            $exists = Db::fetch(
                'SELECT role_id FROM role_permissions WHERE role_id = 2 AND permission_id = ?',
                [$pid]
            );
            if ($exists === null) {
                Db::insert('role_permissions', [
                    'role_id' => 2,
                    'permission_id' => (int) $pid,
                ]);
            }
        }
    }
}
