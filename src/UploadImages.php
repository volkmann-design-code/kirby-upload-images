<?php

namespace VolkmannDesignCode\UploadImages;

use Closure;
use Imagick;
use Kirby\Cms\File;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Filesystem\F;
use Kirby\Image\Darkroom\GdLib;

/**
 * Uploaded images made web-ready, in place, right after Kirby stored
 * them (`file.create:after`, `file.replace:after`): HEIC/HEIF converted,
 * shrunk to a long edge, oriented, metadata stripped; what the photo said
 * about itself (capture time, GPS, camera …) kept as file fields first.
 * `file.create:before` refuses what this server can't do, before anything
 * is stored.
 *
 * Imagick when the server has it (any size: ImageMagick works outside
 * PHP's `memory_limit`; HEIC if built with libheif), else Kirby's GD
 * driver within the memory PHP has left.
 */
class UploadImages
{
	public const OPTIONS = 'volkmann-design-code.upload-images';

	/**
	 * Formats it resizes in place; GIF is left alone (animations), SVG
	 * isn't pixels.
	 */
	public const RESIZABLE = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

	/**
	 * Bytes PHP may still allocate; replaceable in tests.
	 */
	public static Closure|null $memoryLeft = null;

	/**
	 * The options for a file: the plugin's, overridden by its blueprint's
	 * `uploadImages` (`true` or a list of options); null when it is off
	 * for this file.
	 */
	public static function options(File $file): array|null
	{
		$kirby   = $file->kirby();
		$options = [];

		foreach (['enabled', 'maxSize', 'quality', 'convert', 'strip', 'fields', 'driver'] as $key) {
			$options[$key] = $kirby->option(static::OPTIONS . '.' . $key);
		}

		$blueprint = $file->blueprint()->toArray();
		$local     = $blueprint['uploadImages'] ?? $blueprint['uploadimages'] ?? null;

		if (is_array($local) === true) {
			$options = [...$options, ...$local, 'enabled' => $local['enabled'] ?? true];
		} elseif (is_bool($local) === true) {
			$options['enabled'] = $local;
		}

		if ($options['enabled'] instanceof Closure) {
			$options['enabled'] = ($options['enabled'])($file);
		}

		return $options['enabled'] === true ? $options : null;
	}

	/**
	 * Whether this file type is one it works on.
	 */
	public static function handles(string $filename, array $options): bool
	{
		$extension = strtolower(F::extension($filename));

		return in_array($extension, static::RESIZABLE, true) || array_key_exists($extension, $options['convert'] ?? []);
	}

	/**
	 * `file.create:before`: refuses an image this server can't convert,
	 * before Kirby stores it.
	 *
	 * @throws \Kirby\Exception\InvalidArgumentException
	 */
	public static function check(File $file, string $source): void
	{
		$options = static::options($file);

		if ($options === null || static::handles($file->filename(), $options) === false) {
			return;
		}

		if (static::driver($file->filename(), $options) === 'gd') {
			static::guardMemory($source);
		}
	}

	/**
	 * `file.create:after`, `file.replace:after`: the stored file
	 * converted in place, renamed to its new format, the metadata in its
	 * fields.
	 */
	public static function process(File $file): File
	{
		$options = static::options($file);

		if ($options === null || static::handles($file->filename(), $options) === false) {
			return $file;
		}

		$result = static::prepare($file->root(), $file->filename(), $options);
		$fields = static::fields($result['metadata'], $options['fields'] ?? []);

		return $file->kirby()->impersonate('kirby', function () use ($file, $result, $fields) {
			if ($result['filename'] !== $file->filename()) {
				$file = $file->changeName(F::name(static::freeName($file, $result['filename'])), false, F::extension($result['filename']));
			}

			return $fields !== [] ? $file->update($fields) : $file;
		});
	}

