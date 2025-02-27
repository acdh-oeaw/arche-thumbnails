# ARCHE-thumbnails

[![Build status](https://github.com/acdh-oeaw/arche-thumbnails/actions/workflows/deploy.yaml/badge.svg)](https://github.com/acdh-oeaw/arche-thumbnails/actions/workflows/deploy.yaml)
[![Coverage Status](https://coveralls.io/repos/github/acdh-oeaw/arche-thumbnails/badge.svg?branch=master)](https://coveralls.io/github/acdh-oeaw/arche-thumbnails?branch=master)

An ARCHE dissemination service providing thumbnails for resources (so they can be nicely displayed in the GUI).

For images it simply provides thumbnails and for another resources it tries to do its best either by finding a connected image (e.g. with `acdh:hasTitleImage` metadata link) or by rendering a content fragment (for text resources) or by providing an icon based on the resource type.

To speed things up it caches provided results.

It can be queried as `{deploymentUrl}/?{parameters}`, where available parameters are

* `id={archeId}` (**required**) where the `archeId` is any identifier of an ARCHE resource. The **value should be properly URL encoded**.
* `width` (optional) a requested thumbnail width in pixels (if only `height` is specified, it is computed automatically to keep the aspect ratio)
* `height` (optional) a requested thumbnail height in pixels (if only `width` is specified, it is computed automatically to keep the aspect ratio)

## Extending

Prepare a new class implementing `acdhOeaw\repo\thumbnails\handler\HandlerInterface` and register it by addding `mimeHandlers[]='yourClassName'` to the `config.ini`.

For example implementations look into the `src\acdhOeaw\repo\thumbnails\handler` folder.
