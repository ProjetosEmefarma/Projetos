<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Auth;
use App\Core\Db;
use Throwable;
use App\Services\DeliveryService;
use App\Services\EventService;
use App\Services\ItemService;
use App\Services\RequestService;
use App\Services\StockService;
use App\Services\TradeService;

/**
 * Demo / homologation data. Goes through the real services, so every movement,
 * balance and audit row is consistent. All demo users use the password Demo@123.
 * NEVER run in production.
 */
final class DemoSeeder
{
    public const PASSWORD = 'Demo@123';

    public static function run(callable $out): void
    {
        $admin = Auth::loadUser(1);
        Auth::actingAs($admin);
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        mt_srand(42);

        try {
            if ((int) Db::value('SELECT COUNT(*) FROM items') > 0) {
                $out('Dados de demonstração já existem.');
                return;
            }
        } catch (Throwable) {
        }

        // The seeded admin gets the demo password and no forced change.
        Db::update('users', ['password_hash' => $hash, 'must_change_password' => 0], ['id' => 1]);

        $departments = self::names('departments', ['Comercial', 'Marketing', 'Trade Marketing', 'Eventos', 'Diretoria']);
        $categories = self::names('categories', ['Eletrônicos', 'Eletrodomésticos', 'Vestuário', 'Papelaria', 'Utilidades', 'Kits']);
        $locations = self::names('locations', ['Escritório - Almoxarifado'], [
            'kind' => 'outro',
            'description' => 'Escritório / almoxarifado',
        ]);
        $locations['CD Belford Roxo'] = (int) Db::value("SELECT id FROM locations WHERE kind = 'cd' ORDER BY id LIMIT 1");
        $locations['CD - Centro de Distribuição'] = $locations['CD Belford Roxo'];

        $suppliers = [];
        foreach (['Fornecedor Exemplo Ltda' => 'compras@fornecedor.example', 'Brindes & Cia' => 'vendas@brindesecia.example'] as $name => $email) {
            $suppliers[$name] = Db::insert('suppliers', ['name' => $name, 'contact_email' => $email, 'created_at' => now(), 'updated_at' => now()]);
        }
        $industries = [];
        foreach (['Indústria Alfa', 'Indústria Beta', 'Indústria Gama', 'Indústria Delta'] as $name) {
            $slug = strtolower(substr(strrchr($name, ' '), 1));
            $industries[$name] = Db::insert('industries', [
                'name' => $name,
                'contact_name' => 'Contato ' . ucfirst($slug),
                'contact_email' => "contato@{$slug}.example",
                'contact_phone' => '(11) 90000-000' . count($industries),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $out('Cadastros auxiliares criados.');

        foreach ([
            ['Gestor Comercial', 'gestor@brindes.local', 2, 'Comercial'],
            ['Operação CD', 'operacao@brindes.local', 3, 'Eventos'],
            ['Solicitante Trade', 'solicitante@brindes.local', 4, 'Trade Marketing'],
        ] as [$name, $email, $role, $dept]) {
            $existing = Db::fetch('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL', [$email]);
            if ($existing !== null) {
                Db::update('users', [
                    'password_hash' => $hash,
                    'must_change_password' => 0,
                    'active' => 1,
                    'role_id' => $role,
                    'department_id' => $departments[$dept],
                ], ['id' => (int) $existing['id']]);
                continue;
            }
            Db::insert('users', [
                'name' => $name, 'email' => $email, 'password_hash' => $hash, 'role_id' => $role,
                'department_id' => $departments[$dept], 'active' => 1, 'must_change_password' => 0,
                'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $industryExisting = Db::fetch("SELECT id FROM users WHERE email = 'industria@brindes.local' AND deleted_at IS NULL");
        if ($industryExisting === null) {
            Db::insert('users', [
                'name' => 'Portal Indústria Alfa', 'email' => 'industria@brindes.local', 'password_hash' => $hash,
                'role_id' => 5, 'industry_id' => $industries['Indústria Alfa'], 'active' => 1, 'must_change_password' => 0,
                'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $out('Usuários de demonstração criados (senha: ' . self::PASSWORD . ').');

        // Items with an opening balance 25 days ago
        Clock::set(date('Y-m-d 09:00:00', strtotime('-25 days')));
        $items = [];
        foreach ([
            ['Air Fryer 4L', 'Eletrodomésticos', 389.90, 5, 30],
            ['Smart TV 43"', 'Eletrônicos', 1899.00, 2, 8],
            ['Smartphone 128GB', 'Eletrônicos', 1299.00, 3, 10],
            ['Fone de Ouvido Bluetooth', 'Eletrônicos', 149.90, 10, 60],
            ['Caixa de Som Bluetooth', 'Eletrônicos', 219.90, 8, 25],
            ['Garrafa Térmica 500ml', 'Utilidades', 39.90, 30, 200],
            ['Camiseta Evento', 'Vestuário', 24.90, 50, 300],
            ['Boné Bordado', 'Vestuário', 29.90, 40, 120],
            ['Caderno Personalizado', 'Papelaria', 18.50, 50, 250],
            ['Kit Churrasco', 'Kits', 159.00, 5, 12],
            ['Mochila Executiva', 'Utilidades', 129.00, 10, 15],
            ['Squeeze 600ml', 'Utilidades', 14.90, 100, 400],
        ] as [$name, $category, $value, $min, $initial]) {
            $item = ItemService::create([
                'name' => $name,
                'category_id' => $categories[$category],
                'location_id' => $locations['CD - Centro de Distribuição'],
                'supplier_id' => $suppliers[array_rand($suppliers)],
                'unit_value' => $value,
                'min_stock' => $min,
                'initial_quantity' => $initial,
                'description' => "{$name} para ações e eventos.",
            ]);
            $items[$name] = $item['id'];
        }
        $out(count($items) . ' brindes criados com saldo inicial.');

        // Day by day, in chronological order (deterministic): exits, one replenishment
        // (7 days ago), one inventory adjustment (3 days ago), and yesterday a campaign
        // that leaves one item low and one at zero so the dashboard shows both alerts.
        $purposes = ['Ação de vendas', 'Evento com cliente', 'Campanha de incentivo', 'Convenção comercial', 'Premiação'];
        $exits = 0;
        for ($daysAgo = 24; $daysAgo >= 1; $daysAgo--) {
            $day = date('Y-m-d', strtotime("-{$daysAgo} days"));
            $hour = 8;
            for ($k = 0, $n = mt_rand(1, 3); $k < $n; $k++) {
                $hour += mt_rand(1, 3);
                Clock::set(sprintf('%s %02d:%02d:00', $day, $hour, mt_rand(0, 59)));
                $name = array_rand($items);
                $available = StockService::snapshot($items[$name])['available'];
                $qty = min($available, mt_rand(1, max(1, (int) ($available / 6))));
                if ($qty < 1) {
                    continue;
                }
                StockService::exit($items[$name], $qty, [
                    'purpose' => $purposes[array_rand($purposes)],
                    'department_id' => $departments[array_rand($departments)],
                    'industry_id' => $industries[array_rand($industries)],
                    'recipient' => 'Cliente ' . mt_rand(100, 999),
                ]);
                $exits++;
            }
            Clock::set("{$day} 18:00:00");
            if ($daysAgo === 7) {
                StockService::entry($items['Squeeze 600ml'], 150, ['purchase_ticket_no' => 'CH-2026-0457', 'document_ref' => 'NF 12345', 'supplier_id' => $suppliers['Brindes & Cia']]);
            }
            if ($daysAgo === 3) {
                $bone = StockService::snapshot($items['Boné Bordado']);
                StockService::adjust($items['Boné Bordado'], 'delta', -min(2, $bone['on_hand']), 'Contagem mensal no CD: 2 unidades avariadas');
            }
            if ($daysAgo === 1) {
                foreach (['Smart TV 43"' => 1, 'Kit Churrasco' => 0] as $name => $keep) {
                    $available = StockService::snapshot($items[$name])['available'];
                    if ($available > $keep) {
                        StockService::exit($items[$name], $available - $keep, [
                            'purpose' => 'Premiação de campanha', 'department_id' => $departments['Comercial'],
                            'industry_id' => $industries['Indústria Alfa'], 'recipient' => 'Vencedores da campanha',
                        ]);
                        $exits++;
                    }
                }
            }
        }
        Clock::set(null);
        $out("{$exits} saídas, 1 entrada de reposição e 1 ajuste lançados.");

        $sol = Auth::loadUser((int) Db::value("SELECT id FROM users WHERE email = 'solicitante@brindes.local'"));
        $gestor = Auth::loadUser((int) Db::value("SELECT id FROM users WHERE email = 'gestor@brindes.local'"));
        $opUser = Auth::loadUser((int) Db::value("SELECT id FROM users WHERE email = 'operacao@brindes.local'"));
        $sig = self::signature();

        Auth::actingAs($sol);
        $small = RequestService::create([
            'purpose' => 'Ação de vendas regional',
            'recipient' => 'Cliente demonstração',
            'department_id' => $departments['Trade Marketing'],
            'industry_id' => $industries['Indústria Alfa'],
            'items' => [['item_id' => $items['Squeeze 600ml'], 'qty_requested' => 2]],
        ], true);
        $large = RequestService::create([
            'purpose' => 'Convenção comercial — volume alto',
            'recipient' => 'Equipe de campo',
            'department_id' => $departments['Comercial'],
            'industry_id' => $industries['Indústria Beta'],
            'items' => [['item_id' => $items['Camiseta Evento'], 'qty_requested' => 20]],
        ], true);
        $out('Solicitações de demonstração: ' . $small['code'] . ' (' . $small['status'] . '), ' . $large['code'] . ' (' . $large['status'] . ').');

        Auth::actingAs($sol);
        $trade = TradeService::create([
            'industry_id' => $industries['Indústria Alfa'],
            'purpose' => 'Feirão setembro — Air Fryer',
            'action_type' => 'feirao',
            'recipient' => 'Vendedores',
            'delivery_place' => 'CD Belford Roxo',
            'items' => [['item_id' => $items['Air Fryer 4L'], 'qty_requested' => 10, 'unit_value' => 389.90]],
        ]);
        $out('Solicitação TRADE aguardando aprovação (sem chamado/NF): ' . ($trade['public_code'] ?? $trade['code']));

        Auth::actingAs($gestor);
        $readyCd = TradeService::approveWithTicket((int) $trade['id'], [
            'purchase_ticket_no' => 'CH-DEMO-001',
            'notes' => 'Aprovado para o CD conferir a NF na chegada.',
        ]);
        $out('Solicitação TRADE pronta para recebimento no CD: ' . ($readyCd['public_code'] ?? $readyCd['code']) . ' (chamado CH-DEMO-001)');

        Auth::actingAs($sol);
        $pending = TradeService::create([
            'industry_id' => $industries['Indústria Beta'],
            'purpose' => 'Ação de incentivo — Fone Bluetooth',
            'action_type' => 'campanha',
            'recipient' => 'Equipe regional',
            'delivery_place' => 'CD Belford Roxo',
            'items' => [['item_id' => $items['Fone de Ouvido Bluetooth'], 'qty_requested' => 20, 'unit_value' => 149.90]],
        ]);
        $out('Segunda solicitação TRADE ainda aguardando aprovação: ' . ($pending['public_code'] ?? $pending['code']));

        Auth::actingAs($gestor);
        if ($large['status'] === 'aguardando_aprovacao') {
            RequestService::approve((int) $large['id'], ['justification' => 'Aprovado para a convenção']);
        }

        Auth::actingAs($opUser);
        RequestService::startPicking((int) $small['id']);
        RequestService::markReady((int) $small['id']);
        DeliveryService::deliverRequest((int) $small['id'], [
            'received_by_name' => 'Maria da Silva',
            'received_by_email' => 'contato@alfa.example',
            'received_by_document' => '123.456.789-00',
            'signature' => $sig,
        ]);

        Auth::actingAs($admin);
        $event = EventService::create([
            'name' => 'Convenção 2026',
            'venue' => 'Centro de Convenções',
            'starts_on' => date('Y-m-d'),
            'ends_on' => date('Y-m-d', strtotime('+1 day')),
        ]);
        EventService::saveAllocations((int) $event['id'], [
            ['industry_id' => $industries['Indústria Alfa'], 'item_id' => $items['Squeeze 600ml'], 'qty_allocated' => 15],
            ['industry_id' => $industries['Indústria Alfa'], 'item_id' => $items['Boné Bordado'], 'qty_allocated' => 8],
            ['industry_id' => $industries['Indústria Beta'], 'item_id' => $items['Squeeze 600ml'], 'qty_allocated' => 10],
        ]);
        EventService::open((int) $event['id']);
        Auth::actingAs($opUser);
        EventService::withdraw((int) $event['id'], [
            'industry_id' => $industries['Indústria Alfa'],
            'received_by_name' => 'João Responsável',
            'received_by_email' => 'contato@alfa.example',
            'items' => [['item_id' => $items['Squeeze 600ml'], 'qty' => 5]],
            'signature' => $sig,
        ]);
        $out('Evento Convenção 2026 aberto com 1 retirada de demonstração.');
        Auth::actingAs(null);
    }

    private static function signature(): string
    {
        $img = imagecreatetruecolor(400, 160);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        $ink = imagecolorallocate($img, 20, 20, 20);
        imageline($img, 20, 80, 360, 70, $ink);
        imageline($img, 40, 110, 280, 90, $ink);
        ob_start();
        imagepng($img);
        $bin = (string) ob_get_clean();
        imagedestroy($img);
        return 'data:image/png;base64,' . base64_encode($bin);
    }

    /** @return array<string,int> name => id */
    private static function names(string $table, array $names, array $extra = []): array
    {
        $ids = [];
        foreach ($names as $name) {
            $ids[$name] = Db::insert($table, ['name' => $name, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()] + $extra);
        }
        return $ids;
    }
}
