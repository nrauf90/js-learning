# BUG-001 - guest is redirected from cashflow page to login

- **Milestone:** M3
- **Status:** closed
- **Resolution:** False positive. `serve` clean URLs rewrite `login.html` → `/login`. E2E assertion updated to accept both. Redirect itself worked.

## Links

- `e2e/tests/m3-cashflow.spec.js`
