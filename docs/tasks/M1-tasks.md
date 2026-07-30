# M1 — Foundation tasks

Work top to bottom. Mark done as you complete each task.

- [x] **M1-T1** Create Laravel project in `backend/` via Composer
- [ ] **M1-T2** Configure `.env` for MySQL (document XAMPP defaults in `.env.example`)
- [x] **M1-T3** Install and configure Laravel Sanctum for API auth *(package installed; User model + api routes pending)*
- [ ] **M1-T4** Configure CORS for `http://localhost:3000` (and production frontend URL placeholder)
- [ ] **M1-T5** Add `GET /api/health` route returning `{ "status": "ok", "app": "..." }`
- [ ] **M1-T6** Create `js/api.js` — base URL, credentials, JSON helpers, error handling
- [x] **M1-T7** Add `backend/README.md` with setup and serve instructions
- [ ] **M1-T8** Update root `package.json` scripts (optional `dev:api` helper)
- [ ] **M1-T9** Verify health check callable from browser console on frontend origin

## Progress notes

- Laravel **12.12.2** installed in `backend/` (PHP 8.2 compatible).
- Sanctum **v4.3.3** installed; config + migrations published.
- CORS config published to `backend/config/cors.php` (needs `supports_credentials` + origin lock).
- Remaining M1 items require **agent mode** (plan mode blocks non-markdown edits).

## Notes

- PHP 8.2 + Composer available via XAMPP on this machine.
- MySQL: use XAMPP MySQL (`127.0.0.1:3306`, user `root`, empty password) unless `.env` differs.
- Default install uses SQLite for quick boot; switch to MySQL per `.env.example` before production.
