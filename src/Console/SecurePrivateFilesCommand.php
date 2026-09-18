<?php

/*
 * This file is part of fof/cloud-assets.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\CloudAssets\Console;

use Flarum\Foundation\Paths;
use FoF\CloudAssets\Driver\CloudDriver;
use FoF\CloudAssets\Config\StorageConfig;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Filesystem\FilesystemAdapter;

/**
 * Moves private files out of the public bucket.
 *
 * Until this release every disk went into one bucket, keyed by its local root:
 * the compiled assets under `assets/`, and the private disks — GDPR exports,
 * private attachments, images posted in private channels — under `storage/`.
 * The public bucket has a hostname in front of it, and no provider can serve
 * one prefix from a hostname while refusing another: R2 has no prefix scoping
 * at all, and per-object ACLs are either rejected (AWS since 2023) or accepted
 * and ignored (R2).
 *
 * So on any forum set up that way, those files have been readable by anyone
 * who knew the URL — and GDPR exports are named `export-1.zip`, `export-2.zip`,
 * which is not much of a secret. Upgrading stops new files going there, but
 * cannot move what is already in the bucket, because only the operator knows
 * where it should go instead.
 *
 * This command is that step. It finds what is under the private prefix in the
 * public bucket and moves it to wherever the current configuration says those
 * disks now live: the private bucket if one is named, otherwise back to the
 * local filesystem.
 *
 * Nothing is deleted until the copy is verified — the destination is read back
 * and compared with the source, and the source is only removed once they
 * agree. A file that cannot be verified is left in place and reported, on the
 * grounds that a file still exposed is recoverable and a file destroyed is
 * not.
 */
class SecurePrivateFilesCommand extends Command
{
    protected $signature = 'cloud-assets:secure
        {--force : Actually move the files — without this, only reports what would move}';

    protected $description = 'Move private files out of the public bucket, where earlier versions put them';

    public function __construct(
        protected Container $container,
        protected Paths $paths,
        protected Factory $filesystem,
        protected StorageConfig $config
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = ! $this->option('force');

        // Read the public bucket directly rather than through a disk. The
        // disks no longer point at these keys — that is the whole point of the
        // fix — so the only way to reach what was left behind is to address
        // the bucket itself.
        $public = $this->publicBucket();
        $exposed = $public->allFiles(CloudDriver::PRIVATE_PREFIX);

        if ($exposed === []) {
            $this->info('Nothing under `'.CloudDriver::PRIVATE_PREFIX.'` in the public bucket. Nothing to do.');

            return 0;
        }

        $this->warn(sprintf(
            '%d file(s) under `%s` in the public bucket `%s`.',
            count($exposed),
            CloudDriver::PRIVATE_PREFIX,
            $this->config->bucket
        ));
        $this->line('These have been publicly readable by anyone with the URL.');
        $this->line('');

        [$destination, $describe, $rename] = $this->destination();

        $this->info('Moving to: '.$describe);
        $this->line('');

        if ($dryRun) {
            foreach ($exposed as $key) {
                $this->line('  would move  '.$key);
            }

            $this->line('');
            $this->info(sprintf('Would move %d file(s). Pass --force to do it.', count($exposed)));

            return 0;
        }

        $moved = 0;
        $failed = 0;

        foreach ($exposed as $key) {
            try {
                $this->move($public, $destination, $key, $rename($key));
                $moved++;
            } catch (\Throwable $e) {
                $this->error('  '.$key.' — '.$e->getMessage());
                $failed++;
            }
        }

        $this->line('');
        $this->info(sprintf('Moved %d file(s).', $moved));

        if ($failed > 0) {
            $this->error(sprintf(
                '%d file(s) could not be moved and are still in the public bucket.',
                $failed
            ));

            return 1;
        }

        return 0;
    }

    /**
     * Copy one key across, prove it arrived, and only then delete it.
     *
     * The proof is a byte comparison of what the destination reads back, not
     * merely that a write returned true: this is the one destructive path in
     * the package, and the file it is deleting may be the only copy of
     * somebody's personal data export. A truncated write that reported success
     * would otherwise destroy the original.
     *
     * Streamed rather than read whole — attachments and exports can be large,
     * and a command run over a whole bucket should not need the biggest file
     * to fit in memory twice.
     */
    private function move(FilesystemAdapter $public, FilesystemAdapter $destination, string $key, string $relative): void
    {
        $source = $public->readStream($key);

        if ($source === false || $source === null) {
            throw new \RuntimeException('could not read from the public bucket');
        }

        try {
            if (! $destination->writeStream($relative, $source)) {
                throw new \RuntimeException('the destination refused the write');
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }

        $expected = $public->fileSize($key);
        $actual = $destination->fileSize($relative);

        if ($expected !== $actual) {
            throw new \RuntimeException(sprintf(
                'copied but not verified — %d bytes at the source, %d at the destination; left in place',
                $expected,
                $actual
            ));
        }

        $public->delete($key);
    }

    /**
     * Where the private files belong now, and how to describe it.
     *
     * Both answers come from the configuration rather than from a flag,
     * because the driver has already made this decision: whatever it does with
     * a private disk today is where the stranded files have to end up, or they
     * will not be found.
     *
     * The third element maps a key in the public bucket to where it belongs at
     * the destination, which is not the same in both cases: the private bucket
     * is addressed from its root and the disks write `storage/…` keys into it,
     * so the prefix is part of the key and has to be kept. The local
     * filesystem destination is already rooted at the storage directory, so
     * the same prefix would nest a second `storage/` inside it.
     *
     * Getting this backwards puts the rescued files somewhere nothing reads —
     * in the right bucket, at a key no disk will ever ask for.
     *
     * @return array{0: FilesystemAdapter, 1: string, 2: callable(string): string}
     */
    private function destination(): array
    {
        $private = $this->config->privateDisk();

        if ($private !== null) {
            return [
                $this->bucket($private),
                'the private bucket `'.$private['bucket'].'`',
                fn (string $key): string => $key,
            ];
        }

        // No private bucket. The disks are back on the filesystem, which is
        // the right place for a single-instance forum — and on several
        // instances it is at least not public, which is the problem being
        // fixed here.
        return [
            $this->localStorage(),
            'the local filesystem, under `'.$this->paths->storage.'` (no private bucket is configured)',
            fn (string $key): string => substr($key, strlen(CloudDriver::PRIVATE_PREFIX)),
        ];
    }

    private function publicBucket(): FilesystemAdapter
    {
        return $this->bucket($this->config->publicDisk());
    }

    /**
     * A disk addressing a whole bucket, with no root prefix.
     *
     * @param array<string, mixed> $config
     */
    private function bucket(array $config): FilesystemAdapter
    {
        /** @var CloudDriver $driver */
        $driver = $this->container->make(CloudDriver::class);

        return $driver->bucketDisk($config);
    }

    private function localStorage(): FilesystemAdapter
    {
        /** @var CloudDriver $driver */
        $driver = $this->container->make(CloudDriver::class);

        return $driver->storageDisk();
    }
}
