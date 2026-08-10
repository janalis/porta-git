<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/** Whether a status refresh always repaints or stays quiet when nothing changed. */
enum RefreshMode
{
    case Eager;
    case Quiet;
}
