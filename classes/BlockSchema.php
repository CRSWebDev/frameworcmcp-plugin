<?php namespace CRSCompany\FrameworCMcp\Classes;

use Tailor\Classes\BlueprintIndexer;
use Tailor\Classes\FieldManager;
use Tailor\ContentFields\EntriesField;
use Tailor\ContentFields\MediaFinderField;
use Tailor\ContentFields\NestedFormField;
use Tailor\ContentFields\NestedItemsField;
use Tailor\ContentFields\RepeaterField;

/**
 * BlockSchema derives the builder block catalogue from the live Tailor blueprints.
 *
 * This is deliberately the only description of what a block looks like: the
 * schema endpoints and the payload validator both read it, so a blueprint edit
 * can never leave the API accepting a shape the CMS cannot store.
 */
class BlockSchema
{
    /**
     * @var string SECTION the Tailor section holding FrameworC pages.
     */
    const SECTION = 'Builder';

    /**
     * @var array|null groups memoised per request.
     */
    protected static $groups = null;

    /**
     * @var array|null schema memoised per request.
     */
    protected static $schema = null;

    /**
     * @var array blueprints memoises forBlueprint per handle.
     */
    protected static $blueprints = [];

    /**
     * @var array presentational field types that never carry a value.
     */
    protected static $ignoredTypes = ['section', 'hint', 'partial', 'ruler'];

    /**
     * @var array whenToUse guidance, keyed by block name.
     *
     * Ported from the MCP's hand-authored catalogue so the authoring hints
     * survive once that file is retired in favour of these endpoints.
     */
    protected static $guidance = [
        'Header' => 'Always the first block on a page. Use H1/H2 in the headline here; every other block uses H2/H3. One per page.',
        'Section' => 'The default choice for any paragraph plus image content. Pick a variant matching the ratio of text to visual. Use "textAndText" for two text columns, and an "embed*" variant for a YouTube video, map iframe or similar.',
        'Tiles' => 'Feature lists, service grids, value-proposition card rows, or any list of related items.',
        'Slider' => 'Image-heavy content that benefits from sequential browsing: testimonials, step-by-step walkthroughs, gallery-like rows.',
        'Tabs' => 'Long content split into topic tabs. Do not use for only one or two sections; a Section block is better.',
        'Accordion' => 'FAQs and any content that should be collapsed by default. Each item is a headline plus rich text.',
        'Form' => 'Only when a contact form is needed and the referenced Form entry already exists. Get its id from GET /forms. The form fields live on the linked Form entry, not on this block.',
        'Gallery' => 'Several images shown together in a masonry grid with a lightbox.',
        'Downloads' => 'Document, spec or PDF lists. Each row has a name, an icon, an optional description and a file.',
        'Columns' => 'Two to four distinct blocks side by side on desktop. Each column holds its own full builder array, so any block type can be nested. Avoid deep nesting.',
        'Prefill' => 'Shared blocks reused across pages, such as a newsletter call to action. The content lives on the Prefill entry; get its id from GET /prefills. Whenever the same section would appear on more than one page, do NOT copy it: keep it in one Prefill entry and insert this block on every page that needs it. An existing page block can be moved into a new Prefill with POST /pages/{id}/blocks/{blockId}/extract-to-prefill. Not for one-off content.',
        'BlogList' => 'A blog index or category listing. Pulls BlogPost entries automatically.',
        'MenuBlock' => 'Embeds an existing Menu entry as page content. Get its id from GET /menus.',
        'ImageStrip' => 'A horizontally scrolling strip of logos or photos, optionally auto-scrolling.',
        'InstaFeed' => 'A live Instagram feed grid. Requires an Instagram token.',
    ];

