<?php

namespace Voyager\Http\Async;

/** Loop resource names, one per async driver. */
enum AsyncResource: string
{
    case CURL = 'http.curl';
    case PCURL = 'http.pcurl';
}
