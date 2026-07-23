# Stitch design prompt — Family App (Flutter)

**How to use:** Paste sections into [Google Stitch](https://stitch.withgoogle.com/) as one master prompt, or send **one flow at a time** (Auth → Onboarding → Shell → Chat → Media → etc.) if Stitch works better with smaller scopes. Ask Stitch for **mobile Flutter / Material 3**, **iPhone 15 / Pixel-size frames**, light mode first.

Copy from “MASTER PROMPT” below through the end of the page list.

---

## MASTER PROMPT (paste into Stitch)

```
Design a complete mobile UI for “Family App” — a private family networking Flutter app.

PRODUCT IN ONE LINE
Families discover relatives, connect privately, chat end-to-end encrypted, share encrypted photos/videos, and browse a family tree — all phone + password based (no social login).

AUDIENCE
Adults managing real family relationships. Trust, privacy, and clarity matter more than playful social-network chrome. Not a dating app. Not a corporate dashboard.

PLATFORM
- Flutter mobile (iOS + Android)
- Material 3 patterns adapted to a warm, human family brand
- Phone portrait primary; design at ~390×844
- Support loading, empty, and error states for every data screen
- Safe areas, 16pt base padding, touch targets ≥44pt
- Bottom navigation for main app; stack pushes for detail flows
- Light mode only for v1

VISUAL DIRECTION (IMPORTANT — avoid AI UI clichés)
- Do NOT use: purple-to-indigo gradients, glow neon, cream+#terracotta newspaper layouts, dark-mode-first, emoji-heavy UI, pill-stat strips, floating promo badges on heroes
- DO use: one calm family brand color (deep teal-green or soft indigo as primary — pick ONE and stay consistent), warm off-white surfaces with subtle texture or soft radial wash (not flat pure white everywhere), clear hierarchy, generous whitespace
- Brand name “Family App” must feel present on auth and home (hero-level on login/register; not only tiny nav text)
- Typography: expressive but readable — distinctive display for titles, clean body (not Inter/Roboto/Arial defaults if Stitch allows alternatives)
- Cards: use only when they wrap a real interaction (list rows, selectable options). Avoid decorative card grids
- Imagery: authentic family / kinship atmosphere only where it helps (auth splash, empty states) — never stock collage clutter
- Motion later; for now design static screens with clear primary CTA

PRODUCT RULES THAT AFFECT UI
1. Phone number + password auth (country dial code picker on phone fields)
2. Encryption keys restore with account password — users must unlock gallery & chats after reinstall
3. Free plan = 5 GB; storage usage = uploads + reads; when over quota show: “Storage limit reached. Please subscribe to a paid plan.” Block open/download/upload of media; allow delete; chat stays usable
4. Show total storage usage to users as a single progress (do NOT show separate “stored” vs “read” breakdown on profile/gallery)
5. Member code is shareable identity for joining family trees
6. Ghost / private mode exists (stay private)
7. Chat and media are end-to-end encrypted (subtle lock/secure cues OK; don’t scare users)

INFORMATION ARCHITECTURE
Guest: Login ↔ Register
After auth if keys missing: Encryption setup
If family not joined: Onboarding join choice → join by code / find family / solo tree / ghost
Main shell (4 tabs): Home | Tree | Chats | Connect
Header menu (avatar): Gallery, Calendar, Find or join family, Profile & settings, Sign out
Stack screens: Profile, Calendar, Gallery, Private media, Event folder, Media viewer, Manage family, Member detail, Create group, Group chat, Group settings

OUTPUT NEEDED FROM STITCH
For EACH screen below produce a clear mobile mock:
- Screen title / route name
- Layout with real copy (use the labels given)
- Primary + secondary actions
- Empty / loading / error variants where listed
- Note any bottom sheets or dialogs as separate frames

Reuse components consistently: AppHeader (branded bar + avatar), bottom NavigationBar, PrimaryButton, SecondaryButton, PhoneField (with country code), PasswordField (show/hide), ErrorBanner, EmptyState, ListTile with avatar, FAB, Segmented control, Bottom sheets.

---

### FLOW A — AUTH

A1. LOGIN (`/login`)
Purpose: Sign in.
Content:
- Brand mark + “Family App” as hero signal
- Headline: “Welcome back”
- Subtitle: “Sign in with your phone number and password.”
- Phone field (country code + number) — may be empty or prefilled with last used phone; NEVER hardcode a demo number in design
- Password field with show/hide
- Primary: “Sign in”
- Secondary text button: “Create an account”
- Error banner area above fields (e.g. wrong credentials / rate limited)
States: default, loading (button spinner / overlay), error

A2. REGISTER (`/register`)
Purpose: Create account.
Content:
- Brand + “Join Family App”
- Headline: “Create account”
- Fields: Phone, Display name, Password, Confirm password
- Primary: “Create account”
- Link: “Already have an account? Sign in”
States: default, loading, validation errors, API error banner

---

### FLOW B — ENCRYPTION SETUP

B1. ENCRYPTION SETUP (`/encryption/setup`)
Purpose: Restore or create durable keys so gallery & chats unlock after reinstall.
Two visual variants:
Variant Restore (server backup exists):
- Lock icon
- Title: “Unlock gallery & chats”
- Body: explain account password restores encryption
- Password field
- Primary: “Restore keys”
- Destructive text: “Start fresh (lose old media unlock)” → confirm dialog warning permanent loss of old unlock
Variant Create (first device / no backup):
- Title: “Secure this device”
- Primary: “Create secure backup”
States: checking, submitting, error

Dialog: Start fresh — strong warning, Cancel / Confirm

---

### FLOW C — ONBOARDING / JOIN FAMILY

C1. JOIN CHOICE (`/onboarding/join`)
Purpose: Choose how to enter the family graph.
Four equal option tiles (not tiny chips):
1. “Use a member code” — join someone who shared a code
2. “Find by relatives” — search using parents/relatives
3. “Start my own tree” — solo family root
4. “Stay private (ghost mode)” — limited privacy path
States: default, busy, error banner

C2. JOIN BY CODE (`/onboarding/join-code`)
Purpose: Look up relative by member code and pick relation.
Steps on one scrollable screen:
- Member code text field
- Button: “Find person”
- After lookup: target person card (name, hint)
- Section: “People in their tree” (list)
- Relation options (radio/list): mother, father, sibling, spouse, child, etc.
- Primary: “Verify and connect”
States: empty (before search), loading, found, error, no person found

C2b. PARENT / SPOUSE CONTEXT (nested page from join-by-code)
Purpose: Collect required mother/father/spouse details when the chosen relation needs anchors.
- Form: parent/spouse name fields as required by context
- Primary: “Save details”

C3. FAMILY DETAILS (`/onboarding/family-details`)
Purpose: Persist mother/father (+ optional relatives) before search/join.
- Parent form (mother, father)
- Expandable “All relatives”
- “Add relative”
- Primary: “Save and continue”
States: busy, error

C4. FIND FAMILY (`/onboarding/find-family`)
Purpose: Search matches from declared relatives, then verify.
- Status card: family details completeness + “Edit/Add family details”
- Primary search: “Search”
- Match result cards
- Relation picker + “Verify and connect”
States: incomplete details gate, searching, “No matches yet”, error, success path

C4b. FAMILY SEARCH DETAILS (nested)
- Same parent + relatives editor as family details, tailored for search

---

### FLOW D — MAIN SHELL CHROME

D0. SHELL FRAME (shared)
- Top AppHeader: screen title + circular avatar menu
- Avatar menu items: Gallery | Calendar | Find or join family | Profile & settings | Sign out
- Bottom nav 4 tabs with icons + labels:
  1. Home
  2. Tree
  3. Chats (optional unread badge)
  4. Connect (optional pending badge)
- Sign out → confirm dialog

D1. HOME (`/home`)
Purpose: Warm greeting hub — not a dashboard of widgets.
First viewport composition:
- Greeting: “Hello, {FirstName}”
- Optional “Today” celebration cards (birthdays/anniversaries) — only if any; else omit
- Two clear shortcuts: Gallery, Calendar (Gallery may show badge for new shares)
Do NOT pack stats, schedules, address blocks, or promo strips into home.
States: default with celebrations, default without, pull-to-refresh

D2. FAMILY TREE (`/family-tree`)
Purpose: Interactive kinship graph.
- Segmented control: All | Blood | In-laws
- Legend: On app / Not on app
- Actions: Add, Manage, Refresh
- Pan-zoom canvas with person nodes (tap → member detail)
States: loading spinner, error + Retry, empty “No visible members” + Add CTA

D3. CHATS LIST (`/groups`)
Purpose: Inbox for groups + DMs + shortcuts to connected people.
- Section “Your chats” (avatar, title, last message preview, time, unread)
- Section “Connected family” (quick start DM)
- FAB: “New group”
States: loading, error + Retry, empty → CTA to open Connect tab

D4. CONNECTIONS (`/connections`)
Purpose: Discover and manage family links.
Tabs:
- Suggestions: “Connect all” + suggestion tiles (Connect)
- My connections: Accept / Reject / Disconnect / Block
States: per-tab loading, empty, error; confirm dialogs for disconnect/block

---

### FLOW E — FAMILY TREE STACK

E1. MANAGE FAMILY (`/family-tree/manage`)
- Tabs: Parents | Spouse | Children | Siblings
- Editable member tiles
- FAB: “Quick add”
States: loading, empty per tab, error + Retry

E2. MEMBER DETAIL (`/family-tree/members/:id`)
- Large name, kinship chip, gender, living status, DOB, on-app badge
- Actions: Link connection / Unlink (with confirm)
States: loading, error

Sheets (separate frames):
- Add family member sheet (relation, names, DOB/DOD, living)
- Edit family member sheet
- Duplicate match dialog: same person vs different person

---

### FLOW F — CHAT

F1. CREATE GROUP (`/groups/create`)
- Name, optional description
- Multi-select list of connected members (checkboxes + avatars)
- Primary: “Create group”
States: loading members, empty “Connect first”, submitting, error

F2. GROUP / DIRECT CHAT (`/groups/:id/chat`)
Purpose: Encrypted messaging.
- App bar: title, optional lock cue, settings gear
- Message bubbles (me vs them), timestamps, pending/sending/failed
- Reply banner / edit banner when active
- Composer: attach | emoji | GIF | voice | text field | send
- Long-press message → actions sheet: Reply, Copy, Info, Edit, Delete
States: syncing, empty “No messages yet”, error + Retry, delete confirm

F3. GROUP SETTINGS (`/groups/:id/settings`)
- Name / description (editable for owner/admin; hide for DM or read-only)
- Members list with roles
- Add members (sheet with checkboxes)
- Leave group / Delete group (owner) with confirms
States: loading, saving, error

Chat sheets (separate frames):
- Attachment source: Device vs Media library
- Share storage choice: sender vs recipient quota (clear copy)
- Library media picker
- GIF picker
- Message info (delivery)

---

### FLOW G — MEDIA / GALLERY

Treat Private media (`/media`) and Family Gallery (`/gallery`) as TWO MODES of one gallery pattern.

G1. PRIVATE MEDIA (`/media`) — title “Private media” or “My media”
- Storage usage header: single progress bar + “X of 5 GB used” (or plan quota) — NO stored/read split
- Tabs: General | Events
- Thumbnail grid (images/videos; video badge)
- FAB upload
- Selection mode toolbar: info, download, share, rename, delete
- Upload progress indicator
States: loading, empty General, empty Events, error + Retry
Over-quota state: banner “Storage limit reached. Please subscribe to a paid plan.” — disable open/download/upload; keep delete enabled

G2. FAMILY GALLERY (`/gallery`) — title “Gallery”
- Same grid/tabs/FAB pattern
- No private storage header (or lighter shared context)
- Badge/unread affordance for new shares when relevant

G3. EVENT FOLDER (`/media/events/:id`)
- Event title in app bar
- File grid
- Add files
- Selection: share / delete
States: loading, empty, over-quota snackbar/banner

G4. MEDIA VIEWER (full screen)
- Swipe between items
- Close, download, info
- Video: play/pause, scrub
- Quota-aware error if blocked

Media sheets (separate frames):
- Upload sheet: General vs Event destination; pick files; create event
- Create event: title, description, date, location
- Add files to event
- Share to connections
- Media / event info
- Assign to event
- Rename / delete confirms

---

### FLOW H — CALENDAR

H1. CALENDAR (`/calendar`)
- Month navigator
- Month grid with event dots
- List of events for selected day
- FAB: “Reminder”
- Delete owned custom reminders
States: loading, empty month, error + Retry

Sheet: Add reminder — title, date, notes, yearly toggle, personal vs family visibility

---

### FLOW I — PROFILE & SETTINGS

I1. PROFILE (`/profile`)
Sections top to bottom:
1. Member code (large, copy button) — explain “share this so relatives can find you”
2. Profile summary / edit: display name, first/last, DOB, birthplace, gender, marital status, marriage date
3. Connected members summary
4. Ghost mode switch (privacy)
5. Tile: Private media → `/media`
6. Storage & backup:
   - Single storage progress (total usage vs quota)
   - Over-quota message + subscribe CTA when limited
   - Key backup card: create/restore recovery passphrase (secondary to account-password restore)
States: profile loading, error + Retry, saving overlay

Dialogs: passphrase create/restore for key backup

---

### COMPONENT SYSTEM (design once, reuse)

1. Brand header / auth hero
2. AppHeader + avatar menu
3. Bottom NavigationBar (4 tabs + badges)
4. Primary / Secondary / Destructive buttons
5. Phone field + country picker sheet
6. Password field
7. ErrorBanner
8. EmptyState (illustration + title + CTA)
9. Loading overlay / skeleton
10. Person avatar + name row
11. Chat bubble set
12. Media thumbnail cell (image/video)
13. Storage progress (single bar)
14. Bottom sheet scaffold
15. Confirm dialog scaffold

---

### DELIVERABLE ORDER (recommended Stitch batches)

Batch 1: Auth (A1–A2) + Encryption (B1)
Batch 2: Onboarding (C1–C4)
Batch 3: Shell + Home + Connections + Chats list (D0–D4)
Batch 4: Family tree + manage + member (D2, E1–E2)
Batch 5: Chat thread + create + settings (F1–F3)
Batch 6: Gallery modes + viewer + sheets (G1–G4)
Batch 7: Calendar + Profile (H1, I1)

End of prompt.
```

---

## Quick page checklist (for tracking Stitch frames)

| ID | Screen | Route |
|----|--------|-------|
| A1 | Login | `/login` |
| A2 | Register | `/register` |
| B1 | Encryption setup | `/encryption/setup` |
| C1 | Join choice | `/onboarding/join` |
| C2 | Join by code | `/onboarding/join-code` |
| C2b | Parent context | nested |
| C3 | Family details | `/onboarding/family-details` |
| C4 | Find family | `/onboarding/find-family` |
| D0 | Shell chrome | — |
| D1 | Home | `/home` |
| D2 | Family tree | `/family-tree` |
| D3 | Chats list | `/groups` |
| D4 | Connections | `/connections` |
| E1 | Manage family | `/family-tree/manage` |
| E2 | Member detail | `/family-tree/members/:id` |
| F1 | Create group | `/groups/create` |
| F2 | Group chat | `/groups/:id/chat` |
| F3 | Group settings | `/groups/:id/settings` |
| G1 | Private media | `/media` |
| G2 | Family gallery | `/gallery` |
| G3 | Event folder | `/media/events/:id` |
| G4 | Media viewer | push |
| H1 | Calendar | `/calendar` |
| I1 | Profile | `/profile` |

Plus sheets/dialogs listed in the master prompt.

---

## Notes for humans (not for Stitch)

- Source of truth for routes: `mobile/lib/core/routing/app_router.dart`
- Capacity / improvement backlog: [implementation-capacity-report.md](../00-overview/implementation-capacity-report.md)
- Permanent rules: [permanent-product-rules.md](../00-overview/permanent-product-rules.md)
- Applying Stitch → Flutter: [ui-redesign-plan.md](./ui-redesign-plan.md) (Kinship / Nocturnal themes; phased UI-only).
