<?php namespace CRSCompany\FrameworCMcp\Classes;

use Date;
use Config;
use ApplicationException;
use Media\Classes\MediaLibrary;
use Media\Classes\MediaLibraryItem;
use October\Rain\Filesystem\Definitions as FileDefinitions;

/**
 * MediaPaths normalises and checks media library paths supplied over the API.
 *
 * Media is assignment-only. A payload names a file that already exists in the
 * library and the path is stored verbatim, exactly as the backend media picker
 * writes it — a `mediafinder` is a text column, not a relation. Nothing here
 * uploads, moves or deletes.
 */
class MediaPaths
{
    /**
     * normalise turns a caller-supplied value into a library path.
     *
     * Accepts what a model realistically sends: a bare filename with no leading
     * slash, a path already carrying the media disk prefix, a full public URL,
     * or a percent-encoded path. An empty value returns '' — callers read that
     * as "clear the field", not as an error.
     *
     * The library root is rejected unless $allowRoot: '/' is a valid folder to
     * browse but never a valid file to assign.
     */
    public static function normalise($value, ?string &$error = null, bool $allowRoot = false): ?string
    {
        $error = null;

        if ($value === null) {
            return '';
        }

        if (is_array($value) || is_object($value) || is_bool($value)) {
            $error = 'Expected a media library path string, e.g. "/images/hero.jpg".';
            return null;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return '';
        }

        $path = rawurldecode($raw);

        // A full URL: keep the path component only. The host is deliberately
        // not checked — a path copied from a staging site still names the file.
        if (preg_match('#^https?://#i', $path)) {
            $path = (string) parse_url($path, PHP_URL_PATH);
        }

        // Strip the media disk prefix so /storage/app/media/x.jpg and the full
        // URL form both collapse onto the library-relative /x.jpg.
        $prefix = rtrim((string) Config::get('filesystems.disks.media.url', '/storage/app/media'), '/');

        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
        }

        try {
            // October's own guard: normalises separators, prepends the leading
            // slash, enforces the character allowlist and rejects traversal.
            $path = MediaLibrary::validatePath($path);
        }
        catch (ApplicationException $ex) {
            $error = 'Not a valid media library path: "' . $raw . '".';
            return null;
        }

        if ($path === '/' && !$allowRoot) {
            $error = 'Expected a path to a file, not the media library root.';
            return null;
        }

        return $path;
    }

    /**
     * fileExists reports whether a file sits at this library path.
     *
     * Storage::exists() is Flysystem has(), which is true for directories too,
     * so a folder path would otherwise validate as a file and then throw deep
     * in Flysystem when its size is read. findFile() cannot stand in here: it
     * reads lastModified() and throws on a missing path. listFolderContents()
     * cannot either — it is cached for media.item_cache_ttl, so a file uploaded
     * a minute ago would read as absent.
     */
    public static function fileExists(string $path): bool
    {
        $library = MediaLibrary::instance();

        return $library->exists($path) && !$library->folderExists($path);
    }

    /**
     * folderExists reports whether a folder sits at this library path.
     */
    public static function folderExists(string $path): bool
    {
        if ($path === '' || $path === '/') {
            return true;
        }

        return MediaLibrary::instance()->folderExists($path);
    }

    /**
     * isImageLike reports whether a path may sit in a `mode: image` field.
     *
     * Deliberately wider than image_extensions, and deliberately independent of
     * how an install configures that list. `mode` is only a hint for the
     * backend widget — October never validates it — and real content relies on
     * the slack: the Navigation logo is an SVG (stock October classifies SVG as
     * a document, though this install adds it to media.image_extensions), and
     * Header.image holds an .mp4 when isVideoBg is on. Narrowing this would
     * reject data the backend itself wrote, and would do so differently per
     * install. It still keeps a .pdf out of a hero image.
     */
    public static function isImageLike(string $path): bool
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === '') {
            return false;
        }

        $allowed = array_merge(
            (array) FileDefinitions::get('image_extensions'),
            ['svg'],
            (array) FileDefinitions::get('video_extensions')
        );

        return in_array($extension, array_map('strtolower', $allowed), true);
    }

    /**
     * describe renders a file item for the wire.
     *
     * @return array{path:string,name:string,file_type:?string,extension:string,size:int,last_modified:?string,url:?string}
     */
    public static function describe(MediaLibraryItem $item): array
    {
        return [
            'path' => $item->path,
            'name' => basename($item->path),
            'file_type' => $item->getFileType(),
            'extension' => strtolower((string) pathinfo($item->path, PATHINFO_EXTENSION)),
            'size' => (int) $item->size,
            'last_modified' => $item->lastModified
                ? Date::createFromTimestamp($item->lastModified)->toAtomString()
                : null,
            'url' => $item->publicUrl,
        ];
    }

    /**
     * describeFolder renders a folder item for the wire.
     *
     * A folder's `size` is its item count, not a byte count.
     *
     * @return array{path:string,name:string,item_count:int}
     */
    public static function describeFolder(MediaLibraryItem $item): array
    {
        return [
            'path' => $item->path,
            'name' => basename($item->path),
            'item_count' => (int) $item->size,
        ];
    }
}
