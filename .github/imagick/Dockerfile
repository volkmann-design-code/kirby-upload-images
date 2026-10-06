# PHP with Imagick, GD and exif on Debian 12 (ImageMagick 6.9.11 with
# HEIC through libheif, as on many shared hosts), for running the Imagick
# tests locally:
#
#   docker build -t kirby-upload-images-imagick .github/imagick
#   docker run --rm -v "$PWD":/repo -w /repo kirby-upload-images-imagick vendor/bin/phpunit
FROM php:8.4-cli-bookworm
RUN apt-get update \
	&& apt-get install -y --no-install-recommends libmagickwand-dev libheif-dev imagemagick libjpeg-dev libpng-dev libwebp-dev \
	&& pecl install imagick \
	&& docker-php-ext-enable imagick \
	&& docker-php-ext-configure gd --with-jpeg --with-webp \
	&& docker-php-ext-install exif gd \
	&& rm -rf /var/lib/apt/lists/*
