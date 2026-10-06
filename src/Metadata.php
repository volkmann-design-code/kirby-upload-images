<?php

namespace VolkmannDesignCode\UploadImages;

use Imagick;
use Kirby\Image\Image;
use Throwable;

/**
 * What a photo says about itself, read before it is converted and its
 * metadata stripped: PHP's exif (JPEG), else ImageMagick's properties
 * (HEIC: PHP reads neither EXIF nor size from an iPhone's, 8.5 included).
 *
 * Every value is null when the photo doesn't have it.
 */
class Metadata
{
	public const KEYS = [
		'taken', 'lat', 'lng', 'location', 'altitude',
		'camera', 'lens', 'iso', 'aperture', 'exposure', 'focalLength',
		'width', 'height', 'filename',
	];

	/**
	 * @return array<string, string|int|float|null> keyed by `KEYS`
	 */
	public static function read(string $root, string|null $filename = null): array
	{
		$tags = static::exif($root);

		if ($tags === [] && class_exists(Imagick::class) === true) {
			$tags = static::imagick($root);
		}

		$lat = static::degrees($tags['GPSLatitude'] ?? null, $tags['GPSLatitudeRef'] ?? null);
		$lng = static::degrees($tags['GPSLongitude'] ?? null, $tags['GPSLongitudeRef'] ?? null);

		// 0,0 is what some cameras write without a fix
		if ($lat === null || $lng === null || ($lat == 0 && $lng == 0)) {
			$lat = $lng = null;
		}

		[$width, $height] = static::size($root);

		return [
			'taken'       => static::date($tags['DateTimeOriginal'] ?? $tags['DateTimeDigitized'] ?? null),
			'lat'         => $lat !== null ? round($lat, 6) : null,
			'lng'         => $lng !== null ? round($lng, 6) : null,
			'location'    => $lat !== null ? round($lat, 6) . ', ' . round($lng, 6) : null,
			'altitude'    => static::number($tags['GPSAltitude'] ?? null) !== null ? round(static::number($tags['GPSAltitude']), 1) : null,
			'camera'      => static::camera($tags['Make'] ?? null, $tags['Model'] ?? null),
			'lens'        => static::text($tags['UndefinedTag:0xA434'] ?? $tags['LensModel'] ?? null),
			'iso'         => is_numeric($iso = is_array($tags['ISOSpeedRatings'] ?? null) ? $tags['ISOSpeedRatings'][0] : ($tags['ISOSpeedRatings'] ?? $tags['PhotographicSensitivity'] ?? null)) ? (int)$iso : null,
			'aperture'    => ($f = static::number($tags['FNumber'] ?? null)) !== null ? 'f/' . round($f, 1) : null,
			'exposure'    => static::exposure($tags['ExposureTime'] ?? null),
			'focalLength' => ($mm = static::number($tags['FocalLength'] ?? null)) !== null ? round($mm, 1) . ' mm' : null,
			'width'       => $width,
			'height'      => $height,
			'filename'    => $filename,
		];
	}

	/**
	 * PHP's exif, flattened; [] without the extension or readable EXIF.
	 */
	protected static function exif(string $root): array
	{
		if (function_exists('exif_read_data') === false) {
			return [];
		}

		try {
			$data = @exif_read_data($root);
		} catch (Throwable) {
			return [];
		}

		return is_array($data) === true ? $data : [];
	}

	/**
	 * ImageMagick's `exif:*` properties, named like PHP's exif.
	 */
	protected static function imagick(string $root): array
	{
		try {
			$image = new Imagick();
			$image->pingImage($root);
			$tags = [];

			foreach ($image->getImageProperties('exif:*') as $name => $value) {
				$tags[substr($name, 5)] = $value;
			}

			// "51/1, 18/1, 4662/100" as PHP's exif has it: a list
			foreach (['GPSLatitude', 'GPSLongitude'] as $name) {
				if (isset($tags[$name]) === true) {
					$tags[$name] = array_map('trim', explode(',', $tags[$name]));
				}
			}

			return $tags;
		} catch (Throwable) {
			return [];
		}
	}

	/**
	 * Width and height as shown (EXIF orientation applied).
	 */
	protected static function size(string $root): array
	{
		try {
			$image = new Image($root);
			$size  = $image->dimensions();

			if ($size->width() > 0) {
				return [$size->width(), $size->height()];
			}
		} catch (Throwable) {
			// HEIC before PHP 8.5
		}

		if (class_exists(Imagick::class) === true) {
			try {
				$image = new Imagick();
				$image->pingImage($root);
				$turned = in_array($image->getImageOrientation(), [5, 6, 7, 8], true);
				$width  = $image->getImageWidth();
				$height = $image->getImageHeight();

				return $turned ? [$height, $width] : [$width, $height];
			} catch (Throwable) {
				// not an image ImageMagick knows
			}
		}

		return [null, null];
	}

	/**
	 * `[51/1, 18/1, 4662/100]` and "N" → 51.31295.
	 */
	public static function degrees(mixed $value, mixed $ref): float|null
	{
		if (is_string($value) === true) {
			$value = explode(',', $value);
		}

		if (is_array($value) === false || count($value) !== 3) {
			return null;
		}

		[$d, $m, $s] = array_map(fn ($part) => static::number($part) ?? 0.0, array_values($value));
		$degrees     = $d + $m / 60 + $s / 3600;

		return in_array(strtoupper(trim((string)$ref)), ['S', 'W'], true) ? -$degrees : $degrees;
	}

	/**
	 * "4662/100" → 46.62, "8" → 8.0
	 */
	public static function number(mixed $value): float|null
	{
		if (is_int($value) === true || is_float($value) === true) {
			return (float)$value;
		}

		if (is_string($value) === false || $value === '') {
			return null;
		}

		$parts = explode('/', trim($value));

		if (is_numeric($parts[0]) === false || (isset($parts[1]) === true && (is_numeric($parts[1]) === false || (float)$parts[1] === 0.0))) {
			return null;
		}

		return (float)$parts[0] / (float)($parts[1] ?? 1);
	}

	protected static function date(mixed $value): string|null
	{
		if (is_string($value) === false) {
			return null;
		}

		$date = date_create_from_format('Y:m:d H:i:s', trim($value));

		return $date !== false ? $date->format('Y-m-d H:i:s') : null;
	}

	/**
	 * "Apple" + "iPhone 15 Pro" → "Apple iPhone 15 Pro"; a model that
	 * names its make already ("Canon EOS R6") stays as it is.
	 */
	protected static function camera(mixed $make, mixed $model): string|null
	{
		$make  = static::text($make);
		$model = static::text($model);

		if ($model !== null && $make !== null && stripos($model, $make) === 0) {
			return $model;
		}

		return static::text(trim(($make ?? '') . ' ' . ($model ?? '')));
	}

	protected static function exposure(mixed $value): string|null
	{
		$seconds = static::number($value);

		if ($seconds === null || $seconds <= 0) {
			return null;
		}

		return $seconds >= 1 ? round($seconds, 1) . ' s' : '1/' . round(1 / $seconds) . ' s';
	}

	protected static function text(mixed $value): string|null
	{
		if (is_string($value) === false) {
			return null;
		}

		$value = trim(str_replace("\0", '', $value));

		return $value !== '' ? $value : null;
	}
}
