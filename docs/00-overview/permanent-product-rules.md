# Permanent product rules (NON-NEGOTIABLE)

These rules must never be weakened by “quick fixes”, env toggles, or reinstall side effects.
Agents and humans must keep behavior aligned with this file.

## 1. No user data loss (chat + gallery)

Encrypted media and chat exist only as ciphertext. Without recoverable encryption keys, that data is **gone forever**.

| Must | Must not |
|------|----------|
| Survive app reinstall, updates, and bug-fix releases | Mint new identity keys when a server key-backup already exists |
| Auto restore/create key backup with **account password** on login/register | Treat reinstall as “new encryption identity” |
| Keep content unlockable for the account password holder | Move chat/media plaintext to Firebase (or similar) as a continuity shortcut |

Details: [12-encryption-and-keys/key-continuity.md](../12-encryption-and-keys/key-continuity.md)

## 2. Storage: Free plan 5 GB by default (plans from admin)

| Must | Must not |
|------|----------|
| Seed a **Free** plan with **5 GB** (`5 * 1024^3` bytes) | Use `MEDIA_DEFAULT_QUOTA_BYTES` (or any env) as the free-tier quota source of truth |
| Assign Free plan to **every new registered user** | Leave new users with `quota_bytes = 0` / unlimited by default |
| Let admins change plans/quotas in the **admin plans** UI | Hard-code paid Stripe/payment in v1 (payment flow is **next versions**) |
| Plans have a **billing period** (when **price** is charged): Free = **yearly**, others = **monthly** by default | Leave assignments with `ends_at = null` (no next bill date) |
| Set `ends_at` = next billing date from the plan period; advance it via `storage:renew-plans` | Reset **storage quota** or wipe `storage_used_bytes` on bill cycle |
| Assigned **quota stays the plan’s `quota_bytes`** for the whole assignment — only price renews on the interval | Treat billing renewal as “fresh storage” or a new quota grant |
| When **stored** ≥ quota: **block gallery item access** + uploads; show subscribe CTA | Delete user media/chat automatically to “free space”; silently allow unlimited uploads |
| Keep all stored media/chat on the server when over quota (access gate only) | Soft-block **chat** playback when over **storage** quota |

### Limit-reached UX (v1)

Message (user-facing, storage full):

> Storage limit reached. Please subscribe to a paid plan.

Until payments ship: same message (subscribe coming soon). **Block opening/downloading gallery items and new uploads.** Do **not** delete files automatically. Users may still **delete** their own items to free **stored** space. Chat (and chat attachments) stay available.

## 3. Quota metering: stored vs monthly access (NON-NEGOTIABLE)

Object storage (B2/S3) costs money for **storage** and for **egress (reads)**. Plans enforce them separately:

| Component | Column / meter | Cap | User-visible | Reset |
|-----------|----------------|-----|--------------|-------|
| Stored (upload) | Pool `user_storage_usage.storage_used_bytes` (contribution cache: `users.storage_used_bytes`) | Plan `storage_limit_bytes` / `quota_bytes` (Free = 5 GB) | **Yes** | Delete / ownership transfer only |
| Monthly access (egress) | Pool `streamed_bytes + downloaded_bytes + file_viewed_bytes` | Plan `monthly_access_limit_bytes` (snapshot on the open period) | **No** | Auto monthly (`storage:renew-plans`) + **admin manual reset** |
| Lifetime egress | `users.storage_read_bytes` | — (analytics) | Admin only | Never |

| Must | Must not |
|------|----------|
| Enforce **uploads** against **stored** only (`assertCanStore`) | Combine lifetime read + stored into the user-facing plan bar |
| Meter every media transfer through our API into period + lifetime counters | Issue unmetered direct download URLs that bypass metering |
| Soft-gate when monthly access remaining ≤ 0.5 GB: block gallery open/download/stream only if media `size_bytes` > 100 MB | Show access GB used/remaining in the mobile storage UI |
| Warn (push) near 2 GB and 1 GB remaining on the monthly access pool — friendly upgrade copy only | Soft-block chat solely because monthly access is soft-gated |
| Keep files ≤ 100 MB openable even when soft-gated | Hard-block all gallery media when access soft-gated |

### Soft-gate message (large files)

> Too many requests. Please upgrade your subscription.

### Implementation map

| Concern | Location |
|---------|----------|
| Free 5 GB seed | `database/seeders/StoragePlanSeeder.php` (`slug=free`) |
| Assign on register (+ backfill on login if missing) | `PlanAssignmentService::ensureDefaultFreePlan`, `PhoneAuthService` |
| Stored quota | `StorageQuotaService::quotaBytes` / `storedBytes` / `assertCanStore` (pool) |
| Monthly access | Open `user_storage_usage` snapshot (`monthly_access_limit_bytes`) |
| Soft large-file gate | `assertCanOpenMedia` → `MediaUploadService::downloadContent`, `MediaStreamService` |
| Period roll + plan renew | `storage:renew-plans` → `renewDueAssignments` + `payments:retry-past-due` |
| Payment lock | `billing_status = media_locked` on the pool assignment |
| Admin reset | `POST /admin/users/{uuid}/access-usage/reset` |
| User quota API | `storage/quota` — **stored only** (pool bar) |
| Admin user detail | `adminSummary` — pool bars, stream/download/view, B2 cost |
| Object store | `MEDIA_DISK=b2` + `B2_*`; prefixes from `MEDIA_KEY_PREFIX` / `AVATAR_KEY_PREFIX` |

## 4. Agent / PR checklist

- [ ] Reinstall + same password still unlocks old gallery + chat
- [ ] New register user has Free 5 GB plan assignment (not env default)
- [ ] No new reliance on `MEDIA_DEFAULT_QUOTA_BYTES`
- [ ] At/over **stored** quota: uploads fail; gallery open/download blocked with limit-reached message; delete still allowed; chat still works
- [ ] Monthly access soft-gate: only gallery media > 100 MB blocked; ≤ 100 MB and chat still work
- [ ] Every new media download/stream path calls `chargeReadTransfer`
- [ ] User-facing quota APIs expose stored usage only; admin shows monthly access + reset
- [ ] Media disk uses `B2_*` (or documented local/MinIO for dev), not production AWS keys for media
