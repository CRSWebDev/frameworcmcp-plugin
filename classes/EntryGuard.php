<?php namespace CRSCompany\FrameworCMcp\Classes;

use Tailor\Models\EntryRecord;

/**
 * EntryGuard blocks deletion of entries other content still points at.
 *
 * Deleting a referenced Form, Menu or Prefill would leave broken blocks on
 * live pages, so the delete endpoints refuse unless the caller forces it.
 */
class EntryGuard
{
    /**
     * assertDeletable throws 422 when the entry is referenced, unless forced.
     */
    public static function assertDeletable(string $section, EntryRecord $entry, bool $force): void
    {
        if ($force) {
            return;
        }

        $hosts = static::references($section, (int) $entry->id);

        if ($hosts) {
            throw ApiException::invalid([
                'id' => 'This ' . $section . ' entry is still referenced by: ' . implode('; ', $hosts)
                    . '. Remove those references first, or pass ?force=1 to delete anyway.',
            ]);
        }
    }

    /**
     * references lists human-readable hosts still pointing at the entry.
     */
    public static function references(string $section, int $id): array
    {
        $hosts = [];

        foreach (['Builder' => 'Page', 'Prefill' => 'Prefill'] as $hostSection => $label) {
            if ($hostSection === 'Prefill' && $section === 'Prefill') {
                // A prefill cannot reference another prefill's builder today,
                // but scanning would still be harmless; skip self-section noise.
                continue;
            }

            foreach (EntryRecord::inSection($hostSection)->get() as $record) {
                $blocks = PageSerializer::blocks($record->builder);

                if (static::blocksReference($blocks, $section, $id)) {
                    $hosts[] = $label . ' "' . $record->title . '" (id ' . $record->id . ')';
                }
            }
        }

        if ($section === 'Menu') {
            foreach (['Navigation', 'Footer'] as $single) {
                $record = EntryRecord::inSection($single)->first();
                $nav = $record ? $record->nav : null;
                $linked = $nav ? $nav->first() : null;

                if ($linked && (int) $linked->id === $id) {
                    $hosts[] = $single . ' single';
                }
            }
        }

        return $hosts;
    }

    /**
     * blocksReference deep-scans serialized blocks for a reference.
     */
    protected static function blocksReference(array $blocks, string $section, int $id): bool
    {
        foreach ($blocks as $block) {
            $block = (array) $block;

            $ref = null;
            if ($section === 'Form') {
                $ref = $block['form'] ?? null;
            }
            elseif ($section === 'Menu') {
                $ref = ((array) ($block['content'] ?? []))['menu'] ?? null;
            }
            elseif ($section === 'Prefill') {
                $ref = ((array) ($block['content'] ?? []))['block'] ?? null;
            }

            if (is_array($ref) && (int) ($ref['id'] ?? 0) === $id) {
                return true;
            }

            // Recurse into nested builders, e.g. blocks inside Columns.
            foreach ((array) ($block['content'] ?? []) as $value) {
                if (!is_array($value)) {
                    continue;
                }

                foreach ($value as $row) {
                    $row = (array) $row;

                    if (isset($row['builder']) && is_array($row['builder'])
                        && static::blocksReference($row['builder'], $section, $id)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
