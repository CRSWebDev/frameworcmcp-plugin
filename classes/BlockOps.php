<?php namespace CRSCompany\FrameworCMcp\Classes;

use DB;
use Tailor\Models\EntryRecord;

/**
 * BlockOps edits single builder blocks without touching their siblings.
 *
 * This is the fine-grained alternative to rebuilding the whole builder array:
 * every operation leaves the other rows — including their ids and any media a
 * human assigned in the backend — exactly as they were.
 */
class BlockOps
{
    /**
     * add appends or inserts one block.
     *
     * Position is 1-based; omitted means append.
     */
    public static function add(EntryRecord $host, string $section, array $block, ?int $position): array
    {
        SchemaGuard::assertReady($section);
        PageWriter::validateBlocks([$block], 'block');

        return DB::transaction(function () use ($host, $block, $position) {
            $ids = static::builderIds($host);

            $item = PageWriter::writeBlock($host, 'builder', $block, count($ids) + 1);

            if ($position !== null) {
                $position = max(1, min($position, count($ids) + 1));
                array_splice($ids, $position - 1, 0, [(int) $item->id]);
                static::resequence($host, $ids);
            }

            $host->touch();
            $host->reloadRelations('builder');

            return [
                'block' => static::serializeRow($host, (int) $item->id),
                'builder_ids' => static::builderIds($host),
            ];
        });
    }

    /**
     * get returns one block by row id.
     */
    public static function get(EntryRecord $host, $blockId): array
    {
        return ['block' => static::serializeRow($host, $blockId)];
    }

    /**
     * update replaces one block in place.
     *
     * A block is a tree of rows with no stable identity for its children, so a
     * partial merge is not meaningful: the old rows are deleted and rewritten
     * at the same position, which means the row id changes. Media the payload
     * cannot carry is taken over from the old block wherever the payload
     * leaves the field empty — unless the block type changed, in which case
     * the old fields have no counterpart.
     */
    public static function update(EntryRecord $host, string $section, $blockId, array $block): array
    {
        SchemaGuard::assertReady($section);
        PageWriter::validateBlocks([$block], 'block');

        return DB::transaction(function () use ($host, $blockId, $block) {
            $existing = static::findRow($host, $blockId);
            $sort = (int) $existing->sort_order;

            $old = PageSerializer::block($existing);
            $block = static::mergeBlockMedia($block, $old);

            $existing->delete();
            $host->reloadRelations('builder');

            $item = PageWriter::writeBlock($host, 'builder', $block, $sort);

            $host->touch();
            $host->reloadRelations('builder');

            return [
                'block' => static::serializeRow($host, (int) $item->id),
                'builder_ids' => static::builderIds($host),
                'note' => 'The block row id changed; blocks are replaced, not patched.',
            ];
        });
    }

    /**
     * remove deletes one block and closes the gap in the ordering.
     */
    public static function remove(EntryRecord $host, string $section, $blockId): array
    {
        SchemaGuard::assertReady($section);

        return DB::transaction(function () use ($host, $blockId) {
            $existing = static::findRow($host, $blockId);
            $existing->delete();

            $host->reloadRelations('builder');
            static::resequence($host, static::builderIds($host));

            $host->touch();
            $host->reloadRelations('builder');

            return [
                'deleted' => true,
                'builder_ids' => static::builderIds($host),
            ];
        });
    }

    /**
     * reorder rewrites the block order from a full id permutation.
     */
    public static function reorder(EntryRecord $host, string $section, array $order): array
    {
        SchemaGuard::assertReady($section);

        $order = array_map('intval', array_values($order));
        $current = static::builderIds($host);

        $missing = array_diff($current, $order);
        $unknown = array_diff($order, $current);

        if ($missing || $unknown || count($order) !== count($current)) {
            throw ApiException::invalid([
                'order' => 'Expected a permutation of the current block ids: [' . implode(', ', $current) . '].',
            ]);
        }

        return DB::transaction(function () use ($host, $order) {
            static::resequence($host, $order);

            $host->touch();
            $host->reloadRelations('builder');

            return ['builder_ids' => static::builderIds($host)];
        });
    }

