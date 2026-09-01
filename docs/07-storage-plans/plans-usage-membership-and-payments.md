# Tijori plans, usage, membership, and payments

This is the product spec for storage plans. The Cursor plan file is **not** in this repo (it lives under the editor’s `.cursor/plans` folder). Use **this file** as the doc you can open in the project.

Status: **implemented in code.** Caps live on `storage_plans`; period meters live on `user_storage_usage`; shared seats on `plan_assignment_members`.

**Media** in this doc means **every** encrypted file: gallery, events, **and chat attachments**.

---

## 1. Catalog (dynamic)

Keep the existing `storage_plans` table (do not add a second `plans` table). Admin can create/edit/deactivate any plan. Limits live on the row — no hardcoded `3 × storage` in code.

| Plan | Storage | Monthly access | Streaming | Downloads | Seats | Price (placeholder) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Free | 5 GB | 15 GB | 10 GB | 5 GB | Owner only | $0 |
| Personal | 100 GB | 300 GB | 250 GB | 100 GB | Owner only | $2.99 / month |
| Plus | 200 GB | 600 GB | 500 GB | 200 GB | Shared, 4 members + owner | $4.99 / month |
| Pro | 500 GB | 1.5 TB | 1 TB | 500 GB | Shared, 8 members + owner | $9.99 / month |

File-view cap defaults to monthly access until admin sets a tighter number. Stream / download / view are **overlapping** sub-caps that also count toward monthly access.

`subscription` = existing `user_plan_assignments` (owner = `user_id`). No extra subscriptions table.

---

## 2. Data layers (history + live)

| Layer | Table | Grain |
| --- | --- | --- |
| Catalog | `storage_plans` | One row per plan |
| Who pays | `user_plan_assignments` | Owner’s subscription; old rows kept (`is_active`) |
| Who is on the pool this month | `plan_assignment_members` | `(assignment_id, user_id, period_start)` |
| Period counters | `user_storage_usage` | **`(assignment_id, period_start)`** — one **shared pool**, not per user |
| Event history | `storage_usage_logs` | Who did what, against which pool |

When a period opens, **copy plan limits onto the usage row** (snapshot). Closed months keep old limits even if admin later edits the catalog.

`monthly_access_bytes` = `streamed_bytes + downloaded_bytes + file_viewed_bytes`.

Stored bytes (stock in B2) **do not reset** on renew. Monthly access meters **do** reset.

---

## 3. Shared vs single-seat

- **Free / Personal:** `is_shared = false`. Pool of one (owner).
- **Plus / Pro:** `is_shared = true`, billed **monthly**. All current-cycle members draw from **one** storage + access pool.

A user may be in only **one current-cycle** shared pool.

---

## 4. Shared membership (add-only, then lock)

**Every new cycle starts with the owner only.** Members are **not** copied from last month. The owner must add people again, even to keep the same people.

**During the month — add only**

- Owner may add members until `member_count == max_shared_members` (owner is not counted in that cap).
- Once a person is chosen, they are **locked** until the next billing cycle: **no remove, no swap**.
- Empty seats can still be filled later in the same month.

**Upgrade mid-cycle (example)**  
Cycle started on the 2nd with a 10-member cap; 10 people are locked. On the 8th or 15th the owner pays for a 15-member plan:

- New storage / access / seat cap apply **immediately**.
- The original **10 stay locked**.
- **5 empty seats** open; each new person **locks when chosen**.
- Still cannot drop or swap the original 10.

**At next billing cycle**

- Usage period closes; stock carries; monthly meters zero.
- Roster **resets to owner only**.
- Owner chooses members again by hand.

Dropped members lose pool access. Their files stay in B2 and still count on the **owner’s pool** until delete/transfer. New uploads go to that user’s own plan (Free by default).

---

## 5. Upgrade vs downgrade

| Change | When | Payment | Members |
| --- | --- | --- | --- |
| **Upgrade** | **Now**, after paying the **new** plan price | Charge new price immediately | Locked members stay. Extra seats if cap increased. Single → shared starts owner-only, then owner adds. |
| **Downgrade** | **Next monthly cycle start** | Keep current plan until then | Current roster unchanged this month. After renew: owner-only on the cheaper plan. |

Owner can cancel a pending downgrade before renew.

If stored bytes still exceed the new storage cap when a downgrade activates: the cheaper plan still starts; gallery over-quota lock applies until they delete files.

---

## 6. Metering (gallery and chat)

Every media transfer through the API is metered (including chat attachments):

| Action | Counter |
| --- | --- |
| Upload complete | pool `storage_used_bytes` + |
| Delete | pool `storage_used_bytes` − |
| Stream | `streamed_bytes` |
| Full download | `downloaded_bytes` |
| Preview / thumbnail open | `file_viewed_bytes` |

One meter hit per `(user, file, action)` per time window (not per stream chunk). Same-pool co-owners must not double-count stored size.

Mobile quota UI shows **stored vs storage limit** (the pool bar). Stream/download breakdown is **admin-only**.

---

## 7. Two gates

