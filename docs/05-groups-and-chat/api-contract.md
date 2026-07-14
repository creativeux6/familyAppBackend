# Groups & Chat — API Contract

Base path: `/api/v1/groups` (requires Bearer token)

WhatsApp-style **groups** (2+ members) and **direct chats** (1:1 with a connected user). Both use the same encrypted message pipeline and Reverb channels.

Only users you have **connected** with can be added to groups or direct chats.

Messages are **E2E encrypted** on the client — the server stores ciphertext only. Real-time delivery uses **Laravel Reverb** WebSockets.

## POST /groups

Create a group. Creator becomes `owner`. Must include at least one other connected member.

**Request:**
```json
{
  "name": "Khan Family Chat",
  "description": "Daily updates",
  "member_user_uuids": ["...", "..."]
}
```

**Response 201:** group payload with `"type": "group"`.

## POST /groups/direct

Open or create a **direct chat** with one connected user. Idempotent — returns the existing conversation if one already exists for the pair.

**Request:**
```json
{
  "user_uuid": "..."
}
```

**Response 200:** group payload with `"type": "direct"`, `"display_name"` (other person's name), and `"other_member"`.

Direct chats:

- Exactly 2 members
- Cannot add more members
- Either participant can delete the conversation (`DELETE /groups/{uuid}` or leave via `DELETE /groups/{uuid}/members/{userUuid}`)

## GET /groups

List groups and direct chats for the authenticated user, plus connected contacts for starting new direct chats.

**Response 200:**
```json
{
  "groups": [
    {
      "uuid": "...",
      "type": "group",
      "name": "Khan Family Chat",
      "display_name": "Khan Family Chat",
      "member_count": 3,
      "unread_count": 2,
      "last_message": { "uuid": "...", "sender_display_name": "Ali", "type": "text", "created_at": "..." },
      "members": [...]
    },
    {
      "uuid": "...",
      "type": "direct",
      "name": "Sara Khan",
      "display_name": "Sara Khan",
      "other_member": { "user_uuid": "...", "display_name": "Sara Khan" },
      "member_count": 2,
      "unread_count": 0,
      "members": [...]
    }
  ],
  "connected_contacts": [
    {
      "user_uuid": "...",
      "display_name": "Sara Khan",
      "direct_group_uuid": "..."
    }
  ]
}
```

`direct_group_uuid` is `null` when no direct chat exists yet for that contact.

## GET /groups/{uuid}

Group or direct chat detail with members.

## PATCH /groups/{uuid}

Update name/description (`owner` or `admin`). **Direct chats:** name cannot be changed.

## POST /groups/{uuid}/members

Add connected members (`owner` or `admin`). **Not allowed for direct chats.**

## DELETE /groups/{uuid}/members/{userUuid}

Remove a member or leave. **Direct chats:** deletes the conversation for both users.

## DELETE /groups/{uuid}

Delete group (`owner` only for groups). **Direct chats:** either participant may delete.

---

## Group encryption (E2E)

## POST /groups/{uuid}/encryption/envelopes

Upload wrapped group keys for each member (`owner`/`admin`, after create or member add).

## GET /groups/{uuid}/encryption/envelopes/me

Fetch the caller's wrapped group key for a generation.

---

## Messages

## GET /groups/{uuid}/messages

Paginated encrypted message history (newest first).

**Query:** `cursor`, `limit` (max 50)

## POST /groups/{uuid}/messages

Send an encrypted message. Broadcasts `message.sent` on the group WebSocket channel.

## POST /groups/{uuid}/read

Mark messages read for the current user (updates unread counts).

## PATCH /groups/{uuid}/messages/{messageUuid}

Edit own encrypted text message.

## DELETE /groups/{uuid}/messages/{messageUuid}

Soft-delete own message.

---

## Realtime

## GET /groups/realtime/config

Global Reverb connection settings.

## GET /groups/{uuid}/realtime

WebSocket connection info for a group channel (`private-group.{uuid}`).

See also [`docs/09-realtime-websockets/README.md`](../09-realtime-websockets/README.md).

**Mobile client behavior** (encryption, optimistic send, reply, voice, FCM): [`client-flow.md`](./client-flow.md).

## Status

Implemented in `app/Modules/Groups/`. Swagger tags **Groups** and **Chat**.
