# Paid & v2-Only Services

v1 uses **free tiers and self-hosted** options only. Anything with recurring cost, usage-based billing, or paid SaaS tiers belongs here until v2.

## Notifications & realtime

| Service | Cost | v1 (free) alternative | v2 when |
|---------|------|---------------------|---------|
| **Firebase Cloud Messaging (FCM)** | Free | ✅ v1 default for background push | — |
| **Apple Push Notification service (APNS)** | Free delivery | ✅ via FCM | — |
| **Apple Developer Program** | ~$99/year | Emulator/simulator only for iOS push testing | Production iOS App Store + real device push |
| **OneSignal** | Free tier → paid | FCM + Laravel backend | Managed push analytics, A/B campaigns |
| **Pusher Beams** | Paid tiers | Self-hosted **Laravel Reverb** + FCM | Fully managed push without own backend |
| **AWS SNS** (mobile push) | Pay per million | FCM HTTP v1 from Laravel | AWS-native multi-channel at scale |

## Infrastructure

| Service | Cost | v1 alternative | v2 when |
|---------|------|----------------|---------|
| **Neo4j Aura** | Paid cloud | MySQL graph (`GRAPH_DRIVER=mysql`) | Large family graphs, complex kinship queries |
| **Redis Cloud** | Paid tiers | Database cache/queue (`CACHE_STORE=database`) | High-throughput cache, Redis queues |
| **Managed Laravel hosting** (Forge, Cloudways) | Monthly | Local Docker / `php artisan serve` | Production SLA |
| **AWS S3** (production) | Usage-based | MinIO local / `FILESYSTEM_DISK=local` | Production media at scale |
| **CloudFront / CDN** | Usage-based | Direct S3/MinIO | Global media delivery |

## Payments & billing

| Service | Cost | v1 alternative | v2 when |
|---------|------|----------------|---------|
| **Stripe** | Per transaction | `ManualPlanGateway` (admin assigns plans) | Self-serve plan upgrades |
| **PayPal / regional gateways** | Per transaction | Manual plans | Local payment methods |
| **RevenueCat** | Paid tiers | N/A | Mobile subscription management |

## Third-party APIs

| Service | Cost | v1 alternative | v2 when |
|---------|------|----------------|---------|
| **Giphy API** | Free tier (rate limits) | Optional `--dart-define=GIPHY_API_KEY` | Higher GIF search volume |
| **Twilio SMS** (OTP) | Per SMS | Password auth only (v1) | Phone OTP login |
| **SendGrid / Mailgun** | Free tier → paid | Log driver / no email | Email verification, invites |

## Analytics & monitoring

| Service | Cost | v1 alternative | v2 when |
|---------|------|----------------|---------|
| **Sentry** | Free tier → paid | Laravel logs / Pail | Error tracking in production |
| **Datadog / New Relic** | Paid | Local logs | APM at scale |
| **Firebase Analytics** | Free | None in v1 | Product analytics |

## Admin & ops

| Service | Cost | v1 alternative | v2 when |
|---------|------|----------------|---------|
| **React admin hosting** | Hosting cost | Not built yet | Separate admin deployment |
| **Bugbot / CI minutes** | Plan-dependent | Local `php artisan test` | PR automation at scale |

---

## v1 notification stack (all free)

```
App open     → Laravel Reverb (WebSocket) → instant badge + tray
App closed   → FCM (free) → OS notification + app icon badge
Backend      → Self-hosted Reverb + optional FCM service account JSON
Mobile       → firebase_messaging + flutter_local_notifications + app_badge_plus
```

Setup: [../10-flutter-mobile/push-notifications-setup.md](../10-flutter-mobile/push-notifications-setup.md)

## Decision rule

- **Use in v1** if: free tier with no credit card, or self-hosted (Reverb, MinIO, MySQL, FCM).
- **Defer to v2** if: recurring SaaS fee, usage billing that grows with users, or requires paid Apple/Google developer accounts for production-only features.

## Event planning (product v2 — no paid SaaS required)

Event **media folders** already exist. Full planning (expenses, bookings, tasks) is schema + adapter ready but **not** shown in the app yet. See [event-management.md](./event-management.md).

See also: [README.md](./README.md) (Neo4j, Redis, payments adapters).
