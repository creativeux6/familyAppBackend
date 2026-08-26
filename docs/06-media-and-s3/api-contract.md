# Media & S3 — API Contract

Base path: `/api/v1/media` (requires Bearer token)

All media is **E2E encrypted on the client** before upload. The server stores **ciphertext only** on S3 (or local disk in dev). Metadata and key envelopes live in MySQL.

## Storage quota

Quota is the user's **assigned storage plan** (Free **5 GB stored** seeded on register). **Stored** and **monthly access** are separate:

- Pool `user_storage_usage.storage_used_bytes` — stored uploads (reclaimable on delete); this is what the plan bar enforces (`users.storage_used_bytes` is the owner's contribution cache)
- Pool `streamed_bytes + downloaded_bytes + file_viewed_bytes` — monthly access (cap = plan `monthly_access_limit_bytes`, snapshot on the open period); not shown in user quota API
- `users.storage_read_bytes` — lifetime egress (admin analytics)

At/over **stored** quota the API rejects gallery uploads/downloads and the app shows “Storage limit reached…”. When monthly access is soft-gated (≤ 0.5 GB remaining), gallery open/download/stream of media **> 100 MB** returns “Too many requests. Please upgrade your subscription.” Chat stays playable unless `billing_status = media_locked`. See [permanent-product-rules.md](../00-overview/permanent-product-rules.md). Production object store: Backblaze B2 via `MEDIA_DISK=b2` + `B2_*`.

---

## POST /media/uploads/initiate

Start an upload. Returns a presigned PUT URL (S3/MinIO) or a direct upload URL (local disk).

**Request:**
```json
{
  "display_name": "family-photo.enc",
  "size_bytes": 1048576,
  "mime_type": "application/octet-stream",
  "checksum_sha256": "abc123...",
  "encryption_version": 1
}
```

**Response 201:**
```json
{
  "uuid": "...",
  "status": "pending_upload",
  "upload_method": "PUT",
  "upload_url": "https://...",
  "upload_headers": {},
  "expires_at": "2026-06-14T20:00:00Z"
}
```

## PUT /media/{uuid}/content

Direct ciphertext upload (local `MEDIA_DISK=local` or when presigned URL points here). Raw binary body.

For large files, prefer **chunked upload** (5 MB parts by default):

| Method | Path | Notes |
|--------|------|-------|
| GET | `/media/{uuid}/upload/status` | Resume progress (`uploaded_parts`, `progress_percent`) |
| PUT | `/media/{uuid}/chunks/{partNumber}` | Upload one chunk (raw binary, max `chunk_size`) |
| DELETE | `/media/{uuid}/upload` | Abort pending upload |
| POST | `/media/{uuid}/complete` | Finalize (assembles S3 multipart or local parts) |

S3/B2 object keys use prefix `{MEDIA_KEY_PREFIX}/{user_uuid}/{media_uuid}` (default `tagori/media/...`). Never store at the bucket root.

## POST /media/{uuid}/complete

Confirm upload finished. Verifies object exists and activates the file.

**Response 200:**
```json
{
  "uuid": "...",
  "status": "active",
  "size_bytes": 1048576
}
```

## GET /media

List files the user owns or has `view` permission on. Response includes:

- `owned` / `shared` — file lists (`media_event_uuid` when organized into an event folder)
- `events` — the user's media event folders (v1). Same records become the hub for **v2 event management** (expenses, bookings) when enabled — see [../14-v2-adapters/event-management.md](../14-v2-adapters/event-management.md).

## Media events (folders)

| Method | Path | Notes |
|--------|------|-------|
| GET | `/media/events` | List event folders |
| POST | `/media/events` | Create folder (`title`, optional date/location/`event_type`) |
| PATCH | `/media/events/{uuid}` | Update metadata |
| DELETE | `/media/events/{uuid}` | Delete folder; files return to General |
| PATCH | `/media/{uuid}/event` | Assign/unassign a file (`media_event_uuid`: uuid or null) |

Event payloads may include `management_enabled` / `management_available` (always false usage in v1 — no expenses/booking UI yet).

## GET /media/{uuid}

File metadata + presigned download URL (if permitted).

## DELETE /media/{uuid}

Soft-delete file (owner only). Frees storage quota.

---

## Permissions

## POST /media/{uuid}/permissions

Grant access to a **connected user** or **group member**.

**Request (user):**
```json
{
  "user_uuid": "...",
  "access": "view"
}
```

**Request (group):**
```json
{
  "group_uuid": "...",
  "access": "view"
}
```

## DELETE /media/{uuid}/permissions/{permissionId}

Revoke permission (owner only).

---

## Key envelopes (E2E)

## POST /media/{uuid}/encryption/envelopes

Owner uploads wrapped content keys for recipients.

**Request:**
```json
{
  "envelopes": [
    {
      "recipient_user_uuid": "...",
      "wrapped_content_key": "base64..."
    }
  ]
}
```

## GET /media/{uuid}/encryption/envelopes/me

Fetch caller's wrapped content key.

---

## Ownership transfer

## POST /media/{uuid}/transfer

Initiate transfer to a **connected** user (they must have quota).

**Request:**
```json
{
  "to_user_uuid": "..."
}
```

## POST /media/transfers/{uuid}/accept

Accept transfer (recipient). Updates owner and quota.

## POST /media/transfers/{uuid}/decline

Decline transfer (recipient).

## POST /media/transfers/{uuid}/cancel

Cancel pending transfer (current owner).

---

## Status

Implemented in `app/Modules/Media/`. Swagger tag **Media**.

See [commands.md](../11-deployment-and-ops/commands.md) for S3/MinIO setup.
