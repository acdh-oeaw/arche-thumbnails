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

/**
 *
 * @author zozlak
 */
interface ResourceInterface {

    /**
     * Returns resource's basic metadata (URL, mime type, size,
     * modification date, etc.)
     *
     * @return \acdhOeaw\arche\thumbnails\ResourceMeta
     */
    public function getMeta(): ResourceMeta;

    /**
     * Returns path to the file storing a repository resource payload.
     * @return string
     */
    public function getResourcePath(): string;

    /**
     * Returns a configuration property value stored in the config.ini file
     *
     * @param string $property configuration property name
     */
    public function getConfig(string $property);

    /**
     * List files cached for a resource.
     * 
     * Returnes 2D array with first dimension indicating available widths and
     * second dimension listing heights available for a given width. Both dimensions
     * are encoded as strings left-padded with zeros up to 5 digits length.
     *
     * @param int $order \SCANDIR_SORT_ASCENDING or SCANDIR_SORT_DESCENDING
     * @return array
     */
    public function getCachedFiles(int $order): array;
}

