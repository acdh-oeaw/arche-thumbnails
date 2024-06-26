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

use DateTimeImmutable;
use Throwable;
use PDO;
use quickRdf\DataFactory as DF;
use quickRdf\Dataset;
use quickRdfIo\NQuadsParser;
use quickRdfIo\RdfIoException;
use termTemplates\PredicateTemplate as PT;
use termTemplates\QuadTemplate as QT;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\StreamWrapper;
use GuzzleHttp\Exception\RequestException;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\lib\SearchTerm;
use zozlak\RdfConstants as RDF;
use zozlak\logging\Logger;
use acdhOeaw\arche\thumbnails\handler\HandlerInterface;

/**
 * Description of Resource
 *
 * @author zozlak
 */
class Resource implements ResourceInterface {

    static private Client $client;

    static private function resolveUrl(string $url): string {
        if (!isset(self::$client)) {
            self::$client = new Client([
                'allow_redirects' => ['track_redirects' => true],
                'verify'          => false
            ]);
        }
        try {
            $response = self::$client->send(new Request('HEAD', $url));
        } catch (RequestException $ex) {
            header('HTTP/1.1 ' . $ex->getCode() . ' ARCHE resource resolution error');
            exit($ex->getMessage() . "\n");
        }
        $redirects = array_merge([$url], $response->getHeader('X-Guzzle-Redirect-History'));
        return array_pop($redirects);
    }

    private object $config;
    private string $url;
    private PDO $pdo;
    private ResourceMeta $meta;
    private HandlerInterface $defaultHandler;

    /**
     *
     * @var array<HandlerInterface>
     */
    private $handlers = [];

