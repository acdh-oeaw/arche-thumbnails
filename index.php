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
use zozlak\logging\Log;
use zozlak\logging\Logger;
use acdhOeaw\arche\thumbnails\Resource;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Requested-With, Content-Type');

$composer = require_once 'vendor/autoload.php';

$config = new Config('config.ini');

Logger::addLog(new Log($config->get('logFile')), $config->get('logLevel'));

// extract ARCHE id from the request URL
$url = filter_input(INPUT_GET, 'id');
if (empty($url)) {
    $url = substr($_SERVER['REDIRECT_URL'], strlen($config->get('baseUrl')));
    if (!preg_match('|^https?://|', $url)) {
        $url = $config->get('archeIdPrefix') . $url;
    }
}

$width  = filter_input(INPUT_GET, 'width') ?? 0;
$height = filter_input(INPUT_GET, 'height') ?? 0;
if ($width === 0 && $height === 0) {
    $width  = $config->get('thumbnailDefaultWidth');
    $height = $config->get('thumbnailDefaultHeight');
}

$res   = new Resource($url, $config);
$path = $res->getThumbnail($width, $height);
header('Content-Type: image/png');
header('Content-Size: ' . filesize($path));
readfile($path);
