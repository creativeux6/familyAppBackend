# Environment Variables

## Application

| Variable | Example | Description |
|----------|---------|-------------|
| `APP_NAME` | FamilyApp | Application name |
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

## S3 / MinIO

| Variable | Example | Description |
|----------|---------|-------------|
| `AWS_ACCESS_KEY_ID` | minioadmin | S3 access key |
| `AWS_SECRET_ACCESS_KEY` | minioadmin | S3 secret |
| `AWS_DEFAULT_REGION` | us-east-1 | Region |
| `AWS_BUCKET` | family-app-media | Bucket name |
| `AWS_ENDPOINT` | http://minio:9000 | MinIO endpoint (local) |
| `AWS_USE_PATH_STYLE_ENDPOINT` | true | Required for MinIO |
| `MEDIA_DISK` | s3 / local | Media storage disk |
| `MEDIA_DEFAULT_QUOTA_BYTES` | 5368709120 | Default quota (5 GB) if no plan assigned |
| `MEDIA_KEY_PREFIX` | media | S3 key prefix |

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
