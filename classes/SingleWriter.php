<?php namespace CRSCompany\FrameworCMcp\Classes;

use DB;
use Site;
use Tailor\Classes\BlueprintIndexer;
use Tailor\Models\EntryRecord;

/**
 * SingleWriter reads and writes the per-site Tailor singles.
 *
 * These hold site-wide settings such as SEO defaults, the navbar and the
 * footer, so an assistant scaffolding a site can set them alongside its pages.
 * Fields go through the same schema walker, writer and serializer as every
 * other feature: repeaters (e.g. the footer's socials) and entries references
 * (e.g. the linked Menu) are fully supported; media stays read-only.
 */
class SingleWriter extends ContentWriter
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

        return BlockSchema::forBlueprint($handle);
    }

    /**
     * read returns the current values for the active site.
     */
    public static function read(string $handle): array
    {
        $record = static::record($handle);
        $schema = static::schema($handle);

        return [
            'handle' => $handle,
            'id' => (int) $record->id,
            'site_id' => $record->site_id !== null ? (int) $record->site_id : null,
            'fields' => ContentSerializer::contentFields($record, $schema),
            'schema' => $schema,
        ];
    }

    /**
     * write updates the single for the active site.
     *
     * Scalars are assigned; a repeater or nested form key present in the
     * payload replaces its stored rows wholesale (they carry no media the API
     * could lose — media fields themselves are rejected by validation).
     */
    public static function write(string $handle, array $data): array
    {
        SchemaGuard::assertReady($handle);

        $record = static::record($handle);
        $schema = static::schema($handle);

        unset($data['site_id'], $data['handle']);

        $errors = [];
        static::validateFields($data, $schema, 'fields', $errors);

        if ($errors) {
            throw ApiException::invalid($errors);
        }

        DB::transaction(function () use ($record, $data, $schema) {
            // A join-table entries field with an `array` rule fails validation
            // whenever the relation is empty: October hands the validator an
            // explicit null, which the backend never hits because its forms
            // always post the key. Relax the rule for this save.
            foreach ($schema as $name => $spec) {
                if (isset($spec['column']) && !empty($spec['multiple']) && isset($record->rules[$name])) {
                    $record->addValidationRule($name, 'nullable');
                }
            }

            $scalars = static::prepare($data, $schema);

            foreach ($scalars as $name => $value) {
                $record->{$name} = $value;
            }

            $record->save();

            // Replace nested rows for every structured key in the payload.
            foreach ($data as $name => $value) {
                $spec = $schema[$name] ?? null;

                if (!$spec || (empty($spec['repeater']) && !isset($spec['fields']))) {
                    continue;
                }

                foreach ($record->{$name} as $existing) {
                    $existing->delete();
                }

                $record->reloadRelations($name);

                if ($value !== null) {
                    static::writeNested($record, [$name => $value], [$name => $spec]);
                }
            }
        });

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
