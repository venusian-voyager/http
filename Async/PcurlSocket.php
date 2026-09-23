<?php

namespace Voyager\Http\Async;

/** libcurl CURL_SOCKET_* values. Kept in userland; ext-pcurl defines none. */
enum PcurlSocket: int
{
    case TIMEOUT = -1;
}
