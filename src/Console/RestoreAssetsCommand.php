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
use Flarum\Http\UrlGenerator;
use FoF\CloudAssets\Driver\CloudDriver;
use FoF\CloudAssets\Config\StorageConfig;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Filesystem\FilesystemAdapter;

/**
 * Brings the files back down to the local filesystem.
 *
 * The counterpart to {@see CopyAssetsCommand}, for a forum leaving cloud
 * storage: scaling back to one instance, moving provider, or simply deciding
 * the bucket was not worth it. Without this, adopting the library is a one-way
 * door — the bucket accumulates avatars and attachments that exist nowhere
 * else, and the only way back is a manual sync.
 *
 * Run it while the bucket is still configured, because that is what tells the
 * command where the files are. Once everything is down, take the configuration
 * out and the disks go back to the filesystem on their own:
 *
 *     php flarum cloud-assets:restore --force
 *     # remove FLARUM_CLOUD_ASSETS_* from the environment
 *     php flarum cache:clear
 *
 * The compiled bundles are deliberately not downloaded — `cache:clear` rebuilds
 * them locally, faster than fetching them and with no chance of restoring a
 * stale one. Everything else is copied, on the same reasoning as the upload
 * direction: a file nobody can account for might be somebody's avatar, and the
 * cost of guessing wrong is not symmetrical.
 *
 * Nothing in the bucket is deleted. A restore that is also a teardown leaves no
 * way back if it turns out to have been a mistake, and deleting a bucket is
 * something the operator can do deliberately once they are satisfied the files
 * are down.
 */
class RestoreAssetsCommand extends Command
{
    protected $signature = 'cloud-assets:restore
        {--disk=* : Disks to restore; defaults to every registered disk}
        {--force : Actually download — without this, only reports what would be restored}
        {--overwrite : Replace local files that already exist}';

    protected $description = 'Download files from the bucket back onto the local filesystem';

    public function __construct(
        protected Container $container,
        protected Paths $paths,
        protected StorageConfig $config
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = ! $this->option('force');
        $overwrite = (bool) $this->option('overwrite');

        if ($dryRun) {
            $this->info('Dry run — pass --force to download.');
            $this->line('');
        }

        $driver = $this->container->make(CloudDriver::class);
        $roots = $this->roots();
        $disks = $this->option('disk') ?: array_keys($roots);

        // Every disk's key prefix, so a file belonging to a nested disk can be
        // left to it. `flarum-assets` is rooted at `public/assets` and
        // `flarum-avatars` inside it at `public/assets/avatars`, so listing the
        // first sweeps up the second — and restoring an avatar as part of the
        // assets disk writes it to the right path by luck rather than by
        // design, then writes it again on the avatars disk's own pass.
        //
        // Answered against all registered disks rather than the ones selected,
        // so `--disk=flarum-assets` does not quietly restore the avatars too.
        $prefixes = [];

        foreach ($roots as $disk => $diskRoot) {
            if (($diskPrefix = $driver->cloudPath($diskRoot)) !== null) {
                $prefixes[$disk] = rtrim($diskPrefix, '/');
            }
        }

        // The compiled bundles and their sourcemaps: `cache:clear` writes these
        // again from source, so downloading them is a request each for a file
        // that is about to be replaced — and restoring a stale one is worse
        // than not restoring it at all.
        $regenerable = $this->regenerable();

        $totalRestored = 0;
        $totalSkipped = 0;
        $failed = 0;

        foreach ($disks as $name) {
            $localRoot = $roots[$name] ?? null;

            if ($localRoot === null) {
                $this->error("Disk [$name] is not registered.");
                $failed++;

                continue;
            }

            $prefix = $driver->cloudPath($localRoot);

            if ($prefix === null) {
                // Rooted outside public/ and storage/, so it was never in the
                // bucket to begin with.
                continue;
            }

            $source = $this->sourceFor($driver, $localRoot);

            if ($source === null) {
                // A private disk with no private bucket configured is already
                // local — there is nothing in a bucket to bring down.
                continue;
            }

            $restored = 0;
            $skipped = 0;

            foreach ($source->allFiles($prefix) as $key) {
                if ($this->belongsToAnotherDisk($name, $prefixes, $key)) {
                    continue;
                }

                $relative = substr($key, strlen($prefix));
                $relative = ltrim($relative, '/');

                if ($relative === '' || isset($regenerable[$relative])) {
                    $skipped++;

                    continue;
                }

                $target = $localRoot.'/'.$relative;

                if (! $overwrite && file_exists($target)) {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $restored++;

                    continue;
                }

                try {
                    $this->download($source, $key, $target);
                    $restored++;
                } catch (\Throwable $e) {
                    $this->error("  $name: $relative — {$e->getMessage()}");
                    $failed++;
                }
            }

            if ($restored > 0 || $skipped > 0) {
                $this->info(sprintf(
                    '%-18s %s%d file(s)%s',
                    $name,
                    $dryRun ? 'would restore ' : 'restored ',
                    $restored,
                    $skipped > 0 ? ", skipped $skipped already local or rebuilt by cache:clear" : ''
                ));
            }

            $totalRestored += $restored;
            $totalSkipped += $skipped;
        }

        $this->line('');
        $this->info(sprintf(
            '%s %d file(s); skipped %d.',
            $dryRun ? 'Would restore' : 'Restored',
            $totalRestored,
            $totalSkipped
        ));

        if (! $dryRun && $totalRestored > 0) {
            $this->line('');
            $this->info('The files are now local. To stop using the bucket, remove the');
            $this->info('configuration from your environment and run `php flarum cache:clear`');
            $this->info('to rebuild the compiled assets on disk.');
            $this->line('');
            $this->comment('Nothing was deleted from the bucket.');
        }

        if ($failed > 0) {
            $this->error("$failed file(s) could not be restored.");

            return 1;
        }

        return 0;
    }

