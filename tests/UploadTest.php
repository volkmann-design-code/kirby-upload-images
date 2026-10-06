<?php

namespace VolkmannDesignCode\UploadImages\Tests;

use Kirby\Exception\InvalidArgumentException;
use VolkmannDesignCode\UploadImages\UploadImages;

/**
 * Through Kirby's own upload (`File::create`, `replace`): which files it
 * works on, what they become, what lands in their fields, what is
 * refused. These run with GD (`driver: gd`), so everywhere; the Imagick
 * path is in ImagickTest.
 */
class UploadTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$this->kirby = $this->app(['volkmann-design-code.upload-images.driver' => 'gd']);
	}

	public function testOffUnlessTheBlueprintTurnsItOn(): void
	{
		$file = $this->upload($this->image(3200, 2400), 'big.jpg', 'default');

		$this->assertSame([3200, 2400], $this->dimensions($file));
		$this->assertTrue($file->content()->get('taken')->isEmpty());
	}

	public function testOnForEveryImageWithTheOption(): void
	{
		$this->kirby = $this->app(['volkmann-design-code.upload-images.driver' => 'gd', 'volkmann-design-code.upload-images.enabled' => true]);

		$this->assertSame([2560, 1920], $this->dimensions($this->upload($this->image(3200, 2400), 'big.jpg', 'default')));
	}

	public function testOrDecidedPerFile(): void
	{
		$this->kirby = $this->app([
			'volkmann-design-code.upload-images.driver'  => 'gd',
			'volkmann-design-code.upload-images.enabled' => fn ($file) => str_starts_with($file->filename(), 'shrink-'),
		]);

		$this->assertSame([2560, 1920], $this->dimensions($this->upload($this->image(3200, 2400), 'shrink-me.jpg', 'default')));
		$this->assertSame([3200, 2400], $this->dimensions($this->upload($this->image(3200, 2400), 'keep-me.jpg', 'default')));
	}

	public function testABigPhotoIsShrunkToTheLongEdge(): void
	{
		$this->assertSame([2560, 1920], $this->dimensions($this->upload($this->image(3200, 2400), 'landscape.jpg')));
		$this->assertSame([1920, 2560], $this->dimensions($this->upload($this->image(2400, 3200), 'portrait.jpg')));
		$this->assertSame([800, 600], $this->dimensions($this->upload($this->image(800, 600), 'small.jpg')));
	}

	public function testTheMetadataLandsInFieldsAndLeavesTheImage(): void
	{
		$file = $this->upload($this->fixture('photo-gps.jpg'), 'photo.jpg');

		$this->assertSame('2026-10-05 09:12:00', $file->content()->get('taken')->value());
		$this->assertSame('Example Camera', $file->content()->get('camera')->value());
		// no GPS by default: it's personal data
		$this->assertTrue($file->content()->get('lat')->isEmpty());

		$exif = @exif_read_data($file->root()) ?: [];
		$this->assertArrayNotHasKey('GPSLatitude', $exif);
		$this->assertArrayNotHasKey('DateTimeOriginal', $exif);
	}

	public function testABlueprintMapsItsOwnFields(): void
	{
		$file = $this->upload($this->fixture('photo-gps.jpg'), 'photo.jpg', 'custom');

		$this->assertSame('2026-10-05 09:12:00', $file->content()->get('date')->value());
		$this->assertSame('51.31295, 9.480119', $file->content()->get('where')->value());
		$this->assertSame('51.31295', $file->content()->get('lat')->value());
		// and its own size
		$this->assertSame([1000, 750], $this->dimensions($file));
	}

	public function testThePhotoIsTurnedTheWayItIsShown(): void
	{
		$this->assertSame([300, 400], $this->dimensions($this->upload($this->fixture('photo-rotated.jpg'), 'turned.jpg')));
	}

	public function testOtherFormatsKeepTheirsUnlessConverted(): void
	{
		$png = $this->upload($this->image(3000, 1500, 'png'), 'plan.png');
		$this->assertSame('plan.png', $png->filename());
		$this->assertSame([2560, 1280], $this->dimensions($png));

		$this->kirby = $this->app(['volkmann-design-code.upload-images.driver' => 'gd', 'volkmann-design-code.upload-images.convert' => ['png' => 'jpg']]);
		$jpg         = $this->upload($this->image(300, 200, 'png'), 'sketch.png');

		$this->assertSame('sketch.jpg', $jpg->filename());
		$this->assertSame('image/jpeg', getimagesize($jpg->root())['mime']);
		$this->assertFalse(file_exists($this->page()->root() . '/sketch.png'));
	}

	public function testAReplacedImageIsShrunkToo(): void
	{
		$file = $this->upload($this->image(800, 600), 'photo.jpg');
		$file = $file->replace($this->image(3200, 2400));

		$this->assertSame([2560, 1920], $this->dimensions($file));
	}

	public function testTooBigForGdIsRefusedBeforeItIsStored(): void
	{
		UploadImages::$memoryLeft = fn () => 10 * 1024 * 1024;

		try {
			$this->upload($this->image(3200, 2400), 'huge.jpg');
			$this->fail('not refused');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('8 megapixels', $e->getMessage());
		}

		$this->assertNull($this->page()->file('huge.jpg'));
	}

	public function testHeicWithoutImageMagickIsRefusedBeforeItIsStored(): void
	{
		try {
			$this->upload($this->fixture('photo.heic'), 'IMG_0002.heic');
			$this->fail('not refused');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('HEIC', $e->getMessage());
		}

		$this->assertNull($this->page()->file('img_0002.heic'));
	}

	public function testGifsAreLeftAlone(): void
	{
		$image  = imagecreatetruecolor(3000, 10);
		$source = $this->root . '/anim.gif';
		imagegif($image, $source);

		$this->assertSame([3000, 10], $this->dimensions($this->upload($source, 'anim.gif')));
	}

	public function testPrepareWorksWithoutKirbysUpload(): void
	{
		$source = $this->fixture('photo-gps.jpg');
		$result = UploadImages::prepare($source, 'IMG_0001.JPG', ['driver' => 'gd', 'maxSize' => 600]);

		$this->assertTrue($result['converted']);
		$this->assertSame('IMG_0001.JPG', $result['filename']);
		$this->assertSame('2026-10-05 09:12:00', $result['metadata']['taken']);
		$this->assertSame([600, 450], $this->dimensions($source));
	}

	public function testFieldsMapOnlyWhatThereIs(): void
	{
		$this->assertSame(
			['date' => '2026-10-05 09:12:00'],
			UploadImages::fields(['taken' => '2026-10-05 09:12:00', 'lat' => null], ['taken' => 'date', 'lat' => 'lat', 'camera' => 'camera']),
		);
	}

	public function testGdWhenImageMagickCantReadTheFormat(): void
	{
		// e.g. a host whose ImageMagick has no JPEG delegate
		UploadImages::$imagickFormats = fn () => ['PNG', 'HEIC'];

		$this->assertSame('gd', UploadImages::driver('photo.jpg'));
		$this->assertSame('gd', UploadImages::driver('logo.png', ['convert' => ['png' => 'jpg']]));
		$this->assertSame('imagick', UploadImages::driver('logo.png'));
		$this->assertSame([2560, 1920], $this->dimensions($this->upload($this->image(3200, 2400), 'big.jpg')));
	}

	public function testHeicStillNeedsImageMagick(): void
	{
		UploadImages::$imagickFormats = fn () => ['HEIC', 'JPEG'];
		$this->assertSame('imagick', UploadImages::driver('IMG_0001.HEIC', UploadImages::defaults()));

		UploadImages::$imagickFormats = fn () => ['JPEG'];
		$this->expectException(InvalidArgumentException::class);
		UploadImages::driver('IMG_0001.HEIC', UploadImages::defaults());
	}
}
