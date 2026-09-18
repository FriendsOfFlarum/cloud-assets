<?php

/*
 * This file is part of fof/cloud-assets.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\CloudAssets\Tests\unit;

use FoF\CloudAssets\Filesystem\CacheControl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CacheControlTest extends TestCase
{
    #[Test]
    public function content_addressed_bundles_are_immutable_for_a_year(): void
    {
        $cacheControl = new CacheControl();

        // Safe only because Flarum requests these as `forum.js?v=<hash>`, so a
        // changed file is always a changed URL.
        $this->assertSame('public, max-age=31536000, immutable', $cacheControl->for('forum.js'));
        $this->assertSame('public, max-age=31536000, immutable', $cacheControl->for('forum.css'));
    }

    #[Test]
    public function documents_and_feeds_are_not_cached(): void
    {
        $cacheControl = new CacheControl();

        // `no-cache` rather than `max-age=0`: a proxy may serve a stale copy of
        // the latter, and a sitemap regenerated at a stable URL is exactly
        // where freshness matters.
        $this->assertSame('no-cache', $cacheControl->for('sitemaps/sitemap.xml'));
        $this->assertSame('no-cache', $cacheControl->for('manifest.json'));
    }

    #[Test]
    public function media_is_cached_for_a_month_but_not_marked_immutable(): void
    {
        $cacheControl = new CacheControl();

        $this->assertSame('public, max-age=2592000', $cacheControl->for('avatars/x.png'));
        $this->assertSame('public, max-age=2592000', $cacheControl->for('fonts/fa-solid-900.woff2'));
    }

    #[Test]
    public function an_unlisted_extension_falls_back(): void
    {
        $cacheControl = new CacheControl();

        $this->assertSame('public, max-age=2592000', $cacheControl->for('something.unheard-of'));
        $this->assertSame('public, max-age=2592000', $cacheControl->for('no-extension-at-all'));
    }

    #[Test]
    public function an_override_replaces_a_tier(): void
    {
        $cacheControl = new CacheControl(['pdf' => 604800]);

        $this->assertSame('public, max-age=604800', $cacheControl->for('manual.pdf'));
        $this->assertSame('public, max-age=31536000, immutable', $cacheControl->for('forum.js'));
    }

    #[Test]
    public function an_empty_key_replaces_the_fallback_only(): void
    {
        $cacheControl = new CacheControl(['' => 3600]);

        $this->assertSame('public, max-age=3600', $cacheControl->for('something.unheard-of'));
        // Listed extensions keep their own tier.
        $this->assertSame('no-cache', $cacheControl->for('sitemap.xml'));
    }

    #[Test]
    public function the_extension_decides_regardless_of_case(): void
    {
        $cacheControl = new CacheControl();

        $this->assertSame('public, max-age=31536000, immutable', $cacheControl->for('FORUM.JS'));
    }
}
