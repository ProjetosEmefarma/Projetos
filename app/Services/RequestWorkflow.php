<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;

/** Status machine for day-to-day requests. */
final class RequestWorkflow
{
    public const LABELS = [
        'rascunho' => 'Rascunho',
        'solicitada' => 'Solicitada',
        'aguardando_aprovacao' => 'Aguardando aprovação',
        'aprovada' => 'Aprovada',
        'em_separacao' => 'Em separação',
        'pronta' => 'Pronto para retirada',
        'finalizada' => 'Entregue / Finalizada',
        'reprovada' => 'Reprovada',
        'cancelada' => 'Cancelada',
        'compra_realizada' => 'Compra realizada',
        'aguardando_recebimento' => 'Aguardando recebimento',
        'recebido_cd' => 'Recebido no CD',
        'retirado' => 'Retirado',
        'entregue' => 'Entregue',
    ];

    public const OPEN = [
        'solicitada', 'aguardando_aprovacao', 'aprovada', 'em_separacao', 'pronta',
        'compra_realizada', 'aguardando_recebimento', 'recebido_cd',
    ];

    public const TRADE_OPEN = ['solicitada', 'compra_realizada', 'aguardando_recebimento', 'recebido_cd', 'pronta', 'retirado'];

    /** from => [to, ...] */
    private const TRANSITIONS = [
        'rascunho' => ['solicitada', 'aguardando_aprovacao', 'aprovada', 'cancelada'],
        'solicitada' => ['aguardando_aprovacao', 'aprovada', 'compra_realizada', 'aguardando_recebimento', 'reprovada', 'cancelada'],
        'aguardando_aprovacao' => ['aprovada', 'reprovada', 'cancelada'],
        'aprovada' => ['em_separacao', 'cancelada'],
        'em_separacao' => ['pronta', 'cancelada'],
        'pronta' => ['finalizada', 'retirado', 'cancelada'],
        'compra_realizada' => ['aguardando_recebimento', 'cancelada'],
        'aguardando_recebimento' => ['recebido_cd', 'pronta', 'cancelada'],
        'recebido_cd' => ['pronta', 'cancelada'],
        'retirado' => ['entregue', 'finalizada'],
        'entregue' => [],
    ];

    public static function assertTransition(string $from, string $to): void
    {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw HttpException::conflict(
                'INVALID_TRANSITION',
                'Não é possível alterar o status de "' . (self::LABELS[$from] ?? $from) . '" para "' . (self::LABELS[$to] ?? $to) . '".'
            );
        }
    }

    public static function apply(int $requestId, string $to, ?string $comment = null): void
    {
        $row = Db::fetch('SELECT id, status FROM requests WHERE id = ? FOR UPDATE', [$requestId]);
        if ($row === null) {
            throw HttpException::notFound('Solicitação não encontrada.');
        }
        $from = (string) $row['status'];
        self::assertTransition($from, $to);
        $now = now();
        $extra = ['status' => $to, 'status_changed_at' => $now, 'updated_at' => $now];
        if ($to === 'solicitada' || $to === 'aguardando_aprovacao' || ($to === 'aprovada' && $from === 'rascunho')) {
            $extra['submitted_at'] = $now;
        }
        if ($to === 'aprovada' || $to === 'compra_realizada') {
            $extra['approved_at'] = $now;
        }
        if ($to === 'finalizada') {
            $extra['finalized_at'] = $now;
        }
        if ($to === 'cancelada') {
            $extra['cancelled_at'] = $now;
            if ($comment) {
                $extra['cancel_reason'] = mb_substr($comment, 0, 255);
            }
        }
        if ($to === 'reprovada' && $comment) {
            $extra['cancel_reason'] = mb_substr($comment, 0, 255);
        }
        Db::update('requests', $extra, ['id' => $requestId]);
        Db::insert('request_status_history', [
            'request_id' => $requestId,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => Auth::id(),
            'comment' => $comment,
            'created_at' => $now,
        ]);
        Audit::log($to === 'aprovada' ? 'approve' : ($to === 'reprovada' ? 'reject' : ($to === 'cancelada' ? 'cancel' : ($to === 'finalizada' ? 'deliver' : 'update'))),
            'request', $requestId, ['status' => $from], ['status' => $to], null);
    }

    public static function nextAction(array $request): ?array
    {
        $status = $request['status'] ?? '';
        $flow = $request['flow'] ?? 'interna';
        if ($flow === 'trade') {
            $map = [
                'solicitada' => Auth::can('requests.approve') ? ['key' => 'approve_ticket', 'label' => 'Aprovar e informar o chamado'] : null,
                'compra_realizada' => Auth::can(['stock.receive', 'stock.entry']) ? ['key' => 'receive', 'label' => 'Registrar chegada no CD + anexar NF'] : null,
                'aguardando_recebimento' => Auth::can(['stock.receive', 'stock.entry']) ? ['key' => 'receive', 'label' => 'Registrar chegada no CD + anexar NF'] : null,
                'recebido_cd' => Auth::can('requests.process') ? ['key' => 'ready', 'label' => 'Marcar pronto para retirada'] : null,
                'pronta' => Auth::can(['requests.process', 'stock.exit']) ? ['key' => 'withdraw', 'label' => 'Registrar retirada'] : null,
                'retirado' => Auth::can('requests.process') ? ['key' => 'delivered', 'label' => 'Marcar como entregue'] : null,
                'entregue' => ['key' => 'protocol', 'label' => 'Ver comprovante'],
                'finalizada' => ['key' => 'protocol', 'label' => 'Ver protocolo'],
                'reprovada' => null,
            ];
            return $map[$status] ?? null;
        }
        $map = [
            'aguardando_aprovacao' => Auth::can('requests.approve') ? ['key' => 'approve', 'label' => 'Aprovar / Reprovar'] : null,
            'aprovada' => Auth::can('requests.process') ? ['key' => 'start_picking', 'label' => 'Iniciar separação'] : null,
            'em_separacao' => Auth::can('requests.process') ? ['key' => 'ready', 'label' => 'Marcar como pronta'] : null,
            'pronta' => Auth::can('requests.process') ? ['key' => 'deliver', 'label' => 'Registrar entrega'] : null,
            'finalizada' => ['key' => 'protocol', 'label' => 'Ver protocolo'],
            'rascunho' => ((int) ($request['requester']['id'] ?? $request['requester_id'] ?? 0) === (int) Auth::id() || Auth::isAdmin())
                ? ['key' => 'submit', 'label' => 'Enviar solicitação'] : null,
        ];
        return $map[$status] ?? null;
    }
}