    /**
     * extractToPrefill moves a page block into a new Prefill entry.
     *
     * Prefills exist to de-duplicate: a section repeated across pages should
     * live once and be referenced everywhere. The block's serialized form is
     * rewritten into the new entry through the internal write path — skipping
     * the media-rejecting API validation — so human-assigned media survives
     * the move. The page block is replaced in place by a Prefill reference.
     */
    public static function extractToPrefill(EntryRecord $page, $blockId, string $title): array
    {
        SchemaGuard::assertReady(BlockSchema::SECTION);
        SchemaGuard::assertReady('Prefill');

        if (trim($title) === '') {
            throw ApiException::invalid(['title' => 'This field is required.']);
        }

        return DB::transaction(function () use ($page, $blockId, $title) {
            $existing = static::findRow($page, $blockId);

            if ($existing->content_group === 'Prefill') {
                throw ApiException::invalid([
                    'block_id' => 'This block already references a Prefill entry.',
                ]);
            }

            $sort = (int) $existing->sort_order;
            $serialized = PageSerializer::block($existing);
            $blockLabel = $serialized['base']['blockId'] ?? null;

            $prefill = PageWriter::create(['prefill' => ['title' => $title]], 'Prefill');
            PageWriter::writeBlock($prefill, 'builder', $serialized, 1);
            $prefill->reloadRelations('builder');

            $existing->delete();
            $page->reloadRelations('builder');

            $reference = PageWriter::writeBlock($page, 'builder', [
                'content_group' => 'Prefill',
                'base' => ['blockId' => $blockLabel ?: $title],
                'content' => ['block' => (int) $prefill->id],
            ], $sort);

            $page->touch();
            $page->reloadRelations('builder');

            return [
                'prefill' => PageSerializer::prefillFull($prefill),
                'block' => static::serializeRow($page, (int) $reference->id),
                'builder_ids' => static::builderIds($page),
            ];
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * findRow loads one builder row of the host or 404s.
     */
    protected static function findRow(EntryRecord $host, $blockId)
    {
        foreach ($host->builder as $row) {
            if ((int) $row->id === (int) $blockId) {
                return $row;
            }
        }

        throw ApiException::notFound(
            'No block with id ' . $blockId . ' on this record. Current ids: ['
            . implode(', ', static::builderIds($host)) . '].'
        );
    }

    /**
     * serializeRow re-reads and serializes one row.
     */
    protected static function serializeRow(EntryRecord $host, int $blockId): array
    {
        return PageSerializer::block(static::findRow($host, $blockId));
    }

    /**
     * builderIds lists the row ids in display order.
     */
    protected static function builderIds(EntryRecord $host): array
    {
        $rows = $host->builder->sortBy('sort_order');

        return array_map(fn ($row) => (int) $row->id, array_values($rows->all()));
    }

    /**
     * resequence assigns sort_order 1..n following the given id order.
     */
    protected static function resequence(EntryRecord $host, array $orderedIds): void
    {
        $rows = [];
        foreach ($host->builder as $row) {
            $rows[(int) $row->id] = $row;
        }

        $sort = 1;
        foreach ($orderedIds as $id) {
            if (!isset($rows[$id])) {
                continue;
            }

            $rows[$id]->sort_order = $sort++;
            $rows[$id]->save();
        }
    }

    /**
     * mergeBlockMedia carries old media values into a replacement payload.
     */
    protected static function mergeBlockMedia(array $new, array $old): array
    {
        if (($new['content_group'] ?? null) !== ($old['content_group'] ?? null)) {
            return $new;
        }

        $schema = BlockSchema::get($new['content_group']);

        $new['base'] = static::mergeFieldsMedia((array) ($new['base'] ?? []), (array) ($old['base'] ?? []), $schema['base']);
        $new['content'] = static::mergeFieldsMedia((array) ($new['content'] ?? []), (array) ($old['content'] ?? []), $schema['content']);

        return $new;
    }

    /**
     * mergeFieldsMedia fills empty media fields from the old values.
     *
     * Rows inside repeaters have no stable identity in the payload, so they
     * are matched by position — exact for flat fields, best-effort for nested
     * repeater rows.
     */
    protected static function mergeFieldsMedia(array $new, array $old, array $schema): array
    {
        foreach ($schema as $name => $spec) {
            if (!empty($spec['readonly'])) {
                $newEmpty = ContentWriter::isEmptyValue($new[$name] ?? null);
                $oldValue = $old[$name] ?? null;

                if ($newEmpty && !ContentWriter::isEmptyValue($oldValue)) {
                    $new[$name] = $oldValue;
                }
                continue;
            }

            if (!isset($new[$name]) || !isset($old[$name])) {
                continue;
            }

            if (!empty($spec['repeater'])) {
                if (!is_array($new[$name]) || !is_array($old[$name])) {
                    continue;
                }

                if (!empty($spec['recursive'])) {
                    foreach (array_values($new[$name]) as $i => $childBlock) {
                        if (isset($old[$name][$i])) {
                            $new[$name][$i] = static::mergeBlockMedia((array) $childBlock, (array) $old[$name][$i]);
                        }
                    }
                }
                elseif (empty($spec['grouped'])) {
                    foreach (array_values($new[$name]) as $i => $childRow) {
                        if (isset($old[$name][$i])) {
                            $new[$name][$i] = static::mergeFieldsMedia(
                                (array) $childRow,
                                (array) $old[$name][$i],
                                $spec['fields'] ?? []
                            );
                        }
                    }
                }
                continue;
            }

            if (isset($spec['fields'])) {
                if ((is_array($new[$name]) || is_object($new[$name])) && (is_array($old[$name]) || is_object($old[$name]))) {
                    $new[$name] = static::mergeFieldsMedia((array) $new[$name], (array) $old[$name], $spec['fields']);
                }
            }
        }

        return $new;
    }
}
