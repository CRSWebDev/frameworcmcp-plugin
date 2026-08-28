<?php namespace CRSCompany\FrameworCMcp\Classes;

use DB;
use Str;
use Tailor\Models\EntryRecord;

/**
 * MenuWriter creates and edits Menu entries with their navigation tree.
 *
 * The tree is stored as flat rows linked by parent_id; a write replaces the
 * whole tree, which is safe because menu items carry no media fields at all.
 */
class MenuWriter extends ContentWriter
{
    /**
     * schema returns the walked Menu blueprint.
     */
    public static function schema(): array
    {
        return BlockSchema::forBlueprint('Menu');
    }

    /**
     * treeSpec returns the navigation nested-items spec.
     */
    public static function treeSpec(): array
    {
        $spec = static::schema()['navigation'] ?? null;

        if (!$spec || empty($spec['tree'])) {
            throw new ApiException('The Menu blueprint has no navigation nested-items field.', 500);
        }

        return $spec;
    }

    /**
     * create writes a new menu.
     */
    public static function create(array $payload): EntryRecord
    {
        $menuData = (array) ($payload['menu'] ?? []);
        $tree = $payload['navigation'] ?? null;

        SchemaGuard::assertReady('Menu');

        if (trim((string) ($menuData['title'] ?? '')) === '') {
            throw ApiException::invalid(['menu.title' => 'This field is required.']);
        }

        if ($tree !== null) {
            static::validateTree((array) $tree);
        }

        return DB::transaction(function () use ($menuData, $tree) {
            $menu = EntryRecord::inSection('Menu');
            $menu->title = $menuData['title'];
            $menu->slug = trim((string) ($menuData['slug'] ?? '')) !== ''
                ? $menuData['slug']
                : Str::slug($menuData['title']);
            $menu->is_enabled = (bool) ($menuData['is_enabled'] ?? true);
            $menu->save();

            if ($tree) {
                static::writeTreeRows($menu, 'navigation', (array) $tree, static::treeSpec(), null);
            }

            return $menu;
        });
    }

    /**
     * update edits menu attributes and optionally replaces the tree.
     */
    public static function update(EntryRecord $menu, array $payload): EntryRecord
    {
        $menuData = (array) ($payload['menu'] ?? []);
        $tree = $payload['navigation'] ?? null;

        SchemaGuard::assertReady('Menu');

        if ($tree !== null) {
            static::validateTree((array) $tree);
        }

        return DB::transaction(function () use ($menu, $menuData, $tree) {
            if ($menuData) {
                foreach (['title', 'slug'] as $field) {
                    if (array_key_exists($field, $menuData)) {
                        $menu->{$field} = $menuData[$field];
                    }
                }

                if (array_key_exists('is_enabled', $menuData)) {
                    $menu->is_enabled = (bool) $menuData['is_enabled'];
                }

                $menu->save();
            }

            if ($tree !== null) {
                foreach ($menu->navigation as $existing) {
                    $existing->delete();
                }

                $menu->reloadRelations('navigation');

                static::writeTreeRows($menu, 'navigation', (array) $tree, static::treeSpec(), null);
            }

            return $menu;
        });
    }

    /**
     * validateTree runs the generic tree validation plus per-item title checks.
     */
    protected static function validateTree(array $tree): void
    {
        $errors = [];

        static::validateTreeRows($tree, static::treeSpec(), 'navigation', $errors, 1);
        static::validateTitles($tree, 'navigation', $errors);

        if ($errors) {
            throw ApiException::invalid($errors);
        }
    }

    /**
     * validateTitles requires a label on every item, at every level.
     */
    protected static function validateTitles(array $rows, string $path, array &$errors): void
    {
        foreach (array_values($rows) as $i => $row) {
            $row = (array) $row;
            $at = $path . '.' . $i;

            if (trim((string) ($row['title'] ?? '')) === '') {
                $errors[$at . '.title'] = 'This field is required.';
            }

            if (!empty($row['children']) && is_array($row['children'])) {
                static::validateTitles($row['children'], $at . '.children', $errors);
            }
        }
    }
}
