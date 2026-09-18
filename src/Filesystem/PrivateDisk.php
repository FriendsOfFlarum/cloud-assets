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

use RuntimeException;

/**
 * A disk in the private bucket, which cannot produce a public address.
 *
 * The files here are GDPR exports, private attachments and images posted in
 * private channels. They reach a user by being streamed through PHP once the
 * request has been authorised — `readStream()` in flarum/gdpr, the downloader
 * in fof/upload, a controller in ramon/chat — and never by URL. Nothing in
 * core or any installed extension asks a private disk for one.
 *
 * So `url()` has no correct answer here, and both of the answers it would
 * otherwise give are harmful. Laravel's S3 adapter falls back to
 * `getObjectUrl()` when no `url` is configured, handing out a bucket endpoint
 * address for a file that is supposed to have none; its local adapter falls
 * back to `/storage/<path>`, a Laravel convention that does not exist in
 * Flarum and which returns the same string for every private disk.
 *
 * Neither is reachable today — the bucket has no public hostname, and
 * `/storage` is outside the document root — so what they really produce is a
 * URL that looks usable, gets stored or logged or put in an email, and becomes
 * a leak the day someone points a hostname at the wrong bucket. Throwing keeps
 * that mistake in the open, where a caller that needs a link has to ask for a
 * signed one deliberately.
 */
class PrivateDisk extends CloudDisk
{
    /**
     * @param string $path
     */
    public function url($path): string
    {
        throw new RuntimeException(sprintf(
            'Cannot make a public URL for [%s]: it is on a private disk, whose files are served by streaming them through Flarum after an access check. Read the file with get() or readStream(), or generate a signed, expiring link with temporaryUrl().',
            $path
        ));
    }
}
