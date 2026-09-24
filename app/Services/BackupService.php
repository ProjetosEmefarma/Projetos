<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use Ifsnop\Mysqldump\Mysqldump;

final class BackupService
{
    public static function create(): string
    {
        $c = Config::get('db');
        $name = 'backup-' . date('Ymd-His') . '.sql';
        $dir = Config::get('paths.storage') . '/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . $name;
        $dump = new Mysqldump(
            sprintf('mysql:host=%s;port=%d;dbname=%s', $c['host'], $c['port'], $c['name']),
            $c['user'],
            $c['pass'],
            ['add-drop-table' => true, 'default-character-set' => Mysqldump::UTF8MB4]
        );
        $dump->start($path);
        self::prune(14);
        return $path;
    }

    public static function prune(int $keep): void
    {
        $dir = Config::get('paths.storage') . '/backups';
        $files = glob($dir . '/backup-*.sql') ?: [];
        rsort($files);
        foreach (array_slice($files, $keep) as $f) {
            @unlink($f);
        }
    }

    /**
     * Import a .sql dump into the database configured in .env.
     * The application dump uses DROP TABLE, so existing tables are replaced.
     */
    public static function restore(string $sqlPath): int
    {
        if (!is_file($sqlPath) || !is_readable($sqlPath)) {
            throw new \RuntimeException('Arquivo de backup não encontrado: ' . $sqlPath);
        }
        $sql = (string) file_get_contents($sqlPath);
        if (trim($sql) === '') {
            throw new \RuntimeException('O arquivo de backup está vazio.');
        }

        $pdo = new \PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Config::get('db.host'),
                (int) Config::get('db.port'),
                Config::get('db.name')
            ),
            (string) Config::get('db.user'),
            (string) Config::get('db.pass'),
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ]
        );
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('SET NAMES utf8mb4');
        $count = 0;
        foreach (self::splitStatements($sql) as $statement) {
            $pdo->exec($statement);
            $count++;
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        return $count;
    }

    /** @return list<string> */
    public static function splitStatements(string $sql): array
    {
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $i = 0;
        $inString = false;
        $quote = '';
        while ($i < $len) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';
            if (!$inString && $ch === '-' && $next === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if (!$inString && $ch === '/' && $next === '*') {
                $i += 2;
                while ($i + 1 < $len && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i += 2;
                continue;
            }
            if ($inString) {
                $buf .= $ch;
                if ($ch === '\\' && $next !== '') {
                    $buf .= $next;
                    $i += 2;
                    continue;
                }
                if ($ch === $quote) {
                    $inString = false;
                }
                $i++;
                continue;
            }
            if ($ch === '\'' || $ch === '"' || $ch === '`') {
                $inString = true;
                $quote = $ch;
                $buf .= $ch;
                $i++;
                continue;
            }
            if ($ch === ';') {
                $stmt = trim($buf);
                if ($stmt !== '') {
                    $out[] = $stmt;
                }
                $buf = '';
                $i++;
                continue;
            }
            $buf .= $ch;
            $i++;
        }
        $stmt = trim($buf);
        if ($stmt !== '') {
            $out[] = $stmt;
        }
        return $out;
    }
}
