<?php

namespace VolkmannDesignCode\UploadImages\Tests;

use VolkmannDesignCode\UploadImages\UploadImages;

/**
 * The Imagick path: HEIC, any size, orientation, transparency. Skipped
 * where the extension (or its HEIC support) is missing; CI installs it.
 */
class ImagickTest extends TestCase
{
	public function testHeicBecomesAJpegWithItsMetadata(): void
	{
		$this->requireImagick(heic: true);

		$file = $this->upload($this->fixture('photo.heic'), 'IMG_0002.HEIC');

		$this->assertSame('img_0002.jpg', $file->filename());
		$this->assertSame('image/jpeg', getimagesize($file->root())['mime']);
		$this->assertSame([900, 1200], $this->dimensions($file));
		$this->assertSame('2026-10-05 09:12:00', $file->content()->get('taken')->value());
		$this->assertSame('Example Camera', $file->content()->get('camera')->value());
		$this->assertNull($this->page()->file('img_0002.heic'));
	}

	public function testARealIphonePhoto(): void
	{
		$this->requireImagick(heic: true);

		$file = $this->upload($this->fixture('iphone.heic'), 'IMG_1234.HEIC', 'custom');

		$this->assertSame('img_1234.jpg', $file->filename());
		$this->assertSame([1000, 750], $this->dimensions($file));
		$this->assertSame('2026-08-17 07:34:24', $file->content()->get('date')->value());
		$this->assertSame('49.743942, -125.573569', $file->content()->get('where')->value());
		// the image itself carries none of it any more
		$this->assertArrayNotHasKey('GPSLatitude', @exif_read_data($file->root()) ?: []);
	}

	public function testANameTakenAlreadyGetsANumber(): void
	{
		$this->requireImagick(heic: true);

		$this->upload($this->fixture('photo-plain.jpg'), 'photo.jpg');
		$file = $this->upload($this->fixture('photo.heic'), 'photo.heic');

		$this->assertSame('photo-1.jpg', $file->filename());
	}

	public function testAnySizeWhateverPhpsMemoryLimit(): void
	{
		$this->requireImagick();
		// GD would refuse it; ImageMagick doesn't use PHP's memory
		UploadImages::$memoryLeft = fn () => 1024;

		$this->assertSame([2560, 1920], $this->dimensions($this->upload($this->image(3200, 2400), 'big.jpg')));
	}

	public function testThePhotoIsTurnedTheWayItIsShown(): void
	{
		$this->requireImagick();

		$this->assertSame([300, 400], $this->dimensions($this->upload($this->fixture('photo-rotated.jpg'), 'turned.jpg')));
	}

	public function testTransparencyBecomesWhiteInAJpeg(): void
	{
		$this->requireImagick();
		$this->kirby = $this->app(['volkmann-design-code.upload-images.convert' => ['png' => 'jpg']]);

		$file  = $this->upload($this->image(40, 40, 'png', alpha: true), 'logo.png');
		$image = imagecreatefromjpeg($file->root());
		$rgb   = imagecolorsforindex($image, imagecolorat($image, 20, 20));

		$this->assertSame('logo.jpg', $file->filename());
		$this->assertGreaterThan(240, $rgb['red']);
		$this->assertGreaterThan(240, $rgb['green']);
	}
}
