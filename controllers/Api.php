<?php namespace CRSCompany\FrameworCMcp\Controllers;

use CRSCompany\FrameworCMcp\Classes\ApiException;
use CRSCompany\FrameworCMcp\Classes\BlockOps;
use CRSCompany\FrameworCMcp\Classes\BlockSchema;
use CRSCompany\FrameworCMcp\Classes\EntryGuard;
use CRSCompany\FrameworCMcp\Classes\FormSerializer;
use CRSCompany\FrameworCMcp\Classes\FormWriter;
use CRSCompany\FrameworCMcp\Classes\MenuSerializer;
use CRSCompany\FrameworCMcp\Classes\MenuWriter;
use CRSCompany\FrameworCMcp\Classes\PageSerializer;
use CRSCompany\FrameworCMcp\Classes\PageWriter;
use CRSCompany\FrameworCMcp\Classes\SettingsBridge;
use CRSCompany\FrameworCMcp\Classes\SchemaGuard;
use CRSCompany\FrameworCMcp\Classes\SingleWriter;
use CRSCompany\FrameworCMcp\Classes\SiteResolver;
use CRSCompany\FrameworCMcp\Classes\TokenGuard;
use Illuminate\Http\Request;
use October\Rain\Database\ModelException;
use Tailor\Models\EntryRecord;
use Throwable;

/**
 * Api handles every /api/mcp/v1 request.
 *
 * Each handler is wrapped by `run`, which authenticates, resolves the site
 * context and converts failures into the {message, errors} JSON the MCP client
 * knows how to display.
 */
class Api
{
    /**
     * run is the common entry point for all endpoints.
     */
    public static function run(Request $request, callable $handler, int $successStatus = 200)
    {
        try {
            TokenGuard::check($request);

            $siteId = $request->isMethod('GET') || $request->isMethod('DELETE')
                ? $request->query('site_id')
                : $request->input('site_id', $request->query('site_id'));

            $result = SiteResolver::withSite($siteId, fn ($site) => $handler($request, $site));

            return response()->json($result, $successStatus);
        }
        catch (ApiException $ex) {
            return $ex->toResponse();
        }
        catch (ModelException $ex) {
            // A model-level rule rejected the data; report it as a validation
            // failure rather than a server error.
            $errors = [];

            foreach ($ex->getModel()->errors()->getMessages() as $field => $messages) {
                $errors[$field] = array_values($messages);
            }

            return ApiException::invalid($errors, $ex->getMessage())->toResponse();
        }
        catch (Throwable $ex) {
            // Surface the reason rather than an HTML error page; the MCP only
            // ever shows the body to the user.
            return response()->json([
                'message' => $ex->getMessage(),
                'errors' => [],
            ], 500);
        }
    }

    // ------------------------------------------------------------------
    // Sites and schema
    // ------------------------------------------------------------------

    public static function sites(Request $request)
    {
        // Deliberately not site-scoped, but still authenticated.
        try {
            TokenGuard::check($request);

            return response()->json([
                'sites' => SiteResolver::listSites(),
                // Surfaced here so a stale database is visible before the first
                // write fails, rather than after.
                'schema' => SchemaGuard::report(),
            ]);
        }
        catch (ApiException $ex) {
            return $ex->toResponse();
        }
    }

    public static function blocks(Request $request)
    {
        return static::run($request, fn () => ['blocks' => array_values(BlockSchema::all())]);
    }

    public static function block(Request $request, string $name)
    {
        return static::run($request, fn () => BlockSchema::get($name));
    }

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    public static function listPages(Request $request)
    {
        return static::run($request, function () {
            $pages = EntryRecord::inSection(BlockSchema::SECTION)
                ->orderBy('fullslug')
                ->get();

            return ['pages' => $pages->map(fn ($p) => PageSerializer::summary($p))->all()];
        });
    }

    public static function getPage(Request $request, $id)
    {
        return static::run($request, fn () => PageSerializer::full(static::findPage($id)));
    }

    public static function createPage(Request $request)
    {
        return static::run($request, function (Request $request) {
            $payload = static::payload($request);

            $page = PageWriter::create($payload);

            return PageSerializer::full($page);
        }, 201);
    }

