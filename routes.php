<?php

use CRSCompany\FrameworCMcp\Controllers\Api;
use Illuminate\Http\Request;

/**
 * The HTTP surface consumed by the frameworc-mcp MCP server.
 *
 * Paths mirror frameworc-mcp's src/api-client.ts exactly; do not rename them
 * without shipping a matching MCP release. Literal segments (blocks/order,
 * forms/schema) are registered before their {param} siblings on purpose.
 */
Route::group([
    'prefix' => 'api/mcp/v1',
    'middleware' => ['throttle:240,1'],
], function () {
    Route::get('sites', fn (Request $r) => Api::sites($r));

    Route::get('blocks', fn (Request $r) => Api::blocks($r));
    Route::get('blocks/{name}', fn (Request $r, $name) => Api::block($r, $name));

    // Pages
    Route::get('pages', fn (Request $r) => Api::listPages($r));
    Route::post('pages', fn (Request $r) => Api::createPage($r));
    Route::get('pages/{id}', fn (Request $r, $id) => Api::getPage($r, $id));
    Route::patch('pages/{id}', fn (Request $r, $id) => Api::updatePage($r, $id));
    Route::delete('pages/{id}', fn (Request $r, $id) => Api::deletePage($r, $id));
    Route::post('pages/{id}/translations', fn (Request $r, $id) => Api::createTranslation($r, $id));

    // Per-block operations on pages
    Route::post('pages/{id}/blocks/order', fn (Request $r, $id) => Api::reorderBlocks($r, $id));
    Route::post('pages/{id}/blocks', fn (Request $r, $id) => Api::addBlock($r, $id));
    Route::get('pages/{id}/blocks/{blockId}', fn (Request $r, $id, $blockId) => Api::getBlock($r, $id, $blockId));
    Route::patch('pages/{id}/blocks/{blockId}', fn (Request $r, $id, $blockId) => Api::updateBlock($r, $id, $blockId));
    Route::delete('pages/{id}/blocks/{blockId}', fn (Request $r, $id, $blockId) => Api::removeBlock($r, $id, $blockId));
    Route::post('pages/{id}/blocks/{blockId}/extract-to-prefill', fn (Request $r, $id, $blockId) => Api::extractBlockToPrefill($r, $id, $blockId));

    // Forms
    Route::get('forms/schema', fn (Request $r) => Api::formSchema($r));
    Route::get('forms', fn (Request $r) => Api::listForms($r));
    Route::post('forms', fn (Request $r) => Api::createForm($r));
    Route::get('forms/{id}', fn (Request $r, $id) => Api::getForm($r, $id));
    Route::patch('forms/{id}', fn (Request $r, $id) => Api::updateForm($r, $id));
    Route::delete('forms/{id}', fn (Request $r, $id) => Api::deleteForm($r, $id));

    // Menus
    Route::get('menus', fn (Request $r) => Api::listMenus($r));
    Route::post('menus', fn (Request $r) => Api::createMenu($r));
    Route::get('menus/{id}', fn (Request $r, $id) => Api::getMenu($r, $id));
    Route::patch('menus/{id}', fn (Request $r, $id) => Api::updateMenu($r, $id));
    Route::delete('menus/{id}', fn (Request $r, $id) => Api::deleteMenu($r, $id));

    // Prefills
    Route::get('prefills', fn (Request $r) => Api::listPrefills($r));
    Route::post('prefills', fn (Request $r) => Api::createPrefill($r));
    Route::get('prefills/{id}', fn (Request $r, $id) => Api::getPrefill($r, $id));
    Route::patch('prefills/{id}', fn (Request $r, $id) => Api::updatePrefill($r, $id));
    Route::delete('prefills/{id}', fn (Request $r, $id) => Api::deletePrefill($r, $id));
    Route::post('prefills/{id}/translations', fn (Request $r, $id) => Api::createPrefillTranslation($r, $id));

    // Per-block operations on prefills
    Route::post('prefills/{id}/blocks/order', fn (Request $r, $id) => Api::reorderBlocks($r, $id, 'Prefill'));
    Route::post('prefills/{id}/blocks', fn (Request $r, $id) => Api::addBlock($r, $id, 'Prefill'));
    Route::get('prefills/{id}/blocks/{blockId}', fn (Request $r, $id, $blockId) => Api::getBlock($r, $id, $blockId, 'Prefill'));
    Route::patch('prefills/{id}/blocks/{blockId}', fn (Request $r, $id, $blockId) => Api::updateBlock($r, $id, $blockId, 'Prefill'));
    Route::delete('prefills/{id}/blocks/{blockId}', fn (Request $r, $id, $blockId) => Api::removeBlock($r, $id, $blockId, 'Prefill'));

    // Singles (Meta, Navigation, Footer)
    Route::get('singles/{handle}', fn (Request $r, $handle) => Api::getSingle($r, $handle));
    Route::patch('singles/{handle}', fn (Request $r, $handle) => Api::updateSingle($r, $handle));

    // FrameworC settings (global)
    Route::get('settings', fn (Request $r) => Api::getSettings($r));
    Route::patch('settings', fn (Request $r) => Api::updateSettings($r));
});
