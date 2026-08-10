<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/** Whether reloading history selects the first commit or keeps the current selection. */
enum HistoryReload
{
    case KeepSelection;
    case SelectFirst;
}
