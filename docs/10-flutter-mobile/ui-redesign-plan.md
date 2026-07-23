# Flutter UI Redesign Plan

**Status:** Active  
**Design source:** [`stitch_family_app_ui_design/`](../../../stitch_family_app_ui_design/)  
**Tokens:** Light = [Kinship & Hearth](../../../stitch_family_app_ui_design/kinship_hearth/DESIGN.md) · Dark = [Nocturnal Hearth](../../../stitch_family_app_ui_design/nocturnal_hearth/DESIGN.md)  
**Stitch prompt:** [stitch-design-prompt.md](./stitch-design-prompt.md)

---

## 1. Non-negotiable rules

1. **UI only** — providers, API contracts, encryption, routing, and business rules stay unchanged unless a phase explicitly adds an approved feature (e.g. avatars).
2. **Design missing something we already have** — keep the feature; restyle to match Kinship / Nocturnal.
3. **Design adds something new** — add to **Discuss first** (§6); do not implement until approved.
4. **Prefer synced Stitch folders** when duplicates exist (`login_light_mode_updated`, `login_dark_mode_synced`, `manage_family_*_synced`, `member_detail_*_synced`).
5. **Dark mode is client-only** — `ThemeMode` stored on device; no backend preference field.
6. **Generic screen naming** — use feature labels (`Gallery`, `Storage`, `Calendar`), not product-name prefixes. Product display name only via `AppConfig.appName`.

---

## 2. Design system (Phase 0)

| Token | Light (Kinship) | Dark (Nocturnal) |
|-------|-----------------|------------------|
| Primary | `#006D77` / `#00535b` | `#9ce7e0` / `#80cbc4` |
| Surface / background | `#faf9f5` | `#121414` |
| On-surface | `#1b1c1a` | `#e2e2e2` |
| Secondary accent | seafoam / teal | amber `#ffb95a` (sparingly) |
| Fonts | Epilogue (display) + Plus Jakarta Sans (body) | Epilogue (all levels OK) |

**Flutter targets**

- **[`mobile/lib/core/theme/app_design.dart`](../../../mobile/lib/core/theme/app_design.dart)** — **single place** for colors, fonts, spacing, radii
- [`mobile/lib/core/theme/app_theme.dart`](../../../mobile/lib/core/theme/app_theme.dart) — `light()` / `dark()` ThemeData from those tokens
- [`mobile/lib/core/theme/theme_mode_provider.dart`](../../../mobile/lib/core/theme/theme_mode_provider.dart) — SharedPreferences
- Shared: `AppAvatar`, buttons/fields consume `ColorScheme`

Legacy `AppColors.indigo*` aliases map to the new primary so unmigrated screens pick up teal immediately; later phases replace hardcodes with `Theme.of(context).colorScheme`.

---

## 3. Screen inventory (Stitch ↔ app)

| App screen | Route | Light Stitch | Dark Stitch | Phase |
|------------|-------|--------------|-------------|-------|
| Login | `/login` | `login_light_mode_updated` | `login_dark_mode_synced` | 1 |
| Register | `/register` | `register_family_app` | *(derive)* | 1 |
| Encryption setup | `/encryption/setup` | *(derive)* | `encryption_setup_restore_dark` | 1 |
| Join choice | `/onboarding/join` | `join_choice_family_app` | `join_choice_dark_mode` | 2 |
| Join by code | `/onboarding/join-code` | `join_by_code_family_app` | `join_by_code_dark_mode` | 2 |
| Family details | `/onboarding/family-details` | `family_details_family_app` | *(derive)* | 2 |
| Find family | `/onboarding/find-family` | `find_family_family_app` | *(derive)* | 2 |
| Home | `/home` | `home_family_app` | `home_dark_mode` | 3 |
| Connections | `/connections` | `connections_family_app` | *(derive)* | 3 |
| Chats list | `/groups` | `chats_family_app` | `chats_dark_mode` | 3 |
| Family tree | `/family-tree` | `family_tree_family_app` | `family_tree_dark_mode` | 4 |
| Manage family | `/family-tree/manage` | `manage_family_light_synced` | `manage_family_dark_synced` | 4 |
| Member detail | `/family-tree/members/:id` | `member_detail_light_synced` | `member_detail_dark_synced` | 4 |
| Create group | `/groups/create` | `create_group_family_app` | `create_group_dark_mode` | 5 |
| Group chat | `/groups/:id/chat` | `group_chat_family_app` | `group_chat_dark_mode` | 5 |
| Group settings | `/groups/:id/settings` | `group_settings_family_app` | `group_settings_dark_mode` | 5 |
| Gallery | `/gallery` | `gallery_family_app` | `gallery_dark_mode` | 6 |
| Private media | `/media` | `private_media_family_app` | *(derive)* | 6 |
| Event folder | `/media/events/:id` | `summer_trip_2023_family_app` | *(derive)* | 6 |
| Media viewer | push | `media_viewer_family_app` | *(derive)* | 6 |
| Calendar | `/calendar` | `calendar_family_app` | `calendar_dark_mode` | 6 |
| Profile | `/profile` | `profile_family_app` | *(derive)* | 6 |
| Avatars (all surfaces) | — | profile / tree / chat avatars | — | 7 |

Nested app-only flows (parent context, sheets, over-quota, key backup): **keep + restyle** in the phase that owns the parent screen.

---

