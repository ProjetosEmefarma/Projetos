<?php
/** @var array $app */
$settings = $app['settings'];
$color = e($settings['primary_color'] ?? '#2563EB');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <meta name="csrf-token" content="<?= e($app['csrf']) ?>">
  <meta name="base-url" content="<?= e($app['base_url']) ?>">
  <title><?= e($app['page']['title'] ?? 'Modo Evento') ?></title>
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
  <style>:root { --brand-primary: <?= $color ?>; } body { background:#0f172a; color:#fff; }</style>
</head>
<body class="event-kiosk">
  <?= $content ?? '' ?>
<script src="<?= e(asset('js/api.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?= $scripts ?? '' ?>
<script src="<?= e(asset('vendor/alpinejs/alpine.min.js')) ?>"></script>
</body>
</html>
