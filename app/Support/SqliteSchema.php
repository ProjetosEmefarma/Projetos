<?php

declare(strict_types=1);

namespace App\Support;

/** Converts the MySQL schema/seed dialect into SQLite for the Vercel demo. */
final class SqliteSchema
{
    public static function convert(string $sql): string
    {
        $sql = preg_replace('/^\s*SET\s+.+$/mi', '', $sql) ?? $sql;
        $sql = preg_replace('/UNSIGNED/i', '', $sql) ?? $sql;
        $sql = preg_replace('/\s+CHARACTER SET\s+\w+(\s+COLLATE\s+\w+)?/i', '', $sql) ?? $sql;
        $sql = preg_replace('/\s+COLLATE\s+\w+/i', '', $sql) ?? $sql;
        $sql = preg_replace('/\s+ON UPDATE CURRENT_TIMESTAMP/i', '', $sql) ?? $sql;
        $sql = preg_replace('/\s+AUTO_INCREMENT/i', '', $sql) ?? $sql;
        $sql = preg_replace('/\s+ENGINE=InnoDB\s+DEFAULT CHARSET=\w+(\s+COLLATE=\w+)?/i', '', $sql) ?? $sql;
        $sql = preg_replace('/ENUM\s*\([^)]+\)/i', 'TEXT', $sql) ?? $sql;
        $sql = preg_replace('/TINYINT\s*\(\s*1\s*\)/i', 'INTEGER', $sql) ?? $sql;
        $sql = preg_replace('/\bBIGINT\b/i', 'INTEGER', $sql) ?? $sql;
        $sql = preg_replace('/\bINT\b/i', 'INTEGER', $sql) ?? $sql;
        $sql = preg_replace('/\bDECIMAL\s*\(\s*\d+\s*,\s*\d+\s*\)/i', 'NUMERIC', $sql) ?? $sql;
        $sql = preg_replace('/\bDOUBLE(\s+PRECISION)?\b/i', 'REAL', $sql) ?? $sql;
        $sql = preg_replace('/\bFLOAT\b/i', 'REAL', $sql) ?? $sql;
        $sql = preg_replace('/\bDATETIME\b/i', 'TEXT', $sql) ?? $sql;
        $sql = preg_replace('/\bTIMESTAMP\b/i', 'TEXT', $sql) ?? $sql;
        $sql = preg_replace('/\bMEDIUMTEXT\b/i', 'TEXT', $sql) ?? $sql;
        $sql = preg_replace('/\bLONGTEXT\b/i', 'TEXT', $sql) ?? $sql;
        $sql = preg_replace('/\bLONGBLOB\b/i', 'BLOB', $sql) ?? $sql;
        $sql = preg_replace('/\bJSON\b/i', 'TEXT', $sql) ?? $sql;
        $sql = preg_replace('/UNIQUE KEY\s+[`\w]+\s+/i', 'UNIQUE ', $sql) ?? $sql;
        $sql = preg_replace('/,\s*KEY\s+[`\w]+\s+\([^)]+\)/i', '', $sql) ?? $sql;
        $sql = preg_replace('/,\s*INDEX\s+[`\w]+\s+\([^)]+\)/i', '', $sql) ?? $sql;
        $sql = preg_replace('/,\s*CONSTRAINT\s+[`\w]+\s+FOREIGN KEY/i', ', FOREIGN KEY', $sql) ?? $sql;
        return trim($sql);
    }
}
