# Shop, staff and roles

## What it is

The shop record — name, phone, address, receipt footer — and the logins that work
its till. A shop owner adds a cashier, decides whether that cashier may touch the
catalogue, resets their password, and deactivates them when they leave.

## How it works

### Three roles

`users.role` carries one of three values, alongside the older `is_admin` flag:

| role | who |
|---|---|
| `admin` | platform operator — runs the [admin panel](./admin-panel.md), has no shop |
| `shop_admin` | owns a shop, its catalogue, staff and takings |
| `staff` | works the till, nothing else unless granted |

`role` defaults to `shop_admin`, because every account that existed when the
column was added had signed up to run its own shop: the products, sales and cash
entries in those rows belong to the person who registered. The migration also
back-filled `role = 'admin'` for existing `is_admin` rows so the two never
disagree — **`is_admin` stays the authority for platform admins, `role` mirrors
it.**

`role`, `shop_id`, `can_manage_products` and `is_admin` are all deliberately
**out of `$fillable`**. They decide whether an account can reach the catalogue,
other people's takings, or another shop entirely, so they are written only
through `User::assignRole()` and `User::setProductPermission()`, both of which
`forceFill`.

### `dataOwnerId()` — the one rule that makes staff work

```php
public function dataOwnerId(): int
{
    if ($this->isStaff() && $this->shop_id) {
        return (int) ($this->shop?->owner_id ?: $this->id);
    }
    return (int) $this->id;
}
```

Staff work their shop owner's data. Every product, sale, purchase, khata page and
day book is filed under the owner's `user_id`, and every query and policy compares
against `dataOwnerId()` rather than `id` — otherwise a cashier would open the till
to an empty catalogue and none of their own sales would be visible to the owner.

The one exception is `cash_entries`, which stays per-account. See
[cash-flow.md](./cash-flow.md).

### The catalogue permission

`can_manage_products` is a per-user grant a shop admin hands out. Without it,
staff can sell but cannot touch the catalogue, categories, stock adjustments or
purchases. `User::canManageCatalogue()` returns true for platform admins and shop
admins unconditionally.

The policies phrase the check as "everyone except ungranted staff"
(`! $user->isStaff() || $user->canManageCatalogue()`) rather than calling
`canManageCatalogue()` directly, because `role` carries a database-level default:
a `User` instance created in-process and never read back has a null role, which
would read as "not a shop admin" and lock an owner out of their own products.

`toAuthArray()` returns `can_manage_products` as `canManageCatalogue()` — the
effective permission, not the raw column — so the frontend does not have to
re-derive it.

### The shop record

One row per shop, owned by a `shop_admin` (`shops.owner_id`). Staff point at it
through `users.shop_id`; the owner does not need to (though `ShopController` keeps
`users.shop_id` in step on first save, so `dataOwnerId()` and the staff list
agree for a self-registered account).

`GET /api/shop` resolves ownership first — a shop admin's own shop wins over
`users.shop_id`, which is only the working link staff travel through — and is
readable by **staff too**, because the till needs the shop name and receipt footer
to print a receipt.

`PUT /api/shop` sits behind `ShopAdminOnly` and takes no shop id: the caller is
always a shop admin editing the shop they own, and `owner_id` comes from the
token. Accounts that registered themselves (rather than being onboarded by a
platform admin) have no shop row yet, so the first save creates it.

### Staff management

Every `/api/staff` route is behind `ShopAdminOnly`, so `$request->user()` is
always a shop admin. What that middleware cannot check is *which* staff row is
being touched, so each method re-resolves the caller's own shop and refuses to see
anything outside it. An id from another shop **404s rather than 403s**, so the
response cannot be used to probe for accounts that exist elsewhere.

Creating a staff member uses the same `Password::defaults()` rules as
registration — a staff login is a login like any other, and this one is chosen by
somebody else. Only name/email/password are mass-assigned; role, `shop_id` and
`can_manage_products` go through the explicit setters precisely so a stray
`"role": "admin"` in the body does nothing.

Changing a staff password **revokes every token they had open**, which is the
point of a shop admin resetting it.

