<?php namespace CRSCompany\FrameworCMcp\Classes;

/**
 * ContentSerializer renders Tailor content rows back into the MCP JSON shapes.
 *
 * Storage flattens mixins onto their rows and keeps nested forms as their own
 * rows, so this re-nests them into the {base, content} form the writers accept.
 */
abstract class ContentSerializer
{
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
    public static function block($item): array
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
    public static function contentFields($row, array $schema): array
    {
        $out = [];

        foreach ($schema as $name => $spec) {
            if (!empty($spec['repeater'])) {
                if (!empty($spec['recursive'])) {
                    $out[$name] = static::blocks($row->{$name});
                }
                elseif (!empty($spec['grouped'])) {
                    $out[$name] = static::groupedRows($row->{$name}, $spec);
                }
                elseif (!empty($spec['tree'])) {
                    $out[$name] = static::treeRows($row->{$name}, $spec);
                }
                else {
                    $out[$name] = static::repeaterRows($row->{$name}, $spec['fields'] ?? []);
                }
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
    public static function repeaterRows($rows, array $schema): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row->id] + static::contentFields($row, $schema);
        }

        return $out;
    }

    /**
     * groupedRows serializes a grouped (non-builder) repeater, e.g. form fields.
     */
    public static function groupedRows($rows, array $spec): array
    {
        $out = [];

        foreach ($rows as $row) {
            $group = $row->content_group;
            $groupSchema = $spec['groups'][$group]['fields'] ?? [];

            $out[] = [
                'id' => (int) $row->id,
                'group' => $group,
            ] + static::contentFields($row, $groupSchema);
        }

        return $out;
    }

    /**
     * treeRows assembles a nested-items relation back into a nested tree.
     *
     * The relation returns every row flat; nesting lives in parent_id.
     */
    public static function treeRows($rows, array $spec): array
    {
        $byParent = [];

        foreach ($rows as $row) {
            $parentKey = $row->parent_id === null ? 0 : (int) $row->parent_id;
            $byParent[$parentKey][] = $row;
        }

        return static::treeLevel($byParent, 0, $spec);
    }

    /**
     * treeLevel renders one level of a nested-items tree.
     */
    protected static function treeLevel(array $byParent, int $parentKey, array $spec): array
    {
        $out = [];

        foreach ($byParent[$parentKey] ?? [] as $row) {
            $item = ['id' => (int) $row->id] + static::contentFields($row, $spec['fields'] ?? []);

            $children = static::treeLevel($byParent, (int) $row->id, $spec);

            if ($children) {
                $item['children'] = $children;
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * pick reads a set of flattened attributes off a row.
     */
    public static function pick($row, array $names, array $schema): array
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
    public static function referenceValue($row, string $name)
    {
        $related = $row->{$name};

        // An entries field without maxItems resolves as a collection even
        // when validation caps it at one item.
        if ($related instanceof \Illuminate\Support\Collection) {
            $related = $related->first();
        }

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
    public static function castOut($value, array $spec)
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
