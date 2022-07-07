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

namespace acdhOeaw\arche\thumbnails\handler;

use Imagick;
use ImagickPixel;
use acdhOeaw\arche\thumbnails\ResourceInterface;

/**
 * Creates thumbnails from image files by rescaling it to the desired resolution.
 *
 * @author zozlak
 */
class Image implements HandlerInterface {

    public function __construct() {
        
    }

    /**
     * 
     * @return array<string>
     */
    public function getHandledMimeTypes(): array {
        return ['image/png', 'image/jpeg', 'image/tiff', 'image/svg+xml'];
    }

    public function maintainsAspectRatio(): bool {
        return true;
    }

    public function createThumbnail(ResourceInterface $resource, int $width,
                                    int $height, string $path): void {
        $srcPath   = $resource->getResourcePath();
        $src       = new Imagick();
        $src->setBackgroundColor(new ImagickPixel('transparent'));
        $src->readImage($srcPath);
        $ratio     = $src->getImageWidth() / $src->getImageHeight();
        $width     = $width > 0 ? $width : $height * $ratio;
        $height    = $height > 0 ? $height : $width / $ratio;
        $src->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1, true);
        $srcWidth  = $src->getImageWidth();
        $srcHeight = $src->getImageHeight();
        $x         = (int) round(($width - $srcWidth) / 2);
        $y         = (int) round(($height - $srcHeight) / 2);

        $trgt = new Imagick();
        $trgt->newImage($width, $height, new ImagickPixel('transparent'));
        $trgt->compositeImage($src, Imagick::COMPOSITE_COPY, $x, $y);
        $trgt->writeImage('png:' . $path);
    }
}
