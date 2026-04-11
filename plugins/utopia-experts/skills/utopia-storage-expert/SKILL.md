---
name: utopia-storage-expert
description: Expert reference for utopia-php/storage — Device abstraction over local filesystem and S3-compatible object storage with chunked upload, cross-device transfer, and telemetry. Consult for bucket-to-bucket moves, multipart uploads, and adapter pitfalls.
---

# utopia-php/storage Expert

## Purpose
Consistent `Device` abstraction over local filesystem and S3-compatible object storage (AWS S3, DO Spaces, Backblaze B2, Linode, Wasabi) with built-in chunked upload, cross-device transfer, and telemetry.

## Public API
- `Utopia\Storage\Storage` — static registry (`setDevice`/`getDevice`) plus `human()` byte formatter
- `Utopia\Storage\Device` (abstract) — contract for upload/read/write/transfer/delete/exists/getFileHash/getFileMimeType/createDirectory/deletePath/listFiles
- `Device\Local` — filesystem adapter with deterministic nested path sharding via `getPath()`
- `Device\S3` — canonical SigV4 S3 client with multipart state in `$metadata`
- `Device\AWS`, `Device\DOSpaces`, `Device\Backblaze`, `Device\Linode`, `Device\Wasabi` — subclass S3 with preset FQDNs, region/ACL constants
- `Device\Telemetry` — histogram wrapper emitting `storage.operation` metrics
- `Storage\Validator\{File,FileExt,FileName,FileSize,FileType,Upload}` — request-side validators

## Core patterns
- **Chunked upload protocol**: `upload($source, $path, $chunk, $chunks, $metadata)` with caller-owned `$metadata` by-ref that persists S3 multipart upload IDs and parts between chunks
- **`transfer($path, $dest, $targetDevice)`** copies across any two devices using `$transferChunkSize` (default 20MB) — reuse for bucket-to-bucket moves without downloading to disk
- All writes use `getPath()` to produce a sharded tree (e.g. `ab/cd/ef/abcdef…`) so a flat bucket doesn't become unlistable
- Telemetry injected via constructor; every op is timed into a single `storage.operation` histogram tagged by method

## Gotchas
- `$metadata` is by-reference — if a worker crashes mid-upload and you don't persist `$metadata`, you lose the S3 multipart ID and must `abort()`
- `Local::upload()` uses `move_uploaded_file`; passing a non-tmp path falls back to `rename()` which silently breaks across filesystems
- S3 adapter's `MAX_PAGE_SIZE` is 1000 — `listFiles()` pages internally but base `Device::MAX_PAGE_SIZE = PHP_INT_MAX` misleads
- `getFileHash()` on S3 returns the ETag, which is **NOT** an MD5 for multipart uploads — hash client-side if integrity matters

## Appwrite leverage opportunities
- **Streaming transfer**: current `transfer()` buffers `$transferChunkSize` in memory per chunk. Add a `streamTransfer()` that pipes through `php://temp` with a hash context, giving free SHA256 verification on bucket moves
- **Missing adapters**: no GCS, no Cloudflare R2 (R2 works via S3 but uses virtual-host style and `UNSIGNED-PAYLOAD` which the current signer may not default to); R2 as a first-class adapter would cut Cloud bandwidth costs
- **Parallel chunk upload**: multipart chunks are uploaded serially by the caller; a Swoole-aware `upload()` variant could `go()` all parts concurrently and still reuse `$metadata`
- **Content-addressed dedupe**: `getFileHash()` + `exists()` can back a write-if-absent wrapper so repeated uploads of the same file (avatars, deployments) short-circuit

## Example
```php
use Utopia\Storage\Storage;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Local;

Storage::setDevice('hot',  new Local('/var/lib/appwrite/uploads'));
Storage::setDevice('cold', new S3('bucket', getenv('AWS_KEY'), getenv('AWS_SECRET'),
    'appwrite-cold', S3::EU_CENTRAL_1, S3::ACL_PRIVATE));

$hot  = Storage::getDevice('hot');
$cold = Storage::getDevice('cold');

$metadata = [];
$chunks   = (int) ceil($hot->getFileSize($src = '/tmp/video.mp4') / (5 * 1024 * 1024));
for ($i = 1; $i <= $chunks; $i++) {
    $cold->upload($src, 'videos/x.mp4', $i, $chunks, $metadata);
}
if ($hot->getFileHash($src) !== $cold->getFileHash('videos/x.mp4')) {
    $cold->abort('videos/x.mp4', $metadata['uploadId'] ?? '');
}
```
