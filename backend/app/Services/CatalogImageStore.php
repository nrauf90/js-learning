<?php

namespace App\Services;

/**
 * Stores product and category pictures on the `public` disk.
 *
 * Shared by ProductController and ProductCategoryController rather than copied
 * into both. The checks themselves live in ImageStore, which every upload
 * endpoint in the app goes through; this class only says where catalogue
 * pictures land and how large they may be.
 *
 * Public disk on purpose: a shelf photo is decoration on a till screen that
 * pulls dozens of them at once, and there is nothing in one worth an
 * authenticated round trip. Money documents are the opposite case — see
 * ReceiptImageStore.
 */
class CatalogImageStore extends ImageStore
{
    /** Everything this class writes lives under here — see ImageStore::delete(). */
    public const ROOT = 'catalog/';

    public const PRODUCT_DIR = self::ROOT.'products';

    public const CATEGORY_DIR = self::ROOT.'categories';

    /**
     * 2 MB. A phone photo of a shelf label is comfortably under this, and the
     * till has to pull dozens of these over a shop's DSL line.
     */
    public const MAX_KILOBYTES = 2048;

    public function disk(): string
    {
        return 'public';
    }

    public function root(): string
    {
        return self::ROOT;
    }

    public function maxKilobytes(): int
    {
        return self::MAX_KILOBYTES;
    }
}
