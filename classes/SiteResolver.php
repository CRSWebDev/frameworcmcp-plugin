<?php namespace CRSCompany\FrameworCMcp\Classes;

use Closure;
use Site;
use System\Models\SiteDefinition;

/**
 * SiteResolver turns a request's site_id into a site context.
 *
 * Every handler runs inside Site::withContext. Without it, Tailor falls back to
 * the site matched from the request hostname, which on a route-prefix
 * multi-language install would silently read and write the wrong site.
 */
class SiteResolver
{
    /**
     * resolve returns the requested site, defaulting to the primary one.
     */
    public static function resolve($siteId = null): SiteDefinition
    {
        if ($siteId === null || $siteId === '') {
            $site = Site::getPrimarySite();

            if (!$site) {
                throw new ApiException('No site is configured on this install.', 500);
            }

            return $site;
        }

        $site = Site::getSiteFromId((int) $siteId);

        if (!$site) {
            throw ApiException::invalid([
                'site_id' => "Unknown site_id [{$siteId}]. Call GET /api/mcp/v1/sites for the available ids.",
            ]);
        }

        return $site;
    }

    /**
     * withSite runs the callback inside the resolved site's context.
     */
    public static function withSite($siteId, Closure $callback)
    {
        $site = static::resolve($siteId);

        return Site::withContext($site->id, fn () => $callback($site));
    }

    /**
     * listSites describes every configured site for the API consumer.
     */
    public static function listSites(): array
    {
        $primaryId = optional(Site::getPrimarySite())->id;

        return Site::listSites()->map(fn ($site) => [
            'id' => (int) $site->id,
            'name' => $site->name,
            'code' => $site->code,
            'locale' => $site->locale,
            'is_enabled' => (bool) $site->is_enabled,
            'is_primary' => (int) $site->id === (int) $primaryId,
        ])->values()->all();
    }
}
