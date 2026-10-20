<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

/**
 * How History's files column stands to the diff: beside it, or stacked over
 * it when the window is too narrow for three columns.
 */
enum SplitShape
{
    case Beside;
    case Stacked;
}
