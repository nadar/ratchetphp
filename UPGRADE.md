# Upgrade guide

## Upgrading to the `nadar/ratchetphp` PHP 8.5 fork

This fork makes Ratchet compatible with **PHP 8.5**. Upgrading is **not** a
drop-in `composer update` — PHP 8.5 deprecated some `SplObjectStorage` methods
that are commonly used in **your own application code** (the chat/push examples
in the Ratchet docs use them). You have to update that code yourself.

> **AI agent?** A machine-readable version of this guide, written as a
> step-by-step checklist, lives in [`AGENTS.md`](AGENTS.md).

---

### 1. Switch the dependency

```diff
 {
   "require": {
-    "plesk/ratchetphp": "^1.0"
+    "nadar/ratchetphp": "^1.0"
   }
 }
```

If you installed the package via the command line:

```bash
composer remove plesk/ratchetphp
composer require nadar/ratchetphp
```

The namespaces (`Ratchet\…`) are unchanged, so no `use` statements need to be
touched.

### 2. Requirements changed

* **PHP 8.5** or higher is now required (was `^8.0`).
* `symfony/http-foundation` and `symfony/routing` are now pinned to `^7.0`
  (Symfony 5.4 / 6 do not support PHP 8.5). If you also depend on Symfony
  directly, make sure your project is on Symfony 7.

### 3. Update your own `SplObjectStorage` usage (the important part)

PHP 8.5 deprecated the `SplObjectStorage` alias methods `attach()`, `detach()`
and `contains()` in favour of the `ArrayAccess` methods `offsetSet()`,
`offsetUnset()` and `offsetExists()`
(see the [PHP 8.5 deprecations RFC](https://wiki.php.net/rfc/deprecations_php_8_5)).

The library itself has been fixed, but **the typical Ratchet application keeps
its connected clients in an `SplObjectStorage` and calls these deprecated
methods**. Search your code base and replace them:

| Deprecated in 8.5                     | Replacement (array syntax)        | Replacement (method)                     |
| ------------------------------------- | --------------------------------- | ---------------------------------------- |
| `$s->attach($obj)`                    | `$s[$obj] = null;`                | `$s->offsetSet($obj);`                   |
| `$s->attach($obj, $data)`             | `$s[$obj] = $data;`               | `$s->offsetSet($obj, $data);`            |
| `$s->detach($obj)`                    | `unset($s[$obj]);`                | `$s->offsetUnset($obj);`                 |
| `$s->contains($obj)`                  | `isset($s[$obj])`                 | `$s->offsetExists($obj)`                 |

`removeAllExcept()`, `count()`, iteration (`foreach`) and reading
(`$s[$obj]`) are **not** deprecated and need no changes.

#### Before

```php
class MyChat implements MessageComponentInterface
{
    protected $clients;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);          // deprecated in 8.5
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);          // deprecated in 8.5
    }

    public function isConnected(ConnectionInterface $conn)
    {
        return $this->clients->contains($conn); // deprecated in 8.5
    }
}
```

#### After

```php
class MyChat implements MessageComponentInterface
{
    protected $clients;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients[$conn] = null;           // was attach()
    }

    public function onClose(ConnectionInterface $conn)
    {
        unset($this->clients[$conn]);           // was detach()
    }

    public function isConnected(ConnectionInterface $conn)
    {
        return isset($this->clients[$conn]);    // was contains()
    }
}
```

### 4. If you extend Ratchet's Session classes

If you subclass `Ratchet\Session\Storage\VirtualSessionStorage`,
`Ratchet\Session\Storage\Proxy\VirtualProxy`, or any Symfony
`NativeSessionStorage` / `SessionHandlerProxy`, your overridden methods must now
carry the Symfony 7 native type declarations, e.g.:

```php
public function start(): bool { /* … */ }
public function getId(): string { /* … */ }
public function setId(string $id): void { /* … */ }
public function getName(): string { /* … */ }
```

Missing return/parameter types will produce a fatal
`Declaration … must be compatible with …` error on PHP 8.5.

### 5. Verify

```bash
composer update
composer exec phpcs -- -p --standard=vendor/phpcompatibility/php-compatibility/PHPCompatibility --runtime-set testVersion 8.5 src
php -d error_reporting=E_ALL your-server.php   # watch for "Deprecated:" notices at runtime
```

Run your WebSocket server and exercise connect/message/disconnect: there should
be **no** `Deprecated: Method SplObjectStorage::… is deprecated since 8.5`
notices in the output.
