<?php

/**
 * The Kirby site for the cover images (`../cover.mjs`): one page with the
 * repository's iPhone photo (`tests/fixtures/iphone.heic`), uploaded
 * through Kirby with this plugin, English Panel. Kirby is the repository's
 * dev dependency (`kirby/`); content, accounts and sessions live in
 * `COVER_DATA`, a temp folder, so every run starts fresh. Converting HEIC
 * takes Imagick with libheif (cover.mjs uses Docker if PHP has none).
 *
 *   php index.php seed   creates the account and uploads the photo,
 *                        prints the photo before and after as JSON
 */

use Kirby\Cms\File;

$repo = dirname(__DIR__, 3);
$data = getenv('COVER_DATA') ?: exit("COVER_DATA is not set\n");

require $repo . '/vendor/autoload.php';

$kirby = new Kirby([
	'roots' => [
		'index'      => __DIR__,
		'kirby'      => $repo . '/kirby',
		'blueprints' => __DIR__ . '/blueprints',
		'plugins'    => $data . '/plugins',
		'content'    => $data . '/content',
		'accounts'   => $data . '/accounts',
		'sessions'   => $data . '/sessions',
		'cache'      => $data . '/cache',
	],
	'options' => [
		'debug' => true,
		'panel' => ['language' => 'en'],
		// in Docker, Kirby would build URLs from the server's 0.0.0.0
		...(getenv('COVER_URL') ? ['url' => getenv('COVER_URL')] : []),
	],
]);

if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === 'seed') {
	$kirby->impersonate('kirby');
	$kirby->users()->create(['email' => 'mara@example.com', 'name' => 'Mara Lind', 'role' => 'admin', 'password' => 'cover-password']);

	$page = $kirby->site()->createChild([
		'slug'     => 'photos',
		'template' => 'photos',
		'isDraft'  => false,
		'content'  => ['title' => 'Photos'],
	]);

	$heic   = $repo . '/tests/fixtures/iphone.heic';
	$source = $data . '/IMG_1234.HEIC';
	copy($heic, $source);
	$before = ['name' => 'IMG_1234.HEIC', 'size' => filesize($heic), 'width' => 4032, 'height' => 3024];

	$file = File::create([
		'source'   => $source,
		'parent'   => $page,
		'filename' => 'IMG_1234.HEIC',
		'template' => 'photo',
	]);

	[$width, $height] = getimagesize($file->root());

	echo json_encode([
		'before' => $before,
		'after'  => ['name' => $file->filename(), 'size' => $file->size(), 'width' => $width, 'height' => $height],
		'path'   => 'pages/photos/files/' . $file->filename(),
	]), "\n";

	return;
}

echo $kirby->render();
