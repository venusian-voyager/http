<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Async drivers
    |--------------------------------------------------------------------------
    |
    | Which loop handler carries an async() request. "curl" uses ext-curl's
    | multi interface and owns the loop's sleep while requests are in
    | flight. "pcurl" needs the pcurl extension and hands curl's sockets to
    | the loop as streams. Sync requests never touch a driver.
    | Available drivers: 'curl' | 'pcurl'
    */

    'async' => [
        'default' => env('HTTP_ASYNC_DRIVER', 'curl'),
        'drivers' => [
            'curl'  => ['driver' => 'curl',  'max_handles' => 50, 'multi_options' => []],
            'pcurl' => ['driver' => 'pcurl', 'max_handles' => 50],
        ],
    ],

];
