# M1 — Foundation: Laravel API shell

**Phase:** 1  
**Goal:** Stand up the Laravel JSON API with MySQL, CORS, and Sanctum so the vanilla frontend can call `/api/*`.  
**Status:** Done — see [M1 tasks](../tasks/M1-tasks.md) completion log.

## Exit criteria

- [x] `php artisan serve` runs without errors
- [x] `GET /api/health` returns JSON `{ "status": "ok" }`
- [x] Frontend can call the health endpoint from `http://localhost:3000` (CORS verified)
