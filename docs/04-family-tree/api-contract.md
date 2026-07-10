# Family Tree — API Contract

Base path: `/api/v1/family-tree` (requires Bearer token)

Kinship labels (Grandmother, Cousin, Father-in-law, etc.) are **computed at runtime** from `relationship_edges` — never stored in the database.

## View modes

| Mode | Description |
|------|-------------|
| `blood` | Blood relatives only — no marriage (`spouse_of`) hops |
| `inlaws` | Includes in-laws via marriage paths |
| `all` | Full graph (blood + marriage + step/adoptive edges) |

## GET /family-tree

Tree from the authenticated user's perspective (defaults to their `family_members` row as root).

**Query:**

| Param | Default | Description |
|-------|---------|-------------|
| `view_mode` | `all` | `blood` \| `inlaws` \| `all` |
| `max_depth` | `6` | Max traversal depth (1–8) |
| `root_member_uuid` | self | Optional root member in same family |

**Response 200:**
```json
{
  "family_uuid": "...",
  "view_mode": "all",
  "root_member_uuid": "...",
  "viewer_member_uuid": "...",
  "members": [
    {
      "uuid": "...",
      "first_name": "Ahmed",
      "last_name": "Khan",
      "gender": "male",
      "is_living": true,
      "user_uuid": null,
      "kinship_label": "Father",
      "depth": 1
    }
  ],
  "edges": [
    {
      "uuid": "...",
      "from_member_uuid": "...",
      "to_member_uuid": "...",
      "edge_type": "parent_of"
    }
  ]
}
```

Anonymous members are omitted unless the viewer is connected to them. Registered members you are not connected with appear as **ghost** placeholders (kinship label only, no name or account details).

Tree member fields:
- `is_registered` — `true` when the viewer can see a linked app account
- `is_ghost` — `true` when the person is on the app but not connected to the viewer

## GET /family-tree/members/{memberUuid}

Member detail plus kinship label relative to the viewer.

**Query:** `view_mode` (optional, default `all`)

**Response 200:**
```json
{
  "member": {
    "uuid": "...",
    "first_name": "Sara",
    "last_name": "Khan",
    "gender": "female",
    "date_of_birth": "1992-03-10",
    "is_living": true,
    "user_uuid": "..."
  },
  "kinship_label": "Sister",
  "view_mode": "all"
}
```

## GET /family-tree/kinship/{targetMemberUuid}

Resolve kinship label between viewer and target member.

**Query:** `view_mode` (optional, default `all`)

**Response 200:**
```json
{
  "viewer_member_uuid": "...",
  "target_member_uuid": "...",
  "kinship_label": "Cousin",
  "view_mode": "all",
  "path_found": true
}
```

## Status

Implemented in `app/Modules/FamilyTree/`. Graph reads use `MysqlFamilyGraphRepository` (v1). Swagger tag **FamilyTree**.
