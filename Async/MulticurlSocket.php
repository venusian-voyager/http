<?php

namespace Voyager\Http\Async;

/** libcurl CURL_SOCKET_* values. Kept in userland; ext-multicurl defines none. */
enum MulticurlSocket: int
{
    case TIMEOUT = -1;
}
