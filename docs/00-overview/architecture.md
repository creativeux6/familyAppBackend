# Architecture

## Stack (v1)

| Layer | Technology |
|-------|------------|
| API | Laravel 13, PHP 8.3+ |
| Auth | Phone + password, Laravel Sanctum |
| Roles | spatie/laravel-permission |
| DB | MySQL 8.4 |
| Cache/Queue | Laravel `database` driver |
| Realtime | Laravel Reverb |
| Media | AWS S3 / MinIO (ciphertext only) |
| Mobile | Flutter 3.x (Phase 2) |
| Admin | React + Vite (Phase 2) |

## v1 architecture

```
Flutter / React Admin
        │
        ▼
  Laravel API (/api/v1/)
        │
   ┌────┴────┬──────────┐
   ▼         ▼          ▼
 MySQL    MinIO/S3   Reverb
```

## Adapter pattern (v1 → v2)

| Concern | v1 | v2 |
|---------|----|----|
| Graph reads | `MysqlFamilyGraphRepository` | `Neo4jFamilyGraphRepository` |
| Cache | `CACHE_DRIVER=database` | `CACHE_DRIVER=redis` |
| Queue | `QUEUE_CONNECTION=database` | `QUEUE_CONNECTION=redis` |
| Payments | `ManualPlanGateway` | `CardPaymentGateway` |

Config flags in `config/graph.php` and `config/features.php`. No controller changes on v2 upgrade.

## Domain layers (Laravel)

```
HTTP Controllers (thin)
    → Actions / Services
        → Repositories (FamilyGraphRepositoryInterface)
        → Eloquent Models
        → Events → Listeners → Jobs
```

## API versioning

All routes: `/api/v1/*`. Breaking changes require `/api/v2/`.

## Security model

- Phone + password for authentication (SMS OTP planned for v2)
- Sanctum bearer tokens for API
- E2E encryption for message body and media blobs
- Server stores: ciphertext, metadata, public encryption keys, wrapped key envelopes
- Admin sees metadata only — never plaintext content
- **Key continuity (required):** identity private keys are backed up encrypted with the account password so gallery and chat stay unlockable after reinstall — see [12-encryption-and-keys/key-continuity.md](../12-encryption-and-keys/key-continuity.md)
- **Free storage (required):** seeded Free plan = **5 GB**, assigned on register; quotas from plans only (not env). Usage = **stored uploads + reads/egress**. Over quota → gallery locked + subscribe message. [permanent-product-rules.md](./permanent-product-rules.md)

## Key services

| Service | Responsibility |
|---------|----------------|
| `PhoneAuthService` | Register, login, sessions |
| `FamilyGraphRepository` | Graph CRUD, tree traversal |
| `KinshipResolverService` | Compute grandson, cousin, in-law labels |
| `FamilyMatcherService` | Onboarding fuzzy match |
| `KeyEnvelopeService` | Store/fetch encryption key envelopes |
| `StorageQuotaService` | Enforce plan limits |

## Event flow (v2-ready)

```
FamilyMemberCreated → SyncGraphProjectionListener (no-op v1, Neo4j sync v2)
RelationshipEdgeCreated → SyncGraphProjectionListener
MessageStored → BroadcastMessageEnvelope (Reverb)
```
php artisan calendar:send-notifications