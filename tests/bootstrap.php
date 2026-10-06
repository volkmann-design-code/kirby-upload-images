<?php

/**
 * Kirby (a dev dependency, installed into `kirby/`) with this plugin only
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../index.php';

// no error page handler in tests, as in Kirby's own test suite
Kirby\Cms\App::$enableWhoops = false;

require __DIR__ . '/TestCase.php';
