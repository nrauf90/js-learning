# M12 — Security hardening tasks

Depends on M11 complete.

- [x] **M12-T1** Payment IPN: require real signature verification (no sandbox bypass); keep the no-signature path only on the authenticated `sandboxComplete` endpoint
- [x] **M12-T2** Google OAuth: stop putting the Sanctum token in the redirect URL
- [x] **M12-T3** Stop returning the JazzCash merchant password (`pp_Password`) in the checkout API response
- [x] **M12-T4** Sanctum token expiration + revoke other sessions on password change
- [x] **M12-T5** Misc hardening: remove `is_admin` from `$fillable`, redact `payload` on admin payment responses, throttle `PUT /user/password` + Google OAuth routes, fix `billing.sandbox` default, add `amount`/`per_page` bounds, drop `google_id` from `/user` payload
- [x] **M12-T6** PHPUnit tests for each fix; full regression + `npm run qa:milestone -- M12`

## Completion log

### M12-T1 — done 2026-07-31

**Modified files**
- `backend/app/Services/Billing/JazzCashGateway.php` — `verifyCallback()` no longer bypasses signature checking when sandbox mode is on; fails closed if no integrity salt is configured
- `backend/app/Services/Billing/EasyPaisaGateway.php` — same fix for the hash-key check
- `backend/tests/Feature/BillingTest.php` — replaced the old test that proved the vulnerable behavior with `test_jazzcash_ipn_rejects_unsigned_payload_even_in_sandbox` and `test_jazzcash_ipn_activates_pending_payment_with_valid_signature`

**QA notes**
1. `cd backend && php artisan test --filter=BillingTest`
2. POST to `/api/billing/jazzcash/ipn` with a guessed `txn_ref` and no `pp_SecureHash` (even with `BILLING_SANDBOX=true`) → payment stays `pending`, response `success:false`

### M12-T2 — done 2026-07-31

**Modified files**
- `backend/app/Http/Controllers/Api/GoogleAuthController.php` — `callback()` now stores the Sanctum token in cache under a random one-time code and redirects with `?google_code=...` instead of `?token=...`; new `exchange()` action (`POST /api/auth/google/exchange`) trades the code for `{ user, token }`, deleting it on first use (`Cache::pull`)
- `backend/app/Models/User.php` — added `toAuthArray()` (shared response shape for login/register/user/exchange, and no longer includes `google_id`)
- `backend/app/Http/Controllers/Api/AuthController.php` — uses `$user->toAuthArray()` everywhere instead of a duplicated private `userPayload()`
- `backend/routes/api.php` — added throttled `POST /api/auth/google/exchange` and throttled `GET /api/auth/google/redirect`
- `js/auth.js` — `handleGoogleTokenFromUrl()` → `handleGoogleCodeFromUrl()`, now exchanges the code via `apiPost('/api/auth/google/exchange', { code })` instead of reading a bearer token from the URL
- `backend/tests/Feature/GoogleAuthTest.php` — new: valid exchange, single-use code, unknown/expired code

**QA notes**
1. `cd backend && php artisan test --filter=GoogleAuthTest`
2. Google OAuth E2E is optional (needs real credentials) per workspace rules; verified manually that no `token=` param ever appears in `login.html`'s URL during the flow

### M12-T3 — done 2026-07-31

**Modified files**
- `backend/bootstrap/app.php` — registered a `signed` middleware alias (`ValidateSignature`)
- `backend/app/Http/Controllers/Api/BillingController.php` — `checkout()` only returns raw gateway `fields` (incl. `pp_Password`) for sandbox checkouts (which carry no secrets); for live gateways it returns a `redirect_url` (a `temporarySignedRoute`, 15 min TTL) instead. New `gatewayRedirect()` action renders a server-side auto-submit page
- `backend/resources/views/billing/gateway-redirect.blade.php` — new auto-submit form view
- `backend/routes/api.php` — added signed `GET /api/billing/gateway-redirect/{payment}`
- `js/billing.js` — `onCheckout()` navigates to `redirect_url` for live checkouts instead of building/submitting the gateway form from JSON `fields` client-side; sandbox flow unchanged
- `backend/tests/Feature/BillingTest.php` — added `test_live_checkout_never_exposes_gateway_credentials_in_json` and `test_gateway_redirect_rejects_an_unsigned_url`

**QA notes**
1. `cd backend && php artisan test --filter=BillingTest`
2. With real JazzCash credentials configured (`billing.sandbox=false`), `POST /api/billing/checkout` response never contains `pp_Password` or `fields`

### M12-T4 — done 2026-07-31

**Modified files**
- `backend/config/sanctum.php` — `expiration` now defaults to 30 days (`SANCTUM_TOKEN_EXPIRATION_MINUTES`) instead of `null` (never expires)
- `backend/app/Http/Controllers/Api/AuthController.php` — `updatePassword()` now revokes every other token for the user (keeps only the one used for the request)
- `backend/tests/Feature/ProfileTest.php` — added `test_password_update_revokes_other_sessions`

**QA notes**
1. `cd backend && php artisan test --filter=ProfileTest`
2. Log in on two "devices" (two tokens), change password on one → the other's `/api/user` call now returns 401

### M12-T5 — done 2026-07-31

**Modified files**
- `backend/app/Models/User.php` — removed `is_admin` from `$fillable`
- `backend/app/Http/Controllers/Api/AdminController.php` — `userUpdate()` sets `is_admin` explicitly instead of via mass assignment; added `perPage()` helper capping `per_page` at 100 across `users`/`subscriptions`/`cashEntries`/`payments`
- `backend/database/seeders/AdminUserSeeder.php` — sets `is_admin` via `forceFill()` after create
- `backend/database/factories/UserFactory.php` — added `admin()` state (`forceFill` after creation) since `is_admin` can no longer be mass-assigned via a factory array
- `backend/tests/Feature/AdminPanelTest.php`, `backend/tests/Feature/AuthTest.php` — updated to use `User::factory()->admin()->create()`; added `test_admin_payments_endpoint_never_exposes_raw_gateway_payload` and `test_admin_list_endpoints_clamp_per_page`
- `backend/app/Models/Payment.php` — `payload` added to `$hidden` so it's never serialized to the admin API
- `backend/config/billing.php`, `backend/.env`, `backend/README.md` — `billing.sandbox` now requires an explicit `BILLING_SANDBOX=true`, no longer defaults on for `APP_ENV=local`
- `backend/app/Http/Controllers/Api/CashEntryController.php` — added `max:999999999.99` to the `amount` validation on create/update
- `backend/tests/Feature/CashEntryTest.php` — added `test_entry_amount_has_a_max_bound`

**QA notes**
1. `cd backend && php artisan test`
2. `GET /api/admin/users?per_page=100000` → `per_page` in the response is clamped to `100`
3. `POST /api/cash-entries` with `amount: 9999999999` → 422 validation error

### M12-T6 — done 2026-07-31

**Modified files**
- `e2e/playwright.config.js`, `scripts/qa-milestone.mjs` — added `M12` to `MILESTONE_ORDER` (no dedicated M12 E2E spec — covered by backend PHPUnit — but this keeps `qa:milestone -- M12` regressing M1–M11's suite correctly)

**QA notes**
1. `cd backend && php artisan test` — 75 passed
2. `npm run qa:milestone -- M12` — 63 passed (full M1–M11 E2E regression, no new UI surface for M12 itself)
