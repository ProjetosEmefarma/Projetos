<?php
/** @var array $app */
$settings = $app['settings'];
$color = e($settings['primary_color'] ?? '#2563EB');
$title = $app['page']['title'] ?? 'Entrar';
$company = e($settings['company_name'] ?? 'Controle de Brindes');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Acesse o sistema de Controle de Brindes — <?= $company ?>">
  <meta name="robots" content="noindex, nofollow">
  <meta name="csrf-token" content="<?= e($app['csrf']) ?>">
  <meta name="base-url" content="<?= e($app['base_url']) ?>">
  <title><?= e($title . ' — ' . ($settings['company_name'] ?? '')) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
  <style>:root { --brand-primary: <?= $color ?>; }</style>
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card fade-in">
    <div class="text-center mb-4">
      <?php if (!empty($settings['logo_url'])): ?>
        <img src="<?= e($settings['logo_url']) ?>" alt="<?= $company ?>" style="max-height:52px; margin-bottom: 12px">
      <?php else: ?>
        <div style="width:52px;height:52px;background:var(--brand-primary);border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px">
          <i class="bi bi-gift" style="font-size:24px;color:#fff"></i>
        </div>
      <?php endif; ?>
      <h1 class="h5 fw-bold mb-0" style="letter-spacing:-0.02em"><?= $company ?></h1>
      <p class="text-muted small mt-1 mb-0">Sistema de Controle de Brindes</p>
    </div>
    <?= $content ?? '' ?>
  </div>
</div>
<script src="<?= e(asset('js/api.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?= $scripts ?? '' ?>
<script src="<?= e(asset('vendor/alpinejs/alpine.min.js')) ?>"></script>
</body>
</html>

