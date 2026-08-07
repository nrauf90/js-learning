# Catalogue images

## What it is

Pictures on products and product categories. A picture on the till tile is worth
more than a name to a cashier working at speed, and the category rail reads much
faster with images than with a column of words.

## How it works

### Separate endpoints, on purpose

Uploads are `multipart/form-data` and sit outside the JSON product and category
routes rather than overloading `PUT` with two request shapes. A multipart body and
a JSON body are different enough that mixing them would mean the catalogue form
could no longer send a plain `PUT`.

`js/products.js` therefore builds the upload request by hand rather than using
`apiPost()`: `js/api.js` always sets `Content-Type: application/json`, and any
`Content-Type` set by hand stops the browser writing the multipart boundary the
server needs to find the file. The base URL and the bearer token are reused; the
request is built inline.

A picture can only be attached to a row that exists, so on "Add product" the
chosen file is **staged** in memory and uploaded straight after the POST comes back
with an id. Local previews use `URL.createObjectURL()` and are revoked when
replaced.

### Storage

`CatalogImageStore` is shared by both controllers rather than copied into each: an
upload endpoint is the one place in this app where a caller hands over bytes that
later get served back, so the checks belong in exactly one place where they can be
reviewed as a unit.

Files land on the `public` disk under `catalog/products/` and
`catalog/categories/`. The **relative path** is stored, not a full URL, so the app
can move between disks (local dev, S3) without rewriting every row;
`Product::imageUrl()` and `ProductCategory::imageUrl()` resolve it at render time
and both payloads expose it as `image_url` — the same key on both, so the till's
rail and the catalogue tiles read one field name rather than two.

### The filename

Nothing the client sent reaches the path:

```
<prefix>-<16 random chars>.<extension>
```

- the **directory** is a class constant,
- the **prefix** is built from the row id (`product-42`),
- the **extension** comes from the decoded image header, not the upload's filename.

An upload called `../../../public/shell.php` therefore cannot escape the directory
and cannot land with an executable extension. The random segment also busts the
browser and CDN cache — a replaced image gets a new URL instead of showing the old
one until it expires.

### Two gates on the content

1. Laravel's `image` + `mimes:jpg,jpeg,png,webp` rules, which sniff the file's
   bytes rather than trusting the client-supplied name or `Content-Type`.
2. `verifiedExtension()`, which runs `getimagesize()`. The validation rules trust
   finfo's byte-signature guess, which a file only has to *start* like an image to
   pass; `getimagesize()` parses the header properly and fails when no real
   dimensions can be read. So a PHP script wearing a GIF89a hat does not get
   through, and whatever does get through is stored under an extension **we** chose
   rather than one the uploader picked.

**SVG is deliberately absent** from the allowed list. It is a script container, and
one served from our own origin would be a stored-XSS delivery mechanism.

Size cap is 2 MB. A phone photo of a shelf label is comfortably under it, and the
till has to pull dozens of these over a shop's DSL line.

### Replacing and deleting

The old file goes only **once the new one is safely on disk**, so a failed write
never leaves the row pointing at nothing.

`delete()` silently ignores any path that does not start with `catalog/` or that
contains `..`. `image_path` is written only by this class, but a delete driven by
a column value is exactly the kind of call that should refuse to reach outside its
own directory if that ever stops being true.

Deleting a product or a category deletes its image too.

### On screen

The browser pre-checks type and size before sending, which only saves a doomed
2 MB round trip and gives the message straight away — the API re-checks both.

A missing picture renders as an empty placeholder in the catalogue and as a
**letter tile** (the category's first character) on the till rail. A broken image
URL falls back to the same letter tile through an `error` listener, so a deleted
file does not leave a broken-image icon on the till.

Every upload and removal writes an [activity log](./activity-log.md) row — the
trail records that `image_path` changed, though not what the old picture was.

## Screens / files

| Layer | File |
|---|---|
| Catalogue UI | `products.html` (`#product-image`, `#category-image` and their previews); `js/products.js` — `stageFile()`, `uploadImage()`, `clearStaged()` |
| Till rail | `js/pos.js` — `categoryThumbHTML()` |
| API | `ProductController::uploadImage()` / `destroyImage()`, `ProductCategoryController::uploadImage()` / `destroyImage()` |
| Service | `backend/app/Services/CatalogImageStore.php` |
| Migration | `2026_08_07_100002_add_image_path_to_products_and_categories.php` |
| Tests | `backend/tests/Feature/CatalogImageTest.php` |
| Demo data | `backend/database/seeders/GroceryCatalogSeeder.php` copies bundled images in |

## API endpoints

| Method | Path | Body | What it does |
|---|---|---|---|
| POST | `/api/products/{product}/image` | multipart `image` | Replace the product picture |
| DELETE | `/api/products/{product}/image` | — | Remove it |
| POST | `/api/product-categories/{productCategory}/image` | multipart `image` | Replace the category picture |
| DELETE | `/api/product-categories/{productCategory}/image` | — | Remove it |

All four return the updated product/category payload.

## Permissions & gating

- `auth:sanctum` + the `subscribed` gate.
- All four authorise on the **update** policy of the parent record, so they need
  the catalogue permission — a till-only cashier cannot change pictures.
- Ownership is checked through the same policy, so one shop cannot upload over
  another's product.

## Edge cases & known limits

- **Serving requires `php artisan storage:link`.** Files live on the `public` disk
  and `imageUrl()` builds a `/storage/...` URL; without the symlink every picture
  404s.
- **No resizing, cropping or thumbnailing.** A 2 MB photo is served at full size to
  every till tile.
- **No image for the shop itself.** `shops.logo_path` exists but has no upload
  endpoint. See [shop-and-staff.md](./shop-and-staff.md).
- **Orphaned files are possible.** If a row is removed by any route other than the
  controllers (a raw SQL delete, a cascade from deleting a user), the file stays on
  disk — nothing sweeps the directory.
- **One picture per record.** No galleries, no alternate angles.
- The stored path is not validated on read, only on delete; a hand-edited
  `image_path` would be rendered into an `<img src>` (escaped, so it cannot break
  out of the attribute, but it would point wherever it says).
