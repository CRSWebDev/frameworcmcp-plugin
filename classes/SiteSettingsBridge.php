<?php namespace CRSCompany\FrameworCMcp\Classes;

use CRSCompany\FrameworC\Models\FrameworcSiteSetting;
use Site;
use Yaml;

/**
 * SiteSettingsBridge exposes the per-site FrameworC settings over the API.
 *
 * Same schema, validation and coercion as SettingsBridge, but backed by the
 * multisite FrameworcSiteSetting model with flat fields (no `wrapper`). The
 * site comes from the request context set up by SiteResolver.
 */
class SiteSettingsBridge extends SettingsBridge
{
    /**
     * @var array|null schema memoised per request.
     */
    protected static $schema = null;

    /**
     * @var string scope reported in read responses.
     */
    protected static $scope = 'site';

    /**
     * fieldDefinitions returns the raw field definitions from fields.yaml.
     */
    protected static function fieldDefinitions(): array
    {
        $path = plugins_path('crscompany/frameworc/models/frameworcsitesetting/fields.yaml');

        if (!class_exists(FrameworcSiteSetting::class) || !file_exists($path)) {
            throw new ApiException('The FrameworC per-site settings are missing. Update the FrameworC plugin.', 500);
        }

        $config = (array) Yaml::parseFile($path);

        return (array) ($config['tabs']['fields'] ?? $config['fields'] ?? []);
    }

    /**
     * storedValues returns every stored value of the active site.
     */
    protected static function storedValues(): array
    {
        return FrameworcSiteSetting::instance()->toArray();
    }

    /**
     * storeValues writes the given values to the active site.
     */
    protected static function storeValues(array $values): void
    {
        FrameworcSiteSetting::set($values);
        FrameworcSiteSetting::clearInternalCache();
    }

    /**
     * read adds the site the values belong to.
     */
    public static function read(): array
    {
        return ['site_id' => (int) Site::getSiteIdFromContext()] + parent::read();
    }
}
