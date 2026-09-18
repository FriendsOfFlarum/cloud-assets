<?php

/*
 * This file is part of fof/cloud-assets.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\CloudAssets\Filesystem;

use Illuminate\Filesystem\AwsS3V3Adapter;

/**
 * A bucket-backed disk that stamps each file with a suitable `Cache-Control`.
 *
 * Objects carry their own cache headers, set once when written — unlike files
 * served from disk, there is no web server in front of them adding headers per
 * request. So one value cannot serve every file: the compiled JavaScript is
 * content-addressed and can be cached for a year, while a sitemap regenerated
 * at a stable URL must not be cached at all.
 *
 * Applied here rather than in the adapter's constructor options because those
 * are per disk, and the decision is per file. Flysystem treats the constructor
 * options as defaults for each write's config, so a value set here wins.
 */
class CloudDisk extends AwsS3V3Adapter
{
    private ?CacheControl $cacheControl = null;

    private ?WriteBatch $batch = null;

    private ?MimeTypes $mimeTypes = null;

    public function setCacheControl(CacheControl $cacheControl): void
    {
        $this->cacheControl = $cacheControl;
    }

    /**
     * Where writes go while a batch is open, so a rebuild's files travel
     * together instead of one round trip at a time.
     */
    public function setWriteBatch(WriteBatch $batch, MimeTypes $mimeTypes): void
    {
        $this->batch = $batch;
        $this->mimeTypes = $mimeTypes;
    }

    public function put($path, $contents, $options = []): bool
    {
        $options = $this->withCacheControl($path, $options);

        if ($this->batch !== null && $this->batch->isOpen() && is_array($options) && is_string($contents)) {
            // Queued rather than sent. The adapter is bypassed, so the two
            // things it would have decided — the content type, and the
            // visibility-derived ACL — are settled here instead; everything
            // else about the request is the same PutObject it would have made.
            $this->batch->add($path, $contents, $this->batchOptions($path, $options));

            return true;
        }

        return parent::put($path, $contents, $options);
    }

    /**
     * The PutObject arguments Flysystem's adapter would have assembled.
     *
     * Only `ContentType` has to be recovered: the adapter detects it from the
     * filename, and without it the bucket stores `binary/octet-stream` and
     * serves a stylesheet a browser will refuse to apply. The write options
     * carried on the disk — an ACL where the provider honours one — are
     * already in `$options`, and `Cache-Control` was set per file before this.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function batchOptions(string $path, array $options): array
    {
        if (! isset($options['ContentType']) && $this->mimeTypes !== null) {
            $options['ContentType'] = $this->mimeTypes->for($path);
        }

        return $options;
    }

    public function writeStream($path, $resource, array $options = []): bool
    {
        return parent::writeStream($path, $resource, $this->withCacheControl($path, $options));
    }

    /**
     * @param array<string, mixed>|string $options
     * @return array<string, mixed>|string
     */
    protected function withCacheControl(string $path, $options)
    {
        // A caller that set its own wins. A string is Flysystem's visibility
        // shorthand rather than an option array, and has nowhere to put this.
        if ($this->cacheControl === null || ! is_array($options) || isset($options['CacheControl'])) {
            return $options;
        }

        $options['CacheControl'] = $this->cacheControl->for($path);

        return $options;
    }
}
