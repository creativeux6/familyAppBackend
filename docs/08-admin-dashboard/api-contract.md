# Admin / Web Panel — API Contract

Base path for operator APIs: `/api/v1/admin`  
**Required:** Bearer token + Spatie role **`super_admin` or `admin`**.

Web panel UI lives at **`/web`** (Laravel + React SPA). Mobile continues to use the same `/api/v1` feature APIs. Legacy `/panel` redirects to `/web`.

## Roles (Spatie)

| Role | Panel home | Can open Logs | Notes |
|------|------------|---------------|--------|
| `super_admin` | Stats dashboard | Yes | Can assign `super_admin` role |
| `admin` | Stats dashboard | Yes | Cannot assign `super_admin` |
| `user` | App-style home | No | Default for self-register |

Self-register (`POST /auth/register`) **always** assigns `user`. Admin/super_admin are never created from the register page.

### Auth additions (panel + mobile)

| Method | Path | Auth | Notes |
|--------|------|------|--------|
| GET | `/auth/me` | Bearer | User + `roles[]` + `permissions[]` |
| POST | `/auth/forgot-password` | Public | `{ phone }` — local env may return `reset_token` |
| POST | `/auth/reset-password` | Public | `{ phone, token, password, password_confirmation }` |
| POST | `/auth/login` | Public | Response includes roles; send `X-Client: web` for panel token name |
| POST | `/auth/register` | Public | Always creates `user` role |

---

## GET /admin/dashboard

Platform overview metrics (unchanged).

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

---

## System logs (Phase 1)

Every `/api/*` response is written to `system_error_logs` (success **2xx** and failures **4xx/5xx**), including duration and a truncated JSON body when present.  
Client-reported failures that never reach Laravel (e.g. nginx **413**) are stored via `POST /client-errors`.  
Admin log list/detail endpoints themselves are excluded to avoid recursion.

### GET /admin/system-logs

**Query:** `q` (search message/exception/path/status), `path`, `status_code`, `user_uuid`, `from`, `to`, `page`, `per_page` (default **20**, max **20**)

**Status codes list:** `GET /admin/system-logs/status-codes` → `{ "status_codes": [200, 401, 422, ...] }` for dropdown filters.

Web UI (`/web/logs`): status-code **dropdown** (All + common + seen codes), colored HTTP badges (e.g. **200 Success**), and always-visible pagination (First / Prev / Next / Last) at **20 per page**.

**Response 200:** paginated `{ data: [...], meta: {...} }`  
Each row: `uuid`, `occurred_at`, `method`, `path`, `status_code`, `exception_class`, `message`, `user` `{ uuid, display_name, phone } | null`.

### GET /admin/system-logs/{uuid}

Full detail including `trace`, `request_id`, `ip_address`.

### GET /admin/websocket-health

Returns overall status plus a **per-socket listing**.

**Response 200:**
```json
{
  "status": "ok|degraded|down",
  "checked_at": "...",
  "summary": { "total": 8, "ok": 6, "degraded": 1, "down": 1 },
  "sockets": [
    {
      "id": "reverb_server",
      "name": "Reverb server",
      "type": "tcp",
      "description": "Laravel Reverb process (TCP bind)",
      "endpoint": "127.0.0.1:8080",
      "status": "ok|degraded|down",
      "latency_ms": 3,
      "message": "Port is reachable"
    }
  ],
  "connection": {
    "host": "127.0.0.1",
    "client_host": "localhost",
    "port": 8080,
    "client_port": 8080,
    "scheme": "http",
    "reachable": true,
    "app_key_set": true,
    "broadcast_driver": "reverb",
    "auth_endpoint": "https://.../broadcasting/auth"
  },
  "log_path": "/var/log/familyapp/reverb.log",
  "log_readable": false,
  "recent_errors": []
}
```

Socket checks include: Reverb server TCP, Reverb client endpoint, broadcasting auth HTTP, groups realtime config API, `private-group.*` / `private-user.*` channel registration, broadcast driver + app key.

Optional env: `REVERB_LOG_PATH` for Reverb log tailing.

Web UI lives at **`/web`** (legacy `/panel` redirects here).

---

## Users / audit / abuse (existing)

Same as before under `/admin/users`, `/admin/audit-logs`, `/admin/abuse-reports`.

### POST /admin/users/{uuid}/roles

**Request:** `{ "role": "admin" | "user" | "super_admin" }`  
`super_admin` may only be assigned by an actor who already has `super_admin`.

Storage plan admin: `/admin/storage/*` (see [07-storage-plans](../07-storage-plans/api-contract.md)) — same `super_admin|admin` gate.

---

## Phase 2 (admin console — built)

**Admins / super_admin (UI at `/web`):**

- **Users** (`/web/users`) — list/search, ban/restore, assign storage plan — API `/admin/users/*`, `/admin/storage/users/.../assign`
- **Storage plans** (`/web/plans`) — CRUD for plan name, description, data limit (GB), price — API `/admin/storage/plans`
- **System logs** — status dropdown + colored badges + 20/page pagination

**Still later (user web console):** gallery, events, calendar, connections, groups — same mobile `/api/v1` APIs.
