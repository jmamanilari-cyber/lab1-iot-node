-- Run once against a new database. Stop on errors; never use --force.
-- Deliberately fails if lab2 already exists: review it before proceeding.
CREATE DATABASE lab2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lab2;
SET time_zone = '+00:00';

CREATE TABLE devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    node_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(100) NOT NULL,
    token_hash VARCHAR(255) NULL COMMENT 'NULL disables REST device authentication',
    last_seen DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_devices_node_id (node_id),
    CONSTRAINT chk_devices_node_id CHECK (node_id REGEXP '^[A-Za-z0-9_-]{1,64}$')
) ENGINE=InnoDB;

CREATE TABLE telemetry (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id BIGINT UNSIGNED NOT NULL,
    seq BIGINT UNSIGNED NOT NULL,
    ts BIGINT UNSIGNED NULL COMMENT 'Payload timestamp: Unix UTC seconds; NULL if unsynchronized',
    received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    value DOUBLE NOT NULL COMMENT 'Numeric measurement; PIR domain validation accepts only 0 or 1',
    rssi SMALLINT NULL,
    extensions JSON NULL COMMENT 'Optional documented JSON object; not part of identity',
    PRIMARY KEY (id),
    UNIQUE KEY uq_telemetry_device_seq (device_id, seq),
    KEY idx_telemetry_device_ts (device_id, ts),
    KEY idx_telemetry_device_received (device_id, received_at),
    CONSTRAINT fk_telemetry_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE RESTRICT,
    CONSTRAINT chk_telemetry_seq CHECK (seq <= 9007199254740991),
    CONSTRAINT chk_telemetry_ts CHECK (ts IS NULL OR ts <= 253402300799),
    CONSTRAINT chk_telemetry_rssi CHECK (rssi IS NULL OR rssi BETWEEN -127 AND 0),
    CONSTRAINT chk_telemetry_extensions CHECK (extensions IS NULL OR JSON_TYPE(extensions) = 'OBJECT')
) ENGINE=InnoDB;

CREATE TABLE configurations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id BIGINT UNSIGNED NOT NULL,
    `key` VARCHAR(64) NOT NULL,
    `value` JSON NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_configurations_device_key (device_id, `key`),
    CONSTRAINT fk_configurations_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id BIGINT UNSIGNED NULL COMMENT 'NULL for global events',
    type VARCHAR(64) NOT NULL,
    severity ENUM('info', 'warning', 'error') NOT NULL,
    ts DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    detail JSON NULL,
    PRIMARY KEY (id),
    KEY idx_events_device_ts (device_id, ts),
    KEY idx_events_type_ts (type, ts),
    KEY idx_events_ts (ts),
    CONSTRAINT fk_events_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE RESTRICT
) ENGINE=InnoDB;
