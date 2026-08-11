<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Event;

use Porta\GitPlugin\Command\GitRemoteOperation;

/** A git pane asked for fetch, pull or push. */
final readonly class GitRemoteRequested
{
    public function __construct(
        public GitRemoteOperation $operation,
    ) {}
}
