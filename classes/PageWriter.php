<?php namespace CRSCompany\FrameworCMcp\Classes;

use DB;
use Str;
use Tailor\Classes\RecordIndexer;
use Tailor\Models\EntryRecord;

/**
 * PageWriter turns MCP JSON into Tailor records for the builder sections.
 *
 * Both Builder pages and Prefill entries carry the same `builder` repeater, so
 * the same writer serves them; only the writable record fields and the slug
 * rules differ per section.
 */
class PageWriter extends ContentWriter
{
    /**
     * @var array sectionFields writable record attributes per section.
     */
    protected static $sectionFields = [
        'Builder' => [
            'title',
            'slug',
            'metaTitle',
            'metaDescription',
            'ogImage',
            'menuStyle',
            'menuHide',
            'jsonLdPageType',
            'jsonLdDisable',
            'jsonLdCustom',
            'parent_id',
        ],
        'Prefill' => [
            'title',
            'slug',
        ],
    ];

    /**
     * @var array jsonLdPageTypes options of the Builder `jsonLdPageType` dropdown.
     */
    protected static $jsonLdPageTypes = ['WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'FAQPage'];

    /**
     * @var array recordMediaFields media attributes on the record itself, per
     * section. Blocks carry their media inside blueprint fieldsets and so have
     * a spec to read; these few hang off the record and need one declared.
     */
    protected static $recordMediaFields = [
        'Builder' => [
            'ogImage' => ['media' => true, 'max_items' => 1, 'mode' => 'image'],
        ],
        'Prefill' => [],
    ];

    /**
     * create writes a new record with its builder blocks.
     */
    public static function create(array $payload, string $section = BlockSchema::SECTION): EntryRecord
    {
        $pageData = static::recordData($payload);
        $blocks = $payload['builder'] ?? null;

        SchemaGuard::assertReady($section);

        // Prefills are referenced by id, not routed by slug, so a missing slug
        // is derived rather than rejected.
        if ($section !== BlockSchema::SECTION && trim((string) ($pageData['slug'] ?? '')) === '') {
            $pageData['slug'] = Str::slug((string) ($pageData['title'] ?? ''));
        }

        static::validatePage($pageData, true, $section);
        static::validateSlugAvailable($pageData['slug'], $pageData['parent_id'] ?? null, null, $section);

        if ($blocks !== null) {
            static::validateBlocks($blocks);
        }

        return DB::transaction(function () use ($pageData, $blocks, $section) {
            $page = EntryRecord::inSection($section);

            static::fillPage($page, $pageData, $section);

            // Pages start as drafts: a human reviews and publishes. A draft
            // prefill would silently break every block referencing it, so
            // prefills go live immediately.
            $page->is_enabled = $section === BlockSchema::SECTION
                ? false
                : (bool) ($pageData['is_enabled'] ?? true);
            $page->save();

            if ($blocks) {
                static::writeBlocks($page, 'builder', $blocks);
            }

            static::reindex($page, $section);

            return $page;
        });
    }

    /**
     * update edits record fields and optionally rebuilds the whole builder array.
     */
    public static function update(EntryRecord $page, array $payload, string $section = BlockSchema::SECTION): EntryRecord
    {
        $pageData = static::recordData($payload);
        $blocks = $payload['builder'] ?? null;

        SchemaGuard::assertReady($section);

        if ($pageData) {
            static::validatePage($pageData, false, $section);

            if (array_key_exists('slug', $pageData)) {
                static::validateSlugAvailable(
                    $pageData['slug'],
                    $pageData['parent_id'] ?? $page->parent_id,
                    $page->id,
                    $section
                );
            }
        }

        if ($blocks !== null) {
            static::validateBlocks($blocks);
        }

        return DB::transaction(function () use ($page, $pageData, $blocks, $section) {
            if ($pageData) {
                static::fillPage($page, $pageData, $section);

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

            static::reindex($page, $section);

            return $page;
        });
    }

    /**
     * createTranslation spawns the sibling record for another site.
     *
     * findOrCreateForSite force-saves, which would leave a sibling with no
     * title or slug, so the unsaved model is fetched and filled first.
     */
    public static function createTranslation(EntryRecord $source, array $payload, string $section = BlockSchema::SECTION): EntryRecord
    {
        SchemaGuard::assertReady($section);

        $siteId = (int) $payload['site_id'];
        $pageData = static::recordData($payload);
        $blocks = $payload['builder'] ?? null;

        if ($blocks !== null) {
            static::validateBlocks($blocks);
        }

        return DB::transaction(function () use ($source, $siteId, $pageData, $blocks, $section) {
            $target = $source->findOtherSiteModel($siteId);

            if ($target->exists && $target->id === $source->id) {
                throw ApiException::invalid([
                    'site_id' => 'The source record already belongs to this site.',
                ]);
            }

            // Inherit from the source, then let the payload override.
            foreach (static::$sectionFields[$section] ?? static::$sectionFields['Builder'] as $field) {
                if ($field === 'parent_id') {
                    continue;
                }

                $target->{$field} = $pageData[$field] ?? $source->{$field};
            }

            $target->is_enabled = $section === BlockSchema::SECTION
                ? false
                : (bool) ($pageData['is_enabled'] ?? $source->is_enabled);
            $target->save();

            if ($blocks !== null) {
                foreach ($target->builder as $existing) {
                    $existing->delete();
                }

                $target->reloadRelations('builder');

                static::writeBlocks($target, 'builder', $blocks);
            }

            static::reindex($target, $section);

            return $target;
        });
    }

    /**
     * recordData reads the record payload from its `page` or `prefill` key.
     */
    protected static function recordData(array $payload): array
    {
        return (array) ($payload['page'] ?? $payload['prefill'] ?? []);
    }

    /**
     * fillPage assigns the writable record attributes for the section.
     */
    protected static function fillPage(EntryRecord $page, array $data, string $section): void
    {
        $fields = static::$sectionFields[$section] ?? static::$sectionFields['Builder'];
        $media = static::$recordMediaFields[$section] ?? [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $page->{$field} = isset($media[$field])
                ? static::castMediaIn($data[$field], $media[$field])
                : $data[$field];
        }
    }

    /**
     * reindex recomputes fullslug, which only structure sections carry.
     */
    protected static function reindex(EntryRecord $page, string $section): void
    {
        if ($section === BlockSchema::SECTION) {
            RecordIndexer::instance()->process($page);
        }
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * validatePage checks the record attributes.
     */
    protected static function validatePage(array $data, bool $creating, string $section = BlockSchema::SECTION): void
    {
        $errors = [];

        foreach (static::$recordMediaFields[$section] ?? [] as $field => $spec) {
            if (array_key_exists($field, $data)) {
                static::validateMedia($data[$field], $spec, 'page.' . $field, $errors);
            }
        }

        if ($creating) {
            foreach (['title', 'slug'] as $required) {
                if (trim((string) ($data[$required] ?? '')) === '') {
                    $errors['page.' . $required] = 'This field is required.';
                }
            }
        }

        if (array_key_exists('jsonLdPageType', $data)
            && !in_array($data['jsonLdPageType'], static::$jsonLdPageTypes, true)
        ) {
            $errors['page.jsonLdPageType'] = 'Must be one of: ' . implode(', ', static::$jsonLdPageTypes) . '.';
        }

        // The frontend silently drops invalid custom JSON-LD, so reject it here
        // where the caller can still see why.
        if (array_key_exists('jsonLdCustom', $data) && trim((string) $data['jsonLdCustom']) !== '') {
            if (!is_string($data['jsonLdCustom']) || !is_array(json_decode($data['jsonLdCustom'], true))) {
                $errors['page.jsonLdCustom'] = 'Must be a JSON object or array encoded as a string, without a script tag.';
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
    protected static function validateSlugAvailable($slug, $parentId, $ignoreId, string $section = BlockSchema::SECTION): void
    {
        $slug = trim((string) $slug);

        if ($slug === '') {
            return;
        }

        $query = EntryRecord::inSection($section)
            ->newQuery()
            ->where('slug', $slug);

        // Only structure sections nest; entries have no parent scope.
        if ($section === BlockSchema::SECTION) {
            $parentId === null
                ? $query->whereNull('parent_id')
                : $query->where('parent_id', $parentId);
        }

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ApiException::invalid([
                'page.slug' => 'A record with the slug "' . $slug . '" already exists at this level on this site.',
            ]);
        }
    }
}
