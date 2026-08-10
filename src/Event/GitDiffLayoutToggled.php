<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Event;

/**
 * The split/unified toggle flipped. Carries nothing: the widget owns the
 * layout state, and the listener's job is to persist whatever it now is.
 */
final readonly class GitDiffLayoutToggled {}
