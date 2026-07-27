<?php namespace CRSCompany\FrameworCMcp\Controllers;

use CRSCompany\FrameworCMcp\Classes\ApiException;
use CRSCompany\FrameworCMcp\Classes\BlockSchema;
use CRSCompany\FrameworCMcp\Classes\PageSerializer;
use CRSCompany\FrameworCMcp\Classes\PageWriter;
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

            return response()->json(['sites' => SiteResolver::listSites()]);
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

            $page = PageWriter::update(static::findPage($id), $payload);

            return PageSerializer::full($page->fresh());
        });
    }

    public static function deletePage(Request $request, $id)
    {
        return static::run($request, function () use ($id) {
            $page = static::findPage($id);
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
    // Referenced entries
    // ------------------------------------------------------------------

    public static function entries(Request $request, string $section)
    {
        return static::run($request, function () use ($section) {
            $entries = EntryRecord::inSection($section)
                ->orderBy('title')
                ->get();

            return [
                'entries' => $entries->map(fn ($e) => [
                    'id' => (int) $e->id,
                    'title' => $e->title,
                ])->all(),
            ];
        });
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
            unset($data['site_id']);

            return SingleWriter::write($handle, $data);
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

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
        $page = EntryRecord::inSection(BlockSchema::SECTION)->where('id', (int) $id)->first();

        if (!$page) {
            throw ApiException::notFound('No page with id ' . $id . ' on this site.');
        }

        return $page;
    }
}
