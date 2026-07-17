# Agent guide: migrating a project to `nadar/ratchetphp` (PHP 8.5)

This file is written for an AI coding agent. It is the machine-actionable
version of [`UPGRADE.md`](UPGRADE.md). Follow the steps in order. Do not stop at
`composer update` — the deprecations that break on PHP 8.5 live in the
**consuming application's own code**, not only in the library.

## Context

PHP 8.5 deprecated the `SplObjectStorage` alias methods. Each has a direct,
behaviour-preserving replacement:

- `attach($o)`        → `$s[$o] = null;`   (or `$s->offsetSet($o);`)
- `attach($o, $d)`    → `$s[$o] = $d;`     (or `$s->offsetSet($o, $d);`)
- `detach($o)`        → `unset($s[$o]);`   (or `$s->offsetUnset($o);`)
- `contains($o)`      → `isset($s[$o])`    (or `$s->offsetExists($o)`)

NOT deprecated, leave unchanged: `removeAllExcept()`, `count()`, `foreach`
iteration, reads via `$s[$o]`, `new \SplObjectStorage()`.

## Checklist

### 1. Update the dependency
- In `composer.json`, replace `"plesk/ratchetphp"` with `"nadar/ratchetphp"`
  (keep the version constraint). Namespaces are unchanged (`Ratchet\…`) — do
  not rewrite `use` statements.
- Ensure `"php"` allows `>= 8.5` and that `symfony/http-foundation` /
  `symfony/routing` (if required directly) allow `^7.0`.

### 2. Find deprecated SplObjectStorage calls in the project
Run (repo root, exclude `vendor/`):

```bash
grep -rnE '->(attach|detach|contains)\(' --include='*.php' . | grep -v '/vendor/'
```

For each hit, confirm the receiver is an `SplObjectStorage` (or a subclass —
including `\SplObjectStorage` used to hold Ratchet `ConnectionInterface`
clients). Ignore matches on unrelated classes that happen to have methods named
`attach`/`detach`/`contains` (e.g. event emitters, collections with their own
API). When unsure, check how the variable was constructed.

### 3. Apply the replacements
Rewrite each confirmed call using the table above. Prefer array syntax
(`$s[$o] = null;`, `unset($s[$o]);`, `isset($s[$o])`) — it is the idiom used in
this fork's README. Preserve any second argument passed to `attach()` as the
array value.

### 4. Fix subclasses of Symfony session classes (only if present)
If the project extends `NativeSessionStorage`, `SessionHandlerProxy`,
`Ratchet\Session\Storage\VirtualSessionStorage`, or
`Ratchet\Session\Storage\Proxy\VirtualProxy`, add the Symfony 7 native type
declarations to overridden methods, otherwise PHP 8.5 raises a fatal
`Declaration … must be compatible with …`:
- `start(): bool`, `regenerate(bool $destroy = false, ?int $lifetime = null): bool`
- `save(): void`, `getId(): string`, `setId(string $id): void`
- `getName(): string`, `setName(string $name): void`
- `setSaveHandler(AbstractProxy|\SessionHandlerInterface|null $saveHandler = null): void`

### 5. Verify (all must pass with zero deprecation output)
```bash
composer update
# Static check for PHP 8.5 compatibility across the whole project source:
composer require --dev phpcompatibility/php-compatibility
vendor/bin/phpcs -p \
  --standard=vendor/phpcompatibility/php-compatibility/PHPCompatibility \
  --runtime-set testVersion 8.5 <your-source-dirs>
# Run the test suite / boot the server on 8.5 with deprecations visible:
php -d error_reporting=E_ALL <entrypoint>
```
The migration is complete only when there are **no** `Deprecated: Method
SplObjectStorage::… is deprecated since 8.5` notices at runtime and the
PHPCompatibility scan reports no `testVersion 8.5` errors.

### 6. Do not
- Do not add `@` error suppression or lower `error_reporting` to hide the
  deprecations — fix the calls.
- Do not change reads, iteration, `count()`, or `removeAllExcept()`.
- Do not leak vendor paths, stack traces, or local debug output into committed
  files.