    public static function updatePage(Request $request, $id)
    {
        return static::run($request, function (Request $request) use ($id) {
            $payload = static::payload($request);

            // fullslug is derived and read-only; keep the teaching error in the
            // writer but strip it from an echoed payload so a get_page ->
            // update_page round trip does not 422.
            static::stripReadOnlyRecordKeys($payload, 'page');

            $page = PageWriter::update(static::findPage($id), $payload);

            return PageSerializer::full($page->fresh());
        });
    }

    public static function deletePage(Request $request, $id)
    {
        return static::run($request, function (Request $request) use ($id) {
            $page = static::findPage($id);

            EntryGuard::assertDeletable(BlockSchema::SECTION, $page, (bool) $request->query('force'));

            $page->delete();

            return ['deleted' => true, 'id' => (int) $id];
        });
    }

    public static function createTranslation(Request $request, $id)
    {
        return static::run($request, function (Request $request) use ($id) {
            $targetSiteId = $request->input('site_id');

            if (!$targetSiteId) {
                throw ApiException::invalid(['site_id' => 'This field is required.']);
            }

            // The source is read in its own site, which the caller names with
            // source_site_id when it differs from the target.
            $source = SiteResolver::withSite(
                $request->input('source_site_id'),
                fn () => static::findPage($id)
            );

            $payload = [
                'site_id' => SiteResolver::resolve($targetSiteId)->id,
                'page' => $request->input('page', []),
                'builder' => $request->input('builder'),
            ];

            $target = PageWriter::createTranslation($source, $payload);

            return PageSerializer::full($target->fresh());
        }, 201);
    }

    // ------------------------------------------------------------------
    // Per-block operations (pages and prefills)
    // ------------------------------------------------------------------

    public static function addBlock(Request $request, $id, string $section = BlockSchema::SECTION)
    {
        return static::run($request, function (Request $request) use ($id, $section) {
            $host = static::findEntry($section, $id);
            $position = $request->input('position');

            return BlockOps::add(
                $host,
                $section,
                (array) $request->input('block'),
                $position !== null ? (int) $position : null
            );
        }, 201);
    }

    public static function getBlock(Request $request, $id, $blockId, string $section = BlockSchema::SECTION)
    {
        return static::run($request, fn () => BlockOps::get(static::findEntry($section, $id), $blockId));
    }

    public static function updateBlock(Request $request, $id, $blockId, string $section = BlockSchema::SECTION)
    {
        return static::run($request, function (Request $request) use ($id, $blockId, $section) {
            $host = static::findEntry($section, $id);

            // The client sends the block object directly as the body; a
            // wrapped {block: ...} form works too.
            $body = $request->all();
            $block = isset($body['block']) && is_array($body['block']) ? $body['block'] : $body;
            unset($block['site_id']);

            return BlockOps::update($host, $section, $blockId, $block);
        });
    }

    public static function removeBlock(Request $request, $id, $blockId, string $section = BlockSchema::SECTION)
    {
        return static::run($request, fn () => BlockOps::remove(static::findEntry($section, $id), $section, $blockId));
    }

    public static function reorderBlocks(Request $request, $id, string $section = BlockSchema::SECTION)
    {
        return static::run($request, function (Request $request) use ($id, $section) {
            return BlockOps::reorder(
                static::findEntry($section, $id),
                $section,
                (array) $request->input('order', [])
            );
        });
    }

    public static function extractBlockToPrefill(Request $request, $id, $blockId)
    {
        return static::run($request, function (Request $request) use ($id, $blockId) {
            return BlockOps::extractToPrefill(
                static::findEntry(BlockSchema::SECTION, $id),
                $blockId,
                (string) $request->input('title', '')
            );
        }, 201);
    }

    // ------------------------------------------------------------------
    // Forms
    // ------------------------------------------------------------------

    public static function listForms(Request $request)
    {
        return static::run($request, function () {
            $forms = EntryRecord::inSection('Form')->orderBy('title')->get();

            return ['forms' => $forms->map(fn ($f) => FormSerializer::summary($f))->all()];
        });
    }

