# Family tree — Manage family info

Base path: `/api/v1/family-tree/family-info` (requires Bearer token)

Edit core relatives (father, mother, spouse, in-laws, children) for the authenticated user's tree view.

## GET /family-tree/family-info

Returns structured relative slots with graph member UUIDs when linked.

## PATCH /family-tree/family-info

Sync relatives to the family graph and declared-relative records.

**Request:** nested `father`, `mother`, `spouse`, `spouse_father`, `spouse_mother`, `children[]` objects with optional `uuid`, names, DOB, gender, living status.

## POST /family-tree/members

Add a single relative by `relation_type` (`father`, `mother`, `spouse`, `child`, etc.).

## Status

Implemented in `app/Modules/FamilyTree/`. Flutter: `features/family_tree` (Manage Family screen).
