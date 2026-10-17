# ADR 0002: Database engine: stay on MySQL / MariaDB

- **Status:** Accepted (2026-10-08, by the product owner)
- **Supersedes:** the open question "MySQL vs PostgreSQL" in ADR 0001 and backlog §13

## Context

ADR 0001 chose a database per firm and left the engine open: PostgreSQL offers schema-per-firm (lighter than a
database per firm), stronger partitioning and built-in full-text search; MySQL is what the legacy system and the
existing code run on. The default was to stay on MySQL unless an evaluation showed a decisive win.

Since then the platform has been built and measured on MySQL/MariaDB:

- per-firm snapshots and point-in-time restore replay MySQL's binary log (TEN-14);
- the install restore drill and the legacy cutover rehearsal restore through `mysqldump` / `mysql` (CARRY-7, CARRY-5);
- capacity ceilings, migration timings and cell latency were measured on MariaDB (ENG-27, DATA-13, TEN-4);
- the legacy production database is MySQL, so the cutover is an in-place upgrade on the same engine;
- CI runs the suite and the isolation gate on MySQL 8.

## Decision

Stay on **MySQL 8 / MariaDB 10.11+**, one database per firm. PostgreSQL is not supported as a firm database engine.
(SQLite remains for tests and single-firm development only.)

## Consequences

- No engine migration project. The tooling above stays valid as built.
- What PostgreSQL would have given is covered another way: search by Meilisearch (ENG-19); audit partitioning on
  MySQL (DATA-11).
- The known MySQL cost stays: every firm is a database with its own tables, so a server's table and definition caches
  are the first limit (about 200–300 firms per server; docs/ops/CAPACITY.md). Growth means more cells (TEN-4).
- Revisit only with evidence: if measured cost per firm at real scale makes the per-server ceiling a business problem,
  write a new ADR with the numbers.
