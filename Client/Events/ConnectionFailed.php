<?php

namespace Voyager\Http\Client\Events;

use Voyager\Http\Client\ConnectionException;
use Voyager\Http\Client\Request;

class ConnectionFailed
{
    /**
     * The request instance.
     *
     * @var \Voyager\Http\Client\Request
     */
    public $request;

    /**
     * The exception instance.
     *
     * @var \Voyager\Http\Client\ConnectionException
     */
    public $exception;

    /**
     * Create a new event instance.
     *
     * @param  \Voyager\Http\Client\Request  $request
     * @param  \Voyager\Http\Client\ConnectionException  $exception
     */
    public function __construct(Request $request, ConnectionException $exception)
    {
        $this->request = $request;
        $this->exception = $exception;
    }
}
