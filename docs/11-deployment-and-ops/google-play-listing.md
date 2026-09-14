# Google Play listing (Tijori Cloud)

Fill Play Console with these answers. Package: `com.prolampx.tijori`. App name: **Tijori Cloud** (≤30 characters).

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
8. Build: `./build_play.sh` (auto-bumps version code).
9. New personal developer accounts usually need **closed testing** (commonly 12 testers / 14 days) before production.

## Store listing (copy into Play Console)

Google Play has **no separate keywords field**. Search uses **App name + short description + full description**. Paste below into **Grow users → Store presence → Main store listing**.

| Field | Limit | Value |
| --- | --- | --- |
| App name | 30 | `Tijori Cloud` |
| Short description | 80 | see below |
| Full description | 4000 | see below |
| App category | — | **Productivity** (primary). Tags / secondary: Social or Communication if offered. **Not** a kids app. |
| Content rating | — | Questionnaire: **not designed for children**. |
| Privacy policy | — | https://app.prolampx.com/privacy |
| Account deletion | — | https://app.prolampx.com/account-deletion (also Profile → Delete account) |
| Contact email / website | — | Your Prolampx support email + https://app.prolampx.com |
| Graphics | — | High-res icon (from app logo). Feature graphic: `mobile/store/feature_graphic.png` (1024×500). Phone screenshots: gallery/storage, share/chat, family tree, plans (2–8 shots). |

### Short description (≤80 characters) — paste exactly

```text
Private cloud storage to save, share with anyone, chat, and build your tree.
```

(75 characters.)

### Full description — paste exactly

```text
Tijori Cloud by Prolampx is private cloud storage for everyone — friends, family, teammates, or any group you choose.

Save photos, videos, and files in one secure place. Share storage with the people you invite. Chat in groups. Optionally build a family tree — all in one app.

CLOUD STORAGE
• Upload photos, videos, documents, and other files
• Private media library with optional storage plans
• Access your files from your phone whenever you need them

SHAREABLE STORAGE
• Share albums, folders, and media with anyone you invite
• Control who can see what you upload
• Keep important files and memories in one shared space

CHAT
• Group chats for friends, family, or any circle
• Voice notes and media in conversation
• Stay connected without mixing private chats into public social apps

CONNECTIONS & FAMILY TREE
• Connect with people already on Tijori Cloud
• Optional family tree to organize relatives when you need it
• Invite friends or family on your terms

WHO IT’S FOR
Anyone who wants private storage, easy sharing, and chat — with friends, family, or both — without ads and without selling your data.

Privacy policy: https://app.prolampx.com/privacy
Delete your account anytime in the app (Profile → Delete account) or at https://app.prolampx.com/account-deletion

Paid storage upgrades are billed through Google Play.
```

### Search phrases covered (naturally, not stuffed)

Ideas for screenshot captions / feature graphic labels: **cloud storage**, **private storage**, **share files**, **shared storage**, **group chat**, **secure cloud**, **photo storage**, **family tree** (optional feature).

Do **not** paste a comma-separated keyword list into the description — Play can demote keyword stuffing.

### Screenshot captions (optional, under each phone shot)

1. Private cloud storage for your photos & files  
2. Share storage with friends or family  
3. Group chat for your circle  
4. Optional family tree when you need it  
5. Choose a storage plan that fits you  

## Data safety

Declare collection for **app functionality** only. No ads, no sale of data, no advertising ID.

| Data type | Collected | Shared | Required | Purpose |
| --- | --- | --- | --- | --- |
| Phone number | Yes | No | Yes (account) | Account |
| Name | Yes | With people you connect with | Optional display | App functionality |
| Contacts | Optional | No (hashes only) | No | Find people already on Tijori Cloud |
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
