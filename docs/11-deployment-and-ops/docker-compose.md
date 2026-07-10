# Docker Compose (v1)

Local development stack: MySQL, MinIO, Reverb. No Neo4j or Redis in v1.

## Services

| Service | Port | Purpose |
|---------|------|---------|
| mysql | 3306 | Primary database |
| minio | 9000, 9001 | S3-compatible media storage |
| reverb | 8080 | WebSockets |
| app | 8000 | Laravel API (optional container) |

## Usage

```bash
cd backend
docker compose up -d
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
php artisan queue:work
php artisan reverb:start
```

## MinIO setup

1. Open http://localhost:9001 (minioadmin / minioadmin)
2. Create bucket `family-app-media`
3. Set bucket policy private

## v1 queue worker

Required for async jobs (graph events, media verification):

```bash
php artisan queue:work --tries=3
```

Full command reference: [commands.md](./commands.md)

Uses `database` queue driver — no Redis needed.

## v2 additions

Add to same compose file when scaling:
- `redis` service
- `neo4j` service

See `docs/14-v2-adapters/migration-runbook.md`.
