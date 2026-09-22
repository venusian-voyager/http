<?php

namespace Voyager\Http\Async;

/** libcurl CURL_POLL_* values. Kept in userland; ext-pcurl defines none. */
enum PcurlPoll: int
{
    case NONE = 0;
    case IN = 1;
    case OUT = 2;
    case INOUT = 3;
    case REMOVE = 4;
}
