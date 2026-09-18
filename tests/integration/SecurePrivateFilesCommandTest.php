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

use Flarum\Testing\integration\ConsoleTestCase;
use FoF\CloudAssets\Config\AwsConfig;
use FoF\CloudAssets\Config\Credentials;
use FoF\CloudAssets\Extend\CloudAssets;
use PHPUnit\Framework\Attributes\Test;

/**
 * `cloud-assets:secure` moves private files out of a bucket that serves them
 * publicly. Where one bucket holds both trees there is nowhere to move them
 * to — `storage/` is already the right place — so the command has to recognise
 * that arrangement rather than treating every key under the private prefix as
 * misplaced.
 */
class SecurePrivateFilesCommandTest extends ConsoleTestCase
{
    private function withOneBucket(): void
    {
        $this->extend(new CloudAssets(new AwsConfig(
            bucket: 'forum',
            region: 'eu-west-1',
            credentials: Credentials::keyPair('test-key', 'test-secret'),
            // The same bucket for both: private files live under `storage/`,
            // walled off by a bucket policy rather than by being elsewhere.
            privateBucket: 'forum',
        )));
    }

    #[Test]
    public function one_bucket_has_nothing_to_move()
    {
        $this->withOneBucket();

        $output = $this->runCommand(['command' => 'cloud-assets:secure']);

        // Nothing is misplaced, so nothing is offered for moving. Reporting a
        // move here would be wrong twice over: the destination is the source,
        // so `--force` would copy each file onto itself and then delete it.
        $this->assertStringNotContainsString('would move', $output);
        $this->assertStringContainsString('one bucket', $output);
    }

    #[Test]
    public function one_bucket_says_where_the_protection_actually_comes_from()
    {
        $this->withOneBucket();

        $output = $this->runCommand(['command' => 'cloud-assets:secure']);

        // "Nothing to do" on its own reads as "you are safe". The operator has
        // to know the guarantee rests on a bucket policy this command has not
        // seen and cannot check.
        $this->assertStringContainsString('policy', $output);
    }
}
