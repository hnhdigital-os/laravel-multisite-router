<?php

return [
    'default' => [
        'title'    => 'Default',
        'session'  => 'default',
        'domain'   => 'default.example',
        'site'     => 'default',
        'template' => 'default',
    ],
    'current' => [
        'server-name'      => '',
        'domain'           => '',
        'site'             => '',
        'group'            => '',
        'group-uuid'       => '',
        'template'         => '',
        'route-parameters' => [],
    ],
    'allowed' => [
        'domains' => include(__DIR__.'/../allowed-domains.php'),
    ],
    'sites' => [],
    'site'  => [
        'title'          => [],
        'logo'           => [],
        'middleware'     => [],
        'auth-providers' => [],
        'cache'          => [],
        'assets'         => [],
        'variables'      => [],
    ],
];
