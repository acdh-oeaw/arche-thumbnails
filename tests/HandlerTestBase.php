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

use Imagick;
use acdhOeaw\arche\thumbnails\handler\HandlerInterface;

/**
 * Description of HandlerTestBase
 *
 * @author zozlak
 */
class HandlerTestBase extends \PHPUnit\Framework\TestCase {

    const TMP_FILE = '/tmp/_handlerTest_';

    static protected HandlerInterface $handler;

    static public function setUpBeforeClass(): void {
        $config = json_decode(json_encode(yaml_parse_file(__DIR__ . '/config.yaml')));
        foreach ($config->mimeHandlers as $i) {
            if (preg_replace('`^\\\\`', '', $i->class) === static::HANDLER_CLASS) {
                $class         = $i->class;
                self::$handler = new $class($i->config ?? new \stdClass());
            }
        }
    }

    public function setUp(): void {
        foreach (glob(self::TMP_FILE . "*") as $i) {
            unlink($i);
        }
    }

    public function tearDown(): void {
        foreach (glob(self::TMP_FILE . "*") as $i) {
            unlink($i);
        }
    }

    public function testGetHandledMimeTypes(): void {
        $expected = static::MIME_TYPES;
        $this->assertEquals($expected, self::$handler->getHandledMimeTypes());
    }

    public function testMaintainsAspectRatio(): void {
        $this->assertEquals(static::MAINTAINS_ASPECT_RATIO, self::$handler->maintainsAspectRatio());
    }

    /**
     * Images generated on different platforms may differ a little when text
     * rendering is involved, thus we first convert them to BMP and then compare 
     * byte-to-byte allowing configurable discrepancy ratio
     */
    protected function assertImagesEqual(string $path1, string $path2,
                                         float $minRatio = 1.0): void {
        $i1    = new Imagick();
        $i1->readImage($path1);
        $i1->setFormat('pbm');
        $i1b   = $i1->getImageBlob();
        $i2    = new Imagick();
        $i2->readImage($path2);
        $i2->setFormat('pbm');
        $i2b   = $i2->getImageBlob();
        $this->assertEquals(strlen($i1b), strlen($i2b));
        $size  = strlen($i1b);
        $match = 0;
        for ($i = 0; $i < $size; $i++) {
            $match += $i1b[$i] === $i2b[$i];
        }
        $this->assertGreaterThanOrEqual($minRatio, $match / $size);
    }
}
