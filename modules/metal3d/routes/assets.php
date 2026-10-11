<?php

use Illuminate\Support\Facades\Route;
use Modules\Metal3d\Metal3dServiceProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
 * The theme's stylesheet and script. The URL has no file extension on
 * purpose: the installer's nginx config answers *.css and *.js itself with
 * "try_files $uri =404" and then hands the 404 to index.php, so a URL ending
 * in .css would reach Laravel but go out with status 404, and the browser
 * refuses to apply a stylesheet that arrived as a 404.
 */
Route::get('themes/metal3d/{kind}', function (string $kind) {
    $files = ['css' => ['metal3d.css', 'text/css'], 'js' => ['metal3d.js', 'application/javascript']];
    $path = Metal3dServiceProvider::assetPath($files[$kind][0]);

    abort_unless(is_file($path), 404);

    $response = new BinaryFileResponse($path, 200, ['Content-Type' => $files[$kind][1].'; charset=UTF-8']);
    $response->setPublic()->setMaxAge(31536000)->setImmutable();

    return $response;
})->where('kind', 'css|js')->name('metal3d.asset');
