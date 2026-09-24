<?php

declare(strict_types=1);

/**
 * Export client manuals + training deck to PDF.
 *   php bin/export-docs.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$root = dirname(__DIR__);
$out = dirname($root) . DIRECTORY_SEPARATOR . 'entrega' . DIRECTORY_SEPARATOR . 'Manuais-e-Apresentacao';
if (!is_dir($out)) {
    mkdir($out, 0775, true);
}

function md_to_html(string $md): string
{
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    $html = '';
    $inTable = false;
    $inList = false;
    $inCode = false;
    foreach (explode("\n", $md) as $line) {
        if (str_starts_with($line, '```')) {
            if ($inCode) {
                $html .= '</pre>';
                $inCode = false;
            } else {
                if ($inList) {
                    $html .= '</ul>';
                    $inList = false;
                }
                $html .= '<pre>';
                $inCode = true;
            }
            continue;
        }
        if ($inCode) {
            $html .= htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
            continue;
        }
        if (preg_match('/^\|(.+)\|$/', $line, $m)) {
            $cells = array_map('trim', explode('|', trim($line, '| ')));
            $isSep = count(array_filter($cells, static fn ($c) => preg_match('/^:?-+:?$/', $c))) === count($cells);
            if ($isSep) {
                continue;
            }
            if (!$inTable) {
                if ($inList) {
                    $html .= '</ul>';
                    $inList = false;
                }
                $html .= '<table>';
                $inTable = true;
                $tag = 'th';
            } else {
                $tag = 'td';
            }
            $html .= '<tr>';
            foreach ($cells as $c) {
                $html .= "<{$tag}>" . inline($c) . "</{$tag}>";
            }
            $html .= '</tr>';
            continue;
        }
        if ($inTable) {
            $html .= '</table>';
            $inTable = false;
        }
        if (preg_match('/^### (.+)$/', $line, $m)) {
            if ($inList) {
                $html .= '</ul>';
                $inList = false;
            }
            $html .= '<h3>' . inline($m[1]) . '</h3>';
            continue;
        }
        if (preg_match('/^## (.+)$/', $line, $m)) {
            if ($inList) {
                $html .= '</ul>';
                $inList = false;
            }
            $html .= '<h2>' . inline($m[1]) . '</h2>';
            continue;
        }
        if (preg_match('/^# (.+)$/', $line, $m)) {
            if ($inList) {
                $html .= '</ul>';
                $inList = false;
            }
            $html .= '<h1>' . inline($m[1]) . '</h1>';
            continue;
        }
        if (preg_match('/^- (.+)$/', $line, $m)) {
            if (!$inList) {
                $html .= '<ul>';
                $inList = true;
            }
            $html .= '<li>' . inline($m[1]) . '</li>';
            continue;
        }
        if (preg_match('/^\d+\.\s+(.+)$/', $line, $m)) {
            if ($inList) {
                $html .= '</ul>';
                $inList = false;
            }
            $html .= '<p>' . inline($m[1]) . '</p>';
            continue;
        }
        if (trim($line) === '' || $line === '---') {
            if ($inList) {
                $html .= '</ul>';
                $inList = false;
            }
            continue;
        }
        if ($inList) {
            $html .= '</ul>';
            $inList = false;
        }
        $html .= '<p>' . inline($line) . '</p>';
    }
    if ($inList) {
        $html .= '</ul>';
    }
    if ($inTable) {
        $html .= '</table>';
    }
    if ($inCode) {
        $html .= '</pre>';
    }
    return $html;
}

function inline(string $s): string
{
    $s = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $s = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $s) ?? $s;
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s) ?? $s;
    return $s;
}

function wrap(string $title, string $body): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#1f2937;margin:28px}
h1{font-size:20px;color:#2563EB;margin:0 0 12px}
h2{font-size:15px;color:#1e3a8a;margin:18px 0 8px}
h3{font-size:13px;margin:14px 0 6px}
p,li{line-height:1.45} table{width:100%;border-collapse:collapse;margin:8px 0 14px}
th,td{border:1px solid #e5e7eb;padding:6px 8px;text-align:left;font-size:11px} th{background:#eff6ff}
code,pre{font-size:10px;background:#f8fafc} pre{padding:8px;border:1px solid #e5e7eb}
.muted{color:#64748b;font-size:11px}
</style></head><body>' . $body . '<p class="muted">Controle de Brindes — documento interno</p></body></html>';
}

function pdf(string $html, string $path): void
{
    $opt = new Options();
    $opt->set('isRemoteEnabled', false);
    $opt->set('defaultFont', 'DejaVu Sans');
    $dom = new Dompdf($opt);
    $dom->loadHtml($html, 'UTF-8');
    $dom->setPaper('A4', 'portrait');
    $dom->render();
    file_put_contents($path, $dom->output());
}

$tech = dirname($root) . DIRECTORY_SEPARATOR . 'entrega' . DIRECTORY_SEPARATOR . 'Documentacao-Tecnica';
if (!is_dir($tech)) {
    mkdir($tech, 0775, true);
}

$manuals = [
    'Manual-do-Usuario.md',
    'Manual-de-Instalacao-e-Migracao.md',
    'INDICE.md',
    'Arquitetura-e-Pastas.md',
    'Mapa-de-Modulos.md',
    'Perfis-e-Permissoes.md',
    'Dependencias.md',
    'Backup-e-Restauracao.md',
    'Contas-e-Credenciais.md',
    'Versao-Final.md',
    'database.md',
];
foreach ($manuals as $md) {
    $src = $root . '/docs/' . $md;
    if (!is_file($src)) {
        continue;
    }
    copy($src, $out . '/' . $md);
    copy($src, $tech . '/' . $md);
}

if (is_file($root . '/docs/diagrama-banco.html')) {
    copy($root . '/docs/diagrama-banco.html', $out . '/diagrama-banco.html');
    copy($root . '/docs/diagrama-banco.html', $tech . '/diagrama-banco.html');
}

$deck = str_replace('{{NOME_DA_EMPRESA}}', 'TRADE', (string) file_get_contents($root . '/docs/Apresentacao-Treinamento.html'));
file_put_contents($out . '/Apresentacao-Treinamento.html', $deck);

$pdfTitles = [
    'Manual-do-Usuario.md' => 'Manual do Usuário',
    'Manual-de-Instalacao-e-Migracao.md' => 'Manual de Instalação e Migração',
    'Arquitetura-e-Pastas.md' => 'Arquitetura e estrutura de pastas',
    'Mapa-de-Modulos.md' => 'Mapa dos módulos',
    'Perfis-e-Permissoes.md' => 'Perfis e permissões',
    'Dependencias.md' => 'Dependências',
    'Backup-e-Restauracao.md' => 'Backup e restauração',
    'Contas-e-Credenciais.md' => 'Contas e credenciais',
    'Versao-Final.md' => 'Versão final',
    'database.md' => 'Modelo de dados',
];
foreach ($pdfTitles as $md => $title) {
    $src = $root . '/docs/' . $md;
    if (!is_file($src)) {
        continue;
    }
    $pdfName = preg_replace('/\.md$/', '.pdf', $md);
    $html = wrap($title, md_to_html((string) file_get_contents($src)));
    pdf($html, $out . '/' . $pdfName);
    pdf($html, $tech . '/' . $pdfName);
}

$opt = new Options();
$opt->set('isRemoteEnabled', false);
$opt->set('defaultFont', 'DejaVu Sans');
$dom = new Dompdf($opt);
$printDeck = str_replace(
    ['.slide { width: 960px; min-height: 540px; margin: 24px auto; background: #fff; padding: 56px 64px; border-radius: 8px; page-break-after: always; }', 'body { margin: 0; font-family: Segoe UI, system-ui, sans-serif; background: #0f172a; color: #0f172a; }'],
    ['.slide { width: auto; min-height: 0; margin: 0 0 18px; background: #fff; padding: 24px; border: 1px solid #e5e7eb; page-break-inside: avoid; }', 'body { margin: 16px; font-family: DejaVu Sans, sans-serif; background: #fff; color: #0f172a; }'],
    $deck
);
$dom->loadHtml($printDeck, 'UTF-8');
$dom->setPaper('A4', 'landscape');
$dom->render();
file_put_contents($out . '/Apresentacao-Treinamento.pdf', $dom->output());

echo "OK → {$out}" . PHP_EOL;
foreach (scandir($out) ?: [] as $f) {
    if ($f[0] === '.') {
        continue;
    }
    echo '  ' . $f . ' (' . filesize($out . DIRECTORY_SEPARATOR . $f) . ' bytes)' . PHP_EOL;
}
echo "OK → {$tech}" . PHP_EOL;
