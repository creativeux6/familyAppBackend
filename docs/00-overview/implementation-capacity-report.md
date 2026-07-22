# Family App — Implementation & Capacity Report

**Date:** July 2026  
**Scope:** v1 stack (Laravel 13 / Flutter / MySQL / Reverb / S3)  
**Purpose:** Baseline for improvement planning. Capacity figures are **heuristics** from [production-deployment.md](../11-deployment-and-ops/production-deployment.md), not load-test results.

| Metric | Value |
|--------|-------|
| Comfortable DAU (chat + media) | 50 – 200 |
| Registered users (1 VPS) | 300 – 1,000 |
| Overall implementation | **7.2 / 10** |
| DB structure | **7.5 / 10** |

**Capacity confidence:** Assumes a single VPS (~2 vCPU / 4 GB, php-fpm `max_children` ≈ 20, DB-backed cache + queue, one Reverb process). Chat + video streaming is the limiting workload; light browse usage scales higher.

Related: [architecture.md](./architecture.md) · [permanent-product-rules.md](./permanent-product-rules.md) · [v2 migration runbook](../14-v2-adapters/migration-runbook.md)

---

## 1. Stack snapshot

| Layer | Technology | Role |
|-------|------------|------|
| Mobile | Flutter 3.3+ · Riverpod · Dio · cryptography | E2E client, gallery, chat, onboarding |
| API | Laravel 13 · Sanctum · Spatie roles | REST `/api/v1` · ~143 routes |
| Admin | React + Vite in Laravel `public/build` | Users, plans, system logs |
| Database | MySQL 8.x · 48 migrations | Graph, chat meta, quota counters |
| Cache / queue (v1) | database driver | Jobs, cache — Redis planned v2 |
| Realtime | Laravel Reverb · private channels | Chat events · nginx `/app/` proxy |
| Media | S3/MinIO ciphertext · API proxy | 5 MB upload chunks · 256 KB stream |
| Push | FCM | Message / connection / family alerts |

---

## 2. Product flows — ranked by criticality

Rank reflects business risk if broken (data loss, lockout, abuse) and daily usage weight.

| Rank | Flow | Path (client → API → store) | Status | Maturity |
|------|------|-----------------------------|--------|----------|
| P0 | Auth + durable keys | Login/Register → `PhoneAuthService` → `ensureDurableIdentityKeys` → `users` + `key_backups` | Live | Strong |
| P0 | Abuse protection | `throttle:auth-*` + phone lockout → 429 | Live | Strong |
| P0 | Onboarding / join by code | JoinChoice → lookup/join → FamilyMatcher / FamilyTree → families, members, edges | Live | Good |
| P1 | Connections | ConnectionsScreen → ConnectionService → `connections` | Live | Good |
| P1 | Groups / encrypted chat | GroupChat → GroupMessageService + envelopes → messages + Reverb | Live | Good |
| P1 | Media gallery / stream / quota | Upload worker → chunks → stream package → MediaStreamService + StorageQuotaService | Live | Good* |
| P2 | Family tree / kinship | FamilyTreeScreen → FamilyTreeService → `relationship_edges` | Live | Good |
| P2 | Calendar | CalendarScreen → CalendarService → reminders + schedule | Live | Adequate |
| P2 | Admin ops | `/web` → Admin* → users, plans, `system_error_logs` | Live | Good |
| P3 | Devices / FCM | Push token → `device_push_tokens` → queue → FCM | Live | Adequate |
| — | Events / payments / Neo4j | Null adapters · v2 docs | Stubbed | Not v1 |

\*Media: streaming + thumbs recently hardened; older uploads without `has_stream` still full-download. Client crypto + S3 metering are intentional.

### End-to-end happy paths

**New user:** Register → Free 5 GB plan → durable key backup (account password) → join by code / find family → relation pick → connections → home. Keys restored on every login before auth completes — no second password prompt on normal sign-in.

**Chat + media day:** Open group → decrypt envelopes → send (optimistic pending → confirm) → Reverb broadcast · Upload video → encrypt → chunks → thumb + 256 KB stream package → play via Range proxy · quota = stored + read.

---

## 3. Capacity — how many users before choking?

Assumptions: ~5–20 msgs/user/day, ~30% DAU, avg group size 5 (see production-deployment capacity guide).

### Single VPS (2 vCPU / 4 GB) — comfortable

| Metric | Comfortable range |
|--------|-------------------|
| Registered users | 300 – 1,000 |
| Daily active (chat + media) | 50 – 200 |
| Light browse DAU | can sit higher in band; API concurrency ~100–300 |
| Concurrent WebSockets | 200 – 800 |
| Sustained message sends | 5 – 20 / sec |
| Busy concurrent (chat+media) | ~50 – 150 |

### Scaled (aspirational — needs Redis+)

| Metric | Comfortable range |
|--------|-------------------|
| Registered users | 5,000 – 20,000 |
| Concurrent WebSockets | 2,000 – 8,000 |

