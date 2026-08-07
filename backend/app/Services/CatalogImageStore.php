<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores product and category pictures on the `public` disk.
 *
 * Shared by ProductController and ProductCategoryController rather than copied
 * into both: an upload endpoint is the one place in this app where a caller
 * hands over bytes that later get served back, so the checks belong in exactly
 * one place where they can be reviewed as a unit.
 */
class CatalogImageStore
{
    /** Everything this class writes lives under here — see delete(). */
    public const ROOT = 'catalog/';

    public const PRODUCT_DIR = self::ROOT.'products';

    public const CATEGORY_DIR = self::ROOT.'categories';

    /**
     * 2 MB. A phone photo of a shelf label is comfortably under this, and the
     * till has to pull dozens of these over a shop's DSL line.
     */
    public const MAX_KILOBYTES = 2048;

    /**
     * Formats every browser renders and getimagesize() can positively identify.
     * SVG is deliberately absent: it is a script container, and one served from
     * our own origin would be a stored-XSS delivery mechanism.
     *
     * @var array<int, string>
     */
    private const ALLOWED_TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];

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
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KILOBYTES],
        ];
    }

    /**
     * @param  string  $directory  one of the *_DIR constants — never request input
     * @param  string  $prefix     server-built, e.g. "product-42"
     * @param  string|null  $replacing  the path this upload supersedes, if any
     * @return string the stored path, relative to the public disk
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

        $path = Storage::disk('public')->putFileAs($directory, $file, $name);

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
     * Removes a stored image. Silently ignores anything outside the catalogue
     * tree: image_path is written only by this class, but a delete driven by a
     * column value is exactly the kind of call that should refuse to reach
     * outside its own directory if that ever stops being true.
     */
    public function delete(?string $path): void
    {
        if (blank($path) || ! Str::startsWith($path, self::ROOT) || Str::contains($path, '..')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    /**
     * The stored extension, decided by what the bytes actually are.
     *
     * The validation rules already sniff the content, but they trust finfo's
     * byte-signature guess — which a file only has to *start* like an image to
     * pass. getimagesize() parses the header properly and fails when no real
     * dimensions can be read, so a PHP script wearing a GIF89a hat does not get
     * through, and whatever does get through is stored under an extension we
     * chose rather than one the uploader picked.
     */
    private function verifiedExtension(UploadedFile $file): string
    {
        $info = @getimagesize((string) $file->getRealPath());

        $type = is_array($info) ? ($info[2] ?? null) : null;
        $width = is_array($info) ? (int) ($info[0] ?? 0) : 0;
        $height = is_array($info) ? (int) ($info[1] ?? 0) : 0;

        if (! is_int($type) || ! isset(self::ALLOWED_TYPES[$type]) || $width < 1 || $height < 1) {
            throw ValidationException::withMessages([
                'image' => ['That file is not a readable JPG, PNG or WebP image.'],
            ]);
        }

        return self::ALLOWED_TYPES[$type];
    }
}
