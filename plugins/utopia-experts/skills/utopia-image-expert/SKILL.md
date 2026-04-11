---
name: utopia-image-expert
description: Expert reference for utopia-php/image — Imagick-backed image manipulation for the Appwrite Storage preview pipeline. Consult when adding format conversions, tuning quality defaults, or stripping EXIF for privacy.
---

# utopia-php/image Expert

## Purpose
Imagick-backed image manipulation library for crop, resize, rotate, border, opacity, rounded corners, and format conversion.

## Public API
- `Utopia\Image\Image`
- `__construct(string $data)` — raw binary, not a path
- `crop(int $width, int $height, string $gravity = GRAVITY_CENTER): self`
- `setBorder(int $width, string $color): self`
- `setBorderRadius(int $radius): self`
- `setOpacity(float $opacity): self`
- `setRotation(int $degrees): self`
- `setBackground(string $color): self`
- `output(string $type, int $quality): string` / `save(string $path, string $type, int $quality)`
- `GRAVITY_*` constants (9 positions)

## Core patterns
- **Binary-in / binary-out** — always feed `file_get_contents()`; no filesystem coupling
- **EXIF orientation auto-applied at export time**, not on read — avoids double-rotation with mobile photos
- **GIF-aware** — `coalesceImages()` / `deconstructImages()` around frame loops so animations survive crop
- **Gravity-based cropping** — scales to fit the target aspect, then crops to position
- **Fluent `setX()` mutators** that defer work until `output()` — build a pipeline then render once

## Gotchas
- **Imagick AND GD are required** (`ext-imagick`, `ext-gd` in composer.json) — GD is used for some opacity paths; deployments with only Imagick will fail to install
- **Quality is int 0-100 and applies to all formats** including PNG where it has a different meaning (compression level, not quality). Always benchmark WebP/AVIF separately
- `$data` loaded via `readImageBlob` — a malicious/huge image blows memory before any limit check. Validate size before passing in
- **`setRotation` combines with EXIF-derived rotation** — setting 90 on a phone photo already flagged 90 yields 180. Either trust EXIF or call an EXIF-clear method first

## Appwrite leverage opportunities
- **Storage preview pipeline**: Appwrite Storage already uses this for `/files/{id}/preview`. Quality defaults of 100 are wasteful for WebP — bench and drop to 80 for bandwidth savings on Cloud. WebP quality 80 is visually indistinguishable from 100 but ~35% smaller
- **Format conversion ladder**: add AVIF output path for clients that send `Accept: image/avif`; current code supports jpg/png/gif/webp only. Imagick supports AVIF since 7.0.25
- **EXIF-strip default**: the library preserves EXIF (GPS, camera) through the pipeline — Appwrite Storage should strip by default for privacy unless the user opts in via preview params
- **Memory-bounded loading**: wrap the constructor in a decorator that rejects `strlen($data) > X` based on plan tier before handing to Imagick, and set `Imagick::setResourceLimit(RESOURCETYPE_MEMORY, …)` to prevent DoS on Cloud

## Example
```php
use Utopia\Image\Image;

$image = new Image(file_get_contents('avatar.jpg'));
$thumbnail = $image
    ->crop(256, 256, Image::GRAVITY_CENTER)
    ->setBorderRadius(16)
    ->setBorder(2, '#e5e7eb')
    ->output('webp', 80);

file_put_contents('avatar-thumb.webp', $thumbnail);
```
