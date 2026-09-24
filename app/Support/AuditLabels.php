<?php

declare(strict_types=1);

namespace App\Support;

/** PT-BR labels for the audit trail screen. */
final class AuditLabels
{
    public const ACTIONS = [
        'create' => 'Cadastro',
        'update' => 'Alteração',
        'delete' => 'Exclusão (lixeira)',
        'restore' => 'Restauração',
        'purge' => 'Exclusão definitiva',
        'activate' => 'Ativação',
        'deactivate' => 'Inativação',
        'login' => 'Login',
        'logout' => 'Logout',
        'password_change' => 'Troca de senha',
        'password_reset' => 'Redefinição de senha',
        'photo_update' => 'Foto alterada',
        'photo_delete' => 'Foto removida',
        'permissions_update' => 'Permissões alteradas',
        'settings_update' => 'Configurações alteradas',
        'stock_entrada' => 'Entrada de estoque',
        'stock_saida' => 'Saída de estoque',
        'stock_ajuste' => 'Ajuste de estoque',
        'stock_reserve' => 'Reserva de estoque',
        'stock_release' => 'Liberação de reserva',
        'stock_exit_authorize' => 'Saída autorizada',
        'stock_exit_confirm' => 'Saída confirmada no CD',
        'submit' => 'Envio',
        'approve' => 'Aprovação',
        'reject' => 'Reprovação',
        'cancel' => 'Cancelamento',
        'start_picking' => 'Início da separação',
        'ready' => 'Pronta para retirada',
        'deliver' => 'Entrega',
        'open' => 'Abertura',
        'close' => 'Encerramento',
        'withdraw' => 'Retirada',
    ];

    public const ENTITIES = [
        'user' => 'Usuário',
        'role' => 'Perfil',
        'item' => 'Brinde',
        'category' => 'Categoria',
        'department' => 'Departamento',
        'industry' => 'Indústria',
        'location' => 'Local',
        'supplier' => 'Fornecedor',
        'settings' => 'Configurações',
        'request' => 'Solicitação',
        'event' => 'Evento',
        'delivery' => 'Protocolo',
        'approval_rule' => 'Regra de aprovação',
        'stock_exit_order' => 'Saída autorizada',
    ];

    public static function action(string $action): string
    {
        return self::ACTIONS[$action] ?? $action;
    }

    public static function entity(string $entity): string
    {
        return self::ENTITIES[$entity] ?? $entity;
    }
}
