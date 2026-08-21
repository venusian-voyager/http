<?php

namespace Voyager\Http\Client\Events;

use Voyager\Http\Client\Request;
use Voyager\Http\Client\Response;

class ResponseReceived
{
    /**
     * The request instance.
     *
     * @var \Voyager\Http\Client\Request
     */
    public $request;

    /**
     * The response instance.
     *
     * @var \Voyager\Http\Client\Response
     */
    public $response;

    /**
     * Create a new event instance.
     *
     * @param  \Voyager\Http\Client\Request  $request
     * @param  \Voyager\Http\Client\Response  $response
     */
    public function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;
    }
}
