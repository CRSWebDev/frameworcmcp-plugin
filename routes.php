<?php

use CRSCompany\FrameworCMcp\Controllers\Api;
use Illuminate\Http\Request;

/**
 * The HTTP surface consumed by the frameworc-mcp MCP server.
 *
 * Paths mirror frameworc-mcp's src/api-client.ts exactly; do not rename them
 * without shipping a matching MCP release.
 */
Route::group([
    'prefix' => 'api/mcp/v1',
    'middleware' => ['throttle:120,1'],
], function () {
    Route::get('sites', fn (Request $r) => Api::sites($r));

    Route::get('blocks', fn (Request $r) => Api::blocks($r));
    Route::get('blocks/{name}', fn (Request $r, $name) => Api::block($r, $name));

    Route::get('pages', fn (Request $r) => Api::listPages($r));
    Route::post('pages', fn (Request $r) => Api::createPage($r));
    Route::get('pages/{id}', fn (Request $r, $id) => Api::getPage($r, $id));
    Route::patch('pages/{id}', fn (Request $r, $id) => Api::updatePage($r, $id));
    Route::delete('pages/{id}', fn (Request $r, $id) => Api::deletePage($r, $id));
    Route::post('pages/{id}/translations', fn (Request $r, $id) => Api::createTranslation($r, $id));

    Route::get('forms', fn (Request $r) => Api::entries($r, 'Form'));
    Route::get('menus', fn (Request $r) => Api::entries($r, 'Menu'));
    Route::get('prefills', fn (Request $r) => Api::entries($r, 'Prefill'));

    Route::get('singles/{handle}', fn (Request $r, $handle) => Api::getSingle($r, $handle));
    Route::patch('singles/{handle}', fn (Request $r, $handle) => Api::updateSingle($r, $handle));
});
