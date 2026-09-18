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

use FoF\CloudAssets\Filesystem\PrivateDisk;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PrivateDiskTest extends TestCase
{
    #[Test]
    public function a_private_disk_will_not_produce_a_public_url(): void
    {
        /** @var PrivateDisk $disk */
        $disk = (new \ReflectionClass(PrivateDisk::class))->newInstanceWithoutConstructor();

        // Laravel's S3 adapter would fall back to a bucket endpoint address,
        // and its local adapter to `/storage/<path>` — both plausible-looking
        // and both wrong. A caller that needs a link has to ask for a signed
        // one deliberately.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/private disk/');

        $disk->url('gdpr-exports/export-1.zip');
    }
}
