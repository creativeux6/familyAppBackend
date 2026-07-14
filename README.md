# Family App API

Laravel 13 backend — installed via Composer (official installer).

**All commands:** [`../docs/11-deployment-and-ops/commands.md`](../docs/11-deployment-and-ops/commands.md)

## Quick start

```bash
cd backend
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
composer dev          # API + queue + logs + vite (see commands.md for Reverb)
php artisan reverb:start
```

### Push notifications (optional, free — WhatsApp-style when app is closed)

1. Create a [Firebase project](https://console.firebase.google.com/) (free).
2. **Service accounts → Generate new private key** — copy these three fields into `.env` (do **not** commit the JSON file):

   ```env
   FIREBASE_PROJECT_ID=your-project-id
   FIREBASE_CLIENT_EMAIL=firebase-adminsdk-xxxxx@your-project-id.iam.gserviceaccount.com
   FIREBASE_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\nMIIE...\n-----END PRIVATE KEY-----\n"
   ```

   Use `\n` for line breaks inside the private key (keep the double quotes).

3. Keep **`php artisan queue:work`** running (push jobs are queued).
4. Mobile: add `google-services.json` to `mobile/android/app/` (see [push-notifications-setup.md](../docs/10-flutter-mobile/push-notifications-setup.md)).

Without Firebase, chat still works with **instant badges while the app is open** (Reverb WebSocket).

Swagger UI: http://localhost:8000/api/documentation

After API changes: `composer swagger`

## Docs

- Module specs: [`../docs/`](../docs/)
- Production deployment: [`../docs/11-deployment-and-ops/production-deployment.md`](../docs/11-deployment-and-ops/production-deployment.md)
- Chat client flow: [`../docs/05-groups-and-chat/client-flow.md`](../docs/05-groups-and-chat/client-flow.md)