## 4. Phases

| Phase | Scope | Status |
|-------|--------|--------|
| **0** | Tokens, fonts, `ThemeMode`, `AppAvatar`, header/menu appearance control | **Done** |
| **1** | Auth + encryption | **Done** |
| **2** | Onboarding | **Done** |
| **3** | Shell + Home + Connections + Chats list | **Done** |
| **4** | Family tree stack | **Done** |
| **5** | Chat stack | **Done** |
| **6** | Media + calendar + profile | **Done** |
| **7** | Avatar upload/API/cache (see §5) | **Done** |

**Per-phase acceptance**

- Existing flows still work (same providers / APIs).
- Light + dark look correct (derive dark when no Stitch dark frame).
- No new product behavior unless listed in that phase.

---

## 5. Avatar system (Phase 7 — approved)

### Who uploads

| Subject | Who uploads | After they register |
|---------|-------------|---------------------|
| Registered user | Only that user — **their** profile avatar | — |
| Unregistered `family_members` | Any authorized family user | New user owns profile avatar; can replace |

### Last-write-wins (unregistered members)

- Only the **latest** photo is shown to everyone.
- Previous cropped + thumb S3 objects are **deleted** and **not recoverable**.

### Processing

1. Pick → **crop** to avatar frame.
2. Compress **cropped master** + **thumb**.
3. Store both on S3; thumb also in **local cache**.
4. UI: local thumb first → background API sync for latest `updated_at`.
5. No image → **initials** (unchanged).

### Backend (Phase 7)

- `users`: avatar paths + `avatar_updated_at`
- `family_members`: avatar paths + `avatar_updated_at` + `avatar_updated_by_user_id`
- On claim/register: prefer user avatar; document member-photo cleanup
- Signed URLs; not E2E gallery ciphertext
- Quota: count cropped + thumb toward storage unless revisited

### Flutter (Phase 7)

- Cropper + compress; `AvatarCacheStore`; `AppAvatar` already from Phase 0

---

## 6. Discuss first

| Item | Source | Decision |
|------|--------|----------|
| Forgot Password link on login | Stitch login | **Pending** — no mobile forgot-password flow yet; omitted from UI for now |
| Terms of Service / Privacy Policy URLs | Stitch login/register footers | **Pending** — shown as styled text only (not navigable) until pages exist |
| Brand wordmark in Stitch (“OurHome”) | Stitch headers | Use generic screen titles; product name only via `AppConfig.appName` |
| Encryption screen fake AppBar (menu + avatar) | Stitch encryption | **Skipped** — screen is pre-shell; use brand header only |
| Join choice dark 2-path IA (Create / Join only) | Stitch `join_choice_dark_mode` | **Rejected for now** — keep app’s 4 options; restyle to light 2×2 grid in both themes |
| Join choice “Help Me” / “Already have account? Log in” | Stitch join choice | **Skipped** — user is already authenticated; replaced help with static tip card |
| Find family bottom NavigationBar | Stitch find family | **Skipped** — onboarding is outside main shell |
| Family details “Creating Legacy” marketing image | Stitch family details | **Skipped** — decorative only; keep form + actions |
| Home “Activity” feed + View all | Stitch home | **Skipped** — no activity API yet; keep greeting + Today + Gallery/Calendar |
| Home “Send a wish” CTA | Stitch today card | **Skipped** — keep existing celebration cards (no wish flow) |
| Manage Family “Invite via Link” | Stitch manage family | **Skipped** — no invite-link API yet |
| Member detail Shared Memories / Upcoming Events | Stitch member detail | **Skipped** — keep link/unlink + profile fields only |
| Group settings mute / privacy prefs | Stitch group settings | **Skipped** — no mute/privacy API; discuss first |
| Group avatar camera affordance | Stitch group settings | **Skipped** — group avatars not in product yet (Phase 7 is user/member avatars) |
| “Invite Member” email-style row | Stitch group settings | **Skipped** — keep existing add-from-connected-members flow |
| Group settings bottom NavigationBar | Stitch group settings | **Skipped** — settings is a pushed route, not a tab |
| Chat “online” presence subtitle | Stitch group chat | **Skipped** — no presence API; show member count instead |
| Gallery masonry / “Shared by” overlays | Stitch gallery | **Skipped** — keep 3-col grid + existing New badge; no per-thumb sharer UI yet |
| Media viewer Share / Delete actions | Stitch media viewer | **Skipped** — keep download + info (share/delete stay in selection mode) |
| Profile Account Security / Notifications / Subscription | Stitch profile | **Skipped** — no those settings screens yet; Appearance stays in account menu |
| Profile Sign Out row | Stitch profile | **Skipped** — Sign out already in account menu |
| Calendar trip / map-style event cards | Stitch calendar | **Skipped** — keep reminder + tree date list with restyled tiles |

**Keep + restyle (known app extras):** over-quota banner, key backup card, ghost mode, chat attachment storage choice, nested parent-context forms, encryption “start fresh”, rate-limit errors, phone contacts picker (already in `PhoneTextField`).


---

## 7. How to run a phase

1. Open matching Stitch `screen.png` (+ dark if present).
2. Restyle Flutter widgets only; do not change notifier/API call sequences.
3. Update this doc’s phase status + Discuss first rows.
4. Smoke-test light + dark for that flow.

When ready for the next phase, say e.g. **“start Phase 1”**.
