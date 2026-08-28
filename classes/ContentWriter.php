<?php namespace CRSCompany\FrameworCMcp\Classes;

use Tailor\Classes\BlueprintIndexer;
use Tailor\Models\EntryRecord;

/**
 * ContentWriter is the shared machinery for writing Tailor content rows.
 *
 * A block is not a JSON blob: it is a RepeaterItem row carrying flattened mixin
 * fields, plus a NestedFormItem row for its `content`, plus any further
 * repeater rows below that. Everything therefore goes through the model layer
 * so Tailor's afterRelation hook can attach the right fieldset before the row
 * is filled.
 *
 * PageWriter (Builder/Prefill), FormWriter, MenuWriter and SingleWriter all
 * build on this class so every feature validates and stores content the same
 * way.
 */
abstract class ContentWriter
{
    /**
     * writeBlocks creates every builder block under a host's repeater relation.
     */
    public static function writeBlocks($host, string $relation, array $blocks): void
    {
        $sort = 1;

        foreach ($blocks as $block) {
            static::writeBlock($host, $relation, (array) $block, $sort++);
        }
    }

    /**
     * writeBlock creates one block row plus its nested content row.
     */
    public static function writeBlock($host, string $relation, array $block, int $sort)
    {
        $group = $block['content_group'];
        $schema = BlockSchema::get($group);

        $item = $host->makeRelation($relation);
        $item->content_group = $group;
        $item->extendWithBlueprint();

        $base = static::prepare((array) ($block['base'] ?? []), $schema['base']);

        // Block-level entries references live alongside the base fields. They
        // are assigned by relation name, not by the underlying `<field>_id`
        // column, which the model does not treat as fillable.
        foreach ($schema['base'] as $name => $spec) {
            if (isset($spec['column']) && array_key_exists($name, $block)) {
                $base[$name] = static::referenceId($block[$name]);
            }
        }

        $item->fill($base);
        $item->sort_order = $sort;
        $host->{$relation}()->add($item);

        $content = static::prepare((array) ($block['content'] ?? []), $schema['content']);

        $contentRow = $item->makeRelation('content');
        $contentRow->fill($content);
        $item->content()->add($contentRow);

        static::writeNested($contentRow, (array) ($block['content'] ?? []), $schema['content']);

        return $item;
    }

    /**
     * writeNested creates the repeater and nested-form rows below a content row.
     */
    public static function writeNested($row, array $data, array $schema): void
    {
        foreach ($schema as $name => $spec) {
            if (!array_key_exists($name, $data) || $data[$name] === null) {
                continue;
            }

            if (!empty($spec['repeater'])) {
                if (!empty($spec['recursive'])) {
                    // A nested builder, e.g. a column's blocks.
                    static::writeBlocks($row, $name, (array) $data[$name]);
                    continue;
                }

                if (!empty($spec['grouped'])) {
                    static::writeGroupedRows($row, $name, (array) $data[$name], $spec);
                    continue;
                }

                if (!empty($spec['tree'])) {
                    static::writeTreeRows($row, $name, (array) $data[$name], $spec, null);
                    continue;
                }

                $sort = 1;
                foreach ((array) $data[$name] as $entry) {
                    $entry = (array) $entry;

                    $child = $row->makeRelation($name);
                    $child->extendWithBlueprint();
                    $child->fill(static::prepare($entry, $spec['fields'] ?? []));
                    $child->sort_order = $sort++;
                    $row->{$name}()->add($child);

                    static::writeNested($child, $entry, $spec['fields'] ?? []);
                }
                continue;
            }

            if (isset($spec['fields'])) {
                $child = $row->makeRelation($name);
                $child->fill(static::prepare((array) $data[$name], $spec['fields']));
                $row->{$name}()->add($child);

                static::writeNested($child, (array) $data[$name], $spec['fields']);
            }
        }
    }

    /**
     * writeGroupedRows creates the rows of a grouped (non-builder) repeater.
     *
     * Each payload row names its group, e.g. a form field's type; the group key
     * is stored as content_group exactly like a builder block.
     */
    public static function writeGroupedRows($host, string $relation, array $rows, array $spec): void
    {
        $sort = 1;

        foreach ($rows as $entry) {
            $entry = (array) $entry;
            $group = $entry['group'];
            $groupSchema = $spec['groups'][$group]['fields'] ?? [];

            $child = $host->makeRelation($relation);
            $child->content_group = $group;
            $child->extendWithBlueprint();
            $child->fill(static::prepare($entry, $groupSchema));
            $child->sort_order = $sort++;
            $host->{$relation}()->add($child);

            static::writeNested($child, $entry, $groupSchema);
        }
    }

