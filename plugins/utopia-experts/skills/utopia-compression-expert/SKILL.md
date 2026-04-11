---
name: utopia-compression-expert
description: Expert reference for utopia-php/compression — zero-dependency facade across Brotli/Deflate/GZIP/LZ4/Snappy/XZ/Zstd with HTTP Accept-Encoding negotiation. Consult for response compression middleware or storage-at-rest compression.
---

# utopia-php/compression Expert

## Purpose
Zero-dependency compression facade across Brotli, Deflate, GZIP, LZ4, Snappy, XZ, and Zstd with a shared abstract class, supported-check, and HTTP `Accept-Encoding` negotiation.

## Public API
- `Utopia\Compression\Compression` (abstract) — constants (`BROTLI`, `DEFLATE`, `GZIP`, `LZ4`, `SNAPPY`, `XZ`, `ZSTD`, `NONE`), `compress(string)`, `decompress(string)`, `getName()`, `getContentEncoding()`, `isSupported()` static
- `Compression::fromName(string)` — factory by canonical name or alias (`br` → Brotli)
- `Compression::fromAcceptEncoding(string, array $supported = [])` — parses `Accept-Encoding: br;q=0.9, gzip;q=0.8` and returns the best supported algorithm
- `Algorithms\Zstd` — level 1–22, default 3
- `Algorithms\Brotli`, `Algorithms\GZIP`, `Algorithms\Deflate`, `Algorithms\LZ4`, `Algorithms\Snappy`, `Algorithms\XZ`

## Core patterns
- **Instance-per-algorithm** — you construct the concrete algorithm; there's no runtime polymorphism trick. `fromName()` returns the right class
- **Content-Encoding derived from `getName()`** lowercased, so the same object works for compressing bodies and advertising headers
- **`fromAcceptEncoding()` defaults to preference list** of `zstd > brotli > gzip > deflate > none` filtered by `isSupported()` — automatic negotiation if the server has the right extensions loaded
- **Levels are constructor args**, not setters on the base — `new Zstd(15)` not `(new Zstd())->setLevel(15)` (setters exist too, with range validation)

## Gotchas
- PHP extension requirements are **suggests**, not `require`. `new Zstd()` will fatal at runtime if `ext-zstd` isn't loaded. Always gate construction on `Zstd::isSupported()`
- Zstd levels ≥ 20 consume 256MB+ memory per stream — don't expose level as user input without clamping
- `Compression::NONE` / `IDENTITY` return `null` from `fromName()` — wrap the call site to tolerate null as "pass-through"
- LZ4 and Snappy aren't standard HTTP content-encodings — browsers will reject them if returned in `Content-Encoding`

## Appwrite leverage opportunities
- **Automatic response compression middleware**: `utopia-php/http` has no built-in response compression. A middleware using `Compression::fromAcceptEncoding($request->getHeader('Accept-Encoding'))` → `compress($response->getBody())` → set `Content-Encoding` would cut egress by ~70% on JSON APIs essentially for free
- **Storage-at-rest compression**: Appwrite Functions deployment tarballs and database backups are stored raw. Use Zstd level 6 in a `Device` decorator: transparent compress on upload, decompress on read. Zstd decompresses at ~2GB/s, so cold reads are free
- **LZ4 for hot path payloads**: the Realtime service broadcasts JSON to WS clients — LZ4 is ~500MB/s compress, and though not HTTP-standard, it's fine over a custom WS framing
- **Accept-Encoding propagation**: SDK HTTP calls (via `utopia-php/fetch`) should automatically set `Accept-Encoding` based on locally-supported algorithms — no helper today; add one that returns the header value from the supported list

## Example
```php
use Utopia\Compression\Compression;
use Utopia\Compression\Algorithms\Zstd;

$algorithm = Compression::fromAcceptEncoding(
    $request->getHeader('Accept-Encoding', ''),
);

$body = $response->getBody();
if ($algorithm !== null && strlen($body) > 1024) {
    $compressed = $algorithm->compress($body);
    $response
        ->addHeader('Content-Encoding', $algorithm->getContentEncoding())
        ->addHeader('Vary', 'Accept-Encoding')
        ->setBody($compressed);
}

$archive = (new Zstd(6))->compress(file_get_contents('/tmp/deployment.tar'));
file_put_contents('/var/backups/deployment.tar.zst', $archive);
```
