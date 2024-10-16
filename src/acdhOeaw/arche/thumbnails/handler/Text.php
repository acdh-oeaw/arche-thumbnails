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
use ImagickDraw;
use ImagickPixel;
use acdhOeaw\arche\thumbnails\Resource;
use acdhOeaw\arche\thumbnails\NoSuchFileException;

/**
 * Creates the resource thumbnail by plotting first few lines of a resource content.
 *
 * @author zozlak
 */
class Text implements HandlerInterface {

    private object $config;

    public function __construct(object $config) {
        $this->config = $config;
    }

    /**
     * 
     * @return array<string>
     */
    public function getHandledMimeTypes(): array {
        return ['application/json', 'application/vnd.geo+json', 'application/xml',
            'text/xml', 'text/plain'];
    }

    public function maintainsAspectRatio(): bool {
        return false;
    }

    public function createThumbnail(Resource $resource, int $width, int $height,
                                    string $path): void {
        $srcPath   = $resource->getRefFilePath();
        $srcHandle = fopen($srcPath, 'r') ?: throw new NoSuchFileException();
        $nLines    = max($this->config->minLines, $height / $this->config->lineHeight);
        $lines     = [];
        while (count($lines) < $nLines) {
            $lines[] = fgets($srcHandle, 1000);
        }
        fclose($srcHandle);

        $x          = (int) ($width * $this->config->margin);
        $y          = (int) min($x, $height * $this->config->margin);
        $lineHeight = ($height - 2 * $y) / $nLines;
        $draw       = new ImagickDraw();
        $draw->setFontSize($lineHeight * 0.75);
        foreach ($lines as $n => $l) {
            $draw->annotation(5, $y + round(($n + 1) * $lineHeight), (string) $l);
        }

        $trgt = new Imagick();
        $trgt->newImage($height, $width, new ImagickPixel('white'));
        $trgt->drawImage($draw);
        $trgt->writeImage('png:' . $path);
    }
}
