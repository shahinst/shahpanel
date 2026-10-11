<?php

use Illuminate\Support\Facades\Route;
use Modules\Metal3d\Metal3dServiceProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
 * The theme's stylesheet and script. The file name is a fixed whitelist, so
 * nothing outside the assets directory can be asked for.
 */
Route::get('themes/metal3d/{file}', function (string $file) {
    $types = ['metal3d.css' => 'text/css', 'metal3d.js' => 'application/javascript'];
    $path = Metal3dServiceProvider::assetPath($file);

    abort_unless(isset($types[$file]) && is_file($path), 404);

    $response = new BinaryFileResponse($path, 200, ['Content-Type' => $types[$file].'; charset=UTF-8']);
    $response->setPublic()->setMaxAge(31536000)->setImmutable();

    return $response;
})->where('file', 'metal3d\.(css|js)')->name('metal3d.asset');
