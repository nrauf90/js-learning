# M2 — Authentication tasks

Depends on M1 complete.

- [ ] **M2-T1** Migration: add `google_id`, `avatar` to `users`
- [ ] **M2-T2** `POST /api/register` — name, email, password validation
- [ ] **M2-T3** `POST /api/login` — returns user + Sanctum token (or cookie session)
- [ ] **M2-T4** `POST /api/logout` — revoke token / invalidate session
- [ ] **M2-T5** `GET /api/user` — current authenticated user
- [ ] **M2-T6** Install Laravel Socialite; `GET /api/auth/google/redirect`
- [ ] **M2-T7** Google callback route — create/link user, issue auth, redirect to frontend
- [ ] **M2-T8** Frontend `login.html` + `signup.html` styled like existing app
- [ ] **M2-T9** Frontend `js/auth.js` — wire forms and Google button to API
- [ ] **M2-T10** Store auth token in memory/localStorage; attach to `api.js` requests
- [ ] **M2-T11** PHPUnit feature tests for register/login/logout
