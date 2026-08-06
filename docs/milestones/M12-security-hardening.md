# M12 — Security hardening

**Phase:** 4 (post-launch hardening) · **Depends on:** M11

**Goal:** Close the concrete security findings from the app-wide improvement
review before building any new features — all are small, isolated fixes
with outsized risk reduction.

## Scope

- Payment IPN: never skip signature verification, even in sandbox mode —
  `JazzCashGateway`/`EasyPaisaGateway::verifyCallback()`.
- Google OAuth: stop putting the Sanctum token in the redirect URL.
- Stop returning the JazzCash merchant password (`pp_Password`) in the
  checkout API response.
- Sanctum tokens get a finite expiration; changing password revokes all
  other tokens.
- `is_admin` removed from `User::$fillable`.
- Admin payment endpoints stop returning the raw gateway `payload`.
- Throttle `PUT /user/password` and the Google OAuth routes.
- `billing.sandbox` no longer defaults on just because `APP_ENV=local`.
- Add `max:` bounds on entry `amount` and admin `per_page`.
- Drop `google_id` from the public `/user` payload.

## Tasks

See [M12 tasks](../tasks/M12-tasks.md).

## Exit criteria

- [x] Sandbox IPN callbacks without a valid signature are rejected
- [x] No bearer token appears in any URL (Google OAuth flow)
- [x] No gateway merchant credentials appear in any API response
- [x] Password change invalidates other sessions; tokens expire
- [x] `backend/tests/Feature/*` cover each fix; full regression green

## Status: done (2026-07-31)

See [M12 tasks](../tasks/M12-tasks.md) for the completion log and QA notes.