    public function __construct(string $id, object $config) {
        Logger::info("Processing $id");

        $this->config = $config;
        $this->url    = $id;
        $this->meta   = new ResourceMeta();
        $this->pdo    = new PDO($config->db);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->maintainDb();
        $this->maintainMetadataCache();

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
     * Returns path to the resource thumbnail in a given dimensions.
     * 
     * Always returns a proper path.
     * 
     * @param int $width
     * @param int $height
     * @return string|false
     */
    public function getThumbnail(int $width = 0, int $height = 0): string | false {
        $path = $this->getFilePath($width, $height);
        if (file_exists($path)) {
            Logger::info("\tfound in cache ($path)");
        }
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $handler = $this->handlers[$this->meta->mime] ?? null;
        if (!file_exists($path) && $handler !== null) {
            if (!$handler->maintainsAspectRatio()) {
                $width  = $width > 0 ? $width : $this->config->defaultWidth;
                $height = $height > 0 ? $height : $this->config->defaultHeight;
            }
            try {
                Logger::info("\tusing the " . get_called_class() . " handler");
                Logger::info($this->getResourcePath());
                $this->handlers[$this->meta->mime]->createThumbnail($this, $width, $height, $path);
            } catch (Throwable $ex) {
                Logger::error($ex);
            }
        }

        // last chance
        if (!file_exists($path)) {
            $handler = $this->defaultHandler;
            if (!$handler->maintainsAspectRatio()) {
                $width  = $width > 0 ? $width : $this->config->efaultWidth;
                $height = $height > 0 ? $height : $this->config->defaultHeight;
            }
            Logger::info("\thandler for " . $this->meta->mime . " unavailable - using the fallback handler");
            Logger::info("\t\thandlers available for " . implode(', ', array_keys($this->handlers)));
            try {
                $handler->createThumbnail($this, $width, $height, $path);
            } catch (NoThumbnailException $ex) {
                Logger::info("\tno thumbnail generated according to the config");
                return false;
            }
        }

        return $path;
    }

    public function getMeta(): ResourceMeta {
        return clone($this->meta);
    }

    public function getResourcePath(): string {
        return $this->getThumbnailPath();
    }

    /**
     * Gets path to an already cached resource thumbnail of a given dimensions.
     * 
     * If $width and $height are not specified, returns path to the original resource file.
     * 
     * If the thumbnail of a given dimensions is not yet cached, a 
     * \acdhOeaw\arche\thumbnails\NoSuchFileException exception is thrown but the resource
     * in original dimensions can be assumed to be always available.
     * 
     * @param int $width
     * @param int $height
     * @return string
     * @throws NoSuchFileException
     */
    public function getThumbnailPath(int $width = 0, int $height = 0): string {
        $path = $this->getFilePath($width, $height);
        if (!file_exists($path)) {
            if ($width == 0 && $height == 0) {
                $this->fetchResourceFile();
            } else {
                throw new NoSuchFileException();
            }
        }

        return $path;
    }

    /**
     * List cached files for a given resource.
     * 
     * Returnes 2D array with first dimension indicating available widths and
     * second dimension listing heights available for a given width. Both dimensions
     * are encoded as strings left-padded with zeros up to 5 digits length.
     * @param int $order \SCANDIR_SORT_ASCENDING or SCANDIR_SORT_DESCENDING
     * @return array<string, array<string>>
     */
    public function getCachedFiles(int $order): array {
        $dir = dirname($this->getFilePath());
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (scandir($dir, $order) ?: [] as $i) {
            $i = explode('_', $i);
            if (count($i) > 1) {
                $w = sprintf('%05d', $i[0]);
                $h = sprintf('%05d', $i[1]);
                if (!isset($files[$w])) {
                    $files[$w] = [];
                }
                $files[$w][] = $h;
            }
        }
        return $files;
    }

    /**
     * Returns expected cached file location (but doesn't assure such a file exists).
     * 
     * If $width or $height are not specified, returns path to the original reference file.
     * @param int $width
     * @param int $height
     * @return string
     */
    private function getFilePath(int $width = 0, int $height = 0): string {
        $base = $this->config->cache->dir;
        $hash = hash('sha1', $this->meta->realUrl);
        return sprintf('%s/%s/%04d_%04d', $base, $hash, $width, $height);
    }

    /**
     * Fetches original resource
     * 
     * @throws FileToLargeException
     */
    private function fetchResourceFile(): string {
        $limit = $this->config->cache->maxFileSizeMb;
        if ($this->meta->sizeMb > $limit) {
            throw new FileToLargeException('Resource size (' . $this->meta->sizeMb . ' MB) exceeds the limit (' . $limit . ' MB)');
        }

        $path = $this->getFilePath();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $chunk = 10 ^ 6; // 1 MB
        $fin   = fopen($this->meta->realUrl, 'r') ?: throw new NoSuchFileException();
        $fout  = fopen($path, 'w') ?: throw new NoSuchFileException();
        while (!feof($fin)) {
            fwrite($fout, (string) fread($fin, $chunk));
        }
        fclose($fin);
        fclose($fout);

        return $path;
    }

    /**
     * Fetches resource's metadata into $this->meta
     * (from the database or, when needed, from the repository)
     */
    private function maintainMetadataCache(): void {
        $query = $this->pdo->prepare("
            SELECT 
                url, 
                check_date AS 'checkDate', 
                repo_hash AS 'repoHash', 
                mime, size_mb AS 'sizeMb', 
                real_url AS 'realUrl',
                class
            FROM resources 
            WHERE url = ?
        ");
        $query->execute([$this->url]);
        $tmp   = $query->fetchObject(ResourceMeta::class);
        if ($tmp !== false) {
            $this->meta            = $tmp;
            $this->meta->checkDate = new DateTimeImmutable($this->meta->checkDate);
        }
        $oldMeta = $this->meta;

        // compute time since last repository metadata check and update the local db
        $diff = max(999999999, $this->config->cache->keepAlive);
        if (!empty($this->meta->checkDate)) {
            $diff = (new DateTimeImmutable())->diff($this->meta->checkDate);
            $diff = $diff->days * 24 * 3600 + $diff->h * 3600 + $diff->i * 60 + $diff->s;
        }

        // if needed, fetch metadata from the repository
        if (empty($this->meta->checkDate) || $diff > $this->config->cache->keepAlive) {
            Logger::info("\tfetching fresh metadata from the repository ($diff s)");

            $parser  = new NQuadsParser(new DF(), false, NQuadsParser::MODE_TRIPLES);
            $meta    = null;
            $realUrl = $this->resolveUrl($this->url);
            $realUrl = (string) preg_replace('|/metadata|', '', $realUrl);

            // try to find thumbnail pointing to the resource
            $baseUrl   = substr($realUrl, 0, (int) strrpos($realUrl, '/'));
            $searchUrl = $baseUrl . '/search' .
                '?property[0]=' . urlencode($this->config->schema->titleImage) .
                '&value[0]=' . urlencode($this->url) .
                '&type[0]=' . urlencode(SearchTerm::TYPE_RELATION);
            $headers   = [
                $this->config->schema->metaFetchHeader => RepoResourceInterface::META_RESOURCE,
                'Accept'                               => 'application/n-triples',
            ];
            try {
                $resp = self::$client->send(new Request('GET', $searchUrl, $headers));
                if ($resp->getStatusCode() === 200) {
                    $meta = new Dataset();
                    $meta->add($parser->parseStream(StreamWrapper::getResource($resp->getBody())));
                    $sbj  = $meta->getSubject(new PT(DF::namedNode($this->config->schema->searchMatch)));
                    if ($sbj !== null) {
                        $realUrl = $sbj->getValue();
                        $meta->deleteExcept(new QT($sbj));
                        Logger::info("\t\tthumbnail pointing to the resource found");
                    } else {
                        $meta = null;
                    }
                }
            } catch (RequestException $e) {
                
            }

            // get the resource itself as a fallback
            if ($meta === null) {
                try {
                    $resp = self::$client->send(new Request('GET', $realUrl . '/metadata', $headers));
                    if ($resp->getStatusCode() === 200) {
                        $meta = new Dataset();
                        $meta->add($parser->parseStream(StreamWrapper::getResource($resp->getBody())));
                        Logger::info("\t\tusing resource itself");
                    }
                } catch (RequestException $e) {
                    Logger::error("\t\tRequestException while fetching resource metadata: " . $e->getMessage());
                } catch (RdfIoException $e) {
                    Logger::error("\t\tRdfIoException while parsing resource metadata:" . $e->getMessage());
                }
            }

            $hashProp    = DF::namedNode($this->config->schema->hash);
            $modDateProp = DF::namedNode($this->config->schema->modDate);
            $mimeProp    = DF::namedNode($this->config->schema->mime);
            $sizeProp    = DF::namedNode($this->config->schema->size);
            $classProp   = DF::namedNode(RDF::RDF_TYPE);
            $this->meta  = new ResourceMeta([
                'url'       => $this->url,
                'checkDate' => new DateTimeImmutable(),
                'repoHash'  => (string) ($meta->getObject(new PT($hashProp)) ?? $meta->getObject(new PT($modDateProp))),
                'mime'      => (string) $meta->getObject(new PT($mimeProp)),
                'sizeMb'    => round((int) $meta->getObject(new PT($sizeProp))?->getValue() / 1024 / 1024),
                'realUrl'   => $realUrl,
                'class'     => (string) $meta->getObject(new PT($classProp)),
            ]);

            if (empty($oldMeta->checkDate)) {
                $query = $this->pdo->prepare("
                    INSERT INTO resources (check_date, repo_hash, mime, size_mb, real_url, class, url)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
            } else {
                $query = $this->pdo->prepare("
                    UPDATE resources 
                    SET check_date = ?, repo_hash = ?, mime = ?, size_mb = ?, real_url = ?, class = ?
                    WHERE url = ?
                ");
            }
            $param = [
                $this->meta->checkDate->format('Y-m-d H:i:s'),
                $this->meta->repoHash,
                $this->meta->mime,
                $this->meta->sizeMb,
                $this->meta->realUrl,
                $this->meta->class,
                $this->url,
            ];
            $query->execute($param);
        } else {
            Logger::info("\tlocal metadata valid ($diff)");
        }
        Logger::info("\t" . json_encode($this->meta, JSON_UNESCAPED_SLASHES));

        // if metadata suggest resource has changed, delete local cache
        $dir = dirname($this->getFilePath());
        if ($oldMeta->repoHash !== $this->meta->repoHash && is_dir($dir)) {
            Logger::info("\tresource has changed - removing local cache");
            foreach (scandir($dir) ?: [] as $i) {
                $path = $dir . '/' . $i;
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /**
     * Assures database contains all the tables
     */
    private function maintainDb(): void {
        $this->pdo->query("
            CREATE TABLE IF NOT EXISTS resources (
                url text primary key,
                check_date timestamp not null,
                repo_hash timestamp not null,
                mime text not null,
                size_mb int not null,
                real_url text not null,
                class text not null
            )
        ");
    }
}
