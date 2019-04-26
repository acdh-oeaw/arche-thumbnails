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

namespace acdhOeaw\repo\thumbnails\handler;

use Imagick;
use ImagickPixel;
use acdhOeaw\repo\thumbnails\ResourceInterface;

/**
 * Creates thumbnails from image files by rescaling it to the desired resolution.
 *
 * @author zozlak
 */
class Image implements HandlerInterface {

    public function __construct() {
        
    }

    public function getHandledMimeTypes(): array {
        return ['image/png', 'image/jpeg', 'image/tiff'];
    }

    public function createThumbnail(ResourceInterface $resource, int $width,
                                    int $height, string $path) {
        $srcPath   = $resource->getNotSmallerThumbnailPath($width, $height);
        $src       = new Imagick();
        $src->readImage($srcPath);
        $src->resizeImage($height, $width, Imagick::FILTER_LANCZOS, 1, true);
        $srcWidth  = $src->getImageWidth();
        $srcHeight = $src->getImageHeight();
        $x         = round(($width - $srcWidth) / 2);
        $y         = round(($height - $srcHeight) / 2);

        $trgt = new Imagick();
        $trgt->newImage($height, $width, new ImagickPixel('transparent'));
        $trgt->compositeImage($src, Imagick::COMPOSITE_COPY, $x, $y);
        $trgt->writeImage('png:' . $path);
    }

}