	/**
	 * Converts an image file in place; also usable without Kirby's upload,
	 * e.g. before `File::create()` in your own route.
	 *
	 * @param array $options as the plugin's options (`maxSize`, `quality`,
	 *                       `convert`, `strip`, `driver`)
	 * @return array{filename: string, metadata: array, converted: bool}
	 *                                                                   the file name for its new format and what it said about itself
	 * @throws \Kirby\Exception\InvalidArgumentException what this server can't convert
	 */
	public static function prepare(string $root, string $filename, array $options = []): array
	{
		$options = [...static::defaults(), ...$options];
		$meta    = Metadata::read($root, $filename);

		if (static::handles($filename, $options) === false) {
			return ['filename' => $filename, 'metadata' => $meta, 'converted' => false];
		}

		$extension = strtolower(F::extension($filename));
		$format    = $options['convert'][$extension] ?? null;
		$long      = max($meta['width'] ?? 0, $meta['height'] ?? 0);
		$shrink    = $options['maxSize'] !== null && $long > $options['maxSize'];

		if ($format === null && $shrink === false && $options['strip'] !== true) {
			return ['filename' => $filename, 'metadata' => $meta, 'converted' => false];
		}

		$thumb = [
			'width'   => $options['maxSize'],
			'height'  => $options['maxSize'],
			'quality' => $options['quality'],
			'format'  => $format,
			'strip'   => $options['strip'],
		];

		if (static::driver($filename, $options) === 'imagick') {
			(new ImagickDarkroom())->process($root, $thumb);
		} else {
			static::guardMemory($root);
			(new GdLib())->process($root, $thumb);
		}

		return [
			'filename'  => $format !== null ? F::name($filename) . '.' . $format : $filename,
			'metadata'  => $meta,
			'converted' => true,
		];
	}

	public static function defaults(): array
	{
		return [
			'maxSize' => 2560,
			'quality' => 85,
			'convert' => ['heic' => 'jpg', 'heif' => 'jpg'],
			'strip'   => true,
			'fields'  => ['taken' => 'taken', 'camera' => 'camera'],
			'driver'  => null,
		];
	}

	/**
	 * The metadata as file fields: `['taken' => 'date']` writes the
	 * capture time into the field `date`; empty values are left out.
	 */
	public static function fields(array $metadata, array $map): array
	{
		$fields = [];

		foreach ($map as $key => $field) {
			if (is_string($field) === true && $field !== '' && ($metadata[$key] ?? null) !== null) {
				$fields[$field] = (string)$metadata[$key];
			}
		}

		return $fields;
	}

	/**
	 * `imagick` or `gd`: the option, else Imagick when the server has it.
	 * HEIC/HEIF need ImageMagick built with libheif.
	 *
	 * @throws \Kirby\Exception\InvalidArgumentException
	 */
	public static function driver(string $filename, array $options = []): string
	{
		$heic   = in_array(strtolower(F::extension($filename)), ['heic', 'heif'], true);
		$driver = $options['driver'] ?? (static::imagick() ? 'imagick' : 'gd');

		if ($heic === true && ($driver !== 'imagick' || static::imagick(heic: true) === false)) {
			throw new InvalidArgumentException(key: 'volkmann-design-code.upload-images.heic', fallback: 'This server can\'t convert HEIC images');
		}

		return $driver;
	}

	public static function imagick(bool $heic = false): bool
	{
		if (class_exists(Imagick::class) === false) {
			return false;
		}

		return $heic === false || Imagick::queryFormats('HEI*') !== [];
	}

	/**
	 * GD decodes the whole image into PHP's memory (4 bytes a pixel, the
	 * result on top): refused when it wouldn't fit, rather than a fatal
	 * error halfway through.
	 *
	 * @throws \Kirby\Exception\InvalidArgumentException
	 */
	public static function guardMemory(string $root): void
	{
		$size   = @getimagesize($root);
		$pixels = ($size[0] ?? 0) * ($size[1] ?? 0);

		if ($pixels * 4 * 1.8 > static::memoryLeft()) {
			throw new InvalidArgumentException(
				key: 'volkmann-design-code.upload-images.tooLarge',
				data: ['megapixels' => round($pixels / 1000000)],
				fallback: 'The image is too large for this server',
			);
		}
	}

	protected static function memoryLeft(): int
	{
		if (static::$memoryLeft !== null) {
			return (static::$memoryLeft)();
		}

		$limit = (string)ini_get('memory_limit');

		if ($limit === '' || $limit === '-1') {
			return PHP_INT_MAX;
		}

		$bytes = (int)$limit * match (strtolower(substr($limit, -1))) {
			'g'     => 1024 ** 3,
			'm'     => 1024 ** 2,
			'k'     => 1024,
			default => 1,
		};

		return $bytes - memory_get_usage();
	}

	/**
	 * A file name not taken beside the file yet (`photo.jpg`, `photo-1.jpg`).
	 */
	protected static function freeName(File $file, string $filename): string
	{
		$name = $filename;

		for ($i = 1; ($other = $file->parent()->file($name)) !== null && $other->is($file) === false; $i++) {
			$name = F::name($filename) . '-' . $i . '.' . F::extension($filename);
		}

		return $name;
	}
}
