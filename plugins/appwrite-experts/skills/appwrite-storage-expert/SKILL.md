---
name: appwrite-storage-expert
description: Buckets, files, image previews, compression, and antivirus scanning in the Appwrite storage system.
---

# Appwrite Storage Expert

## Module structure

`src/Appwrite/Platform/Modules/Storage/` — 1 service (Http), 16 actions.

Key files:
- `Services/Http.php` — route registration
- `Http/` — action classes for buckets and files

Storage devices registered per resource type:
- `deviceForFiles` — user file uploads
- `deviceForFunctions` — function code packages
- `deviceForSites` — site deployment bundles
- `deviceForBuilds` — build artifacts
- `deviceForCache` — cached data
- `deviceForMigrations` — import/export files

## Bucket model

Buckets define storage policies:
- `fileSecurity` — whether file-level permissions are checked (like `documentSecurity` in databases)
- `maximumFileSize` — per-file size limit in bytes
- `allowedFileExtensions` — whitelist of allowed extensions (empty = all)
- `compression` — `none`, `gzip`, or `zstd`
- `encryption` — boolean, encrypts file content at rest
- `antivirus` — boolean, scans uploads via ClamAV

Permissions on buckets follow the same model as database collections.

## File upload flow

1. `POST /v1/storage/buckets/{bucketId}/files` — multipart upload
2. Validates: file size, extension, bucket permissions
3. If `antivirus: true` — scans via ClamAV adapter
4. If `compression` set — compresses before storage
5. If `encryption: true` — encrypts with project key
6. Stores via device adapter (local, S3, DO Spaces, etc.)
7. Creates file document with metadata (size, mimeType, hash, etc.)

Chunked upload supported via `X-Appwrite-Chunk` headers for large files.

## Image preview

`GET /v1/storage/buckets/{bucketId}/files/{fileId}/preview` generates on-the-fly image transformations:

Parameters: `width`, `height`, `gravity`, `quality`, `borderWidth`, `borderColor`, `borderRadius`, `opacity`, `rotation`, `background`, `output` (jpg/png/gif/webp).

Uses the `utopia-php/image` library. Preview results cached by device.

## File download / view

- `GET .../files/{fileId}/download` — forces download (`Content-Disposition: attachment`)
- `GET .../files/{fileId}/view` — inline display (`Content-Disposition: inline`)
- `GET .../files/{fileId}` — returns file metadata document

## Storage device abstraction

`utopia-php/storage` provides the device interface:
- `Local` — filesystem storage
- `S3` — AWS S3 compatible
- `DOSpaces` — DigitalOcean Spaces
- `Backblaze` — B2 storage
- `Linode` — Linode Object Storage
- `Wasabi` — Wasabi storage

Device selection via `_APP_STORAGE_DEVICE` env var. Device factory in `app/init/resources/`.

## Gotchas

- File permissions are only checked if `fileSecurity: true` on the bucket — otherwise only bucket permissions matter
- Antivirus scanning adds latency to uploads — ClamAV must be running (`_APP_STORAGE_ANTIVIRUS`)
- Compression happens before encryption — the stored blob is compressed then encrypted
- `maximumFileSize` is in bytes — the global limit is `_APP_STORAGE_LIMIT` (default 30MB)
- Image preview only works for image mimetypes — requesting preview of a PDF returns an error
- Chunked uploads require the client to manage chunk ordering via the `X-Appwrite-Chunk` header

## Related skills

- `appwrite-databases-expert` — file metadata stored as documents
- `appwrite-workers-expert` — Deletes worker handles file cleanup on bucket/file deletion
