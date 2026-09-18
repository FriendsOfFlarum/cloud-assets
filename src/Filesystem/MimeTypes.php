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

use League\MimeTypeDetection\MimeTypeDetector;

/**
 * The content type an object is stored with.
 *
 * Needed because {@see WriteBatch} sends its own PutObject rather than going
 * through Flysystem's adapter, which is what would normally work this out. An
 * object stored without one comes back as `binary/octet-stream`, and a browser
 * sent that for a stylesheet under `X-Content-Type-Options: nosniff` refuses
 * to apply it — the forum renders unstyled.
 *
 * The same detector the adapter uses is passed in, so a file written in a
 * batch and the same file written on its own are stored identically. That
 * detector is configured to treat what libmagic says about compiled assets as
 * inconclusive (CSS comes back as `text/troff`, JS as `text/x-c`), leaving the
 * filename extension to decide.
 */
class MimeTypes
{
    public function __construct(
        private readonly MimeTypeDetector $detector
    ) {
    }

    public function for(string $path): string
    {
        return $this->detector->detectMimeTypeFromPath($path) ?? 'application/octet-stream';
    }
}
