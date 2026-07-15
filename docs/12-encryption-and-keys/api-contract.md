# Encryption Module — API Contract

Base path: `/api/v1/encryption`

**Note:** This is end-to-end **encryption** (message/media security), not cryptocurrency.

**Key continuity (mandatory):** private-key backups keep gallery and chat unlockable after reinstall. See [key-continuity.md](./key-continuity.md). The mobile app wraps the identity private key with the **account password** on every login/register (`ensureDurableIdentityKeys`).

**Also see:** [permanent product rules](../00-overview/permanent-product-rules.md) (Free 5 GB plan, no env quota, no data loss).

All routes require `Authorization: Bearer {token}`.

## POST /identity-key

Upload user's public identity key (X25519) for key envelope distribution.

**Request:**
```json
{
  "public_identity_key": "base64-encoded-32-bytes",
  "encryption_version": 1
}
```

**Response 201:**
```json
{
  "message": "Identity key stored.",
  "encryption_version": 1
}
```

## GET /identity-key/{userUuid}

Fetch another user's public key (to wrap content keys for them).

**Response 200:**
```json
{
  "user_uuid": "...",
  "public_identity_key": "base64...",
  "encryption_version": 1
}
```

## POST /key-backup

Store encrypted private key backup blob (encrypted on client with user passphrase).

**Request:**
```json
{
  "encrypted_private_key_blob": "base64...",
  "salt": "base64...",
  "encryption_version": 1
}
```

**Response 201:**
```json
{
  "message": "Key backup stored."
}
```

## GET /key-backup

Fetch the active encrypted private key backup for the authenticated user.

**Response 200:**
```json
{
  "encrypted_private_key_blob": "base64...",
  "salt": "base64...",
  "encryption_version": 1,
  "created_at": "2026-06-13T12:00:00+00:00"
}
```

**Response 404:** No backup exists yet.

## Status

Implemented in `app/Modules/Encryption/`. Documented in Swagger tag **Encryption**.
