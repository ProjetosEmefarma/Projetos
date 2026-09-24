<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use Dompdf\Dompdf;
use Dompdf\Options;
use Throwable;

final class PdfService
{
    public static function generate(int $deliveryId): string
    {
        $d = DeliveryService::find($deliveryId);
        $brand = SettingsService::branding();
        $logoHtml = '';
        $logoPath = SettingsService::get('logo_path');
        if ($logoPath && is_file(ImageService::absolute($logoPath))) {
            $logoHtml = '<img src="' . self::dataUri(ImageService::absolute($logoPath), 'image/png') . '" style="height:42px">';
        }
        $sigHtml = '';
        $sigRow = Db::fetch('SELECT signature_path, signature_png FROM deliveries WHERE id = ?', [$deliveryId]);
        $sigBin = '';
        if (!empty($sigRow['signature_png'])) {
            $decoded = base64_decode((string) $sigRow['signature_png'], true);
            if (is_string($decoded) && $decoded !== '') {
                $sigBin = $decoded;
            }
        }
        if ($sigBin === '' && !empty($sigRow['signature_path']) && is_file(ImageService::absolute((string) $sigRow['signature_path']))) {
            $sigBin = (string) file_get_contents(ImageService::absolute((string) $sigRow['signature_path']));
        }
        if ($sigBin !== '') {
            $sigBin = ImageService::flattenOnWhite($sigBin);
            $sigHtml = '<img src="data:image/png;base64,' . base64_encode($sigBin) . '" style="height:72px;background:#fff">';
        }
        $rows = '';
        $totalQty = 0;
        foreach ($d['items'] as $it) {
            $qty = (int) ($it['qty_withdrawn'] ?? $it['qty'] ?? 0);
            $restante = (int) ($it['remaining'] ?? $it['balance_after'] ?? 0);
            $totalQty += $qty;
            $rows .= '<tr><td>' . e($it['item']['code']) . '</td><td>' . e($it['item']['name']) . '</td>'
                . '<td style="text-align:right">' . $qty . '</td>'
                . '<td style="text-align:right">' . $restante . '</td></tr>';
        }
        $qrHtml = '';
        try {
            $qrHtml = '<img src="' . QrService::pngDataUri($d['code'], 4) . '" width="88" height="88">';
        } catch (Throwable) {
            $qrHtml = '';
        }
        $hash = hash('sha256', $d['code'] . '|' . $d['created_at'] . '|' . json_encode($d['items']));
        $color = e($brand['primary_color']);
        $company = e($brand['company_name']);
        $industryName = e($d['industry']['name'] ?? '—');
        $doc = e($d['received_by_document'] ?? '—');
        $mail = e($d['received_by_email'] ?? '—');
        $recv = e($d['received_by_name']);
        $deliv = e($d['delivered_by']['name'] ?? '');
        $ref = '';
        if (!empty($d['event']['name'])) {
            $ref = '<br><b>Evento:</b> ' . e($d['event']['name']);
        } elseif (!empty($d['request']['code'])) {
            $ref = '<br><b>Solicitação:</b> ' . e($d['request']['code']);
        }
        $code = e($d['code']);
        $when = e($d['type_label'] . ' · ' . $d['created_at']);
        $html = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#1f2937;margin:24px}
h1{font-size:18px;margin:0 0 4px} table{width:100%;border-collapse:collapse}
th,td{border-bottom:1px solid #e5e7eb;padding:6px 4px;text-align:left} th{background:#f3f4f6;font-size:11px}
.hdr td{border:0;color:#fff;padding:12px 8px;vertical-align:middle}
.muted{color:#6b7280;font-size:11px}
.box{border:1px solid #e5e7eb;padding:10px;margin-top:12px}
.sig img{height:80px}
</style></head><body>
<table class="hdr" width="100%" style="background:{$color}">
<tr>
  <td>{$logoHtml}</td>
  <td style="text-align:right"><div>{$company}</div><div style="font-size:11px;opacity:.85">COMPROVANTE DE RETIRADA</div></td>
</tr>
</table>
<table style="margin-top:0">
<tr>
  <td style="border:0;vertical-align:top"><h1>{$code}</h1><p class="muted">{$when}</p></td>
  <td style="border:0;width:100px;text-align:right;vertical-align:top">{$qrHtml}</td>
</tr>
</table>
<table>
<tr><th>Código</th><th>Brinde</th><th style="text-align:right">Quantidade retirada</th><th style="text-align:right">Saldo que ainda tem</th></tr>
{$rows}
<tr><td colspan="2"><b>Total nesta retirada</b></td><td style="text-align:right"><b>{$totalQty}</b></td><td></td></tr>
</table>
<p class="muted">Quantidade retirada = o que saiu nesta retirada. Saldo que ainda tem = o que resta desta cota/solicitação depois desta retirada.</p>
<div class="box">
<p><b>Retirado por:</b> {$recv}<br>
<b>Documento:</b> {$doc} · <b>E-mail:</b> {$mail}<br>
<b>Entregue por:</b> {$deliv}<br>
<b>Indústria:</b> {$industryName}{$ref}</p>
<p class="sig"><b>Assinatura</b><br>{$sigHtml}</p>
</div>
<p class="muted">Código de verificação: {$hash}</p>
</body></html>
HTML;

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->addInfo('Title', 'Protocolo ' . $d['code']);
        $dompdf->addInfo('Creator', 'Controle de Brindes');
        $dompdf->addInfo('Author', '');
        $dompdf->render();
        $relative = 'pdf/' . $d['code'] . '.pdf';
        $abs = ImageService::absolute($relative);
        $dir = dirname($abs);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($abs, $dompdf->output());
        Db::update('deliveries', ['pdf_path' => $relative, 'verification_hash' => $hash], ['id' => $deliveryId]);
        return $relative;
    }

    private static function dataUri(string $path, string $mime): string
    {
        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    }
}
