# Storage Plans — API Contract

Base path: `/api/v1/storage` (user) and `/api/v1/admin/storage` (admin)

v1: plans are **admin-assigned** (no payment gateway). v2: `PaymentGatewayInterface` for card/bank checkout.

---

## User — GET /storage/quota

Current storage usage and active plan.

**Response 200:**
```json
{
  "quota_bytes": 5368709120,
  "stored_bytes": 800000,
  "read_bytes": 248576,
  "used_bytes": 1048576,
  "remaining_bytes": 5367660544,
  "unlimited": false,
  "over_quota": false,
  "using_default_quota": true,
  "plan": {
    "uuid": "...",
    "name": "Free",
    "slug": "free",
    "quota_bytes": 5368709120,
    "display_price_cents": 0,
    "currency": "USD"
  },
  "assignment": {
    "starts_at": "2026-06-14T00:00:00Z",
    "ends_at": null,
    "source": "system_default"
  }
}
```

Every user gets the seeded **Free (5 GB)** plan on register (`system_default`). Quota is always from the assigned plan (admin can change plans later). There is **no** `MEDIA_DEFAULT_QUOTA_BYTES` fallback — see [permanent-product-rules.md](../00-overview/permanent-product-rules.md).

**Metering:** `stored_bytes` = uploads held; `read_bytes` = cumulative S3/API egress (full file / thumbnail / stream chunks); `used_bytes` = **stored + read** (this is what the plan enforces). See permanent product rules §3.

When `"over_quota": true`, gallery item access is blocked (uploads too); files are retained; chat stays available. Payments = next versions.

## User — GET /storage/plans

List active plans (catalog for display; assignment is admin-only in v1).

---

## Admin (requires `admin` role)

## GET /admin/storage/plans

List all plans (including inactive).

## POST /admin/storage/plans

Create a plan.

**Request:**
```json
{
  "name": "Family",
  "slug": "family",
  "description": "Shared family media with 10 GB combined upload and read quota.",
  "quota_bytes": 10737418240,
  "display_price_cents": 0,
  "currency": "USD",
  "sort_order": 10
}
```

Fields: **plan name**, **description**, **data limit** (`quota_bytes`), **price** (`display_price_cents` + `currency`).

Seed Free (5 GB), Family (10 GB), Premium (50 GB) via `StoragePlanSeeder`.

## PATCH /admin/storage/plans/{uuid}

Update plan fields. Set `is_active: false` to hide from catalog.

## GET /admin/storage/users/{userUuid}/assignment

Get user's active assignment.

## POST /admin/storage/users/{userUuid}/assign

Assign a plan to a user (deactivates previous active assignment).

**Request:**
```json
{
  "storage_plan_uuid": "...",
  "starts_at": "2026-06-14T00:00:00Z",
  "ends_at": null
}
```

## POST /admin/storage/assignments/{id}/revoke

Deactivate an assignment.

---

## Dev admin user

After `php artisan migrate --seed`, user `+923001234567` has the `admin` role.

## Status

Implemented in `app/Modules/StoragePlans/`. Swagger tags **StoragePlans** and **Admin**.
