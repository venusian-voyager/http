<?php

namespace Voyager\Http\Async;

/** libcurl CURLMOPT_* values pcurl's setopt accepts. Kept in userland; the extension defines none. */
enum PcurlOption: int
{
    case SOCKET_FUNCTION = 20001;
    case TIMER_FUNCTION = 20004;
}
