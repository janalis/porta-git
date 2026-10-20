<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/** What to do with an operation that stopped: carry on, drop the stopped step, or undo it all. */
enum OperationStep: string
{
    case Continue = 'continue';
    case Skip = 'skip';
    case Abort = 'abort';
}
