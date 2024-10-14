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
use rdfInterface\DatasetNodeInterface;
use termTemplates\PredicateTemplate as PT;

/**
 * Description of ResourceMeta
 *
 * @author zozlak
 */
class ResourceMeta {

    static public function fromDatasetNode(DatasetNodeInterface $meta, object $schema): self {
        $resMeta           = new self();
        $resMeta->url      = (string) $meta->getNode();
        $resMeta->realUrl  = $meta->getObjectValue(new PT($schema->titleImage)) ?? $resMeta->url;
        $resMeta->repoHash = $meta->getObjectValue(new PT($schema->hash)) ?? '__no hash__';
        $resMeta->mime     = $meta->getObjectValue(new PT($schema->mime)) ?? '';
        $resMeta->sizeMb   = ((int) $meta->getObjectValue(new PT($schema->size))) >> 20;
        $resMeta->class    = $meta->getObjectValue(new PT($schema->class)) ?? '__no class__';
        $resMeta->modDate  = new DateTimeImmutable($meta->getObjectValue(new PT($schema->modDate)));
        return $resMeta;
    }

    static public function deserialize(string $data): self {
        return unserialize($data);
    }

    public string $url;
    public string $realUrl;
    public string $repoHash = '';
    public string $mime     = '';
    public int $sizeMb   = 0;
    public string $class    = '';
    public DateTimeImmutable $modDate;

    public function serialize(): string {
        return serialize($this);
    }
}
