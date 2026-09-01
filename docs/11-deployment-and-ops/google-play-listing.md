# Google Play listing (Tijori)

Fill Play Console with these answers. Package: `com.familyapp.family_app`. App name: **Tijori** (≤30 characters).

Production API: `https://app.prolampx.com/api/v1`. Privacy: `https://app.prolampx.com/privacy`. Account deletion: `https://app.prolampx.com/account-deletion`.

## Before the first AAB

1. Create an **upload keystore** (once): `mobile/scripts/create_upload_keystore.sh`. Back up `android/app/upload-keystore.jks` and `android/key.properties`. Never commit them.
2. In Play Console: **Play App Signing** (accept; upload the AAB signed with the upload key).
3. Create **subscription** products (base plans, prepaid/auto-renewing monthly):
   - `tijori_personal` — $2.99 / month
   - `tijori_plus` — $4.99 / month
   - `tijori_pro` — $9.99 / month
4. Add **license testers** (your Gmail + reviewer Gmail).
5. Create a Google Cloud **service account** with Financial / Android Publisher access, link it in Play Console → API access, put `GOOGLE_PLAY_CLIENT_EMAIL` + `GOOGLE_PLAY_PRIVATE_KEY` (or `GOOGLE_PLAY_CREDENTIALS_PATH`) on the server.
6. Real-time developer notifications: Pub/Sub push to `https://app.prolampx.com/api/v1/webhooks/google-play?token=YOUR_GOOGLE_PLAY_RTDN_TOKEN`.
7. Production `.env`: `PAYMENTS_STUB_SUCCEED=false`, `PAYMENTS_ALLOW_CLIENT_PAID_CHANGE=false`.
8. Build: `./mobile/scripts/build_release.sh appbundle`.
9. New personal developer accounts usually need **closed testing** (commonly 12 testers / 14 days) before production.

## Store listing

| Field | Value |
| --- | --- |
| App name | Tijori |
| Short description | Private family vault for photos, chat, and your family tree. |
| Full description | Tijori is a private family vault by Prolampx. Keep photos, videos, and files encrypted, chat with relatives, and grow a shared family tree. Optional contacts access finds people already on Tijori (numbers are hashed on device). Paid storage is billed through Google Play. |
| Category | Social (or Lifestyle). **Not** a kids app. |
| Content rating | Complete the questionnaire: **not designed for children**. |
| Privacy policy | https://app.prolampx.com/privacy |
| Account deletion | https://app.prolampx.com/account-deletion (also Profile → Delete account in the app) |
| Graphics | High-res icon (already Tijori). Feature graphic: `mobile/store/feature_graphic.png` (1024×500). 2–8 phone screenshots (tree, chat, gallery, plans). |

## Data safety

Declare collection for **app functionality** only. No ads, no sale of data, no advertising ID.

| Data type | Collected | Shared | Required | Purpose |
| --- | --- | --- | --- | --- |
| Phone number | Yes | No | Yes (account) | Account |
| Name | Yes | With connected family | Optional display | App functionality |
| Contacts | Optional | No (hashes only) | No | Find family on Tijori |
| Photos / videos / files | Yes, only what the user picks | With people they share with | Optional | App functionality |
| Audio (voice notes) | Yes, when they record | Chat members | Optional | App functionality |
| Device or other IDs (FCM token) | Yes | No | Optional (notifications) | App functionality |
| Crash logs / diagnostics | Only if Firebase/Crashlytics is enabled | Google (if used) | Optional | Analytics / crash |

Encrypted media is stored on Backblaze B2. We do not scan the device gallery; users pick files through the system picker.

## Reviewer login

Do **not** give production admin as the only login.

Create a normal user (phone + password) that can open tree, chat, and gallery. Optional seed on the server:

```
PLAY_REVIEWER_PHONE=+92xxxxxxxxxx
PLAY_REVIEWER_PASSWORD=a-strong-password
PLAY_REVIEWER_NAME=Play Reviewer
```

Then `php artisan db:seed --class=UserSeeder`. Put that phone/password in Play Console → App content → App access.

If license testers need a paid plan, use a Play test purchase; do not stub-charge.

## Closed testing notes

- Track: Closed testing.
- Testers: at least 12 Google accounts that opt in via the testing link.
- Leave the AAB on that track for 14 days if Play requires it for a new personal account.
- Internal testing can be used first for license-tester billing checks.
