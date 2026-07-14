# Push Notifications Setup (v1 — free)

WhatsApp-style alerts when the app is **closed or in background** use **Firebase Cloud Messaging (FCM)** — free, no per-message cost.

While the app is **open**, **Laravel Reverb** (WebSocket) delivers instant badge updates — also free (self-hosted).

## Architecture

| App state | Mechanism | Cost |
|-----------|-----------|------|
| Foreground / open | Reverb WebSocket + local notifications | Free |
| Background / killed | FCM → Android/iOS system tray + badge | Free |
| Backend trigger | `MessageSent` → queued FCM job | Free |

Paid alternatives (OneSignal, Pusher Beams) are **v2 only** — see [paid-and-v2-services.md](../14-v2-adapters/paid-and-v2-services.md).

---

## 1. Firebase project (one-time, free)

1. Go to [Firebase Console](https://console.firebase.google.com/) → **Add project**.
2. Enable **Cloud Messaging** (enabled by default).
3. Add an **Android app** with package name `com.familyapp.family_app`.
4. Download `google-services.json` → place in `mobile/android/app/google-services.json`.
5. (Optional iOS) Add iOS app, download `GoogleService-Info.plist` → `mobile/ios/Runner/`.
6. Project settings → **Service accounts** → **Generate new private key** (downloads JSON once).
7. Open the JSON and copy **three values** into backend `.env` — **do not commit the JSON file**:

```env
FIREBASE_PROJECT_ID=your-project-id
FIREBASE_CLIENT_EMAIL=firebase-adminsdk-xxxxx@your-project-id.iam.gserviceaccount.com
FIREBASE_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\nMIIEv...paste key here...\n-----END PRIVATE KEY-----\n"
```

The private key must stay in **double quotes** with `\n` where the JSON has line breaks. Laravel is not listed in Firebase — any language download gives the same JSON; we only need these three fields.

8. Run migration if not done: `php artisan migrate`
9. Ensure queue worker runs: `php artisan queue:work` (push jobs are queued).

---

## 2. Flutter — option A: FlutterFire CLI (recommended)

```bash
cd mobile
dart pub global activate flutterfire_cli
flutterfire configure
```

This generates `lib/firebase_options.dart` with real values (replace the dart-define placeholder file).

Rebuild the app.

---

## 2. Flutter — option B: dart-define (CI / no CLI)

Pass Firebase values at build time:

```bash
flutter run \
  --dart-define=FIREBASE_PROJECT_ID=your-project \
  --dart-define=FIREBASE_MESSAGING_SENDER_ID=123456789 \
  --dart-define=FIREBASE_ANDROID_API_KEY=AIza... \
  --dart-define=FIREBASE_ANDROID_APP_ID=1:123:android:abc \
  --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1
```

If Firebase is **not** configured, the app still runs using WebSocket notifications while open.

---

## 3. Android emulator / device

- Copy `mobile/android/app/google-services.json.example` to `google-services.json` and fill from Firebase, or download from console.
- Gradle applies Google Services plugin only when `google-services.json` exists.

```bash
flutter run -d emulator-5554 \
  --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1
```

---

## 4. iOS (real device / TestFlight)

- Requires **Apple Developer Program** (~$99/year) for production push — see [paid-and-v2-services.md](../14-v2-adapters/paid-and-v2-services.md).
- Upload APNs key to Firebase → Project settings → Cloud Messaging.
- `UIBackgroundModes` → `remote-notification` is in `Info.plist`.

---

## 5. Verify end-to-end

1. Start backend: `php artisan serve`, `php artisan queue:work`, `php artisan reverb:start`
2. Log in on two devices/emulators.
3. **App open** on receiver → send message → Chats tab badge updates instantly (WebSocket).
4. **Swipe app away** on receiver → send message → system notification appears (FCM).
5. Tap notification → opens the chat.

API: `POST /api/v1/devices/push-token` (called automatically on login).

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| No background notification | Check `FIREBASE_CREDENTIALS_PATH`, `queue:work` running, token registered |
| No FCM token on Android | Add real `google-services.json`, rebuild |
| Badge not updating | Grant notification permission |
| Duplicate notifications when open | WebSocket handles foreground; FCM handles background only |
