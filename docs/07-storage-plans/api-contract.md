# Storage Plans — API Contract

Product rules (catalog, shared members, upgrade/downgrade, failed payments): [plans-usage-membership-and-payments.md](./plans-usage-membership-and-payments.md)

Base path: `/api/v1/storage` (user) and `/api/v1/admin/storage` (admin)

v1: plans are **admin-assigned**; user self-serve change uses a stub gateway (`PAYMENTS_STUB_SUCCEED`). Live card/bank checkout is later.

---

## User — GET /storage/quota

Current **stored** usage and active plan. Monthly access, stream, download, and cost are **not** returned here.

**Response 200:**
```json
{
  "quota_bytes": 5368709120,
  "stored_bytes": 800000,
  "used_bytes": 800000,
  "remaining_bytes": 5367909120,
  "unlimited": false,
  "over_quota": false,
  "using_default_quota": true,
  "plan": {
    "uuid": "...",
    "name": "Free",
    "slug": "free",
    "quota_bytes": 5368709120,
    "storage_limit_bytes": 5368709120,
    "monthly_access_limit_bytes": 16106127360,
    "display_price_cents": 0,
    "currency": "USD"
  },
  "assignment": {
    "id": 1,
    "starts_at": "2026-06-14T00:00:00Z",
    "ends_at": "2027-06-14T00:00:00Z",
    "source": "system_default",
    "billing_status": "active"
  }
}
```

Every user gets the seeded **Free (5 GB stored / 15 GB monthly access)** plan on register (`system_default`). Caps live on the plan row (and the open `user_storage_usage` snapshot). There is **no** `MEDIA_DEFAULT_QUOTA_BYTES` fallback and **no** `3 × storage` in code — see [permanent-product-rules.md](../00-overview/permanent-product-rules.md).

When `"over_quota": true` (stored ≥ plan), gallery item access is blocked (uploads too); files are retained; chat stays available. When monthly access remaining ≤ 0.5 GB, gallery opens of media **> 100 MB** return: *Too many requests. Please upgrade your subscription.* Failed paid charges can set `billing_status` to `past_due` then `media_locked` (all media including chat).

Admin reset: `POST /admin/users/{uuid}/access-usage/reset`.

## User — GET /storage/plans

List active plans (catalog). Paid rows include `play_product_id` for Google Play Billing.

## User — GET /storage/members · POST /storage/members

Current-cycle shared roster. Cycle starts **owner only**. `POST` with `{ "user_uuid": "..." }` adds a connected user into an empty seat. Members are **locked** for the cycle (no remove/swap).

## User — GET /storage/billing · POST /storage/payments/retry

Billing status (`active` | `past_due` | `media_locked`). Retry is allowed while past due or locked.

## User — POST /storage/play/verify

Android paid plan purchase. `{ "purchase_token", "product_id", "storage_plan_uuid?" }`. Verifies with the Google Play Developer API, then applies the plan (`source=google_play`).

## User — POST /storage/plan-change · POST /storage/plan-change/cancel

Free plan switches (and pending downgrades). Paid SKUs are rejected unless `PAYMENTS_ALLOW_CLIENT_PAID_CHANGE=true`. Android paid plans must use Play Billing.

## Public — POST /api/v1/webhooks/google-play

Play Real-time developer notifications. Optional `?token=` = `GOOGLE_PLAY_RTDN_TOKEN`.

## Public — GET /privacy · GET /account-deletion · DELETE /api/v1/account

Privacy policy, web deletion form, and in-app account deletion.

---

## Admin (requires `admin` role)

## GET /admin/storage/plans

List all plans (including inactive).

## POST /admin/storage/plans

Create a plan.

**Request:**
```json
{
  "name": "Plus",
  "slug": "plus",
  "description": "200 GB stored, 600 GB monthly access. Shared, 4 members + owner.",
  "quota_bytes": 214748364800,
  "storage_limit_bytes": 214748364800,
  "monthly_access_limit_bytes": 644245094400,
  "streaming_limit_bytes": 536870912000,
  "download_limit_bytes": 214748364800,
  "file_view_limit_bytes": 644245094400,
  "is_shared": true,
  "max_shared_members": 4,
  "display_price_cents": 499,
  "currency": "USD",
  "billing_period": "monthly",
  "sort_order": 30
}
```

Fields: **plan name**, **description**, **storage** (`storage_limit_bytes` / `quota_bytes`), **monthly access** and stream/download/view sub-caps, **shared seats**, **price**, **billing period** (`monthly` | `yearly`).

- **Free** plan: `billing_period=yearly` (forced)
- Other plans: default `monthly`
- Assignments always get `ends_at` = next **billing** date. `php artisan storage:renew-plans` (daily) advances that date, rolls the usage period, applies pending downgrades, and resets the shared roster to the owner. `payments:retry-past-due` (hourly) retries failed charges.
- **Storage quota does not reset on billing.** Stored bytes carry forward. Monthly **access** meters reset when the period rolls.

Seed **Free / Personal / Plus / Pro** via `StoragePlanSeeder`.

## PATCH /admin/storage/plans/{uuid}

Update plan fields. Set `is_active: false` to hide from catalog. Open usage rows for that plan refresh their limit snapshot.

## GET /admin/storage/users/{userUuid}/assignment

Get user's active assignment (includes pending downgrade and billing status).

## POST /admin/storage/users/{userUuid}/assign

Change the user's plan with the same upgrade-now / downgrade-next-cycle rules as the user endpoint.

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

Admin dashboard and user detail include pool stored/access/stream/download/view plus estimated B2 cost vs plan revenue.

---

## Dev admin user

After `php artisan migrate --seed`, user `+923001234567` has the `admin` role.

## Status

Implemented in `app/Modules/StoragePlans/`. Swagger tags **StoragePlans** and **Admin**.
