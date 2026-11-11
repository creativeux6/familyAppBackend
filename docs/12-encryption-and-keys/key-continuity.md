# Encryption key continuity (NON-NEGOTIABLE)

**Status:** Permanent product invariant. Do not regress.

Encrypted gallery media and chat messages are ciphertext. The **device identity private key** is required to unlock content keys. If that private key is lost and not recoverable, **those files and messages are gone forever**. We cannot “look them up” on the server in plaintext.

## Invariant

1. **Media and chat must remain available across app reinstalls, device changes, and method refactors** — as long as the user can authenticate (password, email OTP reset, or same-device fingerprint unlock of a stored session).
2. **Never mint a new identity keypair** when a server-side vault or encrypted private-key backup already exists, unless the user explicitly chooses “start fresh” after understanding permanent data loss.
3. **Never silently overwrite** `users` identity public keys on the server without first restoring or intentionally rotating with envelope re-wrap.
4. **Every successful login/register** must run durable key sync via `ensureDurableIdentityKeys`: prefer local keys → account identity vault → legacy password wrap → keygen + vault upload.
5. New features (gallery, chat, sharing, realtime) must keep using the same identity/key-envelope model — do not invent a second key store that skips backup.

## High-level method (current)

```text
Authenticated session (Sanctum)
    → GET /encryption/identity-vault (server-sealed private key)
    → install identity private key on device
Identity private key (on device)
    → unwraps group keys / content-key envelopes
Group / content keys
    → decrypt chat ciphertext and media/thumbs on S3

Legacy fallback (migration):
Account password → unwraps password-wrapped key-backup → then PUT vault
```

### Lifecycle

| Event | Required behavior |
|-------|-------------------|
| Register | Generate identity keys → upload public key → **PUT identity vault** (optional legacy password wrap) |
| Login (reinstall / new device) | Authenticate → **GET vault** → install keys → same public key |
| Login (same device, keys present) | Refresh public key → **refresh vault upload** |
| Forgot password (email OTP) | Set new password → login → vault GET (vault is **not** tied to password) |
| Fingerprint unlock (same device) | Biometric gates stored Sanctum token + local keys (full data access) |
| App update (keys preserved in secure storage) | Silent vault upload if missing; no rotation |
| Explicit “start fresh” | Allowed only with hard warning; old ciphertext stays undecryptable |

### What we do **not** do

- Do not treat “reinstall” as “generate new encryption keys and continue.”
- Do not require a user-held recovery key / recovery sheet.
- Do not rely on local thumb caches as durability — ciphertext on S3 + envelopes + recoverable identity key are the source of truth.
- Do not move media plaintext to Firebase (or similar) as a shortcut around key continuity.

## Implementation map

| Piece | Location |
|-------|----------|
| Durable sync (vault-first) | `mobile/lib/features/encryption/data/encryption_repository.dart` → `ensureDurableIdentityKeys` |
| Called on login/register | `auth_provider.dart` |
| Identity vault APIs | `PUT/GET /api/v1/encryption/identity-vault` |
| Legacy password wrap | `POST/GET /api/v1/encryption/key-backup` |
| Setup / restore UI | `encryption_setup_screen.dart` |
| Profile status | `key_backup_card.dart` (“Synced to your account”) |
| Env | `ENCRYPTION_VAULT_MASTER_KEY` (falls back to `APP_KEY`) |

## Password change / reset

- **Authenticated password change**: vault unchanged; optional legacy wrap refresh with new password.
- **Forgot-password (email OTP)**: updates login password only; vault remains; next login unlocks history.

## Agent / PR checklist

Before merging anything that touches encryption, media download, chat decrypt, or auth login:

- [ ] Reinstall + login still decrypts **old** gallery thumbs and chat via vault
- [ ] No code path generates identity keys when vault or key-backup exists unless restore ran first
- [ ] Login/register still calls `ensureDurableIdentityKeys`
- [ ] Docs in this file still match behavior

## Related

- API: [api-contract.md](./api-contract.md)
- Chat client flow: [../05-groups-and-chat/client-flow.md](../05-groups-and-chat/client-flow.md)
- Media: [../06-media-and-s3/api-contract.md](../06-media-and-s3/api-contract.md)