Requires: `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, Reverb scale-out, unread-query optimization. **Confidence: low** — architecture plan, not measured.

### Bottlenecks (when it starts choking)

| # | Bottleneck | Symptom | Mitigation |
|---|------------|---------|------------|
| 1 | MySQL + DB queue/cache | Slow inbox, job lag | Redis (v2) |
| 2 | Unread COUNT per group | Groups list latency | Denormalize / composite index |
| 3 | Reverb single process | WS drops under many connections | Scale Reverb / sticky sessions |
| 4 | php-fpm `max_children` ≈ 20 | API queueing / 502 | Raise pm + more cores |
| 5 | API-proxied media streams | Quota + CPU on each 256 KB chunk | CDN carefully / cache |
| 6 | Client E2E crypto | Phone heat on large galleries | Expected; not server CPU |

---

## 4. Implementation ratings

Scores are judgmental (1–10) from structure, docs alignment, tests, and operational readiness — not automated static analysis.

**Weighted overall ≈ 7.2/10 · DB structure ≈ 7.5/10**

| Area | Score | Notes |
|------|-------|-------|
| Architecture / modularity | 8/10 | 14 modules; interface for graph; v2 adapters sketched |
| Auth & abuse control | 8/10 | Sanctum + IP/phone throttles + lockout; no SMS OTP yet |
| Encryption / key continuity | 9/10 | Non-negotiable restore path; KeyBackupTest present |
| Chat + WebSockets | 7.5/10 | Optimistic send fixed; unread queries need work |
| Media + streaming + quota | 7.5/10 | Chunk stream + metering; older files may lack stream |
| Onboarding / matching | 7/10 | Code-join UX improved; matcher CPU grows with tree |
| Admin panel | 7.5/10 | Users/plans/logs/privacy-aware view |
| Automated tests | 4/10 | Critical gap for launch confidence |
| Ops & documentation | 8/10 | Strong runbooks; capacity heuristics published |
| DB structure | 7.5/10 | See §5 |

### Code — what works well

- Modular Laravel (`app/Modules/*`) with clear service boundaries
- Flutter feature folders + Riverpod providers
- Permanent product rules (keys, Free 5 GB, stored+read quota)
- E2E ciphertext-only media/messages; password-bound key backup
- Auth rate limits + enumeration-resistant messages
- ~35 doc files covering contracts and deployment

### Code — gaps / debt

- Tests thin: ~6 backend feature files · 0 unit suite · few Flutter tests
- Media/onboarding/calendar/admin largely untested
- DB-backed queue/cache under load (Redis planned)
- Group unread N+1 documented
- Events/payments/Neo4j still stubs
- Some doc lag (admin “Phase 2” vs shipped UI)

---

## 5. Database structure rating — 7.5/10

| Metric | Count |
|--------|-------|
| App tables (approx) | ~45 |
| Migrations | 48 |
| Public IDs | UUID+ (users still bigint FK) |

| Table cluster | Strengths | Watch-outs |
|---------------|-----------|------------|
| users / phones / tokens | Soft deletes, phone indexes, plan counters on user | Token TTL null (never expire) — rotate policy optional |
| families / members / edges | UUID PKs, member_code unique, name+DOB indexes | Matching still SQL-bound; Neo4j is v2 |
| connections | Pair uniqueness, status indexes | Direct-chat lookup can N+1 |
| groups / messages / envelopes | `(group, created_at)` index, soft delete, generations | Unread COUNT pattern; consider denorm counters |
| media_files / stream / permissions | Owner+status index, ciphertext keys, library items | Read metering hot path on every chunk |
| storage_plans / assignments | Active assignment index, billing period | Usage never auto-resets (by design) |
| encryption keys / backups | Active backup index, continuity rules | Password change vs re-wrap is hard case |
| system_error_logs | occurred_at + status/path indexes | Volume grows fast — monitor retention |

---

## 6. Security posture (current)

| In place | Gap / note |
|----------|------------|
| E2E ciphertext | — |
| Key backup on login | — |
| Auth IP/phone rate limits | Tunables: `config/security.php`, `AUTH_*_PER_*` env |
| Login lockout (8 fails) | — |
| Anti-enumeration messages | — |
| Quota = stored + read | — |
| Security headers middleware | — |
| Sanctum bearer | Token TTL policy optional |
| — | No SMS OTP yet |

---

## 7. Quantitative inventory

| Metric | Count |
|--------|-------|
| Backend modules | 14 |
| Module PHP services (approx) | ~55 |
| API route declarations | ~143 |
| Migrations | 48 |
| Mobile Dart files (`lib`) | ~180 |
| Mobile feature areas | 13 |
| Backend Feature tests | 6–8 files |
| Mobile tests | ~4 |
| Doc topical folders | 14 + api |

---

## 8. Recommendations — improvement backlog

Use this checklist when planning sprints. Check items off as they ship; re-score §4 after major work.

| Priority | Action | Why | Status |
|----------|--------|-----|--------|
| 1 | Redis for cache + queue | Removes main shared bottleneck with MySQL | [ ] |
| 2 | Fix unread aggregation | Groups list is first chat choke point | [ ] |
| 3 | Expand Feature tests (media, stream, onboarding) | Launch confidence | [ ] |
| 4 | Load-test Reverb + php-fpm | Replace heuristics with measured ceilings | [ ] |
| 5 | Backfill stream packages for old videos | Quota + UX for full downloads | [ ] |
| 6 | Token TTL / refresh policy | Limits stolen-token window | [ ] |

### Suggested planning order

1. **Stability & confidence** — items 3 (tests), then 2 (unread).
2. **Scale foundations** — item 1 (Redis), then 4 (load test to set real ceilings).
3. **UX / security polish** — items 5 (stream backfill), 6 (token TTL).
4. **v2 product** — Neo4j, payments, events (see [14-v2-adapters](../14-v2-adapters/README.md)).

---

## Bottom line

v1 is a coherent private-beta system: modular backend, E2E mobile crypto, metered media, and real chat. On one VPS it comfortably serves on the order of **tens to low hundreds of daily active chat/media users** (hundreds registered). It will choke earlier under always-on WebSockets + video streaming than under light browse. Scale past that needs **Redis**, **unread optimization**, and **Reverb capacity** — not just a bigger single PHP box.
