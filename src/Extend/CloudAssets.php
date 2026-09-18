<?php

/*
 * This file is part of fof/cloud-assets.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\CloudAssets\Extend;

use Flarum\Extend\Console;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extend\Filesystem;
use Flarum\Extend\Frontend;
use Flarum\Extend\Locales;
use Flarum\Extension\Extension;
use Flarum\Frontend\Compiler\VersionerInterface;
use FoF\CloudAssets\Console\CopyAssetsCommand;
use FoF\CloudAssets\Console\RestoreAssetsCommand;
use FoF\CloudAssets\Console\SecurePrivateFilesCommand;
use FoF\CloudAssets\Content\AdminPayload;
use FoF\CloudAssets\Driver\CloudDriver;
use FoF\CloudAssets\Config\StorageConfig;
use FoF\CloudAssets\Filesystem\BatchedVersioner;
use Illuminate\Contracts\Container\Container;

/**
 * Relocates Flarum's disks onto S3-compatible cloud storage.
 *
 * Applied from the site's own `extend.php`:
 *
 *     new FoF\CloudAssets\Extend\CloudAssets(
 *         new FoF\CloudAssets\Config\R2Config(
 *             accountId: $_ENV['R2_ACCOUNT_ID'],
 *             bucket: 'forum-public',
 *             privateBucket: 'forum-private',
 *             key: $_ENV['R2_ACCESS_KEY_ID'],
 *             secret: $_ENV['R2_SECRET_ACCESS_KEY'],
 *             url: 'https://cdn.example.com',
 *         )
 *     ),
 *
 * The values are passed in rather than read from the environment here, so the
 * deployment stays in charge of where its own secrets come from.
 *
 * Every registered disk moves, including those declared by extensions — which
 * is the point on a multi-instance forum, where a file written by one container
 * has to be readable by the others. Extensions register disks at boot and
 * cannot be enumerated ahead of time, so this cannot be a list of names in
 * config.php: the driver is installed for every disk, and disks are taken out
 * of it by exception with {@see keepLocal()}.
 */
class CloudAssets implements ExtenderInterface
{
    /**
     * @var list<string>
     */
    private array $keepLocal = [];

    public function __construct(
        private StorageConfig $config
    ) {
    }

    /**
     * Leave a disk on the local filesystem.
     *
     * For a disk whose files are only ever meaningful to the instance that
     * wrote them — a scratch area, or something an extension streams straight
     * back out — where a round trip to the bucket buys nothing.
     */
    public function keepLocal(string ...$disks): self
    {
        foreach ($disks as $disk) {
            $this->keepLocal[] = $disk;
        }

        return $this;
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        // A config object exists only if it was constructed, and its
        // constructor refuses anything incomplete — so there is no
        // half-configured state to check for here.
        $container->instance(StorageConfig::class, $this->config);

        $container->when(CloudDriver::class)
            ->needs('$keepLocal')
            ->give($this->keepLocal);

        // A rebuild writes each compiled file as it finishes it, and against a
        // bucket every one of those is a round trip the next write waits for —
        // 2.7s of a 4.1s admin rebuild, none of it bandwidth. The compilers
        // offer nothing to batch against, but both paths that rebuild a
        // frontend already mark where one starts and ends, by deferring the
        // versioner's writes for its duration. Uploads ride in that window.
        $container->extend(VersionerInterface::class, function (VersionerInterface $versioner, Container $container) {
            return new BatchedVersioner($versioner, $container);
        });

        // Registered against `local` as well as `s3`. Core resolves a disk's
        // driver from `disk_driver.<name>`, defaulting to `local` — so
        // claiming `local` is what reaches a disk nobody has written a
        // config.php entry for, which is every disk an extension declares.
        // `s3` is registered too so an explicit `disk_driver` entry still
        // resolves here rather than being ignored.
        (new Filesystem())
            ->driver('s3', CloudDriver::class)
            ->driver('local', CloudDriver::class)
            ->extend($container, $extension);

        (new Console())
            ->command(CopyAssetsCommand::class)
            ->command(RestoreAssetsCommand::class)
            ->command(SecurePrivateFilesCommand::class)
            ->extend($container, $extension);

        // Which storage a forum is using is otherwise invisible in the admin
        // panel — a forum serving from a bucket looks identical to one that is
        // not, until something is wrong with it.
        //
        // The paths are absolute, so this works from a library: neither
        // extender needs an Extension instance, and there is nothing for the
        // extension manager to enable.
        (new Frontend('admin'))
            ->js(__DIR__.'/../../js/dist/admin.js')
            ->content(AdminPayload::class)
            ->extend($container, $extension);

        (new Locales(__DIR__.'/../../locale'))
            ->extend($container, $extension);
    }
}
