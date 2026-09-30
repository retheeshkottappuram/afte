<?php

namespace App\Services\Trading;

use Throwable;

class PhpCliResolver
{
    /**
     * Resolve a valid PHP CLI binary that strictly satisfies >= 8.4.0 requirements.
     */
    public static function resolve(): string
    {
        // 1. Explicit environment or configuration override
        try {
            $configured = function_exists('config') ? config('trading.php_binary', env('PHP_BINARY_PATH')) : env('PHP_BINARY_PATH');
        } catch (Throwable) {
            $configured = env('PHP_BINARY_PATH');
        }

        if (! empty($configured) && is_string($configured) && $configured !== 'php') {
            if (self::isPhp84OrHigher($configured)) {
                return $configured;
            }
        }

        // 2. Windows Laragon / XAMPP detection
        if (PHP_OS_FAMILY === 'Windows') {
            $laragonPhps = glob('C:\\laragon\\bin\\php\\php*\\php.exe');
            if (! empty($laragonPhps)) {
                rsort($laragonPhps);
                foreach ($laragonPhps as $lphp) {
                    if (self::isPhp84OrHigher($lphp)) {
                        return $lphp;
                    }
                }

                return $laragonPhps[0];
            }

            if (defined('PHP_BINARY') && file_exists(PHP_BINARY) && ! str_contains(strtolower(PHP_BINARY), 'httpd')) {
                if (self::isPhp84OrHigher(PHP_BINARY)) {
                    return PHP_BINARY;
                }
            }

            return 'php';
        }

        // 3. Linux / Unix / macOS: Try current runtime PHP_BINARY if CLI
        if (defined('PHP_BINARY') && file_exists(PHP_BINARY)) {
            $binName = strtolower(basename(PHP_BINARY));
            if (! str_contains($binName, 'fpm') && ! str_contains($binName, 'cgi') && self::isPhp84OrHigher(PHP_BINARY)) {
                return PHP_BINARY;
            }

            // Derive CLI binary from PHP-FPM / CGI binary path
            // e.g. /usr/php84/sbin/php-fpm -> /usr/php84/bin/php or /usr/php84/usr/bin/php
            $derivedCandidates = [
                str_replace(['/sbin/php-fpm', '/sbin/php', '/bin/php-fpm'], ['/bin/php', '/bin/php', '/bin/php'], PHP_BINARY),
                str_replace('/sbin/', '/bin/', PHP_BINARY),
                dirname(PHP_BINARY).'/php',
                dirname(PHP_BINARY).'/php-cli',
                dirname(dirname(PHP_BINARY)).'/bin/php',
                dirname(dirname(PHP_BINARY)).'/usr/bin/php',
                dirname(dirname(PHP_BINARY)).'/bin/php84',
                dirname(dirname(PHP_BINARY)).'/bin/php8.4',
            ];
            foreach ($derivedCandidates as $derived) {
                if (file_exists($derived) && self::isPhp84OrHigher($derived)) {
                    return $derived;
                }
            }
        }

        // 4. Comprehensive Linux candidate paths (StackCP / 20i, cPanel EA4, CloudLinux, Plesk, Ubuntu/Debian PPA, standard)
        $candidates = [
            '/usr/php84/bin/php',         // StackCP / 20i / ServerByt PHP 8.4
            '/usr/php84/usr/bin/php',     // StackCP / 20i / ServerByt PHP 8.4
            '/usr/php84/bin/php-cli',
            '/usr/php84/usr/bin/php-cli',
            '/usr/bin/php8.4',           // Ubuntu / Debian PPA
            '/usr/bin/php-8.4',
            '/usr/bin/php84',
            '/usr/local/bin/php8.4',
            '/usr/local/bin/php84',
            '/usr/local/php84/bin/php',
            '/usr/local/php-8.4/bin/php',
            '/opt/cpanel/ea-php84/root/usr/bin/php', // cPanel EA4
            '/usr/local/bin/ea-php84',
            '/opt/alt/php84/usr/bin/php', // CloudLinux Alt-PHP
            '/opt/plesk/php/8.4/bin/php', // Plesk
            '/etc/alternatives/php',
            'php8.4',
            'php84',
        ];

        foreach ($candidates as $candidate) {
            if (self::isPhp84OrHigher($candidate)) {
                return $candidate;
            }
        }

        // 5. Check which php8.4 or which php from system shell (Linux/Unix only)
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('exec')) {
            $which84 = trim((string) @exec('which php8.4 2>/dev/null'));
            if (! empty($which84) && self::isPhp84OrHigher($which84)) {
                return $which84;
            }
            $whichPhp = trim((string) @exec('which php 2>/dev/null'));
            if (! empty($whichPhp) && self::isPhp84OrHigher($whichPhp)) {
                return $whichPhp;
            }
        }

        // Fallback: system php if >= 8.4
        if (self::isPhp84OrHigher('php')) {
            return 'php';
        }

        // Return first known valid path on Linux shared hosts
        return PHP_OS_FAMILY === 'Windows' ? 'php' : '/usr/php84/bin/php';
    }

    /**
     * Check if a given PHP binary executes and reports PHP_VERSION >= 8.4.0.
     */
    public static function isPhp84OrHigher(string $binary): bool
    {
        if (empty($binary) || ! is_string($binary)) {
            return false;
        }

        try {
            if ($binary !== 'php' && $binary !== 'php8.4' && $binary !== 'php84') {
                if (! file_exists($binary)) {
                    return false;
                }
                if (PHP_OS_FAMILY !== 'Windows' && ! is_executable($binary)) {
                    return false;
                }
            }

            $nullRedirect = PHP_OS_FAMILY === 'Windows' ? '2>nul' : '2>/dev/null';
            $verOutput = @shell_exec(escapeshellcmd($binary).' -r "echo PHP_VERSION;" '.$nullRedirect);
            if ($verOutput && version_compare(trim($verOutput), '8.4.0', '>=')) {
                return true;
            }
        } catch (Throwable) {
        }

        return false;
    }
}
