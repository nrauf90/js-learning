# BUG-007 - Admin edit/delete modals were always visible and couldn't be closed

- **Milestone:** M11
- **Found during:** user testing (`admin-users.html`, `admin-categories.html`) after the M11 review pass shipped
- **Date:** 2026-07-30
- **Status:** closed — added `.admin-modal[hidden] { display: none; }` to `css/styles.css`

## How to reproduce (pre-fix)

1. Log in as an admin and open `/admin-users.html` or `/admin-categories.html`.
2. Observe the page on load, or click an Edit/Delete button and then try
   to dismiss the popup via Cancel, the `×` button, or the overlay.

## Expected

The list/table is visible on page load with no modal open. Clicking
Edit/Delete opens exactly one modal; Cancel, `×`, and clicking outside
the modal all close it and return to the list.

## Actual (pre-fix)

Both the "Edit" and "Delete" modals on `admin-users.html` and
`admin-categories.html` rendered as visible (and stacked on top of each
other) as soon as the page loaded, covering the table underneath.
Clicking Cancel/`×` appeared to do nothing.

Root cause: `css/styles.css` defined

```css
.admin-modal {
  display: flex;
  ...
}
```

with no rule for the `[hidden]` state. The browser's built-in
`[hidden] { display: none }` rule lives in the *user-agent* stylesheet,
which always loses to an *author* stylesheet rule of equal or higher
specificity — regardless of source order. Since `.admin-modal` and
`[hidden]` have the same specificity (0,1,0) but `.admin-modal` is an
author rule, `display: flex` always won, so toggling the `hidden`
attribute via `element.hidden = true/false` in `js/admin-users.js` /
`js/admin-categories.js` had no visual effect — the modals were
permanently `display: flex`. The close buttons *were* wired up
correctly; they just weren't visibly doing anything because the modal
never actually hid.

## Fix

Added an explicit override right after the base rule:

```css
.admin-modal[hidden] {
  display: none;
}
```

This restores the expected cascade: the modal is hidden whenever the
`hidden` attribute is present, and only becomes `flex` once JS clears
it.

## Links

- `docs/milestones/M11-admin-panel.md`
- `docs/tasks/M11-tasks.md`
- Issues index: `docs/issues/README.md`
