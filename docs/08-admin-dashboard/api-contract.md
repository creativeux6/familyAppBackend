# Admin Dashboard — API Contract

Base path: `/api/v1/admin` (requires Bearer token + **`admin` role`)

React admin UI (Phase 2) consumes these APIs. Storage plan admin lives under `/admin/storage/*` (see [07-storage-plans](../07-storage-plans/api-contract.md)).

## GET /dashboard

Platform overview metrics.

**Response 200:**
```json
{
  "users_total": 120,
  "users_new_7d": 8,
  "families_total": 45,
  "groups_total": 210,
  "media_files_active": 980,
  "abuse_reports_open": 3
}
```

## GET /users

Paginated user list.

**Query:** `search` (phone or display name), `page`, `per_page` (max 50), `include_trashed` (boolean)

## GET /users/{uuid}

User detail: profile, storage usage, family member link, roles, active plan assignment.

## PATCH /users/{uuid}

Update user fields (admin moderation).

**Request:**
```json
{
  "display_name": "Updated Name",
  "is_anonymous": false
}
```

## DELETE /users/{uuid}

Soft-delete user (ban). Revokes active Sanctum tokens.

## POST /users/{uuid}/restore

Restore a soft-deleted user.

## POST /users/{uuid}/roles

Assign a role.

**Request:** `{ "role": "admin" }`

## DELETE /users/{uuid}/roles/{role}

Remove a role from user.

---

## GET /audit-logs

Paginated audit trail.

**Query:** `action`, `actor_user_uuid`, `page`, `per_page`

## GET /abuse-reports

List abuse reports.

**Query:** `status` — `open` | `reviewing` | `resolved` | `dismissed`

## PATCH /abuse-reports/{uuid}

Update report status.

**Request:**
```json
{
  "status": "resolved"
}
```

---

## Dev access

After `php artisan migrate --seed`, user `+923001234567` / `password` has the `admin` role.

## Status

Implemented in `app/Modules/Admin/`. Swagger tag **Admin**.
