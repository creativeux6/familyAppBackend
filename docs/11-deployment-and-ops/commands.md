# Application Commands Reference

Single source of truth for commands used in Family App development and operations. **Update this file whenever a new command or workflow is added.**

**Production deployment:** [production-deployment.md](./production-deployment.md) (nginx, Supervisor, SSL, capacity).

All paths assume project root unless noted. Backend commands run from `backend/`.

---

## First-time setup

```bash
cd backend
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

Install PHP dependencies:

```bash
cd backend && composer install
```

Copy and configure `.env` — see [env-variables.md](./env-variables.md) for MySQL, Reverb, and S3/MinIO.

---

## Daily development

### Option A — one terminal (recommended)

```bash
cd backend && composer dev
```

Runs concurrently:

| Process | Command | Port |
|---------|---------|------|
| API | `php artisan serve` | 8000 |
| Queue | `php artisan queue:listen --tries=1 --timeout=0` | — |
| Logs | `php artisan pail --timeout=0` | — |
| Vite | `npm run dev` | 5173 |

**Also start Reverb** in a second terminal for realtime chat:

```bash
cd backend && php artisan reverb:start
```

### Calendar notifications (local)

When you run `php artisan serve` (or `composer dev`) with `APP_ENV=local`, the app automatically starts Laravel’s scheduler in the background. You do **not** need system cron or a separate terminal.

| What | When |
|------|------|
| Scheduled job | `calendar:send-notifications` runs daily at **12:00 AM** (server timezone) |
| Manual test | `php artisan calendar:send-notifications` — run anytime to test pushes |

The scheduled command sends both **3-day-ahead reminders** and **day-of birthday / memorial / anniversary** wishes in one pass.

**Production:** use Supervisor `schedule:work` (see [production-deployment.md](./production-deployment.md#4-supervisor--reverb-queue-and-scheduler)) — not `php artisan serve`.

### Option B — separate terminals

```bash
cd backend && php artisan serve
cd backend && php artisan reverb:start
cd backend && php artisan queue:work
cd backend && php artisan pail
```

---

## Database

```bash
cd backend && php artisan migrate
cd backend && php artisan migrate --seed
cd backend && php artisan migrate:fresh --seed   # dev only — wipes data
cd backend && php artisan db:seed
cd backend && php artisan db:seed --class=UserSeeder
```

---

## API documentation (Swagger / OpenAPI)

**Run after any API change:**

```bash
cd backend && composer swagger
```

Equivalent:

```bash
cd backend && php artisan l5-swagger:generate
cd backend && php scripts/sync-openapi.php
```

Swagger UI: http://localhost:8000/api/documentation

---

## Authentication (dev)

Seeded users (`php artisan migrate --seed`):

| Phone | Password |
|-------|----------|
| `+923001234567` | `password` | Test User (also has **admin** role) |
| `+923009876543` | `password` | Ali Khan |

Login: `POST /api/v1/auth/login`

Seeded plans: **Free** (1 GB), **Family** (10 GB), **Premium** (50 GB). All dev users get the **Family** plan.

### User journey (after login)

1. **Encryption setup** — generate X25519 keys, upload public key
2. **Onboarding** — family questionnaire → match result → confirm family
3. **Connections** — suggestions, connect all/selected, accept/reject, anonymity toggle
4. **Family tree** — view relatives with kinship labels (blood / in-laws / all)
5. **Groups** — create encrypted group chat with connected members
6. **Home shell** — bottom nav (Home, Tree, Groups, Connect)

### Admin API

Requires Bearer token from admin user (`+923001234567`). Base path: `/api/v1/admin/*`

| Endpoint | Purpose |
|----------|---------|
| `GET /admin/dashboard` | Platform stats |
| `GET /admin/users` | User list |
| `GET/PATCH/DELETE /admin/users/{uuid}` | User detail, update, ban |
| `POST /admin/users/{uuid}/restore` | Restore banned user |
| `POST/DELETE /admin/users/{uuid}/roles` | Assign/remove admin role |
| `GET /admin/audit-logs` | Audit trail |
| `GET/PATCH /admin/abuse-reports/{uuid}` | Abuse report queue |
| `GET/POST/PATCH /admin/storage/*` | Storage plans (see module 07) |

See [../08-admin-dashboard/api-contract.md](../08-admin-dashboard/api-contract.md).

---

## Realtime WebSockets (Laravel Reverb)

```bash
cd backend && php artisan reverb:start
```

Channel auth: `POST /broadcasting/auth` with Bearer token.

See [../09-realtime-websockets/README.md](../09-realtime-websockets/README.md).

---

## Queue worker

Required for queued jobs (broadcast fallbacks, future async media verification):

```bash
cd backend && php artisan queue:work
cd backend && php artisan queue:work --tries=3
cd backend && php artisan queue:listen --tries=1 --timeout=0
```

v1 driver: `QUEUE_CONNECTION=database` (no Redis).

---

## S3 / MinIO (encrypted media)

### Local MinIO (Docker)

```bash
cd backend && docker compose up -d
```

MinIO console: http://localhost:9001 — create bucket `family-app-media` (private).

`.env`:

```env
FILESYSTEM_DISK=s3
MEDIA_DISK=s3
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=family-app-media
AWS_ENDPOINT=http://127.0.0.1:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
```

### Without S3 (local disk fallback)

```env
MEDIA_DISK=local
```

Media stored under `storage/app/private/media/` — direct upload via API.

See [../06-media-and-s3/api-contract.md](../06-media-and-s3/api-contract.md).

---

## Testing

```bash
cd backend && composer test
cd backend && php artisan test
cd backend && php artisan test --filter=AuthTest
```

---

## Code quality

```bash
cd backend && ./vendor/bin/pint
```

---

## Composer scripts (backend)

| Script | Command | Purpose |
|--------|---------|---------|
| `composer setup` | install + key + migrate + npm build | Initial project setup |
| `composer dev` | serve + queue + pail + vite | Local dev stack |
| `composer swagger` | regenerate OpenAPI YAML | After API changes |
| `composer test` | run PHPUnit | Tests |

---

## Laravel Artisan (common)

```bash
php artisan route:list
php artisan route:list --path=api/v1/media
php artisan config:clear
php artisan cache:clear
php artisan optimize:clear
php artisan tinker
```

---

## Module build checklist

When adding a new API module:

1. Implement `app/Modules/{Name}/`
2. Run `composer swagger`
3. Update `docs/{module}/api-contract.md`
4. Add routes to [swagger.md](./swagger.md) tag table
5. **Update this file** if new commands are introduced

---

## Flutter mobile (`mobile/`)

Docs: [../10-flutter-mobile/README.md](../10-flutter-mobile/README.md)

### First-time setup

Install Flutter 3.x if needed (macOS):

```bash
git clone https://github.com/flutter/flutter.git -b stable --depth 1 ~/development/flutter
echo 'export PATH="$HOME/development/flutter/bin:$PATH"' >> ~/.zshrc
source ~/.zshrc
```

Then:

```bash
cd mobile
bash scripts/setup.sh
```

Creates platform folders (`android/`, `ios/`, …) if missing, then runs `flutter pub get`.

### Run app

Start backend first: `cd backend && composer dev`

```bash
# iOS Simulator / macOS
cd mobile && flutter run --dart-define=API_BASE_URL=http://localhost:8000/api/v1

# Android Emulator
cd mobile && flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1
```

### Tests

```bash
cd mobile && flutter test
```

---

## URLs (local)

| Service | URL |
|---------|-----|
| API | http://localhost:8000/api/v1 |
| Swagger UI | http://localhost:8000/api/documentation |
| Health | http://localhost:8000/api/v1/health |
| Reverb | ws://localhost:8080 |
| MinIO console | http://localhost:9001 |