    public static function formSchema(Request $request)
    {
        return static::run($request, function () {
            $spec = FormWriter::fieldsSpec();

            return [
                'form' => [
                    'title' => ['type' => 'text', 'required' => true],
                    'slug' => ['type' => 'text', 'comment' => 'Derived from title when omitted.'],
                    'headline' => FormWriter::schema()['headline'] ?? ['type' => 'text'],
                    'recipients' => FormWriter::schema()['recipients'] ?? ['type' => 'taglist'],
                    'is_enabled' => ['type' => 'switch', 'default' => true],
                ],
                'fwcFields' => [
                    'comment' => 'Array of rows; each row sets "group" to one of the group keys plus that group\'s fields. Input groups require a unique "name".',
                    'groups' => $spec['groups'],
                ],
            ];
        });
    }

    public static function getForm(Request $request, $id)
    {
        return static::run($request, fn () => FormSerializer::full(static::findEntry('Form', $id)));
    }

    public static function createForm(Request $request)
    {
        return static::run($request, function (Request $request) {
            $form = FormWriter::create(static::payload($request));

            return FormSerializer::full($form);
        }, 201);
    }

    public static function updateForm(Request $request, $id)
    {
        return static::run($request, function (Request $request) use ($id) {
            $form = FormWriter::update(static::findEntry('Form', $id), static::payload($request));

            return FormSerializer::full($form->fresh());
        });
    }

    public static function deleteForm(Request $request, $id)
    {
        return static::run($request, function (Request $request) use ($id) {
            $form = static::findEntry('Form', $id);

            EntryGuard::assertDeletable('Form', $form, (bool) $request->query('force'));

            $form->delete();

            return ['deleted' => true, 'id' => (int) $id];
        });
    }

    // ------------------------------------------------------------------
    // Menus
    // ------------------------------------------------------------------

    public static function listMenus(Request $request)
    {
        return static::run($request, function () {
            $menus = EntryRecord::inSection('Menu')->orderBy('title')->get();

            return ['menus' => $menus->map(fn ($m) => MenuSerializer::summary($m))->all()];
        });
    }

    public static function getMenu(Request $request, $id)
    {
        return static::run($request, fn () => MenuSerializer::full(static::findEntry('Menu', $id)));
    }

    public static function createMenu(Request $request)
    {
        return static::run($request, function (Request $request) {
            $menu = MenuWriter::create(static::payload($request));

            return MenuSerializer::full($menu);
        }, 201);
    }

    public static function updateMenu(Request $request, $id)
    {
        return static::run($request, function (Request $request) use ($id) {
            $menu = MenuWriter::update(static::findEntry('Menu', $id), static::payload($request));

            return MenuSerializer::full($menu->fresh());
        });
    }

    public static function deleteMenu(Request $request, $id)
    {
        return static::run($request, function (Request $request) use ($id) {
            $menu = static::findEntry('Menu', $id);

            EntryGuard::assertDeletable('Menu', $menu, (bool) $request->query('force'));

            $menu->delete();

            return ['deleted' => true, 'id' => (int) $id];
        });
    }

    // ------------------------------------------------------------------
    // Prefills
    // ------------------------------------------------------------------

    public static function listPrefills(Request $request)
    {
        return static::run($request, function () {
            $prefills = EntryRecord::inSection('Prefill')->orderBy('title')->get();

            return ['prefills' => $prefills->map(fn ($p) => PageSerializer::prefillSummary($p))->all()];
        });
    }

    public static function getPrefill(Request $request, $id)
    {
        return static::run($request, fn () => PageSerializer::prefillFull(static::findEntry('Prefill', $id)));
    }

    public static function createPrefill(Request $request)
    {
        return static::run($request, function (Request $request) {
            $prefill = PageWriter::create(static::payload($request), 'Prefill');

            return PageSerializer::prefillFull($prefill);
        }, 201);
    }

    public static function updatePrefill(Request $request, $id)
    {
        return static::run($request, function (Request $request) use ($id) {
            $payload = static::payload($request);

            static::stripReadOnlyRecordKeys($payload, 'prefill');

            $prefill = PageWriter::update(static::findEntry('Prefill', $id), $payload, 'Prefill');

            return PageSerializer::prefillFull($prefill->fresh());
        });
    }

