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

use DateTimeImmutable;
use quickRdf\DataFactory as DF;
use quickRdf\DatasetNode;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\thumbnails\Resource;
use acdhOeaw\arche\thumbnails\ResourceMeta;
use acdhOeaw\arche\thumbnails\ThumbnailException;
use acdhOeaw\arche\lib\dissCache\ResponseCacheItem;
use acdhOeaw\arche\lib\dissCache\FileCache;

/**
 * Description of ResourceTest
 *
 * @author zozlak
 */
class ResourceTest extends \PHPUnit\Framework\TestCase {

    static private object $config;
    static private object $schema;

    static public function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        self::$config = json_decode(json_encode(yaml_parse_file(__DIR__ . '/config.yaml')));
        self::$schema = new \stdClass();
        foreach (self::$config->schema as $k => $v) {
            self::$schema->$k = DF::namedNode($v);
        }
    }

    public function setUp(): void {
        parent::setUp();

        mkdir(self::$config->cache->dir, recursive: true);
        foreach ((array) (self::$config->localAccess ?? []) as $i) {
            if (!file_exists($i->dir)) {
                mkdir($i->dir, recursive: true);
            }
        }
    }

    public function tearDown(): void {
        parent::tearDown();

        system('rm -fR "' . self::$config->cache->dir . '"');
        foreach ((array) (self::$config->localAccess ?? []) as $i) {
            system('rm -fR "' . $i->dir . '"');
        }
    }

    public function testCacheHandler(): void {
        $modDate = '2024-10-16 09:46:30';
        $resUri  = DF::namedNode('http://foo');
        $graph   = new DatasetNode($resUri);
        $res     = $this->createStub(RepoResourceInterface::class);
        $res->method('getUri')->willReturn($graph->getNode());
        $res->method('getGraph')->willReturn($graph);

        // no metadata
        $graph->add(DF::quad($resUri, self::$schema->modDate, DF::literal($modDate)));
        $resp    = Resource::cacheHandler($res, [], self::$config->schema, null);
        $refResp = $this->getRefResponseItem((string) $resUri, (string) $resUri, '__no class__', '', '__no hash__', 0, $modDate, [
            ]);
        $this->assertEquals($refResp, $resp);

        // ordinaty metadata
        $graph->add([
            DF::quad($resUri, self::$schema->hash, DF::literal('sha1:foobar')),
            DF::quad($resUri, self::$schema->mime, DF::literal('foo/bar')),
            DF::quad($resUri, self::$schema->size, DF::literal(1234 << 20)),
            DF::quad($resUri, self::$schema->class, DF::literal('http://my/class')),
            DF::quad($resUri, self::$schema->modDate, DF::literal($modDate)),
            DF::quad($resUri, self::$schema->aclRead, DF::literal("public")),
        ]);
        $resp    = Resource::cacheHandler($res, [], self::$config->schema, null);
        $refResp = $this->getRefResponseItem((string) $resUri, (string) $resUri, 'http://my/class', 'foo/bar', 'sha1:foobar', 1234, $modDate, [
            'public']);
        $this->assertEquals($refResp, $resp);

        // isTitleImageOf
        $titleImgUri = DF::namedNode('http://title/image');
        $graph->add([
            DF::quad($titleImgUri, self::$schema->titleImage, $resUri),
            DF::quad($titleImgUri, self::$schema->hash, DF::literal('sha1:barbaz')),
            DF::quad($titleImgUri, self::$schema->mime, DF::literal('bar/baz')),
            DF::quad($titleImgUri, self::$schema->size, DF::literal(2345 << 20)),
            DF::quad($titleImgUri, self::$schema->class, DF::literal('http://titleImg/class')),
            DF::quad($titleImgUri, self::$schema->modDate, DF::literal('2024-10-01 09:46:30')),
            DF::quad($titleImgUri, self::$schema->aclRead, DF::literal("sstuhec")),
        ]);
        $resp        = Resource::cacheHandler($res, [], self::$config->schema, null);
        $refResp     = $this->getRefResponseItem((string) $resUri, (string) $titleImgUri, 'http://titleImg/class', 'bar/baz', 'sha1:barbaz', 2345, '2024-10-01 09:46:30', [
            'sstuhec']);
        $this->assertEquals($refResp, $resp);
    }

    public function testGetMeta(): void {
        $meta = $this->getResourceMeta('http://foo', 'http://bar', 'class', 'major/minor', 'sha1:foobar', 25, '2024-01-06 20:45:13', [
            'foo', 'bar']);
        $res  = new Resource($meta, self::$config, null);
        $this->assertEquals($meta, $res->getMeta());
    }

    public function testGetRefFilePathLocal(): void {
        $nmsp = 'https://arche.acdh.oeaw.ac.at/api/';
        $meta = $this->getResourceMeta('http://12345', $nmsp . '23456', 'class', 'major/minor', 'sha1:foobar', 25, '2024-01-06 20:45:13', [
            'public']);

        // local with missing binary
        $res = new Resource($meta, self::$config, null);
        $this->assertEquals('', $res->getRefFilePath());

        // local with existing binary
        $path = self::$config->localAccess->$nmsp->dir . '/56/34/23456';
        mkdir(dirname($path), recursive: true);
        file_put_contents($path, '');
        // new object is needed as the getRefFilePath() result is cached
        $res = new Resource($meta, self::$config, null);
        $this->assertEquals($path, $res->getRefFilePath());
    }

    public function testGetRefFilePathRemote(): void {
        $realUrl             = 'https://arche.acdh.oeaw.ac.at/api/504945';
        $config              = json_decode(json_encode(self::$config));
        $config->localAccess = null;
        $meta                = $this->getResourceMeta('http://12345', $realUrl, 'class', 'image/png', 'sha1:foobar', 25, '2024-01-06 20:45:13', [
            'sstuhec', 'public']);
        $res                 = new Resource($meta, $config, null);
        $this->assertEquals($config->cache->dir . '/001726ab4849b793e901a00b451231ae/' . FileCache::REF_FILE_NAME, $res->getRefFilePath());
    }

    public function testGetThumbnailPath(): void {
        $meta       = $this->getResourceMeta('http://12345', 'https://arche.acdh.oeaw.ac.at/api/504945', 'class', 'image/png', 'sha1:foobar', 25, '2024-01-06 20:45:13', [
            'sstuhec', 'public']);
        $res        = new Resource($meta, self::$config, null);
        $resp       = $res->getResponse(100, 100);
        $refPath    = self::$config->cache->dir . '/001726ab4849b793e901a00b451231ae/0100_0100';
        $refHeaders = [
            'Content-Size' => 2847,
            'Content-Type' => 'image/png',
        ];
        $refResp    = new ResponseCacheItem($refPath, 200, $refHeaders, false, true);
        $this->assertEquals($refResp, $resp);
    }

    public function testGetThumbnailPathUnauthorized(): void {
        $meta = $this->getResourceMeta('http://12345', 'https://arche.acdh.oeaw.ac.at/api/504945', 'class', 'image/png', 'sha1:foobar', 25, '2024-01-06 20:45:13', [
            'ikant-head']);
        $res  = new Resource($meta, self::$config, null);
        try {
            $res->getResponse(100, 100);
            $this->assertTrue(false);
        } catch (ThumbnailException $ex) {
            $this->assertTrue(true);
        }
    }

    private function getResourceMeta(string $url, string $realUrl,
                                     string $class, string $mime, string $hash,
                                     int $sizeMb, string $modDate,
                                     array $aclRead): ResourceMeta {
        $meta           = new ResourceMeta();
        $meta->url      = $url;
        $meta->realUrl  = $realUrl;
        $meta->class    = $class;
        $meta->mime     = $mime;
        $meta->repoHash = $hash;
        $meta->sizeMb   = $sizeMb;
        $meta->modDate  = new DateTimeImmutable($modDate);
        $meta->aclRead  = $aclRead;
        return $meta;
    }

    private function getRefResponseItem(string $url, string $realUrl,
                                        string $class, string $mime,
                                        string $hash, int $sizeMb,
                                        string $modDate, array $aclRead): ResponseCacheItem {
        $meta = $this->getResourceMeta($url, $realUrl, $class, $mime, $hash, $sizeMb, $modDate, $aclRead);
        return new ResponseCacheItem($meta->serialize(), 0, [], false);
    }
}
