# Repo-thumbnails

![Build status](https://github.com/acdh-oeaw/arche-thumbnails/workflows/deploy/badge.svg?branch=master)
[![Coverage Status](https://coveralls.io/repos/github/acdh-oeaw/arche-thumbnails/badge.svg?branch=master)](https://coveralls.io/github/acdh-oeaw/arche-thumbnails?branch=master)
[![License](https://poser.pugx.org/acdh-oeaw/arche-thumbnails/license)](https://packagist.org/packages/acdh-oeaw/arche-thumbnails)


An ARCHE dissemination service providing thumbnails for resources (so they can be nicely displayed in the GUI).

For images it simply provides thumbnails and for another resources it tries to do its best either by finding a connected image (e.g. with `acdh:hasTitleImage` metadata link) or by rendering a content fragment (for text resources) or by providing an icon based on the resource type.

To speed things up it caches provided results.

It can be queried as `{deploymentUrl}/{archeID}?{parameters}`, where

* `{archeId}` is either a full ARCHE resource id (e.g. `https://id.acdh.oeaw.ac.at/Troesmis`) or an ARCHE resource id with the ACDH id namespace skipped (e.g. `Troesmis` as the ACDH id namespace is `https://id.acdh.oeaw.ac.at/`). In both cases the **value should be properly URL encoded**.
* supported parameters are:
    * `width`, `height` - width and height of a thumbnail (in pixels)

## Extending

Prepare a new class implementing `acdhOeaw\repo\thumbnails\handler\HandlerInterface` and register it by addding `mimeHandlers[]='yourClassName'` to the `config.ini`.

For example implementations look into the `src\acdhOeaw\repo\thumbnails\handler` folder.
