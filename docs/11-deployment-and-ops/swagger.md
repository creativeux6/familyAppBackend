# Swagger / OpenAPI

Interactive API docs and a committed YAML spec that stays in sync with code.

## URLs

| URL | Description |
|-----|-------------|
| http://localhost:8000/api/documentation | Swagger UI (test APIs) |
| http://localhost:8000/swagger | Redirect to Swagger UI |
| `backend/storage/api-docs/api-docs.yaml` | Generated YAML (runtime) |
| [`docs/api/openapi.yaml`](../api/openapi.yaml) | **Committed YAML copy** (update via command below) |

## Keep OpenAPI YAML updated

Run this **after adding or changing any API** (new controllers, parameters, routes):

```bash
cd backend
composer swagger
```

This command:

1. Scans all `#[OA\...]` attributes under `app/`
2. Regenerates `storage/api-docs/api-docs.json` and `api-docs.yaml`
3. Copies YAML to `docs/api/openapi.yaml`

Equivalent manual steps:

```bash
php artisan l5-swagger:generate
cp storage/api-docs/api-docs.yaml ../docs/api/openapi.yaml
```

### When to run

- After adding a new module or endpoint
- After changing request/response parameters in controller annotations
- Before committing API changes (so `docs/api/openapi.yaml` stays current)

### Environment (`.env`)

```
APP_URL=http://localhost:8000          # Also used by Swagger/L5-Swagger host
L5_SWAGGER_GENERATE_ALWAYS=true        # Regenerate on each Swagger UI load (dev)
L5_SWAGGER_GENERATE_YAML_COPY=true     # Write api-docs.yaml alongside json
```

## Test authenticated endpoints in Swagger UI

1. `POST /auth/register` — phone, password, display_name
2. Or `POST /auth/login` — phone + password
3. Copy `access_token` → **Authorize** → `Bearer {token}`

## Module structure & Swagger tags

Each module in `app/Modules/{Name}/` uses a matching Swagger **tag**:

| Module | Tag | Routes file |
|--------|-----|-------------|
| Health | Health | `app/Modules/Health/routes.php` |
| Auth | Auth | `app/Modules/Auth/routes.php` |
| Encryption | Encryption | `app/Modules/Encryption/routes.php` |
| Onboarding | Onboarding | `app/Modules/Onboarding/routes.php` |
| Connections | Connections | `app/Modules/Connections/routes.php` |
| Privacy | Privacy | `app/Modules/Connections/routes.php` |
| FamilyTree | FamilyTree | `app/Modules/FamilyTree/routes.php` |
| Groups | Groups | `app/Modules/Groups/routes.php` |
| Chat | Chat | `app/Modules/Groups/routes.php` |
| Media | Media | `app/Modules/Media/routes.php` |
| StoragePlans | StoragePlans | `app/Modules/StoragePlans/routes.php` |
| Admin | Admin | `app/Modules/Admin/routes.php`, `app/Modules/StoragePlans/routes.php` |

OpenAPI base spec: `app/OpenApi/OpenApiSpec.php`

## Adding a new API to Swagger

1. Add controller with `OpenApi\Attributes` annotations (`#[OA\Post(...)]`, etc.)
2. Add `routes.php` in your module
3. Run `composer swagger`
4. Verify in Swagger UI and commit `docs/api/openapi.yaml`
