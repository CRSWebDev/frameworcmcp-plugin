<?php namespace CRSCompany\FrameworCMcp\Classes;

use Site;
use Tailor\Classes\BlueprintIndexer;
use Tailor\Classes\FieldManager;
use Tailor\Models\EntryRecord;

/**
 * SingleWriter reads and writes the per-site Tailor singles.
 *
 * These hold site-wide settings such as SEO defaults and the blog base path,
 * so an assistant scaffolding a site can set them alongside its pages.
 */
class SingleWriter
{
    /**
     * @var array allowed handles. Deliberately a fixed list: these are the
     * singles that make sense to drive from a chat, and nothing else should be
     * writable through this API.
     */
    protected static $allowed = ['Meta', 'Navigation', 'Footer'];

    /**
     * handles lists the writable singles.
     */
    public static function handles(): array
    {
        return static::$allowed;
    }

    /**
     * schema derives the field list for a single from its blueprint.
     */
    public static function schema(string $handle): array
    {
        static::assertAllowed($handle);

        $blueprint = BlueprintIndexer::instance()->findByHandle($handle);

        if (!$blueprint) {
            throw ApiException::notFound('No blueprint with handle "' . $handle . '".');
        }

        $fields = FieldManager::instance()
            ->makeFieldset(['fields' => (array) $blueprint->fields])
            ->getAllFields();

        $out = [];

        foreach ($fields as $name => $field) {
            if (str_starts_with($name, '_')) {
                continue;
            }

            $config = (array) $field->config;
            $type = $config['type'] ?? 'text';

            if (in_array($type, ['section', 'hint', 'partial', 'ruler'], true)) {
                continue;
            }

            $spec = ['type' => $type];

            if (!empty($config['label'])) {
                $spec['label'] = $config['label'];
            }
            if (!empty($config['options'])) {
                $spec['options'] = (array) $config['options'];
            }
            if ($field instanceof \Tailor\ContentFields\MediaFinderField || $type === 'fileupload') {
                $spec['readonly'] = true;
            }
            if ($field instanceof \Tailor\ContentFields\RepeaterField) {
                // Navigation and Footer hold repeaters; expose them read-only
                // rather than half-supporting a write path nobody asked for.
                $spec['repeater'] = true;
                $spec['readonly'] = true;
            }

            $out[$name] = $spec;
        }

        return $out;
    }

    /**
     * read returns the current values for the active site.
     */
    public static function read(string $handle): array
    {
        $record = static::record($handle);
        $schema = static::schema($handle);

        $values = [];

        foreach ($schema as $name => $spec) {
            if (!empty($spec['repeater'])) {
                continue;
            }

            $values[$name] = $record->{$name};
        }

        return [
            'handle' => $handle,
            'id' => (int) $record->id,
            'site_id' => $record->site_id !== null ? (int) $record->site_id : null,
            'fields' => $values,
            'schema' => $schema,
        ];
    }

    /**
     * write updates the single for the active site.
     */
    public static function write(string $handle, array $data): array
    {
        $record = static::record($handle);
        $schema = static::schema($handle);

        $errors = [];

        foreach ($data as $name => $value) {
            if (in_array($name, ['site_id', 'handle'], true)) {
                continue;
            }

            if (!isset($schema[$name])) {
                $errors[$name] = 'Unknown field. Allowed: ' . implode(', ', array_keys($schema)) . '.';
                continue;
            }

            if (!empty($schema[$name]['readonly']) && !($value === null || $value === '' || $value === [])) {
                $errors[$name] = 'This field cannot be set over the API.';
            }
        }

        if ($errors) {
            throw ApiException::invalid($errors);
        }

        foreach ($data as $name => $value) {
            if (!isset($schema[$name]) || !empty($schema[$name]['readonly'])) {
                continue;
            }

            $record->{$name} = $value;
        }

        $record->save();

        return static::read($handle);
    }

    /**
     * record loads the single for the active site, creating it when missing.
     *
     * Tailor only materialises a single's record the first time it is opened in
     * the backend, so a freshly added language site has none. This mirrors
     * Entries::findSingularModelObjectWithFallback: prefer this site's record,
     * otherwise spawn a linked sibling from another site, otherwise create the
     * very first one.
     */
    protected static function record(string $handle): EntryRecord
    {
        static::assertAllowed($handle);

        $record = EntryRecord::inSection($handle)->first();

        if ($record) {
            return $record;
        }

        $blueprint = BlueprintIndexer::instance()->findByHandle($handle);

        if (!$blueprint) {
            throw ApiException::notFound('No blueprint with handle "' . $handle . '".');
        }

        $sibling = EntryRecord::inSectionUuid($blueprint->uuid)->withSites()->first();

        if ($sibling) {
            $record = $sibling->findOtherSiteModel(Site::getSiteIdFromContext());

            if (!$record->exists) {
                $record->save(['force' => true]);
            }

            return $record;
        }

        return EntryRecord::findSingleForSectionUuid($blueprint->uuid);
    }

    /**
     * assertAllowed rejects handles outside the writable list.
     */
    protected static function assertAllowed(string $handle): void
    {
        if (!in_array($handle, static::$allowed, true)) {
            throw ApiException::notFound(
                'Unknown single "' . $handle . '". Available: ' . implode(', ', static::$allowed) . '.'
            );
        }
    }
}
