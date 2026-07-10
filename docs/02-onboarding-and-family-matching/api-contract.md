# Onboarding & Family Matching — API Contract

Base path: `/api/v1/onboarding` (requires Bearer token)

## POST /questionnaire

Submit relative questionnaire. Creates family member stubs, runs matching, returns session + match preview.

**Request:**
```json
{
  "answers": [
    {
      "relative_slot": "self",
      "first_name": "Ali",
      "last_name": "Khan",
      "date_of_birth": "1990-05-15",
      "birthplace": "Lahore",
      "gender": "male",
      "is_living": true
    },
    {
      "relative_slot": "father",
      "first_name": "Ahmed",
      "last_name": "Khan",
      "date_of_birth": "1965-01-10",
      "is_living": true
    }
  ]
}
```

**relative_slot:** `self`, `father`, `mother`, `paternal_grandfather`, `paternal_grandmother`, `maternal_grandfather`, `maternal_grandmother`, `other_relative`

**Response 201:**
```json
{
  "session_uuid": "...",
  "status": "matched",
  "matched_family": { "uuid": "...", "name": "Khan Family" },
  "top_match_score": 0.8500,
  "active_members": [
    { "user_uuid": "...", "display_name": "Sara Khan", "phone": "+923..." }
  ]
}
```

## GET /match-result

Latest onboarding session for authenticated user.

## POST /confirm-family

Confirm or reject matched family affiliation.

**Request:**
```json
{
  "confirmed": true
}
```

**Response 200:** links user to family member in matched family.

## Status

Implemented in `app/Modules/Onboarding/`. Swagger tag **Onboarding**.
