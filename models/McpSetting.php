<?php namespace CRSCompany\FrameworCMcp\Models;

use Model;

/**
 * McpSetting Model
 *
 * @link https://docs.octobercms.com/4.x/extend/system/models.html
 */
class McpSetting extends \System\Models\SettingModel
{
    public $settingsCode = 'frameworc_mcp_settings';

    public $settingsFields = 'fields.yaml';

    /**
     * getApiToken returns the configured bearer token, or null when the API is disabled.
     */
    public static function getApiToken(): ?string
    {
        $token = trim((string) static::instance()->api_token);

        return $token !== '' ? $token : null;
    }
}
