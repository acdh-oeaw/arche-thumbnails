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
        // check if we can use an icon
        $map = $resource->getConfig('fallbackMap');
        if (isset($map[$mime])) {
            $img = $this->createFromMap($map[$mime], $width, $height);
        } else {
            $imgPath = $resource->getConfig('fallbackImage');
            $tc      = $resource->getConfig('fallbackFontColor');
            $tw      = $resource->getConfig('fallbackFontWeight');
            $ml      = $resource->getConfig('fallbackLabelMinLength');
            $sw      = $resource->getConfig('fallbackStrokeWidth');
            if ($imgPath !== null) {
                $x   = $resource->getConfig('fallbackX');
                $y   = $resource->getConfig('fallbackY');
                $lw  = $resource->getConfig('fallbackWidth');
                $lh  = $resource->getConfig('fallbackHeight');
                $img = $this->createFromTemplate($mime, $width, $height, $imgPath, $x, $y, $lw, $lh, $tc, $tw, $ml);
            } else {
                $img = $this->createGeneric($mime, $width, $height, $sw, $tc, $tw, $ml);
            }
        }
        $img->writeImage('png:' . $path);
    }

    private function createFromMap(string $imgPath, int $width, int $height): Imagick {
        $src       = new Imagick();
        $src->setBackgroundColor(new ImagickPixel('transparent'));
        $src->readImage($imgPath);
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
        return $trgt;
    }

    private function createFromTemplate(string $label, int $width, int $height, string $tmplPath, float $lx, float $ly, float $lw, float $lh, string $textColor, float $textWeight, int $labelMinLen): Imagick {
        $src       = new Imagick();
        $src->setBackgroundColor(new ImagickPixel('transparent'));
        $src->readImage($tmplPath);
        $srcWidth  = $src->getImageWidth();
        $srcHeight = $src->getImageHeight();

        // print the label on the original thumbnail
        $draw  = new ImagickDraw();
        $drawHeight = $srcHeight * $lh;
        $textColor = new ImagickPixel($textColor);
        $availWidth = $srcWidth * $lw;
        $draw->setFontSize(floor($drawHeight));
        $draw->setFontWeight($textWeight);
        $this->findFontSize($src, $draw, $availWidth, $label, $labelMinLen);
        // draw the text
        $draw->setStrokeWidth(0);
        $draw->setFillColor($textColor);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $draw->annotation($srcWidth * $lx, $srcHeight * $ly + ($drawHeight - $textGeom['textHeight']) / 2, $label);
        $src->drawImage($draw);

        // rescale
        $ratio     = $srcWidth / $srcHeight;
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
        return $trgt;
    }

    private function findFontSize(Imagick $img, ImagickDraw $draw, int $availWidth, string $label, int $labelMinLen): void {
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);
        $textGeom = $img->queryFontMetrics($draw, $label);
        while ($textGeom['textWidth'] > $availWidth) {
            if (mb_strlen($label) > $labelMinLen) {
                $label = mb_substr($label, 0, $labelMinLen);
            } else {
                $draw->setFontSize($draw->getFontSize() - 1);
            }
            $textGeom = $img->queryFontMetrics($draw, $label);
        }
    }

    private function createGeneric(string $label, int $width, int $height, float $strokeWidth, string $textColor, int $textWeight, int $labelMinLen): Imagick {
        // upscaling for nice font rendering in low resolution
	$upscale = 400 / min($width, $height);
        $upscale = $upscale <= 1 ? 1 : ceil($upscale);
        $width   *= $upscale;
        $height  *= $upscale;

        // Imagick initialization
        $trgt = new Imagick();
        $trgt->newImage($width, $height, new ImagickPixel('transparent'));

        $draw  = new ImagickDraw();
        $white = new ImagickPixel($textColor);
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
        $draw->setStrokeWidth($strokeWidth * min($rectWidth * 1.4, $rectHeight));
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
        $draw->setFontWeight($textWeight);
        $this->findFontSize($trgt, $draw, $bandWidth * 0.8, $label, $labelMinLen);
        // draw the text
        $draw->setStrokeWidth(0);
        $draw->setFillColor($white);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $draw->annotation($bandX1 + $bandWidth * 0.5, $bandY1 + ($bandHeight - $textGeom['textHeight']) / 1.5 + $textGeom['textHeight'], $label);

        // output
        $trgt->drawImage($draw);

        $trgt->resizeImage($width / $upscale, $height / $upscale, Imagick::FILTER_LANCZOS, 1, true); 
        return $trgt;
    }

}
