<?php

namespace VolkmannDesignCode\UploadImages\Tests;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Filesystem\Dir;
use Kirby\Toolkit\Str;
use PHPUnit\Framework\TestCase as BaseTestCase;
use VolkmannDesignCode\UploadImages\UploadImages;

/**
 * A Kirby site with one page to upload to and three file templates:
 * `photo` (the plugin on), `custom` (on, with its own options) and
 * `default` (off unless the plugin's `enabled` says otherwise).
 *
 * Fixtures: `photo-gps.jpg` 1200 × 900, taken 2026-10-05 09:12:00 at
 * 51.31295, 9.48012 with "Example Camera"; `photo.heic` 900 × 1200 with
 * the same EXIF; `photo-plain.jpg` without GPS; `photo-rotated.jpg`
 * 400 × 300 pixels with EXIF orientation 6 (shown 300 × 400);
 * `iphone.heic` a real iPhone 14 Pro photo (4032 × 3024, Vancouver Island,
 * 2026-08-17), metadata and location kept on purpose.
 */
abstract class TestCase extends BaseTestCase
{
	protected string $root;
	protected App $kirby;

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/kirby-upload-images-test-' . Str::random(8, 'alphaNum');
		Dir::make($this->root . '/content/site');
		$this->kirby = $this->app();
	}

	protected function tearDown(): void
	{
		UploadImages::$memoryLeft = null;
		Dir::remove($this->root);
	}

	protected function app(array $options = []): App
	{
		$kirby = new App([
			'roots' => [
				'index'   => $this->root,
				'content' => $this->root . '/content',
				'site'    => $this->root . '/site',
				'media'   => $this->root . '/media',
				'cache'   => $this->root . '/cache',
			],
			'blueprints' => [
				// as the README has it: every image type, HEIC included
				'files/photo'  => ['title' => 'Photo', 'accept' => ['type' => 'image'], 'uploadImages' => true],
				'files/custom' => ['title' => 'Custom', 'uploadImages' => [
					'maxSize' => 1000,
					'fields'  => ['taken' => 'date', 'location' => 'where', 'lat' => 'lat', 'lng' => 'lng'],
				]],
			],
			'options' => ['debug' => true, ...$options],
		]);

		$kirby->impersonate('kirby');

		return $kirby;
	}

	protected function page(): Page
	{
		return $this->kirby->page('site') ?? $this->kirby->site()->createChild(['slug' => 'site', 'isDraft' => false, 'content' => ['title' => 'Site']]);
	}

	/**
	 * A copy of a fixture to upload; Kirby moves or copies it from there.
	 */
	protected function fixture(string $name): string
	{
		$source = $this->root . '/upload-' . Str::random(6, 'alphaNum') . '-' . $name;
		copy(__DIR__ . '/fixtures/' . $name, $source);

		return $source;
	}

	/**
	 * A plain image of the given size, made with GD.
	 */
	protected function image(int $width, int $height, string $type = 'jpg', bool $alpha = false): string
	{
		$image = imagecreatetruecolor($width, $height);

		if ($alpha === true) {
			imagesavealpha($image, true);
			imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
		} else {
			imagefill($image, 0, 0, imagecolorallocate($image, 120, 160, 90));
		}

		$source = $this->root . '/upload-' . Str::random(6, 'alphaNum') . '.' . $type;
		$type === 'png' ? imagepng($image, $source) : imagejpeg($image, $source, 80);

		return $source;
	}

	protected function upload(string $source, string $filename, string $template = 'photo'): File
	{
		return File::create([
			'source'   => $source,
			'parent'   => $this->page(),
			'filename' => $filename,
			'template' => $template,
		]);
	}

	protected function dimensions(File|string $file): array
	{
		return array_slice(getimagesize($file instanceof File ? $file->root() : $file), 0, 2);
	}

	protected function requireImagick(bool $heic = false): void
	{
		if (UploadImages::imagick($heic) === false) {
			$this->markTestSkipped($heic ? 'ImageMagick without HEIC here' : 'no Imagick here');
		}
	}

	protected function withoutImagick(): void
	{
		if (UploadImages::imagick() === true) {
			$this->markTestSkipped('Imagick is installed: the GD path is not taken');
		}
	}
}
