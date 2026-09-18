<?php

/*
 * This file is part of fof/cloud-assets.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\CloudAssets\Content;

use Flarum\Frontend\Document;
use FoF\CloudAssets\Config\StorageConfig;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Tells the admin frontend where files are being served from.
 *
 * Worth surfacing because it is otherwise invisible: nothing in the admin panel
 * indicates that assets have moved off the filesystem, so a forum serving from
 * a bucket looks identical to one that is not — until something is wrong with
 * it, at which point the first question is which storage is in use.
 */
class AdminPayload
{
    public function __construct(
        protected StorageConfig $config
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $document->payload['cloudAssets'] = [
            'provider' => $this->config->label(),
            'bucket' => $this->config->bucket,
            // The hostname files are served from, which is what an admin
            // actually needs — a CDN in front of the bucket, or the bucket's
            // own endpoint when there is none.
            'host' => $this->host($this->config->publicDisk()['url'] ?? null),
        ];
    }

    private function host(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        return parse_url($url, PHP_URL_HOST) ?: $url;
    }
}
