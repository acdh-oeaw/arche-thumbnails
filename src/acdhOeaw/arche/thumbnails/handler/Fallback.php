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
use acdhOeaw\arche\thumbnails\ResourceInterface;

/**
 * A fallback thumbnail handler creating a document-like icon filled with
 * the resource's mime type
 *
 * @author zozlak
 */
class Fallback implements HandlerInterface {

    public function __construct() {
        
    }

    /**
     * 
     * @return array<string>
     */
    public function getHandledMimeTypes(): array {
        return [];
    }

    public function maintainsAspectRatio(): bool {
        return false;
    }

    public function createThumbnail(ResourceInterface $resource, int $width,
                                    int $height, string $path): void {
        // upscaling for nice font rendering in low resolution
	$upscale = 400 / min($width, $height);
        $upscale = $upscale <= 1 ? 1 : ceil($upscale);
        $width   *= $upscale;
        $height  *= $upscale;

        // sanitize the mime type
        $mime = $resource->getMeta()->mime;
        if (empty($mime)) {
            $mime = $resource->getMeta()->class ?? 'Collection';
            $mime = preg_replace('`^.*[/#]`', '', $mime);
        }
        $mime = explode('/', $mime);
        if (count($mime) > 1) {
            $mime = $mime[1];
        } else {
            $mime = $mime[0];
        }

        // Imagick initialization
        $trgt = new Imagick();
        $trgt->newImage($width, $height, new ImagickPixel('transparent'));

        $draw  = new ImagickDraw();
        $white = new ImagickPixel('white');
        $black = new ImagickPixel('black');

        // draw the document icon
        $ratio = $height / $width;
        if ($ratio > 1.2) {
            $rectWidth  = round($width * 0.8);
            $rectHeight = round($rectWidth * 1.4);
            $rectX1     = round($width * 0.1);
            $rectY1     = round(($height - $rectHeight) / 2);
            $bandWidth  = $width;
            $bandX1     = 0;
        } else {
            $rectHeight = $height;
            $rectWidth  = round($rectHeight / 1.4);
            $rectX1     = round(($width - $rectWidth) / 2);
            $rectY1     = 0;
            $bandWidth  = $rectWidth / 0.8;
            $bandX1     = round(($width - $bandWidth) / 2);
        }
        $bandHeight = round($rectHeight / 3);
        $bandY1     = $rectY1 + round($rectHeight * 0.45);
        $corner     = round($rectWidth / 3);
        $draw->setStrokeWidth($resource->getConfig('fallbackStrokeWidth') * min($rectWidth * 1.4, $rectHeight));
        $draw->setStrokeColor($black);
        $draw->setFillColor($white);
        $draw->polyline([
            ['x' => $rectX1, 'y' => $rectY1],
            ['x' => $rectX1, 'y' => $rectY1 + $rectHeight],
            ['x' => $rectX1 + $rectWidth, 'y' => $rectY1 + $rectHeight],
            ['x' => $rectX1 + $rectWidth, 'y' => $rectY1 + $corner],
            ['x' => $rectX1 + $rectWidth - $corner, 'y' => $rectY1],
            ['x' => $rectX1, 'y' => $rectY1],
        ]);
        $draw->setStrokeColor($black);
        $draw->setFillColor($black);
        $draw->polyline([
            ['x' => $rectX1 + $rectWidth - $corner, 'y' => $rectY1 + $corner],
            ['x' => $rectX1 + $rectWidth, 'y' => $rectY1 + $corner],
            ['x' => $rectX1 + $rectWidth - $corner, 'y' => $rectY1],
            ['x' => $rectX1 + $rectWidth - $corner, 'y' => $rectY1 + $corner],
        ]);
        $draw->rectangle($bandX1, $bandY1, $bandX1 + $bandWidth, $bandY1 + $bandHeight);

        // fit the text into the document icon band
        $draw->setFontSize(round($bandHeight / 2));
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);
        $draw->setFontWeight($resource->getConfig('fallbackFontWeight'));
        $textGeom = $trgt->queryFontMetrics($draw, $mime);
        while ($textGeom['textWidth'] > $bandWidth * 0.8) {
            if (strlen($mime) > 10) {
                $mime = substr($mime, 0, 10);
            } else {
                $draw->setFontSize($draw->getFontSize() - 1);
            }
            $textGeom = $trgt->queryFontMetrics($draw, $mime);
        }
        // draw the text
        $draw->setStrokeWidth(0);
        $draw->setFillColor($white);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $draw->annotation($bandX1 + $bandWidth * 0.5, $bandY1 + ($bandHeight - $textGeom['textHeight']) / 3 + $textGeom['textHeight'], $mime);

        // output
        $trgt->drawImage($draw);

        $trgt->resizeImage($width / $upscale, $height / $upscale, Imagick::FILTER_LANCZOS, 1, true); 
        $trgt->writeImage('png:' . $path);
    }

}