**A. Storage / access quota (today)**  
Stored full → block **gallery** upload/open. Soft access gate: last 0.5 GB remaining blocks gallery files **> 100 MB**. Text chat, tree, reminders stay up. Chat attachments still play when **only** over quota.

**B. Failed payment lock (stricter)**  
After grace, **all media** is blocked — gallery **and** chat. See §8.

---

## 8. Failed payments (monthly recharge)

Free never enters this flow. Owner of a paid (or shared) plan is billed.

1. Charge fails → `past_due`. Push notification. App shows **Retry payment**.
2. Auto retry **once every 24 hours for 3 days** (3 attempts).
3. Manual Retry anytime during grace. Success → `active` immediately.
4. After 3 days still unpaid → `media_locked`.

**While `media_locked` (owner and all current shared members):**

| Allowed | Not allowed |
| --- | --- |
| Delete own files | Open, watch, stream, download, preview |
| See names in gallery/chat (so they can delete) | Share media |
| Family tree | Upload new media |
| Text chat, reminders | Play **any** media, including **chat attachments** |

Files stay in B2. Successful payment clears the lock immediately. This pass does **not** auto-downgrade to Free.

Android paid plans use **Google Play Billing** (`tijori_personal` / `tijori_plus` / `tijori_pro`). The app never stub-charges paid SKUs. Play RTDN updates `billing_status` and drops to Free when the subscription expires or is revoked.

---

## 9. Admin (system + each user)

Stream / download / file-view / dollar estimates are **admin-only**. Mobile still shows stored vs storage limit (the pool bar).

B2 $/GB rates come from env/config (`B2_STORAGE_USD_PER_GB_MONTH`, `B2_EGRESS_USD_PER_GB`). Compute cost at **read time**; do not treat a stored dollar column as the source of truth.

```
storage_cost = storage_used_bytes × storage rate
egress_cost  = (streamed + downloaded + file_viewed) × egress rate
estimated    = storage_cost + egress_cost
revenue      = that assignment’s plan price for the period
net          = revenue − estimated
```

### System dashboard (`/web` admin home)

Keep existing count cards (families, groups, media files, abuse). Add:

| Card | Source |
| --- | --- |
| Total users | `users` count |
| Active subscribers | paid `user_plan_assignments` with `billing_status = active` |
| Total storage used | sum of open-period pool `storage_used_bytes` |
| Total streamed this month | sum of open-period `streamed_bytes` |
| Total downloaded | sum of open-period `downloaded_bytes` |
| Estimated B2 storage | total stored × storage rate |
| Estimated egress | (streamed + downloaded + viewed) × egress rate |
| Estimated revenue | sum of live assignment prices |
| Estimated gross margin | revenue − (storage + egress) |

Also show past_due and media_locked assignment counts.

### Each user (Users → View)

Same layout as the original mock, on the user’s **current pool**:

- Plan: e.g. Personal — $2.99
- Storage bar: 82 / 100 GB
- Monthly access bar: 210 / 300 GB
- Streaming / Downloads / File views (GB)
- Estimated cost / Revenue / Net contribution
- Request counts, locked members + empty seats, billing status, pending downgrade, past periods

If the user is a **shared-plan member**, show the **pool** bars (not a personal copy of the cap) plus pool owner name and this user’s owned-bytes contribution.

List rows should show stored used and monthly-access used, not stored-only.

---

## 10. B2 smoke test (after this work is implemented)

**Names:** the product is **Tijori** and does not change. Bucket and object prefixes are **env only** and can change later.

This environment:

| Env | Current value |
| --- | --- |
| `B2_BUCKET` | `tagori` |
| `MEDIA_KEY_PREFIX` | `tagori/media` |
| `AVATAR_KEY_PREFIX` | `tagori/avatars` |

Keys are `{MEDIA_KEY_PREFIX}/{user_uuid}/{media_uuid}` (never the bucket root). Do not hardcode a product-name folder in new B2 checks. Code defaults, `.env.example`, empty-prefix fallbacks, and the durability test follow env (`tagori/media`, `tagori/avatars`). Objects already stored under an old prefix stay there until moved; this pass does not migrate them.

When `MEDIA_DISK=b2` and `B2_*` are configured:

1. Upload 1–2 small files through the **media API** (so metering runs).
2. Confirm objects land at `{MEDIA_KEY_PREFIX}/{user_uuid}/{media_uuid}` (today `tagori/media/…`) inside `B2_BUCKET`.
3. Confirm the admin system totals and that user’s stored bar move.
4. Stream or download once; confirm the matching meter and estimated egress move.
5. Delete the test files; confirm stock drops.

PHPUnit default tests keep using a fake disk. A `@group b2` test or `php artisan media:b2-smoke` may hit live B2 and must **skip** if credentials are missing. Do not commit keys.

---

## 11. Out of scope for the first build

- Live card processor (interface only; dunning uses gateway success/fail)
- Auto-downgrade to Free after a long lock
- Invite links / email (add by existing user uuid / connection)
- Percentage-based quota gates (columns stored, not used yet)