    /**
     * groups returns the raw repeater group config, keyed by block name.
     */
    public static function groups(): array
    {
        if (static::$groups !== null) {
            return static::$groups;
        }

        $blueprint = BlueprintIndexer::instance()->findByHandle(static::SECTION);

        if (!$blueprint) {
            throw new ApiException('The Builder blueprint is missing. Is the FrameworC plugin installed?', 500);
        }

        $fields = FieldManager::instance()
            ->makeFieldset(['fields' => (array) $blueprint->fields])
            ->getAllFields();

        $repeater = $fields['builder'] ?? null;

        if (!$repeater instanceof RepeaterField) {
            throw new ApiException('The Builder blueprint has no "builder" repeater field.', 500);
        }

        return static::$groups = (array) $repeater->fieldsetConfig;
    }

    /**
     * all returns the full catalogue.
     */
    public static function all(): array
    {
        if (static::$schema !== null) {
            return static::$schema;
        }

        $out = [];
        foreach (static::groups() as $name => $config) {
            $out[$name] = static::describeGroup($name, $config);
        }

        return static::$schema = $out;
    }

    /**
     * get returns one block's schema.
     */
    public static function get(string $name): array
    {
        $all = static::all();

        if (!isset($all[$name])) {
            throw ApiException::invalid([
                'content_group' => 'Unknown block "' . $name . '". Available: ' . implode(', ', array_keys($all)) . '.',
            ]);
        }

        return $all[$name];
    }

    /**
     * names lists the valid content_group values.
     */
    public static function names(): array
    {
        return array_keys(static::all());
    }

    /**
     * forBlueprint derives the field schema of any blueprint by handle.
     *
     * This is the walker the singles, forms and settings endpoints share, so
     * every feature describes and validates fields identically.
     */
    public static function forBlueprint(string $handle): array
    {
        if (isset(static::$blueprints[$handle])) {
            return static::$blueprints[$handle];
        }

        $blueprint = BlueprintIndexer::instance()->findByHandle($handle);

        if (!$blueprint) {
            throw ApiException::notFound('No blueprint with handle "' . $handle . '".');
        }

        return static::$blueprints[$handle] = static::walk(['fields' => (array) $blueprint->fields]);
    }

    /**
     * describeGroup turns one repeater group into a block spec.
     *
     * A group is `base` (the BaseBlock mixin, flattened onto the row) plus
     * `content` (the Blocks/<Name> mixin, which itself contributes a nested
     * form and sometimes a block-level entries reference).
     */
    protected static function describeGroup(string $name, array $config): array
    {
        $fields = static::walk(['fields' => $config['fields'] ?? []]);

        // The nested form is stored as its own row; everything else is flattened
        // onto the block row alongside the BaseBlock fields.
        $content = $fields['content'] ?? null;
        unset($fields['content']);

        return [
            'name' => $name,
            'label' => $config['name'] ?? $name,
            'description' => $config['description'] ?? null,
            'whenToUse' => static::$guidance[$name] ?? null,
            'base' => $fields,
            'content' => $content['fields'] ?? [],
        ];
    }

