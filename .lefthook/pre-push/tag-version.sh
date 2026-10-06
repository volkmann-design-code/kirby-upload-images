#!/bin/sh
# A tag pushed must match "version" in composer.json at its commit;
# Packagist ignores it otherwise ("version mismatch"). Git passes the
# refs being pushed on stdin: <local ref> <sha> <remote ref> <remote sha>;
# a deletion has an all-zero sha.

while read -r ref sha _; do
	case "$ref" in refs/tags/*) ;; *) continue ;; esac
	case "$sha" in *[!0]*) ;; *) continue ;; esac

	tag=${ref#refs/tags/}
	version=$(git show "$sha:composer.json" | php -r 'echo json_decode(stream_get_contents(STDIN))->version ?? "";')

	if [ "$tag" != "$version" ]; then
		echo "tag $tag, but composer.json at it says \"$version\": set the version, commit, tag again"
		exit 1
	fi
done