    /**
     * Whether a key under this disk's prefix is really a nested disk's.
     *
     * @param array<string, string> $prefixes
     */
    private function belongsToAnotherDisk(string $disk, array $prefixes, string $key): bool
    {
        $own = $prefixes[$disk] ?? '';

        foreach ($prefixes as $other => $prefix) {
            if ($other === $disk || strlen($prefix) <= strlen($own)) {
                continue;
            }

            if (str_starts_with($key, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stream one object down to a local path.
     *
     * Written to a temporary file in the destination directory and moved into
     * place, so an interrupted download cannot leave a half-written avatar
     * looking like a real one — the same reason the upload direction verifies
     * before it deletes. The rename is atomic within a filesystem, and the
     * temporary file is alongside the target rather than in the system temp
     * directory, which may be on a different one.
     */
    private function download(FilesystemAdapter $source, string $key, string $target): void
    {
        $directory = dirname($target);

        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new \RuntimeException("could not create $directory");
        }

        $stream = $source->readStream($key);

        if ($stream === false || $stream === null) {
            throw new \RuntimeException('could not read from the bucket');
        }

        $temporary = $target.'.cloud-assets-restore';
        $handle = @fopen($temporary, 'wb');

        if ($handle === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw new \RuntimeException("could not open $temporary for writing");
        }

        try {
            if (stream_copy_to_stream($stream, $handle) === false) {
                throw new \RuntimeException('the download was interrupted');
            }
        } finally {
            fclose($handle);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! @rename($temporary, $target)) {
            @unlink($temporary);

            throw new \RuntimeException("could not move the download into place at $target");
        }
    }

    /**
     * The bucket a disk's files are in, or null if they are already local.
     *
     * A private disk follows whatever the driver decided for it: the private
     * bucket when one is configured, and otherwise nowhere — it never left the
     * filesystem.
     */
    private function sourceFor(CloudDriver $driver, string $localRoot): ?FilesystemAdapter
    {
        $storage = rtrim($this->paths->storage, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (str_starts_with($localRoot, $storage)) {
            $private = $this->config->privateDisk();

            return $private === null ? null : $driver->bucketDisk($private);
        }

        return $driver->bucketDisk($this->config->publicDisk());
    }

    /**
     * Files `cache:clear` writes again anyway, keyed by path relative to the
     * assets disk.
     *
     * Taken from the versioner rather than guessed from filenames, for the
     * same reason the upload direction does: it is the only thing that knows
     * which files a rebuild owns.
     *
     * @return array<string, true>
     */
    private function regenerable(): array
    {
        $versioner = $this->container->make(\Flarum\Frontend\Compiler\VersionerInterface::class);
        $regenerable = [];

        foreach (array_keys($versioner->allRevisions()) as $file) {
            $regenerable[$file] = true;
            $regenerable[$file.'.map'] = true;
        }

        return $regenerable;
    }

    /**
     * Every registered disk's local root, keyed by disk name.
     *
     * @return array<string, string>
     */
    private function roots(): array
    {
        $paths = $this->container->make(Paths::class);
        $url = $this->container->make(UrlGenerator::class);
        $roots = [];

        foreach ($this->container->make('flarum.filesystem.disks') as $name => $declaration) {
            $callback = is_string($declaration)
                ? [$this->container->make($declaration), '__invoke']
                : $declaration;

            $root = $callback($paths, $url)['root'] ?? null;

            if ($root !== null) {
                $roots[$name] = $root;
            }
        }

        return $roots;
    }
}
