<?php
$status = (int) ($app['error']['status'] ?? 500);
$msg = $app['error']['message'] ?? 'Erro inesperado.';
ob_start(); ?>
<div class="card card-body mx-auto" style="max-width:480px">
  <h1 class="h4">Erro <?= (int) $status ?></h1>
  <p><?= e($msg) ?></p>
  <a class="btn btn-primary" href="<?= e(url('/')) ?>">Voltar ao início</a>
</div>
<?php
$content = ob_get_clean();
if (!empty($app['user'])) {
    include BASE_PATH . '/resources/views/layouts/app.php';
} else {
    include BASE_PATH . '/resources/views/layouts/auth.php';
}
