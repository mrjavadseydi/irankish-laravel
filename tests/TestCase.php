<?php

namespace MJSeydi\iranKish\Tests;

use MJSeydi\iranKish\IranKishServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected static ?string $privateKey = null;

    protected static ?string $publicKey = null;

    protected function getPackageProviders($app): array
    {
        return [IranKishServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['IranKish' => \MJSeydi\iranKish\Facades\IranKish::class];
    }

    protected function defineEnvironment($app): void
    {
        if (static::$privateKey === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            static::$privateKey = $private;
            static::$publicKey = openssl_pkey_get_details($key)['key'];
        }

        $app['config']->set('IranKish.terminalId', '08012345');
        $app['config']->set('IranKish.password', 'A1B2C3D4E5F60718');
        $app['config']->set('IranKish.acceptor', '992180008012345');
        $app['config']->set('IranKish.public_key', static::$publicKey);
        $app['config']->set('IranKish.callback', 'https://shop.test/callback');
    }
}
