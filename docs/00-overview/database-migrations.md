# Database Migrations

Review gate document. Laravel migration files in `backend/database/migrations/` are generated from this spec.

## Migration order (20 files)

| # | File | Tables |
|---|------|--------|
| 01 | `0001_01_01_000000_create_users_table.php` | users, sessions (phone-first, all columns in one file) |
| 02 | `0001_01_01_000001_create_cache_table.php` | cache, cache_locks |
| 03 | `0001_01_01_000002_create_jobs_table.php` | jobs, job_batches, failed_jobs |
| 04 | `2026_06_13_*_create_personal_access_tokens_table.php` | personal_access_tokens |
| 05 | `2026_06_13_*_create_permission_tables.php` | spatie permission tables |
| 06 | `2026_01_01_000006_create_user_phones_table.php` | user_phones |
| 07 | `2026_01_01_000010_create_families_table.php` | families |
| 08 | `2026_01_01_000011_create_family_members_table.php` | family_members |
| 09 | `2026_01_01_000012_create_relationship_tables.php` | relationship_edge_types, relationship_edges |
| 10 | `2026_01_01_000013_create_onboarding_sessions_table.php` | onboarding_sessions, onboarding_answers |
| 11 | `2026_01_01_000020_create_user_encryption_tables.php` | user_encryption_keys, user_key_backups, user_devices |
| 12 | `2026_01_01_000030_create_connections_table.php` | connections |
| 13 | `2026_01_01_000040_create_groups_table.php` | groups, group_members |
| 14 | `2026_01_01_000041_create_group_encryption_tables.php` | group_encryption_generations, group_key_envelopes |
| 15 | `2026_01_01_000042_create_messages_table.php` | messages |
| 16 | `2026_01_01_000050_create_media_tables.php` | media_files, media_permissions, media_key_envelopes, media_ownership_transfers |
| 16b | `2026_07_08_000001_create_media_events_tables.php` | media_events + media_files.media_event_uuid |
| 16c | `2026_07_08_000002_extend_media_events_for_v2_management.php` | event management columns + event_expenses, event_bookings, event_tasks, event_collaborators (v2; unused in app until flag on) |
| 17 | `2026_01_01_000060_create_storage_plans_tables.php` | storage_plans, user_plan_assignments |
| 18 | `2026_01_01_000070_create_audit_logs_table.php` | audit_logs, abuse_reports |
| 19 | `2026_01_01_000080_create_graph_projection_state_table.php` | graph_projection_state |
| 20 | `2026_01_01_000090_create_payment_tables.php` | payment_transactions |
| 21 | `2026_01_01_000014_seed_relationship_edge_types.php` | seed edge types |

## users

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| uuid | char(36) unique | Public API identifier |
| phone | varchar(20) unique | E.164 primary identity |
| phone_verified_at | timestamp nullable | |
| display_name | varchar(255) | |
| name | varchar(255) | Laravel compatibility |
| email | varchar nullable unique | Optional |
| email_verified_at | timestamp nullable | |
| password | varchar | Hashed password (required) |
| is_anonymous | boolean default false | |
| storage_used_bytes | bigint default 0 | Stored (uploaded) bytes |
| storage_read_bytes | bigint default 0 | Cumulative read/egress bytes (`2026_07_15_000001_…`) |
| remember_token | varchar nullable | |
| timestamps | | |
| soft_deletes | | |

**Dev note:** All user columns live in migration `01` — no separate `modify_users` migration. After schema changes in dev, edit the original migration and run `php artisan migrate:fresh`.

## user_phones

Supports phone change and history.

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| user_id | FK users | |
| phone | varchar(20) unique | |
| is_primary | boolean | |
| verified_at | timestamp nullable | |
| revoked_at | timestamp nullable | |
| timestamps | | |

## families

| Column | Type | Notes |
|--------|------|-------|
| uuid | char(36) PK | |
| name | varchar nullable | |
| slug | varchar unique nullable | |
| member_count | int unsigned default 0 | |
| timestamps | | |

## family_members

| Column | Type | Notes |
|--------|------|-------|
| uuid | char(36) PK | |
| family_uuid | FK families | |
| user_id | FK users nullable unique | Set when member registers |
| first_name | varchar | |
| middle_name | varchar nullable | |
| last_name | varchar | |
| maiden_name | varchar nullable | |
| date_of_birth | date nullable | |
| date_of_death | date nullable | |
| birthplace | varchar nullable | |
| gender | enum | male, female, other, unknown |
| is_living | boolean default true | |
| is_anonymous | boolean default false | |
| match_confidence | decimal(5,4) nullable | |
| timestamps | | |
| soft_deletes | | |

## relationship_edge_types (seeded)

| code | is_symmetric | Description |
|------|--------------|-------------|
| parent_of | false | Parent → child |
| spouse_of | true | Marriage/partnership |
| adoptive_parent_of | false | Adoptive parent → child |
| step_parent_of | false | Step-parent → step-child |

Kinship labels (grandson, cousin, in-law) are **computed**, not stored.

## relationship_edges

| Column | Type | Notes |
|--------|------|-------|
| uuid | char(36) PK | |
| from_member_uuid | FK family_members | |
| to_member_uuid | FK family_members | |
| edge_type_id | FK relationship_edge_types | |
| confidence | decimal(5,4) default 1 | |
| created_by_user_id | FK users nullable | |
| timestamps | | |

Unique: (from_member_uuid, to_member_uuid, edge_type_id)

## user_encryption_keys

| Column | Type | Notes |
|--------|------|-------|
| user_id | PK FK users | |
| public_identity_key | binary | X25519 public key |
| encryption_version | tinyint default 1 | |
| rotated_at | timestamp nullable | |
| timestamps | | |

## messages

Stores ciphertext only. Columns: uuid, group_uuid, sender_user_id, encryption_generation, ciphertext (binary), nonce (binary), encryption_version, type (text/media_reference/system), media_file_uuid nullable, timestamps, soft_deletes.

## media_files

Columns: uuid, owner_user_id, uploaded_by_user_id, s3_bucket, s3_key, display_name nullable, size_bytes, mime_type, checksum_sha256, encryption_version, status (pending_upload/active/deleted), timestamps, soft_deletes.

## storage_plans / user_plan_assignments

Plans: uuid, name, description (nullable), slug, quota_bytes, display_price_cents, currency, billing_period (`monthly`|`yearly`), is_active, sort_order.

Assignments: user_id, storage_plan_uuid, source (`admin_manual` / `payment` / `system_default`), assigned_by_user_id, starts_at, ends_at (**required for renewal**; Free = +1 year, others = +1 month), is_active.

## v2 stub tables

- `graph_projection_state` — Neo4j sync tracking (unused v1)
- `payment_transactions` — payment records (unused v1)
