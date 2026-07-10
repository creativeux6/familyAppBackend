# v2 Adapters

Interfaces and config switches that allow v2 infrastructure (Neo4j, Redis, payments) without rewriting business logic.

## FamilyGraphRepositoryInterface

```php
interface FamilyGraphRepositoryInterface
{
    public function findMember(string $uuid): ?FamilyMemberNode;
    public function createMember(FamilyMemberData $data): FamilyMemberNode;
    public function createEdge(RelationshipEdgeData $data): void;
    public function subtree(string $memberUuid, TreeViewMode $mode, int $maxDepth = 6): TreeResult;
    public function resolveKinship(string $viewerMemberUuid, string $targetMemberUuid, TreeViewMode $mode): KinshipLabel;
    public function findFamilyCluster(string $memberUuid): array;
    public function validateMatch(MatchCandidate $candidate): MatchValidation;
}
```

| Driver | Class | When |
|--------|-------|------|
| `mysql` | `MysqlFamilyGraphRepository` | v1 (default) |
| `neo4j` | `Neo4jFamilyGraphRepository` | v2 |

Config: `config/graph.php` → `GRAPH_DRIVER` env.

## PaymentGatewayInterface

```php
interface PaymentGatewayInterface
{
    public function assignPlan(User $user, StoragePlan $plan, ?User $assignedBy = null): void;
    public function isEnabled(): bool;
}
```

| Implementation | When |
|----------------|------|
| `ManualPlanGateway` | v1 — admin assigns plans |
| `CardPaymentGateway` | v2 — bank/card provider |

Config: `config/features.php` → `PAYMENTS_ENABLED` env.

## Cache & Queue

Always use Laravel facades — never `Redis::` in domain code:

```php
Cache::remember("tree:{$uuid}:{$mode}", 300, fn () => $this->graph->subtree(...));
SyncGraphProjectionJob::dispatch($memberUuid);
```

v2: change `.env` only:
```
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
```

## Graph projection events

v1: `SyncGraphProjectionListener` is registered but no-ops when `NEO4J_SYNC_ENABLED=false`.

v2: enable sync, run `php artisan graph:backfill-neo4j`, flip `GRAPH_DRIVER=neo4j`.

See [migration-runbook.md](./migration-runbook.md).

## Paid services (v2 only)

v1 uses free/self-hosted options. Recurring or usage-based SaaS is documented in **[paid-and-v2-services.md](./paid-and-v2-services.md)** (OneSignal, Stripe, Neo4j Aura, Redis Cloud, production AWS, etc.).

## Event management (v2)

Media **events** are photo/video folders in v1. The same `media_events` row (plus `event_expenses`, `event_bookings`, `event_tasks`, `event_collaborators`) is the foundation for wedding/tour/party planning in v2.

See **[event-management.md](./event-management.md)**. Flag: `EVENT_MANAGEMENT_ENABLED` (default off). Adapter: `EventManagementServiceInterface` → `NullEventManagementService` until a full implementation is ready.
