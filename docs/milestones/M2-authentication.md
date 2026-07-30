# M2 — Authentication: email + Google

**Phase:** 1  
**Goal:** Users can register, log in, log out, and sign in with Google. Frontend login/signup pages match existing UI style.

## Deliverables

- Auth API: register, login, logout, `/api/user`
- Google OAuth redirect + callback (Socialite)
- `login.html`, `signup.html` + `js/auth.js`
- User model extended with `google_id`, `avatar`

## Tasks

See [M2 tasks](../tasks/M2-tasks.md).

## Exit criteria

- [ ] Email signup/login works end-to-end
- [ ] Google sign-in creates/links user and returns to app logged in
- [ ] Unauthenticated requests to protected routes return 401
