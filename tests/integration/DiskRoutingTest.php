<?php

/*
 * This file is part of fof/cloud-assets.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\CloudAssets\Tests\integration;

use Flarum\Extend;
use Flarum\Foundation\Paths;
use Flarum\Http\UrlGenerator;
use Flarum\Testing\integration\TestCase;
use FoF\CloudAssets\Config\Credentials;
use FoF\CloudAssets\Config\R2Config;
use FoF\CloudAssets\Extend\CloudAssets;
use FoF\CloudAssets\Filesystem\CloudDisk;
use FoF\CloudAssets\Filesystem\ListingCache;
use FoF\CloudAssets\Filesystem\PrivateDisk;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Filesystem\FilesystemAdapter;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Which disk ends up where.
 *
 * No bucket is contacted: building a disk only constructs a client, so the
 * routing can be checked against made-up credentials. What the bucket does
 * with a request is the provider's business; what this package decides is
 * which bucket — or filesystem — a disk is pointed at, and that is what these
 * cover.
 */
class DiskRoutingTest extends TestCase
{
    /**
     * A disk rooted under `storage/`, standing in for the ones core and its
     * extensions declare there — GDPR exports, private attachments, images
     * posted in private channels. Declared here rather than depending on those
     * packages being installed, since what is being tested is the rooting, not
     * whose disk it is.
     */
    private function declarePrivateDisk(): void
    {
        $this->extend(
            (new Extend\Filesystem())->disk('test-private', function (Paths $paths, UrlGenerator $url) {
                return ['root' => $paths->storage.'/test-private'];
            })
        );
    }

    private function withConfig(?string $privateBucket, string ...$keepLocal): void
    {
        $this->declarePrivateDisk();

        $extender = new CloudAssets(new R2Config(
            accountId: 'test-account',
            bucket: 'public-bucket',
            credentials: Credentials::keyPair('test-key', 'test-secret'),
            privateBucket: $privateBucket,
            url: 'https://cdn.example.test',
        ));

        if ($keepLocal !== []) {
            $extender->keepLocal(...$keepLocal);
        }

        $this->extend($extender);
    }

    private function disk(string $name): object
    {
        return $this->app()->getContainer()->make(Factory::class)->disk($name);
    }

    #[Test]
    public function public_disks_go_to_the_public_bucket()
    {
        $this->withConfig('private-bucket');

        // The assets disk is the one a rebuild writes file by file, so it is
        // the only one that batches its existence checks.
        $this->assertInstanceOf(ListingCache::class, $this->disk('flarum-assets'));
        $this->assertInstanceOf(CloudDisk::class, $this->disk('flarum-avatars'));

        $this->assertSame(
            'https://cdn.example.test/assets/probe.png',
            $this->disk('flarum-assets')->url('probe.png')
        );
    }

    #[Test]
    public function a_storage_rooted_disk_goes_to_the_private_bucket()
    {
        $this->withConfig('private-bucket');

        $this->assertInstanceOf(PrivateDisk::class, $this->disk('test-private'));
    }

    #[Test]
    public function a_private_disk_refuses_to_produce_a_url()
    {
        $this->withConfig('private-bucket');

        $this->expectException(RuntimeException::class);

        $this->disk('test-private')->url('export-1.zip');
    }

    #[Test]
    public function private_disks_stay_local_when_no_private_bucket_is_named()
    {
        // The single-instance forum offloading its assets to a CDN and nothing
        // else. Putting these in the public bucket instead would publish them.
        $this->withConfig(null);

        $this->assertInstanceOf(FilesystemAdapter::class, $this->disk('test-private'));
        $this->assertNotInstanceOf(CloudDisk::class, $this->disk('test-private'));

        // The public disks still go to the bucket.
        $this->assertInstanceOf(ListingCache::class, $this->disk('flarum-assets'));
    }

    #[Test]
    public function a_disk_can_be_kept_on_the_filesystem_by_name()
    {
        $this->withConfig('private-bucket', 'flarum-avatars');

        $this->assertInstanceOf(FilesystemAdapter::class, $this->disk('flarum-avatars'));
        $this->assertNotInstanceOf(CloudDisk::class, $this->disk('flarum-avatars'));

        $this->assertInstanceOf(ListingCache::class, $this->disk('flarum-assets'));
    }
}
