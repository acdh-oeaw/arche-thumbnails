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
use RuntimeException;
use Psr\Log\LoggerInterface;
use rdfInterface\QuadInterface;
use quickRdf\DataFactory as DF;
use termTemplates\PredicateTemplate as PT;
use termTemplates\QuadTemplate as QT;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use acdhOeaw\arche\lib\dissCache\ResponseCacheItem;
use acdhOeaw\arche\lib\RepoResourceInterface;
use zozlak\logging\Logger;
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
    private string $tmpId;

    public function __construct(ResourceMeta $meta, object $config,
                                ?LoggerInterface $log) {
        $this->meta   = $meta;
        $this->config = $config;
        $this->log    = $log;
        $this->tmpId  = '.tmp' . rand(0, 100000);

        $this->log?->debug(json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function getThumbnailPath(int $width, int $height): string {
        $path = $this->getFilePath($width, $height);

        // if the thumbnail exists and is up to date, just serve it
        if (file_exists($path) && filemtime($path) > $this->meta->modDate->getTimestamp()) {
            $this->log?->info("Serving $width x $height thumbnail from cache $path");
            return $path;
        }

        $limit = $this->maxFileSizeMb ?? self::DEFAULT_MAX_FILE_SIZE_MB;
        if ($this->meta->sizeMb > $limit) {
            throw new FileToLargeException("Resource size (" . $this->meta->sizeMb . " MB) exceeds the limit ($limit MB");
        }

        $refFilePath = $this->getRefFilePath();
        $this->generateThumbnail($path, $refFilePath, $width, $height);

        return $path;
    }

    /**
     * Returns the path to the full resolution image.
     * Downloads the resource content if needed.
     */
    public function getRefFilePath(): string {
        // direct local access
        foreach ($this->config->localAccess as $nmsp => $nmspCfg) {
            if (str_starts_with($this->meta->realUrl, $nmsp)) {
                $id     = (int) preg_replace('`^.*/`', '', $this->meta->realUrl);
                $level  = $nmspCfg->level;
                $path   = $nmspCfg->dir;
                $idPart = $id;
                while ($level > 0) {
                    $path   .= sprintf('/%02d', $idPart % 100);
                    $idPart = (int) ($idPart / 100);
                    $level--;
                }
                $path .= '/' . $id;
                return file_exists($path) ? $path : '';
            }
        }

        // cache access
        $path = $this->getFilePath();
        if (!file_exists($path)) {
            $this->fetchResourceBinary($path);
        }
        return $path;
    }

    public function getMeta(): ResourceMeta {
        return $this->meta;
    }

    private function generateThumbnail(string $path, string $refPath,
                                       int $width, int $height): void {
        $this->initHandlers();

        // generate the thumbnail
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $pathTmp = $path . $this->tmpId;

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

    /**
     * Fetches original resource
     * 
     * @throws FileToLargeException
     */
    private function fetchResourceBinary(string $path): void {
        if ($this->meta->mime === '') {
            return;
        }

        $this->log?->info("Downloading " . $this->meta->realUrl);

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $pathTmp = $path . $this->tmpId;

        $opts   = ['stream' => true, 'http_errors' => false];
        $client = new Client($opts);
        $resp   = $client->send(new Request('get', $this->meta->realUrl));
        // mime mismatch is most probably redirect to metadata
        $mime   = $resp->getHeader('Content-Type')[0] ?? 'lacking content type';
        if ($resp->getStatusCode() !== 200) {
            throw new NoSuchFileException();
        }
        if ($mime !== $this->meta->mime) {
            $this->log?->error("Mime mismatch: downloaded $mime, metadata " . $this->meta->mime);
            throw new NoSuchFileException('The requested file misses binary content');
        }
        $body  = $resp->getBody();
        $fout  = fopen($pathTmp, 'w') ?: throw new RuntimeException("Can't open $pathTmp for writing");
        $chunk = 10 ^ 6; // 1 MB
        while (!$body->eof()) {
            fwrite($fout, (string) $body->read($chunk));
        }
        fclose($fout);
        if (!file_exists($path)) {
            rename($pathTmp, $path);
        }
    }
}
