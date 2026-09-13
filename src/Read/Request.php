<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Read;

/**
 * The site as the service may be told it: the two filtered documents, the patches whose text travels, and the maps a run reads beside them.
 */
class Request
{
    public function __construct(
        /** The composer.json the service receives, narrowed to the keys and packages it can judge. */
        public readonly string $composerJson,
        /** The composer.lock the service receives, one trimmed entry per checkable package. */
        public readonly string $composerLock,
        public readonly PatchConfig $patches,
        /**
         * Every package the lock names, to the version it pins, so a package the service cannot judge can still be named with its release.
         *
         * @var array<string, string>
         */
        public readonly array $installed,
        /**
         * The site's own requirement for each checkable package, keyed by composer name.
         *
         * @var array<string, string>
         */
        public readonly array $constraints,
        /**
         * The core requirement each installed release declares, read from the site's own vendor directory.
         *
         * @var array<string, string>
         */
        public readonly array $declaredCore,
    ) {
    }
}
