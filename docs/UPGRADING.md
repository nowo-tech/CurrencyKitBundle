# Upgrading

## Table of contents

- [0.1.0](#010)

## 0.1.0

First release. Nothing to upgrade from; create the bundle's tables.

The tables are mapped read-only on `doctrine.entity_manager` (default: the default manager), so
`doctrine:migrations:diff` generates them and a `doctrine:schema:update --dump-sql` drift gate shows no diff once
they exist. Hosts that write migrations by hand use the reference DDL below (default prefix `nowo_`; generated from the
mapping by `SchemaTest::testReferenceDdlInUpgradingIsCurrent`, which fails when this section is out of date).

<!-- DDL -->

### MySQL 8.4

```sql
CREATE TABLE nowo_currency_rate_publication (source VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, rate_date DATE NOT NULL, origin VARCHAR(16) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, currency_count SMALLINT NOT NULL, content_hash CHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, first_stored_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, version INT NOT NULL, id CHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, UNIQUE INDEX nowo_currency_rate_publication_source_date_uniq (source, rate_date), PRIMARY KEY (id));
CREATE TABLE nowo_currency_rate_change (source VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, rate_date DATE NOT NULL, currency CHAR(3) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, old_rate NUMERIC(24, 12) DEFAULT NULL, new_rate NUMERIC(24, 12) DEFAULT NULL, origin VARCHAR(16) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, detected_at DATETIME NOT NULL, applied TINYINT NOT NULL, run_id CHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, id CHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, INDEX nowo_currency_rate_change_source_date_idx (source, rate_date), INDEX nowo_currency_rate_change_detected_idx (detected_at), PRIMARY KEY (id));
CREATE TABLE nowo_currency_rate (source VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, rate_date DATE NOT NULL, currency CHAR(3) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, rate NUMERIC(24, 12) NOT NULL, publication_id CHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, id CHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, INDEX nowo_currency_rate_source_currency_date_idx (source, currency, rate_date), UNIQUE INDEX nowo_currency_rate_source_date_currency_uniq (source, rate_date, currency), PRIMARY KEY (id));
CREATE TABLE nowo_currency_sync_lock (locked_until DATETIME NOT NULL, holder VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, name VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, PRIMARY KEY (name));
CREATE TABLE nowo_currency_manual_rate (currency CHAR(3) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, rate NUMERIC(24, 12) NOT NULL, valid_from DATE NOT NULL, valid_until DATE DEFAULT NULL, reason VARCHAR(500) NOT NULL, status VARCHAR(8) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, created_by VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`, created_at DATETIME NOT NULL, updated_by VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`, updated_at DATETIME NOT NULL, version INT NOT NULL, id CHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, INDEX nowo_currency_manual_rate_currency_status_from_idx (currency, status, valid_from), PRIMARY KEY (id));
```

### MariaDB 11.4

```sql
CREATE TABLE nowo_currency_rate_publication (source VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, rate_date DATE NOT NULL, origin VARCHAR(16) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, currency_count SMALLINT NOT NULL, content_hash CHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, first_stored_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, version INT NOT NULL, id CHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, UNIQUE INDEX nowo_currency_rate_publication_source_date_uniq (source, rate_date), PRIMARY KEY (id));
CREATE TABLE nowo_currency_rate_change (source VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, rate_date DATE NOT NULL, currency CHAR(3) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, old_rate NUMERIC(24, 12) DEFAULT NULL, new_rate NUMERIC(24, 12) DEFAULT NULL, origin VARCHAR(16) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, detected_at DATETIME NOT NULL, applied TINYINT NOT NULL, run_id CHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, id CHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, INDEX nowo_currency_rate_change_source_date_idx (source, rate_date), INDEX nowo_currency_rate_change_detected_idx (detected_at), PRIMARY KEY (id));
CREATE TABLE nowo_currency_rate (source VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, rate_date DATE NOT NULL, currency CHAR(3) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, rate NUMERIC(24, 12) NOT NULL, publication_id CHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, id CHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, INDEX nowo_currency_rate_source_currency_date_idx (source, currency, rate_date), UNIQUE INDEX nowo_currency_rate_source_date_currency_uniq (source, rate_date, currency), PRIMARY KEY (id));
CREATE TABLE nowo_currency_sync_lock (locked_until DATETIME NOT NULL, holder VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, name VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, PRIMARY KEY (name));
CREATE TABLE nowo_currency_manual_rate (currency CHAR(3) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, rate NUMERIC(24, 12) NOT NULL, valid_from DATE NOT NULL, valid_until DATE DEFAULT NULL, reason VARCHAR(500) NOT NULL, status VARCHAR(8) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, created_by VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_nopad_bin`, created_at DATETIME NOT NULL, updated_by VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_nopad_bin`, updated_at DATETIME NOT NULL, version INT NOT NULL, id CHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`, INDEX nowo_currency_manual_rate_currency_status_from_idx (currency, status, valid_from), PRIMARY KEY (id));
```

### PostgreSQL 16

```sql
CREATE TABLE nowo_currency_rate_publication (source VARCHAR(32) NOT NULL, rate_date DATE NOT NULL, origin VARCHAR(16) NOT NULL, currency_count SMALLINT NOT NULL, content_hash CHAR(64) NOT NULL, first_stored_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, version INT NOT NULL, id CHAR(36) NOT NULL, PRIMARY KEY (id));
CREATE UNIQUE INDEX nowo_currency_rate_publication_source_date_uniq ON nowo_currency_rate_publication (source, rate_date);
CREATE TABLE nowo_currency_rate_change (source VARCHAR(32) NOT NULL, rate_date DATE NOT NULL, currency CHAR(3) NOT NULL, old_rate NUMERIC(24, 12) DEFAULT NULL, new_rate NUMERIC(24, 12) DEFAULT NULL, origin VARCHAR(16) NOT NULL, detected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, applied BOOLEAN NOT NULL, run_id CHAR(36) NOT NULL, id CHAR(36) NOT NULL, PRIMARY KEY (id));
CREATE INDEX nowo_currency_rate_change_source_date_idx ON nowo_currency_rate_change (source, rate_date);
CREATE INDEX nowo_currency_rate_change_detected_idx ON nowo_currency_rate_change (detected_at);
CREATE TABLE nowo_currency_rate (source VARCHAR(32) NOT NULL, rate_date DATE NOT NULL, currency CHAR(3) NOT NULL, rate NUMERIC(24, 12) NOT NULL, publication_id CHAR(36) NOT NULL, id CHAR(36) NOT NULL, PRIMARY KEY (id));
CREATE INDEX nowo_currency_rate_source_currency_date_idx ON nowo_currency_rate (source, currency, rate_date);
CREATE UNIQUE INDEX nowo_currency_rate_source_date_currency_uniq ON nowo_currency_rate (source, rate_date, currency);
CREATE TABLE nowo_currency_sync_lock (locked_until TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, holder VARCHAR(36) NOT NULL, name VARCHAR(64) NOT NULL, PRIMARY KEY (name));
CREATE TABLE nowo_currency_manual_rate (currency CHAR(3) NOT NULL, rate NUMERIC(24, 12) NOT NULL, valid_from DATE NOT NULL, valid_until DATE DEFAULT NULL, reason VARCHAR(500) NOT NULL, status VARCHAR(8) NOT NULL, created_by VARCHAR(128) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_by VARCHAR(128) DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, version INT NOT NULL, id CHAR(36) NOT NULL, PRIMARY KEY (id));
CREATE INDEX nowo_currency_manual_rate_currency_status_from_idx ON nowo_currency_manual_rate (currency, status, valid_from);
```

### SQLite

```sql
CREATE TABLE nowo_currency_rate_publication (source VARCHAR(32) NOT NULL, rate_date DATE NOT NULL, origin VARCHAR(16) NOT NULL, currency_count SMALLINT NOT NULL, content_hash CHAR(64) NOT NULL, first_stored_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, version INTEGER NOT NULL, id CHAR(36) NOT NULL, PRIMARY KEY (id));
CREATE UNIQUE INDEX nowo_currency_rate_publication_source_date_uniq ON nowo_currency_rate_publication (source, rate_date);
CREATE TABLE nowo_currency_rate_change (source VARCHAR(32) NOT NULL, rate_date DATE NOT NULL, currency CHAR(3) NOT NULL, old_rate NUMERIC(24, 12) DEFAULT NULL, new_rate NUMERIC(24, 12) DEFAULT NULL, origin VARCHAR(16) NOT NULL, detected_at DATETIME NOT NULL, applied BOOLEAN NOT NULL, run_id CHAR(36) NOT NULL, id CHAR(36) NOT NULL, PRIMARY KEY (id));
CREATE INDEX nowo_currency_rate_change_source_date_idx ON nowo_currency_rate_change (source, rate_date);
CREATE INDEX nowo_currency_rate_change_detected_idx ON nowo_currency_rate_change (detected_at);
CREATE TABLE nowo_currency_rate (source VARCHAR(32) NOT NULL, rate_date DATE NOT NULL, currency CHAR(3) NOT NULL, rate NUMERIC(24, 12) NOT NULL, publication_id CHAR(36) NOT NULL, id CHAR(36) NOT NULL, PRIMARY KEY (id));
CREATE INDEX nowo_currency_rate_source_currency_date_idx ON nowo_currency_rate (source, currency, rate_date);
CREATE UNIQUE INDEX nowo_currency_rate_source_date_currency_uniq ON nowo_currency_rate (source, rate_date, currency);
CREATE TABLE nowo_currency_sync_lock (locked_until DATETIME NOT NULL, holder VARCHAR(36) NOT NULL, name VARCHAR(64) NOT NULL, PRIMARY KEY (name));
CREATE TABLE nowo_currency_manual_rate (currency CHAR(3) NOT NULL, rate NUMERIC(24, 12) NOT NULL, valid_from DATE NOT NULL, valid_until DATE DEFAULT NULL, reason VARCHAR(500) NOT NULL, status VARCHAR(8) NOT NULL, created_by VARCHAR(128) DEFAULT NULL, created_at DATETIME NOT NULL, updated_by VARCHAR(128) DEFAULT NULL, updated_at DATETIME NOT NULL, version INTEGER NOT NULL, id CHAR(36) NOT NULL, PRIMARY KEY (id));
CREATE INDEX nowo_currency_manual_rate_currency_status_from_idx ON nowo_currency_manual_rate (currency, status, valid_from);
```

<!-- /DDL -->



