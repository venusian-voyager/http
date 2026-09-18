<?php

namespace Voyager\Http\Client\Events;

use Voyager\Http\Client\Request;

class RequestSending
{
    /**
     * The request instance.
     *
     * @var \Voyager\Http\Client\Request
     */
    public $request;

    /**
     * Create a new event instance.
     *
     * @param  \Voyager\Http\Client\Request  $request
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }
}
