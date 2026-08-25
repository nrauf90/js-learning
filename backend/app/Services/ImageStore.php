<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The checks every upload endpoint in this app runs before it keeps bytes a
 * caller handed over.
 *
 * An upload endpoint is the one place where a caller supplies content that the
 * server later serves back, so the rules live here rather than in each
 * controller — one place that can be reviewed as a unit, and one place that has
 * to be got right. Subclasses choose only *where* the file lands and how big it
 * may be; what counts as an acceptable file is not theirs to decide.
 *
 * @see CatalogImageStore  product and category pictures, public disk
 * @see ReceiptImageStore  supplier invoices and payment screenshots, private disk
 */
abstract class ImageStore
{
    /**
     * Formats every browser renders and getimagesize() can positively identify.
     * SVG is deliberately absent: it is a script container, and one served from
     * our own origin would be a stored-XSS delivery mechanism.
     *
     * @var array<int, string>
     */
    protected const ALLOWED_TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];

    /** The filesystem disk this store writes to. */
    abstract public function disk(): string;

    /** Everything this store writes lives under here — see delete(). */
    abstract public function root(): string;

    /** Size ceiling in kilobytes. */
    abstract public function maxKilobytes(): int;

    /**
     * Validation rules for the upload endpoints. `image` and `mimes` both sniff
     * the file's bytes rather than trusting the client-supplied name or
     * Content-Type header; verifiedExtension() below is the second gate.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$this->maxKilobytes()],
        ];
    }

    /**
     * @param  string  $directory  a constant on the subclass — never request input
     * @param  string  $prefix  server-built, e.g. "product-42"
     * @param  string|null  $replacing  the path this upload supersedes, if any
     * @return string the stored path, relative to the disk
     */
    public function store(UploadedFile $file, string $directory, string $prefix, ?string $replacing = null): string
    {
        $extension = $this->verifiedExtension($file);

        // Nothing the client sent reaches the path: the directory is a constant,
        // the prefix is built from the row id, and the extension comes from the
        // decoded image header rather than the upload's filename. An upload
        // called "../../../public/shell.php" therefore cannot escape the
        // directory, and cannot land with an executable extension either.
        //
        // The random segment also busts the browser and CDN cache — a replaced
        // image gets a new URL instead of showing the old one until it expires.
        $name = $prefix.'-'.Str::lower(Str::random(16)).'.'.$extension;

        $path = Storage::disk($this->disk())->putFileAs($directory, $file, $name);

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                'image' => ['The image could not be saved. Please try again.'],
            ]);
        }

        // Old file goes only once the new one is safely on disk, so a failed
        // write never leaves the row pointing at nothing.
        if ($replacing !== null && $replacing !== $path) {
            $this->delete($replacing);
        }

        return $path;
    }

    /**
     * Removes a stored image. Silently ignores anything outside this store's
     * own tree: the stored path is written only by this class, but a delete
     * driven by a column value is exactly the kind of call that should refuse
     * to reach outside its own directory if that ever stops being true.
     */
    public function delete(?string $path): void
    {
        if (blank($path) || ! Str::startsWith($path, $this->root()) || Str::contains($path, '..')) {
            return;
        }

        Storage::disk($this->disk())->delete($path);
    }

    /**
     * A sane ceiling on either side, in pixels.
     *
     * Not a product limit — it is the check that makes getimagesize() usable as
     * a gate at all. For PNG, getimagesize() reads the 8-byte signature and then
     * takes the next eight bytes as the IHDR width and height *without
     * validating them*, so a PHP payload prefixed with a PNG signature comes
     * back as a perfectly good image 1,752,113,267 pixels wide. Real photographs
     * are not. A 100-megapixel phone is roughly 12,000 px on its long side, and
     * a 600 dpi A4 scan is about 7,000.
     */
    protected const MAX_DIMENSION = 20000;

    /**
     * The stored extension, decided by what the bytes actually are.
     *
     * Three checks, because no one of them is enough on its own:
     *
     * 1. **getimagesize()** parses the header and names the format. It is the
     *    only one of the three that tells us which extension to store under, and
     *    it is deliberately not trusted for anything else — see MAX_DIMENSION
     *    for how little it validates on the PNG path.
     * 2. **Plausible dimensions.** Both sides positive and under the ceiling.
     *    This is what actually stops the PNG-signature trick above.
     * 3. **finfo agrees.** The caller's validation rules already run this, but
     *    repeating it here means the store is safe on its own terms rather than
     *    on the assumption that every caller remembered to apply rules(). It is
     *    also the check that catches a file which merely *starts* like an image.
     *
     * Whatever survives all three is stored under an extension we chose from the
     * decoded header, never one the uploader picked.
     */
    protected function verifiedExtension(UploadedFile $file): string
    {
        $path = (string) $file->getRealPath();
        $info = @getimagesize($path);

        $type = is_array($info) ? ($info[2] ?? null) : null;
        $width = is_array($info) ? (int) ($info[0] ?? 0) : 0;
        $height = is_array($info) ? (int) ($info[1] ?? 0) : 0;

        $plausible = $width >= 1
            && $height >= 1
            && $width <= static::MAX_DIMENSION
            && $height <= static::MAX_DIMENSION;

        if (! is_int($type) || ! isset(static::ALLOWED_TYPES[$type]) || ! $plausible) {
            throw $this->rejected();
        }

        // The mime getimagesize() inferred from the header, against the one
        // finfo reads independently. A PHP script behind an image signature
        // fails here even when the header parser was fooled.
        $claimed = is_array($info) ? ($info['mime'] ?? null) : null;

        if (! is_string($claimed) || $claimed !== $this->sniffedMime($path)) {
            throw $this->rejected();
        }

        return static::ALLOWED_TYPES[$type];
    }

    /** finfo's own read of the file, independent of any header parsing. */
    private function sniffedMime(string $path): ?string
    {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mime = @finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) ? $mime : null;
    }

    /**
     * One message for every way a file can fail the checks above.
     *
     * Deliberately does not say *which* check rejected it: the person uploading
     * a shelf photo cannot act on the difference, and anyone probing the gate
     * should not be handed a map of it.
     */
    private function rejected(): ValidationException
    {
        return ValidationException::withMessages([
            'image' => ['That file is not a readable JPG, PNG or WebP image.'],
        ]);
    }
}