    /**
     * writeTreeRows creates the rows of a nested-items tree, level by level.
     *
     * Every row belongs to the host relation; nesting is expressed by the
     * child's parent_id pointing at the saved parent row.
     */
    public static function writeTreeRows($host, string $relation, array $rows, array $spec, ?int $parentId): void
    {
        $sort = 1;

        foreach ($rows as $entry) {
            $entry = (array) $entry;
            $children = (array) ($entry['children'] ?? []);

            $child = $host->makeRelation($relation);
            $child->extendWithBlueprint();
            $child->fill(static::prepare($entry, $spec['fields'] ?? []));
            $child->parent_id = $parentId;
            $child->sort_order = $sort++;
            $host->{$relation}()->add($child);

            if ($children) {
                static::writeTreeRows($host, $relation, $children, $spec, (int) $child->id);
            }
        }
    }

    /**
     * prepare normalises a flat set of values for storage.
     *
     * Nested structures are handled separately by writeNested; only scalar and
     * list fields are returned here.
     */
    public static function prepare(array $data, array $schema): array
    {
        $out = [];

        foreach ($schema as $name => $spec) {
            if (!array_key_exists($name, $data)) {
                continue;
            }

            // fileupload only: a database attachment the API cannot address.
            if (!empty($spec['readonly'])) {
                continue;
            }

            if (!empty($spec['media'])) {
                // A key absent from the payload never reaches here, so an
                // explicit value — a path or an empty one — is always meant.
                $out[$name] = static::castMediaIn($data[$name], $spec);
                continue;
            }

            if (!empty($spec['repeater']) || isset($spec['fields'])) {
                continue;
            }

            if (isset($spec['column'])) {
                // Assign by relation name; `<field>_id` is not fillable. A
                // join-table relation syncs from an array of ids on save.
                $out[$name] = !empty($spec['multiple'])
                    ? static::referenceIds($data[$name])
                    : static::referenceId($data[$name]);
                continue;
            }

            $out[$name] = static::castIn($data[$name], $spec);
        }

        return $out;
    }

    /**
     * castMediaIn converts a media payload value into what Tailor stores.
     *
     * A maxItems:1 mediafinder is a plain string column and stores '' when
     * unset; any other is jsonable and stores a list. Both shapes match what
     * the backend media widget writes, which is what lets a block round-trip
     * through the API and still open correctly in the backend.
     *
     * Paths are already validated by validateMedia at this point, so a value
     * that fails to normalise here is dropped rather than reported.
     */
    public static function castMediaIn($value, array $spec)
    {
        $multiple = !empty($spec['multiple']);

        if (static::isEmptyValue($value)) {
            return $multiple ? [] : '';
        }

        $paths = [];

        foreach ((is_array($value) ? $value : [$value]) as $single) {
            $path = MediaPaths::normalise($single, $error);

            if ($error === null && $path !== null && $path !== '') {
                $paths[] = $path;
            }
        }

        return $multiple ? $paths : ($paths[0] ?? '');
    }

    /**
     * castIn converts a JSON value into what Tailor stores.
     */
    public static function castIn($value, array $spec)
    {
        $type = $spec['type'] ?? 'text';

        if ($type === 'switch') {
            return $value ? '1' : '0';
        }

        if (in_array($type, ['taglist', 'checkboxlist'], true)) {
            return array_values((array) $value);
        }

        if ($type === 'pagefinder') {
            return static::resolvePageFinder($value);
        }

        return $value;
    }

