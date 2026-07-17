# Ratchet (nadar fork)

[![CI](https://github.com/nadar/ratchetphp/workflows/CI/badge.svg)](https://github.com/nadar/ratchetphp/actions?query=workflow%3ACI)
[![Latest Stable Version](https://poser.pugx.org/nadar/ratchetphp/v)](https://packagist.org/packages/nadar/ratchetphp)

A PHP library for asynchronously serving WebSockets.
Build up your application through simple interfaces and re-use your application without changing any of its code just by combining different components.

> **About this fork**
>
> `nadar/ratchetphp` is a maintenance fork of [Ratchet](https://github.com/ratchetphp/Ratchet)
> focused on **PHP 8.5 compatibility**. PHP 8.5 deprecated the `SplObjectStorage`
> alias methods `attach()`, `detach()` and `contains()` in favour of the
> `ArrayAccess` methods `offsetSet()`, `offsetUnset()` and `offsetExists()`
> (see the [PHP 8.5 deprecations RFC](https://wiki.php.net/rfc/deprecations_php_8_5)).
> This fork replaces every deprecated call so running Ratchet on PHP 8.5 no
> longer emits deprecation notices. **This fork requires PHP 8.5.**
>
> ⚠️ **Switching the dependency is not enough.** The deprecated
> `SplObjectStorage` methods are typically also used in *your own* application
> code (the classic chat example keeps its clients in an `SplObjectStorage`),
> so `composer update` alone will not silence the deprecations — you have to
> update that code too. See **[UPGRADE.md](UPGRADE.md)** for the human guide and
> **[AGENTS.md](AGENTS.md)** for a checklist an AI agent can follow.

## Installation

```bash
composer require nadar/ratchetphp
```

Already using `plesk/ratchetphp` (or upstream Ratchet)? Read
**[UPGRADE.md](UPGRADE.md)** first — you will need to update `SplObjectStorage`
calls in your own code.

## Requirements

* PHP 8.5 or higher.

Shell access is required and root access is recommended.
To avoid proxy/firewall blockage it's recommended WebSockets are requested on port 80 or 443 (SSL), which requires root access.
In order to do this, along with your sync web stack, you can either use a reverse proxy or two separate machines.
You can find more details in the [server conf docs](http://socketo.me/docs/deploy#server_configuration).

### Documentation

User and API documentation is available on Ratchet's website: http://socketo.me

See https://github.com/cboden/Ratchet-examples for some out-of-the-box working demos using Ratchet.

---

### A quick example

```php
<?php
// Make sure composer dependencies have been installed
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

/**
 * chat.php
 * Send any incoming messages to all connected clients (except sender)
 */
class MyChat implements MessageComponentInterface
{
    protected $clients;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients[$conn] = null; // SplObjectStorage->attach is deprecated as of PHP 8.5
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        foreach ($this->clients as $client) {
            if ($from != $client) {
                $client->send($msg);
            }
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        unset($this->clients[$conn]); // SplObjectStorage->detach is deprecated as of PHP 8.5
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $conn->close();
    }
}

// Run the server application through the WebSocket protocol on port 8080
$app = new Ratchet\App('localhost', 8080);
$app->route('/chat', new MyChat(), array('*'));
$app->route('/echo', new Ratchet\Server\EchoServer, array('*'));
$app->run();
```

    $ php chat.php

```javascript
// Then some JavaScript in the browser:
var conn = new WebSocket('ws://localhost:8080/echo');
conn.onmessage = function(e) { console.log(e.data); };
conn.onopen = function(e) { conn.send('Hello Me!'); };
```

## Checking PHP 8.5 compatibility

This fork ships tooling to verify PHP 8.5 compatibility:

```bash
composer install

# Scan the source for code that is not compatible with PHP 8.5
# (uses PHPCompatibility with testVersion 8.5)
composer compat

# Run the test suite
composer test

# Do both at once
composer check:compat
```

The [GitHub Actions CI](.github/workflows/ci.yml) runs the test suite, the
PHPCompatibility check and the coding-standards check against PHP 8.5 on every
push and pull request.

## License

MIT — see [LICENSE](LICENSE).
