<?php

/*
 * The MIT License
 *
 * Copyright 2019 Austrian Centre for Digital Humanities.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

use acdhOeaw\arche\lib\dissCache\Service;
use acdhOeaw\arche\thumbnails\Resource;
use acdhOeaw\arche\thumbnails\ResourceMeta;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Requested-With, Content-Type');

require_once 'vendor/autoload.php';

$t0 = microtime(true);

$service = new Service(__DIR__ . '/config.yaml');

$config = $service->getConfig();
$log    = $service->getLog();
$clbck  = fn($res, $param) => Resource::cacheHandler($res, $param, $config->schema, $log);
$service->setCallback($clbck);

$width  = filter_input(INPUT_GET, 'width') ?? 0;
$height = filter_input(INPUT_GET, 'height') ?? 0;
if ($width === 0 && $height === 0) {
    $width  = $config->defaultWidth;
    $height = $config->defaultHeight;
}
$response = $service->serveRequest($_GET['id'] ?? '', [$width, $height], $_GET['force'] ?? false);
if ($response->responseCode === 0) {
    try {
        $resMeta = ResourceMeta::deserialize($response->body);
        $res     = new Resource($resMeta, $config, $log);

        $path = $res->getThumbnailPath($width, $height);
        header('Content-Size: ' . filesize($path));
        header('Content-Type: image/png');
        readfile($path);
        $log->info("Thumbnail served in " . round(microtime(true) - $t0, 3) . " s");
    } catch (Throwable $e) {
        $service->processException($e)->send();
    }
} else {
    $response->send();
}

