# Flutter Mobile — Module 10

Mobile client for Family App. Consumes Laravel API at `/api/v1/*` (see per-module contracts in `docs/01-*` through `docs/12-*`).

## Stack

| Concern | Package / approach |
|---------|-------------------|
| UI | Flutter 3.x, Material 3 |
| State | Riverpod |
| Routing | go_router |
| HTTP | Dio |
| Token storage | flutter_secure_storage |
| Realtime | web_socket_channel + Reverb (+ polling fallback) |
| Emoji / GIF chat | emoji_picker_flutter, Giphy (optional `GIPHY_API_KEY`) |

## Repo layout

```
mobile/
├── lib/
│   ├── main.dart
│   ├── app.dart
│   ├── core/                     # config, network, routing, theme, storage
│   ├── shared/                   # reusable widgets & utils
│   └── features/                 # one folder per backend module
│       ├── auth/                 # 01-auth-and-roles
│       ├── profile/              # profile API (see 01-auth profile contract)
│       ├── onboarding/           # 02-onboarding
│       ├── connections/          # 03-connections-and-privacy
│       ├── family_tree/          # 04-family-tree
│       ├── groups/               # 05-groups-and-chat
│       ├── media/                # 06-media (chat attachments; gallery planned)
│       ├── storage/              # 07-storage-plans
│       └── encryption/           # 12-encryption-and-keys
```

### Feature folder convention

```
features/{name}/
├── data/           # API clients + repositories
├── models/
├── providers/      # Riverpod
└── presentation/   # Screens + widgets
```

**Rule:** Screens never call Dio directly — use `data/*_repository.dart`.

## API alignment

| Docs module | API base | Flutter feature |
|-------------|----------|-----------------|
| 01 Auth | `/auth` | `features/auth` |
| 01 Profile | `/profile` | `features/profile` |
| 02 Onboarding | `/onboarding` | `features/onboarding` |
| 03 Connections | `/connections`, `/privacy` | `features/connections` |
| 04 Family tree | `/family-tree` | `features/family_tree` |
| 05 Groups & chat | `/groups` | `features/groups` |
| 06 Media | `/media` | `features/media` |
| 07 Storage | `/storage` | `features/storage` |
| 12 Encryption | `/encryption` | `features/encryption` |

Paths: `core/network/api_paths.dart`.

## Environment

```bash
flutter run --dart-define=API_BASE_URL=http://localhost:8000/api/v1
# Optional GIF search:
flutter run --dart-define=GIPHY_API_KEY=your_key
```

## v1 feature status

| Feature | Status |
|---------|--------|
| Core + shared UI | Done |
| Auth (login/register/session) | Done |
| Profile (self edit, anonymity) | Done |
| Encryption (auto identity keys) | Done |
| Onboarding (questionnaire + match) | Done |
| Connections + privacy | Done |
| Family tree viewer + manage family | Done |
| Groups + direct chat + E2E messaging | Done |
| Chat extras (emoji, GIF, edit/delete, reply, voice, files) | Done |
| Realtime (Reverb + polling fallback) | Done |
| Push notifications (FCM, background/killed app) | Done — requires Firebase setup |
| Local notifications + app icon badge | Done |

**Chat client flow (E2E, optimistic UI, reply, voice):** [../05-groups-and-chat/client-flow.md](../05-groups-and-chat/client-flow.md)
| Storage quota display (profile) | Done |
| Media gallery (encrypted upload/list/view/delete) | Done |
| Group settings (rename, members, leave/delete) | Done |
| Encryption key backup UI (profile) | Done |
| React admin client | Planned (later) |

## Build order (remaining v1)

1. API freeze + expand backend test coverage
2. React admin dashboard (later)

See [architecture.md](./architecture.md) for layering details.

Push setup (free FCM): [push-notifications-setup.md](./push-notifications-setup.md). Paid alternatives: [paid-and-v2-services.md](../14-v2-adapters/paid-and-v2-services.md).
