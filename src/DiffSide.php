<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/**
 * Which side of the index the shown diff was taken from — it decides what a
 * line/hunk staging selection means ({@see GitStagingService::stageSelection}:
 * worktree stages, cached unstages, untracked has no partial form).
 */
enum DiffSide
{
    case Worktree;
    case Cached;
    case Untracked;
}