**Deactivate, not delete.** `DELETE /api/staff/{user}` does not remove the row —
every sale, stock movement and activity log entry pointing at that user keeps its
name and its history. Deactivation is modelled as *unlinked from the shop*: the
account keeps the `staff` role but `shop_id` goes null, so `dataOwnerId()` no
longer resolves to the shop's data, and its tokens are revoked so an open till
session stops working immediately rather than at the next login.

A shop admin with no shop row yet gets a **409** on the staff endpoints — the
request is fine, the account just is not set up yet — and the screen turns that
into "Save your shop details first, then add staff."

### `ShopAdminOnly`

The middleware turns platform admins away **first**. They carry the default
`shop_admin` role (`is_admin` is the authority for them, not `role`), and letting
them through would mean every handler behind it had to decide which shop "theirs"
means — silently operating on somebody else's staff list. They onboard shops from
the admin panel instead.

A null `role` falls back to `shop_admin`, the same way `toAuthArray()` does, so an
account predating the roles column is not locked out of its own shop.

It is referenced by class name in `routes/api.php` rather than through an alias,
so the whole feature stays inside its own files.

## Screens / files

| Layer | File |
|---|---|
| Page | `shop.html` (sidebar label **Shop & Staff**) |
| Controller | `js/shop.js` |
| API | `backend/app/Http/Controllers/Api/ShopController.php`, `StaffController.php` |
| Middleware | `backend/app/Http/Middleware/ShopAdminOnly.php` |
| Models | `backend/app/Models/Shop.php`, `User.php` |
| Migrations | `2026_08_07_100000_create_shops_table.php`, `2026_08_07_100001_add_roles_to_users_table.php` |
| Tests | `backend/tests/Feature/ShopStaffTest.php` |

The sidebar link is shown only to `role === 'shop_admin' && !is_admin`
(`js/shell.js`). For a staff account the page renders the shop details read-only
and the staff sections not at all — but that is a courtesy, not the security
boundary; everything is enforced server-side.

## API endpoints

| Method | Path | Who | What it does |
|---|---|---|---|
| GET | `/api/shop` | any subscribed account | The caller's shop, or `null` |
| PUT | `/api/shop` | shop admin | Create or update name, phone, address, receipt footer |
| GET | `/api/staff` | shop admin | This shop's staff |
| POST | `/api/staff` | shop admin | Create a staff login |
| PUT | `/api/staff/{user}` | shop admin | Rename, re-email, reset password, toggle the catalogue permission |
| DELETE | `/api/staff/{user}` | shop admin | Deactivate (unlink + revoke tokens) |

Shop payload: `id`, `name`, `phone`, `address`, `receipt_footer`, `logo_url`.

## Permissions & gating

- `auth:sanctum` + the `subscribed` gate on all of the above. Shop admins run
  these from inside the app, so they sit behind the same gate as everything else;
  for staff the subscription checked is the owner's.
- `PUT /api/shop` and every `/api/staff` route additionally require
  `ShopAdminOnly`.
- Onboarding a *new* shop is platform-operator work and lives at
  `POST /api/admin/shop-admins`, which opts **out** of the subscription gate —
  whether the operator's own trial is still running has nothing to do with signing
  up a customer. See [admin-panel.md](./admin-panel.md).

## Edge cases & known limits

- **`shops.logo_path` has no upload endpoint.** The column and `logoUrl()` exist
  and the payload carries `logo_url`, but nothing writes it, and the shop form has
  no file input.
- **`receipt_footer` is stored and editable but never printed.** `js/receipt.js`
  ends every slip with hard-coded "Thank you for shopping!" and "No exchange
  without this receipt", and heads it with the *user's* name rather than
  `shops.name`. See [receipts-printing.md](./receipts-printing.md).
- **A staff account cannot be reactivated through the UI.** Deactivation nulls
  `shop_id`; nothing offers to set it back, so the row is effectively orphaned.
  It also disappears from `GET /api/staff`, which filters on `shop_id`.
- **One shop per owner.** `ownedShop()` is a `hasOne`; there is no multi-branch
  support.
- **Staff cannot be promoted** to shop admin, and a shop cannot be transferred to
  a different owner.
- **`can_manage_products` is a single flag.** There is no finer permission — a
  cashier either can reshape the catalogue, adjust stock and book in deliveries,
  or none of those.
- The email address on a staff account must be globally unique, so the same person
  cannot work at two shops.
