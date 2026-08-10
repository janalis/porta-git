<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/**
 * The three remote operations the git view offers.
 *
 * A backed enum rather than the three string literals it replaces, because the
 * value crosses an event now: {@see \Porta\Event\GitRemoteCompleted} is
 * plugin-observable, and freezing `'fetch'|'pull'|'push'` as bare strings would
 * freeze three literals as third-party API with nothing naming them.
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
