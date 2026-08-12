#!/usr/bin/env php
<?php

declare(strict_types=1);

function checkFail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function runCommand(array $command, string $workingDirectory): void
{
    $parts = array_map('escapeshellarg', $command);
    $descriptor = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(implode(' ', $parts), $descriptor, $pipes, $workingDirectory);
    if (!is_resource($process)) {
        checkFail('无法启动命令：' . implode(' ', $command));
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if (is_string($stdout) && $stdout !== '') {
        fwrite(STDOUT, $stdout);
    }
    if (is_string($stderr) && $stderr !== '') {
        fwrite(STDERR, $stderr);
    }
    if ($status !== 0) {
        checkFail('命令失败：' . implode(' ', $command));
    }
}

$root = dirname(__DIR__);
$phpFiles = [];
foreach (['geetest_captcha', 'tests', 'scripts'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if ($item->isFile() && strtolower($item->getExtension()) === 'php') {
            $phpFiles[] = $item->getPathname();
        }
    }
}
sort($phpFiles, SORT_STRING);
foreach ($phpFiles as $file) {
    runCommand([PHP_BINARY, '-l', $file], $root);
}

runCommand([PHP_BINARY, 'tests/security_contract.php'], $root);
runCommand([PHP_BINARY, 'tests/render_contract.php'], $root);
runCommand([PHP_BINARY, 'scripts/secret_scan.php'], $root);
runCommand([PHP_BINARY, 'scripts/build.php'], $root);

$package = $root . '/dist/geetest_captcha_v1.3.0_zjmf_v10_import.zip';
if (!class_exists(ZipArchive::class)) {
    checkFail('缺少 PHP zip 扩展');
}
$zip = new ZipArchive();
if ($zip->open($package) !== true) {
    checkFail('无法打开普通安装包');
}
$entries = [];
$allowedCredentialFixtures = [
    'examplecaptchaidexamplecaptchaid',
    'examplekeyexamplekeyexamplekeyex',
];
$forbiddenFragments = [
    chr(48) . chr(48) . chr(54),
    implode('', ['i', 'd', 'c']),
    implode('', ['zero', 'zero', 'six']),
    implode('-', ['zero', 'zero', 'six']),
    implode('_', ['zero', 'zero', 'six']),
    (string) json_decode('"\\u96f6\\u96f6\\u9646"'),
    (string) json_decode('"\\u96f6\\u96f6\\u516d"'),
];
for ($index = 0; $index < $zip->numFiles; $index++) {
    $name = $zip->getNameIndex($index);
    if (!is_string($name) || $name === '') {
        $zip->close();
        checkFail('安装包存在空路径');
    }
    if ($name[0] === '/' || strpos($name, '../') !== false || strpos($name, '\\') !== false) {
        $zip->close();
        checkFail('安装包存在不安全路径');
    }
    $entries[] = $name;
    if (substr($name, -1) === '/') {
        continue;
    }
    $bytes = $zip->getFromIndex($index);
    if (!is_string($bytes)) {
        $zip->close();
        checkFail('无法读取安装包文件：' . $name);
    }
    $sourcePath = $root . '/' . $name;
    $sourceBytes = is_file($sourcePath) ? file_get_contents($sourcePath) : false;
    if (!is_string($sourceBytes)
        || str_replace(["\r\n", "\r"], "\n", $sourceBytes) !== $bytes
    ) {
        $zip->close();
        checkFail('安装包文件与公开运行源码不一致：' . $name);
    }
    foreach ($forbiddenFragments as $fragment) {
        if ($fragment !== '' && stripos($bytes, $fragment) !== false) {
            $zip->close();
            checkFail('安装包包含历史发布者标识：' . $name);
        }
    }
    if (preg_match('/BEGIN (?:OPENSSH |RSA |EC |DSA )?PRIVATE KEY/', $bytes) === 1) {
        $zip->close();
        checkFail('安装包包含私钥：' . $name);
    }
    if (preg_match_all('/\b[A-Za-z0-9]{32}\b/', $bytes, $matches) > 0) {
        foreach (array_unique($matches[0]) as $candidate) {
            if (!in_array($candidate, $allowedCredentialFixtures, true)) {
                $zip->close();
                checkFail('安装包包含非示例的凭据形态值：' . $name);
            }
        }
    }
}
$zip->close();
$expected = [
    'geetest_captcha/',
    'geetest_captcha/GeetestCaptcha.php',
    'geetest_captcha/README.md',
    'geetest_captcha/config.php',
    'geetest_captcha/logic/',
    'geetest_captcha/logic/GeetestCaptchaLogic.php',
    'geetest_captcha/service/',
    'geetest_captcha/service/GeetestValidator.php',
];
sort($entries, SORT_STRING);
sort($expected, SORT_STRING);
if ($entries !== $expected) {
    checkFail('安装包文件白名单不匹配');
}

$firstHash = hash_file('sha256', $package);
runCommand([PHP_BINARY, 'scripts/build.php'], $root);
$secondHash = hash_file('sha256', $package);
if (!is_string($firstHash) || !hash_equals($firstHash, (string) $secondHash)) {
    checkFail('普通安装包不可复现');
}
$checksumFile = $root . '/dist/geetest_captcha_v1.3.0_SHA256SUMS.txt';
$checksum = is_file($checksumFile) ? trim((string) file_get_contents($checksumFile)) : '';
if ($checksum !== $secondHash . '  ' . basename($package)) {
    checkFail('版本化 SHA256SUMS 文件与普通安装包不一致');
}

fwrite(STDOUT, "PASS: complete repository audit\n");
