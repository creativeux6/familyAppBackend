# Event Management (v2)

Family **media events** double as the foundation for full event management in v2.

## Product intent

| Phase | What events are |
|-------|-----------------|
| **v1 (now)** | Private media **folders** (wedding album, tour photos, party clips). Per-file share rights stay independent of the folder. |
| **v2 (later)** | Same event UUID becomes a **planning workspace**: expenses, bookings, tasks, collaborators — attached to the same media festival record. |

No management UI or APIs are exposed in the mobile app until `EVENT_MANAGEMENT_ENABLED=true` and a real `EventManagementServiceInterface` implementation is wired.

## Shared event record (`media_events`)

v1 fields (already used by private media):

- `title`, `description`, `event_date`, `location`
- media files linked via `media_files.media_event_uuid`

v2 foundation columns (added; defaults keep v1 behavior):

| Column | Purpose |
|--------|---------|
| `event_type` | `general`, `wedding`, `tour`, `party`, `religious`, `other` |
| `status` | `draft`, `planned`, `active`, `completed`, `archived` |
| `starts_at` / `ends_at` | Full schedule (hotels, ceremony windows) |
| `timezone` | Local event timezone |
| `currency` | Default for expenses/booking costs |
| `management_enabled` | Owner opts this event into planning features |
| `management_meta` | Extensible JSON for v2 settings |
| `notes` | Free-form planning notes |

## Child tables (schema only in v1)

| Table | Role |
|-------|------|
| `event_expenses` | Spend lines (venue, food, travel) |
| `event_bookings` | Hotels, flights, vendors, confirmations |
| `event_tasks` | To-dos / checklists with assignee |
| `event_collaborators` | Co-planners (`owner` / `editor` / `viewer`) |

Models: `EventExpense`, `EventBooking`, `EventTask`, `EventCollaborator`.

## Adapter

```php
interface EventManagementServiceInterface
{
    public function enableManagement(User $user, MediaEvent $event, array $data = []): MediaEvent;
    public function summarize(User $user, MediaEvent $event): array;
    public function addExpense(...): array;
    public function addBooking(...): array;
    public function addTask(...): array;
    public function inviteCollaborator(...): array;
}
```

| Implementation | When |
|----------------|------|
| `NullEventManagementService` | v1 default — rejects with a clear “reserved for v2” message |
| Full service (to build) | v2 — bind in `AppServiceProvider` when `EVENT_MANAGEMENT_ENABLED=true` |

Config: `config/features.php` → `EVENT_MANAGEMENT_ENABLED` (default `false`).

## Enabling v2 (runbook sketch)

1. Run migrations (already includes event management tables).
2. Set `EVENT_MANAGEMENT_ENABLED=true`.
3. Implement and bind a real `EventManagementServiceInterface`.
4. Add `/api/v1/events/{uuid}/expenses|bookings|tasks|collaborators` routes (new module; do not mix with chat “groups”).
5. Mobile: show management tabs only when `management_available && management_enabled` on the event payload.
6. Existing v1 media folders keep working unchanged — optional “Turn on planning” toggles `management_enabled`.

## Important product rules

- **Media permissions stay per-file.** Putting a photo in an event folder does **not** grant expense/booking access, and vice versa.
- **Chat groups remain separate.** Event management is not the same as encrypted family chat groups.
- **Ghost / anonymous users** may still own private media events; sharing management collaborators should use the same connection rules as media share when implemented.

See also: [../06-media-and-s3/api-contract.md](../06-media-and-s3/api-contract.md), [README.md](./README.md).
