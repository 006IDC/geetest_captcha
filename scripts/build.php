#!/usr/bin/env php
<?php

declare(strict_types=1);

const PLUGIN_SLUG = 'geetest_captcha';
const PLUGIN_NAME = 'GeetestCaptcha';
const VERSION = '1.3.0';
const RUNTIME_FILES = [
    'GeetestCaptcha.php',
    'README.md',
    'config.php',
    'logic/GeetestCaptchaLogic.php',
    'service/GeetestValidator.php',
];

function buildFail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function removeTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            if (!rmdir($item->getPathname())) {
                buildFail('无法清理临时目录');
            }
        } elseif (!unlink($item->getPathname())) {
            buildFail('无法清理临时文件');
        }
    }
    if (!rmdir($path)) {
        buildFail('无法清理临时根目录');
    }
}

if (!class_exists(ZipArchive::class)) {
    buildFail('缺少 PHP zip 扩展');
}

$repositoryRoot = dirname(__DIR__);
$pluginRoot = $repositoryRoot . '/' . PLUGIN_SLUG;
$entry = $pluginRoot . '/' . PLUGIN_NAME . '.php';
if (!is_file($entry)) {
    buildFail('插件入口不存在');
}
$entrySource = (string) file_get_contents($entry);
if (preg_match("/['\"]version['\"]\\s*=>\\s*['\"]" . preg_quote(VERSION, '/') . "['\"]/", $entrySource) !== 1) {
    buildFail('构建版本与入口版本不一致');
}

$actualFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pluginRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $item) {
    if ($item->isLink()) {
        buildFail('插件目录禁止包含符号链接');
    }
    if (!$item->isFile()) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($pluginRoot) + 1));
    if ($relative === '' || strpos($relative, '../') !== false || strpos($relative, "\0") !== false) {
        buildFail('插件目录包含不安全路径');
    }
    $actualFiles[] = $relative;
}
sort($actualFiles, SORT_STRING);
$expectedFiles = RUNTIME_FILES;
sort($expectedFiles, SORT_STRING);
if ($actualFiles !== $expectedFiles) {
    buildFail('运行文件白名单不匹配：' . implode(', ', $actualFiles));
}

$dist = $repositoryRoot . '/dist';
if (!is_dir($dist) && !mkdir($dist, 0755, true) && !is_dir($dist)) {
    buildFail('无法创建 dist 目录');
}
$output = $dist . '/' . PLUGIN_SLUG . '_v' . VERSION . '_zjmf_v10_import.zip';
$checksumOutput = $dist . '/' . PLUGIN_SLUG . '_v' . VERSION . '_SHA256SUMS.txt';
$temporaryRoot = sys_get_temp_dir() . '/geetest-captcha-build-' . bin2hex(random_bytes(8));
if (!mkdir($temporaryRoot, 0700, true)) {
    buildFail('无法创建临时构建目录');
}

try {
    $stagePlugin = $temporaryRoot . '/' . PLUGIN_SLUG;
    foreach (RUNTIME_FILES as $relative) {
        $source = $pluginRoot . '/' . $relative;
        $target = $stagePlugin . '/' . $relative;
        $targetDirectory = dirname($target);
        if (!is_dir($targetDirectory)
            && !mkdir($targetDirectory, 0755, true)
            && !is_dir($targetDirectory)
        ) {
            buildFail('无法创建构建目录');
        }
        $bytes = file_get_contents($source);
        if (!is_string($bytes)) {
            buildFail('无法读取运行文件：' . $relative);
        }
        $bytes = str_replace(["\r\n", "\r"], "\n", $bytes);
        if (file_put_contents($target, $bytes, LOCK_EX) === false) {
            buildFail('无法写入构建文件：' . $relative);
        }
        touch($target, 946684800);
    }

    $zip = new ZipArchive();
    if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        buildFail('无法创建安装包');
    }
    $directories = [PLUGIN_SLUG . '/', PLUGIN_SLUG . '/logic/', PLUGIN_SLUG . '/service/'];
    foreach ($directories as $directory) {
        $directoryName = rtrim($directory, '/');
        $zip->addEmptyDir($directoryName);
        if (method_exists($zip, 'setMtimeName')) {
            $zip->setMtimeName($directoryName . '/', 946684800);
        }
    }
    foreach (RUNTIME_FILES as $relative) {
        $archivePath = PLUGIN_SLUG . '/' . $relative;
        if (!$zip->addFile($stagePlugin . '/' . $relative, $archivePath)) {
            $zip->close();
            buildFail('无法添加安装包文件：' . $relative);
        }
        if (method_exists($zip, 'setMtimeName')) {
            $zip->setMtimeName($archivePath, 946684800);
        }
    }
    $zip->close();
    chmod($output, 0644);
} finally {
    removeTree($temporaryRoot);
}

$packageHash = hash_file('sha256', $output);
if (!is_string($packageHash)
    || file_put_contents(
        $checksumOutput,
        $packageHash . '  ' . basename($output) . "\n",
        LOCK_EX
    ) === false
) {
    buildFail('无法写入 SHA256SUMS.txt');
}
chmod($checksumOutput, 0644);

fwrite(STDOUT, $output . "\nSHA-256: " . $packageHash . "\n");
