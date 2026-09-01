# Environment Variables

## Application

| Variable | Example | Description |
|----------|---------|-------------|
| `APP_NAME` | Tijori | Application name |
| `APP_ENV` | local / production | Environment |
| `APP_KEY` | base64:... | Laravel encryption key |
| `APP_URL` | http://localhost:8000 | API base URL |
| `APP_DEBUG` | true / false | Debug mode |

## Database (v1)

| Variable | Example | Description |
|----------|---------|-------------|
| `DB_CONNECTION` | mysql | Database driver |
| `DB_HOST` | mysql | MySQL host |
| `DB_PORT` | 3306 | MySQL port |
| `DB_DATABASE` | family_app | Database name |
| `DB_USERNAME` | family_app | Database user |
| `DB_PASSWORD` | secret | Database password |

## Cache & Queue (v1)

| Variable | Example | Description |
|----------|---------|-------------|
| `CACHE_STORE` | database | v1: database; v2: redis |
| `QUEUE_CONNECTION` | database | v1: database; v2: redis |
| `SESSION_DRIVER` | database | Session storage |

## Redis (v2 only)

| Variable | Example | Description |
|----------|---------|-------------|
| `REDIS_HOST` | redis | Redis host |
| `REDIS_PORT` | 6379 | Redis port |

## S3 / MinIO / Backblaze B2

Production media uses **Backblaze B2** (S3-compatible) via the `b2` disk.

| Variable | Example | Description |
|----------|---------|-------------|
| `MEDIA_DISK` | `b2` | Media storage disk (`b2`, `s3`, or `local`) |
| `B2_KEY_ID` | (key id) | B2 application key ID |
| `B2_APPLICATION_KEY` | (secret) | B2 application key |
| `B2_REGION` | `eu-central-003` | B2 region |
| `B2_BUCKET` | `tagori` | Bucket name |
| `B2_ENDPOINT` | `https://s3.eu-central-003.backblazeb2.com` | S3-compatible endpoint |
| `B2_USE_PATH_STYLE_ENDPOINT` | `true` | Required for B2 |
| `MEDIA_KEY_PREFIX` | `tagori/media` | Folder inside the bucket. Objects are `{prefix}/{user_uuid}/{media_uuid}` — never the bucket root |
| `AVATAR_KEY_PREFIX` | `tagori/avatars` | Avatar objects under `{prefix}/…` |
| `B2_STORAGE_USD_PER_GB_MONTH` | `0.006` | Admin estimated storage cost |
| `B2_EGRESS_USD_PER_GB` | `0.01` | Admin estimated download/stream/view cost |
| `MEDIA_ACCESS_SOFT_REMAINING_BYTES` | `536870912` | Soft-gate when remaining ≤ this (default 0.5 GB) |
| `MEDIA_LARGE_FILE_BYTES` | `104857600` | Soft-gate blocks media larger than this (default 100 MB) |

Local MinIO (optional) may still use `MEDIA_DISK=s3` + `AWS_*` for development:

| Variable | Example | Description |
|----------|---------|-------------|
| `AWS_ACCESS_KEY_ID` | minioadmin | S3 access key |
| `AWS_SECRET_ACCESS_KEY` | minioadmin | S3 secret |
| `AWS_DEFAULT_REGION` | us-east-1 | Region |
| `AWS_BUCKET` | family-app-media | Bucket name |
| `AWS_ENDPOINT` | http://minio:9000 | MinIO endpoint (local) |
| `AWS_USE_PATH_STYLE_ENDPOINT` | true | Required for MinIO |

## Reverb (WebSockets)

| Variable | Example | Description |
|----------|---------|-------------|
| `BROADCAST_CONNECTION` | reverb | Broadcast driver |
| `REVERB_APP_ID` | family-app | Reverb app ID |
| `REVERB_APP_KEY` | local-reverb-key | Client key |
| `REVERB_APP_SECRET` | local-reverb-secret | Server secret |
| `REVERB_HOST` | localhost | Client-facing host |
| `REVERB_PORT` | 8080 | Client-facing port |
| `REVERB_SCHEME` | http | http or https |
| `REVERB_SERVER_HOST` | 0.0.0.0 | Reverb bind host |
| `REVERB_SERVER_PORT` | 8080 | Reverb bind port |

Run `php artisan reverb:start` alongside `php artisan serve` and `php artisan queue:work`.

## Push notifications (FCM — free)

Preferred — individual secrets in `.env` (no JSON file on server):

| Variable | Example | Description |
|----------|---------|-------------|
| `FIREBASE_PROJECT_ID` | `my-family-app` | From service account JSON `project_id` |
| `FIREBASE_CLIENT_EMAIL` | `firebase-adminsdk-...@....iam.gserviceaccount.com` | Service account email |
| `FIREBASE_PRIVATE_KEY` | `"-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n"` | PEM key; use `\n` for newlines inside quotes |

Legacy (not recommended): `FIREBASE_CREDENTIALS_PATH` — path to downloaded JSON file.

Requires `php artisan queue:work` for push delivery. See [push-notifications-setup.md](../10-flutter-mobile/push-notifications-setup.md).

## Google Play Billing

| Variable | Example | Description |
|----------|---------|-------------|
| `GOOGLE_PLAY_PACKAGE` | `com.familyapp.family_app` | Android application id |
| `GOOGLE_PLAY_CLIENT_EMAIL` | Play API service account | From Play Console API access |
| `GOOGLE_PLAY_PRIVATE_KEY` | PEM | Use `\n` for newlines |
| `GOOGLE_PLAY_CREDENTIALS_PATH` | `/path/to.json` | Optional JSON file instead of env PEM |
| `GOOGLE_PLAY_RTDN_TOKEN` | random secret | Query token on `/api/v1/webhooks/google-play` |
| `GOOGLE_PLAY_SKU_PERSONAL` | `tijori_personal` | Play subscription product id |
| `GOOGLE_PLAY_SKU_PLUS` | `tijori_plus` | |
| `GOOGLE_PLAY_SKU_PRO` | `tijori_pro` | |
| `PAYMENTS_STUB_SUCCEED` | `true` local / `false` production | Stub gateway for admin/local only |
| `PAYMENTS_ALLOW_CLIENT_PAID_CHANGE` | `false` | Must stay false in production |
| `PLAY_REVIEWER_PHONE` | E.164 | Optional seeded Play reviewer login |
| `PLAY_REVIEWER_PASSWORD` | secret | Optional; never commit |

See [google-play-listing.md](./google-play-listing.md).

## Feature flags

| Variable | Example | Description |
|----------|---------|-------------|
| `GRAPH_DRIVER` | mysql | v1: mysql; v2: neo4j |
| `NEO4J_SYNC_ENABLED` | false | Enable Neo4j projection sync |
| `PAYMENTS_ENABLED` | false | Enable payment gateway |

## Neo4j (v2 only)

| Variable | Example | Description |
|----------|---------|-------------|
| `NEO4J_URI` | bolt://neo4j:7687 | Neo4j connection |
| `NEO4J_USERNAME` | neo4j | Neo4j user |
| `NEO4J_PASSWORD` | secret | Neo4j password |
