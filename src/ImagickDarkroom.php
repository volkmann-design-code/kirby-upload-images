<?php

namespace VolkmannDesignCode\UploadImages;

use Imagick as Image;
use Kirby\Image\Darkroom\Imagick;

/**
 * Kirby's Imagick driver for originals instead of thumbs: oriented, fit
 * into a square box (the long edge), the first image only, every profile
 * but the colour one stripped (Kirby's `strip()`), written in place in
 * the target format.
 *
 * What differs from Kirby's own: the size comes from ImageMagick
 * (`getimagesize()` knows no HEIC before PHP 8.5), only the primary image
 * is kept (a HEIC may hold a depth map or thumbnail), and the format is
 * named when writing (ImageMagick otherwise takes it from the file name,
 * e.g. `.heic`). ImageMagick allocates outside PHP's `memory_limit`.
 */
class ImagickDarkroom extends Imagick
{
	public function preprocess(string $file, array $options = []): array
	{
		$options = $this->options($options);
		$ping    = new Image();
		$ping->pingImage($file);

		$width  = $ping->getImageWidth();
		$height = $ping->getImageHeight();
		$long   = max($width, $height);
		// a square box fits portrait and landscape alike, whatever the
		// orientation flag says; never larger than the image
		$box = min($options['width'] ?? $long, $long);

		return [
			...$options,
			'sourceWidth'  => $width,
			'sourceHeight' => $height,
			'width'        => $box,
			'height'       => $box,
			'crop'         => false,
		];
	}

	protected function coalesce(Image $image): Image
	{
		$image->setIteratorIndex(0);

		return $image->getImage();
	}

	/**
	 * Kirby's strip (all but the colour profile), unless `strip` is false.
	 */
	protected function strip(Image $image, array $options): Image
	{
		return ($options['strip'] ?? true) === false ? $image : parent::strip($image, $options);
	}

	protected function save(Image $image, string $file, array $options): bool
	{
		$format = match ($options['format'] ?? null) {
			'jpg', 'jpeg' => 'jpeg',
			null          => strtolower($image->getImageFormat()),
			default       => $options['format'],
		};

		// JPEG has no transparency: on white, not black
		if ($format === 'jpeg' && $image->getImageAlphaChannel() === true) {
			$image->setImageBackgroundColor('white');
			$image = $image->mergeImageLayers(Image::LAYERMETHOD_FLATTEN);
		}

		$image->setImageFormat($format);

		return $image->writeImage($format . ':' . $file);
	}
}
