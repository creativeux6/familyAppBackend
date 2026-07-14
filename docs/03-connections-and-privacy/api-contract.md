# Connections & Privacy — API Contract

Base path: `/api/v1/connections` and `/api/v1/privacy` (requires Bearer token)

After onboarding, users see active family members and choose to connect with all, selected members, or stay anonymous. Connections can be changed at any time.

## GET /connections/suggestions

Family members in the same family who have app accounts (excluding self), with current connection status.

**Response 200:**
```json
{
  "family_uuid": "...",
  "is_anonymous": false,
  "members": [
    {
      "user_uuid": "...",
      "display_name": "Sara Khan",
      "member_uuid": "...",
      "connection_uuid": null,
      "connection_status": null
    }
  ]
}
```

`connection_status`: `null` | `pending_sent` | `pending_received` | `connected` | `rejected` | `disconnected` | `blocked`

## GET /connections

List connections for the authenticated user.

**Query:** `status` (optional) — `pending` | `connected` | `rejected` | `disconnected` | `blocked`

**Response 200:**
```json
{
  "connections": [
    {
      "uuid": "...",
      "status": "connected",
      "connected_at": "2026-06-14T12:00:00Z",
      "direction": "sent",
      "other_user": {
        "uuid": "...",
        "display_name": "Sara Khan"
      }
    }
  ]
}
```

## POST /connections

Send a connection request to one family member.

**Request:**
```json
{
  "user_uuid": "..."
}
```

**Response 201:** connection object with `status: pending`

## POST /connections/bulk

Send connection requests to selected members.

**Request:**
```json
{
  "user_uuids": ["...", "..."]
}
```

**Response 200:**
```json
{
  "created": 2,
  "skipped": 1,
  "connections": []
}
```

## POST /connections/connect-all

Send connection requests to all eligible same-family active members (excluding self and blocked users).

**Response 200:** same shape as bulk response.

## POST /connections/{uuid}/accept

Accept a pending request (recipient only).

## POST /connections/{uuid}/reject

Reject a pending request (recipient only).

## POST /connections/{uuid}/disconnect

Disconnect an active connection (either party).

## POST /connections/{uuid}/block

Block a user (either party). Prevents future requests until unblocked (v1: no unblock endpoint).

---

## GET /privacy

**Response 200:**
```json
{
  "is_anonymous": false
}
```

When `is_anonymous` is true, the user is hidden from family discovery and tree views for non-connected members.

## Tree privacy (connection-aware)

| Viewer relationship | What they see |
|---------------------|---------------|
| Self | Full profile |
| Connected | Full profile (name, DOB, registered status) |
| Not connected (registered relative) | Ghost placeholder — kinship label only, `is_ghost: true` |
| Not connected (anonymous mode) | Hidden from tree |
| Unregistered stub | Stub info entered by family (matching placeholder) |

Disconnecting immediately masks the other person's profile in your tree view.

## PATCH /privacy/anonymity

Toggle anonymity mode.

**Request:**
```json
{
  "is_anonymous": true
}
```

**Response 200:**
```json
{
  "is_anonymous": true,
  "message": "Anonymity enabled. You are hidden from family discovery."
}
```

## Status

Implemented in `app/Modules/Connections/`. Swagger tags **Connections** and **Privacy**.
