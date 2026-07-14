# Auth Module — API Contract

Base path: `/api/v1/auth`

v1 uses **phone + password** (no SMS OTP). OTP may be added in v2 for phone verification and recovery.

## POST /register

Create a new account.

**Request:**
```json
{
  "phone": "+923001234567",
  "password": "secret123",
  "password_confirmation": "secret123",
  "display_name": "Ali Khan"
}
```

**Response 201:**
```json
{
  "action": "registered",
  "token_type": "Bearer",
  "access_token": "...",
  "user": {
    "uuid": "...",
    "phone": "+923001234567",
    "display_name": "Ali Khan",
    "is_anonymous": false
  }
}
```

## POST /login

Login with phone and password.

**Request:**
```json
{
  "phone": "+923001234567",
  "password": "secret123"
}
```

**Response 200:**
```json
{
  "action": "logged_in",
  "token_type": "Bearer",
  "access_token": "...",
  "user": {
    "uuid": "...",
    "phone": "+923001234567",
    "display_name": "Ali Khan",
    "is_anonymous": false
  }
}
```

## POST /logout

Requires `Authorization: Bearer {token}`

## POST /refresh

Requires `Authorization: Bearer {token}` — issues new token.

## Status

Implemented in `app/Modules/Auth/`. Documented in Swagger tag **Auth**.
