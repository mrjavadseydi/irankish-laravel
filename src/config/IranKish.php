<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Terminal credentials
    |--------------------------------------------------------------------------
    |
    | The values Iran Kish (IKC) gives you when your terminal is activated.
    | "password" is the terminal pass phrase and "acceptor" the acceptor
    | (merchant) id.
    |
    */

    'terminalId' => env('IRANKISH_TERMINAL_ID', ''),

    'password' => env('IRANKISH_PASSWORD', ''),

    'acceptor' => env('IRANKISH_ACCEPTOR_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | Public key
    |--------------------------------------------------------------------------
    |
    | The RSA public key of Iran Kish used to seal the authentication
    | envelope. Either the PEM content itself or an absolute path to a
    | file that contains it.
    |
    */

    'public_key' => env('IRANKISH_PUBLIC_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Callback (revert) URL
    |--------------------------------------------------------------------------
    |
    | Where the customer is sent back to after paying. Its host must match
    | the website registered for your acceptor. It can be overridden per
    | payment.
    |
    */

    'callback' => env('IRANKISH_CALLBACK_URL', 'https://example.com/callback'),

    /*
    |--------------------------------------------------------------------------
    | Gateway
    |--------------------------------------------------------------------------
    */

    'base_url' => env('IRANKISH_BASE_URL', 'https://ikc.shaparak.ir'),

    'payment_url' => env('IRANKISH_PAYMENT_URL', 'https://ikc.shaparak.ir/iuiv3/IPG/Index/'),

    // HTTP timeout in seconds for the calls to the gateway API.
    'timeout' => (int) env('IRANKISH_TIMEOUT', 30),

    // The IKC servers still negotiate ciphers that recent OpenSSL builds
    // reject at their default security level. Lowering the level for these
    // calls only keeps the handshake working; set to false if not needed.
    'legacy_ssl_ciphers' => (bool) env('IRANKISH_LEGACY_SSL_CIPHERS', true),

];
