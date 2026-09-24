-- =====================================================================
-- TRADE upgrade (v1.1) — run only when requests.flow does not exist.
-- Fresh installs already have these changes in schema.sql + seed.sql.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE locations
  ADD COLUMN kind ENUM('cd','evento','outro') NOT NULL DEFAULT 'cd' AFTER description;

UPDATE locations SET name = 'CD Belford Roxo', description = 'Centro de distribuição — estoque físico principal', kind = 'cd'
 WHERE id = 1 AND name LIKE 'CD%';

ALTER TABLE users
  ADD COLUMN industry_id INT UNSIGNED NULL AFTER department_id,
  ADD KEY idx_users_industry (industry_id),
  ADD CONSTRAINT fk_users_industry FOREIGN KEY (industry_id) REFERENCES industries (id);

ALTER TABLE items
  ADD COLUMN kind ENUM('fisico','voucher','cartao','outro') NOT NULL DEFAULT 'fisico' AFTER supplier_id;

ALTER TABLE events
  ADD COLUMN location_id INT UNSIGNED NULL AFTER venue,
  ADD KEY idx_events_location (location_id),
  ADD CONSTRAINT fk_events_location FOREIGN KEY (location_id) REFERENCES locations (id);

ALTER TABLE requests
  ADD COLUMN invoice_no VARCHAR(60) NULL AFTER purchase_ticket_no,
  ADD COLUMN action_type VARCHAR(40) NULL AFTER invoice_no,
  ADD COLUMN delivery_place VARCHAR(190) NULL AFTER action_type,
  ADD COLUMN public_code VARCHAR(40) NULL AFTER delivery_place,
  ADD COLUMN flow ENUM('interna','trade') NOT NULL DEFAULT 'interna' AFTER public_code,
  ADD UNIQUE KEY uq_requests_public_code (public_code),
  ADD KEY idx_requests_flow (flow, status);

ALTER TABLE requests
  MODIFY COLUMN status ENUM(
    'rascunho','solicitada','aguardando_aprovacao','aprovada','em_separacao','pronta','finalizada','reprovada','cancelada',
    'compra_realizada','aguardando_recebimento','recebido_cd','retirado','entregue'
  ) NOT NULL DEFAULT 'rascunho';

ALTER TABLE request_items
  ADD COLUMN qty_received INT NOT NULL DEFAULT 0 AFTER qty_delivered;

ALTER TABLE stock_movements
  ADD COLUMN from_location_id INT UNSIGNED NULL AFTER balance_after,
  ADD COLUMN to_location_id INT UNSIGNED NULL AFTER from_location_id;

ALTER TABLE stock_movements
  MODIFY COLUMN type ENUM('entrada','saida','ajuste','transferencia','estorno') NOT NULL;

ALTER TABLE stock_movements
  ADD CONSTRAINT fk_stock_movements_from_loc FOREIGN KEY (from_location_id) REFERENCES locations (id),
  ADD CONSTRAINT fk_stock_movements_to_loc FOREIGN KEY (to_location_id) REFERENCES locations (id);

CREATE TABLE IF NOT EXISTS stock_positions (
  item_id      INT UNSIGNED NOT NULL,
  location_id  INT UNSIGNED NOT NULL,
  qty          INT          NOT NULL DEFAULT 0,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (item_id, location_id),
  KEY idx_stock_positions_location (location_id),
  CONSTRAINT fk_stock_positions_item FOREIGN KEY (item_id) REFERENCES items (id),
  CONSTRAINT fk_stock_positions_location FOREIGN KEY (location_id) REFERENCES locations (id),
  CONSTRAINT chk_stock_positions_qty CHECK (qty >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stock_positions (item_id, location_id, qty)
SELECT s.item_id, COALESCE(i.location_id, 1), s.qty_on_hand
  FROM stock s JOIN items i ON i.id = s.item_id
 WHERE s.qty_on_hand > 0
ON DUPLICATE KEY UPDATE qty = VALUES(qty);

INSERT INTO roles (id, slug, name, description) VALUES
  (5, 'industry', 'Indústria', 'Acesso somente às informações da própria indústria parceira.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

UPDATE roles SET name = 'TRADE / Gestor', description = 'Cria solicitações TRADE, consulta estoque, retiradas e relatórios.' WHERE slug = 'approver';
UPDATE roles SET name = 'CD / Estoque', description = 'Recebimento no CD, saídas, transferências, retiradas e consulta ao estoque.' WHERE slug = 'operations';
UPDATE roles SET name = 'TRADE', description = 'Cria e acompanha solicitações de compra de brindes.' WHERE slug = 'requester';

INSERT INTO permissions (slug, name, module) VALUES
  ('stock.transfer', 'Transferir estoque entre locais/eventos', 'Estoque'),
  ('stock.receive',  'Registrar recebimento no CD',             'Estoque')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO role_permissions (role_id, permission_id)
  SELECT 3, p.id FROM permissions p
   WHERE p.slug IN ('stock.transfer', 'stock.receive')
     AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = 3 AND rp.permission_id = p.id);

INSERT INTO role_permissions (role_id, permission_id)
  SELECT 1, p.id FROM permissions p
   WHERE NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = 1 AND rp.permission_id = p.id);

INSERT INTO role_permissions (role_id, permission_id)
  SELECT 5, p.id FROM permissions p
   WHERE p.slug IN ('dashboard.view','items.view','stock.view','requests.view_own','events.view','deliveries.view','reports.view','reports.export')
     AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = 5 AND rp.permission_id = p.id);

-- TRADE / Gestor: eventos (criar, abrir, modo evento) + cadastros de brindes/indústrias
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 2, p.id FROM permissions p
   WHERE p.slug IN (
     'events.manage', 'events.withdraw', 'items.manage', 'lookups.manage',
     'stock.exit', 'stock.transfer'
   )
     AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = 2 AND rp.permission_id = p.id);

