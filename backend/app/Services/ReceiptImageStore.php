<?php

namespace App\Services;

/**
 * Stores the paperwork behind money that moved: a photo of the wholesaler's
 * bill when stock is booked in, and the JazzCash/EasyPaisa/bank screenshot when
 * an instalment is paid against it.
 *
 * Private disk, unlike CatalogImageStore. A shelf photo is decoration; one of
 * these carries an account title, a phone number, a transaction id and an
 * amount, and a public URL for it would be guessable-free but permanent,
 * shareable and unauthenticated. They are served instead by
 * AttachmentController::show(), which re-checks the shop boundary on every
 * read.
 */
class ReceiptImageStore extends ImageStore
{
    /** Everything this class writes lives under here — see ImageStore::delete(). */
    public const ROOT = 'receipts/';

    public const PURCHASE_DIR = self::ROOT.'purchases';

    public const PAYMENT_DIR = self::ROOT.'payments';

    /** Money taken against a customer's khata, rather than paid to a supplier. */
    public const KHATA_DIR = self::ROOT.'khata';

    /**
     * 5 MB, against the catalogue's 2 MB.
     *
     * A shelf photo is resized by the phone's camera app; this is a full-page
     * screenshot or a photo of an A4 bill that has to stay legible enough to
     * read a transaction id off, and modern phone screenshots clear 2 MB
     * routinely. These are fetched one at a time by one person looking at one
     * invoice, not dozens at once by a till, so the size costs far less here.
     */
    public const MAX_KILOBYTES = 5120;

    public function disk(): string
    {
        return 'receipts';
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
