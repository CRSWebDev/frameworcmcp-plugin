<?php namespace CRSCompany\FrameworCMcp;

use System\Classes\PluginBase;

/**
 * Plugin Information File
 *
 * @link https://docs.octobercms.com/4.x/extend/system/plugins.html
 */
class Plugin extends PluginBase
{
    /**
     * @var array require the FrameworC plugin, whose blueprints this API reads and writes.
     */
    public $require = ['CRSCompany.FrameworC'];

    /**
     * pluginDetails about this plugin.
     */
    public function pluginDetails()
    {
        return [
            'name' => 'FrameworC MCP',
            'description' => 'HTTP API pro tvorbu stránek pomocí AI (frameworc-mcp)',
            'author' => 'CRS',
            'icon' => 'icon-plug'
        ];
    }

    /**
     * register method, called when the plugin is first registered.
     */
    public function register()
    {
        //
    }

    /**
     * boot method, called right before the request route.
     */
    public function boot()
    {
        //
    }

    /**
     * registerSettings used by the backend.
     */
    public function registerSettings()
    {
        return [
            'settings' => [
                'label' => 'MCP API',
                'description' => 'Nastavení API pro napojení AI asistenta (frameworc-mcp)',
                'category' => 'FrameworC',
                'icon' => 'icon-plug',
                'class' => \CRSCompany\FrameworCMcp\Models\McpSetting::class,
                'order' => 510,
                'keywords' => 'mcp api ai token'
            ]
        ];
    }
}