    /**
     * referenceId accepts either a bare id or a {id: n} object.
     */
    public static function referenceId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return isset($value['id']) ? (int) $value['id'] : null;
        }

        if (is_object($value)) {
            return isset($value->id) ? (int) $value->id : null;
        }

        return (int) $value;
    }

    /**
     * referenceIds normalises a reference value into an array of ids.
     *
     * Accepts null, a bare id, {id: n}, or an array of either.
     */
    public static function referenceIds($value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_array($value) && !isset($value['id'])) {
            $ids = [];

            foreach ($value as $single) {
                $id = static::referenceId($single);

                if ($id !== null) {
                    $ids[] = $id;
                }
            }

            return $ids;
        }

        $id = static::referenceId($value);

        return $id === null ? [] : [$id];
    }

    /**
     * resolvePageFinder turns {page_id: n} into the october:// link scheme.
     *
     * Pagefinder values are otherwise opaque strings: a path, an anchor or a
     * full URL pass through untouched.
     */
    public static function resolvePageFinder($value)
    {
        $pageId = static::pageFinderPageId($value);

        if ($pageId === null) {
            return $value;
        }

        $blueprint = BlueprintIndexer::instance()->findByHandle(BlockSchema::SECTION);

        return 'october://entry-' . $blueprint->uuid . '@link/' . $pageId . '?cms_page=page';
    }

    /**
     * pageFinderPageId extracts the page id from a {page_id: n} value, if any.
     */
    protected static function pageFinderPageId($value): ?int
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (is_array($value) && isset($value['page_id'])) {
            return (int) $value['page_id'];
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * validateBlocks walks a builder payload against the live blueprint schema.
     */
    public static function validateBlocks(array $blocks, string $path = 'builder'): void
    {
        $errors = [];

        foreach (array_values($blocks) as $i => $block) {
            $block = (array) $block;
            $at = $path . '.' . $i;
            $group = $block['content_group'] ?? null;

            if (!$group) {
                $errors[$at . '.content_group'] = 'This field is required.';
                continue;
            }

            if (!in_array($group, BlockSchema::names(), true)) {
                $errors[$at . '.content_group'] = 'Unknown block "' . $group . '". Available: '
                    . implode(', ', BlockSchema::names()) . '.';
                continue;
            }

            $schema = BlockSchema::get($group);

            static::validateFields((array) ($block['base'] ?? []), $schema['base'], $at . '.base', $errors);
            static::validateFields((array) ($block['content'] ?? []), $schema['content'], $at . '.content', $errors);

            // Block-level references, e.g. Form's `form`.
            foreach ($schema['base'] as $name => $spec) {
                if (isset($spec['column']) && array_key_exists($name, $block)) {
                    static::validateReference($block[$name], $spec, $at . '.' . $name, $errors);
                }
            }
        }

        if ($errors) {
            throw ApiException::invalid($errors);
        }
    }

    /**
     * validateFields checks one level of a content payload.
     */
    public static function validateFields(array $data, array $schema, string $path, array &$errors): void
    {
        // The serializer emits an `id` on every repeater/grouped/tree row and
        // on options sub-rows; it is reserved and never a writable field, so
        // silently drop it before the unknown-field check would reject a
        // round-tripped payload.
        unset($data['id']);

        foreach ($data as $name => $value) {
            if (!isset($schema[$name])) {
                $errors[$path . '.' . $name] = 'Unknown field. Allowed: ' . implode(', ', array_keys($schema)) . '.';
                continue;
            }

            $spec = $schema[$name];
            $at = $path . '.' . $name;

            if (!empty($spec['readonly']) && !static::isEmptyValue($value)) {
                $errors[$at] = 'This field is a database attachment and cannot be set over the API.';
                continue;
            }

            if (!empty($spec['media'])) {
                static::validateMedia($value, $spec, $at, $errors);
                continue;
            }

            if (isset($spec['column'])) {
                static::validateReference($value, $spec, $at, $errors);
                continue;
            }

            if (!empty($spec['repeater'])) {
                if (!is_array($value)) {
                    $errors[$at] = 'Expected an array of items.';
                    continue;
                }

                if (!empty($spec['recursive'])) {
                    try {
                        static::validateBlocks($value, $at);
                    }
                    catch (ApiException $ex) {
                        $errors = array_merge($errors, $ex->getErrors());
                    }
                    continue;
                }

                if (!empty($spec['grouped'])) {
                    static::validateGroupedRows($value, $spec, $at, $errors);
                    continue;
                }

                if (!empty($spec['tree'])) {
                    static::validateTreeRows($value, $spec, $at, $errors, 1);
                    continue;
                }

                foreach (array_values($value) as $i => $entry) {
                    static::validateFields((array) $entry, $spec['fields'] ?? [], $at . '.' . $i, $errors);
                }
                continue;
            }

            if (isset($spec['fields'])) {
                if (!is_array($value) && !is_object($value)) {
                    $errors[$at] = 'Expected an object.';
                    continue;
                }

                static::validateFields((array) $value, $spec['fields'], $at, $errors);
                continue;
            }

            if (($spec['type'] ?? null) === 'pagefinder') {
                static::validatePageFinder($value, $at, $errors);
                continue;
            }

            if (!empty($spec['options']) && !static::isEmptyValue($value)) {
                $allowed = array_map('strval', array_keys($spec['options']));

                foreach ((array) $value as $single) {
                    if (!in_array((string) $single, $allowed, true) && $spec['type'] !== 'taglist') {
                        $errors[$at] = 'Invalid value "' . $single . '". Allowed: ' . implode(', ', $allowed) . '.';
                        break;
                    }
                }
            }
        }
    }

    /**
     * validateMedia checks a media library assignment.
     *
     * An empty value is valid and means "clear this field" — the caller can
     * only reach here by sending the key explicitly, since prepare() and the
     * media merge both key off the presence of the key, not its value.
     */
    public static function validateMedia($value, array $spec, string $path, array &$errors): void
    {
        if (static::isEmptyValue($value)) {
            return;
        }

        $multiple = !empty($spec['multiple']);

        if (!$multiple && is_array($value)) {
            $errors[$path] = 'Expected a single media path string such as "/images/hero.jpg", not an array.';
            return;
        }

        if ($multiple && is_array($value) && array_values($value) !== $value) {
            $errors[$path] = 'Expected an array of media path strings.';
            return;
        }

        $values = is_array($value) ? array_values($value) : [$value];
        $max = $spec['max_items'] ?? null;

        if (is_int($max) && count($values) > $max) {
            $errors[$path] = 'At most ' . $max . ' file(s) allowed here, ' . count($values) . ' given.';
            return;
        }

        foreach ($values as $single) {
            $normalised = MediaPaths::normalise($single, $error);

            if ($error !== null) {
                $errors[$path] = $error;
                return;
            }

            if ($normalised === '') {
                continue;
            }

            if (!MediaPaths::fileExists($normalised)) {
                $errors[$path] = 'No file at "' . $normalised . '" in the media library. '
                    . 'Call GET /media or GET /media/search to find the real path; this API cannot upload files.';
                return;
            }

            if (($spec['mode'] ?? null) === 'image' && !MediaPaths::isImageLike($normalised)) {
                $errors[$path] = '"' . $normalised . '" is not an image, SVG or video, and this field renders as an image.';
                return;
            }
        }
    }

    /**
     * validateGroupedRows checks the rows of a grouped (non-builder) repeater.
     */
    public static function validateGroupedRows(array $rows, array $spec, string $path, array &$errors): void
    {
        $known = array_keys($spec['groups'] ?? []);

        foreach (array_values($rows) as $i => $entry) {
            $entry = (array) $entry;
            $at = $path . '.' . $i;
            $group = $entry['group'] ?? null;

            if (!$group) {
                $errors[$at . '.group'] = 'This field is required.';
                continue;
            }

            if (!in_array($group, $known, true)) {
                $errors[$at . '.group'] = 'Unknown group "' . $group . '". Available: ' . implode(', ', $known) . '.';
                continue;
            }

            unset($entry['group']);
            static::validateFields($entry, $spec['groups'][$group]['fields'] ?? [], $at, $errors);
        }
    }

    /**
     * validateTreeRows checks nested-items rows and enforces the depth limit.
     */
    public static function validateTreeRows(array $rows, array $spec, string $path, array &$errors, int $depth): void
    {
        $maxDepth = (int) ($spec['max_depth'] ?? 1);

        foreach (array_values($rows) as $i => $entry) {
            $entry = (array) $entry;
            $at = $path . '.' . $i;

            $children = $entry['children'] ?? null;
            unset($entry['children']);

            static::validateFields($entry, $spec['fields'] ?? [], $at, $errors);

            if ($children === null || $children === []) {
                continue;
            }

            if (!is_array($children)) {
                $errors[$at . '.children'] = 'Expected an array of items.';
                continue;
            }

            if ($depth >= $maxDepth) {
                $errors[$at . '.children'] = 'Items cannot nest deeper than ' . $maxDepth . ' level(s).';
                continue;
            }

            static::validateTreeRows($children, $spec, $at . '.children', $errors, $depth + 1);
        }
    }

    /**
     * validateReference checks that a linked entry exists in the right section.
     */
    public static function validateReference($value, array $spec, string $path, array &$errors): void
    {
        $ids = !empty($spec['multiple']) ? static::referenceIds($value) : (array) static::referenceId($value);
        $source = $spec['entries_source'] ?? null;

        if (!$ids || !$source) {
            return;
        }

        $maxItems = $spec['max_items'] ?? null;

        if ($maxItems !== null && count($ids) > $maxItems) {
            $errors[$path] = 'At most ' . $maxItems . ' item(s) allowed.';
            return;
        }

        foreach ($ids as $id) {
            if (!EntryRecord::inSection($source)->where('id', $id)->exists()) {
                $errors[$path] = 'No ' . $source . ' entry with id ' . $id . ' on this site.';
            }
        }
    }

    /**
     * validatePageFinder checks a {page_id} value points at a real page.
     */
    public static function validatePageFinder($value, string $path, array &$errors): void
    {
        $pageId = static::pageFinderPageId($value);

        if ($pageId === null) {
            if (is_array($value) || is_object($value)) {
                $errors[$path] = 'Expected a URL string or {"page_id": n}.';
            }

            return;
        }

        $exists = EntryRecord::inSection(BlockSchema::SECTION)->where('id', $pageId)->exists();

        if (!$exists) {
            $errors[$path] = 'No page with id ' . $pageId . ' on this site.';
        }
    }

    /**
     * isEmptyValue treats null, "" and [] alike.
     */
    public static function isEmptyValue($value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
