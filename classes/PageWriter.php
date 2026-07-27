<?php namespace CRSCompany\FrameworCMcp\Classes;

use DB;
use Tailor\Classes\RecordIndexer;
use Tailor\Models\EntryRecord;

/**
 * PageWriter turns MCP JSON into Tailor records.
 *
 * A block is not a JSON blob: it is a RepeaterItem row carrying the flattened
 * BaseBlock fields, plus a NestedFormItem row for its `content`, plus any
 * further repeater rows below that. Everything therefore goes through the model
 * layer so Tailor's afterRelation hook can attach the right fieldset before the
 * row is filled.
 */
class PageWriter
{
    /**
     * @var array pageFields writable attributes on the page record itself.
     */
    protected static $pageFields = [
        'title',
        'slug',
        'metaTitle',
        'metaDescription',
        'menuStyle',
        'menuHide',
        'parent_id',
    ];

    /**
     * create writes a new draft page.
     */
    public static function create(array $payload): EntryRecord
    {
        $pageData = (array) ($payload['page'] ?? []);
        $blocks = $payload['builder'] ?? null;

        SchemaGuard::assertReady();

        static::validatePage($pageData, true);
        static::validateSlugAvailable($pageData['slug'], $pageData['parent_id'] ?? null, null);

        if ($blocks !== null) {
            static::validateBlocks($blocks);
        }

        return DB::transaction(function () use ($pageData, $blocks) {
            $page = EntryRecord::inSection(BlockSchema::SECTION);

            static::fillPage($page, $pageData);

            // Drafts by default: a human assigns media and publishes.
            $page->is_enabled = false;
            $page->save();

            if ($blocks) {
                static::writeBlocks($page, 'builder', $blocks);
            }

            RecordIndexer::instance()->process($page);

            return $page;
        });
    }

    /**
     * update edits page meta and optionally rebuilds the whole builder array.
     */
    public static function update(EntryRecord $page, array $payload): EntryRecord
    {
        $pageData = (array) ($payload['page'] ?? []);
        $blocks = $payload['builder'] ?? null;

        SchemaGuard::assertReady();

        if ($pageData) {
            static::validatePage($pageData, false);

            if (array_key_exists('slug', $pageData)) {
                static::validateSlugAvailable(
                    $pageData['slug'],
                    $pageData['parent_id'] ?? $page->parent_id,
                    $page->id
                );
            }
        }

        if ($blocks !== null) {
            static::validateBlocks($blocks);
        }

        return DB::transaction(function () use ($page, $pageData, $blocks) {
            if ($pageData) {
                static::fillPage($page, $pageData);

                if (array_key_exists('is_enabled', $pageData)) {
                    $page->is_enabled = (bool) $pageData['is_enabled'];
                }

                $page->save();
            }

            if ($blocks !== null) {
                foreach ($page->builder as $existing) {
                    $existing->delete();
                }

                $page->reloadRelations('builder');

                static::writeBlocks($page, 'builder', $blocks);
            }

            RecordIndexer::instance()->process($page);

            return $page;
        });
    }

    /**
     * createTranslation spawns the sibling record for another site.
     *
     * findOrCreateForSite force-saves, which would leave a sibling with no
     * title or slug, so the unsaved model is fetched and filled first.
     */
    public static function createTranslation(EntryRecord $source, array $payload): EntryRecord
    {
        SchemaGuard::assertReady();

        $siteId = (int) $payload['site_id'];
        $pageData = (array) ($payload['page'] ?? []);
        $blocks = $payload['builder'] ?? null;

        if ($blocks !== null) {
            static::validateBlocks($blocks);
        }

        return DB::transaction(function () use ($source, $siteId, $pageData, $blocks) {
            $target = $source->findOtherSiteModel($siteId);

            if ($target->exists && $target->id === $source->id) {
                throw ApiException::invalid([
                    'site_id' => 'The source page already belongs to this site.',
                ]);
            }

            // Inherit from the source, then let the payload override.
            $target->title = $pageData['title'] ?? $source->title;
            $target->slug = $pageData['slug'] ?? $source->slug;
            $target->metaTitle = $pageData['metaTitle'] ?? $source->metaTitle;
            $target->metaDescription = $pageData['metaDescription'] ?? $source->metaDescription;
            $target->menuStyle = $pageData['menuStyle'] ?? $source->menuStyle;
            $target->menuHide = $pageData['menuHide'] ?? $source->menuHide;
            $target->is_enabled = false;
            $target->save();

            if ($blocks !== null) {
                foreach ($target->builder as $existing) {
                    $existing->delete();
                }

                $target->reloadRelations('builder');

                static::writeBlocks($target, 'builder', $blocks);
            }

            RecordIndexer::instance()->process($target);

            return $target;
        });
    }

