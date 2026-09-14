<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Command;

/**
 * The three remote operations the git view offers, from the key that asks for
 * one ({@see \Porta\GitPlugin\Event\GitRemoteRequested}) through the command
 * that runs it.
 *
 * Backed, because the value crosses into core at the end:
 * {@see \Porta\Event\GitRemoteCompleted} is plugin-observable and core cannot
 * import a plugin type, so it carries `->value`. That makes the three strings
 * third-party API — this enum is what names them.
 *
 * The values double as the keybinding action ids the panes match on, which is
 * what lets the widget map a key straight to a case.
 */
enum GitRemoteOperation: string
{
    case Fetch = 'fetch';
    case Pull = 'pull';
    case Push = 'push';
}
