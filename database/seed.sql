-- =====================================================================
--  Controle de Brindes - essential seed v1.0 (run once, after schema.sql)
--  Default login: admin@brindes.local / Trocar@123  (must change on first login)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Roles (perfis)
-- ---------------------------------------------------------------------
INSERT INTO roles (id, slug, name, description) VALUES
  (1, 'admin',      'Administrador',     'Acesso total: configurações, usuários, cadastros, ajustes e auditoria.'),
  (2, 'approver',   'TRADE / Gestor',    'Cria solicitações TRADE, consulta estoque, retiradas e relatórios.'),
  (3, 'operations', 'CD / Estoque',      'Recebimento no CD, saídas, transferências, retiradas e consulta ao estoque.'),
  (4, 'requester',  'TRADE',             'Cria e acompanha solicitações de compra de brindes.'),
  (5, 'industry',   'Indústria',         'Acesso somente às informações da própria indústria parceira.');

-- ---------------------------------------------------------------------
-- Permissions catalog
-- ---------------------------------------------------------------------
INSERT INTO permissions (slug, name, module) VALUES
  ('dashboard.view',            'Ver dashboard',                                'Dashboard'),
  ('items.view',                'Ver brindes',                                  'Brindes'),
  ('items.manage',              'Cadastrar, editar e inativar brindes',         'Brindes'),
  ('items.delete',              'Excluir e restaurar brindes (lixeira)',        'Brindes'),
  ('items.purge',               'Excluir brindes definitivamente',              'Brindes'),
  ('stock.view',                'Ver estoque e movimentações',                  'Estoque'),
  ('stock.entry',               'Registrar entradas',                           'Estoque'),
  ('stock.exit',                'Autorizar saídas',                             'Estoque'),
  ('stock.exit_confirm',        'Confirmar saídas autorizadas no CD',           'Estoque'),
  ('stock.adjust',              'Ajustar estoque manualmente',                  'Estoque'),
  ('stock.transfer',            'Transferir estoque entre locais/eventos',      'Estoque'),
  ('stock.receive',             'Registrar recebimento no CD',                  'Estoque'),
  ('lookups.view',              'Ver cadastros auxiliares',                     'Cadastros'),
  ('lookups.manage',            'Cadastrar, editar e inativar cadastros',       'Cadastros'),
  ('lookups.delete',            'Excluir e restaurar cadastros (lixeira)',      'Cadastros'),
  ('lookups.purge',             'Excluir cadastros definitivamente',            'Cadastros'),
  ('users.view',                'Ver usuários',                                 'Usuários'),
  ('users.manage',              'Cadastrar, editar e inativar usuários',        'Usuários'),
  ('roles.manage',              'Gerenciar perfis e permissões',                'Usuários'),
  ('requests.create',           'Criar solicitações',                           'Solicitações'),
  ('requests.view_own',         'Ver as próprias solicitações',                 'Solicitações'),
  ('requests.view_department',  'Ver solicitações do departamento',             'Solicitações'),
  ('requests.view_all',         'Ver todas as solicitações',                    'Solicitações'),
  ('requests.approve',          'Aprovar e reprovar solicitações',              'Solicitações'),
  ('requests.process',          'Separar e entregar solicitações',              'Solicitações'),
  ('requests.cancel_any',       'Cancelar qualquer solicitação',                'Solicitações'),
  ('events.view',               'Ver eventos',                                  'Eventos'),
  ('events.manage',             'Criar e gerenciar eventos',                    'Eventos'),
  ('events.withdraw',           'Registrar retiradas no Modo Evento',           'Eventos'),
  ('deliveries.view',           'Ver protocolos de entrega',                    'Eventos'),
  ('rules.manage',              'Gerenciar regras de aprovação',                'Solicitações'),
  ('reports.view',              'Ver relatórios',                               'Relatórios'),
  ('reports.export',            'Exportar relatórios (Excel/CSV)',              'Relatórios'),
  ('import.run',                'Importar planilhas',                           'Relatórios'),
  ('alerts.stock',              'Receber alertas de estoque',                   'Alertas'),
  ('audit.view',                'Ver auditoria',                                'Administração'),
  ('settings.manage',           'Gerenciar configurações',                      'Administração');

-- Administrador: everything (the code also treats the admin role as full access)
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 1, id FROM permissions;

-- TRADE / Gestor
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 2, id FROM permissions WHERE slug IN (
    'dashboard.view', 'items.view', 'items.manage', 'stock.view', 'stock.entry', 'stock.exit', 'stock.transfer',
    'lookups.view', 'lookups.manage',
    'requests.create', 'requests.view_own', 'requests.view_department', 'requests.view_all', 'requests.approve',
    'events.view', 'events.manage', 'events.withdraw', 'deliveries.view', 'reports.view', 'reports.export'
  );

-- CD / Estoque
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 3, id FROM permissions WHERE slug IN (
    'dashboard.view', 'items.view', 'items.manage', 'stock.view', 'stock.entry',
    'stock.exit_confirm', 'stock.transfer', 'stock.receive',
    'lookups.view', 'requests.view_own', 'requests.view_all',
    'events.view', 'events.withdraw', 'deliveries.view', 'reports.view', 'reports.export', 'alerts.stock'
  );

-- TRADE (solicitante)
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 4, id FROM permissions WHERE slug IN (
    'dashboard.view', 'items.view', 'lookups.view', 'requests.create', 'requests.view_own'
  );

-- Indústria (somente o que é dela — o recorte é aplicado no backend)
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 5, id FROM permissions WHERE slug IN (
    'dashboard.view', 'items.view', 'stock.view',
    'requests.view_own', 'events.view', 'deliveries.view', 'reports.view', 'reports.export'
  );

-- ---------------------------------------------------------------------
-- First administrator  (password: Trocar@123 - forced change on first login)
-- ---------------------------------------------------------------------
INSERT INTO users (id, name, email, password_hash, role_id, active, must_change_password, session_version)
VALUES (1, 'Administrador', 'admin@brindes.local',
        '$2y$11$2qTBE44e5eaN3EL4LRFT6OZB3t5G44Op01ZfzlekTf/oVB7cMRz7i', 1, 1, 1, 1);

-- ---------------------------------------------------------------------
-- Minimum registers so the first item can be created right away
-- ---------------------------------------------------------------------
INSERT INTO categories (name, description) VALUES ('Geral', 'Categoria padrão');
INSERT INTO locations (name, description, kind) VALUES ('CD Belford Roxo', 'Centro de distribuição — estoque físico principal', 'cd');

-- ---------------------------------------------------------------------
-- Settings (branding and rules - editable in Configurações)
-- ---------------------------------------------------------------------
INSERT INTO settings (`key`, value) VALUES
  ('company_name',      'Controle de Brindes'),
  ('primary_color',     '#2563EB'),
  ('logo_path',         ''),
  ('stalled_days',      '3'),
  ('event_email_mode',  'por_retirada'),
  ('alert_emails',      '');

-- Default approval rules (Gestor/Aprovador = role_id 2)
INSERT INTO approval_rules (name, criterion, operator, value, approver_role_id, priority, active) VALUES
  ('Valor acima de R$ 500', 'valor', '>', '500', 2, 10, 1),
  ('Quantidade acima de 10', 'quantidade', '>', '10', 2, 20, 1);
