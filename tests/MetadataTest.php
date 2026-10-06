<?php

namespace VolkmannDesignCode\UploadImages\Tests;

use VolkmannDesignCode\UploadImages\Metadata;

/**
 * What a photo says about itself (Metadata), read from the fixtures.
 */
class MetadataTest extends TestCase
{
	public function testAPhotoWithGps(): void
	{
		$meta = Metadata::read(__DIR__ . '/fixtures/photo-gps.jpg', 'IMG_0001.JPG');

		$this->assertSame('2026-10-05 09:12:00', $meta['taken']);
		$this->assertEqualsWithDelta(51.31295, $meta['lat'], 0.000001);
		$this->assertEqualsWithDelta(9.48012, $meta['lng'], 0.000001);
		$this->assertSame($meta['lat'] . ', ' . $meta['lng'], $meta['location']);
		$this->assertSame('Example Camera', $meta['camera']);
		$this->assertSame(1200, $meta['width']);
		$this->assertSame(900, $meta['height']);
		$this->assertSame('IMG_0001.JPG', $meta['filename']);
	}

	public function testARealIphonePhoto(): void
	{
		// PHP reads nothing from it (8.5 included): ImageMagick does
		$this->requireImagick(heic: true);

		$meta = Metadata::read(__DIR__ . '/fixtures/iphone.heic');

		$this->assertSame('2026-08-17 07:34:24', $meta['taken']);
		$this->assertSame(49.743942, $meta['lat']);
		$this->assertSame(-125.573569, $meta['lng']);
		$this->assertSame(229.1, $meta['altitude']);
		$this->assertSame('Apple iPhone 14 Pro', $meta['camera']);
		$this->assertSame('iPhone 14 Pro back triple camera 2.22mm f/2.2', $meta['lens']);
		$this->assertSame(40, $meta['iso']);
		$this->assertSame('f/2.2', $meta['aperture']);
		$this->assertSame('1/759 s', $meta['exposure']);
		$this->assertSame('2.2 mm', $meta['focalLength']);
		$this->assertSame([4032, 3024], [$meta['width'], $meta['height']]);
	}

	public function testAPhotoWithoutGps(): void
	{
		$meta = Metadata::read(__DIR__ . '/fixtures/photo-plain.jpg');

		$this->assertSame('2026-10-06 07:05:00', $meta['taken']);
		$this->assertNull($meta['lat']);
		$this->assertNull($meta['location']);
		$this->assertNull($meta['iso']);
	}

	public function testTheSizeAsShown(): void
	{
		$meta = Metadata::read(__DIR__ . '/fixtures/photo-rotated.jpg');

		$this->assertSame([300, 400], [$meta['width'], $meta['height']]);
	}

	public function testNotAnImage(): void
	{
		$file = $this->root . '/notes.txt';
		file_put_contents($file, 'hello');

		$meta = Metadata::read($file);

		$this->assertSame(Metadata::KEYS, array_keys($meta));
		$this->assertSame([null], array_values(array_unique(array_values($meta), SORT_REGULAR)));
	}

	public function testAnExifBlockOnItsOwn(): void
	{
		// the APP1 segment of a JPEG: what ImageMagick hands out for a HEIC
		$jpeg  = file_get_contents(__DIR__ . '/fixtures/photo-gps.jpg');
		$start = strpos($jpeg, "Exif\0\0");
		$block = substr($jpeg, $start, unpack('n', substr($jpeg, $start - 2, 2))[1] - 2);

		$tags = Metadata::exifBlock($block);

		$this->assertSame('2026:10:05 09:12:00', $tags['DateTimeOriginal']);
		$this->assertSame('N', $tags['GPSLatitudeRef']);
		$this->assertSame([], Metadata::exifBlock('not exif'));
	}

	public function testRationalsAndReferences(): void
	{
		$this->assertEqualsWithDelta(51.31295, Metadata::degrees(['51/1', '18/1', '4662/100'], 'N'), 0.000001);
		$this->assertEqualsWithDelta(-9.5, Metadata::degrees('9/1, 30/1, 0/1', 'W'), 0.000001);
		$this->assertNull(Metadata::degrees(['51/1'], 'N'));
		$this->assertSame(46.62, Metadata::number('4662/100'));
		$this->assertNull(Metadata::number('1/0'));
		$this->assertNull(Metadata::number('abc'));
	}
}
