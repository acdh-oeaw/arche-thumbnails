#!/usr/bin/php
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

use zozlak\util\Config;
use acdhOeaw\repo\thumbnails\ClearCache;

$composer = require_once __DIR__ . '/vendor/autoload.php';
$composer->addPsr4('acdhOeaw\\', __DIR__ . '/src/acdhOeaw');


if (!in_array($argc, [2, 4])) {
    echo "Clears thumbnails cache\nUsage:\n";
    echo "  " . $argv[0] . " pathToConfig.ini\n";
    echo "or\n";
    echo "  " . $argv[0] . " cacheDir cacheSizeLimitMb mode\n      where `mode` should be 'time' or 'size'\n";
    exit();
}

if ($argc === 2) {
    $config  = new Config($argv[1]);
    $dir     = $config->get('cacheDir');
    $maxSize = $config->get('cacheMaxSizeMb');
    $mode = $config->get('cacheMaxSizeMb');
} else {
    $dir     = $argv[1];
    $maxSize = $argv[2];
    $mode    = $argv[3];
}
$mode = $mode === 'size' ? ClearCache::BY_SIZE : ClearCache::BY_MOD_TIME;

$cache = new ClearCache($dir);
$cache->clean($maxSize, $mode);
