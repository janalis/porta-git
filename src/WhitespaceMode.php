<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/** Whether a diff keeps whitespace-only changes or drops them (git's `-w`). */
enum WhitespaceMode
{
    case Shown;
    case Hidden;
}
