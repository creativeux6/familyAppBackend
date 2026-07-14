# Storage Plans — API Contract

Base path: `/api/v1/storage` (user) and `/api/v1/admin/storage` (admin)

v1: plans are **admin-assigned** (no payment gateway). v2: `PaymentGatewayInterface` for card/bank checkout.

---

## User — GET /storage/quota

Current storage usage and active plan.

**Response 200:**
```json
{
  "quota_bytes": 10737418240,
  "used_bytes": 1048576,
  "remaining_bytes": 10736369664,
  "using_default_quota": false,
  "plan": {
    "uuid": "...",
    "name": "Family",
    "slug": "family",
    "quota_bytes": 10737418240,
    "display_price_cents": 0,
    "currency": "USD"
  },
  "assignment": {
    "starts_at": "2026-06-14T00:00:00Z",
    "ends_at": null,
    "source": "admin_manual"
  }
}
```

If no plan assigned, `using_default_quota: true` and `plan: null` (uses `MEDIA_DEFAULT_QUOTA_BYTES`).

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
  "quota_bytes": 10737418240,
  "display_price_cents": 0,
  "currency": "USD",
  "sort_order": 10
}
```

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
