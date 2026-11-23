<?php

namespace Voyager\Http\Async;

/** libcurl CURLMOPT_* values multicurl's setopt accepts. Kept in userland; the extension defines none. */
enum MulticurlOption: int
{
    case SOCKET_FUNCTION = 20001;
    case TIMER_FUNCTION = 20004;
}
