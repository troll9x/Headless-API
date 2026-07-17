<?php
declare(strict_types=1);

$source = $argv[1] ?? '';
$target = $argv[2] ?? '';
if ( ! is_dir( $source ) || '' === $target ) {
	fwrite( STDERR, "Usage: php build-release-zip.php <staged-plugin-dir> <zip-path>\n" );
	exit( 2 );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $target, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Cannot open ZIP target\n" );
	exit( 3 );
}

$zip->addEmptyDir( 'headless-api' );
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);
foreach ( $iterator as $item ) {
	$relative = substr( $item->getPathname(), strlen( $source ) + 1 );
	$name = 'headless-api/' . str_replace( '\\', '/', $relative );
	$item->isDir() ? $zip->addEmptyDir( $name ) : $zip->addFile( $item->getPathname(), $name );
}
$zip->close();

echo "BUILD_RELEASE_ZIP PASS\n";