    /**
     * walk resolves a form config into a field => spec map, recursing into
     * nested forms and repeaters.
     */
    protected static function walk(array $formConfig, int $depth = 0): array
    {
        // Guard against a blueprint that nests itself without bound (Columns
        // legitimately recurses, so stop at a depth no real page exceeds).
        if ($depth > 6) {
            return [];
        }

        $fields = FieldManager::instance()
            ->makeFieldset(['fields' => static::collectFields($formConfig)])
            ->getAllFields();

        $out = [];
        foreach ($fields as $fieldName => $field) {
            if (str_starts_with($fieldName, '_')) {
                continue;
            }

            $config = (array) $field->config;
            $type = $config['type'] ?? 'text';

            if (in_array($type, static::$ignoredTypes, true)) {
                continue;
            }

            $spec = ['type' => $type];

            if (!empty($config['label'])) {
                $spec['label'] = $config['label'];
            }
            if (array_key_exists('default', $config)) {
                $spec['default'] = $config['default'];
            }
            if (!empty($config['options'])) {
                $spec['options'] = static::normaliseOptions($config['options']);
            }
            if (!empty($config['trigger'])) {
                $spec['trigger'] = $config['trigger'];
            }
            if (!empty($config['comment'])) {
                $spec['comment'] = $config['comment'];
            }

            if ($field instanceof MediaFinderField || $type === 'fileupload') {
                // Media cannot be assigned over the API; a human picks files in
                // the backend media library.
                $spec['readonly'] = true;
            }

            if ($field instanceof EntriesField) {
                $spec['entries_source'] = $config['source'] ?? null;
                $spec['max_items'] = $config['maxItems'] ?? null;
                $spec['column'] = $fieldName . '_id';

                // Without maxItems: 1 the relation is join-table backed and is
                // written as an array of ids, even when validation caps it.
                if (($config['maxItems'] ?? null) !== 1) {
                    $spec['multiple'] = true;
                }
            }

            if ($field instanceof NestedFormField) {
                $spec['fields'] = static::walk((array) $field->fieldsetConfig, $depth + 1);
            }

            if ($field instanceof NestedItemsField) {
                // A sortable tree of uniform rows, e.g. a menu's navigation.
                $spec['repeater'] = true;
                $spec['tree'] = true;
                $spec['max_depth'] = (int) ($config['maxDepth'] ?? 1);
                $spec['fields'] = static::walk((array) $field->fieldsetConfig, $depth + 1);
            }

            if ($field instanceof RepeaterField) {
                $spec['repeater'] = true;
                $fieldsetConfig = (array) $field->fieldsetConfig;

                if (static::isGroupedRepeater($fieldsetConfig)) {
                    if (static::isBuilderRepeater($fieldsetConfig)) {
                        // A nested builder: the same block catalogue, recursively.
                        $spec['groups'] = array_keys($fieldsetConfig);
                        $spec['recursive'] = true;
                    }
                    else {
                        // Any other grouped repeater, e.g. a form's fields:
                        // every group gets its own walked field map.
                        $spec['grouped'] = true;
                        $groups = [];

                        foreach ($fieldsetConfig as $groupName => $groupConfig) {
                            $groupConfig = (array) $groupConfig;
                            $groups[$groupName] = [
                                'label' => $groupConfig['name'] ?? $groupName,
                                'fields' => static::walk($groupConfig, $depth + 1),
                            ];
                        }

                        $spec['groups'] = $groups;
                    }
                }
                else {
                    $spec['fields'] = static::walk($fieldsetConfig, $depth + 1);
                }
            }

            $out[$fieldName] = $spec;
        }

        return $out;
    }

    /**
     * collectFields gathers fields from every place a form config may hold them.
     *
     * @see \Tailor\ContentFields\NestedFormField::getCleanFormConfig
     */
    protected static function collectFields(array $form): array
    {
        return array_merge(
            $form['fields'] ?? [],
            $form['tabs']['fields'] ?? [],
            $form['secondaryTabs']['fields'] ?? []
        );
    }

    /**
     * isGroupedRepeater distinguishes `groups:` from `form:` repeaters.
     */
    protected static function isGroupedRepeater(array $config): bool
    {
        return !isset($config['fields'])
            && !isset($config['tabs'])
            && !isset($config['secondaryTabs']);
    }

    /**
     * isBuilderRepeater recognises the page-builder group set.
     *
     * Compared against the raw repeater config, never names(), because names()
     * runs walk() and this is called from inside walk().
     */
    protected static function isBuilderRepeater(array $config): bool
    {
        $builderKeys = array_keys(static::groups());
        $groupKeys = array_keys($config);

        return !array_diff($groupKeys, $builderKeys) && !array_diff($builderKeys, $groupKeys);
    }

    /**
     * normaliseOptions renders both list and map option styles as a map.
     */
    protected static function normaliseOptions($options): array
    {
        $options = (array) $options;

        if (array_is_list($options)) {
            return array_combine($options, $options);
        }

        return $options;
    }
}
