# Glossary

## User

A registered account on the app. Identified by **phone number** (E.164). Logs in with phone + password. Has Sanctum API tokens, storage quota, and encryption keys.

## FamilyMember

A node in the family tree (`family_members` table). May or may not have a linked User account.

- **With User** — active app member (e.g. your cousin who joined)
- **Without User** — tree-only node (e.g. deceased grandfather, child not yet on app)

One User links to at most one FamilyMember via `family_members.user_id`.

## Family

A cluster of FamilyMembers linked by relationship edges. Identified by `families.uuid`.

## Connection

A relationship between two **Users** (not FamilyMembers). Status: pending, connected, rejected, disconnected, blocked. Required before adding someone to a group or sharing media keys.

## Kinship label

A computed display term (Granddaughter, Cousin, Father-in-law) derived from graph path + gender + view mode. **Not stored in the database.**

## Primitive edge

A stored relationship type in `relationship_edges`: `parent_of`, `spouse_of`, etc. All other kinship terms are computed from these.

## Encryption (not cryptocurrency)

**End-to-end encryption** — chat and media are encrypted on the device before upload. The server stores ciphertext only. APIs live under `/api/v1/encryption/`, never `/crypto/`.

## Anonymity

When `users.is_anonymous = true`, the user is hidden from family discovery and tree views for non-connected members.

## Key envelope

A content encryption key wrapped (encrypted) for a specific recipient's public key. Stored server-side; only the recipient can unwrap.

## View mode

Family tree filter: `blood` (no marriage hops), `inlaws` (includes in-laws), `all`.
