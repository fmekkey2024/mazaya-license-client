#!/bin/bash
# Build a distributable, per-product Agent artifact:
#   git-archive <ref>  →  strip comments/whitespace from src/ (free obfuscation)
#   →  inject version  →  sign the manifest over the stripped files  →  zip.
# The source stays readable; only the shipped artifact is obfuscated.
# Usage: build/build.sh <product-slug> <git-ref> <version>
set -e
PROD="$1"; REF="$2"; VER="$3"
PKG=/var/www/packages/mazaya-license-client
SRV=/var/www/mazaya-license
OUTDIR=/var/www/packages/artifacts
BUILD=$(mktemp -d)
git -C "$PKG" archive --format=tar "$REF" | tar -x -C "$BUILD"
# 1) obfuscate: strip comments + whitespace from every src/ .php (behaviour-preserving)
php -r '$d=$argv[1];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d."/src",FilesystemIterator::SKIP_DOTS));$n=0;foreach($it as $f){if($f->isFile()&&$f->getExtension()==="php"){file_put_contents($f->getPathname(),php_strip_whitespace($f->getPathname()));$n++;}}fwrite(STDERR,"  stripped $n src files\n");' "$BUILD"
# 2) inject version
php -r '$p=$argv[1];$s=file_get_contents($p);if(strpos($s,"\"version\"")===false){$s=preg_replace("#(\"name\":\s*\"mazaya/license-client\",)#","$1\n    \"version\": \"".$argv[2]."\",",$s,1);}file_put_contents($p,$s);' "$BUILD/composer.json" "$VER"
# 3) sign the manifest over the STRIPPED files
chmod -R a+rX "$BUILD"
MOUT=$(mktemp -u /tmp/mlc-manifest-XXXXXX.mlic)
sudo -u mlicense php "$SRV/artisan" license:sign-agent-manifest "$PROD" "$BUILD" --out="$MOUT"
cp "$MOUT" "$BUILD/agent-manifest.mlic"; rm -f "$MOUT"
# 4) zip
OUT="$OUTDIR/mazaya-license-client-$VER-obf-$PROD.zip"
( cd "$BUILD" && rm -f "$OUT" && zip -r -q -X "$OUT" . )
chown --reference="$OUTDIR/mazaya-license-client-1.8.0.zip" "$OUT"
rm -rf "$BUILD"
echo "built: $OUT"
