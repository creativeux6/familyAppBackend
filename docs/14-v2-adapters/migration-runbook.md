# v1 → v2 Migration Runbook

## Prerequisites

- v1 running stable on MySQL
- All APIs tested under `/api/v1/`
- Database backup taken

## Step 1 — Add Neo4j

1. Add Neo4j container to Docker Compose
2. Deploy `Neo4jFamilyGraphRepository`
3. Set `NEO4J_SYNC_ENABLED=true` (keep `GRAPH_DRIVER=mysql` initially)
4. Run `php artisan graph:backfill-neo4j`
5. Verify `graph_projection_state` rows are `synced`
6. Run contract tests: MySQL vs Neo4j tree output must match
7. Set `GRAPH_DRIVER=neo4j`
8. Monitor; keep MySQL as write source of truth

## Step 2 — Add Redis

1. Add Redis container
2. Update `.env`:
   ```
   CACHE_STORE=redis
   QUEUE_CONNECTION=redis
   SESSION_DRIVER=redis
   ```
3. Restart queue workers and Reverb
4. No code changes required

## Step 3 — Enable payments

1. Implement `CardPaymentGateway` for chosen provider
2. Set `PAYMENTS_ENABLED=true`
3. `payment_transactions` table already exists from v1 migrations
4. Admin can still override via `ManualPlanGateway`

## Rollback

| Change | Rollback |
|--------|----------|
| Neo4j | Set `GRAPH_DRIVER=mysql` |
| Redis | Set drivers back to `database` |
| Payments | Set `PAYMENTS_ENABLED=false` |

No schema rollback needed — v1 tables remain source of truth.
