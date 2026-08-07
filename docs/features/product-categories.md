# Product categories

## What it is

How the shop is laid out — Atta & Rice, Cold Drinks, Masalas, Sabzi. Categories
group the catalogue, drive the till's category rail, filter the sales list, and
roll the profit report up the way the shelves are arranged.

They are **not** the same thing as the income/expense categories on the cash-flow
screen. Those are a shared, system-seeded list of what money is *for*; these are
each shop's own list of what it *sells*. See
[cash-flow.md](./cash-flow.md) for the other one.

## How it works

A `product_categories` row is `user_id` + `name` + `slug` + `image_path`. The
`user_id` is the shop owner's, and `(user_id, slug)` is unique — one seller's
"Beverages" has nothing to do with another's, unlike `expense_categories`, which
is a single global table.

The name is unique per shop, enforced by a validation rule. The slug is derived
from the name and suffixed until it is free (`cold-drinks`, `cold-drinks-2`),
because two different names can slug to the same string.

Deleting a category does **not** delete its products: `products.product_category_id`
is `nullOnDelete`, so they simply become uncategorised and stay on the till. The
category's image is deleted with it.

The list endpoint returns `products_count` per category. The till deliberately
ignores that figure and counts from its own in-memory catalogue instead, because
`products_count` also counts inactive products the till never shows.

`image_url` is the same key on both the product and the category payload, so the
till's rail and the catalogue tiles read one field name rather than two. A
category with no picture renders as a letter tile (its first character), and a
broken image URL falls back to the same letter tile via an `error` listener.

Every create, update and delete writes an [activity log](./activity-log.md) row
with the before/after of whatever moved.

## Screens / files

| Layer | File |
|---|---|
| Management UI | `products.html` — the "Categories" panel beside the product form; `js/products.js` |
| Till rail | `pos.html` `#pos-categories`; `js/pos.js` — `renderCategories()`, `filterCategories()` |
| Sales filter | `sales.html` `#filter-category`; `js/sales.js` |
| API | `backend/app/Http/Controllers/Api/ProductCategoryController.php` |
| Model | `backend/app/Models/ProductCategory.php` |
| Policy | `backend/app/Policies/ProductCategoryPolicy.php` |
| Images | `backend/app/Services/CatalogImageStore.php` |
| Migrations | `2026_08_06_110000_create_product_categories_table.php`, `2026_08_07_100002_add_image_path_to_products_and_categories.php` |

The till's rail has its own search box, which filters the rail itself. "All"
always stays visible — it is the way back.

## API endpoints

| Method | Path | What it does |
|---|---|---|
| GET | `/api/product-categories` | This shop's categories with `products_count` |
| POST | `/api/product-categories` | Create (`name`) |
| PUT | `/api/product-categories/{productCategory}` | Rename (re-slugs) |
| DELETE | `/api/product-categories/{productCategory}` | Delete; products survive uncategorised |
| POST | `/api/product-categories/{productCategory}/image` | Multipart picture upload |
| DELETE | `/api/product-categories/{productCategory}/image` | Remove the picture |

Payload: `id`, `name`, `slug`, `image_url` (+ `products_count` on the index).

## Permissions & gating

- `auth:sanctum` + the `subscribed` gate.
- `viewAny` is open to everyone: a cashier must see the same categories the
  till's rail does. The list is scoped to `dataOwnerId()`, so staff read their
  owner's categories rather than an empty set of their own.
- `create` / `update` / `delete` require the catalogue permission, using the same
  "everyone except ungranted staff" phrasing as `ProductPolicy` and for the same
  reason (a null `role` on an in-process `User` would otherwise lock an owner
  out).
- Ownership matches `dataOwnerId()` or the caller's own id.

## Edge cases & known limits

- **The list is not paginated and takes no filters.** A shop with hundreds of
  categories gets them all in one response.
- **There is no ordering field.** Categories are always sorted by name; the
  shopkeeper cannot arrange the till rail to match the shop.
- **Rename changes the slug.** Nothing joins on the slug (products point at the
  id), so this is safe today, but a bookmarked slug would break.
- **No merge.** Two categories that turn out to be the same thing have to be
  emptied by re-assigning each product.
- Deleting a category is permanent, and its products silently lose their grouping
  — the confirmation dialog says so.
- **Sales-list filtering by category matches through the product.** Lines whose
  product has since been deleted drop out of a category filter: there is no
  longer anything to say which category they belonged to. The profit report
  handles the same case differently, folding them into "Uncategorised".
