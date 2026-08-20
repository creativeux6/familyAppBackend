# Friends / contact match — API Contract

Base path: `/api/v1/friends` (requires Bearer token)

Device contacts are hashed on the client (SHA-256 of E.164). The server stores hashes only and returns **registered users** whose phone hash is in that set.

Chat, groups, and media share are allowed with those matches (or with an accepted **family connection**). Family connection is still required for tree registered status and birthday/anniversary notifications.

## POST /friends/sync

**Request:**
```json
{
  "phone_hashes": ["<64-char lowercase sha256 hex>", "..."]
}
```

Replaces the caller’s stored hashes. Max 2000 hashes. Rate limited (`throttle:friends-sync`).

**Response 200:**
```json
{
  "matches": [
    {
      "user_uuid": "...",
      "display_name": "Bob",
      "is_registered": true,
      "is_connected_family": false,
      "kinship_label": null,
      "phone_hash": "<sha256 of E.164>"
    }
  ]
}
```

`kinship_label` is set only when `is_connected_family` is true (same family + accepted connection). Anonymous users and blocked users are omitted.

## GET /friends

Same `matches` payload from the last sync.
