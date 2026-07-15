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
| When used ≥ quota: **block gallery item access** + uploads; show subscribe CTA | Delete user media/chat automatically to “free space”; silently allow unlimited uploads |
| Keep all stored media/chat on the server when over quota (access gate only) | Soft-block **chat** playback when over quota |

### Limit-reached UX (v1)

Message (user-facing):

> Storage limit reached. Please subscribe to a paid plan.

Until payments ship: same message (subscribe coming soon). **Block opening/downloading gallery items and new uploads.** Do **not** delete files automatically. Users may still **delete** their own items to free **stored** space. Chat (and chat attachments) stay available (reads still count toward usage).

## 3. Quota metering: uploads AND reads (NON-NEGOTIABLE)

S3 (and our API proxy) costs money for **storage** and for **egress (reads)**. Every user’s assigned plan quota must carefully track **both**.

| Component | Column | When it increases | When it decreases |
|-----------|--------|-------------------|-------------------|
| Stored (upload) | `users.storage_used_bytes` | Upload complete / co-owner storage allocation | User deletes or ownership transfers away |
| Read (egress) | `users.storage_read_bytes` | Every download: full file, thumbnail, stream chunk | **Never** (egress already billed) |
| **Combined used** | `stored + read` | — | — |

| Must | Must not |
|------|----------|
| Enforce plan against **combined** `used_bytes = stored_bytes + read_bytes` | Count only uploads and ignore watching/streaming/downloads |
| Meter **every** media transfer through our API (images, videos, files, thumbs, stream chunks) | Issue unmetered direct S3 download URLs that bypass quota |
| Use `StorageQuotaService::chargeReadTransfer()` / `addReadUsage()` on read paths | Forget to charge read on a new download or stream endpoint |
| Expose accurate breakdown in quota APIs: `stored_bytes`, `read_bytes`, `used_bytes` | Report only one total without the combine method |
| Still record chat attachment reads into `storage_read_bytes` | Block chat opens solely because quota is full |

### Implementation map

| Concern | Location |
|---------|----------|
| Free 5 GB seed | `database/seeders/StoragePlanSeeder.php` (`slug=free`) |
| Assign on register (+ backfill on login if missing) | `PlanAssignmentService::ensureDefaultFreePlan`, `PhoneAuthService` |
| Combined quota math | `StorageQuotaService::usedBytes()` = `storedBytes()` + `readBytes()` |
| Add stored | `StorageQuotaService::addStoredUsage()` / `addUsage()` |
| Add read/egress | `StorageQuotaService::addReadUsage()` / `chargeReadTransfer()` |
| Uploads assert | `assertCanStore` (combined remaining) |
| Reads assert (gallery) | `assertCanTransfer` + `assertCanAccessLibrary` |
| Full file / thumb reads | `MediaUploadService::downloadContent` / `downloadThumbnail` |
| Stream chunk reads | `MediaStreamService::downloadChunk` |
| Download URL always API-metered | `MediaUploadService::buildDownloadTarget` |
| Over-quota API flag | `storage/quota` + media library: `over_quota` |
| Gallery lock UI | Mobile media gallery screens |

## 4. Agent / PR checklist

- [ ] Reinstall + same password still unlocks old gallery + chat
- [ ] New register user has Free 5 GB plan assignment (not env default)
- [ ] No new reliance on `MEDIA_DEFAULT_QUOTA_BYTES`
- [ ] At/over quota: uploads fail; gallery open/download blocked with limit-reached message; delete still allowed to free **stored** space; chat still works
- [ ] Every new media download/stream path calls `chargeReadTransfer` (or `addReadUsage`)
- [ ] Quota APIs expose `stored_bytes`, `read_bytes`, and combined `used_bytes`
- [ ] This file still matches code

## Related

- [architecture.md](./architecture.md)
- [07-storage-plans](../07-storage-plans/) (if present) / storage module code
- [12-encryption-and-keys/key-continuity.md](../12-encryption-and-keys/key-continuity.md)
