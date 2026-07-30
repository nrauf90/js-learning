# M1 — Foundation: Laravel API shell

**Phase:** 1  
**Goal:** Stand up the Laravel JSON API with MySQL, CORS, and Sanctum so the vanilla frontend can call `/api/*`.  
**Status:** In progress — Laravel scaffolded; Sanctum installed; see [M1 tasks](../tasks/M1-tasks.md).

## Deliverables

- `backend/` Laravel project
- MySQL connection configured (`.env.example`)
- Sanctum SPA/token auth ready
- CORS allows frontend origin (`http://localhost:3000`)
- Health check endpoint `GET /api/health`
- `js/api.js` fetch helper on the frontend

## Tasks

See [M1 tasks](../tasks/M1-tasks.md).

## Exit criteria

- [ ] `php artisan serve` runs without errors
- [ ] `GET /api/health` returns JSON `{ "status": "ok" }`
- [ ] Frontend can call the health endpoint from `http://localhost:3000`
