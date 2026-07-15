# Encryption key continuity (NON-NEGOTIABLE)

**Status:** Permanent product invariant. Do not regress.

Encrypted gallery media and chat messages are ciphertext. The **device identity private key** is required to unlock content keys. If that private key is lost and not recoverable, **those files and messages are gone forever**. We cannot “look them up” on the server in plaintext.

## Invariant

1. **Media and chat must remain available across app reinstalls, device changes, and method refactors** — as long as the user can authenticate with their account password (or a designated recovery path).
2. **Never mint a new identity keypair** when a server-side encrypted private-key backup already exists, unless the user explicitly chooses “start fresh” after understanding permanent data loss.
3. **Never silently overwrite** `users` identity public keys on the server without first restoring or intentionally rotating with envelope re-wrap.
4. **Every successful login/register** must run durable key sync: restore from backup if local keys are missing, otherwise ensure a backup exists (wrapped with the **account password**).
5. New features (gallery, chat, sharing, realtime) must keep using the same identity/key-envelope model — do not invent a second key store that skips backup.

## High-level method (current)

```text
Account password
    → wraps/unwraps identity private-key backup on server
Identity private key (on device)
    → unwraps group keys / content-key envelopes
Group / content keys
    → decrypt chat ciphertext and media/thumbs on S3
```

### Lifecycle

| Event | Required behavior |
|-------|-------------------|
| Register | Generate identity keys → upload public key → **immediately** create key backup with account password |
| Login (reinstall / new device) | Authenticate → **restore** key backup with account password → install keys locally → re-upload same public key |
| Login (same device, keys present) | Refresh public key registration → **refresh** key backup with account password |
| App update (keys preserved in secure storage) | No rotation — continue |
| Explicit “start fresh” | Allowed only with hard warning; old ciphertext stays undecryptable |

### What we do **not** do

- Do not treat “reinstall” as “generate new encryption keys and continue.”
- Do not rely on local thumb caches as durability — ciphertext on S3 + envelopes + recoverable identity key are the source of truth.
- Do not move media plaintext to Firebase (or similar) as a shortcut around key continuity.

## Implementation map

| Piece | Location |
|-------|----------|
| Auto backup/restore with account password | `mobile/lib/features/encryption/data/encryption_repository.dart` → `ensureDurableIdentityKeys` |
| Called on login/register | `login_screen.dart`, `register_screen.dart` |
| Setup flow must not auto-rotate when backup exists | `setup_flow_provider.dart` |
| Setup / restore UI | `encryption_setup_screen.dart` |
| Manual backup UI (Profile) | `key_backup_card.dart` |
| Server backup APIs | `POST/GET /api/v1/encryption/key-backup` |

## Password change / reset (follow carefully)

- **Authenticated password change** (future): decrypt backup with old password → re-encrypt with new password → store. Do not generate new identity keys.
- **Forgot-password reset** without the old password cannot re-wrap the existing backup. Prefer requiring a recovery step, or warn that E2E access may be lost if no alternate recovery exists. Never silently rotate keys after reset without that warning.

## Agent / PR checklist

Before merging anything that touches encryption, media download, chat decrypt, or auth login:

- [ ] Reinstall + login with same password still decrypts **old** gallery thumbs and chat
- [ ] No code path generates identity keys when `GET /encryption/key-backup` succeeds unless restore ran first
- [ ] Login/register still calls `ensureDurableIdentityKeys`
- [ ] Docs in this file still match behavior

## Related

- API: [api-contract.md](./api-contract.md)
- Chat client flow: [../05-groups-and-chat/client-flow.md](../05-groups-and-chat/client-flow.md)
- Media: [../06-media-and-s3/api-contract.md](../06-media-and-s3/api-contract.md)
