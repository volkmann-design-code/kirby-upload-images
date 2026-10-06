# Kirby Upload Images

**Kirby 5** · PHP 8.2–8.5 · MIT

Uploaded images, made web-ready right after Kirby stores them:

- **HEIC/HEIF** (the iPhone's default) becomes a JPEG every browser shows.
- **Big photos are shrunk** to a long edge (2560 px by default),
  without running out of memory on shared hosting.
- **Turned the way they are shown** (EXIF orientation applied).
- **What the photo said about itself** (capture time, camera, and GPS
  if you want it) **lands in file fields** before the metadata is
  stripped from the image.

It works on Kirby's own upload, so in every files section and files
field of the Panel, for `File::create()` and `$file->replace()`, for
the file templates you turn it on for.

## Why

Kirby can resize images on upload (`create: width/height` in a file
blueprint), and that covers many sites. It runs into three things that
phones bring along:

- **HEIC** isn't among Kirby's resizable types, so it is stored as it
  is, and only Safari shows it. PHP itself can't decode HEIC, and from
  an iPhone photo it reads neither size nor EXIF, PHP 8.5 included:
  converting it takes ImageMagick.
- **24–48 megapixel photos** need ~100–200 MB in PHP with GD, Kirby's
  default driver: on a 128 MB shared host the upload ends with a fatal
  error.
- **Resizing strips the metadata.** Capture time and place are gone,
  unless a hook saves them first. Questions about that come up again and
  again on the forum.

This plugin uses **Imagick** when the server has it: ImageMagick works
outside PHP's `memory_limit`, so photos of any size fit, and it decodes
HEIC if built with libheif (many hosts are). Without Imagick, it uses
Kirby's **GD** driver after checking that the image fits into the memory
PHP has left, and refuses it with a clear message before anything is
stored otherwise.

## Similar concepts and plugins

- **Kirby itself**: `create: { width, height, format, quality }` in a
  file blueprint resizes on upload with the configured thumb driver. No
  HEIC, no memory check, metadata stripped. Use it *or* this plugin for a
  template (see [Notes](#notes)).
- [kirby-imagex](https://github.com/timnarr/kirby-imagex) by Tim Narr:
  responsive `<picture>` markup and srcsets at render time. Originals
  stay as uploaded; the two go well together.
- [kirby-heic-convert](https://github.com/pwaldhauer/kirby-heic-convert)
  by Philipp Waldhauer: converts HEIC to JPEG after upload with the
  `heif-convert` binary (which most shared hosts don't have); no
  resizing, no metadata.
- [kirby-autoresize](https://github.com/medienbaecker/kirby-autoresize)
  by Thomas Günther: resizing on upload for Kirby 3, part of Kirby since
  version 4.
- [kirby-upload-extended](https://github.com/Werbschaft/kirby-upload-extended):
  renaming on upload, resizing and compression through TinyPNG.

## Install

### Composer

```sh
composer require volkmann-design-code/kirby-upload-images
```

### Git submodule

```sh
git submodule add https://github.com/volkmann-design-code/kirby-upload-images.git site/plugins/upload-images
```

### Download

Download the [latest release](https://github.com/volkmann-design-code/kirby-upload-images/releases/latest)
and unzip it into `site/plugins/upload-images`.

## Usage

Turn it on in the file blueprints it should work for, and accept HEIC
there (Kirby's `image` type includes it):

```yaml
# site/blueprints/files/photo.yml
title: Photo
accept: image
uploadImages: true
fields:
  taken:
    type: date
    time: true
  camera:
    type: text
```

Upload a photo: it is stored as a JPEG of at most 2560 px with its
capture time and camera in the fields `taken` and `camera`. The fields
don't have to be in the blueprint; showing them is up to you.

A blueprint can bring its own options:

```yaml
# site/blueprints/files/gallery.yml
accept: image
uploadImages:
  maxSize: 4096
  fields:
    taken: date
    location: place     # "51.31295, 9.480119"
```

Or turn it on for every image upload (`enabled: true` below).

## Options

```php
// site/config/config.php
return [
	'volkmann-design-code.upload-images' => [
		// false (default): only where a file blueprint says `uploadImages`;
		// true: every image upload; or fn (Kirby\Cms\File $file): bool
		'enabled' => false,
		// the long edge in pixels; null keeps the size
		'maxSize' => 2560,
		'quality' => 85,
		// extension => format; other images keep theirs
		'convert' => ['heic' => 'jpg', 'heif' => 'jpg'],
		// remove EXIF, XMP & co. from the image (Imagick keeps the colour profile)
		'strip'   => true,
		// metadata => file field, written before stripping
		'fields'  => ['taken' => 'taken', 'camera' => 'camera'],
		// null: Imagick when installed, else GD; or 'imagick', 'gd'
		'driver'  => null,
	],
];
```

A blueprint's `uploadImages` options override these for its files.
More formats: e.g. `'convert' => ['heic' => 'jpg', 'heif' => 'jpg', 'png' => 'webp']`
(transparency becomes white when converting to JPEG). GIFs are left
alone (animations), SVGs too.

### Metadata

Keys for `fields`, each written only when the photo has it:

| Key | Example |
| --- | --- |
| `taken` | `2026-10-05 09:12:00` (as Kirby's date field stores it) |
| `lat`, `lng` | `51.31295`, `9.480119` |
| `location` | `51.31295, 9.480119` |
| `altitude` | `153.2` (metres) |
| `camera` | `Apple iPhone 15 Pro` |
| `lens` | `iPhone 15 Pro back triple camera 6.86mm f/1.78` |
| `iso`, `aperture`, `exposure`, `focalLength` | `64`, `f/1.8`, `1/120 s`, `6.9 mm` |
| `width`, `height` | the original's, as shown |
| `filename` | the name it was uploaded with |

**GPS is not written by default.** Where a photo was taken can be
personal data (and dangerous to publish for some people); map `lat`,
`lng` or `location` to fields only where you need them, and keep in mind
that file fields can show up wherever your templates output them.

### Without Kirby's upload

The conversion is also a function, e.g. for your own upload route
before `File::create()`:

```php
use VolkmannDesignCode\UploadImages\UploadImages;

['filename' => $filename, 'metadata' => $meta] = UploadImages::prepare($path, 'IMG_0001.HEIC', [
	'maxSize' => 2560,
]);
// $path now holds a JPEG; $filename is "IMG_0001.jpg"; $meta['taken'], $meta['lat'] …
```

## Server

| | Imagick | GD only |
| --- | --- | --- |
| JPEG, PNG, WebP, AVIF | any size | what fits into PHP's free memory (≈ 12 MP at 128 MB) |
| HEIC/HEIF | if ImageMagick has libheif | refused |
| Colour profile | kept | dropped |

Check your server with its PHP:

```sh
php -r 'echo Imagick::getVersion()["versionString"], " · HEIC: ", implode(",", Imagick::queryFormats("HEI*")) ?: "none", PHP_EOL;'
```

PHP's `exif` extension reads JPEG metadata; HEIC metadata comes through
Imagick.

Measured with ImageMagick 6.9.11 and PHP's `memory_limit` at 128 MB: a
12 MP iPhone photo (HEIC, 840 KB) became a 2560 × 1920 JPEG of 425 KB
in 0.4 s; a 48 MP JPEG (14 MB) and a 24 MP HEIC each one of about
630 KB in 1.5 s; PHP itself stayed at 12–16 MB.

## Notes

- **Don't combine with Kirby's `create` resizing** for the same
  template: Kirby resizes first, with its thumb driver (GD by default),
  before this plugin sees the file.
- Refusals happen in `file.create:before`, so nothing is stored; the
  Panel shows the message in its upload dialog.
- Converting renames the file (`photo.heic` → `photo.jpg`, or
  `photo-1.jpg` when that name is taken).
- The conversion runs as Kirby's internal user, so an editor who may
  upload but not rename files still gets a converted file.

## How it works

- `file.create:before`: is this an image it should handle, and can the
  server do it (HEIC needs Imagick with libheif, GD needs the memory)?
- `file.create:after` and `file.replace:after`: reads the metadata,
  converts the stored file in place (a subclass of Kirby's own Imagick
  driver, or Kirby's GD driver), renames it to its new format, writes
  the fields, and returns the new file to Kirby (and so to the Panel).

## Languages

Error messages in English and German, English elsewhere. More languages
are welcome as a pull request (`index.php`).

## Development

```sh
composer install    # Kirby into kirby/, PHPUnit
composer test
```

Without Imagick the Imagick tests skip; CI installs it.
`tests/fixtures/iphone.heic` is a real iPhone 14 Pro photo by Enzo
Volkmann, published with this repository under its licence, metadata
and location kept on purpose; the other fixtures are generated. To run them
locally with Docker:

```sh
docker build -t kirby-upload-images-imagick .github/imagick
docker run --rm -v "$PWD":/repo -w /repo kirby-upload-images-imagick vendor/bin/phpunit
```

## License

MIT, see [LICENSE](LICENSE). By [volkmann design code](https://www.volkmann-design-code.de).
