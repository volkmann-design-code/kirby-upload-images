<?php

use Kirby\Cms\File;
use Kirby\Filesystem\F;
use VolkmannDesignCode\UploadImages\UploadImages;

// for ZIP and submodule installs; Composer autoloads them
F::loadClasses([
	'VolkmannDesignCode\\UploadImages\\UploadImages'   => 'src/UploadImages.php',
	'VolkmannDesignCode\\UploadImages\\Metadata'       => 'src/Metadata.php',
	'VolkmannDesignCode\\UploadImages\\ImagickDarkroom' => 'src/ImagickDarkroom.php',
], __DIR__);

Kirby::plugin(
	name: 'volkmann-design-code/upload-images',
	extends: [
		'options' => [
			// off until a file blueprint says `uploadImages: true` (or a list
			// of options); true for every image upload; or fn (File $file): bool
			'enabled' => false,
			// the long edge in pixels; null keeps the size
			'maxSize' => 2560,
			'quality' => 85,
			// extension => format; others keep theirs (e.g. add 'png' => 'webp')
			'convert' => ['heic' => 'jpg', 'heif' => 'jpg'],
			// removes EXIF & co. (the colour profile stays with Imagick)
			'strip'   => true,
			// metadata => field: written into the file's content before stripping
			'fields'  => ['taken' => 'taken', 'camera' => 'camera'],
			// null: Imagick when installed and its ImageMagick knows the formats, else GD; or 'imagick', 'gd'
			'driver'  => null,
		],
		'hooks' => [
			'file.create:before' => function (File $file, $upload) {
				UploadImages::check($file, $upload->root());
			},
			'file.create:after' => function (File $file) {
				return UploadImages::process($file);
			},
			'file.replace:after' => function (File $newFile) {
				return UploadImages::process($newFile);
			},
		],
		'translations' => [
			'en' => [
				'error.volkmann-design-code.upload-images.heic'     => 'This server can\'t convert HEIC images. Please upload a JPEG.',
				'error.volkmann-design-code.upload-images.tooLarge' => 'At {megapixels} megapixels the image is too large for this server. Please upload a smaller one.',
			],
			'de' => [
				'error.volkmann-design-code.upload-images.heic'     => 'HEIC-Bilder kann dieser Server nicht umwandeln. Bitte ein JPEG hochladen.',
				'error.volkmann-design-code.upload-images.tooLarge' => 'Das Bild ist mit {megapixels} Megapixeln zu groß für diesen Server. Bitte ein kleineres hochladen.',
			],
		],
	]
);
