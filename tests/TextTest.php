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
use acdhOeaw\arche\thumbnails\handler\Text;

/**
 * Description of TextTest
 *
 * @author zozlak
 */
class TextTest extends HandlerTestBase {

    const HANDLER_CLASS          = Text::class;
    const MIME_TYPES             = ['application/json', 'application/vnd.geo+json',
        'application/xml', 'text/xml', 'text/plain'];
    const MAINTAINS_ASPECT_RATIO = false;

    public function testCreateThumbnail(): void {
        $tmpFile = self::TMP_FILE . "res";
        file_put_contents($tmpFile, "foo\nbar\nbaz");
        $res     = $this->createStub(Resource::class);
        $res->method('getRefFilePath')->willReturn($tmpFile);
        self::$handler->createThumbnail($res, 100, 100, self::TMP_FILE);
        $this->assertImagesEqual(__DIR__ . '/data/text.png', self::TMP_FILE);
    }
}
