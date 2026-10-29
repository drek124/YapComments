<?php

/**
 * Builds the installable package: php build.php
 *
 * Output: dist/pkg_yapcomments_v<version>.zip (component + content plugin)
 *         updates/pkg_yapcomments.xml (Joomla update feed for that zip)
 *
 * @package     YapComments
 * @author      drek124 <https://github.com/drek124/>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root    = __DIR__;
$src     = $root . '/src';
$dist    = $root . '/dist';
$version = (string) simplexml_load_file($src . '/pkg_yapcomments.xml')->version;

/**
 * Zip a directory (recursively) into $zipPath.
 */
function zipDir(string $dir, string $zipPath, array $extra = []): void
{
    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create ' . $zipPath);
    }

    if ($dir !== '') {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            $local = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            $zip->addFile($file->getPathname(), $local);
        }
    }

    foreach ($extra as $local => $path) {
        $zip->addFile($path, $local);
    }

    $zip->close();
}

@mkdir($dist, 0755, true);
$tmp = $dist . '/tmp';
@mkdir($tmp, 0755, true);

zipDir($src . '/com_yapcomments', $tmp . '/com_yapcomments.zip');
zipDir($src . '/plg_content_yapcomments', $tmp . '/plg_content_yapcomments.zip');

$package = $dist . '/pkg_yapcomments_v' . $version . '.zip';

zipDir('', $package, [
    'pkg_yapcomments.xml'                       => $src . '/pkg_yapcomments.xml',
    'pkg_script.php'                            => $src . '/pkg_script.php',
    'packages/com_yapcomments.zip'              => $tmp . '/com_yapcomments.zip',
    'packages/plg_content_yapcomments.zip'      => $tmp . '/plg_content_yapcomments.zip',
    'language/en-GB/pkg_yapcomments.sys.ini'    => $src . '/language/en-GB/pkg_yapcomments.sys.ini',
    'language/ar-AA/pkg_yapcomments.sys.ini'    => $src . '/language/ar-AA/pkg_yapcomments.sys.ini',
]);

foreach (glob($tmp . '/*') as $file) {
    unlink($file);
}

rmdir($tmp);

echo 'Built ' . $package . ' (' . round(filesize($package) / 1024, 1) . " KB)\n";

/*
 * Update feed read by Joomla's updater (see <updateservers> in pkg_yapcomments.xml).
 * The sha512 must match the zip attached to the release, so the release workflow
 * regenerates this from the zip it uploads and commits it to main.
 */
$repo    = 'https://github.com/drek124/YapComments';
$feed    = $root . '/updates/pkg_yapcomments.xml';
$file    = basename($package);
$sha512  = hash_file('sha512', $package);

@mkdir(dirname($feed), 0755, true);

file_put_contents($feed, <<<XML
<?xml version="1.0" encoding="utf-8"?>
<updates>
    <update>
        <name>YapComments</name>
        <description>YapComments package</description>
        <element>pkg_yapcomments</element>
        <type>package</type>
        <version>{$version}</version>
        <client>site</client>
        <infourl title="YapComments {$version}">{$repo}/releases/tag/v{$version}</infourl>
        <downloads>
            <downloadurl type="full" format="zip">{$repo}/releases/download/v{$version}/{$file}</downloadurl>
        </downloads>
        <sha512>{$sha512}</sha512>
        <targetplatform name="joomla" version="(5|6)\..*"/>
        <php_minimum>8.1</php_minimum>
        <maintainer>drek124</maintainer>
        <maintainerurl>https://github.com/drek124/</maintainerurl>
    </update>
</updates>

XML);

echo 'Wrote ' . $feed . "\n";
