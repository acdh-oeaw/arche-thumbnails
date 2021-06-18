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

use DateTime;
use Throwable;
use PDO;
use quickRdf\DataFactory as DF;
use quickRdf\Dataset;
use quickRdfIo\NQuadsParser;
use quickRdfIo\RdfIoException;
use termTemplates\QuadTemplate as QT;
use termTemplates\DatasetExtractors as DE;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\StreamWrapper;
use GuzzleHttp\Exception\RequestException;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\lib\SearchTerm;
use zozlak\util\Config;
use zozlak\logging\Logger;

/**
 * Description of Resource
 *
 * @author zozlak
 */
class Resource implements ResourceInterface {

    /**
     *
     * @var Client;
     */
    static private $client;

    static private function resolveUrl(string $url): string {
        if (self::$client === null) {
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

    /**
     *
     * @var \zozlak\util\Config
     */
    private $config;

    /**
     *
     * @var string
     */
    private $url;

    /**
     *
     * @var \PDO
     */
    private $pdo;

    /**
     *
     * @var \acdhOeaw\arche\thumbnails\ResourceMeta
     */
    private $meta;

    /**
     *
     * @var array<handler\HandlerInterface>
     */
    private $handlers = [];

    /**
     * 
     * @param string $id
     * @param Config $config
     */
    public function __construct(string $id, Config $config) {
        Logger::info("Processing $id");

        $this->config = $config;
        $this->url    = $id;
        $this->meta   = new ResourceMeta();
        $this->pdo    = new PDO($config->get('db'));
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->maintainDb();
        $this->maintainMetadataCache();

        foreach ($this->config->get('mimeHandlers') as $i) {
            $handler = new $i();
            foreach ($handler->getHandledMimeTypes() as $j) {
                $this->handlers[$j] = $handler;
            }
        }
    }

    /**
     * Returns path to the resource thumbnail in a given dimensions.
     * 
     * Always returns a proper path.
     * 
     * @param int $width
     * @param int $height
     * @return string
     */
    public function getThumbnail(int $width = 0, int $height = 0): string {
        $path = $this->getFilePath($width, $height);
        if (file_exists($path)) {
            Logger::info("\tfound in cache");
        }
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $handler = $this->handlers[$this->meta->mime] ?? null;
        if (!file_exists($path) && $handler !== null) {
            if (!$handler->maintainsAspectRatio()) {
                $width  = $width > 0 ? $width : $this->config->get('thumbnailDefaultWidth');
                $height = $height > 0 ? $height : $this->config->get('thumbnailDefaultHeight');
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
            $handlerClass = $this->config->get('mimeFallbackHandler');
            $handler      = new $handlerClass();
            if (!$handler->maintainsAspectRatio()) {
                $width  = $width > 0 ? $width : $this->config->get('thumbnailDefaultWidth');
                $height = $height > 0 ? $height : $this->config->get('thumbnailDefaultHeight');
            }
            Logger::info("\thandler for " . $this->meta->mime . " unavailable - using the fallback handler");
            Logger::info("\t\thandlers available for " . implode(', ', array_keys($this->handlers)));
            $handler->createThumbnail($this, $width, $height, $path);
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

    public function getConfig(string $property): mixed {
        return $this->config->get($property);
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
        foreach (scandir($dir, $order) as $i) {
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
        $base = $this->config->get('cacheDir');
        $hash = hash('sha1', $this->meta->realUrl);
        return sprintf('%s/%s/%04d_%04d', $base, $hash, $width, $height);
    }

    /**
     * Fetches original resource
     * 
     * @throws FileToLargeException
     */
    private function fetchResourceFile(): string {
        $limit = $this->config->get('cacheMaxFileSizeMb');
        if ($this->meta->sizeMb > $limit) {
            throw new FileToLargeException('Resource size (' . $this->meta->sizeMb . ' MB) exceeds the limit (' . $limit . ' MB)');
        }

        $path = $this->getFilePath();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $chunk = 10 ^ 6; // 1 MB
        $fin   = fopen($this->meta->realUrl, 'r');
        $fout  = fopen($path, 'w');
        while (!feof($fin)) {
            fwrite($fout, fread($fin, $chunk));
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
                real_url AS 'realUrl' 
            FROM resources 
            WHERE url = ?
        ");
        $query->execute([$this->url]);
        $tmp   = $query->fetchObject('\\acdhOeaw\\arche\\thumbnails\\ResourceMeta');
        if ($tmp !== false) {
            $this->meta            = $tmp;
            $this->meta->checkDate = new DateTime($this->meta->checkDate);
        }
        $oldMeta = $this->meta;

        // compute time since last repository metadata check and update the local db
        $diff = max(999999999, $this->config->get('cacheKeepAlive'));
        if (!empty($this->meta->checkDate)) {
            $diff = (new DateTime())->diff($this->meta->checkDate);
            $diff = $diff->days * 24 * 3600 + $diff->h * 3600 + $diff->i * 60 + $diff->s;
        }

        // if needed, fetch metadata from the repository
        if (empty($this->meta->checkDate) || $diff > $this->config->get('cacheKeepAlive')) {
            Logger::info("\tfetching fresh metadata from the repository ($diff s)");

            $parser  = new NQuadsParser(new DF(), false, NQuadsParser::MODE_TRIPLES);
            $meta    = null;
            $realUrl = $this->resolveUrl($this->url);
            $realUrl = preg_replace('|/metadata|', '', $realUrl);

            // try to find thumbnail pointing to the resource
            $baseUrl   = substr($realUrl, 0, strrpos($realUrl, '/'));
            $searchUrl = $baseUrl . '/search' .
                '?property[0]=' . urlencode($this->config->get('archeTitleImageProp')) .
                '&value[0]=' . urlencode($this->url) .
                '&type[0]=' . urlencode(SearchTerm::TYPE_RELATION);
            $headers   = [
                $this->config->get('archeMetaFetchHeader') => RepoResourceInterface::META_RESOURCE,
                'Accept'                                   => 'application/n-triples',
            ];
            try {
                $resp = self::$client->send(new Request('GET', $searchUrl, $headers));
                if ($resp->getStatusCode() === 200) {
                    $meta     = new Dataset();
                    $meta->add($parser->parseStream(StreamWrapper::getResource($resp->getBody())));
                    $matchSbj = DE::getSubject($meta, new QT(null, DF::namedNode($this->config->get('archeSearchMatchProp'))));
                    if ($matchSbj !== null) {
                        $meta->deleteExcept(new QT($matchSbj));
                        $realUrl = DE::getSubjectValue($meta);
                        Logger::info("\t\tthumbnail pointing to the resource found");
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
                    
                } catch (RdfIoException $e) {
                    
                }
            }

            $hashProp    = DF::namedNode($this->config->get('archeHashProp'));
            $modDateProp = DF::namedNode($this->config->get('archeModDateProp'));
            $mimeProp    = DF::namedNode($this->config->get('archeMimeProp'));
            $sizeProp    = DF::namedNode($this->config->get('archeSizeProp'));
            $this->meta  = new ResourceMeta([
                'url'       => $this->url,
                'checkDate' => new DateTime(),
                'repoHash'  => (string) DE::getObjectValue($meta, new QT(null, $hashProp)) ?? DE::getObjectValue($meta, new QT(null, $modDateProp)),
                'mime'      => (string) DE::getObjectValue($meta, new QT(null, $mimeProp)),
                'sizeMb'    => round((int) DE::getObjectValue($meta, new QT(null, $sizeProp)) / 1024 / 1024),
                'realUrl'   => $realUrl,
            ]);

            if (empty($oldMeta->checkDate)) {
                $query = $this->pdo->prepare("
                    INSERT INTO resources (check_date, repo_hash, mime, size_mb, real_url, url)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
            } else {
                $query = $this->pdo->prepare("
                    UPDATE resources 
                    SET check_date = ?, repo_hash = ?, mime = ?, size_mb = ?, real_url = ?
                    WHERE url = ?
                ");
            }
            $param = [
                $this->meta->checkDate->format('Y-m-d H:i:s'),
                $this->meta->repoHash,
                $this->meta->mime,
                $this->meta->sizeMb,
                $this->meta->realUrl,
                $this->url,
            ];
            $query->execute($param);
        } else {
            Logger::info("\tlocal metadata valid ($diff)");
        }
        Logger::info("\t" . json_encode($this->meta));

        // if metadata suggest resource has changed, delete local cache
        $dir = dirname($this->getFilePath());
        if ($oldMeta->repoHash !== $this->meta->repoHash && is_dir($dir)) {
            Logger::info("\tresource has changed - removing local cache");
            foreach (scandir($dir) as $i) {
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
                real_url text not null
            )
        ");
    }
}
