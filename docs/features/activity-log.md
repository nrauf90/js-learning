# Activity log

## What it is

Who changed what, and when. A shop admin handing catalogue access to a cashier
needs to be able to answer "who dropped the price on this" afterwards, and "where
did ten kilos of atta go" the week after that. The Activity screen is that trail.

## How it works

### What is recorded

`ActivityLogger` writes one `activity_logs` row per catalogue change:

| action | fired by |
|---|---|
| `created` | new product or category — full snapshot |
| `updated` | edited product or category — only the fields that actually moved |
| `deleted` | deleted product or category — full snapshot, the last record of what it held |
| `stock_adjusted` | `POST /api/products/{id}/stock` — before/after, type, reason, note |

`SUBJECT_TYPES` is `['Product', 'ProductCategory']`. Sales, purchases, customers,
staff changes, shop edits and cash entries are **not** logged.

### The `changes` column

A JSON map keyed by field name, in one of two shapes:

```json
{"price": {"from": "120.0000", "to": "130.0000"}}   // an update
{"sku": "RICE-001", "name": "Basmati"}              // a create/delete snapshot
```

The report renderer handles both, which is what lets one column answer "what did
it look like when it was deleted" as well as "what moved".

An update diff is driven by Eloquent's own dirty tracking (`getChanges()`), so a
PUT that resends the record unchanged records **nothing at all**. Values that
round-trip to the same thing through a cast ("5" vs 5) are dropped too — logging
5 → 5 would just teach the reader to distrust the report.

`id`, `user_id` and the timestamps are ignored: they say nothing about what a
person did, and they are noise in a report whose whole job is to read like a
sentence. Any field whose name contains `password`, `token`, `secret`,
`signature`, `api_key` or `private_key` is stripped whatever model it is on. The
catalogue has no such column today; the filter is there so pointing the logger at
a model that does cannot quietly turn an audit table into a plaintext secret
store.

### Stock has its own action

Stock does not move through `update()` — sales, refunds, purchases and the adjust
endpoint are the only paths — so `stockAdjusted()` is separate and records the
before/after, the type, the reason and the note. "Adjustment" answers nothing on
its own: whether ten kilos were spoiled, stolen or simply miscounted last week is
the entire question when someone reads this back.

The floats are passed as floats. An earlier version took `int` parameters and
truncated 1500.5 g to 1500.

The `stock_movements` row already records the delta for the ledger; this records
the **person**, which that table has no column for. See
[stock-and-wastage.md](./stock-and-wastage.md).

### Surviving the people it records

Three columns are denormalised on purpose:

- `user_id` is `nullOnDelete` and `user_name` is captured at write time, so
  removing a staff account leaves the trail intact and still naming the actor —
  the whole point is that history survives the person.
- `subject_label` is the human label captured at write time, so a deleted product
  still reads as its name rather than a bare id.

`ip_address` is captured from the request.

### Failure is swallowed, never silent

`ActivityLogger::write()` catches everything and logs to the application log
instead. The trail is a witness to the write, not part of it: letting an insert
failure bubble would roll back a legitimate price change, which is a worse outcome
than a gap in the history. The `Log::error` carries action, subject type, subject
id, actor id and the exception — everything needed to reconstruct the missing row.

### Who can read it

`ActivityLogController::scoped()`:

- A **shop admin**, or any staff member trusted with the catalogue, sees the whole
  shop.
- A **till-only staff account** sees just its own actions. The trail carries cost
  prices, which is exactly the number a shop owner does not hand to every cashier.
- Either way the shop id pins the rows, so no account can reach another shop's
  history.
- A shop admin who has not created a shop yet has `shop_id` null, and the query
  becomes `whereNull('shop_id') AND user_id = me` so they cannot see anybody
  else's shop-less rows.

The actor dropdown is built from the **unfiltered** scope, so picking a person
does not empty the dropdown you picked them from.

Ordering is newest first, tie-broken on id: a burst of edits inside one second
would otherwise come back in an arbitrary order and read as nonsense.

## Screens / files

| Layer | File |
|---|---|
| Page | `activity.html` |
| Controller | `js/activity.js` |
| API | `backend/app/Http/Controllers/Api/ActivityLogController.php` |
| Service | `backend/app/Services/ActivityLogger.php` |
| Model | `backend/app/Models/ActivityLog.php` |
| Migration | `2026_08_07_100006_create_activity_logs_table.php` |
| Tests | `backend/tests/Feature/ActivityLogTest.php` |

The screen is a paginated table — when, who (+ IP), action badge, subject, and the
changes rendered as a `field: was → now` list. Filters: person, action, subject
type, and a from/to date range, plus a reset. Every value goes through
`escapeHtml()`, nested before/after values included, because all of it came from
somebody's keyboard.

Field names are spelled out where the column name would be unhelpful —
`image_path` → "Picture", `is_active` → "On the till", `stock_quantity` → "Stock" —
and anything not in that map is title-cased with underscores replaced.

## API endpoints

| Method | Path | What it does |
|---|---|---|
| GET | `/api/activity` | Paginated trail (25 a page, max 100) + the actor list |

Filters: `user_id`, `action` (validated against `ActivityLogger::ACTIONS`),
`subject_type` (validated against `SUBJECT_TYPES`), `from`, `to`, `page`,
`per_page`.

## Permissions & gating

- `auth:sanctum` + the `subscribed` gate.
- No policy — the scoping *is* the authorisation, and it is applied to both the
  rows and the actor list.
- The route sits in the "media upload and activity log" block of
  `routes/api.php`, alongside the multipart image endpoints.

## Edge cases & known limits

- **Only the catalogue is audited.** No sale, refund, purchase, payment, khata
  change, staff change, shop edit or cash entry produces a row. A price change is
  traceable; a Rs 40,000 refund is not.
- **A gap is invisible from the UI.** When a write fails it lands in the
  application log, not in the trail, and the screen has no way to show that
  something is missing.
- **No retention policy.** Rows accumulate forever; nothing prunes them.
- **`changes` can be null** when nothing survived the ignore/sensitive filters, and
  the screen renders that as a dash.
- **The trail does not record the *old* image**, only that `image_path` changed
  from one random filename to another.
- **A staff member granted the catalogue permission can read the whole shop's
  trail**, including cost prices, from the moment the grant is made — including
  rows written before it.
- Backlog milestone M31 ("entry edit history / audit trail") covers extending this
  to cash entries; it is not started.
