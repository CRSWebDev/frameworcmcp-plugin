<?php namespace CRSCompany\FrameworCMcp\Classes;

use Tailor\Models\EntryRecord;

/**
 * EntryGuard blocks deletion of entries other content still points at.
 *
 * Deleting a referenced Form, Menu or Prefill would leave broken blocks on
 * live pages, so the delete endpoints refuse unless the caller forces it. A
 * Builder page can also be pointed at by a pagefinder field (every Buttons
 * mixin's buttonLink, a Menu's navigation items, the Navigation single's
 * buttons), so those links are tracked too.
 */
class EntryGuard
{
    /**
     * @var string pattern matching a stored pagefinder link, capturing its id.
     *
     * ContentWriter::resolvePageFinder stores links as
     * `october://entry-{uuid}@link/{pageId}?cms_page=page`; the id is the only
     * part this guard needs.
     */
    const PAGEFINDER_LINK = '/@link\/(\d+)/';

    /**
     * assertDeletable throws 422 when the entry is referenced, unless forced.
     */
    public static function assertDeletable(string $section, EntryRecord $entry, bool $force): void
    {
        if ($force) {
            return;
        }

        $hosts = static::references($section, (int) $entry->id);

        if ($hosts) {
            throw ApiException::invalid([
                'id' => 'This ' . $section . ' entry is still referenced by: ' . implode('; ', $hosts)
                    . '. Remove those references first, or pass ?force=1 to delete anyway.',
            ]);
        }
    }

    /**
     * references lists human-readable hosts still pointing at the entry.
     */
    public static function references(string $section, int $id): array
    {
        $hosts = [];

        foreach (['Builder' => 'Page', 'Prefill' => 'Prefill'] as $hostSection => $label) {
            // A Prefill embeds Mixins/Builder, whose groups include the
            // Prefill block, so Prefill->Prefill references are real. Skip
            // only a self-reference (a prefill cannot reference itself) so
            // the listing is not polluted with the very entry being deleted.
            foreach (EntryRecord::inSection($hostSection)->get() as $record) {
                if ($hostSection === $section && (int) $record->id === $id) {
                    continue;
                }

                $blocks = PageSerializer::blocks($record->builder);

                if ($section === 'Builder'
                    ? static::blocksReferencePage($blocks, $id)
                    : static::blocksReference($blocks, $section, $id)) {
                    $hosts[] = $label . ' "' . $record->title . '" (id ' . $record->id . ')';
                }
            }
        }

        if ($section === 'Menu') {
            foreach (['Navigation', 'Footer'] as $single) {
                $record = EntryRecord::inSection($single)->first();
                $nav = $record ? $record->nav : null;
                $linked = $nav ? $nav->first() : null;

                if ($linked && (int) $linked->id === $id) {
                    $hosts[] = $single . ' single';
                }
            }
        }

        if ($section === 'Builder') {
            foreach (static::pageFinderHosts($id) as $host) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * pageFinderHosts lists non-page hosts whose pagefinder links target $id.
     *
     * Pagefinder links always resolve against the Builder section, so only
     * Builder pages can be the link target. Menus link pages in their
     * navigation tree, and the Navigation single links pages through its buttons
     * mixin — both surface as `october://...@link/{id}` strings in the
     * serialized payload.
     */
    protected static function pageFinderHosts(int $id): array
    {
        $hosts = [];

        foreach (EntryRecord::inSection('Menu')->get() as $menu) {
            $serialized = MenuSerializer::full($menu);

            if (static::containsPageFinderId($serialized['navigation'] ?? [], $id)) {
                $hosts[] = 'Menu "' . $menu->title . '" (id ' . $menu->id . ')';
            }
        }

        foreach (['Navigation'] as $handle) {
            $record = EntryRecord::inSection($handle)->first();

            if (!$record) {
                continue;
            }

            $serialized = SingleWriter::read($handle);

            if (static::containsPageFinderId($serialized['fields'] ?? [], $id)) {
                $hosts[] = $handle . ' single';
            }
        }

        return $hosts;
    }

    /**
     * containsPageFinderId deep-scans a serialized payload for a pagefinder id.
     */
    protected static function containsPageFinderId($value, int $id): bool
    {
        if (is_string($value)) {
            return preg_match(static::PAGEFINDER_LINK, $value, $m) && (int) $m[1] === $id;
        }

        if (is_array($value) || is_object($value)) {
            foreach ((array) $value as $child) {
                if (static::containsPageFinderId($child, $id)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * blocksReference deep-scans serialized blocks for a reference.
     */
    protected static function blocksReference(array $blocks, string $section, int $id): bool
    {
        foreach ($blocks as $block) {
            $block = (array) $block;

            $ref = null;
            if ($section === 'Form') {
                $ref = $block['form'] ?? null;
            }
            elseif ($section === 'Menu') {
                $ref = ((array) ($block['content'] ?? []))['menu'] ?? null;
            }
            elseif ($section === 'Prefill') {
                $ref = ((array) ($block['content'] ?? []))['block'] ?? null;
            }

            if (is_array($ref) && (int) ($ref['id'] ?? 0) === $id) {
                return true;
            }

            // Recurse into nested builders, e.g. blocks inside Columns.
            foreach ((array) ($block['content'] ?? []) as $value) {
                if (!is_array($value)) {
                    continue;
                }

                foreach ($value as $row) {
                    $row = (array) $row;

                    if (isset($row['builder']) && is_array($row['builder'])
                        && static::blocksReference($row['builder'], $section, $id)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * blocksReferencePage deep-scans serialized blocks for a pagefinder link.
     *
     * Pagefinder ids are stored as `october://...@link/{id}` strings on
     * pagefinder-typed fields; any such string anywhere in a block (its base,
     * its content, including nested builders) is a reference.
     */
    protected static function blocksReferencePage(array $blocks, int $id): bool
    {
        foreach ($blocks as $block) {
            if (static::containsPageFinderId($block, $id)) {
                return true;
            }
        }

        return false;
    }
}
