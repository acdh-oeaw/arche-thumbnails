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

namespace acdhOeaw\arche\thumbnails;

use Throwable;
use Psr\Log\LoggerInterface;
use rdfInterface\QuadInterface;
use quickRdf\DataFactory as DF;
use termTemplates\PredicateTemplate as PT;
use termTemplates\QuadTemplate as QT;
use acdhOeaw\arche\lib\dissCache\ResponseCacheItem;
use acdhOeaw\arche\lib\dissCache\FileCache;
use acdhOeaw\arche\lib\dissCache\FileCacheException;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\thumbnails\handler\HandlerInterface;

/**
 * Description of Resource
 *
 * @author zozlak
 */
class Resource {

    const DEFAULT_MAX_FILE_SIZE_MB = 100;
    const REAL_URL_PROP            = 'http://real/url';

    /**
     * Gets the requested repository resource metadata and converts it to the thumbnail's
     * service ResourceMeta object.
     * 
     * @param array<mixed> $param
     */
    static public function cacheHandler(RepoResourceInterface $res,
                                        array $param, object $schema,
                                        ?LoggerInterface $log = null): ResponseCacheItem {
        $resUri = $res->getUri();
        $graph  = $res->getGraph()->getDataset();

        // handle isTitleResourceOf
        $titleImageProp = DF::namedNode($schema->titleImage);
        $idProp         = DF::namedNode($schema->id);
        $titleImageSbj  = $graph->getSubject(new PT($titleImageProp, $resUri));
        if ($titleImageSbj !== null) {
            $log?->info("titleImageOf found");
            // replace resource metadata with the title image metadata but keep the original resource ids
            // start with removal of original resource triples but identifiers
            // this has to be done first to avoid unexpected triple removal in forEach()
            $graph->delete(fn(QuadInterface $q) => $q->getSubject()->equals($resUri) && !$q->getPredicate()->equals($idProp));
            $graph->forEach(function (QuadInterface $q) use ($resUri, $idProp,
                                                             $titleImageSbj,
                                                             $titleImageProp) {
                if ($q->getSubject()->equals($resUri) && $q->getPredicate()->equals($idProp)) {
                    return $q;
                } elseif ($q->getSubject()->equals($titleImageSbj)) {
                    if ($q->getPredicate()->equals($titleImageProp)) {
                        return DF::quad($resUri, DF::namedNode(self::REAL_URL_PROP), $titleImageSbj);
                    } else {
                        return $q->withSubject($resUri);
                    }
                } else {
                    return null;
                }
            });
        } else {
            // in case an titleImageOf is accessed directly
            $graph->delete(new QT($resUri, $titleImageProp));
        }

        // return ResourceMeta
        $resourceMeta = ResourceMeta::fromDatasetNode($res->getGraph(), $schema);
        return new ResponseCacheItem($resourceMeta->serialize());
    }

    /**
     * 
     * @var array<HandlerInterface>
     */
    private array $handlers = [];
    private HandlerInterface $defaultHandler;
    private object $config;
    private ResourceMeta $meta;
    private LoggerInterface | null $log;
    private string $refFilePath;
    private string $tmpId;

    public function __construct(ResourceMeta $meta, object $config,
                                ?LoggerInterface $log) {
        $this->meta   = $meta;
        $this->config = $config;
        $this->log    = $log;
        $this->tmpId  = '.tmp' . rand(0, 100000);

        $this->log?->debug(json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function getResponse(int $width, int $height): ResponseCacheItem {
        $allowedRoles = array_intersect($this->meta->aclRead, $this->config->allowedAclRead);
        $allowedClass = in_array($this->meta->class, $this->config->allowedClasses);
        if (count($allowedRoles) === 0 && !$allowedClass) {
            throw new ThumbnailException("Unauthorized\n", 401);
        }

        $path    = $this->getFilePath($width, $height);
        $headers = ['Content-Type' => 'image/png'];

        // if the thumbnail exists and is up to date, just serve it
        if (file_exists($path) && filemtime($path) > $this->meta->modDate->getTimestamp()) {
            $this->log?->info("Serving $width x $height thumbnail from cache $path");
            $headers['Content-Size'] = (string) filesize($path);
            return new ResponseCacheItem($path, 200, $headers, false, true);
        }

        $limit = $this->config->maxFileSizeMb ?? self::DEFAULT_MAX_FILE_SIZE_MB;
        if ($this->meta->sizeMb > $limit) {
            throw new FileToLargeException("Resource size (" . $this->meta->sizeMb . " MB) exceeds the limit ($limit MB");
        }

        $this->generateThumbnail($path, $width, $height);
        $headers['Content-Size'] = (string) filesize($path);
        return new ResponseCacheItem($path, 200, $headers, false, true);
    }

    public function getRefFilePath(): string {
        if (!isset($this->refFilePath)) {
            $fileCache = new FileCache($this->config->cache->dir, $this->log, (array) $this->config->localAccess);
            try {
                $this->refFilePath = $fileCache->getRefFilePath($this->meta->realUrl, $this->meta->mime);
            } catch (FileCacheException $e) {
                $this->refFilePath = '';
            }
        }
        return $this->refFilePath;
    }

    public function getMeta(): ResourceMeta {
        return $this->meta;
    }

    private function generateThumbnail(string $path, int $width, int $height): void {
        $this->initHandlers();

        // generate the thumbnail
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $pathTmp = $path . $this->tmpId;

        $refPath = $this->getRefFilePath();

        $handler = $this->handlers[$this->meta->mime] ?? null;
        if ($handler !== null) {
            if (!$handler->maintainsAspectRatio()) {
                $width  = $width > 0 ? $width : $this->config->defaultWidth;
                $height = $height > 0 ? $height : $this->config->defaultHeight;
            }
            try {
                $this->log?->info("Generating $width x $height thumbnail from $refPath using the " . get_class($handler) . " handler");
                $handler->createThumbnail($this, $width, $height, $pathTmp);
            } catch (Throwable $ex) {
                $this->log?->error($ex->getMessage() . "\n" . $ex->getTraceAsString());
            }
        }

        // last chance
        if (!file_exists($pathTmp)) {
            $handler = $this->defaultHandler;
            if (!$handler->maintainsAspectRatio()) {
                $width  = $width > 0 ? $width : $this->config->efaultWidth;
                $height = $height > 0 ? $height : $this->config->defaultHeight;
            }
            $this->log?->info("Generating $width x $height thumbnail from $refPath using the " . get_class($handler) . " handler");
            $handler->createThumbnail($this, $width, $height, $pathTmp);
        }

        if (!file_exists($pathTmp)) {
            throw new NoSuchFileException("Thumbnail was not properly generated", 500);
        } else {
            $this->log?->info("Thumbnail generated");
        }

        if (!file_exists($path)) {
            rename($pathTmp, $path);
        }
    }

    private function initHandlers(): void {
        foreach ($this->config->mimeHandlers as $i) {
            $class   = $i->class;
            $handler = new $class($i->config ?? new \stdClass());
            foreach ($handler->getHandledMimeTypes() as $j) {
                $this->handlers[$j] = $handler;
            }
            $this->defaultHandler = $handler;
        }
    }

    /**
     * Returns expected cached file location (doesn't assure such a file exists).
     * 
     * @param int $width
     * @param int $height
     * @return string
     */
    private function getFilePath(int $width = 0, int $height = 0): string {
        return sprintf('%s/%s/%04d_%04d', $this->config->cache->dir, hash('xxh128', $this->meta->realUrl), $width, $height);
    }
}
