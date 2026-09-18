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

use Flarum\Frontend\Compiler\VersionerInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Opens a write batch for exactly as long as a rebuild runs.
 *
 * The compilers write one file at a time and expect an answer before moving
 * on, so there is nothing in that interface to batch against. What there is,
 * already, is a marker for the boundaries of a rebuild: both paths that
 * recompile a frontend — {@see \Flarum\Frontend\RecompileFrontendAssets} and
 * {@see \Flarum\Foundation\Console\CacheClearCommand} — wrap their commits in
 * `deferWrites()` and `flushWrites()` so the versioner can store revisions
 * together.
 *
 * That window is the same one the uploads want, so this wraps the real
 * versioner and uses it: writes are held from `deferWrites()` and sent at
 * `flushWrites()`. No core change is needed, and a caller that does not defer
 * — a one-off write from an extension, say — is unaffected, because outside
 * the window each write goes straight out as before.
 *
 * Order matters on the way out: the files are uploaded first, and the
 * revisions recorded only once they have arrived. A revision naming a file
 * that is not in the bucket would leave
 * {@see \Flarum\Frontend\Compiler\RevisionCompiler::getUrl()} pointing at
 * nothing, and because it only recompiles when a revision is *missing*,
 * nothing would repair it.
 */
class BatchedVersioner implements VersionerInterface
{
    public function __construct(
        private readonly VersionerInterface $versioner,
        private readonly Container $container
    ) {
    }

    public function deferWrites(): void
    {
        $this->batch()?->open();
        $this->versioner->deferWrites();
    }

    public function flushWrites(): void
    {
        // Uploads first: a recorded revision must never outlive the file it
        // names. If the batch throws, the revisions stay deferred and are not
        // written, so the next rebuild still believes it has work to do.
        $this->batch()?->close();

        $this->versioner->flushWrites();
    }

    /**
     * The batch the assets disk writes into, once that disk exists.
     *
     * Resolved on use rather than injected: the batch is created while the
     * assets disk is built, and this decorator is assembled before the
     * filesystem manager has got that far. Null until then — and on a forum
     * whose assets disk is not on a bucket at all, null for good, which is
     * the right answer since there is no round trip to save.
     */
    private function batch(): ?WriteBatch
    {
        // Only ever asked for, never built. Resolving the assets disk from
        // here would be circular: building it resolves this versioner, which
        // would ask for the disk again. The batch appears in the container as
        // a side effect of that disk being built for its own reasons, and
        // until then there is nothing to hold writes for anyway — no compiler
        // can have written a file without first having the disk to write it
        // to.
        return $this->container->bound(WriteBatch::class)
            ? $this->container->make(WriteBatch::class)
            : null;
    }

    public function putRevision(string $file, ?string $revision): void
    {
        $this->versioner->putRevision($file, $revision);
    }

    public function getRevision(string $file): ?string
    {
        return $this->versioner->getRevision($file);
    }

    /**
     * @return array<string, string>
     */
    public function allRevisions(): array
    {
        return $this->versioner->allRevisions();
    }
}
