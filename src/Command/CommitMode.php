<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Command;

/** Whether a commit starts fresh or amends (rewrites) the current HEAD. */
enum CommitMode
{
    case Fresh;
    case Amend;
}
