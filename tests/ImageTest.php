<?php

/*
 * The MIT License
 *
 * Copyright 2024 zozlak.
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

namespace acdhOeaw\arche\thumbnails\tests;

use acdhOeaw\arche\thumbnails\Resource;
use acdhOeaw\arche\thumbnails\handler\Image;

/**
 * Description of ImageTest
 *
 * @author zozlak
 */
class ImageTest extends HandlerTestBase {

    const HANDLER_CLASS          = Image::class;
    const MIME_TYPES             = ['image/png', 'image/jpeg', 'image/tiff', 'image/svg+xml'];
    const MAINTAINS_ASPECT_RATIO = true;

    public function testCreateThumbnail(): void {
        $res     = $this->createStub(Resource::class);
        $res->method('getRefFilePath')->willReturn(__DIR__ . '/data/image_input.jpg');
        self::$handler->createThumbnail($res, 100, 100, self::TMP_FILE);
        $this->assertImagesEqual(__DIR__ . '/data/image_output.png', self::TMP_FILE);
    }
}
