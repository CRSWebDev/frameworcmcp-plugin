<?php namespace CRSCompany\FrameworCMcp\Classes;

use Schema;
use Tailor\Classes\BlueprintIndexer;

/**
 * SchemaGuard checks the database is migrated far enough to accept writes.
 *
 * Tailor adds columns to its generated tables over time, and an install only
 * picks them up when the blueprints are re-migrated. `october:migrate` does not
 * do this — it runs module, plugin and app migrations, while the Tailor schema
 * builder is only invoked by `tailor:migrate`. So a site can be fully up to
 * date on plugin versions and still be missing these columns.
 *
 * Without this check the first write fails deep inside the model layer with a
 * raw SQL error, which tells whoever is driving the API nothing useful.
 */
class SchemaGuard
{
    /**
     * @var array requiredRepeaterColumns added by Tailor's repeater table patch.
     *
     * @see \Tailor\Classes\SchemaBuilder\HasRepeaterTable::patchRepeaterTableColumns
     */
    protected static $requiredRepeaterColumns = ['site_root_id', 'parent_id'];

    /**
     * @var array sections whose repeater tables the API writes into.
     */
    protected static $sections = ['Builder', 'Prefill', 'Form', 'Menu'];

    /**
     * @var array checked memoises the per-section result for the request.
     */
    protected static $checked = [];

    /**
     * assertReady throws when a section's tables are behind the blueprints.
     */
    public static function assertReady(string $section = BlockSchema::SECTION): void
    {
        if (!empty(static::$checked[$section])) {
            return;
        }

        $missing = static::missingColumns($section);

        if ($missing) {
            throw new ApiException(
                'This site\'s database is behind its Tailor blueprints and cannot accept writes yet. '
                . 'Missing on ' . $missing['table'] . ': ' . implode(', ', $missing['columns']) . '. '
                . 'Run "php artisan tailor:migrate" on the server, then retry. '
                . 'Note that "october:migrate" does not fix this — it does not run the Tailor schema builder.',
                503
            );
        }

        static::$checked[$section] = true;
    }

    /**
     * missingColumns returns the shortfall, or null when the schema is fine.
     */
    public static function missingColumns(string $section = BlockSchema::SECTION): ?array
    {
        $blueprint = BlueprintIndexer::instance()->findByHandle($section);

        if (!$blueprint) {
            return null;
        }

        $table = $blueprint->getRepeaterTableName();

        if (!Schema::hasTable($table)) {
            return ['table' => $table, 'columns' => ['(table does not exist)']];
        }

        $existing = Schema::getColumnListing($table);
        $missing = array_values(array_diff(static::$requiredRepeaterColumns, $existing));

        return $missing ? ['table' => $table, 'columns' => $missing] : null;
    }

    /**
     * report describes the schema state for the diagnostics endpoint.
     */
    public static function report(): array
    {
        $missing = [];

        foreach (static::$sections as $section) {
            $shortfall = static::missingColumns($section);

            if ($shortfall) {
                $missing[] = $shortfall;
            }
        }

        return [
            'writable' => $missing === [],
            'missing' => $missing ?: null,
            'remedy' => $missing === []
                ? null
                : 'Run "php artisan tailor:migrate" on the server. "october:migrate" will not fix this.',
        ];
    }
}
