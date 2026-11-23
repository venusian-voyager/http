<?php

namespace Voyager\Http\Async;

/** libcurl CURL_CSELECT_* values. Kept in userland; ext-multicurl defines none. */
enum MulticurlSelect: int
{
    case IN = 0x01;
    case OUT = 0x02;
    case ERR = 0x04;
}
