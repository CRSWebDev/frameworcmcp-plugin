<?php namespace CRSCompany\FrameworCMcp\Classes;

use Site;
use Tailor\Models\EntryRecord;

/**
 * PageSerializer renders a Builder entry as the JSON shape the MCP expects.
 *
 * Storage flattens the BaseBlock mixin onto the block row and keeps the nested
 * form in its own row, so this re-nests them back into {base, content}.
 */
class PageSerializer
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
            'menuStyle' => $page->menuStyle,
            'menuHide' => $page->menuHide,
            'translations' => static::translations($page),
            'builder' => static::blocks($page->builder),
        ];
    }

    /**
     * translations maps site id => page id for every sibling sharing a root.
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

    /**
     * blocks serializes a builder relation into the MCP block array.
     */
    public static function blocks($items): array
    {
        $out = [];

        foreach ($items as $item) {
            $out[] = static::block($item);
        }

        return $out;
    }

    /**
     * block serializes one repeater row plus its nested content row.
     */
    protected static function block($item): array
    {
        $group = $item->content_group;
        $schema = BlockSchema::all()[$group] ?? null;

        $block = [
            'id' => (int) $item->id,
            'content_group' => $group,
            'base' => static::pick($item, array_keys($schema['base'] ?? []), $schema['base'] ?? []),
        ];

        // A block-level entries reference, e.g. Form's `form` field.
        foreach (($schema['base'] ?? []) as $name => $spec) {
            if (isset($spec['column'])) {
                $block[$name] = static::referenceValue($item, $name);
                unset($block['base'][$name]);
            }
        }

        $content = $item->content;
        $block['content'] = $content
            ? static::contentFields($content, $schema['content'] ?? [])
            : new \stdClass();

        return $block;
    }

    /**
     * contentFields serializes a nested form row, recursing into repeaters.
     */
    protected static function contentFields($row, array $schema): array
    {
        $out = [];

        foreach ($schema as $name => $spec) {
            if (!empty($spec['repeater'])) {
                $out[$name] = !empty($spec['recursive'])
                    ? static::blocks($row->{$name})
                    : static::repeaterRows($row->{$name}, $spec['fields'] ?? []);
                continue;
            }

            if (isset($spec['fields'])) {
                $nested = $row->{$name};
                $out[$name] = $nested ? static::contentFields($nested, $spec['fields']) : new \stdClass();
                continue;
            }

            if (isset($spec['column'])) {
                $out[$name] = static::referenceValue($row, $name);
                continue;
            }

            $out[$name] = static::castOut($row->{$name}, $spec);
        }

        return $out;
    }

    /**
     * repeaterRows serializes a plain (non-grouped) repeater.
     */
    protected static function repeaterRows($rows, array $schema): array
    {
        $out = [];

        foreach ($rows as $row) {
            $item = ['id' => (int) $row->id];

            foreach ($schema as $name => $spec) {
                if (!empty($spec['repeater'])) {
                    $item[$name] = !empty($spec['recursive'])
                        ? static::blocks($row->{$name})
                        : static::repeaterRows($row->{$name}, $spec['fields'] ?? []);
                    continue;
                }

                $item[$name] = static::castOut($row->{$name}, $spec);
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * pick reads a set of flattened attributes off a row.
     */
    protected static function pick($row, array $names, array $schema): array
    {
        $out = [];

        foreach ($names as $name) {
            $spec = $schema[$name] ?? [];

            if (isset($spec['fields']) || !empty($spec['repeater'])) {
                continue;
            }

            $out[$name] = static::castOut($row->{$name}, $spec);
        }

        return $out;
    }

    /**
     * referenceValue renders an entries link as {id, title} or null.
     */
    protected static function referenceValue($row, string $name)
    {
        $related = $row->{$name};

        if (!$related) {
            return null;
        }

        return [
            'id' => (int) $related->id,
            'title' => $related->title,
        ];
    }

    /**
     * castOut normalises stored values back into natural JSON types.
     *
     * Switches round-trip as "1"/"0" strings in storage but should read as
     * booleans, and empty arrays should stay arrays rather than becoming "".
     */
    protected static function castOut($value, array $spec)
    {
        $type = $spec['type'] ?? 'text';

        if ($type === 'switch') {
            return (bool) $value;
        }

        if (in_array($type, ['taglist', 'checkboxlist'], true)) {
            if ($value === null || $value === '' || $value === []) {
                return [];
            }

            if (is_array($value)) {
                return array_values($value);
            }

            // Empty list fields can surface as the literal string "[]".
            if (is_string($value)) {
                $decoded = json_decode($value, true);

                return is_array($decoded) ? array_values($decoded) : [$value];
            }

            return array_values((array) $value);
        }

        if ($type === 'mediafinder' || $type === 'fileupload') {
            if ($value === null || $value === '') {
                return ($spec['max_items'] ?? null) === 1 ? '' : $value;
            }

            return $value;
        }

        return $value;
    }
}