    /**
     * fillPage assigns the writable page attributes.
     */
    protected static function fillPage(EntryRecord $page, array $data): void
    {
        foreach (static::$pageFields as $field) {
            if (array_key_exists($field, $data)) {
                $page->{$field} = $data[$field];
            }
        }
    }

    /**
     * writeBlocks creates every block under a host's repeater relation.
     */
    protected static function writeBlocks($host, string $relation, array $blocks): void
    {
        $sort = 1;

        foreach ($blocks as $block) {
            static::writeBlock($host, $relation, (array) $block, $sort++);
        }
    }

    /**
     * writeBlock creates one block row plus its nested content row.
     */
    protected static function writeBlock($host, string $relation, array $block, int $sort): void
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
    }

    /**
     * writeNested creates the repeater and nested-form rows below a content row.
     */
    protected static function writeNested($row, array $data, array $schema): void
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
     * prepare normalises a flat set of values for storage.
     *
     * Nested structures are handled separately by writeNested; only scalar and
     * list fields are returned here.
     */
    protected static function prepare(array $data, array $schema): array
    {
        $out = [];

        foreach ($schema as $name => $spec) {
            if (!array_key_exists($name, $data)) {
                continue;
            }

            if (!empty($spec['repeater']) || isset($spec['fields'])) {
                continue;
            }

            if (isset($spec['column'])) {
                // Assign by relation name; `<field>_id` is not fillable.
                $out[$name] = static::referenceId($data[$name]);
                continue;
            }

            $out[$name] = static::castIn($data[$name], $spec);
        }

        return $out;
    }

    /**
     * castIn converts a JSON value into what Tailor stores.
     */
    protected static function castIn($value, array $spec)
    {
        $type = $spec['type'] ?? 'text';

        if ($type === 'switch') {
            return $value ? '1' : '0';
        }

        if (in_array($type, ['taglist', 'checkboxlist'], true)) {
            return array_values((array) $value);
        }

        return $value;
    }

    /**
     * referenceId accepts either a bare id or a {id: n} object.
     */
    protected static function referenceId($value): ?int
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

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * validatePage checks the page attributes.
     */
    protected static function validatePage(array $data, bool $creating): void
    {
        $errors = [];

        if ($creating) {
            foreach (['title', 'slug'] as $required) {
                if (trim((string) ($data[$required] ?? '')) === '') {
                    $errors['page.' . $required] = 'This field is required.';
                }
            }
        }

        if (array_key_exists('fullslug', $data)) {
            $errors['page.fullslug'] = 'fullslug is derived from slug and the parent page; it cannot be set directly.';
        }

        if ($errors) {
            throw ApiException::invalid($errors);
        }
    }

    /**
     * validateSlugAvailable guards against duplicate URLs.
     *
     * The model's `unique_site` rule does not fire for a not-yet-saved record,
     * so two pages with the same slug would otherwise both be created and only
     * one of them would ever resolve on the frontend.
     */
    protected static function validateSlugAvailable($slug, $parentId, $ignoreId): void
    {
        $slug = trim((string) $slug);

        if ($slug === '') {
            return;
        }

        $query = EntryRecord::inSection(BlockSchema::SECTION)
            ->newQuery()
            ->where('slug', $slug);

        $parentId === null
            ? $query->whereNull('parent_id')
            : $query->where('parent_id', $parentId);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ApiException::invalid([
                'page.slug' => 'A page with the slug "' . $slug . '" already exists at this level on this site.',
            ]);
        }
    }

    /**
     * validateBlocks walks the payload against the live blueprint schema.
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
     * validateFields checks one level of a block payload.
     */
    protected static function validateFields(array $data, array $schema, string $path, array &$errors): void
    {
        foreach ($data as $name => $value) {
            if (!isset($schema[$name])) {
                $errors[$path . '.' . $name] = 'Unknown field. Allowed: ' . implode(', ', array_keys($schema)) . '.';
                continue;
            }

            $spec = $schema[$name];
            $at = $path . '.' . $name;

            if (!empty($spec['readonly']) && !static::isEmptyValue($value)) {
                $errors[$at] = 'Media fields cannot be set over the API. Leave it empty and assign the file in the backend.';
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
     * validateReference checks that a linked entry exists in the right section.
     */
    protected static function validateReference($value, array $spec, string $path, array &$errors): void
    {
        $id = static::referenceId($value);

        if ($id === null) {
            return;
        }

        $source = $spec['entries_source'] ?? null;

        if (!$source) {
            return;
        }

        $exists = EntryRecord::inSection($source)->where('id', $id)->exists();

        if (!$exists) {
            $errors[$path] = 'No ' . $source . ' entry with id ' . $id . ' on this site.';
        }
    }

    /**
     * isEmptyValue treats null, "" and [] alike.
     */
    protected static function isEmptyValue($value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
