-- =====================================================================
--  Controle de Brindes - database schema v1.0
--  Compatible: MySQL 8.0.16+ / MariaDB 10.4+  (InnoDB, utf8mb4)
--  Import into an EMPTY database (phpMyAdmin or: mysql db < schema.sql)
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Access control
-- ---------------------------------------------------------------------
CREATE TABLE roles (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug         VARCHAR(30)  NOT NULL,
  name         VARCHAR(60)  NOT NULL,
  description  VARCHAR(255) NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug    VARCHAR(60)  NOT NULL,
  name    VARCHAR(120) NOT NULL,
  module  VARCHAR(40)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id        INT UNSIGNED NOT NULL,
  permission_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  KEY idx_role_permissions_permission (permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Lookup registers (cadastros auxiliares)
-- ---------------------------------------------------------------------
CREATE TABLE departments (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(100) NOT NULL,
  description  VARCHAR(255) NULL,
  active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by   INT UNSIGNED NULL,
  updated_by   INT UNSIGNED NULL,
  deleted_at   DATETIME     NULL,
  deleted_by   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_departments_name (name),
  KEY idx_departments_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(100) NOT NULL,
  description  VARCHAR(255) NULL,
  active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by   INT UNSIGNED NULL,
  updated_by   INT UNSIGNED NULL,
  deleted_at   DATETIME     NULL,
  deleted_by   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_categories_name (name),
  KEY idx_categories_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE locations (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(100) NOT NULL,
  description  VARCHAR(255) NULL,
  kind         ENUM('cd','evento','outro') NOT NULL DEFAULT 'cd',
  active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by   INT UNSIGNED NULL,
  updated_by   INT UNSIGNED NULL,
  deleted_at   DATETIME     NULL,
  deleted_by   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_locations_name (name),
  KEY idx_locations_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suppliers (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(150) NOT NULL,
  cnpj           VARCHAR(20)  NULL,
  contact_name   VARCHAR(120) NULL,
  contact_email  VARCHAR(190) NULL,
  contact_phone  VARCHAR(30)  NULL,
  notes          TEXT         NULL,
  active         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by     INT UNSIGNED NULL,
  updated_by     INT UNSIGNED NULL,
  deleted_at     DATETIME     NULL,
  deleted_by     INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_suppliers_name (name),
  KEY idx_suppliers_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE industries (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(150) NOT NULL,
  cnpj           VARCHAR(20)  NULL,
  contact_name   VARCHAR(120) NULL,
  contact_email  VARCHAR(190) NULL,
  contact_phone  VARCHAR(30)  NULL,
  notes          TEXT         NULL,
  active         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by     INT UNSIGNED NULL,
  updated_by     INT UNSIGNED NULL,
  deleted_at     DATETIME     NULL,
  deleted_by     INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_industries_name (name),
  KEY idx_industries_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Users
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                  VARCHAR(150) NOT NULL,
  email                 VARCHAR(190) NOT NULL,
  password_hash         VARCHAR(255) NOT NULL,
  role_id               INT UNSIGNED NOT NULL,
  department_id         INT UNSIGNED NULL,
  industry_id           INT UNSIGNED NULL,
  phone                 VARCHAR(30)  NULL,
  active                TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_password  TINYINT(1)   NOT NULL DEFAULT 0,
  session_version       INT UNSIGNED NOT NULL DEFAULT 1,
  last_login_at         DATETIME     NULL,
  password_changed_at   DATETIME     NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by            INT UNSIGNED NULL,
  updated_by            INT UNSIGNED NULL,
  deleted_at            DATETIME     NULL,
  deleted_by            INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role_id),
  KEY idx_users_department (department_id),
  KEY idx_users_industry (industry_id),
  KEY idx_users_deleted (deleted_at),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id),
  CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments (id),
  CONSTRAINT fk_users_industry FOREIGN KEY (industry_id) REFERENCES industries (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Items (brindes) and stock
-- ---------------------------------------------------------------------
CREATE TABLE items (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  code         VARCHAR(40)   NOT NULL,
  name         VARCHAR(150)  NOT NULL,
  description  TEXT          NULL,
  category_id  INT UNSIGNED  NOT NULL,
  location_id  INT UNSIGNED  NULL,
  supplier_id  INT UNSIGNED  NULL,
  kind         ENUM('fisico','voucher','cartao','outro') NOT NULL DEFAULT 'fisico',
  unit_value   DECIMAL(12,2) NULL,
  min_stock    INT UNSIGNED  NOT NULL DEFAULT 0,
  status       ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  entry_date   DATE          NULL,
  photo_path   VARCHAR(255)  NULL,
  thumb_path   VARCHAR(255)  NULL,
  notes        TEXT          NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by   INT UNSIGNED  NULL,
  updated_by   INT UNSIGNED  NULL,
  deleted_at   DATETIME      NULL,
  deleted_by   INT UNSIGNED  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_items_code (code),
  KEY idx_items_name (name),
  KEY idx_items_category (category_id),
  KEY idx_items_location (location_id),
  KEY idx_items_supplier (supplier_id),
  KEY idx_items_status (status, deleted_at),
  CONSTRAINT fk_items_category FOREIGN KEY (category_id) REFERENCES categories (id),
  CONSTRAINT fk_items_location FOREIGN KEY (location_id) REFERENCES locations (id),
  CONSTRAINT fk_items_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  CONSTRAINT chk_items_unit_value CHECK (unit_value IS NULL OR unit_value >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One balance row per item. available = qty_on_hand - qty_reserved.
CREATE TABLE stock (
  item_id       INT UNSIGNED NOT NULL,
  qty_on_hand   INT          NOT NULL DEFAULT 0,
  qty_reserved  INT          NOT NULL DEFAULT 0,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (item_id),
  CONSTRAINT fk_stock_item FOREIGN KEY (item_id) REFERENCES items (id),
  CONSTRAINT chk_stock_balance CHECK (qty_on_hand >= 0 AND qty_reserved >= 0 AND qty_reserved <= qty_on_hand)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Events (Modo Evento)
-- ---------------------------------------------------------------------
CREATE TABLE events (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(150) NOT NULL,
  description  TEXT         NULL,
  venue        VARCHAR(190) NULL,
  location_id  INT UNSIGNED NULL,
  starts_on    DATE         NULL,
  ends_on      DATE         NULL,
  status       ENUM('planejado','aberto','encerrado','cancelado') NOT NULL DEFAULT 'planejado',
  notes        TEXT         NULL,
  opened_at    DATETIME     NULL,
  opened_by    INT UNSIGNED NULL,
  closed_at    DATETIME     NULL,
  closed_by    INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by   INT UNSIGNED NULL,
  updated_by   INT UNSIGNED NULL,
  deleted_at   DATETIME     NULL,
  deleted_by   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_events_status (status),
  KEY idx_events_starts (starts_on),
  KEY idx_events_location (location_id),
  CONSTRAINT fk_events_location FOREIGN KEY (location_id) REFERENCES locations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quota (saldo) of each industry per item in an event. saldo = allocated - withdrawn.
CREATE TABLE event_allocations (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id       INT UNSIGNED NOT NULL,
  industry_id    INT UNSIGNED NOT NULL,
  item_id        INT UNSIGNED NOT NULL,
  qty_allocated  INT          NOT NULL DEFAULT 0,
  qty_withdrawn  INT          NOT NULL DEFAULT 0,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_allocations (event_id, industry_id, item_id),
  KEY idx_event_allocations_industry (industry_id),
  KEY idx_event_allocations_item (item_id),
  CONSTRAINT fk_event_allocations_event FOREIGN KEY (event_id) REFERENCES events (id),
  CONSTRAINT fk_event_allocations_industry FOREIGN KEY (industry_id) REFERENCES industries (id),
  CONSTRAINT fk_event_allocations_item FOREIGN KEY (item_id) REFERENCES items (id),
  CONSTRAINT chk_event_allocations CHECK (qty_allocated >= 0 AND qty_withdrawn >= 0 AND qty_withdrawn <= qty_allocated)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Requests (solicitações) and approvals
-- ---------------------------------------------------------------------
CREATE TABLE requests (
  id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  code                VARCHAR(20)   NOT NULL,
  requester_id        INT UNSIGNED  NOT NULL,
  department_id       INT UNSIGNED  NULL,
  industry_id         INT UNSIGNED  NULL,
  event_id            INT UNSIGNED  NULL,
  purpose             VARCHAR(255)  NULL,
  recipient           VARCHAR(150)  NULL,
  needed_date         DATE          NULL,
  purchase_ticket_no  VARCHAR(50)   NULL,
  invoice_no          VARCHAR(60)   NULL,
  invoice_path        VARCHAR(255)  NULL,
  action_type         VARCHAR(40)   NULL,
  delivery_place      VARCHAR(190)  NULL,
  public_code         VARCHAR(40)   NULL,
  flow                ENUM('interna','trade') NOT NULL DEFAULT 'interna',
  needs_purchase      TINYINT(1)    NOT NULL DEFAULT 0,
  status              ENUM('rascunho','solicitada','aguardando_aprovacao','aprovada','em_separacao','pronta','finalizada','reprovada','cancelada','compra_realizada','aguardando_recebimento','recebido_cd','retirado','entregue') NOT NULL DEFAULT 'rascunho',
  total_value         DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes               TEXT          NULL,
  submitted_at        DATETIME      NULL,
  approved_at         DATETIME      NULL,
  finalized_at        DATETIME      NULL,
  cancelled_at        DATETIME      NULL,
  cancel_reason       VARCHAR(255)  NULL,
  status_changed_at   DATETIME      NULL,
  created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at          DATETIME      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_requests_code (code),
  UNIQUE KEY uq_requests_public_code (public_code),
  KEY idx_requests_status (status, status_changed_at),
  KEY idx_requests_flow (flow, status),
  KEY idx_requests_requester (requester_id),
  KEY idx_requests_department (department_id),
  KEY idx_requests_industry (industry_id),
  KEY idx_requests_event (event_id),
  KEY idx_requests_created (created_at),
  CONSTRAINT fk_requests_requester FOREIGN KEY (requester_id) REFERENCES users (id),
  CONSTRAINT fk_requests_department FOREIGN KEY (department_id) REFERENCES departments (id),
  CONSTRAINT fk_requests_industry FOREIGN KEY (industry_id) REFERENCES industries (id),
  CONSTRAINT fk_requests_event FOREIGN KEY (event_id) REFERENCES events (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE request_items (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  request_id     INT UNSIGNED  NOT NULL,
  item_id        INT UNSIGNED  NOT NULL,
  qty_requested  INT           NOT NULL,
  qty_approved   INT           NULL,
  qty_reserved   INT           NOT NULL DEFAULT 0,
  qty_delivered  INT           NOT NULL DEFAULT 0,
  qty_received   INT           NOT NULL DEFAULT 0,
  unit_value     DECIMAL(12,2) NULL,
  notes          VARCHAR(255)  NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_request_items (request_id, item_id),
  KEY idx_request_items_item (item_id),
  CONSTRAINT fk_request_items_request FOREIGN KEY (request_id) REFERENCES requests (id) ON DELETE CASCADE,
  CONSTRAINT fk_request_items_item FOREIGN KEY (item_id) REFERENCES items (id),
  CONSTRAINT chk_request_items CHECK (qty_requested > 0 AND qty_reserved >= 0 AND qty_delivered >= 0 AND (qty_approved IS NULL OR qty_approved >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE request_status_history (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id   INT UNSIGNED    NOT NULL,
  from_status  VARCHAR(30)     NULL,
  to_status    VARCHAR(30)     NOT NULL,
  user_id      INT UNSIGNED    NULL,
  comment      VARCHAR(500)    NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_request_status_history (request_id, created_at),
  CONSTRAINT fk_request_status_history_request FOREIGN KEY (request_id) REFERENCES requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE approval_rules (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(120) NOT NULL,
  criterion         ENUM('quantidade','valor','categoria','departamento','perfil') NOT NULL,
  operator          ENUM('>','>=','=','in') NOT NULL DEFAULT '>',
  value             VARCHAR(255) NOT NULL,
  approver_role_id  INT UNSIGNED NULL,
  approver_user_id  INT UNSIGNED NULL,
  priority          INT          NOT NULL DEFAULT 100,
  active            TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by        INT UNSIGNED NULL,
  updated_by        INT UNSIGNED NULL,
  deleted_at        DATETIME     NULL,
  deleted_by        INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_approval_rules_active (active, priority),
  CONSTRAINT fk_approval_rules_role FOREIGN KEY (approver_role_id) REFERENCES roles (id),
  CONSTRAINT fk_approval_rules_user FOREIGN KEY (approver_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE approvals (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id        INT UNSIGNED NOT NULL,
  rule_id           INT UNSIGNED NULL,
  approver_role_id  INT UNSIGNED NULL,
  approver_user_id  INT UNSIGNED NULL,
  decision          ENUM('pendente','aprovado','reprovado') NOT NULL DEFAULT 'pendente',
  justification     TEXT         NULL,
  decided_by        INT UNSIGNED NULL,
  decided_at        DATETIME     NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_approvals_request (request_id),
  KEY idx_approvals_decision (decision),
  CONSTRAINT fk_approvals_request FOREIGN KEY (request_id) REFERENCES requests (id) ON DELETE CASCADE,
  CONSTRAINT fk_approvals_rule FOREIGN KEY (rule_id) REFERENCES approval_rules (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Deliveries (protocolos) - day-to-day and event withdrawals
-- ---------------------------------------------------------------------
CREATE TABLE deliveries (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                  VARCHAR(20)  NOT NULL,
  type                  ENUM('dia_a_dia','evento') NOT NULL,
  request_id            INT UNSIGNED NULL,
  event_id              INT UNSIGNED NULL,
  industry_id           INT UNSIGNED NULL,
  delivered_by          INT UNSIGNED NOT NULL,
  received_by_name      VARCHAR(150) NOT NULL,
  received_by_document  VARCHAR(30)  NULL,
  received_by_email     VARCHAR(190) NULL,
  received_by_phone     VARCHAR(30)  NULL,
  signature_path        VARCHAR(255) NULL,
  signature_png         MEDIUMTEXT   NULL,
  pdf_path              VARCHAR(255) NULL,
  verification_hash     CHAR(64)     NULL,
  notes                 TEXT         NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_deliveries_code (code),
  KEY idx_deliveries_request (request_id),
  KEY idx_deliveries_event (event_id, industry_id),
  KEY idx_deliveries_created (created_at),
  CONSTRAINT fk_deliveries_request FOREIGN KEY (request_id) REFERENCES requests (id),
  CONSTRAINT fk_deliveries_event FOREIGN KEY (event_id) REFERENCES events (id),
  CONSTRAINT fk_deliveries_industry FOREIGN KEY (industry_id) REFERENCES industries (id),
  CONSTRAINT fk_deliveries_user FOREIGN KEY (delivered_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE delivery_items (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  delivery_id    INT UNSIGNED NOT NULL,
  item_id        INT UNSIGNED NOT NULL,
  qty            INT          NOT NULL,
  balance_after  INT          NULL,
  PRIMARY KEY (id),
  KEY idx_delivery_items_delivery (delivery_id),
  KEY idx_delivery_items_item (item_id),
  CONSTRAINT fk_delivery_items_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries (id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_items_item FOREIGN KEY (item_id) REFERENCES items (id),
  CONSTRAINT chk_delivery_items_qty CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Stock movements - IMMUTABLE ledger (the app never updates/deletes rows)
-- qty is signed: entrada > 0, saida < 0, ajuste +/-
-- ---------------------------------------------------------------------
CREATE TABLE stock_movements (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id             INT UNSIGNED    NOT NULL,
  type                ENUM('entrada','saida','ajuste','transferencia','estorno') NOT NULL,
  qty                 INT             NOT NULL,
  balance_after       INT             NOT NULL,
  from_location_id    INT UNSIGNED    NULL,
  to_location_id      INT UNSIGNED    NULL,
  unit_value          DECIMAL(12,2)   NULL,
  user_id             INT UNSIGNED    NOT NULL,
  requester_id        INT UNSIGNED    NULL,
  department_id       INT UNSIGNED    NULL,
  industry_id         INT UNSIGNED    NULL,
  supplier_id         INT UNSIGNED    NULL,
  recipient           VARCHAR(150)    NULL,
  purpose             VARCHAR(255)    NULL,
  purchase_ticket_no  VARCHAR(50)     NULL,
  document_ref        VARCHAR(60)     NULL,
  reason              VARCHAR(255)    NULL,
  notes               TEXT            NULL,
  attachment_path     VARCHAR(255)    NULL,
  request_id          INT UNSIGNED    NULL,
  delivery_id         INT UNSIGNED    NULL,
  event_id            INT UNSIGNED    NULL,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_stock_movements_item (item_id, created_at),
  KEY idx_stock_movements_type (type, created_at),
  KEY idx_stock_movements_created (created_at),
  KEY idx_stock_movements_user (user_id),
  KEY idx_stock_movements_industry (industry_id),
  KEY idx_stock_movements_department (department_id),
  KEY idx_stock_movements_request (request_id),
  KEY idx_stock_movements_event (event_id),
  CONSTRAINT fk_stock_movements_item FOREIGN KEY (item_id) REFERENCES items (id),
  CONSTRAINT fk_stock_movements_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_stock_movements_requester FOREIGN KEY (requester_id) REFERENCES users (id),
  CONSTRAINT fk_stock_movements_department FOREIGN KEY (department_id) REFERENCES departments (id),
  CONSTRAINT fk_stock_movements_industry FOREIGN KEY (industry_id) REFERENCES industries (id),
  CONSTRAINT fk_stock_movements_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  CONSTRAINT fk_stock_movements_request FOREIGN KEY (request_id) REFERENCES requests (id),
  CONSTRAINT fk_stock_movements_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries (id),
  CONSTRAINT fk_stock_movements_event FOREIGN KEY (event_id) REFERENCES events (id),
  CONSTRAINT fk_stock_movements_from_loc FOREIGN KEY (from_location_id) REFERENCES locations (id),
  CONSTRAINT fk_stock_movements_to_loc FOREIGN KEY (to_location_id) REFERENCES locations (id),
  CONSTRAINT chk_stock_movements_qty CHECK (qty <> 0 AND balance_after >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Physical quantity per location. Total on_hand = sum of positions.
CREATE TABLE stock_positions (
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

-- ---------------------------------------------------------------------
-- Notifications (in-app inbox + e-mail outbox)
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED    NULL,
  channel          ENUM('in_app','email') NOT NULL,
  to_email         VARCHAR(190)    NULL,
  subject          VARCHAR(255)    NOT NULL,
  body             MEDIUMTEXT      NULL,
  link_url         VARCHAR(255)    NULL,
  attachment_path  VARCHAR(255)    NULL,
  related_type     VARCHAR(40)     NULL,
  related_id       BIGINT UNSIGNED NULL,
  status           ENUM('fila','enviado','falhou') NOT NULL DEFAULT 'fila',
  attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error       VARCHAR(500)    NULL,
  dedupe_key       VARCHAR(150)    NULL,
  read_at          DATETIME        NULL,
  sent_at          DATETIME        NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notifications_dedupe (dedupe_key),
  KEY idx_notifications_inbox (user_id, read_at),
  KEY idx_notifications_outbox (status, channel, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit trail - append-only (the app never updates/deletes rows)
-- ---------------------------------------------------------------------
CREATE TABLE audit_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED    NULL,
  user_name     VARCHAR(150)    NULL,
  action        VARCHAR(40)     NOT NULL,
  entity_type   VARCHAR(40)     NOT NULL,
  entity_id     BIGINT UNSIGNED NULL,
  entity_label  VARCHAR(190)    NULL,
  before_json   LONGTEXT        NULL,
  after_json    LONGTEXT        NULL,
  ip            VARCHAR(45)     NULL,
  user_agent    VARCHAR(255)    NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_user (user_id, created_at),
  KEY idx_audit_action (action),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Settings, security and technical tables
-- ---------------------------------------------------------------------
CREATE TABLE settings (
  `key`       VARCHAR(60)  NOT NULL,
  value       TEXT         NULL,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by  INT UNSIGNED NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email       VARCHAR(190)    NOT NULL,
  ip          VARCHAR(45)     NOT NULL,
  success     TINYINT(1)      NOT NULL DEFAULT 0,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_attempts_email (email, created_at),
  KEY idx_login_attempts_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64)     NOT NULL,
  expires_at  DATETIME     NOT NULL,
  used_at     DATETIME     NULL,
  ip          VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_resets_token (token_hash),
  KEY idx_password_resets_user (user_id, created_at),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Protection against duplicated submissions (double click, network retry).
CREATE TABLE idempotency_keys (
  id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED      NOT NULL,
  idem_key       VARCHAR(80)       NOT NULL,
  scope          VARCHAR(190)      NOT NULL,
  request_hash   CHAR(64)          NOT NULL,
  status_code    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  response_body  MEDIUMTEXT        NULL,
  created_at     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_idempotency_keys (user_id, idem_key),
  KEY idx_idempotency_keys_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Human-readable sequential codes (BRD-00001, SOL-2026-0001, PROT-2026-0001).
CREATE TABLE sequences (
  name        VARCHAR(40)  NOT NULL,
  period      VARCHAR(10)  NOT NULL,
  last_value  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (name, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Authorized exits (gestor authorizes, CD confirms via QR)
-- ---------------------------------------------------------------------
CREATE TABLE stock_exit_orders (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code             VARCHAR(20)  NOT NULL,
  item_id          INT UNSIGNED NOT NULL,
  qty              INT          NOT NULL,
  purpose          VARCHAR(255) NOT NULL,
  recipient        VARCHAR(150) NULL,
  industry_id      INT UNSIGNED NULL,
  department_id    INT UNSIGNED NULL,
  requester_id     INT UNSIGNED NULL,
  document_ref     VARCHAR(60)  NULL,
  notes            TEXT         NULL,
  attachment_path  VARCHAR(255) NULL,
  status           ENUM('autorizada','confirmada','cancelada') NOT NULL DEFAULT 'autorizada',
  authorized_by    INT UNSIGNED NOT NULL,
  authorized_at    DATETIME     NOT NULL,
  confirmed_by     INT UNSIGNED NULL,
  confirmed_at     DATETIME     NULL,
  movement_id      BIGINT UNSIGNED NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stock_exit_orders_code (code),
  KEY idx_stock_exit_orders_status (status, created_at),
  KEY idx_stock_exit_orders_item (item_id),
  CONSTRAINT fk_exit_orders_item FOREIGN KEY (item_id) REFERENCES items (id),
  CONSTRAINT fk_exit_orders_industry FOREIGN KEY (industry_id) REFERENCES industries (id),
  CONSTRAINT fk_exit_orders_department FOREIGN KEY (department_id) REFERENCES departments (id),
  CONSTRAINT fk_exit_orders_requester FOREIGN KEY (requester_id) REFERENCES users (id),
  CONSTRAINT fk_exit_orders_auth FOREIGN KEY (authorized_by) REFERENCES users (id),
  CONSTRAINT fk_exit_orders_conf FOREIGN KEY (confirmed_by) REFERENCES users (id),
  CONSTRAINT fk_exit_orders_mov FOREIGN KEY (movement_id) REFERENCES stock_movements (id),
  CONSTRAINT chk_exit_orders_qty CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
