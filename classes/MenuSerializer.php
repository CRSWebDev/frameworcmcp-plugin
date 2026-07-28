<?php namespace CRSCompany\FrameworCMcp\Classes;

use Tailor\Models\EntryRecord;

/**
 * MenuSerializer renders Menu entries as MCP JSON.
 */
class MenuSerializer extends ContentSerializer
{
    /**
     * summary is the shape used by the menu index.
     */
    public static function summary(EntryRecord $menu): array
    {
        return [
            'id' => (int) $menu->id,
            'title' => $menu->title,
            'slug' => $menu->slug,
            'is_enabled' => (bool) $menu->is_enabled,
            'site_id' => $menu->site_id !== null ? (int) $menu->site_id : null,
            'item_count' => $menu->navigation()->count(),
            'updated_at' => optional($menu->updated_at)->toAtomString(),
        ];
    }

    /**
     * full includes the navigation tree.
     */
    public static function full(EntryRecord $menu): array
    {
        return static::summary($menu) + [
            'navigation' => static::treeRows($menu->navigation, MenuWriter::treeSpec()),
        ];
    }
}
