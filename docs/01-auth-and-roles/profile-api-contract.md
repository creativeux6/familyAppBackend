# Profile — API Contract

Base path: `/api/v1/profile` (requires Bearer token)

User account settings and the viewer's own `FamilyMember` record (self node in the tree).

Parent/spouse/children are managed via [`docs/04-family-tree/family-info-api-contract.md`](../04-family-tree/family-info-api-contract.md).

## GET /profile

Returns user account fields and the linked self `FamilyMember`.

## PATCH /profile

Update display name, marital status, anonymity flag.

## PATCH /profile/member

Update the authenticated user's own family member fields (name, DOB, birthplace, gender).

## Status

Implemented in `app/Modules/Profile/`. Flutter: `features/profile`.
