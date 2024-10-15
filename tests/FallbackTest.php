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
use acdhOeaw\arche\thumbnails\ResourceMeta;
use acdhOeaw\arche\thumbnails\NoThumbnailException;
use acdhOeaw\arche\thumbnails\handler\Fallback;

/**
 * Description of FallbackTest
 *
 * @author zozlak
 */
class FallbackTest extends HandlerTestBase {

    const HANDLER_CLASS          = Fallback::class;
    const MIME_TYPES             = [];
    const MAINTAINS_ASPECT_RATIO = false;

    public function testCreateThumbnailMap(): void {
        $config = json_decode(json_encode(yaml_parse_file(__DIR__ . '/config.yaml')));
        foreach ($config->mimeHandlers as $i) {
            if (preg_replace('`^\\\\`', '', $i->class) === static::HANDLER_CLASS) {
                $class                            = $i->class;
                $i->config->classMap->Publication = __DIR__ . '/data/fallback_map_input.png';
                $handler                          = new $class($i->config ?? new \stdClass());
                break;
            }
        }

        $meta        = new ResourceMeta();
        $meta->mime  = '';
        $meta->class = 'https://foo/bar#Publication';
        $res         = $this->createStub(Resource::class);
        $res->method('getMeta')->willReturn($meta);
        $handler->createThumbnail($res, 100, 100, self::TMP_FILE);
        $this->assertFileEquals(__DIR__ . '/data/fallback_map_output.png', self::TMP_FILE);
    }

    public function testCreateThumbnailTemplate(): void {
        $config               = $this->getConfig();
        $config->defaultImage = __DIR__ . '/data/fallback_template_input.png';
        $handler              = new Fallback($config);

        $meta        = new ResourceMeta();
        $meta->mime  = '';
        $meta->class = 'foo/bar';
        $res         = $this->createStub(Resource::class);
        $res->method('getMeta')->willReturn($meta);
        $handler->createThumbnail($res, 100, 100, self::TMP_FILE);
        $this->assertFileEquals(__DIR__ . '/data/fallback_template_output.png', self::TMP_FILE);
    }

    public function testCreateThumbnailGeneric(): void {
        $config               = $this->getConfig();
        $config->defaultImage = null;
        $config->drawGeneric  = true;
        $handler              = new Fallback($config);

        $meta        = new ResourceMeta();
        $meta->mime  = 'foo/bar';
        $meta->class = '';
        $res         = $this->createStub(Resource::class);
        $res->method('getMeta')->willReturn($meta);
        $handler->createThumbnail($res, 100, 100, self::TMP_FILE);
        $this->assertFileEquals(__DIR__ . '/data/fallback_generic.png', self::TMP_FILE);
    }

    public function testCreateThumbnailNoThumbnail(): void {
        $config               = $this->getConfig();
        $config->defaultImage = null;
        $config->drawGeneric  = false;
        $handler              = new Fallback($config);

        $meta        = new ResourceMeta();
        $meta->mime  = 'foo/bar';
        $meta->class = '';
        $res         = $this->createStub(Resource::class);
        $res->method('getMeta')->willReturn($meta);
        try {
            $handler->createThumbnail($res, 100, 100, self::TMP_FILE);
            $this->assertTrue(false);
        } catch (NoThumbnailException) {
            $this->assertTrue(true);
        }
    }

    private function getConfig(): object {
        $config = json_decode(json_encode(yaml_parse_file(__DIR__ . '/config.yaml')));
        foreach ($config->mimeHandlers as $i) {
            if (preg_replace('`^\\\\`', '', $i->class) === static::HANDLER_CLASS) {
                return $i->config;
            }
        }
    }
}
