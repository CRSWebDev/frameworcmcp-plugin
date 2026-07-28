<?php namespace CRSCompany\FrameworCMcp\Classes;

use Site;
use Tailor\Models\EntryRecord;

/**
 * PageSerializer renders Builder pages and Prefill entries as MCP JSON.
 */
class PageSerializer extends ContentSerializer
{
    /**
     * summary is the shape used by the page index.
     */
    public static function summary(EntryRecord $page): array
    {
        return [
            'id' => (int) $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'fullslug' => $page->fullslug,
            'is_enabled' => (bool) $page->is_enabled,
            'site_id' => $page->site_id !== null ? (int) $page->site_id : null,
            'parent_id' => $page->parent_id !== null ? (int) $page->parent_id : null,
            'block_count' => $page->builder()->count(),
            'updated_at' => optional($page->updated_at)->toAtomString(),
        ];
    }

    /**
     * full includes the builder array and sibling translations.
     */
    public static function full(EntryRecord $page): array
    {
        return static::summary($page) + [
            'metaTitle' => $page->metaTitle,
            'metaDescription' => $page->metaDescription,
            'ogImage' => static::castOut($page->ogImage, ['type' => 'mediafinder', 'max_items' => 1]),
            'menuStyle' => $page->menuStyle,
            'menuHide' => $page->menuHide,
            'translations' => static::translations($page),
            'builder' => static::blocks($page->builder),
        ];
    }

    /**
     * prefillSummary is the shape used by the prefill index.
     *
     * Prefills are plain entries: no fullslug, no tree, no menu settings.
     */
    public static function prefillSummary(EntryRecord $prefill): array
    {
        return [
            'id' => (int) $prefill->id,
            'title' => $prefill->title,
            'slug' => $prefill->slug,
            'is_enabled' => (bool) $prefill->is_enabled,
            'site_id' => $prefill->site_id !== null ? (int) $prefill->site_id : null,
            'block_count' => $prefill->builder()->count(),
            'updated_at' => optional($prefill->updated_at)->toAtomString(),
        ];
    }

    /**
     * prefillFull includes the builder array and sibling translations.
     */
    public static function prefillFull(EntryRecord $prefill): array
    {
        return static::prefillSummary($prefill) + [
            'translations' => static::translations($prefill),
            'builder' => static::blocks($prefill->builder),
        ];
    }

    /**
     * translations maps site id => record id for every sibling sharing a root.
     */
    public static function translations(EntryRecord $page): array
    {
        $rootId = $page->site_root_id ?: $page->id;

        $out = [];

        Site::withGlobalContext(function () use ($page, $rootId, &$out) {
            $siblings = $page->newQueryWithoutScopes()
                ->where('site_root_id', $rootId)
                ->get();

            foreach ($siblings as $sibling) {
                if ($sibling->site_id === null) {
                    continue;
                }

                $out[(string) $sibling->site_id] = (int) $sibling->id;
            }
        });

        return $out;
    }
}
