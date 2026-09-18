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

/**
 * How long each kind of file may be cached.
 *
 * Objects in a bucket carry their own `Cache-Control`, set once when written —
 * there is no server config in front of them to add it per request. So the
 * header a file is stored with is the only one it will ever have, and getting
 * it wrong is expensive in both directions: too short and every viewer
 * re-fetches a megabyte of JavaScript that cannot have changed, too long and a
 * file that is not content-addressed goes stale in caches for a year.
 *
 * The tiers mirror the Apache `mod_expires` configuration these deployments
 * already use when serving assets from disk, so relocating to a bucket does
 * not quietly change caching behaviour.
 *
 * A year on JS and CSS is only safe because Flarum content-addresses them:
 * bundles are requested as `forum.js?v=<hash of the compiled output>`, and the
 * font URLs inside a stylesheet carry a revision of the font file itself. A
 * changed file is always a changed URL.
 */
class CacheControl
{
    public const YEAR = 31536000;
    public const MONTH = 2592000;
    public const WEEK = 604800;
    public const HOUR = 3600;
    public const NONE = 0;

    /**
     * Seconds by file extension. Extension rather than detected MIME type
     * because the two disagree on exactly the files that matter — libmagic
     * reports CSS as `text/troff` and JS as `text/x-c`, which is why the
     * driver has to override the MIME detector at all.
     *
     * @var array<string, int>
     */
    public const TIERS = [
        // Content-addressed by Flarum, so a change is always a new URL.
        'css' => self::YEAR,
        'js' => self::YEAR,
        'mjs' => self::YEAR,
        'wasm' => self::YEAR,

        // Fonts change only on an upgrade, and their URLs carry a revision of
        // the font file.
        'woff2' => self::MONTH,
        'woff' => self::MONTH,
        'ttf' => self::MONTH,
        'otf' => self::MONTH,
        'eot' => self::MONTH,
        'ttc' => self::MONTH,

        // Uploads and avatars: the filename is generated per file and never
        // reused, so the content behind a URL does not change.
        'jpg' => self::MONTH,
        'jpeg' => self::MONTH,
        'png' => self::MONTH,
        'gif' => self::MONTH,
        'webp' => self::MONTH,
        'avif' => self::MONTH,
        'bmp' => self::MONTH,
        'svg' => self::MONTH,
        'mp4' => self::MONTH,
        'webm' => self::MONTH,
        'ogg' => self::MONTH,
        'ogv' => self::MONTH,
        'mp3' => self::MONTH,
        'pdf' => self::MONTH,

        // Icons are referenced from markup by a stable path.
        'ico' => self::WEEK,
        'cur' => self::WEEK,

        // Sourcemaps are fetched only when a developer opens the console, and
        // share the revision of the bundle they belong to.
        'map' => self::MONTH,

        // Documents and feeds are read fresh, or nearly so. A sitemap is
        // regenerated on a schedule at a stable URL, so it must not be cached
        // for a month.
        'html' => self::NONE,
        'htm' => self::NONE,
        'json' => self::NONE,
        'xml' => self::NONE,
        'txt' => self::NONE,
        'md' => self::NONE,
        'rss' => self::HOUR,
        'atom' => self::HOUR,
    ];

    /**
     * Anything not listed above. A month matches the Apache config's
     * `ExpiresDefault`, and every unlisted case so far has been a media file.
     */
    public const DEFAULT = self::MONTH;

    /**
     * @param array<string, int> $overrides extension => seconds
     */
    public function __construct(
        private readonly array $overrides = []
    ) {
    }

    /**
     * The header value for a path.
     *
     * Always a value: every file gets a tier, falling back to the default
     * where its extension names none. A file written with no `Cache-Control`
     * at all would be left to whatever the CDN in front of the bucket decides,
     * which is the situation these tiers exist to replace.
     */
    public function for(string $path): string
    {
        $seconds = $this->seconds($path);

        if ($seconds === 0) {
            // Not `max-age=0`: a proxy may still serve a stale copy of that,
            // and these are the files where freshness is the whole point.
            return 'no-cache';
        }

        // `immutable` tells a browser not to revalidate even on a manual
        // reload, which is only truthful for a content-addressed URL.
        return $seconds >= self::YEAR
            ? "public, max-age=$seconds, immutable"
            : "public, max-age=$seconds";
    }

    private function seconds(string $path): int
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // An override keyed by '' replaces the fallback for anything the tiers
        // do not name — which is what a deployment's single max-age setting
        // becomes, rather than being ignored.
        $default = $this->overrides[''] ?? self::DEFAULT;

        if ($extension === '') {
            return $default;
        }

        return $this->overrides[$extension]
            ?? self::TIERS[$extension]
            ?? $default;
    }
}
