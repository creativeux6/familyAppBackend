# Realtime WebSockets (Laravel Reverb)

Group chat uses **private channels** per group. Clients subscribe after authenticating via Sanctum.

See also: [client chat flow](../05-groups-and-chat/client-flow.md) for how the Flutter app handles each event.

## Local development

Terminal 1 — API:
```bash
cd backend && php artisan serve
```

Terminal 2 — Reverb:
```bash
cd backend && php artisan reverb:start
```

Terminal 3 — Queue (FCM push + queued jobs):
```bash
cd backend && php artisan queue:work
```

Or use `composer dev` (runs serve, queue, logs, vite together) **plus** Reverb in a second terminal.

## Environment

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=family-app
REVERB_APP_KEY=local-reverb-key
REVERB_APP_SECRET=local-reverb-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
```

Production: see [production-deployment.md](../11-deployment-and-ops/production-deployment.md) for nginx WebSocket proxy and HTTPS settings.

## Channel auth

`POST /broadcasting/auth` with `Authorization: Bearer {token}`

Body (Pusher protocol): `channel_name=private-group.{groupUuid}&socket_id=...`

Only **group members** may subscribe (`routes/channels.php`).

## Events

All events broadcast on channel `private-group.{groupUuid}` (Pusher name: `private-group.{uuid}`).

| Event | When | Payload |
|-------|------|---------|
| `message.sent` | New encrypted message posted | Full message object (ciphertext base64, sender, type, timestamps) |
| `message.updated` | Own text message edited | Full updated message object |
| `message.deleted` | Own message soft-deleted | `{ "group_uuid", "message_uuid" }` |
| `group.read` | Member marked messages read | Read receipt metadata |
| `group.deleted` | Group/direct chat deleted | Group UUID |

Backend classes: `app/Modules/Groups/Events/*`.

### Mobile handling (`groups_realtime_provider.dart`)

| Event | Open chat | Inbox |
|-------|-----------|-------|
| `message.sent` | Decrypt + append (skip if sender is self) | Unread +1, update last message |
| `message.updated` | Decrypt + replace message row | Update last message if UUID matches |
| `message.deleted` | Mark message deleted in list | Mark last message deleted if UUID matches |
| `group.read` | Refresh read receipts | Clear unread for self |
| `group.deleted` | Remove / refresh | Remove group from list |

## Mobile client flow

1. `GET /groups/realtime/config` — global Reverb connection settings
2. Open WebSocket to Reverb using `key`, `host`, `port`, `scheme`
3. Authorize each `private-group.{uuid}` via `/broadcasting/auth`
4. Listen for events listed above
5. **Fallback:** if Reverb is down, inbox polls every 10 s; open chat polls every 60 s

## Production notes

- Run Reverb as a dedicated Supervisor process (`php artisan reverb:start`)
- Put nginx in front with WebSocket upgrade on `/app/`
- For horizontal scaling, enable Redis-backed Reverb scaling (v2) — see [migration runbook](../14-v2-adapters/migration-runbook.md)
