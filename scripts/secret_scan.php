#!/usr/bin/env php
<?php

declare(strict_types=1);

function secretFail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function isAllowedHost(string $host): bool
{
    $host = strtolower(rtrim($host, '.'));
    return $host === 'example.com'
        || substr($host, -12) === '.example.com'
        || $host === 'github.com'
        || $host === 'img.shields.io'
        || $host === 'geetest.com'
        || substr($host, -12) === '.geetest.com';
}

$root = dirname(__DIR__);
$skipDirectories = ['.git', 'dist'];
$allowedCredentialFixtures = [
    'examplecaptchaidexamplecaptchaid',
    'examplekeyexamplekeyexamplekeyex',
];
$allowedIpv4Fixtures = [
    '0.0.0.0',
    '127.0.0.1',
    '198.51.100.10',
    '203.0.113.10',
    '203.0.113.20',
];
$forbiddenFragments = [];
$patterns = [
    'private_key_marker' => '/BEGIN (?:OPENSSH |RSA |EC |DSA )?PRIVATE KEY/',
    'cloud_secret' => '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/',
    'github_token' => '/\b(?:ghp|github_pat)_[A-Za-z0-9_]{20,}\b/',
    'server_credential' => '/\b(?:sshpass|root_password|server_password)\b\s*[:=]/i',
    'china_mobile_number' => '/(?<![0-9])1[3-9][0-9]{9}(?![0-9])/',
];
$findings = [];
$directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$filter = new RecursiveCallbackFilterIterator(
    $directory,
    static function (SplFileInfo $item) use ($skipDirectories): bool {
        return !$item->isDir() || !in_array($item->getBasename(), $skipDirectories, true);
    }
);
$iterator = new RecursiveIteratorIterator($filter);
foreach ($iterator as $item) {
    $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
    if ($item->isLink()) {
        $findings[] = $relative . ': symbolic link';
        continue;
    }
    if (!$item->isFile()) {
        continue;
    }
    $contents = file_get_contents($item->getPathname());
    if (!is_string($contents)) {
        secretFail('无法读取：' . $relative);
    }
    if (strpos($contents, "\0") !== false) {
        continue;
    }

    foreach ($forbiddenFragments as $fragment) {
        if ($fragment !== '' && stripos($contents, $fragment) !== false) {
            $findings[] = $relative . ': historical publisher identity';
        }
    }
    foreach ($patterns as $name => $pattern) {
        if (preg_match($pattern, $contents) === 1) {
            $findings[] = $relative . ': ' . $name;
        }
    }
    if (preg_match_all('/\b[A-Za-z0-9]{32}\b/', $contents, $matches) > 0) {
        foreach (array_unique($matches[0]) as $candidate) {
            if (!in_array($candidate, $allowedCredentialFixtures, true)) {
                $findings[] = $relative . ': unexpected 32-character credential-like value';
            }
        }
    }
    if (preg_match_all('#https?://([A-Z0-9.-]+\.[A-Z]{2,})(?=[/:)\\]}>`]|$)#i', $contents, $urlMatches) > 0) {
        foreach (array_unique($urlMatches[1]) as $host) {
            if (!isAllowedHost($host)) {
                $findings[] = $relative . ': non-example project URL host ' . $host;
            }
        }
    }
    if (preg_match_all('/\b[A-Z0-9._%+-]+@([A-Z0-9.-]+\.[A-Z]{2,})\b/i', $contents, $emailMatches) > 0) {
        foreach (array_unique($emailMatches[1]) as $host) {
            if (!isAllowedHost($host)) {
                $findings[] = $relative . ': non-example email host ' . $host;
            }
        }
    }
    if (preg_match_all('/(?<![0-9])(?:[0-9]{1,3}\.){3}[0-9]{1,3}(?![0-9])/', $contents, $ipMatches) > 0) {
        foreach (array_unique($ipMatches[0]) as $address) {
            if (!in_array($address, $allowedIpv4Fixtures, true)) {
                $findings[] = $relative . ': unexpected IPv4 address ' . $address;
            }
        }
    }
}

if ($findings) {
    secretFail("敏感信息扫描未通过：\n- " . implode("\n- ", array_unique($findings)));
}

fwrite(STDOUT, "PASS: repository privacy and secret scan\n");
