<?php

declare(strict_types=1);

/**
 * Build the client delivery zip + git bundle.
 *   php bin/pack-delivery.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

$root = dirname(__DIR__);
$workspace = dirname($root);
$stageRoot = $workspace . DIRECTORY_SEPARATOR . 'entrega' . DIRECTORY_SEPARATOR . '_stage';
$destName = 'sistema-brindes-v1.1';
$dest = $stageRoot . DIRECTORY_SEPARATOR . $destName;
$outDir = $workspace . DIRECTORY_SEPARATOR . 'entrega' . DIRECTORY_SEPARATOR . 'ENTREGA-FINAL-2026-09-21';
$zipName = 'CONTROLE-BRINDES-v1.1.3-ENTREGA-FINAL.zip';

$skipDirNames = [
    '.git' => true,
    '.vercel' => true,
    '.idea' => true,
    '.vscode' => true,
    'node_modules' => true,
];
$skipStorageLeaves = [
    'uploads' => true,
    'signatures' => true,
    'pdf' => true,
    'backups' => true,
    'logs' => true,
    'cache' => true,
];
$skipFiles = [
    '.env' => true,
    '.env.local' => true,
    '.env.production' => true,
    '.env.testing' => true,
    'Thumbs.db' => true,
    '.DS_Store' => true,
];

$out = static fn (string $l) => fwrite(STDOUT, $l . PHP_EOL);

if (is_dir($stageRoot)) {
    delete_tree($stageRoot);
}
mkdir($dest, 0775, true);

$copied = 0;
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $file) {
    /** @var SplFileInfo $file */
    $rel = substr($file->getPathname(), strlen($root) + 1);
    $rel = str_replace('\\', '/', $rel);
    $parts = explode('/', $rel);
    if (isset($skipDirNames[$parts[0]])) {
        continue;
    }
    if ($parts[0] === 'storage' && isset($parts[1], $skipStorageLeaves[$parts[1]]) && count($parts) > 2) {
        continue;
    }
    if ($file->isDir()) {
        $targetDir = $dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }
        continue;
    }
    $base = $file->getBasename();
    if (isset($skipFiles[$base])) {
        continue;
    }
    $target = $dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $dir = dirname($target);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    copy($file->getPathname(), $target);
    $copied++;
}

foreach (['uploads', 'signatures', 'pdf', 'backups', 'logs', 'cache', 'cache/sessions'] as $leaf) {
    $d = $dest . '/storage/' . $leaf;
    if (!is_dir($d)) {
        mkdir($d, 0775, true);
    }
    file_put_contents($d . '/.gitkeep', '');
}

$out("Arquivos copiados: {$copied}");

$gitDir = $dest;
$runGit = static function (string $args) use ($gitDir): string {
    $cmd = 'git -C ' . escapeshellarg($gitDir) . ' ' . $args . ' 2>&1';
    $lines = [];
    $code = 0;
    exec($cmd, $lines, $code);
    $text = implode("\n", $lines);
    if ($code !== 0) {
        throw new RuntimeException("git failed ({$code}): {$text}\n{$cmd}");
    }
    return $text;
};

$runGit('init');
$runGit('add -A');
$msgFile = $dest . DIRECTORY_SEPARATOR . '.git-commit-message.txt';
file_put_contents($msgFile, "Entrega final v1.1.3 — 2026-09-21\n\nRegistrar saída somente no gestor; CD confirma com QR. Manual completo na pasta documentacao.\n");
$runGit('-c user.name="Entrega Controle de Brindes" -c user.email="entrega@local" commit -F ' . escapeshellarg($msgFile));
@unlink($msgFile);
$runGit('branch -M main');
$log = $runGit('log -1 --oneline');
$out('Git: ' . trim($log));

if (is_dir($outDir)) {
    delete_tree($outDir);
}
mkdir($outDir, 0775, true);
mkdir($outDir . '/git', 0775, true);
mkdir($outDir . '/documentacao', 0775, true);

$bundle = $outDir . '/git/controle-brindes.bundle';
$runGit('bundle create ' . escapeshellarg($bundle) . ' --all');
$out('Bundle: ' . $bundle);

copy($workspace . '/entrega/LEIA-ME-ENTREGA-FINAL.txt', $outDir . '/LEIA-ME.txt');
copy($dest . '/VERSION', $outDir . '/VERSION.txt');

$docSrc = $dest . '/docs';
$docDst = $outDir . '/documentacao';
foreach (scandir($docSrc) ?: [] as $f) {
    if ($f[0] === '.') {
        continue;
    }
    $from = $docSrc . DIRECTORY_SEPARATOR . $f;
    if (is_file($from)) {
        copy($from, $docDst . DIRECTORY_SEPARATOR . $f);
    }
}
$ev = $docSrc . '/evidencias';
if (is_dir($ev)) {
    mkdir($docDst . '/evidencias', 0775, true);
    foreach (scandir($ev) ?: [] as $f) {
        if ($f[0] === '.') {
            continue;
        }
        copy($ev . '/' . $f, $docDst . '/evidencias/' . $f);
    }
}

$tech = $workspace . '/entrega/Documentacao-Tecnica';
if (is_dir($tech)) {
    foreach (scandir($tech) ?: [] as $f) {
        if ($f[0] === '.' || !str_ends_with($f, '.pdf')) {
            continue;
        }
        copy($tech . '/' . $f, $docDst . '/' . $f);
    }
}
$manuais = $workspace . '/entrega/Manuais-e-Apresentacao';
if (is_dir($manuais)) {
    foreach (['Manual-do-Usuario.pdf', 'Manual-de-Instalacao-e-Migracao.pdf', 'Apresentacao-Treinamento.pdf', 'Apresentacao-Treinamento.html'] as $f) {
        if (is_file($manuais . '/' . $f)) {
            copy($manuais . '/' . $f, $docDst . '/' . $f);
        }
    }
}

$zipPath = $outDir . '/' . $zipName;
if (is_file($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    throw new RuntimeException('Não foi possível criar o zip.');
}
$zipRoot = $outDir;
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($zipRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($walk as $file) {
    if (!$file->isFile()) {
        continue;
    }
    if (str_replace('\\', '/', $file->getPathname()) === str_replace('\\', '/', $zipPath)) {
        continue;
    }
    $local = substr($file->getPathname(), strlen($zipRoot) + 1);
    $zip->addFile($file->getPathname(), str_replace('\\', '/', $local));
}

$codeWalk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($codeWalk as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $local = $destName . '/' . substr($file->getPathname(), strlen($dest) + 1);
    $zip->addFile($file->getPathname(), str_replace('\\', '/', $local));
}
$zip->close();

$hash = hash_file('sha256', $zipPath);
file_put_contents(
    $outDir . '/SHA256.txt',
    $hash . "  {$zipName}\n"
);
file_put_contents(
    $workspace . '/entrega/SHA256-v1.1.3.txt',
    $hash . "  {$zipName}\n"
);

copy($zipPath, $workspace . DIRECTORY_SEPARATOR . 'entrega' . DIRECTORY_SEPARATOR . $zipName);
$out('Zip: ' . $zipPath);
$out('Cópia: ' . $workspace . DIRECTORY_SEPARATOR . 'entrega' . DIRECTORY_SEPARATOR . $zipName);
$out('Tamanho: ' . filesize($zipPath) . ' bytes');
$out('SHA256: ' . $hash);

function delete_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dir);
}
