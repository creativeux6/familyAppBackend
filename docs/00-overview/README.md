# Family App — Overview

A mobile-first family networking platform. Members register with phone + password, complete a relative questionnaire, get matched to existing families, connect with relatives (or stay anonymous), build groups, chat, and share encrypted media.

## Product pillars

1. **Family discovery** — onboarding questionnaire matches users to existing family trees
2. **Privacy** — anonymity mode; E2E encryption for chat and media
3. **Connections** — connect all, select members, or stay anonymous; change anytime
4. **Groups** — custom groups with any connected family members (repeatable across groups)
5. **Media** — S3 storage with per-file permissions and ownership transfer
6. **Family tree** — dynamic kinship labels (grandson, cousin, in-laws) computed from graph

## Permanent rules (must follow)

**[permanent-product-rules.md](./permanent-product-rules.md)** — no data loss after reinstall; Free **5 GB** via seeder (not env); quota = uploads **+** reads; gallery locked at quota with subscribe message.

## v1 vs v2

| | v1 | v2 |
|---|----|----|
| Database | MySQL only | MySQL + Neo4j |
| Cache/Queue | Laravel database driver | Redis |
| Payments | Admin-assigned plans | Bank/card gateway |
| Clients | Flutter + React admin (after API freeze) | Same |

## Module index

| Module | Folder | Description |
|--------|--------|-------------|
| 00 | `00-overview/` | Vision, architecture, migrations, **[implementation & capacity report](./implementation-capacity-report.md)** |
| 01 | `01-auth-and-roles/` | Phone + password auth, roles, [profile](./01-auth-and-roles/profile-api-contract.md) |
| 02 | `02-onboarding-and-family-matching/` | Questionnaire, matching |
| 03 | `03-connections-and-privacy/` | Connections, anonymity |
| 04 | `04-family-tree/` | Tree API, kinship rules, [manage family info](./04-family-tree/family-info-api-contract.md) |
| 05 | `05-groups-and-chat/` | Groups, direct chat, E2E chat — [API](./05-groups-and-chat/api-contract.md), [client flow](./05-groups-and-chat/client-flow.md) |
| 06 | `06-media-and-s3/` | Encrypted media, permissions |
| 07 | `07-storage-plans/` | Plans, quota |
| 08 | `08-admin-dashboard/` | Admin API |
| 09 | `09-realtime-websockets/` | Reverb channels |
| 10 | `10-flutter-mobile/` | Mobile UX |
| 11 | `11-deployment-and-ops/` | Docker, env, **[commands](./11-deployment-and-ops/commands.md)**, **[production deployment](./11-deployment-and-ops/production-deployment.md)** |
| 12 | `12-encryption-and-keys/` | E2E security — **[key continuity (never lose media/chat)](./12-encryption-and-keys/key-continuity.md)**, [API](./12-encryption-and-keys/api-contract.md) |
| 13 | `13-neo4j-graph-sync/` | v2 graph projection |
| 14 | `14-v2-adapters/` | v2 migration adapters |

## Build order

1. Documentation (this folder + migrations review)
2. Laravel backend APIs (`/api/v1/`)
3. Update OpenAPI: `cd backend && composer swagger`
4. API freeze + tests
5. Flutter + React admin

## OpenAPI YAML

Auto-generated spec: [`docs/api/openapi.yaml`](../api/openapi.yaml)

```bash
cd backend && composer swagger
```

## Glossary

See [glossary.md](./glossary.md).

## Architecture

See [architecture.md](./architecture.md).

## Implementation & capacity report

Baseline audit (Jul 2026) with flow rankings, code/DB ratings, capacity bands, and an improvement backlog checklist:

**[implementation-capacity-report.md](./implementation-capacity-report.md)**

## Database

See [database-migrations.md](./database-migrations.md).
