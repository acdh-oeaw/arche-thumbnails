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

use zozlak\logging\Log;
use acdhOeaw\arche\lib\RepoDb;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\exception\NotFound;
use acdhOeaw\arche\lib\dissCache\CachePdo;
use acdhOeaw\arche\lib\dissCache\ResponseCache;
use acdhOeaw\arche\thumbnails\Resource;
use acdhOeaw\arche\thumbnails\ResourceMeta;
use acdhOeaw\arche\lib\dissCache\RepoWrapperGuzzle;
use acdhOeaw\arche\lib\dissCache\RepoWrapperRepoInterface;
use acdhOeaw\arche\thumbnails\ThumbnailException;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Requested-With, Content-Type');

require_once 'vendor/autoload.php';

$config = json_decode(json_encode(yaml_parse_file('config.yaml')));

$logId               = sprintf("%08d", rand(0, 99999999));
$tmpl                = "{TIMESTAMP}:$logId:{LEVEL}\t{MESSAGE}";
$log                 = new Log($config->log->file, $config->log->level, $tmpl);
Resource::$logStatic = $log;
try {
    $id      = $_GET['id'] ?? 'no identifer provided';
    $allowed = false;
    foreach ($config->allowedNmsp as $i) {
        if (str_starts_with($id, $i)) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        throw new ThumbnailException("Requested resource $id not in allowed namespace", 400);
    }

    $cache = new CachePdo($config->db);

    $repos = [];
    foreach ($config->repoDb ?? [] as $i) {
        $repos[] = new RepoWrapperRepoInterface(RepoDb::factory($i), true);
    }
    $repos[] = new RepoWrapperGuzzle(false);

    $searchConfig                         = new SearchConfig();
    $searchConfig->metadataMode           = '1_0_0_0';
    $searchConfig->metadataParentProperty = $config->schema->titleImage;
    $searchConfig->resourceProperties     = array_values((array) $config->schema);
    $searchConfig->relativesProperties    = $searchConfig->resourceProperties;

    $cache = new ResponseCache($cache, fn($a, $b) => Resource::cacheHandler($a, $b), $config->cache->ttl->resource, $config->cache->ttl->response, $repos, $searchConfig, $log);

    $width  = filter_input(INPUT_GET, 'width') ?? 0;
    $height = filter_input(INPUT_GET, 'height') ?? 0;
    if ($width === 0 && $height === 0) {
        $width  = $config->defaultWidth;
        $height = $config->defaultHeight;
    }

    Resource::$schema = $config->schema;
    $cachedItem       = $cache->getResponse([$width, $height, $id], $id);
    $resMeta          = ResourceMeta::deserialize($cachedItem->body);
    $res              = new Resource($resMeta, $config, $log);

    $path = $res->getThumbnailPath($width, $height);
    header('Content-Size: ' . filesize($path));
    header('Content-Type: image/png');
    readfile($path);
} catch (\Throwable $e) {
    $code              = $e->getCode();
    $ordinaryException = $e instanceof ThumbnailException || $e instanceof NotFound;
    $logMsg            = "$code: " . $e->getMessage() . ($ordinaryException ? '' : "\n" . $e->getFile() . ":" . $e->getLine() . "\n" . $e->getTraceAsString());
    $log->error($logMsg);

    if ($code < 400 || $code >= 500) {
        $code = 500;
    }
    http_response_code($code);
    if ($ordinaryException) {
        echo $e->getMessage() . "\n";
    } else {
        echo "Internal Server Error\n";
    }
}