    public static function deletePrefill(Request $request, $id)
    {
        return static::run($request, function (Request $request) use ($id) {
            $prefill = static::findEntry('Prefill', $id);

            EntryGuard::assertDeletable('Prefill', $prefill, (bool) $request->query('force'));

            $prefill->delete();

            return ['deleted' => true, 'id' => (int) $id];
        });
    }

    public static function createPrefillTranslation(Request $request, $id)
    {
        return static::run($request, function (Request $request) use ($id) {
            $targetSiteId = $request->input('site_id');

            if (!$targetSiteId) {
                throw ApiException::invalid(['site_id' => 'This field is required.']);
            }

            $source = SiteResolver::withSite(
                $request->input('source_site_id'),
                fn () => static::findEntry('Prefill', $id)
            );

            $payload = [
                'site_id' => SiteResolver::resolve($targetSiteId)->id,
                'prefill' => $request->input('prefill', $request->input('page', [])),
                'builder' => $request->input('builder'),
            ];

            $target = PageWriter::createTranslation($source, $payload, 'Prefill');

            return PageSerializer::prefillFull($target->fresh());
        }, 201);
    }

    // ------------------------------------------------------------------
    // Singles
    // ------------------------------------------------------------------

    public static function getSingle(Request $request, string $handle)
    {
        return static::run($request, fn () => SingleWriter::read($handle));
    }

    public static function updateSingle(Request $request, string $handle)
    {
        return static::run($request, function (Request $request) use ($handle) {
            $data = $request->all();

            // A single's read returns {handle, id, site_id, fields, schema},
            // with the writable content nested under `fields`. The writer takes
            // content at the top level, so unwrap an echoed payload: when
            // `fields` is present and this single has no real `fields` field,
            // treat its contents as the payload. The envelope keys (handle, id,
            // schema, site_id) are stripped either way. site_id is never taken
            // from the body — site context always comes from the resolver.
            if (isset($data['fields']) && is_array($data['fields']) && !isset(SingleWriter::schema($handle)['fields'])) {
                $data = $data['fields'];
            }

            unset($data['handle'], $data['id'], $data['schema'], $data['site_id']);

            return SingleWriter::write($handle, $data);
        });
    }

    // ------------------------------------------------------------------
    // FrameworC settings
    // ------------------------------------------------------------------

    public static function getSettings(Request $request)
    {
        // Settings are global; site_id is accepted and ignored so a pinned
        // MCP session does not have to special-case this endpoint.
        return static::run($request, fn () => SettingsBridge::read());
    }

    public static function updateSettings(Request $request)
    {
        return static::run($request, function (Request $request) {
            $fields = $request->input('fields');

            if (!is_array($fields)) {
                throw ApiException::invalid(['fields' => 'Expected {"fields": {...}}.']);
            }

            return SettingsBridge::write($fields);
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * stripReadOnlyRecordKeys removes read-only record attributes the serializer
     * emits so an echoed payload round-trips through the writer's validator.
     *
     * Only `fullslug` is an error in the validator; the record id, site_id and
     * timestamps are silently ignored by fillPage already. Stripping fullslug
     * preserves the teaching nudge for genuinely-unknown attempts while
     * keeping a get -> update round trip from failing.
     */
    protected static function stripReadOnlyRecordKeys(array &$payload, string $key): void
    {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            unset($payload[$key]['fullslug']);
        }
    }

    /**
     * payload unwraps the MCP's optional `payload` envelope.
     *
     * create_page sends {page, builder} directly as the body, but keeping the
     * wrapped form working costs nothing.
     */
    protected static function payload(Request $request): array
    {
        $body = $request->all();

        if (isset($body['payload']) && is_array($body['payload'])) {
            return $body['payload'];
        }

        return $body;
    }

    /**
     * findPage loads a page in the current site context.
     */
    protected static function findPage($id): EntryRecord
    {
        return static::findEntry(BlockSchema::SECTION, $id);
    }

    /**
     * findEntry loads a record of any section in the current site context.
     */
    protected static function findEntry(string $section, $id): EntryRecord
    {
        $record = EntryRecord::inSection($section)->where('id', (int) $id)->first();

        if (!$record) {
            throw ApiException::notFound('No ' . $section . ' record with id ' . $id . ' on this site.');
        }

        return $record;
    }
}
