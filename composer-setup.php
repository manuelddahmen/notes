<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

process(is_array($argv) ? $argv : array());

/**
 * processes the installer
 */
function process($argv)
{
    // Determine ANSI output from --ansi and --no-ansi flags
    setUseAnsi($argv);

    if (in_array('--help', $argv)) {
        displayHelp();
        exit(0);
    }

    $check = in_array('--check', $argv);
    $help = in_array('--help', $argv);
    $force = in_array('--force', $argv);
    $quiet = in_array('--quiet', $argv);
    $channel = in_array('--snapshot', $argv) ? 'snapshot' : (in_array('--preview', $argv) ? 'preview' : 'stable');
    $disableTls = in_array('--disable-tls', $argv);
    $installDir = getOptValue('--install-dir', $argv, false);
    $version = getOptValue('--version', $argv, false);
    $filename = getOptValue('--filename', $argv, 'composer.phar');
    $cafile = getOptValue('--cafile', $argv, false);

    if (!checkParams($installDir, $version, $cafile)) {
        exit(1);
    }

    $ok = checkPlatform($warnings, $quiet, $disableTls);

    if ($check) {
        // Only show warnings if we haven't output any errors
        if ($ok) {
            showWarnings($warnings);
            showSecurityWarning($disableTls);
        }
        exit($ok ? 0 : 1);
    }

    if ($ok || $force) {
        installComposer($version, $installDir, $filename, $quiet, $disableTls, $cafile, $channel);
        showWarnings($warnings);
        showSecurityWarning($disableTls);
        exit(0);
    }

    exit(1);
}

/**
 * displays the help
 */
function displayHelp()
{
    echo <<<EOF
Composer Installer
------------------
Options
--help               this help
--check              for checking environment only
--force              forces the installation
--ansi               force ANSI color output
--no-ansi            disable ANSI color output
--quiet              do not output unimportant messages
--install-dir="..."  accepts a target installation directory
--preview            install the latest version from the preview (alpha/beta/rc) channel instead of stable
--snapshot           install the latest version from the snapshot (dev builds) channel instead of stable
--version="..."      accepts a specific version to install instead of the latest
--filename="..."     accepts a target filename (default: composer.phar)
--disable-tls        disable SSL/TLS security for file downloads
--cafile="..."       accepts a path to a Certificate Authority (CA) certificate file for SSL/TLS verification

EOF;
}

/**
 * Sets the USE_ANSI define for colorizing output
 *
 * @param array $argv Command-line arguments
 */
function setUseAnsi($argv)
{
    // --no-ansi wins over --ansi
    if (in_array('--no-ansi', $argv)) {
        define('USE_ANSI', false);
    } elseif (in_array('--ansi', $argv)) {
        define('USE_ANSI', true);
    } else {
        // On Windows, default to no ANSI, except in ANSICON and ConEmu.
        // Everywhere else, default to ANSI if stdout is a terminal.
        define(
            'USE_ANSI',
            (DIRECTORY_SEPARATOR == '\\')
                ? (false !== getenv('ANSICON') || 'ON' === getenv('ConEmuANSI'))
                : (function_exists('posix_isatty') && posix_isatty(1))
        );
    }
}

/**
 * Returns the value of a command-line option
 *
 * @param string $opt The command-line option to check
 * @param array $argv Command-line arguments
 * @param mixed $default Default value to be returned
 *
 * @return mixed The command-line value or the default
 */
function getOptValue($opt, $argv, $default)
{
    $optLength = strlen($opt);

    foreach ($argv as $key => $value) {
        $next = $key + 1;
        if (0 === strpos($value, $opt)) {
            if ($optLength === strlen($value) && isset($argv[$next])) {
                return trim($argv[$next]);
            } else {
                return trim(substr($value, $optLength + 1));
            }
        }
    }

    return $default;
}

/**
 * Checks that user-supplied params are valid
 *
 * @param mixed $installDir The required istallation directory
 * @param mixed $version The required composer version to install
 * @param mixed $cafile Certificate Authority file
 *
 * @return bool True if the supplied params are okay
 */
function checkParams($installDir, $version, $cafile)
{
    $result = true;

    if (false !== $installDir && !is_dir($installDir)) {
        out("The defined install dir ({$installDir}) does not exist.", 'info');
        $result = false;
    }

    if (false !== $version && 1 !== preg_match('/^\d+\.\d+\.\d+(\-(alpha|beta)\d+)*$/', $version)) {
        out("The defined install version ({$version}) does not match release pattern.", 'info');
        $result = false;
    }

    if (false !== $cafile && (!file_exists($cafile) || !is_readable($cafile))) {
        out("The defined Certificate Authority (CA) cert file ({$cafile}) does not exist or is not readable.", 'info');
        $result = false;
    }
    return $result;
}

/**
 * Checks the platform for possible issues running Composer
 *
 * Errors are written to the output, warnings are saved for later display.
 *
 * @param array $warnings Populated by method, to be shown later
 * @param bool $quiet Quiet mode
 * @param bool $disableTls Bypass tls
 *
 * @return bool True if there are no errors
 */
function checkPlatform(&$warnings, $quiet, $disableTls)
{
    getPlatformIssues($errors, $warnings);

    // Make openssl warning an error if tls has not been specifically disabled
    if (isset($warnings['openssl']) && !$disableTls) {
        $errors['openssl'] = $warnings['openssl'];
        unset($warnings['openssl']);
    }

    if (!empty($errors)) {
        out('Some settings on your machine make Composer unable to work properly.', 'error');
        out('Make sure that you fix the issues listed below and run this script again:', 'error');
        outputIssues($errors);
        return false;
    }

    if (empty($warnings) && !$quiet) {
        out('All settings correct for using Composer', 'success');
    }
    return true;
}

/**
 * Checks platform configuration for common incompatibility issues
 *
 * @param array $errors Populated by method
 * @param array $warnings Populated by method
 *
 * @return bool If any errors or warnings have been found
 */
function getPlatformIssues(&$errors, &$warnings)
{
    $errors = array();
    $warnings = array();

    if ($iniPath = php_ini_loaded_file()) {
        $iniMessage = PHP_EOL . 'The php.ini used by your command-line PHP is: ' . $iniPath;
    } else {
        $iniMessage = PHP_EOL . 'A php.ini file does not exist. You will have to create one.';
    }
    $iniMessage .= PHP_EOL . 'If you can not modify the ini file, you can also run `php -d option=value` to modify ini values on the fly. You can use -d multiple times.';

    if (ini_get('detect_unicode')) {
        $errors['unicode'] = array(
            'The detect_unicode setting must be disabled.',
            'Add the following to the end of your `php.ini`:',
            '    detect_unicode = Off',
            $iniMessage
        );
    }

    if (extension_loaded('suhosin')) {
        $suhosin = ini_get('suhosin.executor.include.whitelist');
        $suhosinBlacklist = ini_get('suhosin.executor.include.blacklist');
        if (false === stripos($suhosin, 'phar') && (!$suhosinBlacklist || false !== stripos($suhosinBlacklist, 'phar'))) {
            $errors['suhosin'] = array(
                'The suhosin.executor.include.whitelist setting is incorrect.',
                'Add the following to the end of your `php.ini` or suhosin.ini (Example path [for Debian]: /etc/php5/cli/conf.d/suhosin.ini):',
                '    suhosin.executor.include.whitelist = phar ' . $suhosin,
                $iniMessage
            );
        }
    }

    if (!function_exists('json_decode')) {
        $errors['json'] = array(
            'The json extension is missing.',
            'Install it or recompile php without --disable-json'
        );
    }

    if (!extension_loaded('Phar')) {
        $errors['phar'] = array(
            'The phar extension is missing.',
            'Install it or recompile php without --disable-phar'
        );
    }

    if (!extension_loaded('filter')) {
        $errors['filter'] = array(
            'The filter extension is missing.',
            'Install it or recompile php without --disable-filter'
        );
    }

    if (!extension_loaded('hash')) {
        $errors['hash'] = array(
            'The hash extension is missing.',
            'Install it or recompile php without --disable-hash'
        );
    }

    if (!extension_loaded('iconv') && !extension_loaded('mbstring')) {
        $errors['iconv_mbstring'] = array(
            'The iconv OR mbstring extension is required and both are missing.',
            'Install either of them or recompile php without --disable-iconv'
        );
    }

    if (!ini_get('allow_url_fopen')) {
        $errors['allow_url_fopen'] = array(
            'The allow_url_fopen setting is incorrect.',
            'Add the following to the end of your `php.ini`:',
            '    allow_url_fopen = On',
            $iniMessage
        );
    }

    if (extension_loaded('ionCube Loader') && ioncube_loader_iversion() < 40009) {
        $ioncube = ioncube_loader_version();
        $errors['ioncube'] = array(
            'Your ionCube Loader extension (' . $ioncube . ') is incompatible with Phar files.',
            'Upgrade to ionCube 4.0.9 or higher or remove this line (path may be different) from your `php.ini` to disable it:',
            '    zend_extension = /usr/lib/php5/20090626+lfs/ioncube_loader_lin_5.3.so',
            $iniMessage
        );
    }

    if (version_compare(PHP_VERSION, '5.3.2', '<')) {
        $errors['php'] = array(
            'Your PHP (' . PHP_VERSION . ') is too old, you must upgrade to PHP 5.3.2 or higher.'
        );
    }

    if (version_compare(PHP_VERSION, '5.3.4', '<')) {
        $warnings['php'] = array(
            'Your PHP (' . PHP_VERSION . ') is quite old, upgrading to PHP 5.3.4 or higher is recommended.',
            'Composer works with 5.3.2+ for most people, but there might be edge case issues.'
        );
    }

    if (!extension_loaded('openssl')) {
        $warnings['openssl'] = array(
            'The openssl extension is missing, which means that secure HTTPS transfers are impossible.',
            'If possible you should enable it or recompile php with --with-openssl'
        );
    }

    if (extension_loaded('openssl') && OPENSSL_VERSION_NUMBER < 0x1000100f) {
        // Attempt to parse version number out, fallback to whole string value.
        $opensslVersion = trim(strstr(OPENSSL_VERSION_TEXT, ' '));
        $opensslVersion = substr($opensslVersion, 0, strpos($opensslVersion, ' '));
        $opensslVersion = $opensslVersion ? $opensslVersion : OPENSSL_VERSION_TEXT;

        $warnings['openssl_version'] = array(
            'The OpenSSL library (' . $opensslVersion . ') used by PHP does not support TLSv1.2 or TLSv1.1.',
            'If possible you should upgrade OpenSSL to version 1.0.1 or above.'
        );
    }

    if (!defined('HHVM_VERSION') && !extension_loaded('apcu') && ini_get('apc.enable_cli')) {
        $warnings['apc_cli'] = array(
            'The apc.enable_cli setting is incorrect.',
            'Add the following to the end of your `php.ini`:',
            '    apc.enable_cli = Off',
            $iniMessage
        );
    }

    if (extension_loaded('xdebug')) {
        $warnings['xdebug_loaded'] = array(
            'The xdebug extension is loaded, this can slow down Composer a little.',
            'Disabling it when using Composer is recommended.'
        );

        if (ini_get('xdebug.profiler_enabled')) {
            $warnings['xdebug_profile'] = array(
                'The xdebug.profiler_enabled setting is enabled, this can slow down Composer a lot.',
                'Add the following to the end of your `php.ini` to disable it:',
                '    xdebug.profiler_enabled = 0',
                $iniMessage
            );
        }
    }

    if (!extension_loaded('zlib')) {
        $warnings['zlib'] = array(
            'The zlib extension is not loaded, this can slow down Composer a lot.',
            'If possible, install it or recompile php with --with-zlib',
            $iniMessage
        );
    }

    ob_start();
    phpinfo(INFO_GENERAL);
    $phpinfo = ob_get_clean();
    if (preg_match('{Configure Command(?: *</td><td class="v">| *=> *)(.*?)(?:</td>|$)}m', $phpinfo, $match)) {
        $configure = $match[1];

        if (false !== strpos($configure, '--enable-sigchild')) {
            $warnings['sigchild'] = array(
                'PHP was compiled with --enable-sigchild which can cause issues on some platforms.',
                'Recompile it without this flag if possible, see also:',
                '    https://bugs.php.net/bug.php?id=22999'
            );
        }

        if (false !== strpos($configure, '--with-curlwrappers')) {
            $warnings['curlwrappers'] = array(
                'PHP was compiled with --with-curlwrappers which will cause issues with HTTP authentication and GitHub.',
                'Recompile it without this flag if possible'
            );
        }
    }

    // Stringify the message arrays
    foreach ($errors as $key => $value) {
        $errors[$key] = PHP_EOL . implode(PHP_EOL, $value);
    }

    foreach ($warnings as $key => $value) {
        $warnings[$key] = PHP_EOL . implode(PHP_EOL, $value);
    }

    return !empty($errors) || !empty($warnings);
}


/**
 * Outputs an array of issues
 *
 * @param array $issues
 */
function outputIssues($issues)
{
    foreach ($issues as $issue) {
        out($issue, 'info');
    }
    out('');
}

/**
 * Outputs any warnings found
 *
 * @param array $warnings
 */
function showWarnings($warnings)
{
    if (!empty($warnings)) {
        out('Some settings on your machine may cause stability issues with Composer.', 'error');
        out('If you encounter issues, try to change the following:', 'error');
        outputIssues($warnings);
    }
}

/**
 * Outputs an end of process warning if tls has been bypassed
 *
 * @param bool $disableTls Bypass tls
 */
function showSecurityWarning($disableTls)
{
    if ($disableTls) {
        out('You have instructed the Installer not to enforce SSL/TLS security on remote HTTPS requests.', 'info');
        out('This will leave all downloads during installation vulnerable to Man-In-The-Middle (MITM) attacks', 'info');
    }
}

/**
 * installs composer to the current working directory
 */
function installComposer($version, $installDir, $filename, $quiet, $disableTls, $cafile, $channel)
{
    $installPath = (is_dir($installDir) ? rtrim($installDir, '/') . '/' : '') . $filename;
    $installDir = realpath($installDir) ? realpath($installDir) : getcwd();
    $file = $installDir . DIRECTORY_SEPARATOR . $filename;

    if (is_readable($file)) {
        @unlink($file);
    }

    $home = getHomeDir();

    if (!is_dir($home)) {
        @mkdir($home, 0777, true);
    }

    file_put_contents($home . '/keys.dev.pub', <<<DEVPUBKEY
-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAnBDHjZS6e0ZMoK3xTD7f
FNCzlXjX/Aie2dit8QXA03pSrOTbaMnxON3hUL47Lz3g1SC6YJEMVHr0zYq4elWi
i3ecFEgzLcj+pZM5X6qWu2Ozz4vWx3JYo1/a/HYdOuW9e3lwS8VtS0AVJA+U8X0A
hZnBmGpltHhO8hPKHgkJtkTUxCheTcbqn4wGHl8Z2SediDcPTLwqezWKUfrYzu1f
o/j3WFwFs6GtK4wdYtiXr+yspBZHO3y1udf8eFFGcb2V3EaLOrtfur6XQVizjOuk
8lw5zzse1Qp/klHqbDRsjSzJ6iL6F4aynBc6Euqt/8ccNAIz0rLjLhOraeyj4eNn
8iokwMKiXpcrQLTKH+RH1JCuOVxQ436bJwbSsp1VwiqftPQieN+tzqy+EiHJJmGf
TBAbWcncicCk9q2md+AmhNbvHO4PWbbz9TzC7HJb460jyWeuMEvw3gNIpEo2jYa9
pMV6cVqnSa+wOc0D7pC9a6bne0bvLcm3S+w6I5iDB3lZsb3A9UtRiSP7aGSo7D72
8tC8+cIgZcI7k9vjvOqH+d7sdOU2yPCnRY6wFh62/g8bDnUpr56nZN1G89GwM4d4
r/TU7BQQIzsZgAiqOGXvVklIgAMiV0iucgf3rNBLjjeNEwNSTTG9F0CtQ+7JLwaE
wSEuAuRm+pRqi8BRnQ/GKUcCAwEAAQ==
-----END PUBLIC KEY-----
DEVPUBKEY
    );
    file_put_contents($home . '/keys.tags.pub', <<<TAGSPUBKEY
-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA0Vi/2K6apCVj76nCnCl2
MQUPdK+A9eqkYBacXo2wQBYmyVlXm2/n/ZsX6pCLYPQTHyr5jXbkQzBw8SKqPdlh
vA7NpbMeNCz7wP/AobvUXM8xQuXKbMDTY2uZ4O7sM+PfGbptKPBGLe8Z8d2sUnTO
bXtX6Lrj13wkRto7st/w/Yp33RHe9SlqkiiS4MsH1jBkcIkEHsRaveZzedUaxY0M
mba0uPhGUInpPzEHwrYqBBEtWvP97t2vtfx8I5qv28kh0Y6t+jnjL1Urid2iuQZf
noCMFIOu4vksK5HxJxxrN0GOmGmwVQjOOtxkwikNiotZGPR4KsVj8NnBrLX7oGuM
nQvGciiu+KoC2r3HDBrpDeBVdOWxDzT5R4iI0KoLzFh2pKqwbY+obNPS2bj+2dgJ
rV3V5Jjry42QOCBN3c88wU1PKftOLj2ECpewY6vnE478IipiEu7EAdK8Zwj2LmTr
RKQUSa9k7ggBkYZWAeO/2Ag0ey3g2bg7eqk+sHEq5ynIXd5lhv6tC5PBdHlWipDK
tl2IxiEnejnOmAzGVivE1YGduYBjN+mjxDVy8KGBrjnz1JPgAvgdwJ2dYw4Rsc/e
TzCFWGk/HM6a4f0IzBWbJ5ot0PIi4amk07IotBXDWwqDiQTwyuGCym5EqWQ2BD95
RGv89BPD+2DLnJysngsvVaUCAwEAAQ==
-----END PUBLIC KEY-----
TAGSPUBKEY
    );

    if (false === $disableTls && empty($cafile) && !HttpClient::getSystemCaRootBundlePath()) {
        $errorHandler = new ErrorHandler();
        set_error_handler(array($errorHandler, 'handleError'));

        $target = $home . '/cacert.pem';
        $write = file_put_contents($target, HttpClient::getPackagedCaFile(), LOCK_EX);
        @chmod($target, 0644);

        restore_error_handler();

        if (!$write) {
            throw new RuntimeException('Unable to write bundled cacert.pem to: ' . $target);
        }
        $cafile = $target;
    }

    $httpClient = new HttpClient($disableTls, $cafile);
    $uriScheme = false === $disableTls ? 'https' : 'http';

    if (!$version) {
        $versions = json_decode($httpClient->get($uriScheme . '://getcomposer.org/versions'), true);
        foreach ($versions[$channel] as $candidate) {
            if ($candidate['min-php'] <= PHP_VERSION_ID) {
                $version = $candidate['version'];
                $downloadUrl = $candidate['path'];
                break;
            }
        }

        if (!$version) {
            out('None of the ' . count($versions[$channel]) . ' ' . $channel . ' version(s) of Composer matches your PHP version (' . PHP_VERSION . ' / ID: ' . PHP_VERSION_ID . ')', 'error');
            exit(1);
        }
    } else {
        $downloadUrl = "/download/{$version}/composer.phar";
    }

    $retries = 3;
    while ($retries--) {
        if (!$quiet) {
            out("Downloading $version...", 'info');
        }

        $url = "{$uriScheme}://getcomposer.org{$downloadUrl}";
        $errorHandler = new ErrorHandler();
        set_error_handler(array($errorHandler, 'handleError'));

        // download signature file
        if (false === $disableTls) {
            $signature = $httpClient->get($url . '.sig');
            if (!$signature) {
                out('Download failed: ' . $errorHandler->message, 'error');
            } else {
                $signature = json_decode($signature, true);
                $signature = base64_decode($signature['sha384']);
            }
        }

        $fh = fopen($file, 'w');
        if (!$fh) {
            out('Could not create file ' . $file . ': ' . $errorHandler->message, 'error');
        }
        if (!fwrite($fh, $httpClient->get($url))) {
            out('Download failed: ' . $errorHandler->message, 'error');
        }
        fclose($fh);

        restore_error_handler();
        if ($errorHandler->message) {
            continue;
        }

        try {
            // create a temp file ending in .phar since the Phar class only accepts that
            if ('.phar' !== substr($file, -5)) {
                copy($file, $file . '.tmp.phar');
                $pharFile = $file . '.tmp.phar';
            } else {
                $pharFile = $file;
            }

            // verify signature
            if (false === $disableTls) {
                $pubkeyid = openssl_pkey_get_public('file://' . $home . '/' . (preg_match('{^[0-9a-f]{40}$}', $version) ? 'keys.dev.pub' : 'keys.tags.pub'));
                $algo = defined('OPENSSL_ALGO_SHA384') ? OPENSSL_ALGO_SHA384 : 'SHA384';
                if (!in_array('SHA384', openssl_get_md_methods())) {
                    out('SHA384 is not supported by your openssl extension, could not verify the phar file integrity', 'error');
                    exit(1);
                }
                $verified = 1 === openssl_verify(file_get_contents($file), $signature, $pubkeyid, $algo);
                openssl_free_key($pubkeyid);
                if (!$verified) {
                    out('Signature mismatch, could not verify the phar file integrity', 'error');
                    exit(1);
                }
            }

            // test the phar validity
            if (!ini_get('phar.readonly')) {
                $phar = new Phar($pharFile);
                // free the variable to unlock the file
                unset($phar);
            }
            // clean up temp file if needed
            if ($file !== $pharFile) {
                unlink($pharFile);
            }
            break;
        } catch (Exception $e) {
            if (!$e instanceof UnexpectedValueException && !$e instanceof PharException) {
                throw $e;
            }
            // clean up temp file if needed
            if ($file !== $pharFile) {
                unlink($pharFile);
            }
            unlink($file);
            if ($retries) {
                if (!$quiet) {
                    out('The download is corrupt, retrying...', 'error');
                }
            } else {
                out('The download is corrupt (' . $e->getMessage() . '), aborting.', 'error');
                exit(1);
            }
        }
    }

    if ($errorHandler->message) {
        out('The download failed repeatedly, aborting.', 'error');
        exit(1);
    }

    chmod($file, 0755);

    if (!$quiet) {
        out(PHP_EOL . "Composer successfully installed to: " . $file, 'success', false);
        out(PHP_EOL . "Use it: php $installPath", 'info');
    }
}

/**
 * colorize output
 */
function out($text, $color = null, $newLine = true)
{
    $styles = array(
        'success' => "\033[0;32m%s\033[0m",
        'error' => "\033[31;31m%s\033[0m",
        'info' => "\033[33;33m%s\033[0m"
    );

    $format = '%s';

    if (isset($styles[$color]) && USE_ANSI) {
        $format = $styles[$color];
    }

    if ($newLine) {
        $format .= PHP_EOL;
    }

    printf($format, $text);
}

/**
 * Returns the system-dependent Composer home location, which may not exist
 *
 * @return string
 */
function getHomeDir()
{
    $home = getenv('COMPOSER_HOME');

    if (!$home) {
        $userDir = getUserDir();

        if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
            $home = $userDir . '/Composer';
        } else {
            $home = $userDir . '/.composer';

            if (!is_dir($home) && useXdg()) {
                // XDG Base Directory Specifications
                if (!($xdgConfig = getenv('XDG_CONFIG_HOME'))) {
                    $xdgConfig = $userDir . '/.config';
                }
                $home = $xdgConfig . '/composer';
            }
        }
    }
    return $home;
}

/**
 * Returns the location of the user directory from the environment
 * @throws Runtime Exception If the environment value does not exists
 *
 * @return string
 */
function getUserDir()
{
    $userEnv = defined('PHP_WINDOWS_VERSION_MAJOR') ? 'APPDATA' : 'HOME';
    $userDir = getenv($userEnv);

    if (!$userDir) {
        throw new RuntimeException('The ' . $userEnv . ' or COMPOSER_HOME environment variable must be set for composer to run correctly');
    }

    return rtrim(strtr($userDir, '\\', '/'), '/');
}

/**
 * @return bool
 */
function useXdg()
{
    foreach (array_keys($_SERVER) as $key) {
        if (substr($key, 0, 4) === 'XDG_') {
            return true;
        }
    }
    return false;
}

function validateCaFile($contents)
{
    // assume the CA is valid if php is vulnerable to
    // https://www.sektioneins.de/advisories/advisory-012013-php-openssl_x509_parse-memory-corruption-vulnerability.html
    if (
        PHP_VERSION_ID <= 50327
        || (PHP_VERSION_ID >= 50400 && PHP_VERSION_ID < 50422)
        || (PHP_VERSION_ID >= 50500 && PHP_VERSION_ID < 50506)
    ) {
        return !empty($contents);
    }

    return (bool)openssl_x509_parse($contents);
}

class ErrorHandler
{
    public $message = '';

    public function handleError($code, $msg)
    {
        if ($this->message) {
            $this->message .= "\n";
        }
        $this->message .= preg_replace('{^copy\(.*?\): }', '', $msg);
    }
}

class HttpClient
{

    private $options = array('http' => array());
    private $disableTls = false;

    public function __construct($disableTls = false, $cafile = false)
    {
        $this->disableTls = $disableTls;
        if ($this->disableTls === false) {
            if (!empty($cafile) && !is_dir($cafile)) {
                if (!is_readable($cafile) || !validateCaFile(file_get_contents($cafile))) {
                    throw new RuntimeException('The configured cafile (' . $cafile . ') was not valid or could not be read.');
                }
            }
            $options = $this->getTlsStreamContextDefaults($cafile);
            $this->options = array_replace_recursive($this->options, $options);
        }
    }

    protected function getTlsStreamContextDefaults($cafile)
    {
        $ciphers = implode(':', array(
            'ECDHE-RSA-AES128-GCM-SHA256',
            'ECDHE-ECDSA-AES128-GCM-SHA256',
            'ECDHE-RSA-AES256-GCM-SHA384',
            'ECDHE-ECDSA-AES256-GCM-SHA384',
            'DHE-RSA-AES128-GCM-SHA256',
            'DHE-DSS-AES128-GCM-SHA256',
            'kEDH+AESGCM',
            'ECDHE-RSA-AES128-SHA256',
            'ECDHE-ECDSA-AES128-SHA256',
            'ECDHE-RSA-AES128-SHA',
            'ECDHE-ECDSA-AES128-SHA',
            'ECDHE-RSA-AES256-SHA384',
            'ECDHE-ECDSA-AES256-SHA384',
            'ECDHE-RSA-AES256-SHA',
            'ECDHE-ECDSA-AES256-SHA',
            'DHE-RSA-AES128-SHA256',
            'DHE-RSA-AES128-SHA',
            'DHE-DSS-AES128-SHA256',
            'DHE-RSA-AES256-SHA256',
            'DHE-DSS-AES256-SHA',
            'DHE-RSA-AES256-SHA',
            'AES128-GCM-SHA256',
            'AES256-GCM-SHA384',
            'ECDHE-RSA-RC4-SHA',
            'ECDHE-ECDSA-RC4-SHA',
            'AES128',
            'AES256',
            'RC4-SHA',
            'HIGH',
            '!aNULL',
            '!eNULL',
            '!EXPORT',
            '!DES',
            '!3DES',
            '!MD5',
            '!PSK'
        ));

        /**
         * CN_match and SNI_server_name are only known once a URL is passed.
         * They will be set in the getOptionsForUrl() method which receives a URL.
         *
         * cafile or capath can be overridden by passing in those options to constructor.
         */
        $options = array(
            'ssl' => array(
                'ciphers' => $ciphers,
                'verify_peer' => true,
                'verify_depth' => 7,
                'SNI_enabled' => true,
            )
        );

        /**
         * Attempt to find a local cafile or throw an exception.
         * The user may go download one if this occurs.
         */
        if (!$cafile) {
            $cafile = self::getSystemCaRootBundlePath();
        }
        if (is_dir($cafile)) {
            $options['ssl']['capath'] = $cafile;
        } elseif ($cafile) {
            $options['ssl']['cafile'] = $cafile;
        } else {
            throw new RuntimeException('A valid cafile could not be located automatically.');
        }

        /**
         * Disable TLS compression to prevent CRIME attacks where supported.
         */
        if (version_compare(PHP_VERSION, '5.4.13') >= 0) {
            $options['ssl']['disable_compression'] = true;
        }

        return $options;
    }

    /**
     * This method was adapted from Sslurp.
     * https://github.com/EvanDotPro/Sslurp
     *
     * (c) Evan Coury <me@evancoury.com>
     *
     * For the full copyright and license information, please see below:
     *
     * Copyright (c) 2013, Evan Coury
     * All rights reserved.
     *
     * Redistribution and use in source and binary forms, with or without modification,
     * are permitted provided that the following conditions are met:
     *
     *     * Redistributions of source code must retain the above copyright notice,
     *       this list of conditions and the following disclaimer.
     *
     *     * Redistributions in binary form must reproduce the above copyright notice,
     *       this list of conditions and the following disclaimer in the documentation
     *       and/or other materials provided with the distribution.
     *
     * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
     * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
     * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
     * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
     * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
     * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
     * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
     * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
     * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
     * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
     */
    public static function getSystemCaRootBundlePath()
    {
        static $caPath = null;

        if ($caPath !== null) {
            return $caPath;
        }

        // If SSL_CERT_FILE env variable points to a valid certificate/bundle, use that.
        // This mimics how OpenSSL uses the SSL_CERT_FILE env variable.
        $envCertFile = getenv('SSL_CERT_FILE');
        if ($envCertFile && is_readable($envCertFile) && validateCaFile(file_get_contents($envCertFile))) {
            return $caPath = $envCertFile;
        }

        // If SSL_CERT_DIR env variable points to a valid certificate/bundle, use that.
        // This mimics how OpenSSL uses the SSL_CERT_FILE env variable.
        $envCertDir = getenv('SSL_CERT_DIR');
        if ($envCertDir && is_dir($envCertDir) && is_readable($envCertDir)) {
            return $caPath = $envCertDir;
        }

        $configured = ini_get('openssl.cafile');
        if ($configured && strlen($configured) > 0 && is_readable($configured) && validateCaFile(file_get_contents($configured))) {
            return $caPath = $configured;
        }

        $configured = ini_get('openssl.capath');
        if ($configured && is_dir($configured) && is_readable($configured)) {
            return $caPath = $configured;
        }

        $caBundlePaths = array(
            '/etc/pki/tls/certs/ca-bundle.crt', // Fedora, RHEL, CentOS (ca-certificates package)
            '/etc/ssl/certs/ca-certificates.crt', // Debian, Ubuntu, Gentoo, Arch Linux (ca-certificates package)
            '/etc/ssl/ca-bundle.pem', // SUSE, openSUSE (ca-certificates package)
            '/usr/local/share/certs/ca-root-nss.crt', // FreeBSD (ca_root_nss_package)
            '/usr/ssl/certs/ca-bundle.crt', // Cygwin
            '/opt/local/share/curl/curl-ca-bundle.crt', // OS X macports, curl-ca-bundle package
            '/usr/local/share/curl/curl-ca-bundle.crt', // Default cURL CA bunde path (without --with-ca-bundle option)
            '/usr/share/ssl/certs/ca-bundle.crt', // Really old RedHat?
            '/etc/ssl/cert.pem', // OpenBSD
            '/usr/local/etc/ssl/cert.pem', // FreeBSD 10.x
        );

        foreach ($caBundlePaths as $caBundle) {
            if (@is_readable($caBundle) && validateCaFile(file_get_contents($caBundle))) {
                return $caPath = $caBundle;
            }
        }

        foreach ($caBundlePaths as $caBundle) {
            $caBundle = dirname($caBundle);
            if (is_dir($caBundle) && glob($caBundle . '/*')) {
                return $caPath = $caBundle;
            }
        }

        return $caPath = false;
    }

    public static function getPackagedCaFile()
    {
        return <<<CACERT
##
## Bundle of CA Root Certificates
##
## Certificate data from Mozilla as of: Wed Oct 28 22:42:42 2015
##
## This is a bundle of X.509 certificates of public Certificate Authorities
## (CA). These were automatically extracted from Mozilla's root certificates
## file (certdata.txt).  This file can be found at
## https://raw.githubusercontent.com/bagder/ca-bundle/master/ca-bundle.crt
##
## It contains the certificates in PEM format and therefore
## can be directly used with curl / libcurl / php_curl, or with
## an Apache+mod_ssl webserver for SSL client authentication.
## Just configure this file as the SSLCACertificateFile.
##
## Conversion done with mk-ca-bundle.pl version 1.25.
## SHA1: 6d7d2f0a4fae587e7431be191a081ac1257d300a
##


Equifax Secure CA
=================
-----BEGIN CERTIFICATE-----
MIIDIDCCAomgAwIBAgIENd70zzANBgkqhkiG9w0BAQUFADBOMQswCQYDVQQGEwJVUzEQMA4GA1UE
ChMHRXF1aWZheDEtMCsGA1UECxMkRXF1aWZheCBTZWN1cmUgQ2VydGlmaWNhdGUgQXV0aG9yaXR5
MB4XDTk4MDgyMjE2NDE1MVoXDTE4MDgyMjE2NDE1MVowTjELMAkGA1UEBhMCVVMxEDAOBgNVBAoT
B0VxdWlmYXgxLTArBgNVBAsTJEVxdWlmYXggU2VjdXJlIENlcnRpZmljYXRlIEF1dGhvcml0eTCB
nzANBgkqhkiG9w0BAQEFAAOBjQAwgYkCgYEAwV2xWGcIYu6gmi0fCG2RFGiYCh7+2gRvE4RiIcPR
fM6fBeC4AfBONOziipUEZKzxa1NfBbPLZ4C/QgKO/t0BCezhABRP/PvwDN1Dulsr4R+AcJkVV5MW
8Q+XarfCaCMczE1ZMKxRHjuvK9buY0V7xdlfUNLjUA86iOe/FP3gx7kCAwEAAaOCAQkwggEFMHAG
A1UdHwRpMGcwZaBjoGGkXzBdMQswCQYDVQQGEwJVUzEQMA4GA1UEChMHRXF1aWZheDEtMCsGA1UE
CxMkRXF1aWZheCBTZWN1cmUgQ2VydGlmaWNhdGUgQXV0aG9yaXR5MQ0wCwYDVQQDEwRDUkwxMBoG
A1UdEAQTMBGBDzIwMTgwODIyMTY0MTUxWjALBgNVHQ8EBAMCAQYwHwYDVR0jBBgwFoAUSOZo+SvS
spXXR9gjIBBPM5iQn9QwHQYDVR0OBBYEFEjmaPkr0rKV10fYIyAQTzOYkJ/UMAwGA1UdEwQFMAMB
Af8wGgYJKoZIhvZ9B0EABA0wCxsFVjMuMGMDAgbAMA0GCSqGSIb3DQEBBQUAA4GBAFjOKer89961
zgK5F7WF0bnj4JXMJTENAKaSbn+2kmOeUJXRmm/kEd5jhW6Y7qj/WsjTVbJmcVfewCHrPSqnI0kB
BIZCe/zuf6IWUrVnZ9NA2zsmWLIodz2uFHdh1voqZiegDfqnc1zqcPGUIWVEX/r87yloqaKHee95
70+sB3c4
-----END CERTIFICATE-----

GlobalSign Root CA
==================
-----BEGIN CERTIFICATE-----
MIIDdTCCAl2gAwIBAgILBAAAAAABFUtaw5QwDQYJKoZIhvcNAQEFBQAwVzELMAkGA1UEBhMCQkUx
GTAXBgNVBAoTEEdsb2JhbFNpZ24gbnYtc2ExEDAOBgNVBAsTB1Jvb3QgQ0ExGzAZBgNVBAMTEkds
b2JhbFNpZ24gUm9vdCBDQTAeFw05ODA5MDExMjAwMDBaFw0yODAxMjgxMjAwMDBaMFcxCzAJBgNV
BAYTAkJFMRkwFwYDVQQKExBHbG9iYWxTaWduIG52LXNhMRAwDgYDVQQLEwdSb290IENBMRswGQYD
VQQDExJHbG9iYWxTaWduIFJvb3QgQ0EwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQDa
DuaZjc6j40+Kfvvxi4Mla+pIH/EqsLmVEQS98GPR4mdmzxzdzxtIK+6NiY6arymAZavpxy0Sy6sc
THAHoT0KMM0VjU/43dSMUBUc71DuxC73/OlS8pF94G3VNTCOXkNz8kHp1Wrjsok6Vjk4bwY8iGlb
Kk3Fp1S4bInMm/k8yuX9ifUSPJJ4ltbcdG6TRGHRjcdGsnUOhugZitVtbNV4FpWi6cgKOOvyJBNP
c1STE4U6G7weNLWLBYy5d4ux2x8gkasJU26Qzns3dLlwR5EiUWMWea6xrkEmCMgZK9FGqkjWZCrX
gzT/LCrBbBlDSgeF59N89iFo7+ryUp9/k5DPAgMBAAGjQjBAMA4GA1UdDwEB/wQEAwIBBjAPBgNV
HRMBAf8EBTADAQH/MB0GA1UdDgQWBBRge2YaRQ2XyolQL30EzTSo//z9SzANBgkqhkiG9w0BAQUF
AAOCAQEA1nPnfE920I2/7LqivjTFKDK1fPxsnCwrvQmeU79rXqoRSLblCKOzyj1hTdNGCbM+w6Dj
Y1Ub8rrvrTnhQ7k4o+YviiY776BQVvnGCv04zcQLcFGUl5gE38NflNUVyRRBnMRddWQVDf9VMOyG
j/8N7yy5Y0b2qvzfvGn9LhJIZJrglfCm7ymPAbEVtQwdpf5pLGkkeB6zpxxxYu7KyJesF12KwvhH
hm4qxFYxldBniYUr+WymXUadDKqC5JlR3XC321Y9YeRq4VzW9v493kHMB65jUr9TU/Qr6cf9tveC
X4XSQRjbgbMEHMUfpIBvFSDJ3gyICh3WZlXi/EjJKSZp4A==
-----END CERTIFICATE-----

GlobalSign Root CA - R2
=======================
-----BEGIN CERTIFICATE-----
MIIDujCCAqKgAwIBAgILBAAAAAABD4Ym5g0wDQYJKoZIhvcNAQEFBQAwTDEgMB4GA1UECxMXR2xv
YmFsU2lnbiBSb290IENBIC0gUjIxEzARBgNVBAoTCkdsb2JhbFNpZ24xEzARBgNVBAMTCkdsb2Jh
bFNpZ24wHhcNMDYxMjE1MDgwMDAwWhcNMjExMjE1MDgwMDAwWjBMMSAwHgYDVQQLExdHbG9iYWxT
aWduIFJvb3QgQ0EgLSBSMjETMBEGA1UEChMKR2xvYmFsU2lnbjETMBEGA1UEAxMKR2xvYmFsU2ln
bjCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAKbPJA6+Lm8omUVCxKs+IVSbC9N/hHD6
ErPLv4dfxn+G07IwXNb9rfF73OX4YJYJkhD10FPe+3t+c4isUoh7SqbKSaZeqKeMWhG8eoLrvozp
s6yWJQeXSpkqBy+0Hne/ig+1AnwblrjFuTosvNYSuetZfeLQBoZfXklqtTleiDTsvHgMCJiEbKjN
S7SgfQx5TfC4LcshytVsW33hoCmEofnTlEnLJGKRILzdC9XZzPnqJworc5HGnRusyMvo4KD0L5CL
TfuwNhv2GXqF4G3yYROIXJ/gkwpRl4pazq+r1feqCapgvdzZX99yqWATXgAByUr6P6TqBwMhAo6C
ygPCm48CAwEAAaOBnDCBmTAOBgNVHQ8BAf8EBAMCAQYwDwYDVR0TAQH/BAUwAwEB/zAdBgNVHQ4E
FgQUm+IHV2ccHsBqBt5ZtJot39wZhi4wNgYDVR0fBC8wLTAroCmgJ4YlaHR0cDovL2NybC5nbG9i
YWxzaWduLm5ldC9yb290LXIyLmNybDAfBgNVHSMEGDAWgBSb4gdXZxwewGoG3lm0mi3f3BmGLjAN
BgkqhkiG9w0BAQUFAAOCAQEAmYFThxxol4aR7OBKuEQLq4GsJ0/WwbgcQ3izDJr86iw8bmEbTUsp
9Z8FHSbBuOmDAGJFtqkIk7mpM0sYmsL4h4hO291xNBrBVNpGP+DTKqttVCL1OmLNIG+6KYnX3ZHu
01yiPqFbQfXf5WRDLenVOavSot+3i9DAgBkcRcAtjOj4LaR0VknFBbVPFd5uRHg5h6h+u/N5GJG7
9G+dwfCMNYxdAfvDbbnvRG15RjF+Cv6pgsH/76tuIMRQyV+dTZsXjAzlAcmgQWpzU/qlULRuJQ/7
TBj0/VLZjmmx6BEP3ojY+x1J96relc8geMJgEtslQIxq/H5COEBkEveegeGTLg==
-----END CERTIFICATE-----

Verisign Class 3 Public Primary Certification Authority - G3
============================================================
-----BEGIN CERTIFICATE-----
MIIEGjCCAwICEQCbfgZJoz5iudXukEhxKe9XMA0GCSqGSIb3DQEBBQUAMIHKMQswCQYDVQQGEwJV
UzEXMBUGA1UEChMOVmVyaVNpZ24sIEluYy4xHzAdBgNVBAsTFlZlcmlTaWduIFRydXN0IE5ldHdv
cmsxOjA4BgNVBAsTMShjKSAxOTk5IFZlcmlTaWduLCBJbmMuIC0gRm9yIGF1dGhvcml6ZWQgdXNl
IG9ubHkxRTBDBgNVBAMTPFZlcmlTaWduIENsYXNzIDMgUHVibGljIFByaW1hcnkgQ2VydGlmaWNh
dGlvbiBBdXRob3JpdHkgLSBHMzAeFw05OTEwMDEwMDAwMDBaFw0zNjA3MTYyMzU5NTlaMIHKMQsw
CQYDVQQGEwJVUzEXMBUGA1UEChMOVmVyaVNpZ24sIEluYy4xHzAdBgNVBAsTFlZlcmlTaWduIFRy
dXN0IE5ldHdvcmsxOjA4BgNVBAsTMShjKSAxOTk5IFZlcmlTaWduLCBJbmMuIC0gRm9yIGF1dGhv
cml6ZWQgdXNlIG9ubHkxRTBDBgNVBAMTPFZlcmlTaWduIENsYXNzIDMgUHVibGljIFByaW1hcnkg
Q2VydGlmaWNhdGlvbiBBdXRob3JpdHkgLSBHMzCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoC
ggEBAMu6nFL8eB8aHm8bN3O9+MlrlBIwT/A2R/XQkQr1F8ilYcEWQE37imGQ5XYgwREGfassbqb1
EUGO+i2tKmFZpGcmTNDovFJbcCAEWNF6yaRpvIMXZK0Fi7zQWM6NjPXr8EJJC52XJ2cybuGukxUc
cLwgTS8Y3pKI6GyFVxEa6X7jJhFUokWWVYPKMIno3Nij7SqAP395ZVc+FSBmCC+Vk7+qRy+oRpfw
EuL+wgorUeZ25rdGt+INpsyow0xZVYnm6FNcHOqd8GIWC6fJXwzw3sJ2zq/3avL6QaaiMxTJ5Xpj
055iN9WFZZ4O5lMkdBteHRJTW8cs54NJOxWuimi5V5cCAwEAATANBgkqhkiG9w0BAQUFAAOCAQEA
ERSWwauSCPc/L8my/uRan2Te2yFPhpk0djZX3dAVL8WtfxUfN2JzPtTnX84XA9s1+ivbrmAJXx5f
j267Cz3qWhMeDGBvtcC1IyIuBwvLqXTLR7sdwdela8wv0kL9Sd2nic9TutoAWii/gt/4uhMdUIaC
/Y4wjylGsB49Ndo4YhYYSq3mtlFs3q9i6wHQHiT+eo8SGhJouPtmmRQURVyu565pF4ErWjfJXir0
xuKhXFSbplQAz/DxwceYMBo7Nhbbo27q/a2ywtrvAkcTisDxszGtTxzhT5yvDwyd93gN2PQ1VoDa
t20Xj50egWTh/sVFuq1ruQp6Tk9LhO5L8X3dEQ==
-----END CERTIFICATE-----

Verisign Class 4 Public Primary Certification Authority - G3
============================================================
-----BEGIN CERTIFICATE-----
MIIEGjCCAwICEQDsoKeLbnVqAc/EfMwvlF7XMA0GCSqGSIb3DQEBBQUAMIHKMQswCQYDVQQGEwJV
UzEXMBUGA1UEChMOVmVyaVNpZ24sIEluYy4xHzAdBgNVBAsTFlZlcmlTaWduIFRydXN0IE5ldHdv
cmsxOjA4BgNVBAsTMShjKSAxOTk5IFZlcmlTaWduLCBJbmMuIC0gRm9yIGF1dGhvcml6ZWQgdXNl
IG9ubHkxRTBDBgNVBAMTPFZlcmlTaWduIENsYXNzIDQgUHVibGljIFByaW1hcnkgQ2VydGlmaWNh
dGlvbiBBdXRob3JpdHkgLSBHMzAeFw05OTEwMDEwMDAwMDBaFw0zNjA3MTYyMzU5NTlaMIHKMQsw
CQYDVQQGEwJVUzEXMBUGA1UEChMOVmVyaVNpZ24sIEluYy4xHzAdBgNVBAsTFlZlcmlTaWduIFRy
dXN0IE5ldHdvcmsxOjA4BgNVBAsTMShjKSAxOTk5IFZlcmlTaWduLCBJbmMuIC0gRm9yIGF1dGhv
cml6ZWQgdXNlIG9ubHkxRTBDBgNVBAMTPFZlcmlTaWduIENsYXNzIDQgUHVibGljIFByaW1hcnkg
Q2VydGlmaWNhdGlvbiBBdXRob3JpdHkgLSBHMzCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoC
ggEBAK3LpRFpxlmr8Y+1GQ9Wzsy1HyDkniYlS+BzZYlZ3tCD5PUPtbut8XzoIfzk6AzufEUiGXaS
tBO3IFsJ+mGuqPKljYXCKtbeZjbSmwL0qJJgfJxptI8kHtCGUvYynEFYHiK9zUVilQhu0GbdU6LM
8BDcVHOLBKFGMzNcF0C5nk3T875Vg+ixiY5afJqWIpA7iCXy0lOIAgwLePLmNxdLMEYH5IBtptiW
Lugs+BGzOA1mppvqySNb247i8xOOGlktqgLw7KSHZtzBP/XYufTsgsbSPZUd5cBPhMnZo0QoBmrX
Razwa2rvTl/4EYIeOGM0ZlDUPpNz+jDDZq3/ky2X7wMCAwEAATANBgkqhkiG9w0BAQUFAAOCAQEA
j/ola09b5KROJ1WrIhVZPMq1CtRK26vdoV9TxaBXOcLORyu+OshWv8LZJxA6sQU8wHcxuzrTBXtt
mhwwjIDLk5Mqg6sFUYICABFna/OIYUdfA5PVWw3g8dShMjWFsjrbsIKr0csKvE+MW8VLADsfKoKm
fjaF3H48ZwC15DtS4KjrXRX5xm3wrR0OhbepmnMUWluPQSjA1egtTaRezarZ7c7c2NU8Qh0XwRJd
RTjDOPP8hS6DRkiy1yBfkjaP53kPmF6Z6PDQpLv1U70qzlmwr25/bLvSHgCwIe34QWKCudiyxLtG
UPMxxY8BqHTr9Xgn2uf3ZkPznoM+IKrDNWCRzg==
-----END CERTIFICATE-----

Entrust.net Premium 2048 Secure Server CA
=========================================
-----BEGIN CERTIFICATE-----
MIIEKjCCAxKgAwIBAgIEOGPe+DANBgkqhkiG9w0BAQUFADCBtDEUMBIGA1UEChMLRW50cnVzdC5u
ZXQxQDA+BgNVBAsUN3d3dy5lbnRydXN0Lm5ldC9DUFNfMjA0OCBpbmNvcnAuIGJ5IHJlZi4gKGxp
bWl0cyBsaWFiLikxJTAjBgNVBAsTHChjKSAxOTk5IEVudHJ1c3QubmV0IExpbWl0ZWQxMzAxBgNV
BAMTKkVudHJ1c3QubmV0IENlcnRpZmljYXRpb24gQXV0aG9yaXR5ICgyMDQ4KTAeFw05OTEyMjQx
NzUwNTFaFw0yOTA3MjQxNDE1MTJaMIG0MRQwEgYDVQQKEwtFbnRydXN0Lm5ldDFAMD4GA1UECxQ3
d3d3LmVudHJ1c3QubmV0L0NQU18yMDQ4IGluY29ycC4gYnkgcmVmLiAobGltaXRzIGxpYWIuKTEl
MCMGA1UECxMcKGMpIDE5OTkgRW50cnVzdC5uZXQgTGltaXRlZDEzMDEGA1UEAxMqRW50cnVzdC5u
ZXQgQ2VydGlmaWNhdGlvbiBBdXRob3JpdHkgKDIwNDgpMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8A
MIIBCgKCAQEArU1LqRKGsuqjIAcVFmQqK0vRvwtKTY7tgHalZ7d4QMBzQshowNtTK91euHaYNZOL
Gp18EzoOH1u3Hs/lJBQesYGpjX24zGtLA/ECDNyrpUAkAH90lKGdCCmziAv1h3edVc3kw37XamSr
hRSGlVuXMlBvPci6Zgzj/L24ScF2iUkZ/cCovYmjZy/Gn7xxGWC4LeksyZB2ZnuU4q941mVTXTzW
nLLPKQP5L6RQstRIzgUyVYr9smRMDuSYB3Xbf9+5CFVghTAp+XtIpGmG4zU/HoZdenoVve8AjhUi
VBcAkCaTvA5JaJG/+EfTnZVCwQ5N328mz8MYIWJmQ3DW1cAH4QIDAQABo0IwQDAOBgNVHQ8BAf8E
BAMCAQYwDwYDVR0TAQH/BAUwAwEB/zAdBgNVHQ4EFgQUVeSB0RGAvtiJuQijMfmhJAkWuXAwDQYJ
KoZIhvcNAQEFBQADggEBADubj1abMOdTmXx6eadNl9cZlZD7Bh/KM3xGY4+WZiT6QBshJ8rmcnPy
T/4xmf3IDExoU8aAghOY+rat2l098c5u9hURlIIM7j+VrxGrD9cv3h8Dj1csHsm7mhpElesYT6Yf
zX1XEC+bBAlahLVu2B064dae0Wx5XnkcFMXj0EyTO2U87d89vqbllRrDtRnDvV5bu/8j72gZyxKT
J1wDLW8w0B62GqzeWvfRqqgnpv55gcR5mTNXuhKwqeBCbJPKVt7+bYQLCIt+jerXmCHG8+c8eS9e
nNFMFY3h7CI3zJpDC5fcgJCNs2ebb0gIFVbPv/ErfF6adulZkMV8gzURZVE=
-----END CERTIFICATE-----

Baltimore CyberTrust Root
=========================
-----BEGIN CERTIFICATE-----
MIIDdzCCAl+gAwIBAgIEAgAAuTANBgkqhkiG9w0BAQUFADBaMQswCQYDVQQGEwJJRTESMBAGA1UE
ChMJQmFsdGltb3JlMRMwEQYDVQQLEwpDeWJlclRydXN0MSIwIAYDVQQDExlCYWx0aW1vcmUgQ3li
ZXJUcnVzdCBSb290MB4XDTAwMDUxMjE4NDYwMFoXDTI1MDUxMjIzNTkwMFowWjELMAkGA1UEBhMC
SUUxEjAQBgNVBAoTCUJhbHRpbW9yZTETMBEGA1UECxMKQ3liZXJUcnVzdDEiMCAGA1UEAxMZQmFs
dGltb3JlIEN5YmVyVHJ1c3QgUm9vdDCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAKME
uyKrmD1X6CZymrV51Cni4eiVgLGw41uOKymaZN+hXe2wCQVt2yguzmKiYv60iNoS6zjrIZ3AQSsB
UnuId9Mcj8e6uYi1agnnc+gRQKfRzMpijS3ljwumUNKoUMMo6vWrJYeKmpYcqWe4PwzV9/lSEy/C
G9VwcPCPwBLKBsua4dnKM3p31vjsufFoREJIE9LAwqSuXmD+tqYF/LTdB1kC1FkYmGP1pWPgkAx9
XbIGevOF6uvUA65ehD5f/xXtabz5OTZydc93Uk3zyZAsuT3lySNTPx8kmCFcB5kpvcY67Oduhjpr
l3RjM71oGDHweI12v/yejl0qhqdNkNwnGjkCAwEAAaNFMEMwHQYDVR0OBBYEFOWdWTCCR1jMrPoI
VDaGezq1BE3wMBIGA1UdEwEB/wQIMAYBAf8CAQMwDgYDVR0PAQH/BAQDAgEGMA0GCSqGSIb3DQEB
BQUAA4IBAQCFDF2O5G9RaEIFoN27TyclhAO992T9Ldcw46QQF+vaKSm2eT929hkTI7gQCvlYpNRh
cL0EYWoSihfVCr3FvDB81ukMJY2GQE/szKN+OMY3EU/t3WgxjkzSswF07r51XgdIGn9w/xZchMB5
hbgF/X++ZRGjD8ACtPhSNzkE1akxehi/oCr0Epn3o0WC4zxe9Z2etciefC7IpJ5OCBRLbf1wbWsa
Y71k5h+3zvDyny67G7fyUIhzksLi4xaNmjICq44Y3ekQEe5+NauQrz4wlHrQMz2nZQ/1/I6eYs9H
RCwBXbsdtTLSR9I4LtD+gdwyah617jzV/OeBHRnDJELqYzmp
-----END CERTIFICATE-----

AddTrust Low-Value Services Root
================================
-----BEGIN CERTIFICATE-----
MIIEGDCCAwCgAwIBAgIBATANBgkqhkiG9w0BAQUFADBlMQswCQYDVQQGEwJTRTEUMBIGA1UEChML
QWRkVHJ1c3QgQUIxHTAbBgNVBAsTFEFkZFRydXN0IFRUUCBOZXR3b3JrMSEwHwYDVQQDExhBZGRU
cnVzdCBDbGFzcyAxIENBIFJvb3QwHhcNMDAwNTMwMTAzODMxWhcNMjAwNTMwMTAzODMxWjBlMQsw
CQYDVQQGEwJTRTEUMBIGA1UEChMLQWRkVHJ1c3QgQUIxHTAbBgNVBAsTFEFkZFRydXN0IFRUUCBO
ZXR3b3JrMSEwHwYDVQQDExhBZGRUcnVzdCBDbGFzcyAxIENBIFJvb3QwggEiMA0GCSqGSIb3DQEB
AQUAA4IBDwAwggEKAoIBAQCWltQhSWDia+hBBwzexODcEyPNwTXH+9ZOEQpnXvUGW2ulCDtbKRY6
54eyNAbFvAWlA3yCyykQruGIgb3WntP+LVbBFc7jJp0VLhD7Bo8wBN6ntGO0/7Gcrjyvd7ZWxbWr
oulpOj0OM3kyP3CCkplhbY0wCI9xP6ZIVxn4JdxLZlyldI+Yrsj5wAYi56xz36Uu+1LcsRVlIPo1
Zmne3yzxbrww2ywkEtvrNTVokMsAsJchPXQhI2U0K7t4WaPW4XY5mqRJjox0r26kmqPZm9I4XJui
GMx1I4S+6+JNM3GOGvDC+Mcdoq0Dlyz4zyXG9rgkMbFjXZJ/Y/AlyVMuH79NAgMBAAGjgdIwgc8w
HQYDVR0OBBYEFJWxtPCUtr3H2tERCSG+wa9J/RB7MAsGA1UdDwQEAwIBBjAPBgNVHRMBAf8EBTAD
AQH/MIGPBgNVHSMEgYcwgYSAFJWxtPCUtr3H2tERCSG+wa9J/RB7oWmkZzBlMQswCQYDVQQGEwJT
RTEUMBIGA1UEChMLQWRkVHJ1c3QgQUIxHTAbBgNVBAsTFEFkZFRydXN0IFRUUCBOZXR3b3JrMSEw
HwYDVQQDExhBZGRUcnVzdCBDbGFzcyAxIENBIFJvb3SCAQEwDQYJKoZIhvcNAQEFBQADggEBACxt
ZBsfzQ3duQH6lmM0MkhHma6X7f1yFqZzR1r0693p9db7RcwpiURdv0Y5PejuvE1Uhh4dbOMXJ0Ph
iVYrqW9yTkkz43J8KiOavD7/KCrto/8cI7pDVwlnTUtiBi34/2ydYB7YHEt9tTEv2dB8Xfjea4MY
eDdXL+gzB2ffHsdrKpV2ro9Xo/D0UrSpUwjP4E/TelOL/bscVjby/rK25Xa71SJlpz/+0WatC7xr
mYbvP33zGDLKe8bjq2RGlfgmadlVg3sslgf/WSxEo8bl6ancoWOAWiFeIc9TVPC6b4nbqKqVz4vj
ccweGyBECMB6tkD9xOQ14R0WHNC8K47Wcdk=
-----END CERTIFICATE-----

AddTrust External Root
======================
-----BEGIN CERTIFICATE-----
MIIENjCCAx6gAwIBAgIBATANBgkqhkiG9w0BAQUFADBvMQswCQYDVQQGEwJTRTEUMBIGA1UEChML
QWRkVHJ1c3QgQUIxJjAkBgNVBAsTHUFkZFRydXN0IEV4dGVybmFsIFRUUCBOZXR3b3JrMSIwIAYD
VQQDExlBZGRUcnVzdCBFeHRlcm5hbCBDQSBSb290MB4XDTAwMDUzMDEwNDgzOFoXDTIwMDUzMDEw
NDgzOFowbzELMAkGA1UEBhMCU0UxFDASBgNVBAoTC0FkZFRydXN0IEFCMSYwJAYDVQQLEx1BZGRU
cnVzdCBFeHRlcm5hbCBUVFAgTmV0d29yazEiMCAGA1UEAxMZQWRkVHJ1c3QgRXh0ZXJuYWwgQ0Eg
Um9vdDCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBALf3GjPm8gAELTngTlvtH7xsD821
+iO2zt6bETOXpClMfZOfvUq8k+0DGuOPz+VtUFrWlymUWoCwSXrbLpX9uMq/NzgtHj6RQa1wVsfw
Tz/oMp50ysiQVOnGXw94nZpAPA6sYapeFI+eh6FqUNzXmk6vBbOmcZSccbNQYArHE504B4YCqOmo
aSYYkKtMsE8jqzpPhNjfzp/haW+710LXa0Tkx63ubUFfclpxCDezeWWkWaCUN/cALw3CknLa0Dhy
2xSoRcRdKn23tNbE7qzNE0S3ySvdQwAl+mG5aWpYIxG3pzOPVnVZ9c0p10a3CitlttNCbxWyuHv7
7+ldU9U0WicCAwEAAaOB3DCB2TAdBgNVHQ4EFgQUrb2YejS0Jvf6xCZU7wO94CTLVBowCwYDVR0P
BAQDAgEGMA8GA1UdEwEB/wQFMAMBAf8wgZkGA1UdIwSBkTCBjoAUrb2YejS0Jvf6xCZU7wO94CTL
VBqhc6RxMG8xCzAJBgNVBAYTAlNFMRQwEgYDVQQKEwtBZGRUcnVzdCBBQjEmMCQGA1UECxMdQWRk
VHJ1c3QgRXh0ZXJuYWwgVFRQIE5ldHdvcmsxIjAgBgNVBAMTGUFkZFRydXN0IEV4dGVybmFsIENB
IFJvb3SCAQEwDQYJKoZIhvcNAQEFBQADggEBALCb4IUlwtYj4g+WBpKdQZic2YR5gdkeWxQHIzZl
j7DYd7usQWxHYINRsPkyPef89iYTx4AWpb9a/IfPeHmJIZriTAcKhjW88t5RxNKWt9x+Tu5w/Rw5
6wwCURQtjr0W4MHfRnXnJK3s9EK0hZNwEGe6nQY1ShjTK3rMUUKhemPR5ruhxSvCNr4TDea9Y355
e6cJDUCrat2PisP29owaQgVR1EX1n6diIWgVIEM8med8vSTYqZEXc4g/VhsxOBi0cQ+azcgOno4u
G+GMmIPLHzHxREzGBHNJdmAPx/i9F4BrLunMTA5amnkPIAou1Z5jJh5VkpTYghdae9C8x49OhgQ=
-----END CERTIFICATE-----

AddTrust Public Services Root
=============================
-----BEGIN CERTIFICATE-----
MIIEFTCCAv2gAwIBAgIBATANBgkqhkiG9w0BAQUFADBkMQswCQYDVQQGEwJTRTEUMBIGA1UEChML
QWRkVHJ1c3QgQUIxHTAbBgNVBAsTFEFkZFRydXN0IFRUUCBOZXR3b3JrMSAwHgYDVQQDExdBZGRU
cnVzdCBQdWJsaWMgQ0EgUm9vdDAeFw0wMDA1MzAxMDQxNTBaFw0yMDA1MzAxMDQxNTBaMGQxCzAJ
BgNVBAYTAlNFMRQwEgYDVQQKEwtBZGRUcnVzdCBBQjEdMBsGA1UECxMUQWRkVHJ1c3QgVFRQIE5l
dHdvcmsxIDAeBgNVBAMTF0FkZFRydXN0IFB1YmxpYyBDQSBSb290MIIBIjANBgkqhkiG9w0BAQEF
AAOCAQ8AMIIBCgKCAQEA6Rowj4OIFMEg2Dybjxt+A3S72mnTRqX4jsIMEZBRpS9mVEBV6tsfSlbu
nyNu9DnLoblv8n75XYcmYZ4c+OLspoH4IcUkzBEMP9smcnrHAZcHF/nXGCwwfQ56HmIexkvA/X1i
d9NEHif2P0tEs7c42TkfYNVRknMDtABp4/MUTu7R3AnPdzRGULD4EfL+OHn3Bzn+UZKXC1sIXzSG
Aa2Il+tmzV7R/9x98oTaunet3IAIx6eH1lWfl2royBFkuucZKT8Rs3iQhCBSWxHveNCD9tVIkNAw
HM+A+WD+eeSI8t0A65RF62WUaUC6wNW0uLp9BBGo6zEFlpROWCGOn9Bg/QIDAQABo4HRMIHOMB0G
A1UdDgQWBBSBPjfYkrAfd59ctKtzquf2NGAv+jALBgNVHQ8EBAMCAQYwDwYDVR0TAQH/BAUwAwEB
/zCBjgYDVR0jBIGGMIGDgBSBPjfYkrAfd59ctKtzquf2NGAv+qFopGYwZDELMAkGA1UEBhMCU0Ux
FDASBgNVBAoTC0FkZFRydXN0IEFCMR0wGwYDVQQLExRBZGRUcnVzdCBUVFAgTmV0d29yazEgMB4G
A1UEAxMXQWRkVHJ1c3QgUHVibGljIENBIFJvb3SCAQEwDQYJKoZIhvcNAQEFBQADggEBAAP3FUr4
JNojVhaTdt02KLmuG7jD8WS6IBh4lSknVwW8fCr0uVFV2ocC3g8WFzH4qnkuCRO7r7IgGRLlk/lL
+YPoRNWyQSW/iHVv/xD8SlTQX/D67zZzfRs2RcYhbbQVuE7PnFylPVoAjgbjPGsye/Kf8Lb93/Ao
GEjwxrzQvzSAlsJKsW2Ox5BF3i9nrEUEo3rcVZLJR2bYGozH7ZxOmuASu7VqTITh4SINhwBk/ox9
Yjllpu9CtoAlEmEBqCQTcAARJl/6NVDFSMwGR+gn2HCNX2TmoUQmXiLsks3/QppEIW1cxeMiHV9H
EufOX1362KqxMy3ZdvJOOjMMK7MtkAY=
-----END CERTIFICATE-----

AddTrust Qualified Certificates Root
====================================
-----BEGIN CERTIFICATE-----
MIIEHjCCAwagAwIBAgIBATANBgkqhkiG9w0BAQUFADBnMQswCQYDVQQGEwJTRTEUMBIGA1UEChML
QWRkVHJ1c3QgQUIxHTAbBgNVBAsTFEFkZFRydXN0IFRUUCBOZXR3b3JrMSMwIQYDVQQDExpBZGRU
cnVzdCBRdWFsaWZpZWQgQ0EgUm9vdDAeFw0wMDA1MzAxMDQ0NTBaFw0yMDA1MzAxMDQ0NTBaMGcx
CzAJBgNVBAYTAlNFMRQwEgYDVQQKEwtBZGRUcnVzdCBBQjEdMBsGA1UECxMUQWRkVHJ1c3QgVFRQ
IE5ldHdvcmsxIzAhBgNVBAMTGkFkZFRydXN0IFF1YWxpZmllZCBDQSBSb290MIIBIjANBgkqhkiG
9w0BAQEFAAOCAQ8AMIIBCgKCAQEA5B6a/twJWoekn0e+EV+vhDTbYjx5eLfpMLXsDBwqxBb/4Oxx
64r1EW7tTw2R0hIYLUkVAcKkIhPHEWT/IhKauY5cLwjPcWqzZwFZ8V1G87B4pfYOQnrjfxvM0PC3
KP0q6p6zsLkEqv32x7SxuCqg+1jxGaBvcCV+PmlKfw8i2O+tCBGaKZnhqkRFmhJePp1tUvznoD1o
L/BLcHwTOK28FSXx1s6rosAx1i+f4P8UWfyEk9mHfExUE+uf0S0R+Bg6Ot4l2ffTQO2kBhLEO+GR
wVY18BTcZTYJbqukB8c10cIDMzZbdSZtQvESa0NvS3GU+jQd7RNuyoB/mC9suWXY6QIDAQABo4HU
MIHRMB0GA1UdDgQWBBQ5lYtii1zJ1IC6WA+XPxUIQ8yYpzALBgNVHQ8EBAMCAQYwDwYDVR0TAQH/
BAUwAwEB/zCBkQYDVR0jBIGJMIGGgBQ5lYtii1zJ1IC6WA+XPxUIQ8yYp6FrpGkwZzELMAkGA1UE
BhMCU0UxFDASBgNVBAoTC0FkZFRydXN0IEFCMR0wGwYDVQQLExRBZGRUcnVzdCBUVFAgTmV0d29y
azEjMCEGA1UEAxMaQWRkVHJ1c3QgUXVhbGlmaWVkIENBIFJvb3SCAQEwDQYJKoZIhvcNAQEFBQAD
ggEBABmrder4i2VhlRO6aQTvhsoToMeqT2QbPxj2qC0sVY8FtzDqQmodwCVRLae/DLPt7wh/bDxG
GuoYQ992zPlmhpwsaPXpF/gxsxjE1kh9I0xowX67ARRvxdlu3rsEQmr49lx95dr6h+sNNVJn0J6X
dgWTP5XHAeZpVTh/EGGZyeNfpso+gmNIquIISD6q8rKFYqa0p9m9N5xotS1WfbC3P6CxB9bpT9ze
RXEwMn8bLgn5v1Kh7sKAPgZcLlVAwRv1cEWw3F369nJad9Jjzc9YiQBCYz95OdBEsIJuQRno3eDB
iFrRHnGTHyQwdOUeqN48Jzd/g66ed8/wMLH/S5noxqE=
-----END CERTIFICATE-----

Entrust Root Certification Authority
====================================
-----BEGIN CERTIFICATE-----
MIIEkTCCA3mgAwIBAgIERWtQVDANBgkqhkiG9w0BAQUFADCBsDELMAkGA1UEBhMCVVMxFjAUBgNV
BAoTDUVudHJ1c3QsIEluYy4xOTA3BgNVBAsTMHd3dy5lbnRydXN0Lm5ldC9DUFMgaXMgaW5jb3Jw
b3JhdGVkIGJ5IHJlZmVyZW5jZTEfMB0GA1UECxMWKGMpIDIwMDYgRW50cnVzdCwgSW5jLjEtMCsG
A1UEAxMkRW50cnVzdCBSb290IENlcnRpZmljYXRpb24gQXV0aG9yaXR5MB4XDTA2MTEyNzIwMjM0
MloXDTI2MTEyNzIwNTM0MlowgbAxCzAJBgNVBAYTAlVTMRYwFAYDVQQKEw1FbnRydXN0LCBJbmMu
MTkwNwYDVQQLEzB3d3cuZW50cnVzdC5uZXQvQ1BTIGlzIGluY29ycG9yYXRlZCBieSByZWZlcmVu
Y2UxHzAdBgNVBAsTFihjKSAyMDA2IEVudHJ1c3QsIEluYy4xLTArBgNVBAMTJEVudHJ1c3QgUm9v
dCBDZXJ0aWZpY2F0aW9uIEF1dGhvcml0eTCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEB
ALaVtkNC+sZtKm9I35RMOVcF7sN5EUFoNu3s/poBj6E4KPz3EEZmLk0eGrEaTsbRwJWIsMn/MYsz
A9u3g3s+IIRe7bJWKKf44LlAcTfFy0cOlypowCKVYhXbR9n10Cv/gkvJrT7eTNuQgFA/CYqEAOww
Cj0Yzfv9KlmaI5UXLEWeH25DeW0MXJj+SKfFI0dcXv1u5x609mhF0YaDW6KKjbHjKYD+JXGIrb68
j6xSlkuqUY3kEzEZ6E5Nn9uss2rVvDlUccp6en+Q3X0dgNmBu1kmwhH+5pPi94DkZfs0Nw4pgHBN
rziGLp5/V6+eF67rHMsoIV+2HNjnogQi+dPa2MsCAwEAAaOBsDCBrTAOBgNVHQ8BAf8EBAMCAQYw
DwYDVR0TAQH/BAUwAwEB/zArBgNVHRAEJDAigA8yMDA2MTEyNzIwMjM0MlqBDzIwMjYxMTI3MjA1
MzQyWjAfBgNVHSMEGDAWgBRokORnpKZTgMeGZqTx90tD+4S9bTAdBgNVHQ4EFgQUaJDkZ6SmU4DH
hmak8fdLQ/uEvW0wHQYJKoZIhvZ9B0EABBAwDhsIVjcuMTo0LjADAgSQMA0GCSqGSIb3DQEBBQUA
A4IBAQCT1DCw1wMgKtD5Y+iRDAUgqV8ZyntyTtSx29CW+1RaGSwMCPeyvIWonX9tO1KzKtvn1ISM
Y/YPyyYBkVBs9F8U4pN0wBOeMDpQ47RgxRzwIkSNcUesyBrJ6ZuaAGAT/3B+XxFNSRuzFVJ7yVTa
v52Vr2ua2J7p8eRDjeIRRDq/r72DQnNSi6q7pynP9WQcCk3RvKqsnyrQ/39/2n3qse0wJcGE2jTS
W3iDVuycNsMm4hH2Z0kdkquM++v/eu6FSqdQgPCnXEqULl8FmTxSQeDNtGPPAUO6nIPcj2A781q0
tHuu2guQOHXvgR1m0vdXcDazv/wor3ElhVsT/h5/WrQ8
-----END CERTIFICATE-----

RSA Security 2048 v3
====================
-----BEGIN CERTIFICATE-----
MIIDYTCCAkmgAwIBAgIQCgEBAQAAAnwAAAAKAAAAAjANBgkqhkiG9w0BAQUFADA6MRkwFwYDVQQK
ExBSU0EgU2VjdXJpdHkgSW5jMR0wGwYDVQQLExRSU0EgU2VjdXJpdHkgMjA0OCBWMzAeFw0wMTAy
MjIyMDM5MjNaFw0yNjAyMjIyMDM5MjNaMDoxGTAXBgNVBAoTEFJTQSBTZWN1cml0eSBJbmMxHTAb
BgNVBAsTFFJTQSBTZWN1cml0eSAyMDQ4IFYzMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKC
AQEAt49VcdKA3XtpeafwGFAyPGJn9gqVB93mG/Oe2dJBVGutn3y+Gc37RqtBaB4Y6lXIL5F4iSj7
Jylg/9+PjDvJSZu1pJTOAeo+tWN7fyb9Gd3AIb2E0S1PRsNO3Ng3OTsor8udGuorryGlwSMiuLgb
WhOHV4PR8CDn6E8jQrAApX2J6elhc5SYcSa8LWrg903w8bYqODGBDSnhAMFRD0xS+ARaqn1y07iH
KrtjEAMqs6FPDVpeRrc9DvV07Jmf+T0kgYim3WBU6JU2PcYJk5qjEoAAVZkZR73QpXzDuvsf9/UP
+Ky5tfQ3mBMY3oVbtwyCO4dvlTlYMNpuAWgXIszACwIDAQABo2MwYTAPBgNVHRMBAf8EBTADAQH/
MA4GA1UdDwEB/wQEAwIBBjAfBgNVHSMEGDAWgBQHw1EwpKrpRa41JPr/JCwz0LGdjDAdBgNVHQ4E
FgQUB8NRMKSq6UWuNST6/yQsM9CxnYwwDQYJKoZIhvcNAQEFBQADggEBAF8+hnZuuDU8TjYcHnmY
v/3VEhF5Ug7uMYm83X/50cYVIeiKAVQNOvtUudZj1LGqlk2iQk3UUx+LEN5/Zb5gEydxiKRz44Rj
0aRV4VCT5hsOedBnvEbIvz8XDZXmxpBp3ue0L96VfdASPz0+f00/FGj1EVDVwfSQpQgdMWD/YIwj
VAqv/qFuxdF6Kmh4zx6CCiC0H63lhbJqaHVOrSU3lIW+vaHU6rcMSzyd6BIA8F+sDeGscGNz9395
nzIlQnQFgCi/vcEkllgVsRch6YlL2weIZ/QVrXA+L02FO8K32/6YaCOJ4XQP3vTFhGMpG8zLB8kA
pKnXwiJPZ9d37CAFYd4=
-----END CERTIFICATE-----

GeoTrust Global CA
==================
-----BEGIN CERTIFICATE-----
MIIDVDCCAjygAwIBAgIDAjRWMA0GCSqGSIb3DQEBBQUAMEIxCzAJBgNVBAYTAlVTMRYwFAYDVQQK
Ew1HZW9UcnVzdCBJbmMuMRswGQYDVQQDExJHZW9UcnVzdCBHbG9iYWwgQ0EwHhcNMDIwNTIxMDQw
MDAwWhcNMjIwNTIxMDQwMDAwWjBCMQswCQYDVQQGEwJVUzEWMBQGA1UEChMNR2VvVHJ1c3QgSW5j
LjEbMBkGA1UEAxMSR2VvVHJ1c3QgR2xvYmFsIENBMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIB
CgKCAQEA2swYYzD99BcjGlZ+W988bDjkcbd4kdS8odhM+KhDtgPpTSEHCIjaWC9mOSm9BXiLnTjo
BbdqfnGk5sRgprDvgOSJKA+eJdbtg/OtppHHmMlCGDUUna2YRpIuT8rxh0PBFpVXLVDviS2Aelet
8u5fa9IAjbkU+BQVNdnARqN7csiRv8lVK83Qlz6cJmTM386DGXHKTubU1XupGc1V3sjs0l44U+Vc
T4wt/lAjNvxm5suOpDkZALeVAjmRCw7+OC7RHQWa9k0+bw8HHa8sHo9gOeL6NlMTOdReJivbPagU
vTLrGAMoUgRx5aszPeE4uwc2hGKceeoWMPRfwCvocWvk+QIDAQABo1MwUTAPBgNVHRMBAf8EBTAD
AQH/MB0GA1UdDgQWBBTAephojYn7qwVkDBF9qn1luMrMTjAfBgNVHSMEGDAWgBTAephojYn7qwVk
DBF9qn1luMrMTjANBgkqhkiG9w0BAQUFAAOCAQEANeMpauUvXVSOKVCUn5kaFOSPeCpilKInZ57Q
zxpeR+nBsqTP3UEaBU6bS+5Kb1VSsyShNwrrZHYqLizz/Tt1kL/6cdjHPTfStQWVYrmm3ok9Nns4
d0iXrKYgjy6myQzCsplFAMfOEVEiIuCl6rYVSAlk6l5PdPcFPseKUgzbFbS9bZvlxrFUaKnjaZC2
mqUPuLk/IH2uSrW4nOQdtqvmlKXBx4Ot2/Unhw4EbNX/3aBd7YdStysVAq45pmp06drE57xNNB6p
XE0zX5IJL4hmXXeXxx12E6nV5fEWCRE11azbJHFwLJhWC9kXtNHjUStedejV0NxPNO3CBWaAocvm
Mw==
-----END CERTIFICATE-----

GeoTrust Global CA 2
====================
-----BEGIN CERTIFICATE-----
MIIDZjCCAk6gAwIBAgIBATANBgkqhkiG9w0BAQUFADBEMQswCQYDVQQGEwJVUzEWMBQGA1UEChMN
R2VvVHJ1c3QgSW5jLjEdMBsGA1UEAxMUR2VvVHJ1c3QgR2xvYmFsIENBIDIwHhcNMDQwMzA0MDUw
MDAwWhcNMTkwMzA0MDUwMDAwWjBEMQswCQYDVQQGEwJVUzEWMBQGA1UEChMNR2VvVHJ1c3QgSW5j
LjEdMBsGA1UEAxMUR2VvVHJ1c3QgR2xvYmFsIENBIDIwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAw
ggEKAoIBAQDvPE1APRDfO1MA4Wf+lGAVPoWI8YkNkMgoI5kF6CsgncbzYEbYwbLVjDHZ3CB5JIG/
NTL8Y2nbsSpr7iFY8gjpeMtvy/wWUsiRxP89c96xPqfCfWbB9X5SJBri1WeR0IIQ13hLTytCOb1k
LUCgsBDTOEhGiKEMuzozKmKY+wCdE1l/bztyqu6mD4b5BWHqZ38MN5aL5mkWRxHCJ1kDs6ZgwiFA
Vvqgx306E+PsV8ez1q6diYD3Aecs9pYrEw15LNnA5IZ7S4wMcoKK+xfNAGw6EzywhIdLFnopsk/b
HdQL82Y3vdj2V7teJHq4PIu5+pIaGoSe2HSPqht/XvT+RSIhAgMBAAGjYzBhMA8GA1UdEwEB/wQF
MAMBAf8wHQYDVR0OBBYEFHE4NvICMVNHK266ZUapEBVYIAUJMB8GA1UdIwQYMBaAFHE4NvICMVNH
K266ZUapEBVYIAUJMA4GA1UdDwEB/wQEAwIBhjANBgkqhkiG9w0BAQUFAAOCAQEAA/e1K6tdEPx7
srJerJsOflN4WT5CBP51o62sgU7XAotexC3IUnbHLB/8gTKY0UvGkpMzNTEv/NgdRN3ggX+d6Yvh
ZJFiCzkIjKx0nVnZellSlxG5FntvRdOW2TF9AjYPnDtuzywNA0ZF66D0f0hExghAzN4bcLUprbqL
OzRldRtxIR0sFAqwlpW41uryZfspuk/qkZN0abby/+Ea0AzRdoXLiiW9l14sbxWZJue2Kf8i7MkC
x1YAzUm5s2x7UwQa4qjJqhIFI8LO57sEAszAR6LkxCkvW0VXiVHuPOtSCP8HNR6fNWpHSlaY0VqF
H4z1Ir+rzoPz4iIprn2DQKi6bA==
-----END CERTIFICATE-----

GeoTrust Universal CA
=====================
-----BEGIN CERTIFICATE-----
MIIFaDCCA1CgAwIBAgIBATANBgkqhkiG9w0BAQUFADBFMQswCQYDVQQGEwJVUzEWMBQGA1UEChMN
R2VvVHJ1c3QgSW5jLjEeMBwGA1UEAxMVR2VvVHJ1c3QgVW5pdmVyc2FsIENBMB4XDTA0MDMwNDA1
MDAwMFoXDTI5MDMwNDA1MDAwMFowRTELMAkGA1UEBhMCVVMxFjAUBgNVBAoTDUdlb1RydXN0IElu
Yy4xHjAcBgNVBAMTFUdlb1RydXN0IFVuaXZlcnNhbCBDQTCCAiIwDQYJKoZIhvcNAQEBBQADggIP
ADCCAgoCggIBAKYVVaCjxuAfjJ0hUNfBvitbtaSeodlyWL0AG0y/YckUHUWCq8YdgNY96xCcOq9t
JPi8cQGeBvV8Xx7BDlXKg5pZMK4ZyzBIle0iN430SppyZj6tlcDgFgDgEB8rMQ7XlFTTQjOgNB0e
RXbdT8oYN+yFFXoZCPzVx5zw8qkuEKmS5j1YPakWaDwvdSEYfyh3peFhF7em6fgemdtzbvQKoiFs
7tqqhZJmr/Z6a4LauiIINQ/PQvE1+mrufislzDoR5G2vc7J2Ha3QsnhnGqQ5HFELZ1aD/ThdDc7d
8Lsrlh/eezJS/R27tQahsiFepdaVaH/wmZ7cRQg+59IJDTWU3YBOU5fXtQlEIGQWFwMCTFMNaN7V
qnJNk22CDtucvc+081xdVHppCZbW2xHBjXWotM85yM48vCR85mLK4b19p71XZQvk/iXttmkQ3Cga
Rr0BHdCXteGYO8A3ZNY9lO4L4fUorgtWv3GLIylBjobFS1J72HGrH4oVpjuDWtdYAVHGTEHZf9hB
Z3KiKN9gg6meyHv8U3NyWfWTehd2Ds735VzZC1U0oqpbtWpU5xPKV+yXbfReBi9Fi1jUIxaS5BZu
KGNZMN9QAZxjiRqf2xeUgnA3wySemkfWWspOqGmJch+RbNt+nhutxx9z3SxPGWX9f5NAEC7S8O08
ni4oPmkmM8V7AgMBAAGjYzBhMA8GA1UdEwEB/wQFMAMBAf8wHQYDVR0OBBYEFNq7LqqwDLiIJlF0
XG0D08DYj3rWMB8GA1UdIwQYMBaAFNq7LqqwDLiIJlF0XG0D08DYj3rWMA4GA1UdDwEB/wQEAwIB
hjANBgkqhkiG9w0BAQUFAAOCAgEAMXjmx7XfuJRAyXHEqDXsRh3ChfMoWIawC/yOsjmPRFWrZIRc
aanQmjg8+uUfNeVE44B5lGiku8SfPeE0zTBGi1QrlaXv9z+ZhP015s8xxtxqv6fXIwjhmF7DWgh2
qaavdy+3YL1ERmrvl/9zlcGO6JP7/TG37FcREUWbMPEaiDnBTzynANXH/KttgCJwpQzgXQQpAvvL
oJHRfNbDflDVnVi+QTjruXU8FdmbyUqDWcDaU/0zuzYYm4UPFd3uLax2k7nZAY1IEKj79TiG8dsK
xr2EoyNB3tZ3b4XUhRxQ4K5RirqNPnbiucon8l+f725ZDQbYKxek0nxru18UGkiPGkzns0ccjkxF
KyDuSN/n3QmOGKjaQI2SJhFTYXNd673nxE0pN2HrrDktZy4W1vUAg4WhzH92xH3kt0tm7wNFYGm2
DFKWkoRepqO1pD4r2czYG0eq8kTaT/kD6PAUyz/zg97QwVTjt+gKN02LIFkDMBmhLMi9ER/frslK
xfMnZmaGrGiR/9nmUxwPi1xpZQomyB40w11Re9epnAahNt3ViZS82eQtDF4JbAiXfKM9fJP/P6EU
p8+1Xevb2xzEdt+Iub1FBZUbrvxGakyvSOPOrg/SfuvmbJxPgWp6ZKy7PtXny3YuxadIwVyQD8vI
P/rmMuGNG2+k5o7Y+SlIis5z/iw=
-----END CERTIFICATE-----

GeoTrust Universal CA 2
=======================
-----BEGIN CERTIFICATE-----
MIIFbDCCA1SgAwIBAgIBATANBgkqhkiG9w0BAQUFADBHMQswCQYDVQQGEwJVUzEWMBQGA1UEChMN
R2VvVHJ1c3QgSW5jLjEgMB4GA1UEAxMXR2VvVHJ1c3QgVW5pdmVyc2FsIENBIDIwHhcNMDQwMzA0
MDUwMDAwWhcNMjkwMzA0MDUwMDAwWjBHMQswCQYDVQQGEwJVUzEWMBQGA1UEChMNR2VvVHJ1c3Qg
SW5jLjEgMB4GA1UEAxMXR2VvVHJ1c3QgVW5pdmVyc2FsIENBIDIwggIiMA0GCSqGSIb3DQEBAQUA
A4ICDwAwggIKAoICAQCzVFLByT7y2dyxUxpZKeexw0Uo5dfR7cXFS6GqdHtXr0om/Nj1XqduGdt0
DE81WzILAePb63p3NeqqWuDW6KFXlPCQo3RWlEQwAx5cTiuFJnSCegx2oG9NzkEtoBUGFF+3Qs17
j1hhNNwqCPkuwwGmIkQcTAeC5lvO0Ep8BNMZcyfwqph/Lq9O64ceJHdqXbboW0W63MOhBW9Wjo8Q
JqVJwy7XQYci4E+GymC16qFjwAGXEHm9ADwSbSsVsaxLse4YuU6W3Nx2/zu+z18DwPw76L5GG//a
QMJS9/7jOvdqdzXQ2o3rXhhqMcceujwbKNZrVMaqW9eiLBsZzKIC9ptZvTdrhrVtgrrY6slWvKk2
WP0+GfPtDCapkzj4T8FdIgbQl+rhrcZV4IErKIM6+vR7IVEAvlI4zs1meaj0gVbi0IMJR1FbUGrP
20gaXT73y/Zl92zxlfgCOzJWgjl6W70viRu/obTo/3+NjN8D8WBOWBFM66M/ECuDmgFz2ZRthAAn
ZqzwcEAJQpKtT5MNYQlRJNiS1QuUYbKHsu3/mjX/hVTK7URDrBs8FmtISgocQIgfksILAAX/8sgC
SqSqqcyZlpwvWOB94b67B9xfBHJcMTTD7F8t4D1kkCLm0ey4Lt1ZrtmhN79UNdxzMk+MBB4zsslG
8dhcyFVQyWi9qLo2CQIDAQABo2MwYTAPBgNVHRMBAf8EBTADAQH/MB0GA1UdDgQWBBR281Xh+qQ2
+/CfXGJx7Tz0RzgQKzAfBgNVHSMEGDAWgBR281Xh+qQ2+/CfXGJx7Tz0RzgQKzAOBgNVHQ8BAf8E
BAMCAYYwDQYJKoZIhvcNAQEFBQADggIBAGbBxiPz2eAubl/oz66wsCVNK/g7WJtAJDday6sWSf+z
dXkzoS9tcBc0kf5nfo/sm+VegqlVHy/c1FEHEv6sFj4sNcZj/NwQ6w2jqtB8zNHQL1EuxBRa3ugZ
4T7GzKQp5y6EqgYweHZUcyiYWTjgAA1i00J9IZ+uPTqM1fp3DRgrFg5fNuH8KrUwJM/gYwx7WBr+
mbpCErGR9Hxo4sjoryzqyX6uuyo9DRXcNJW2GHSoag/HtPQTxORb7QrSpJdMKu0vbBKJPfEncKpq
A1Ihn0CoZ1Dy81of398j9tx4TuaYT1U6U+Pv8vSfx3zYWK8pIpe44L2RLrB27FcRz+8pRPPphXpg
Y+RdM4kX2TGq2tbzGDVyz4crL2MjhF2EjD9XoIj8mZEoJmmZ1I+XRL6O1UixpCgp8RW04eWe3fiP
pm8m1wk8OhwRDqZsN/etRIcsKMfYdIKz0G9KV7s1KSegi+ghp4dkNl3M2Basx7InQJJVOCiNUW7d
FGdTbHFcJoRNdVq2fmBWqU2t+5sel/MN2dKXVHfaPRK34B7vCAas+YWH6aLcr34YEoP9VhdBLtUp
gn2Z9DH2canPLAEnpQW5qrJITirvn5NSUZU8UnOOVkwXQMAJKOSLakhT2+zNVVXxxvjpoixMptEm
X36vWkzaH6byHCx+rgIW0lbQL1dTR+iS
-----END CERTIFICATE-----

Visa eCommerce Root
===================
-----BEGIN CERTIFICATE-----
MIIDojCCAoqgAwIBAgIQE4Y1TR0/BvLB+WUF1ZAcYjANBgkqhkiG9w0BAQUFADBrMQswCQYDVQQG
EwJVUzENMAsGA1UEChMEVklTQTEvMC0GA1UECxMmVmlzYSBJbnRlcm5hdGlvbmFsIFNlcnZpY2Ug
QXNzb2NpYXRpb24xHDAaBgNVBAMTE1Zpc2EgZUNvbW1lcmNlIFJvb3QwHhcNMDIwNjI2MDIxODM2
WhcNMjIwNjI0MDAxNjEyWjBrMQswCQYDVQQGEwJVUzENMAsGA1UEChMEVklTQTEvMC0GA1UECxMm
VmlzYSBJbnRlcm5hdGlvbmFsIFNlcnZpY2UgQXNzb2NpYXRpb24xHDAaBgNVBAMTE1Zpc2EgZUNv
bW1lcmNlIFJvb3QwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQCvV95WHm6h2mCxlCfL
F9sHP4CFT8icttD0b0/Pmdjh28JIXDqsOTPHH2qLJj0rNfVIsZHBAk4ElpF7sDPwsRROEW+1QK8b
RaVK7362rPKgH1g/EkZgPI2h4H3PVz4zHvtH8aoVlwdVZqW1LS7YgFmypw23RuwhY/81q6UCzyr0
TP579ZRdhE2o8mCP2w4lPJ9zcc+U30rq299yOIzzlr3xF7zSujtFWsan9sYXiwGd/BmoKoMWuDpI
/k4+oKsGGelT84ATB+0tvz8KPFUgOSwsAGl0lUq8ILKpeeUYiZGo3BxN77t+Nwtd/jmliFKMAGzs
GHxBvfaLdXe6YJ2E5/4tAgMBAAGjQjBAMA8GA1UdEwEB/wQFMAMBAf8wDgYDVR0PAQH/BAQDAgEG
MB0GA1UdDgQWBBQVOIMPPyw/cDMezUb+B4wg4NfDtzANBgkqhkiG9w0BAQUFAAOCAQEAX/FBfXxc
CLkr4NWSR/pnXKUTwwMhmytMiUbPWU3J/qVAtmPN3XEolWcRzCSs00Rsca4BIGsDoo8Ytyk6feUW
YFN4PMCvFYP3j1IzJL1kk5fui/fbGKhtcbP3LBfQdCVp9/5rPJS+TUtBjE7ic9DjkCJzQ83z7+pz
zkWKsKZJ/0x9nXGIxHYdkFsd7v3M9+79YKWxehZx0RbQfBI8bGmX265fOZpwLwU8GUYEmSA20GBu
YQa7FkKMcPcw++DbZqMAAb3mLNqRX6BGi01qnD093QVG/na/oAo85ADmJ7f/hC3euiInlhBx6yLt
398znM/jra6O1I7mT1GvFpLgXPYHDw==
-----END CERTIFICATE-----

Certum Root CA
==============
-----BEGIN CERTIFICATE-----
MIIDDDCCAfSgAwIBAgIDAQAgMA0GCSqGSIb3DQEBBQUAMD4xCzAJBgNVBAYTAlBMMRswGQYDVQQK
ExJVbml6ZXRvIFNwLiB6IG8uby4xEjAQBgNVBAMTCUNlcnR1bSBDQTAeFw0wMjA2MTExMDQ2Mzla
Fw0yNzA2MTExMDQ2MzlaMD4xCzAJBgNVBAYTAlBMMRswGQYDVQQKExJVbml6ZXRvIFNwLiB6IG8u
by4xEjAQBgNVBAMTCUNlcnR1bSBDQTCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAM6x
wS7TT3zNJc4YPk/EjG+AanPIW1H4m9LcuwBcsaD8dQPugfCI7iNS6eYVM42sLQnFdvkrOYCJ5JdL
kKWoePhzQ3ukYbDYWMzhbGZ+nPMJXlVjhNWo7/OxLjBos8Q82KxujZlakE403Daaj4GIULdtlkIJ
89eVgw1BS7Bqa/j8D35in2fE7SZfECYPCE/wpFcozo+47UX2bu4lXapuOb7kky/ZR6By6/qmW6/K
Uz/iDsaWVhFu9+lmqSbYf5VT7QqFiLpPKaVCjF62/IUgAKpoC6EahQGcxEZjgoi2IrHu/qpGWX7P
NSzVttpd90gzFFS269lvzs2I1qsb2pY7HVkCAwEAAaMTMBEwDwYDVR0TAQH/BAUwAwEB/zANBgkq
hkiG9w0BAQUFAAOCAQEAuI3O7+cUus/usESSbLQ5PqKEbq24IXfS1HeCh+YgQYHu4vgRt2PRFze+
GXYkHAQaTOs9qmdvLdTN/mUxcMUbpgIKumB7bVjCmkn+YzILa+M6wKyrO7Do0wlRjBCDxjTgxSvg
GrZgFCdsMneMvLJymM/NzD+5yCRCFNZX/OYmQ6kd5YCQzgNUKD73P9P4Te1qCjqTE5s7FCMTY5w/
0YcneeVMUeMBrYVdGjux1XMQpNPyvG5k9VpWkKjHDkx0Dy5xO/fIR/RpbxXyEV6DHpx8Uq79AtoS
qFlnGNu8cN2bsWntgM6JQEhqDjXKKWYVIZQs6GAqm4VKQPNriiTsBhYscw==
-----END CERTIFICATE-----

Comodo AAA Services root
========================
-----BEGIN CERTIFICATE-----
MIIEMjCCAxqgAwIBAgIBATANBgkqhkiG9w0BAQUFADB7MQswCQYDVQQGEwJHQjEbMBkGA1UECAwS
R3JlYXRlciBNYW5jaGVzdGVyMRAwDgYDVQQHDAdTYWxmb3JkMRowGAYDVQQKDBFDb21vZG8gQ0Eg
TGltaXRlZDEhMB8GA1UEAwwYQUFBIENlcnRpZmljYXRlIFNlcnZpY2VzMB4XDTA0MDEwMTAwMDAw
MFoXDTI4MTIzMTIzNTk1OVowezELMAkGA1UEBhMCR0IxGzAZBgNVBAgMEkdyZWF0ZXIgTWFuY2hl
c3RlcjEQMA4GA1UEBwwHU2FsZm9yZDEaMBgGA1UECgwRQ29tb2RvIENBIExpbWl0ZWQxITAfBgNV
BAMMGEFBQSBDZXJ0aWZpY2F0ZSBTZXJ2aWNlczCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoC
ggEBAL5AnfRu4ep2hxxNRUSOvkbIgwadwSr+GB+O5AL686tdUIoWMQuaBtDFcCLNSS1UY8y2bmhG
C1Pqy0wkwLxyTurxFa70VJoSCsN6sjNg4tqJVfMiWPPe3M/vg4aijJRPn2jymJBGhCfHdr/jzDUs
i14HZGWCwEiwqJH5YZ92IFCokcdmtet4YgNW8IoaE+oxox6gmf049vYnMlhvB/VruPsUK6+3qszW
Y19zjNoFmag4qMsXeDZRrOme9Hg6jc8P2ULimAyrL58OAd7vn5lJ8S3frHRNG5i1R8XlKdH5kBjH
Ypy+g8cmez6KJcfA3Z3mNWgQIJ2P2N7Sw4ScDV7oL8kCAwEAAaOBwDCBvTAdBgNVHQ4EFgQUoBEK
Iz6W8Qfs4q8p74Klf9AwpLQwDgYDVR0PAQH/BAQDAgEGMA8GA1UdEwEB/wQFMAMBAf8wewYDVR0f
BHQwcjA4oDagNIYyaHR0cDovL2NybC5jb21vZG9jYS5jb20vQUFBQ2VydGlmaWNhdGVTZXJ2aWNl
cy5jcmwwNqA0oDKGMGh0dHA6Ly9jcmwuY29tb2RvLm5ldC9BQUFDZXJ0aWZpY2F0ZVNlcnZpY2Vz
LmNybDANBgkqhkiG9w0BAQUFAAOCAQEACFb8AvCb6P+k+tZ7xkSAzk/ExfYAWMymtrwUSWgEdujm
7l3sAg9g1o1QGE8mTgHj5rCl7r+8dFRBv/38ErjHT1r0iWAFf2C3BUrz9vHCv8S5dIa2LX1rzNLz
Rt0vxuBqw8M0Ayx9lt1awg6nCpnBBYurDC/zXDrPbDdVCYfeU0BsWO/8tqtlbgT2G9w84FoVxp7Z
8VlIMCFlA2zs6SFz7JsDoeA3raAVGI/6ugLOpyypEBMs1OUIJqsil2D4kF501KKaU73yqWjgom7C
12yxow+ev+to51byrvLjKzg6CYG1a4XXvi3tPxq3smPi9WIsgtRqAEFQ8TmDn5XpNpaYbg==
-----END CERTIFICATE-----

Comodo Secure Services root
===========================
-----BEGIN CERTIFICATE-----
MIIEPzCCAyegAwIBAgIBATANBgkqhkiG9w0BAQUFADB+MQswCQYDVQQGEwJHQjEbMBkGA1UECAwS
R3JlYXRlciBNYW5jaGVzdGVyMRAwDgYDVQQHDAdTYWxmb3JkMRowGAYDVQQKDBFDb21vZG8gQ0Eg
TGltaXRlZDEkMCIGA1UEAwwbU2VjdXJlIENlcnRpZmljYXRlIFNlcnZpY2VzMB4XDTA0MDEwMTAw
MDAwMFoXDTI4MTIzMTIzNTk1OVowfjELMAkGA1UEBhMCR0IxGzAZBgNVBAgMEkdyZWF0ZXIgTWFu
Y2hlc3RlcjEQMA4GA1UEBwwHU2FsZm9yZDEaMBgGA1UECgwRQ29tb2RvIENBIExpbWl0ZWQxJDAi
BgNVBAMMG1NlY3VyZSBDZXJ0aWZpY2F0ZSBTZXJ2aWNlczCCASIwDQYJKoZIhvcNAQEBBQADggEP
ADCCAQoCggEBAMBxM4KK0HDrc4eCQNUd5MvJDkKQ+d40uaG6EfQlhfPMcm3ye5drswfxdySRXyWP
9nQ95IDC+DwN879A6vfIUtFyb+/Iq0G4bi4XKpVpDM3SHpR7LZQdqnXXs5jLrLxkU0C8j6ysNstc
rbvd4JQX7NFc0L/vpZXJkMWwrPsbQ996CF23uPJAGysnnlDOXmWCiIxe004MeuoIkbY2qitC++rC
oznl2yY4rYsK7hljxxwk3wN42ubqwUcaCwtGCd0C/N7Lh1/XMGNooa7cMqG6vv5Eq2i2pRcV/b3V
p6ea5EQz6YiO/O1R65NxTq0B50SOqy3LqP4BSUjwwN3HaNiS/j0CAwEAAaOBxzCBxDAdBgNVHQ4E
FgQUPNiTiMLAggnMAZkGkyDpnnAJY08wDgYDVR0PAQH/BAQDAgEGMA8GA1UdEwEB/wQFMAMBAf8w
gYEGA1UdHwR6MHgwO6A5oDeGNWh0dHA6Ly9jcmwuY29tb2RvY2EuY29tL1NlY3VyZUNlcnRpZmlj
YXRlU2VydmljZXMuY3JsMDmgN6A1hjNodHRwOi8vY3JsLmNvbW9kby5uZXQvU2VjdXJlQ2VydGlm
aWNhdGVTZXJ2aWNlcy5jcmwwDQYJKoZIhvcNAQEFBQADggEBAIcBbSMdflsXfcFhMs+P5/OKlFlm
4J4oqF7Tt/Q05qo5spcWxYJvMqTpjOev/e/C6LlLqqP05tqNZSH7uoDrJiiFGv45jN5bBAS0VPmj
Z55B+glSzAVIqMk/IQQezkhr/IXownuvf7fM+F86/TXGDe+X3EyrEeFryzHRbPtIgKvcnDe4IRRL
DXE97IMzbtFuMhbsmMcWi1mmNKsFVy2T96oTy9IT4rcuO81rUBcJaD61JlfutuC23bkpgHl9j6Pw
pCikFcSF9CfUa7/lXORlAnZUtOM3ZiTTGWHIUhDlizeauan5Hb/qmZJhlv8BzaFfDbxxvA6sCx1H
RR3B7Hzs/Sk=
-----END CERTIFICATE-----

Comodo Trusted Services root
============================
-----BEGIN CERTIFICATE-----
MIIEQzCCAyugAwIBAgIBATANBgkqhkiG9w0BAQUFADB/MQswCQYDVQQGEwJHQjEbMBkGA1UECAwS
R3JlYXRlciBNYW5jaGVzdGVyMRAwDgYDVQQHDAdTYWxmb3JkMRowGAYDVQQKDBFDb21vZG8gQ0Eg
TGltaXRlZDElMCMGA1UEAwwcVHJ1c3RlZCBDZXJ0aWZpY2F0ZSBTZXJ2aWNlczAeFw0wNDAxMDEw
MDAwMDBaFw0yODEyMzEyMzU5NTlaMH8xCzAJBgNVBAYTAkdCMRswGQYDVQQIDBJHcmVhdGVyIE1h
bmNoZXN0ZXIxEDAOBgNVBAcMB1NhbGZvcmQxGjAYBgNVBAoMEUNvbW9kbyBDQSBMaW1pdGVkMSUw
IwYDVQQDDBxUcnVzdGVkIENlcnRpZmljYXRlIFNlcnZpY2VzMIIBIjANBgkqhkiG9w0BAQEFAAOC
AQ8AMIIBCgKCAQEA33FvNlhTWvI2VFeAxHQIIO0Yfyod5jWaHiWsnOWWfnJSoBVC21ndZHoa0Lh7
3TkVvFVIxO06AOoxEbrycXQaZ7jPM8yoMa+j49d/vzMtTGo87IvDktJTdyR0nAducPy9C1t2ul/y
/9c3S0pgePfw+spwtOpZqqPOSC+pw7ILfhdyFgymBwwbOM/JYrc/oJOlh0Hyt3BAd9i+FHzjqMB6
juljatEPmsbS9Is6FARW1O24zG71++IsWL1/T2sr92AkWCTOJu80kTrV44HQsvAEAtdbtz6SrGsS
ivnkBbA7kUlcsutT6vifR4buv5XAwAaf0lteERv0xwQ1KdJVXOTt6wIDAQABo4HJMIHGMB0GA1Ud
DgQWBBTFe1i97doladL3WRaoszLAeydb9DAOBgNVHQ8BAf8EBAMCAQYwDwYDVR0TAQH/BAUwAwEB
/zCBgwYDVR0fBHwwejA8oDqgOIY2aHR0cDovL2NybC5jb21vZG9jYS5jb20vVHJ1c3RlZENlcnRp
ZmljYXRlU2VydmljZXMuY3JsMDqgOKA2hjRodHRwOi8vY3JsLmNvbW9kby5uZXQvVHJ1c3RlZENl
cnRpZmljYXRlU2VydmljZXMuY3JsMA0GCSqGSIb3DQEBBQUAA4IBAQDIk4E7ibSvuIQSTI3S8Ntw
uleGFTQQuS9/HrCoiWChisJ3DFBKmwCL2Iv0QeLQg4pKHBQGsKNoBXAxMKdTmw7pSqBYaWcOrp32
pSxBvzwGa+RZzG0Q8ZZvH9/0BAKkn0U+yNj6NkZEUD+Cl5EfKNsYEYwq5GWDVxISjBc/lDb+XbDA
BHcTuPQV1T84zJQ6VdCsmPW6AF/ghhmBeC8owH7TzEIK9a5QoNE+xqFx7D+gIIxmOom0jtTYsU0l
R+4viMi14QVFwL4Ucd56/Y57fU0IlqUSc/AtyjcndBInTMu2l+nZrghtWjlA3QVHdWpaIbOjGM9O
9y5Xt5hwXsjEeLBi
-----END CERTIFICATE-----

QuoVadis Root CA
================
-----BEGIN CERTIFICATE-----
MIIF0DCCBLigAwIBAgIEOrZQizANBgkqhkiG9w0BAQUFADB/MQswCQYDVQQGEwJCTTEZMBcGA1UE
ChMQUXVvVmFkaXMgTGltaXRlZDElMCMGA1UECxMcUm9vdCBDZXJ0aWZpY2F0aW9uIEF1dGhvcml0
eTEuMCwGA1UEAxMlUXVvVmFkaXMgUm9vdCBDZXJ0aWZpY2F0aW9uIEF1dGhvcml0eTAeFw0wMTAz
MTkxODMzMzNaFw0yMTAzMTcxODMzMzNaMH8xCzAJBgNVBAYTAkJNMRkwFwYDVQQKExBRdW9WYWRp
cyBMaW1pdGVkMSUwIwYDVQQLExxSb290IENlcnRpZmljYXRpb24gQXV0aG9yaXR5MS4wLAYDVQQD
EyVRdW9WYWRpcyBSb290IENlcnRpZmljYXRpb24gQXV0aG9yaXR5MIIBIjANBgkqhkiG9w0BAQEF
AAOCAQ8AMIIBCgKCAQEAv2G1lVO6V/z68mcLOhrfEYBklbTRvM16z/Ypli4kVEAkOPcahdxYTMuk
J0KX0J+DisPkBgNbAKVRHnAEdOLB1Dqr1607BxgFjv2DrOpm2RgbaIr1VxqYuvXtdj182d6UajtL
F8HVj71lODqV0D1VNk7feVcxKh7YWWVJWCCYfqtffp/p1k3sg3Spx2zY7ilKhSoGFPlU5tPaZQeL
YzcS19Dsw3sgQUSj7cugF+FxZc4dZjH3dgEZyH0DWLaVSR2mEiboxgx24ONmy+pdpibu5cxfvWen
AScOospUxbF6lR1xHkopigPcakXBpBlebzbNw6Kwt/5cOOJSvPhEQ+aQuwIDAQABo4ICUjCCAk4w
PQYIKwYBBQUHAQEEMTAvMC0GCCsGAQUFBzABhiFodHRwczovL29jc3AucXVvdmFkaXNvZmZzaG9y
ZS5jb20wDwYDVR0TAQH/BAUwAwEB/zCCARoGA1UdIASCAREwggENMIIBCQYJKwYBBAG+WAABMIH7
MIHUBggrBgEFBQcCAjCBxxqBxFJlbGlhbmNlIG9uIHRoZSBRdW9WYWRpcyBSb290IENlcnRpZmlj
YXRlIGJ5IGFueSBwYXJ0eSBhc3N1bWVzIGFjY2VwdGFuY2Ugb2YgdGhlIHRoZW4gYXBwbGljYWJs
ZSBzdGFuZGFyZCB0ZXJtcyBhbmQgY29uZGl0aW9ucyBvZiB1c2UsIGNlcnRpZmljYXRpb24gcHJh
Y3RpY2VzLCBhbmQgdGhlIFF1b1ZhZGlzIENlcnRpZmljYXRlIFBvbGljeS4wIgYIKwYBBQUHAgEW
Fmh0dHA6Ly93d3cucXVvdmFkaXMuYm0wHQYDVR0OBBYEFItLbe3TKbkGGew5Oanwl4Rqy+/fMIGu
BgNVHSMEgaYwgaOAFItLbe3TKbkGGew5Oanwl4Rqy+/foYGEpIGBMH8xCzAJBgNVBAYTAkJNMRkw
FwYDVQQKExBRdW9WYWRpcyBMaW1pdGVkMSUwIwYDVQQLExxSb290IENlcnRpZmljYXRpb24gQXV0
aG9yaXR5MS4wLAYDVQQDEyVRdW9WYWRpcyBSb290IENlcnRpZmljYXRpb24gQXV0aG9yaXR5ggQ6
tlCLMA4GA1UdDwEB/wQEAwIBBjANBgkqhkiG9w0BAQUFAAOCAQEAitQUtf70mpKnGdSkfnIYj9lo
fFIk3WdvOXrEql494liwTXCYhGHoG+NpGA7O+0dQoE7/8CQfvbLO9Sf87C9TqnN7Az10buYWnuul
LsS/VidQK2K6vkscPFVcQR0kvoIgR13VRH56FmjffU1RcHhXHTMe/QKZnAzNCgVPx7uOpHX6Sm2x
gI4JVrmcGmD+XcHXetwReNDWXcG31a0ymQM6isxUJTkxgXsTIlG6Rmyhu576BGxJJnSP0nPrzDCi
5upZIof4l/UO/erMkqQWxFIY6iHOsfHmhIHluqmGKPJDWl0Snawe2ajlCmqnf6CHKc/yiU3U7MXi
5nrQNiOKSnQ2+Q==
-----END CERTIFICATE-----

QuoVadis Root CA 2
==================
-----BEGIN CERTIFICATE-----
MIIFtzCCA5+gAwIBAgICBQkwDQYJKoZIhvcNAQEFBQAwRTELMAkGA1UEBhMCQk0xGTAXBgNVBAoT
EFF1b1ZhZGlzIExpbWl0ZWQxGzAZBgNVBAMTElF1b1ZhZGlzIFJvb3QgQ0EgMjAeFw0wNjExMjQx
ODI3MDBaFw0zMTExMjQxODIzMzNaMEUxCzAJBgNVBAYTAkJNMRkwFwYDVQQKExBRdW9WYWRpcyBM
aW1pdGVkMRswGQYDVQQDExJRdW9WYWRpcyBSb290IENBIDIwggIiMA0GCSqGSIb3DQEBAQUAA4IC
DwAwggIKAoICAQCaGMpLlA0ALa8DKYrwD4HIrkwZhR0In6spRIXzL4GtMh6QRr+jhiYaHv5+HBg6
XJxgFyo6dIMzMH1hVBHL7avg5tKifvVrbxi3Cgst/ek+7wrGsxDp3MJGF/hd/aTa/55JWpzmM+Yk
lvc/ulsrHHo1wtZn/qtmUIttKGAr79dgw8eTvI02kfN/+NsRE8Scd3bBrrcCaoF6qUWD4gXmuVbB
lDePSHFjIuwXZQeVikvfj8ZaCuWw419eaxGrDPmF60Tp+ARz8un+XJiM9XOva7R+zdRcAitMOeGy
lZUtQofX1bOQQ7dsE/He3fbE+Ik/0XX1ksOR1YqI0JDs3G3eicJlcZaLDQP9nL9bFqyS2+r+eXyt
66/3FsvbzSUr5R/7mp/iUcw6UwxI5g69ybR2BlLmEROFcmMDBOAENisgGQLodKcftslWZvB1Jdxn
wQ5hYIizPtGo/KPaHbDRsSNU30R2be1B2MGyIrZTHN81Hdyhdyox5C315eXbyOD/5YDXC2Og/zOh
D7osFRXql7PSorW+8oyWHhqPHWykYTe5hnMz15eWniN9gqRMgeKh0bpnX5UHoycR7hYQe7xFSkyy
BNKr79X9DFHOUGoIMfmR2gyPZFwDwzqLID9ujWc9Otb+fVuIyV77zGHcizN300QyNQliBJIWENie
J0f7OyHj+OsdWwIDAQABo4GwMIGtMA8GA1UdEwEB/wQFMAMBAf8wCwYDVR0PBAQDAgEGMB0GA1Ud
DgQWBBQahGK8SEwzJQTU7tD2A8QZRtGUazBuBgNVHSMEZzBlgBQahGK8SEwzJQTU7tD2A8QZRtGU
a6FJpEcwRTELMAkGA1UEBhMCQk0xGTAXBgNVBAoTEFF1b1ZhZGlzIExpbWl0ZWQxGzAZBgNVBAMT
ElF1b1ZhZGlzIFJvb3QgQ0EgMoICBQkwDQYJKoZIhvcNAQEFBQADggIBAD4KFk2fBluornFdLwUv
Z+YTRYPENvbzwCYMDbVHZF34tHLJRqUDGCdViXh9duqWNIAXINzng/iN/Ae42l9NLmeyhP3ZRPx3
UIHmfLTJDQtyU/h2BwdBR5YM++CCJpNVjP4iH2BlfF/nJrP3MpCYUNQ3cVX2kiF495V5+vgtJodm
VjB3pjd4M1IQWK4/YY7yarHvGH5KWWPKjaJW1acvvFYfzznB4vsKqBUsfU16Y8Zsl0Q80m/DShcK
+JDSV6IZUaUtl0HaB0+pUNqQjZRG4T7wlP0QADj1O+hA4bRuVhogzG9Yje0uRY/W6ZM/57Es3zrW
IozchLsib9D45MY56QSIPMO661V6bYCZJPVsAfv4l7CUW+v90m/xd2gNNWQjrLhVoQPRTUIZ3Ph1
WVaj+ahJefivDrkRoHy3au000LYmYjgahwz46P0u05B/B5EqHdZ+XIWDmbA4CD/pXvk1B+TJYm5X
f6dQlfe6yJvmjqIBxdZmv3lh8zwc4bmCXF2gw+nYSL0ZohEUGW6yhhtoPkg3Goi3XZZenMfvJ2II
4pEZXNLxId26F0KCl3GBUzGpn/Z9Yr9y4aOTHcyKJloJONDO1w2AFrR4pTqHTI2KpdVGl/IsELm8
VCLAAVBpQ570su9t+Oza8eOx79+Rj1QqCyXBJhnEUhAFZdWCEOrCMc0u
-----END CERTIFICATE-----

QuoVadis Root CA 3
==================
-----BEGIN CERTIFICATE-----
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
-----END CERTIFICATE-----

Security Communication Root CA
==============================
-----BEGIN CERTIFICATE-----
MIIDWjCCAkKgAwIBAgIBADANBgkqhkiG9w0BAQUFADBQMQswCQYDVQQGEwJKUDEYMBYGA1UEChMP
U0VDT00gVHJ1c3QubmV0MScwJQYDVQQLEx5TZWN1cml0eSBDb21tdW5pY2F0aW9uIFJvb3RDQTEw
HhcNMDMwOTMwMDQyMDQ5WhcNMjMwOTMwMDQyMDQ5WjBQMQswCQYDVQQGEwJKUDEYMBYGA1UEChMP
U0VDT00gVHJ1c3QubmV0MScwJQYDVQQLEx5TZWN1cml0eSBDb21tdW5pY2F0aW9uIFJvb3RDQTEw
ggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQCzs/5/022x7xZ8V6UMbXaKL0u/ZPtM7orw
8yl89f/uKuDp6bpbZCKamm8sOiZpUQWZJtzVHGpxxpp9Hp3dfGzGjGdnSj74cbAZJ6kJDKaVv0uM
DPpVmDvY6CKhS3E4eayXkmmziX7qIWgGmBSWh9JhNrxtJ1aeV+7AwFb9Ms+k2Y7CI9eNqPPYJayX
5HA49LY6tJ07lyZDo6G8SVlyTCMwhwFY9k6+HGhWZq/NQV3Is00qVUarH9oe4kA92819uZKAnDfd
DJZkndwi92SL32HeFZRSFaB9UslLqCHJxrHty8OVYNEP8Ktw+N/LTX7s1vqr2b1/VPKl6Xn62dZ2
JChzAgMBAAGjPzA9MB0GA1UdDgQWBBSgc0mZaNyFW2XjmygvV5+9M7wHSDALBgNVHQ8EBAMCAQYw
DwYDVR0TAQH/BAUwAwEB/zANBgkqhkiG9w0BAQUFAAOCAQEAaECpqLvkT115swW1F7NgE+vGkl3g
0dNq/vu+m22/xwVtWSDEHPC32oRYAmP6SBbvT6UL90qY8j+eG61Ha2POCEfrUj94nK9NrvjVT8+a
mCoQQTlSxN3Zmw7vkwGusi7KaEIkQmywszo+zenaSMQVy+n5Bw+SUEmK3TGXX8npN6o7WWWXlDLJ
s58+OmJYxUmtYg5xpTKqL8aJdkNAExNnPaJUJRDL8Try2frbSVa7pv6nQTXD4IhhyYjH3zYQIphZ
6rBK+1YWc26sTfcioU+tHXotRSflMMFe8toTyyVCUZVHA4xsIcx0Qu1T/zOLjw9XARYvz6buyXAi
FL39vmwLAw==
-----END CERTIFICATE-----

Sonera Class 2 Root CA
======================
-----BEGIN CERTIFICATE-----
MIIDIDCCAgigAwIBAgIBHTANBgkqhkiG9w0BAQUFADA5MQswCQYDVQQGEwJGSTEPMA0GA1UEChMG
U29uZXJhMRkwFwYDVQQDExBTb25lcmEgQ2xhc3MyIENBMB4XDTAxMDQwNjA3Mjk0MFoXDTIxMDQw
NjA3Mjk0MFowOTELMAkGA1UEBhMCRkkxDzANBgNVBAoTBlNvbmVyYTEZMBcGA1UEAxMQU29uZXJh
IENsYXNzMiBDQTCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAJAXSjWdyvANlsdE+hY3
/Ei9vX+ALTU74W+oZ6m/AxxNjG8yR9VBaKQTBME1DJqEQ/xcHf+Js+gXGM2RX/uJ4+q/Tl18GybT
dXnt5oTjV+WtKcT0OijnpXuENmmz/V52vaMtmdOQTiMofRhj8VQ7Jp12W5dCsv+u8E7s3TmVToMG
f+dJQMjFAbJUWmYdPfz56TwKnoG4cPABi+QjVHzIrviQHgCWctRUz2EjvOr7nQKV0ba5cTppCD8P
tOFCx4j1P5iop7oc4HFx71hXgVB6XGt0Rg6DA5jDjqhu8nYybieDwnPz3BjotJPqdURrBGAgcVeH
nfO+oJAjPYok4doh28MCAwEAAaMzMDEwDwYDVR0TAQH/BAUwAwEB/zARBgNVHQ4ECgQISqCqWITT
XjwwCwYDVR0PBAQDAgEGMA0GCSqGSIb3DQEBBQUAA4IBAQBazof5FnIVV0sd2ZvnoiYw7JNn39Yt
0jSv9zilzqsWuasvfDXLrNAPtEwr/IDva4yRXzZ299uzGxnq9LIR/WFxRL8oszodv7ND6J+/3DEI
cbCdjdY0RzKQxmUk96BKfARzjzlvF4xytb1LyHr4e4PDKE6cCepnP7JnBBvDFNr450kkkdAdavph
Oe9r5yF1BgfYErQhIHBCcYHaPJo2vqZbDWpsmh+Re/n570K6Tk6ezAyNlNzZRZxe7EJQY670XcSx
EtzKO6gunRRaBXW37Ndj4ro1tgQIkejanZz2ZrUYrAqmVCY0M9IbwdR/GjqOC6oybtv8TyWf2TLH
llpwrN9M
-----END CERTIFICATE-----

Staat der Nederlanden Root CA
=============================
-----BEGIN CERTIFICATE-----
MIIDujCCAqKgAwIBAgIEAJiWijANBgkqhkiG9w0BAQUFADBVMQswCQYDVQQGEwJOTDEeMBwGA1UE
ChMVU3RhYXQgZGVyIE5lZGVybGFuZGVuMSYwJAYDVQQDEx1TdGFhdCBkZXIgTmVkZXJsYW5kZW4g
Um9vdCBDQTAeFw0wMjEyMTcwOTIzNDlaFw0xNTEyMTYwOTE1MzhaMFUxCzAJBgNVBAYTAk5MMR4w
HAYDVQQKExVTdGFhdCBkZXIgTmVkZXJsYW5kZW4xJjAkBgNVBAMTHVN0YWF0IGRlciBOZWRlcmxh
bmRlbiBSb290IENBMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAmNK1URF6gaYUmHFt
vsznExvWJw56s2oYHLZhWtVhCb/ekBPHZ+7d89rFDBKeNVU+LCeIQGv33N0iYfXCxw719tV2U02P
jLwYdjeFnejKScfST5gTCaI+Ioicf9byEGW07l8Y1Rfj+MX94p2i71MOhXeiD+EwR+4A5zN9RGca
C1Hoi6CeUJhoNFIfLm0B8mBF8jHrqTFoKbt6QZ7GGX+UtFE5A3+y3qcym7RHjm+0Sq7lr7HcsBth
vJly3uSJt3omXdozSVtSnA71iq3DuD3oBmrC1SoLbHuEvVYFy4ZlkuxEK7COudxwC0barbxjiDn6
22r+I/q85Ej0ZytqERAhSQIDAQABo4GRMIGOMAwGA1UdEwQFMAMBAf8wTwYDVR0gBEgwRjBEBgRV
HSAAMDwwOgYIKwYBBQUHAgEWLmh0dHA6Ly93d3cucGtpb3ZlcmhlaWQubmwvcG9saWNpZXMvcm9v
dC1wb2xpY3kwDgYDVR0PAQH/BAQDAgEGMB0GA1UdDgQWBBSofeu8Y6R0E3QA7Jbg0zTBLL9s+DAN
BgkqhkiG9w0BAQUFAAOCAQEABYSHVXQ2YcG70dTGFagTtJ+k/rvuFbQvBgwp8qiSpGEN/KtcCFtR
EytNwiphyPgJWPwtArI5fZlmgb9uXJVFIGzmeafR2Bwp/MIgJ1HI8XxdNGdphREwxgDS1/PTfLbw
MVcoEoJz6TMvplW0C5GUR5z6u3pCMuiufi3IvKwUv9kP2Vv8wfl6leF9fpb8cbDCTMjfRTTJzg3y
nGQI0DvDKcWy7ZAEwbEpkcUwb8GpcjPM/l0WFywRaed+/sWDCN+83CI6LiBpIzlWYGeQiy52OfsR
iJf2fL1LuCAWZwWN4jvBcj+UlTfHXbme2JOhF4//DGYVwSR8MnwDHTuhWEUykw==
-----END CERTIFICATE-----

UTN DATACorp SGC Root CA
========================
-----BEGIN CERTIFICATE-----
MIIEXjCCA0agAwIBAgIQRL4Mi1AAIbQR0ypoBqmtaTANBgkqhkiG9w0BAQUFADCBkzELMAkGA1UE
BhMCVVMxCzAJBgNVBAgTAlVUMRcwFQYDVQQHEw5TYWx0IExha2UgQ2l0eTEeMBwGA1UEChMVVGhl
IFVTRVJUUlVTVCBOZXR3b3JrMSEwHwYDVQQLExhodHRwOi8vd3d3LnVzZXJ0cnVzdC5jb20xGzAZ
BgNVBAMTElVUTiAtIERBVEFDb3JwIFNHQzAeFw05OTA2MjQxODU3MjFaFw0xOTA2MjQxOTA2MzBa
MIGTMQswCQYDVQQGEwJVUzELMAkGA1UECBMCVVQxFzAVBgNVBAcTDlNhbHQgTGFrZSBDaXR5MR4w
HAYDVQQKExVUaGUgVVNFUlRSVVNUIE5ldHdvcmsxITAfBgNVBAsTGGh0dHA6Ly93d3cudXNlcnRy
dXN0LmNvbTEbMBkGA1UEAxMSVVROIC0gREFUQUNvcnAgU0dDMIIBIjANBgkqhkiG9w0BAQEFAAOC
AQ8AMIIBCgKCAQEA3+5YEKIrblXEjr8uRgnn4AgPLit6E5Qbvfa2gI5lBZMAHryv4g+OGQ0SR+ys
raP6LnD43m77VkIVni5c7yPeIbkFdicZD0/Ww5y0vpQZY/KmEQrrU0icvvIpOxboGqBMpsn0GFlo
wHDyUwDAXlCCpVZvNvlK4ESGoE1O1kduSUrLZ9emxAW5jh70/P/N5zbgnAVssjMiFdC04MwXwLLA
9P4yPykqlXvY8qdOD1R8oQ2AswkDwf9c3V6aPryuvEeKaq5xyh+xKrhfQgUL7EYw0XILyulWbfXv
33i+Ybqypa4ETLyorGkVl73v67SMvzX41MPRKA5cOp9wGDMgd8SirwIDAQABo4GrMIGoMAsGA1Ud
DwQEAwIBxjAPBgNVHRMBAf8EBTADAQH/MB0GA1UdDgQWBBRTMtGzz3/64PGgXYVOktKeRR20TzA9
BgNVHR8ENjA0MDKgMKAuhixodHRwOi8vY3JsLnVzZXJ0cnVzdC5jb20vVVROLURBVEFDb3JwU0dD
LmNybDAqBgNVHSUEIzAhBggrBgEFBQcDAQYKKwYBBAGCNwoDAwYJYIZIAYb4QgQBMA0GCSqGSIb3
DQEBBQUAA4IBAQAnNZcAiosovcYzMB4p/OL31ZjUQLtgyr+rFywJNn9Q+kHcrpY6CiM+iVnJowft
Gzet/Hy+UUla3joKVAgWRcKZsYfNjGjgaQPpxE6YsjuMFrMOoAyYUJuTqXAJyCyjj98C5OBxOvG0
I3KgqgHf35g+FFCgMSa9KOlaMCZ1+XtgHI3zzVAmbQQnmt/VDUVHKWss5nbZqSl9Mt3JNjy9rjXx
EZ4du5A/EkdOjtd+D2JzHVImOBwYSf0wdJrE5SIv2MCN7ZF6TACPcn9d2t0bi0Vr591pl6jFVkwP
DPafepE39peC4N1xaf92P2BNPM/3mfnGV/TJVTl4uix5yaaIK/QI
-----END CERTIFICATE-----

UTN USERFirst Hardware Root CA
==============================
-----BEGIN CERTIFICATE-----
MIIEdDCCA1ygAwIBAgIQRL4Mi1AAJLQR0zYq/mUK/TANBgkqhkiG9w0BAQUFADCBlzELMAkGA1UE
BhMCVVMxCzAJBgNVBAgTAlVUMRcwFQYDVQQHEw5TYWx0IExha2UgQ2l0eTEeMBwGA1UEChMVVGhl
IFVTRVJUUlVTVCBOZXR3b3JrMSEwHwYDVQQLExhodHRwOi8vd3d3LnVzZXJ0cnVzdC5jb20xHzAd
BgNVBAMTFlVUTi1VU0VSRmlyc3QtSGFyZHdhcmUwHhcNOTkwNzA5MTgxMDQyWhcNMTkwNzA5MTgx
OTIyWjCBlzELMAkGA1UEBhMCVVMxCzAJBgNVBAgTAlVUMRcwFQYDVQQHEw5TYWx0IExha2UgQ2l0
eTEeMBwGA1UEChMVVGhlIFVTRVJUUlVTVCBOZXR3b3JrMSEwHwYDVQQLExhodHRwOi8vd3d3LnVz
ZXJ0cnVzdC5jb20xHzAdBgNVBAMTFlVUTi1VU0VSRmlyc3QtSGFyZHdhcmUwggEiMA0GCSqGSIb3
DQEBAQUAA4IBDwAwggEKAoIBAQCx98M4P7Sof885glFn0G2f0v9Y8+efK+wNiVSZuTiZFvfgIXlI
wrthdBKWHTxqctU8EGc6Oe0rE81m65UJM6Rsl7HoxuzBdXmcRl6Nq9Bq/bkqVRcQVLMZ8Jr28bFd
tqdt++BxF2uiiPsA3/4aMXcMmgF6sTLjKwEHOG7DpV4jvEWbe1DByTCP2+UretNb+zNAHqDVmBe8
i4fDidNdoI6yqqr2jmmIBsX6iSHzCJ1pLgkzmykNRg+MzEk0sGlRvfkGzWitZky8PqxhvQqIDsjf
Pe58BEydCl5rkdbux+0ojatNh4lz0G6k0B4WixThdkQDf2Os5M1JnMWS9KsyoUhbAgMBAAGjgbkw
gbYwCwYDVR0PBAQDAgHGMA8GA1UdEwEB/wQFMAMBAf8wHQYDVR0OBBYEFKFyXyYbKJhDlV0HN9WF
lp1L0sNFMEQGA1UdHwQ9MDswOaA3oDWGM2h0dHA6Ly9jcmwudXNlcnRydXN0LmNvbS9VVE4tVVNF
UkZpcnN0LUhhcmR3YXJlLmNybDAxBgNVHSUEKjAoBggrBgEFBQcDAQYIKwYBBQUHAwUGCCsGAQUF
BwMGBggrBgEFBQcDBzANBgkqhkiG9w0BAQUFAAOCAQEARxkP3nTGmZev/K0oXnWO6y1n7k57K9cM
//bey1WiCuFMVGWTYGufEpytXoMs61quwOQt9ABjHbjAbPLPSbtNk28GpgoiskliCE7/yMgUsogW
XecB5BKV5UU0s4tpvc+0hY91UZ59Ojg6FEgSxvunOxqNDYJAB+gECJChicsZUN/KHAG8HQQZexB2
lzvukJDKxA4fFm517zP4029bHpbj4HR3dHuKom4t3XbWOTCC8KucUvIqx69JXn7HaOWCgchqJ/kn
iCrVWFCVH/A7HFe7fRQ5YiuayZSSKqMiDP+JJn1fIytH1xUdqWqeUQ0qUZ6B+dQ7XnASfxAynB67
nfhmqA==
-----END CERTIFICATE-----

Camerfirma Chambers of Commerce Root
====================================
-----BEGIN CERTIFICATE-----
MIIEvTCCA6WgAwIBAgIBADANBgkqhkiG9w0BAQUFADB/MQswCQYDVQQGEwJFVTEnMCUGA1UEChMe
QUMgQ2FtZXJmaXJtYSBTQSBDSUYgQTgyNzQzMjg3MSMwIQYDVQQLExpodHRwOi8vd3d3LmNoYW1i
ZXJzaWduLm9yZzEiMCAGA1UEAxMZQ2hhbWJlcnMgb2YgQ29tbWVyY2UgUm9vdDAeFw0wMzA5MzAx
NjEzNDNaFw0zNzA5MzAxNjEzNDRaMH8xCzAJBgNVBAYTAkVVMScwJQYDVQQKEx5BQyBDYW1lcmZp
cm1hIFNBIENJRiBBODI3NDMyODcxIzAhBgNVBAsTGmh0dHA6Ly93d3cuY2hhbWJlcnNpZ24ub3Jn
MSIwIAYDVQQDExlDaGFtYmVycyBvZiBDb21tZXJjZSBSb290MIIBIDANBgkqhkiG9w0BAQEFAAOC
AQ0AMIIBCAKCAQEAtzZV5aVdGDDg2olUkfzIx1L4L1DZ77F1c2VHfRtbunXF/KGIJPov7coISjlU
xFF6tdpg6jg8gbLL8bvZkSM/SAFwdakFKq0fcfPJVD0dBmpAPrMMhe5cG3nCYsS4No41XQEMIwRH
NaqbYE6gZj3LJgqcQKH0XZi/caulAGgq7YN6D6IUtdQis4CwPAxaUWktWBiP7Zme8a7ileb2R6jW
DA+wWFjbw2Y3npuRVDM30pQcakjJyfKl2qUMI/cjDpwyVV5xnIQFUZot/eZOKjRa3spAN2cMVCFV
d9oKDMyXroDclDZK9D7ONhMeU+SsTjoF7Nuucpw4i9A5O4kKPnf+dQIBA6OCAUQwggFAMBIGA1Ud
EwEB/wQIMAYBAf8CAQwwPAYDVR0fBDUwMzAxoC+gLYYraHR0cDovL2NybC5jaGFtYmVyc2lnbi5v
cmcvY2hhbWJlcnNyb290LmNybDAdBgNVHQ4EFgQU45T1sU3p26EpW1eLTXYGduHRooowDgYDVR0P
AQH/BAQDAgEGMBEGCWCGSAGG+EIBAQQEAwIABzAnBgNVHREEIDAegRxjaGFtYmVyc3Jvb3RAY2hh
bWJlcnNpZ24ub3JnMCcGA1UdEgQgMB6BHGNoYW1iZXJzcm9vdEBjaGFtYmVyc2lnbi5vcmcwWAYD
VR0gBFEwTzBNBgsrBgEEAYGHLgoDATA+MDwGCCsGAQUFBwIBFjBodHRwOi8vY3BzLmNoYW1iZXJz
aWduLm9yZy9jcHMvY2hhbWJlcnNyb290Lmh0bWwwDQYJKoZIhvcNAQEFBQADggEBAAxBl8IahsAi
fJ/7kPMa0QOx7xP5IV8EnNrJpY0nbJaHkb5BkAFyk+cefV/2icZdp0AJPaxJRUXcLo0waLIJuvvD
L8y6C98/d3tGfToSJI6WjzwFCm/SlCgdbQzALogi1djPHRPH8EjX1wWnz8dHnjs8NMiAT9QUu/wN
UPf6s+xCX6ndbcj0dc97wXImsQEcXCz9ek60AcUFV7nnPKoF2YjpB0ZBzu9Bga5Y34OirsrXdx/n
ADydb47kMgkdTXg0eDQ8lJsm7U9xxhl6vSAiSFr+S30Dt+dYvsYyTnQeaN2oaFuzPu5ifdmA6Ap1
erfutGWaIZDgqtCYvDi1czyL+Nw=
-----END CERTIFICATE-----

Camerfirma Global Chambersign Root
==================================
-----BEGIN CERTIFICATE-----
MIIExTCCA62gAwIBAgIBADANBgkqhkiG9w0BAQUFADB9MQswCQYDVQQGEwJFVTEnMCUGA1UEChMe
QUMgQ2FtZXJmaXJtYSBTQSBDSUYgQTgyNzQzMjg3MSMwIQYDVQQLExpodHRwOi8vd3d3LmNoYW1i
ZXJzaWduLm9yZzEgMB4GA1UEAxMXR2xvYmFsIENoYW1iZXJzaWduIFJvb3QwHhcNMDMwOTMwMTYx
NDE4WhcNMzcwOTMwMTYxNDE4WjB9MQswCQYDVQQGEwJFVTEnMCUGA1UEChMeQUMgQ2FtZXJmaXJt
YSBTQSBDSUYgQTgyNzQzMjg3MSMwIQYDVQQLExpodHRwOi8vd3d3LmNoYW1iZXJzaWduLm9yZzEg
MB4GA1UEAxMXR2xvYmFsIENoYW1iZXJzaWduIFJvb3QwggEgMA0GCSqGSIb3DQEBAQUAA4IBDQAw
ggEIAoIBAQCicKLQn0KuWxfH2H3PFIP8T8mhtxOviteePgQKkotgVvq0Mi+ITaFgCPS3CU6gSS9J
1tPfnZdan5QEcOw/Wdm3zGaLmFIoCQLfxS+EjXqXd7/sQJ0lcqu1PzKY+7e3/HKE5TWH+VX6ox8O
by4o3Wmg2UIQxvi1RMLQQ3/bvOSiPGpVeAp3qdjqGTK3L/5cPxvusZjsyq16aUXjlg9V9ubtdepl
6DJWk0aJqCWKZQbua795B9Dxt6/tLE2Su8CoX6dnfQTyFQhwrJLWfQTSM/tMtgsL+xrJxI0DqX5c
8lCrEqWhz0hQpe/SyBoT+rB/sYIcd2oPX9wLlY/vQ37mRQklAgEDo4IBUDCCAUwwEgYDVR0TAQH/
BAgwBgEB/wIBDDA/BgNVHR8EODA2MDSgMqAwhi5odHRwOi8vY3JsLmNoYW1iZXJzaWduLm9yZy9j
aGFtYmVyc2lnbnJvb3QuY3JsMB0GA1UdDgQWBBRDnDafsJ4wTcbOX60Qq+UDpfqpFDAOBgNVHQ8B
Af8EBAMCAQYwEQYJYIZIAYb4QgEBBAQDAgAHMCoGA1UdEQQjMCGBH2NoYW1iZXJzaWducm9vdEBj
aGFtYmVyc2lnbi5vcmcwKgYDVR0SBCMwIYEfY2hhbWJlcnNpZ25yb290QGNoYW1iZXJzaWduLm9y
ZzBbBgNVHSAEVDBSMFAGCysGAQQBgYcuCgEBMEEwPwYIKwYBBQUHAgEWM2h0dHA6Ly9jcHMuY2hh
bWJlcnNpZ24ub3JnL2Nwcy9jaGFtYmVyc2lnbnJvb3QuaHRtbDANBgkqhkiG9w0BAQUFAAOCAQEA
PDtwkfkEVCeR4e3t/mh/YV3lQWVPMvEYBZRqHN4fcNs+ezICNLUMbKGKfKX0j//U2K0X1S0E0T9Y
gOKBWYi+wONGkyT+kL0mojAt6JcmVzWJdJYY9hXiryQZVgICsroPFOrGimbBhkVVi76SvpykBMdJ
PJ7oKXqJ1/6v/2j1pReQvayZzKWGVwlnRtvWFsJG8eSpUPWP0ZIV018+xgBJOm5YstHRJw0lyDL4
IBHNfTIzSJRUTN3cecQwn+uOuFW114hcxWokPbLTBQNRxgfvzBRydD1ucs4YKIxKoHflCStFREes
t2d/AYoFWpO+ocH/+OcOZ6RHSXZddZAa9SaP8A==
-----END CERTIFICATE-----

NetLock Notary (Class A) Root
=============================
-----BEGIN CERTIFICATE-----
MIIGfTCCBWWgAwIBAgICAQMwDQYJKoZIhvcNAQEEBQAwga8xCzAJBgNVBAYTAkhVMRAwDgYDVQQI
EwdIdW5nYXJ5MREwDwYDVQQHEwhCdWRhcGVzdDEnMCUGA1UEChMeTmV0TG9jayBIYWxvemF0Yml6
dG9uc2FnaSBLZnQuMRowGAYDVQQLExFUYW51c2l0dmFueWtpYWRvazE2MDQGA1UEAxMtTmV0TG9j
ayBLb3pqZWd5em9pIChDbGFzcyBBKSBUYW51c2l0dmFueWtpYWRvMB4XDTk5MDIyNDIzMTQ0N1oX
DTE5MDIxOTIzMTQ0N1owga8xCzAJBgNVBAYTAkhVMRAwDgYDVQQIEwdIdW5nYXJ5MREwDwYDVQQH
EwhCdWRhcGVzdDEnMCUGA1UEChMeTmV0TG9jayBIYWxvemF0Yml6dG9uc2FnaSBLZnQuMRowGAYD
VQQLExFUYW51c2l0dmFueWtpYWRvazE2MDQGA1UEAxMtTmV0TG9jayBLb3pqZWd5em9pIChDbGFz
cyBBKSBUYW51c2l0dmFueWtpYWRvMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAvHSM
D7tM9DceqQWC2ObhbHDqeLVu0ThEDaiDzl3S1tWBxdRL51uUcCbbO51qTGL3cfNk1mE7PetzozfZ
z+qMkjvN9wfcZnSX9EUi3fRc4L9t875lM+QVOr/bmJBVOMTtplVjC7B4BPTjbsE/jvxReB+SnoPC
/tmwqcm8WgD/qaiYdPv2LD4VOQ22BFWoDpggQrOxJa1+mm9dU7GrDPzr4PN6s6iz/0b2Y6LYOph7
tqyF/7AlT3Rj5xMHpQqPBffAZG9+pyeAlt7ULoZgx2srXnN7F+eRP2QM2EsiNCubMvJIH5+hCoR6
4sKtlz2O1cH5VqNQ6ca0+pii7pXmKgOM3wIDAQABo4ICnzCCApswDgYDVR0PAQH/BAQDAgAGMBIG
A1UdEwEB/wQIMAYBAf8CAQQwEQYJYIZIAYb4QgEBBAQDAgAHMIICYAYJYIZIAYb4QgENBIICURaC
Ak1GSUdZRUxFTSEgRXplbiB0YW51c2l0dmFueSBhIE5ldExvY2sgS2Z0LiBBbHRhbGFub3MgU3pv
bGdhbHRhdGFzaSBGZWx0ZXRlbGVpYmVuIGxlaXJ0IGVsamFyYXNvayBhbGFwamFuIGtlc3p1bHQu
IEEgaGl0ZWxlc2l0ZXMgZm9seWFtYXRhdCBhIE5ldExvY2sgS2Z0LiB0ZXJtZWtmZWxlbG9zc2Vn
LWJpenRvc2l0YXNhIHZlZGkuIEEgZGlnaXRhbGlzIGFsYWlyYXMgZWxmb2dhZGFzYW5hayBmZWx0
ZXRlbGUgYXogZWxvaXJ0IGVsbGVub3J6ZXNpIGVsamFyYXMgbWVndGV0ZWxlLiBBeiBlbGphcmFz
IGxlaXJhc2EgbWVndGFsYWxoYXRvIGEgTmV0TG9jayBLZnQuIEludGVybmV0IGhvbmxhcGphbiBh
IGh0dHBzOi8vd3d3Lm5ldGxvY2submV0L2RvY3MgY2ltZW4gdmFneSBrZXJoZXRvIGF6IGVsbGVu
b3J6ZXNAbmV0bG9jay5uZXQgZS1tYWlsIGNpbWVuLiBJTVBPUlRBTlQhIFRoZSBpc3N1YW5jZSBh
bmQgdGhlIHVzZSBvZiB0aGlzIGNlcnRpZmljYXRlIGlzIHN1YmplY3QgdG8gdGhlIE5ldExvY2sg
Q1BTIGF2YWlsYWJsZSBhdCBodHRwczovL3d3dy5uZXRsb2NrLm5ldC9kb2NzIG9yIGJ5IGUtbWFp
bCBhdCBjcHNAbmV0bG9jay5uZXQuMA0GCSqGSIb3DQEBBAUAA4IBAQBIJEb3ulZv+sgoA0BO5TE5
ayZrU3/b39/zcT0mwBQOxmd7I6gMc90Bu8bKbjc5VdXHjFYgDigKDtIqpLBJUsY4B/6+CgmM0ZjP
ytoUMaFP0jn8DxEsQ8Pdq5PHVT5HfBgaANzze9jyf1JsIPQLX2lS9O74silg6+NJMSEN1rUQQeJB
CWziGppWS3cC9qCbmieH6FUpccKQn0V4GuEVZD3QDtigdp+uxdAu6tYPVuxkf1qbFFgBJ34TUMdr
KuZoPL9coAob4Q566eKAw+np9v1sEZ7Q5SgnK1QyQhSCdeZK8CtmdWOMovsEPoMOmzbwGOQmIMOM
8CgHrTwXZoi1/baI
-----END CERTIFICATE-----

XRamp Global CA Root
====================
-----BEGIN CERTIFICATE-----
MIIEMDCCAxigAwIBAgIQUJRs7Bjq1ZxN1ZfvdY+grTANBgkqhkiG9w0BAQUFADCBgjELMAkGA1UE
BhMCVVMxHjAcBgNVBAsTFXd3dy54cmFtcHNlY3VyaXR5LmNvbTEkMCIGA1UEChMbWFJhbXAgU2Vj
dXJpdHkgU2VydmljZXMgSW5jMS0wKwYDVQQDEyRYUmFtcCBHbG9iYWwgQ2VydGlmaWNhdGlvbiBB
dXRob3JpdHkwHhcNMDQxMTAxMTcxNDA0WhcNMzUwMTAxMDUzNzE5WjCBgjELMAkGA1UEBhMCVVMx
HjAcBgNVBAsTFXd3dy54cmFtcHNlY3VyaXR5LmNvbTEkMCIGA1UEChMbWFJhbXAgU2VjdXJpdHkg
U2VydmljZXMgSW5jMS0wKwYDVQQDEyRYUmFtcCBHbG9iYWwgQ2VydGlmaWNhdGlvbiBBdXRob3Jp
dHkwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQCYJB69FbS638eMpSe2OAtp87ZOqCwu
IR1cRN8hXX4jdP5efrRKt6atH67gBhbim1vZZ3RrXYCPKZ2GG9mcDZhtdhAoWORlsH9KmHmf4MMx
foArtYzAQDsRhtDLooY2YKTVMIJt2W7QDxIEM5dfT2Fa8OT5kavnHTu86M/0ay00fOJIYRyO82FE
zG+gSqmUsE3a56k0enI4qEHMPJQRfevIpoy3hsvKMzvZPTeL+3o+hiznc9cKV6xkmxnr9A8ECIqs
AxcZZPRaJSKNNCyy9mgdEm3Tih4U2sSPpuIjhdV6Db1q4Ons7Be7QhtnqiXtRYMh/MHJfNViPvry
xS3T/dRlAgMBAAGjgZ8wgZwwEwYJKwYBBAGCNxQCBAYeBABDAEEwCwYDVR0PBAQDAgGGMA8GA1Ud
EwEB/wQFMAMBAf8wHQYDVR0OBBYEFMZPoj0GY4QJnM5i5ASsjVy16bYbMDYGA1UdHwQvMC0wK6Ap
oCeGJWh0dHA6Ly9jcmwueHJhbXBzZWN1cml0eS5jb20vWEdDQS5jcmwwEAYJKwYBBAGCNxUBBAMC
AQEwDQYJKoZIhvcNAQEFBQADggEBAJEVOQMBG2f7Shz5CmBbodpNl2L5JFMn14JkTpAuw0kbK5rc
/Kh4ZzXxHfARvbdI4xD2Dd8/0sm2qlWkSLoC295ZLhVbO50WfUfXN+pfTXYSNrsf16GBBEYgoyxt
qZ4Bfj8pzgCT3/3JknOJiWSe5yvkHJEs0rnOfc5vMZnT5r7SHpDwCRR5XCOrTdLaIR9NmXmd4c8n
nxCbHIgNsIpkQTG4DmyQJKSbXHGPurt+HBvbaoAPIbzp26a3QPSyi6mx5O+aGtA9aZnuqCij4Tyz
8LIRnM98QObd50N9otg6tamN8jSZxNQQ4Qb9CYQQO+7ETPTsJ3xCwnR8gooJybQDJbw=
-----END CERTIFICATE-----

Go Daddy Class 2 CA
===================
-----BEGIN CERTIFICATE-----
MIIEADCCAuigAwIBAgIBADANBgkqhkiG9w0BAQUFADBjMQswCQYDVQQGEwJVUzEhMB8GA1UEChMY
VGhlIEdvIERhZGR5IEdyb3VwLCBJbmMuMTEwLwYDVQQLEyhHbyBEYWRkeSBDbGFzcyAyIENlcnRp
ZmljYXRpb24gQXV0aG9yaXR5MB4XDTA0MDYyOTE3MDYyMFoXDTM0MDYyOTE3MDYyMFowYzELMAkG
A1UEBhMCVVMxITAfBgNVBAoTGFRoZSBHbyBEYWRkeSBHcm91cCwgSW5jLjExMC8GA1UECxMoR28g
RGFkZHkgQ2xhc3MgMiBDZXJ0aWZpY2F0aW9uIEF1dGhvcml0eTCCASAwDQYJKoZIhvcNAQEBBQAD
ggENADCCAQgCggEBAN6d1+pXGEmhW+vXX0iG6r7d/+TvZxz0ZWizV3GgXne77ZtJ6XCAPVYYYwhv
2vLM0D9/AlQiVBDYsoHUwHU9S3/Hd8M+eKsaA7Ugay9qK7HFiH7Eux6wwdhFJ2+qN1j3hybX2C32
qRe3H3I2TqYXP2WYktsqbl2i/ojgC95/5Y0V4evLOtXiEqITLdiOr18SPaAIBQi2XKVlOARFmR6j
YGB0xUGlcmIbYsUfb18aQr4CUWWoriMYavx4A6lNf4DD+qta/KFApMoZFv6yyO9ecw3ud72a9nmY
vLEHZ6IVDd2gWMZEewo+YihfukEHU1jPEX44dMX4/7VpkI+EdOqXG68CAQOjgcAwgb0wHQYDVR0O
BBYEFNLEsNKR1EwRcbNhyz2h/t2oatTjMIGNBgNVHSMEgYUwgYKAFNLEsNKR1EwRcbNhyz2h/t2o
atTjoWekZTBjMQswCQYDVQQGEwJVUzEhMB8GA1UEChMYVGhlIEdvIERhZGR5IEdyb3VwLCBJbmMu
MTEwLwYDVQQLEyhHbyBEYWRkeSBDbGFzcyAyIENlcnRpZmljYXRpb24gQXV0aG9yaXR5ggEAMAwG
A1UdEwQFMAMBAf8wDQYJKoZIhvcNAQEFBQADggEBADJL87LKPpH8EsahB4yOd6AzBhRckB4Y9wim
PQoZ+YeAEW5p5JYXMP80kWNyOO7MHAGjHZQopDH2esRU1/blMVgDoszOYtuURXO1v0XJJLXVggKt
I3lpjbi2Tc7PTMozI+gciKqdi0FuFskg5YmezTvacPd+mSYgFFQlq25zheabIZ0KbIIOqPjCDPoQ
HmyW74cNxA9hi63ugyuV+I6ShHI56yDqg+2DzZduCLzrTia2cyvk0/ZM/iZx4mERdEr/VxqHD3VI
Ls9RaRegAhJhldXRQLIQTO7ErBBDpqWeCtWVYpoNz4iCxTIM5CufReYNnyicsbkqWletNw+vHX/b
vZ8=
-----END CERTIFICATE-----

Starfield Class 2 CA
====================
-----BEGIN CERTIFICATE-----
MIIEDzCCAvegAwIBAgIBADANBgkqhkiG9w0BAQUFADBoMQswCQYDVQQGEwJVUzElMCMGA1UEChMc
U3RhcmZpZWxkIFRlY2hub2xvZ2llcywgSW5jLjEyMDAGA1UECxMpU3RhcmZpZWxkIENsYXNzIDIg
Q2VydGlmaWNhdGlvbiBBdXRob3JpdHkwHhcNMDQwNjI5MTczOTE2WhcNMzQwNjI5MTczOTE2WjBo
MQswCQYDVQQGEwJVUzElMCMGA1UEChMcU3RhcmZpZWxkIFRlY2hub2xvZ2llcywgSW5jLjEyMDAG
A1UECxMpU3RhcmZpZWxkIENsYXNzIDIgQ2VydGlmaWNhdGlvbiBBdXRob3JpdHkwggEgMA0GCSqG
SIb3DQEBAQUAA4IBDQAwggEIAoIBAQC3Msj+6XGmBIWtDBFk385N78gDGIc/oav7PKaf8MOh2tTY
bitTkPskpD6E8J7oX+zlJ0T1KKY/e97gKvDIr1MvnsoFAZMej2YcOadN+lq2cwQlZut3f+dZxkqZ
JRRU6ybH838Z1TBwj6+wRir/resp7defqgSHo9T5iaU0X9tDkYI22WY8sbi5gv2cOj4QyDvvBmVm
epsZGD3/cVE8MC5fvj13c7JdBmzDI1aaK4UmkhynArPkPw2vCHmCuDY96pzTNbO8acr1zJ3o/WSN
F4Azbl5KXZnJHoe0nRrA1W4TNSNe35tfPe/W93bC6j67eA0cQmdrBNj41tpvi/JEoAGrAgEDo4HF
MIHCMB0GA1UdDgQWBBS/X7fRzt0fhvRbVazc1xDCDqmI5zCBkgYDVR0jBIGKMIGHgBS/X7fRzt0f
hvRbVazc1xDCDqmI56FspGowaDELMAkGA1UEBhMCVVMxJTAjBgNVBAoTHFN0YXJmaWVsZCBUZWNo
bm9sb2dpZXMsIEluYy4xMjAwBgNVBAsTKVN0YXJmaWVsZCBDbGFzcyAyIENlcnRpZmljYXRpb24g
QXV0aG9yaXR5ggEAMAwGA1UdEwQFMAMBAf8wDQYJKoZIhvcNAQEFBQADggEBAAWdP4id0ckaVaGs
afPzWdqbAYcaT1epoXkJKtv3L7IezMdeatiDh6GX70k1PncGQVhiv45YuApnP+yz3SFmH8lU+nLM
PUxA2IGvd56Deruix/U0F47ZEUD0/CwqTRV/p2JdLiXTAAsgGh1o+Re49L2L7ShZ3U0WixeDyLJl
xy16paq8U4Zt3VekyvggQQto8PT7dL5WXXp59fkdheMtlb71cZBDzI0fmgAKhynpVSJYACPq4xJD
KVtHCN2MQWplBqjlIapBtJUhlbl90TSrE9atvNziPTnNvT51cKEYWQPJIrSPnNVeKtelttQKbfi3
QBFGmh95DmK/D5fs4C8fF5Q=
-----END CERTIFICATE-----

StartCom Certification Authority
================================
-----BEGIN CERTIFICATE-----
MIIHyTCCBbGgAwIBAgIBATANBgkqhkiG9w0BAQUFADB9MQswCQYDVQQGEwJJTDEWMBQGA1UEChMN
U3RhcnRDb20gTHRkLjErMCkGA1UECxMiU2VjdXJlIERpZ2l0YWwgQ2VydGlmaWNhdGUgU2lnbmlu
ZzEpMCcGA1UEAxMgU3RhcnRDb20gQ2VydGlmaWNhdGlvbiBBdXRob3JpdHkwHhcNMDYwOTE3MTk0
NjM2WhcNMzYwOTE3MTk0NjM2WjB9MQswCQYDVQQGEwJJTDEWMBQGA1UEChMNU3RhcnRDb20gTHRk
LjErMCkGA1UECxMiU2VjdXJlIERpZ2l0YWwgQ2VydGlmaWNhdGUgU2lnbmluZzEpMCcGA1UEAxMg
U3RhcnRDb20gQ2VydGlmaWNhdGlvbiBBdXRob3JpdHkwggIiMA0GCSqGSIb3DQEBAQUAA4ICDwAw
ggIKAoICAQDBiNsJvGxGfHiflXu1M5DycmLWwTYgIiRezul38kMKogZkpMyONvg45iPwbm2xPN1y
o4UcodM9tDMr0y+v/uqwQVlntsQGfQqedIXWeUyAN3rfOQVSWff0G0ZDpNKFhdLDcfN1YjS6LIp/
Ho/u7TTQEceWzVI9ujPW3U3eCztKS5/CJi/6tRYccjV3yjxd5srhJosaNnZcAdt0FCX+7bWgiA/d
eMotHweXMAEtcnn6RtYTKqi5pquDSR3l8u/d5AGOGAqPY1MWhWKpDhk6zLVmpsJrdAfkK+F2PrRt
2PZE4XNiHzvEvqBTViVsUQn3qqvKv3b9bZvzndu/PWa8DFaqr5hIlTpL36dYUNk4dalb6kMMAv+Z
6+hsTXBbKWWc3apdzK8BMewM69KN6Oqce+Zu9ydmDBpI125C4z/eIT574Q1w+2OqqGwaVLRcJXrJ
osmLFqa7LH4XXgVNWG4SHQHuEhANxjJ/GP/89PrNbpHoNkm+Gkhpi8KWTRoSsmkXwQqQ1vp5Iki/
untp+HDH+no32NgN0nZPV/+Qt+OR0t3vwmC3Zzrd/qqc8NSLf3Iizsafl7b4r4qgEKjZ+xjGtrVc
UjyJthkqcwEKDwOzEmDyei+B26Nu/yYwl/WL3YlXtq09s68rxbd2AvCl1iuahhQqcvbjM4xdCUsT
37uMdBNSSwIDAQABo4ICUjCCAk4wDAYDVR0TBAUwAwEB/zALBgNVHQ8EBAMCAa4wHQYDVR0OBBYE
FE4L7xqkQFulF2mHMMo0aEPQQa7yMGQGA1UdHwRdMFswLKAqoCiGJmh0dHA6Ly9jZXJ0LnN0YXJ0
Y29tLm9yZy9zZnNjYS1jcmwuY3JsMCugKaAnhiVodHRwOi8vY3JsLnN0YXJ0Y29tLm9yZy9zZnNj
YS1jcmwuY3JsMIIBXQYDVR0gBIIBVDCCAVAwggFMBgsrBgEEAYG1NwEBATCCATswLwYIKwYBBQUH
AgEWI2h0dHA6Ly9jZXJ0LnN0YXJ0Y29tLm9yZy9wb2xpY3kucGRmMDUGCCsGAQUFBwIBFilodHRw
Oi8vY2VydC5zdGFydGNvbS5vcmcvaW50ZXJtZWRpYXRlLnBkZjCB0AYIKwYBBQUHAgIwgcMwJxYg
U3RhcnQgQ29tbWVyY2lhbCAoU3RhcnRDb20pIEx0ZC4wAwIBARqBl0xpbWl0ZWQgTGlhYmlsaXR5
LCByZWFkIHRoZSBzZWN0aW9uICpMZWdhbCBMaW1pdGF0aW9ucyogb2YgdGhlIFN0YXJ0Q29tIENl
cnRpZmljYXRpb24gQXV0aG9yaXR5IFBvbGljeSBhdmFpbGFibGUgYXQgaHR0cDovL2NlcnQuc3Rh
cnRjb20ub3JnL3BvbGljeS5wZGYwEQYJYIZIAYb4QgEBBAQDAgAHMDgGCWCGSAGG+EIBDQQrFilT
dGFydENvbSBGcmVlIFNTTCBDZXJ0aWZpY2F0aW9uIEF1dGhvcml0eTANBgkqhkiG9w0BAQUFAAOC
AgEAFmyZ9GYMNPXQhV59CuzaEE44HF7fpiUFS5Eyweg78T3dRAlbB0mKKctmArexmvclmAk8jhvh
3TaHK0u7aNM5Zj2gJsfyOZEdUauCe37Vzlrk4gNXcGmXCPleWKYK34wGmkUWFjgKXlf2Ysd6AgXm
vB618p70qSmD+LIU424oh0TDkBreOKk8rENNZEXO3SipXPJzewT4F+irsfMuXGRuczE6Eri8sxHk
fY+BUZo7jYn0TZNmezwD7dOaHZrzZVD1oNB1ny+v8OqCQ5j4aZyJecRDjkZy42Q2Eq/3JR44iZB3
fsNrarnDy0RLrHiQi+fHLB5LEUTINFInzQpdn4XBidUaePKVEFMy3YCEZnXZtWgo+2EuvoSoOMCZ
EoalHmdkrQYuL6lwhceWD3yJZfWOQ1QOq92lgDmUYMA0yZZwLKMS9R9Ie70cfmu3nZD0Ijuu+Pwq
yvqCUqDvr0tVk+vBtfAii6w0TiYiBKGHLHVKt+V9E9e4DGTANtLJL4YSjCMJwRuCO3NJo2pXh5Tl
1njFmUNj403gdy3hZZlyaQQaRwnmDwFWJPsfvw55qVguucQJAX6Vum0ABj6y6koQOdjQK/W/7HW/
lwLFCRsI3FU34oH7N4RDYiDK51ZLZer+bMEkkyShNOsF/5oirpt9P/FlUQqmMGqz9IgcgA38coro
g14=
-----END CERTIFICATE-----

Taiwan GRCA
===========
-----BEGIN CERTIFICATE-----
MIIFcjCCA1qgAwIBAgIQH51ZWtcvwgZEpYAIaeNe9jANBgkqhkiG9w0BAQUFADA/MQswCQYDVQQG
EwJUVzEwMC4GA1UECgwnR292ZXJubWVudCBSb290IENlcnRpZmljYXRpb24gQXV0aG9yaXR5MB4X
DTAyMTIwNTEzMjMzM1oXDTMyMTIwNTEzMjMzM1owPzELMAkGA1UEBhMCVFcxMDAuBgNVBAoMJ0dv
dmVybm1lbnQgUm9vdCBDZXJ0aWZpY2F0aW9uIEF1dGhvcml0eTCCAiIwDQYJKoZIhvcNAQEBBQAD
ggIPADCCAgoCggIBAJoluOzMonWoe/fOW1mKydGGEghU7Jzy50b2iPN86aXfTEc2pBsBHH8eV4qN
w8XRIePaJD9IK/ufLqGU5ywck9G/GwGHU5nOp/UKIXZ3/6m3xnOUT0b3EEk3+qhZSV1qgQdW8or5
BtD3cCJNtLdBuTK4sfCxw5w/cP1T3YGq2GN49thTbqGsaoQkclSGxtKyyhwOeYHWtXBiCAEuTk8O
1RGvqa/lmr/czIdtJuTJV6L7lvnM4T9TjGxMfptTCAtsF/tnyMKtsc2AtJfcdgEWFelq16TheEfO
htX7MfP6Mb40qij7cEwdScevLJ1tZqa2jWR+tSBqnTuBto9AAGdLiYa4zGX+FVPpBMHWXx1E1wov
J5pGfaENda1UhhXcSTvxls4Pm6Dso3pdvtUqdULle96ltqqvKKyskKw4t9VoNSZ63Pc78/1Fm9G7
Q3hub/FCVGqY8A2tl+lSXunVanLeavcbYBT0peS2cWeqH+riTcFCQP5nRhc4L0c/cZyu5SHKYS1t
B6iEfC3uUSXxY5Ce/eFXiGvviiNtsea9P63RPZYLhY3Naye7twWb7LuRqQoHEgKXTiCQ8P8NHuJB
O9NAOueNXdpm5AKwB1KYXA6OM5zCppX7VRluTI6uSw+9wThNXo+EHWbNxWCWtFJaBYmOlXqYwZE8
lSOyDvR5tMl8wUohAgMBAAGjajBoMB0GA1UdDgQWBBTMzO/MKWCkO7GStjz6MmKPrCUVOzAMBgNV
HRMEBTADAQH/MDkGBGcqBwAEMTAvMC0CAQAwCQYFKw4DAhoFADAHBgVnKgMAAAQUA5vwIhP/lSg2
09yewDL7MTqKUWUwDQYJKoZIhvcNAQEFBQADggIBAECASvomyc5eMN1PhnR2WPWus4MzeKR6dBcZ
TulStbngCnRiqmjKeKBMmo4sIy7VahIkv9Ro04rQ2JyftB8M3jh+Vzj8jeJPXgyfqzvS/3WXy6Tj
Zwj/5cAWtUgBfen5Cv8b5Wppv3ghqMKnI6mGq3ZW6A4M9hPdKmaKZEk9GhiHkASfQlK3T8v+R0F2
Ne//AHY2RTKbxkaFXeIksB7jSJaYV0eUVXoPQbFEJPPB/hprv4j9wabak2BegUqZIJxIZhm1AHlU
D7gsL0u8qV1bYH+Mh6XgUmMqvtg7hUAV/h62ZT/FS9p+tXo1KaMuephgIqP0fSdOLeq0dDzpD6Qz
DxARvBMB1uUO07+1EqLhRSPAzAhuYbeJq4PjJB7mXQfnHyA+z2fI56wwbSdLaG5LKlwCCDTb+Hbk
Z6MmnD+iMsJKxYEYMRBWqoTvLQr/uB930r+lWKBi5NdLkXWNiYCYfm3LU05er/ayl4WXudpVBrkk
7tfGOB5jGxI7leFYrPLfhNVfmS8NVVvmONsuP3LpSIXLuykTjx44VbnzssQwmSNOXfJIoRIM3BKQ
CZBUkQM8R+XVyWXgt0t97EfTsws+rZ7QdAAO671RrcDeLMDDav7v3Aun+kbfYNucpllQdSNpc5Oy
+fwC00fmcc4QAu4njIT/rEUNE1yDMuAlpYYsfPQS
-----END CERTIFICATE-----

Swisscom Root CA 1
==================
-----BEGIN CERTIFICATE-----
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
NY6E0F/6MBr1mmz0DlP5OlvRHA==
-----END CERTIFICATE-----

DigiCert Assured ID Root CA
===========================
-----BEGIN CERTIFICATE-----
MIIDtzCCAp+gAwIBAgIQDOfg5RfYRv6P5WD8G/AwOTANBgkqhkiG9w0BAQUFADBlMQswCQYDVQQG
EwJVUzEVMBMGA1UEChMMRGlnaUNlcnQgSW5jMRkwFwYDVQQLExB3d3cuZGlnaWNlcnQuY29tMSQw
IgYDVQQDExtEaWdpQ2VydCBBc3N1cmVkIElEIFJvb3QgQ0EwHhcNMDYxMTEwMDAwMDAwWhcNMzEx
MTEwMDAwMDAwWjBlMQswCQYDVQQGEwJVUzEVMBMGA1UEChMMRGlnaUNlcnQgSW5jMRkwFwYDVQQL
ExB3d3cuZGlnaWNlcnQuY29tMSQwIgYDVQQDExtEaWdpQ2VydCBBc3N1cmVkIElEIFJvb3QgQ0Ew
ggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQCtDhXO5EOAXLGH87dg+XESpa7cJpSIqvTO
9SA5KFhgDPiA2qkVlTJhPLWxKISKityfCgyDF3qPkKyK53lTXDGEKvYPmDI2dsze3Tyoou9q+yHy
UmHfnyDXH+Kx2f4YZNISW1/5WBg1vEfNoTb5a3/UsDg+wRvDjDPZ2C8Y/igPs6eD1sNuRMBhNZYW
/lmci3Zt1/GiSw0r/wty2p5g0I6QNcZ4VYcgoc/lbQrISXwxmDNsIumH0DJaoroTghHtORedmTpy
oeb6pNnVFzF1roV9Iq4/AUaG9ih5yLHa5FcXxH4cDrC0kqZWs72yl+2qp/C3xag/lRbQ/6GW6whf
GHdPAgMBAAGjYzBhMA4GA1UdDwEB/wQEAwIBhjAPBgNVHRMBAf8EBTADAQH/MB0GA1UdDgQWBBRF
66Kv9JLLgjEtUYunpyGd823IDzAfBgNVHSMEGDAWgBRF66Kv9JLLgjEtUYunpyGd823IDzANBgkq
hkiG9w0BAQUFAAOCAQEAog683+Lt8ONyc3pklL/3cmbYMuRCdWKuh+vy1dneVrOfzM4UKLkNl2Bc
EkxY5NM9g0lFWJc1aRqoR+pWxnmrEthngYTffwk8lOa4JiwgvT2zKIn3X/8i4peEH+ll74fg38Fn
SbNd67IJKusm7Xi+fT8r87cmNW1fiQG2SVufAQWbqz0lwcy2f8Lxb4bG+mRo64EtlOtCt/qMHt1i
8b5QZ7dsvfPxH2sMNgcWfzd8qVttevESRmCD1ycEvkvOl77DZypoEd+A5wwzZr8TDRRu838fYxAe
+o0bJW1sj6W3YQGx0qMmoRBxna3iw/nDmVG3KwcIzi7mULKn+gpFL6Lw8g==
-----END CERTIFICATE-----

DigiCert Global Root CA
=======================
-----BEGIN CERTIFICATE-----
MIIDrzCCApegAwIBAgIQCDvgVpBCRrGhdWrJWZHHSjANBgkqhkiG9w0BAQUFADBhMQswCQYDVQQG
EwJVUzEVMBMGA1UEChMMRGlnaUNlcnQgSW5jMRkwFwYDVQQLExB3d3cuZGlnaWNlcnQuY29tMSAw
HgYDVQQDExdEaWdpQ2VydCBHbG9iYWwgUm9vdCBDQTAeFw0wNjExMTAwMDAwMDBaFw0zMTExMTAw
MDAwMDBaMGExCzAJBgNVBAYTAlVTMRUwEwYDVQQKEwxEaWdpQ2VydCBJbmMxGTAXBgNVBAsTEHd3
dy5kaWdpY2VydC5jb20xIDAeBgNVBAMTF0RpZ2lDZXJ0IEdsb2JhbCBSb290IENBMIIBIjANBgkq
hkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA4jvhEXLeqKTTo1eqUKKPC3eQyaKl7hLOllsBCSDMAZOn
TjC3U/dDxGkAV53ijSLdhwZAAIEJzs4bg7/fzTtxRuLWZscFs3YnFo97nh6Vfe63SKMI2tavegw5
BmV/Sl0fvBf4q77uKNd0f3p4mVmFaG5cIzJLv07A6Fpt43C/dxC//AH2hdmoRBBYMql1GNXRor5H
4idq9Joz+EkIYIvUX7Q6hL+hqkpMfT7PT19sdl6gSzeRntwi5m3OFBqOasv+zbMUZBfHWymeMr/y
7vrTC0LUq7dBMtoM1O/4gdW7jVg/tRvoSSiicNoxBN33shbyTApOB6jtSj1etX+jkMOvJwIDAQAB
o2MwYTAOBgNVHQ8BAf8EBAMCAYYwDwYDVR0TAQH/BAUwAwEB/zAdBgNVHQ4EFgQUA95QNVbRTLtm
8KPiGxvDl7I90VUwHwYDVR0jBBgwFoAUA95QNVbRTLtm8KPiGxvDl7I90VUwDQYJKoZIhvcNAQEF
BQADggEBAMucN6pIExIK+t1EnE9SsPTfrgT1eXkIoyQY/EsrhMAtudXH/vTBH1jLuG2cenTnmCmr
EbXjcKChzUyImZOMkXDiqw8cvpOp/2PV5Adg06O/nVsJ8dWO41P0jmP6P6fbtGbfYmbW0W5BjfIt
tep3Sp+dWOIrWcBAI+0tKIJFPnlUkiaY4IBIqDfv8NZ5YBberOgOzW6sRBc4L0na4UU+Krk2U886
UAb3LujEV0lsYSEY1QSteDwsOoBrp+uvFRTp2InBuThs4pFsiv9kuXclVzDAGySj4dzp30d8tbQk
CAUw7C29C79Fv1C5qfPrmAESrciIxpg0X40KPMbp1ZWVbd4=
-----END CERTIFICATE-----

DigiCert High Assurance EV Root CA
==================================
-----BEGIN CERTIFICATE-----
MIIDxTCCAq2gAwIBAgIQAqxcJmoLQJuPC3nyrkYldzANBgkqhkiG9w0BAQUFADBsMQswCQYDVQQG
EwJVUzEVMBMGA1UEChMMRGlnaUNlcnQgSW5jMRkwFwYDVQQLExB3d3cuZGlnaWNlcnQuY29tMSsw
KQYDVQQDEyJEaWdpQ2VydCBIaWdoIEFzc3VyYW5jZSBFViBSb290IENBMB4XDTA2MTExMDAwMDAw
MFoXDTMxMTExMDAwMDAwMFowbDELMAkGA1UEBhMCVVMxFTATBgNVBAoTDERpZ2lDZXJ0IEluYzEZ
MBcGA1UECxMQd3d3LmRpZ2ljZXJ0LmNvbTErMCkGA1UEAxMiRGlnaUNlcnQgSGlnaCBBc3N1cmFu
Y2UgRVYgUm9vdCBDQTCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAMbM5XPm+9S75S0t
Mqbf5YE/yc0lSbZxKsPVlDRnogocsF9ppkCxxLeyj9CYpKlBWTrT3JTWPNt0OKRKzE0lgvdKpVMS
OO7zSW1xkX5jtqumX8OkhPhPYlG++MXs2ziS4wblCJEMxChBVfvLWokVfnHoNb9Ncgk9vjo4UFt3
MRuNs8ckRZqnrG0AFFoEt7oT61EKmEFBIk5lYYeBQVCmeVyJ3hlKV9Uu5l0cUyx+mM0aBhakaHPQ
NAQTXKFx01p8VdteZOE3hzBWBOURtCmAEvF5OYiiAhF8J2a3iLd48soKqDirCmTCv2ZdlYTBoSUe
h10aUAsgEsxBu24LUTi4S8sCAwEAAaNjMGEwDgYDVR0PAQH/BAQDAgGGMA8GA1UdEwEB/wQFMAMB
Af8wHQYDVR0OBBYEFLE+w2kD+L9HAdSYJhoIAu9jZCvDMB8GA1UdIwQYMBaAFLE+w2kD+L9HAdSY
JhoIAu9jZCvDMA0GCSqGSIb3DQEBBQUAA4IBAQAcGgaX3NecnzyIZgYIVyHbIUf4KmeqvxgydkAQ
V8GK83rZEWWONfqe/EW1ntlMMUu4kehDLI6zeM7b41N5cdblIZQB2lWHmiRk9opmzN6cN82oNLFp
myPInngiK3BD41VHMWEZ71jFhS9OMPagMRYjyOfiZRYzy78aG6A9+MpeizGLYAiJLQwGXFK3xPkK
mNEVX58Svnw2Yzi9RKR/5CYrCsSXaQ3pjOLAEFe4yHYSkVXySGnYvCoCWw9E1CAx2/S6cCZdkGCe
vEsXCS+0yx5DaMkHJ8HSXPfqIbloEpw8nL+e/IBcm2PN7EeqJSdnoDfzAIJ9VNep+OkuE6N36B9K
-----END CERTIFICATE-----

Certplus Class 2 Primary CA
===========================
-----BEGIN CERTIFICATE-----
MIIDkjCCAnqgAwIBAgIRAIW9S/PY2uNp9pTXX8OlRCMwDQYJKoZIhvcNAQEFBQAwPTELMAkGA1UE
BhMCRlIxETAPBgNVBAoTCENlcnRwbHVzMRswGQYDVQQDExJDbGFzcyAyIFByaW1hcnkgQ0EwHhcN
OTkwNzA3MTcwNTAwWhcNMTkwNzA2MjM1OTU5WjA9MQswCQYDVQQGEwJGUjERMA8GA1UEChMIQ2Vy
dHBsdXMxGzAZBgNVBAMTEkNsYXNzIDIgUHJpbWFyeSBDQTCCASIwDQYJKoZIhvcNAQEBBQADggEP
ADCCAQoCggEBANxQltAS+DXSCHh6tlJw/W/uz7kRy1134ezpfgSN1sxvc0NXYKwzCkTsA18cgCSR
5aiRVhKC9+Ar9NuuYS6JEI1rbLqzAr3VNsVINyPi8Fo3UjMXEuLRYE2+L0ER4/YXJQyLkcAbmXuZ
Vg2v7tK8R1fjeUl7NIknJITesezpWE7+Tt9avkGtrAjFGA7v0lPubNCdEgETjdyAYveVqUSISnFO
YFWe2yMZeVYHDD9jC1yw4r5+FfyUM1hBOHTE4Y+L3yasH7WLO7dDWWuwJKZtkIvEcupdM5i3y95e
e++U8Rs+yskhwcWYAqqi9lt3m/V+llU0HGdpwPFC40es/CgcZlUCAwEAAaOBjDCBiTAPBgNVHRME
CDAGAQH/AgEKMAsGA1UdDwQEAwIBBjAdBgNVHQ4EFgQU43Mt38sOKAze3bOkynm4jrvoMIkwEQYJ
YIZIAYb4QgEBBAQDAgEGMDcGA1UdHwQwMC4wLKAqoCiGJmh0dHA6Ly93d3cuY2VydHBsdXMuY29t
L0NSTC9jbGFzczIuY3JsMA0GCSqGSIb3DQEBBQUAA4IBAQCnVM+IRBnL39R/AN9WM2K191EBkOvD
P9GIROkkXe/nFL0gt5o8AP5tn9uQ3Nf0YtaLcF3n5QRIqWh8yfFC82x/xXp8HVGIutIKPidd3i1R
TtMTZGnkLuPT55sJmabglZvOGtd/vjzOUrMRFcEPF80Du5wlFbqidon8BvEY0JNLDnyCt6X09l/+
7UCmnYR0ObncHoUW2ikbhiMAybuJfm6AiB4vFLQDJKgybwOaRywwvlbGp0ICcBvqQNi6BQNwB6SW
//1IMwrh3KWBkJtN3X3n57LNXMhqlfil9o3EXXgIvnsG1knPGTZQIy4I5p4FTUcY1Rbpsda2ENW7
l7+ijrRU
-----END CERTIFICATE-----

DST Root CA X3
==============
-----BEGIN CERTIFICATE-----
MIIDSjCCAjKgAwIBAgIQRK+wgNajJ7qJMDmGLvhAazANBgkqhkiG9w0BAQUFADA/MSQwIgYDVQQK
ExtEaWdpdGFsIFNpZ25hdHVyZSBUcnVzdCBDby4xFzAVBgNVBAMTDkRTVCBSb290IENBIFgzMB4X
DTAwMDkzMDIxMTIxOVoXDTIxMDkzMDE0MDExNVowPzEkMCIGA1UEChMbRGlnaXRhbCBTaWduYXR1
cmUgVHJ1c3QgQ28uMRcwFQYDVQQDEw5EU1QgUm9vdCBDQSBYMzCCASIwDQYJKoZIhvcNAQEBBQAD
ggEPADCCAQoCggEBAN+v6ZdQCINXtMxiZfaQguzH0yxrMMpb7NnDfcdAwRgUi+DoM3ZJKuM/IUmT
rE4Orz5Iy2Xu/NMhD2XSKtkyj4zl93ewEnu1lcCJo6m67XMuegwGMoOifooUMM0RoOEqOLl5CjH9
UL2AZd+3UWODyOKIYepLYYHsUmu5ouJLGiifSKOeDNoJjj4XLh7dIN9bxiqKqy69cK3FCxolkHRy
xXtqqzTWMIn/5WgTe1QLyNau7Fqckh49ZLOMxt+/yUFw7BZy1SbsOFU5Q9D8/RhcQPGX69Wam40d
utolucbY38EVAjqr2m7xPi71XAicPNaDaeQQmxkqtilX4+U9m5/wAl0CAwEAAaNCMEAwDwYDVR0T
AQH/BAUwAwEB/zAOBgNVHQ8BAf8EBAMCAQYwHQYDVR0OBBYEFMSnsaR7LHH62+FLkHX/xBVghYkQ
MA0GCSqGSIb3DQEBBQUAA4IBAQCjGiybFwBcqR7uKGY3Or+Dxz9LwwmglSBd49lZRNI+DT69ikug
dB/OEIKcdBodfpga3csTS7MgROSR6cz8faXbauX+5v3gTt23ADq1cEmv8uXrAvHRAosZy5Q6XkjE
GB5YGV8eAlrwDPGxrancWYaLbumR9YbK+rlmM6pZW87ipxZzR8srzJmwN0jP41ZL9c8PDHIyh8bw
RLtTcm1D9SZImlJnt1ir/md2cXjbDaJWFBM5JDGFoqgCWjBH4d1QB7wCCZAA62RjYJsWvIjJEubS
fZGL+T0yjWW06XyxV3bqxbYoOb8VZRzI9neWagqNdwvYkQsEjgfbKbYK7p2CNTUQ
-----END CERTIFICATE-----

DST ACES CA X6
==============
-----BEGIN CERTIFICATE-----
MIIECTCCAvGgAwIBAgIQDV6ZCtadt3js2AdWO4YV2TANBgkqhkiG9w0BAQUFADBbMQswCQYDVQQG
EwJVUzEgMB4GA1UEChMXRGlnaXRhbCBTaWduYXR1cmUgVHJ1c3QxETAPBgNVBAsTCERTVCBBQ0VT
MRcwFQYDVQQDEw5EU1QgQUNFUyBDQSBYNjAeFw0wMzExMjAyMTE5NThaFw0xNzExMjAyMTE5NTha
MFsxCzAJBgNVBAYTAlVTMSAwHgYDVQQKExdEaWdpdGFsIFNpZ25hdHVyZSBUcnVzdDERMA8GA1UE
CxMIRFNUIEFDRVMxFzAVBgNVBAMTDkRTVCBBQ0VTIENBIFg2MIIBIjANBgkqhkiG9w0BAQEFAAOC
AQ8AMIIBCgKCAQEAuT31LMmU3HWKlV1j6IR3dma5WZFcRt2SPp/5DgO0PWGSvSMmtWPuktKe1jzI
DZBfZIGxqAgNTNj50wUoUrQBJcWVHAx+PhCEdc/BGZFjz+iokYi5Q1K7gLFViYsx+tC3dr5BPTCa
pCIlF3PoHuLTrCq9Wzgh1SpL11V94zpVvddtawJXa+ZHfAjIgrrep4c9oW24MFbCswKBXy314pow
GCi4ZtPLAZZv6opFVdbgnf9nKxcCpk4aahELfrd755jWjHZvwTvbUJN+5dCOHze4vbrGn2zpfDPy
MjwmR/onJALJfh1biEITajV8fTXpLmaRcpPVMibEdPVTo7NdmvYJywIDAQABo4HIMIHFMA8GA1Ud
EwEB/wQFMAMBAf8wDgYDVR0PAQH/BAQDAgHGMB8GA1UdEQQYMBaBFHBraS1vcHNAdHJ1c3Rkc3Qu
Y29tMGIGA1UdIARbMFkwVwYKYIZIAWUDAgEBATBJMEcGCCsGAQUFBwIBFjtodHRwOi8vd3d3LnRy
dXN0ZHN0LmNvbS9jZXJ0aWZpY2F0ZXMvcG9saWN5L0FDRVMtaW5kZXguaHRtbDAdBgNVHQ4EFgQU
CXIGThhDD+XWzMNqizF7eI+og7gwDQYJKoZIhvcNAQEFBQADggEBAKPYjtay284F5zLNAdMEA+V2
5FYrnJmQ6AgwbN99Pe7lv7UkQIRJ4dEorsTCOlMwiPH1d25Ryvr/ma8kXxug/fKshMrfqfBfBC6t
Fr8hlxCBPeP/h40y3JTlR4peahPJlJU90u7INJXQgNStMgiAVDzgvVJT11J8smk/f3rPanTK+gQq
nExaBqXpIK1FZg9p8d2/6eMyi/rgwYZNcjwu2JN4Cir42NInPRmJX1p7ijvMDNpRrscL9yuwNwXs
vFcj4jjSm2jzVhKIT0J8uDHEtdvkyCE06UgRNe76x5JXxZ805Mf29w4LTJxoeHtxMcfrHuBnQfO3
oKfN5XozNmr6mis=
-----END CERTIFICATE-----

TURKTRUST Certificate Services Provider Root 2
==============================================
-----BEGIN CERTIFICATE-----
MIIEPDCCAySgAwIBAgIBATANBgkqhkiG9w0BAQUFADCBvjE/MD0GA1UEAww2VMOcUktUUlVTVCBF
bGVrdHJvbmlrIFNlcnRpZmlrYSBIaXptZXQgU2HEn2xhecSxY8Sxc8SxMQswCQYDVQQGEwJUUjEP
MA0GA1UEBwwGQW5rYXJhMV0wWwYDVQQKDFRUw5xSS1RSVVNUIEJpbGdpIMSwbGV0acWfaW0gdmUg
QmlsacWfaW0gR8O8dmVubGnEn2kgSGl6bWV0bGVyaSBBLsWeLiAoYykgS2FzxLFtIDIwMDUwHhcN
MDUxMTA3MTAwNzU3WhcNMTUwOTE2MTAwNzU3WjCBvjE/MD0GA1UEAww2VMOcUktUUlVTVCBFbGVr
dHJvbmlrIFNlcnRpZmlrYSBIaXptZXQgU2HEn2xhecSxY8Sxc8SxMQswCQYDVQQGEwJUUjEPMA0G
A1UEBwwGQW5rYXJhMV0wWwYDVQQKDFRUw5xSS1RSVVNUIEJpbGdpIMSwbGV0acWfaW0gdmUgQmls
acWfaW0gR8O8dmVubGnEn2kgSGl6bWV0bGVyaSBBLsWeLiAoYykgS2FzxLFtIDIwMDUwggEiMA0G
CSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQCpNn7DkUNMwxmYCMjHWHtPFoylzkkBH3MOrHUTpvqe
LCDe2JAOCtFp0if7qnefJ1Il4std2NiDUBd9irWCPwSOtNXwSadktx4uXyCcUHVPr+G1QRT0mJKI
x+XlZEdhR3n9wFHxwZnn3M5q+6+1ATDcRhzviuyV79z/rxAc653YsKpqhRgNF8k+v/Gb0AmJQv2g
QrSdiVFVKc8bcLyEVK3BEx+Y9C52YItdP5qtygy/p1Zbj3e41Z55SZI/4PGXJHpsmxcPbe9TmJEr
5A++WXkHeLuXlfSfadRYhwqp48y2WBmfJiGxxFmNskF1wK1pzpwACPI2/z7woQ8arBT9pmAPAgMB
AAGjQzBBMB0GA1UdDgQWBBTZN7NOBf3Zz58SFq62iS/rJTqIHDAPBgNVHQ8BAf8EBQMDBwYAMA8G
A1UdEwEB/wQFMAMBAf8wDQYJKoZIhvcNAQEFBQADggEBAHJglrfJ3NgpXiOFX7KzLXb7iNcX/ntt
Rbj2hWyfIvwqECLsqrkw9qtY1jkQMZkpAL2JZkH7dN6RwRgLn7Vhy506vvWolKMiVW4XSf/SKfE4
Jl3vpao6+XF75tpYHdN0wgH6PmlYX63LaL4ULptswLbcoCb6dxriJNoaN+BnrdFzgw2lGh1uEpJ+
hGIAF728JRhX8tepb1mIvDS3LoV4nZbcFMMsilKbloxSZj2GFotHuFEJjOp9zYhys2AzsfAKRO8P
9Qk3iCQOLGsgOqL6EfJANZxEaGM7rDNvY7wsu/LSy3Z9fYjYHcgFHW68lKlmjHdxx/qR+i9Rnuk5
UrbnBEI=
-----END CERTIFICATE-----

SwissSign Gold CA - G2
======================
-----BEGIN CERTIFICATE-----
MIIFujCCA6KgAwIBAgIJALtAHEP1Xk+wMA0GCSqGSIb3DQEBBQUAMEUxCzAJBgNVBAYTAkNIMRUw
EwYDVQQKEwxTd2lzc1NpZ24gQUcxHzAdBgNVBAMTFlN3aXNzU2lnbiBHb2xkIENBIC0gRzIwHhcN
MDYxMDI1MDgzMDM1WhcNMzYxMDI1MDgzMDM1WjBFMQswCQYDVQQGEwJDSDEVMBMGA1UEChMMU3dp
c3NTaWduIEFHMR8wHQYDVQQDExZTd2lzc1NpZ24gR29sZCBDQSAtIEcyMIICIjANBgkqhkiG9w0B
AQEFAAOCAg8AMIICCgKCAgEAr+TufoskDhJuqVAtFkQ7kpJcyrhdhJJCEyq8ZVeCQD5XJM1QiyUq
t2/876LQwB8CJEoTlo8jE+YoWACjR8cGp4QjK7u9lit/VcyLwVcfDmJlD909Vopz2q5+bbqBHH5C
jCA12UNNhPqE21Is8w4ndwtrvxEvcnifLtg+5hg3Wipy+dpikJKVyh+c6bM8K8vzARO/Ws/BtQpg
vd21mWRTuKCWs2/iJneRjOBiEAKfNA+k1ZIzUd6+jbqEemA8atufK+ze3gE/bk3lUIbLtK/tREDF
ylqM2tIrfKjuvqblCqoOpd8FUrdVxyJdMmqXl2MT28nbeTZ7hTpKxVKJ+STnnXepgv9VHKVxaSvR
AiTysybUa9oEVeXBCsdtMDeQKuSeFDNeFhdVxVu1yzSJkvGdJo+hB9TGsnhQ2wwMC3wLjEHXuend
jIj3o02yMszYF9rNt85mndT9Xv+9lz4pded+p2JYryU0pUHHPbwNUMoDAw8IWh+Vc3hiv69yFGkO
peUDDniOJihC8AcLYiAQZzlG+qkDzAQ4embvIIO1jEpWjpEA/I5cgt6IoMPiaG59je883WX0XaxR
7ySArqpWl2/5rX3aYT+YdzylkbYcjCbaZaIJbcHiVOO5ykxMgI93e2CaHt+28kgeDrpOVG2Y4OGi
GqJ3UM/EY5LsRxmd6+ZrzsECAwEAAaOBrDCBqTAOBgNVHQ8BAf8EBAMCAQYwDwYDVR0TAQH/BAUw
AwEB/zAdBgNVHQ4EFgQUWyV7lqRlUX64OfPAeGZe6Drn8O4wHwYDVR0jBBgwFoAUWyV7lqRlUX64
OfPAeGZe6Drn8O4wRgYDVR0gBD8wPTA7BglghXQBWQECAQEwLjAsBggrBgEFBQcCARYgaHR0cDov
L3JlcG9zaXRvcnkuc3dpc3NzaWduLmNvbS8wDQYJKoZIhvcNAQEFBQADggIBACe645R88a7A3hfm
5djV9VSwg/S7zV4Fe0+fdWavPOhWfvxyeDgD2StiGwC5+OlgzczOUYrHUDFu4Up+GC9pWbY9ZIEr
44OE5iKHjn3g7gKZYbge9LgriBIWhMIxkziWMaa5O1M/wySTVltpkuzFwbs4AOPsF6m43Md8AYOf
Mke6UiI0HTJ6CVanfCU2qT1L2sCCbwq7EsiHSycR+R4tx5M/nttfJmtS2S6K8RTGRI0Vqbe/vd6m
Gu6uLftIdxf+u+yvGPUqUfA5hJeVbG4bwyvEdGB5JbAKJ9/fXtI5z0V9QkvfsywexcZdylU6oJxp
mo/a77KwPJ+HbBIrZXAVUjEaJM9vMSNQH4xPjyPDdEFjHFWoFN0+4FFQz/EbMFYOkrCChdiDyyJk
vC24JdVUorgG6q2SpCSgwYa1ShNqR88uC1aVVMvOmttqtKay20EIhid392qgQmwLOM7XdVAyksLf
KzAiSNDVQTglXaTpXZ/GlHXQRf0wl0OPkKsKx4ZzYEppLd6leNcG2mqeSz53OiATIgHQv2ieY2Br
NU0LbbqhPcCT4H8js1WtciVORvnSFu+wZMEBnunKoGqYDs/YYPIvSbjkQuE4NRb0yG5P94FW6Lqj
viOvrv1vA+ACOzB2+httQc8Bsem4yWb02ybzOqR08kkkW8mw0FfB+j564ZfJ
-----END CERTIFICATE-----

SwissSign Silver CA - G2
========================
-----BEGIN CERTIFICATE-----
MIIFvTCCA6WgAwIBAgIITxvUL1S7L0swDQYJKoZIhvcNAQEFBQAwRzELMAkGA1UEBhMCQ0gxFTAT
BgNVBAoTDFN3aXNzU2lnbiBBRzEhMB8GA1UEAxMYU3dpc3NTaWduIFNpbHZlciBDQSAtIEcyMB4X
DTA2MTAyNTA4MzI0NloXDTM2MTAyNTA4MzI0NlowRzELMAkGA1UEBhMCQ0gxFTATBgNVBAoTDFN3
aXNzU2lnbiBBRzEhMB8GA1UEAxMYU3dpc3NTaWduIFNpbHZlciBDQSAtIEcyMIICIjANBgkqhkiG
9w0BAQEFAAOCAg8AMIICCgKCAgEAxPGHf9N4Mfc4yfjDmUO8x/e8N+dOcbpLj6VzHVxumK4DV644
N0MvFz0fyM5oEMF4rhkDKxD6LHmD9ui5aLlV8gREpzn5/ASLHvGiTSf5YXu6t+WiE7brYT7QbNHm
+/pe7R20nqA1W6GSy/BJkv6FCgU+5tkL4k+73JU3/JHpMjUi0R86TieFnbAVlDLaYQ1HTWBCrpJH
6INaUFjpiou5XaHc3ZlKHzZnu0jkg7Y360g6rw9njxcH6ATK72oxh9TAtvmUcXtnZLi2kUpCe2Uu
MGoM9ZDulebyzYLs2aFK7PayS+VFheZteJMELpyCbTapxDFkH4aDCyr0NQp4yVXPQbBH6TCfmb5h
qAaEuSh6XzjZG6k4sIN/c8HDO0gqgg8hm7jMqDXDhBuDsz6+pJVpATqJAHgE2cn0mRmrVn5bi4Y5
FZGkECwJMoBgs5PAKrYYC51+jUnyEEp/+dVGLxmSo5mnJqy7jDzmDrxHB9xzUfFwZC8I+bRHHTBs
ROopN4WSaGa8gzj+ezku01DwH/teYLappvonQfGbGHLy9YR0SslnxFSuSGTfjNFusB3hB48IHpmc
celM2KX3RxIfdNFRnobzwqIjQAtz20um53MGjMGg6cFZrEb65i/4z3GcRm25xBWNOHkDRUjvxF3X
CO6HOSKGsg0PWEP3calILv3q1h8CAwEAAaOBrDCBqTAOBgNVHQ8BAf8EBAMCAQYwDwYDVR0TAQH/
BAUwAwEB/zAdBgNVHQ4EFgQUF6DNweRBtjpbO8tFnb0cwpj6hlgwHwYDVR0jBBgwFoAUF6DNweRB
tjpbO8tFnb0cwpj6hlgwRgYDVR0gBD8wPTA7BglghXQBWQEDAQEwLjAsBggrBgEFBQcCARYgaHR0
cDovL3JlcG9zaXRvcnkuc3dpc3NzaWduLmNvbS8wDQYJKoZIhvcNAQEFBQADggIBAHPGgeAn0i0P
4JUw4ppBf1AsX19iYamGamkYDHRJ1l2E6kFSGG9YrVBWIGrGvShpWJHckRE1qTodvBqlYJ7YH39F
kWnZfrt4csEGDyrOj4VwYaygzQu4OSlWhDJOhrs9xCrZ1x9y7v5RoSJBsXECYxqCsGKrXlcSH9/L
3XWgwF15kIwb4FDm3jH+mHtwX6WQ2K34ArZv02DdQEsixT2tOnqfGhpHkXkzuoLcMmkDlm4fS/Bx
/uNncqCxv1yL5PqZIseEuRuNI5c/7SXgz2W79WEE790eslpBIlqhn10s6FvJbakMDHiqYMZWjwFa
DGi8aRl5xB9+lwW/xekkUV7U1UtT7dkjWjYDZaPBA61BMPNGG4WQr2W11bHkFlt4dR2Xem1ZqSqP
e97Dh4kQmUlzeMg9vVE1dCrV8X5pGyq7O70luJpaPXJhkGaH7gzWTdQRdAtq/gsD/KNVV4n+Ssuu
WxcFyPKNIzFTONItaj+CuY0IavdeQXRuwxF+B6wpYJE/OMpXEA29MC/HpeZBoNquBYeaoKRlbEwJ
DIm6uNO5wJOKMPqN5ZprFQFOZ6raYlY+hAhm0sQ2fac+EPyI4NSA5QC9qvNOBqN6avlicuMJT+ub
DgEj8Z+7fNzcbBGXJbLytGMU0gYqZ4yD9c7qB9iaah7s5Aq7KkzrCWA5zspi2C5u
-----END CERTIFICATE-----

GeoTrust Primary Certification Authority
========================================
-----BEGIN CERTIFICATE-----
MIIDfDCCAmSgAwIBAgIQGKy1av1pthU6Y2yv2vrEoTANBgkqhkiG9w0BAQUFADBYMQswCQYDVQQG
EwJVUzEWMBQGA1UEChMNR2VvVHJ1c3QgSW5jLjExMC8GA1UEAxMoR2VvVHJ1c3QgUHJpbWFyeSBD
ZXJ0aWZpY2F0aW9uIEF1dGhvcml0eTAeFw0wNjExMjcwMDAwMDBaFw0zNjA3MTYyMzU5NTlaMFgx
CzAJBgNVBAYTAlVTMRYwFAYDVQQKEw1HZW9UcnVzdCBJbmMuMTEwLwYDVQQDEyhHZW9UcnVzdCBQ
cmltYXJ5IENlcnRpZmljYXRpb24gQXV0aG9yaXR5MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIB
CgKCAQEAvrgVe//UfH1nrYNke8hCUy3f9oQIIGHWAVlqnEQRr+92/ZV+zmEwu3qDXwK9AWbK7hWN
b6EwnL2hhZ6UOvNWiAAxz9juapYC2e0DjPt1befquFUWBRaa9OBesYjAZIVcFU2Ix7e64HXprQU9
nceJSOC7KMgD4TCTZF5SwFlwIjVXiIrxlQqD17wxcwE07e9GceBrAqg1cmuXm2bgyxx5X9gaBGge
RwLmnWDiNpcB3841kt++Z8dtd1k7j53WkBWUvEI0EME5+bEnPn7WinXFsq+W06Lem+SYvn3h6YGt
tm/81w7a4DSwDRp35+MImO9Y+pyEtzavwt+s0vQQBnBxNQIDAQABo0IwQDAPBgNVHRMBAf8EBTAD
AQH/MA4GA1UdDwEB/wQEAwIBBjAdBgNVHQ4EFgQULNVQQZcVi/CPNmFbSvtr2ZnJM5IwDQYJKoZI
hvcNAQEFBQADggEBAFpwfyzdtzRP9YZRqSa+S7iq8XEN3GHHoOo0Hnp3DwQ16CePbJC/kRYkRj5K
Ts4rFtULUh38H2eiAkUxT87z+gOneZ1TatnaYzr4gNfTmeGl4b7UVXGYNTq+k+qurUKykG/g/CFN
NWMziUnWm07Kx+dOCQD32sfvmWKZd7aVIl6KoKv0uHiYyjgZmclynnjNS6yvGaBzEi38wkG6gZHa
Floxt/m0cYASSJlyc1pZU8FjUjPtp8nSOQJw+uCxQmYpqptR7TBUIhRf2asdweSU8Pj1K/fqynhG
1riR/aYNKxoUAT6A8EKglQdebc3MS6RFjasS6LPeWuWgfOgPIh1a6Vk=
-----END CERTIFICATE-----

thawte Primary Root CA
======================
-----BEGIN CERTIFICATE-----
MIIEIDCCAwigAwIBAgIQNE7VVyDV7exJ9C/ON9srbTANBgkqhkiG9w0BAQUFADCBqTELMAkGA1UE
BhMCVVMxFTATBgNVBAoTDHRoYXd0ZSwgSW5jLjEoMCYGA1UECxMfQ2VydGlmaWNhdGlvbiBTZXJ2
aWNlcyBEaXZpc2lvbjE4MDYGA1UECxMvKGMpIDIwMDYgdGhhd3RlLCBJbmMuIC0gRm9yIGF1dGhv
cml6ZWQgdXNlIG9ubHkxHzAdBgNVBAMTFnRoYXd0ZSBQcmltYXJ5IFJvb3QgQ0EwHhcNMDYxMTE3
MDAwMDAwWhcNMzYwNzE2MjM1OTU5WjCBqTELMAkGA1UEBhMCVVMxFTATBgNVBAoTDHRoYXd0ZSwg
SW5jLjEoMCYGA1UECxMfQ2VydGlmaWNhdGlvbiBTZXJ2aWNlcyBEaXZpc2lvbjE4MDYGA1UECxMv
KGMpIDIwMDYgdGhhd3RlLCBJbmMuIC0gRm9yIGF1dGhvcml6ZWQgdXNlIG9ubHkxHzAdBgNVBAMT
FnRoYXd0ZSBQcmltYXJ5IFJvb3QgQ0EwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQCs
oPD7gFnUnMekz52hWXMJEEUMDSxuaPFsW0hoSVk3/AszGcJ3f8wQLZU0HObrTQmnHNK4yZc2AreJ
1CRfBsDMRJSUjQJib+ta3RGNKJpchJAQeg29dGYvajig4tVUROsdB58Hum/u6f1OCyn1PoSgAfGc
q/gcfomk6KHYcWUNo1F77rzSImANuVud37r8UVsLr5iy6S7pBOhih94ryNdOwUxkHt3Ph1i6Sk/K
aAcdHJ1KxtUvkcx8cXIcxcBn6zL9yZJclNqFwJu/U30rCfSMnZEfl2pSy94JNqR32HuHUETVPm4p
afs5SSYeCaWAe0At6+gnhcn+Yf1+5nyXHdWdAgMBAAGjQjBAMA8GA1UdEwEB/wQFMAMBAf8wDgYD
VR0PAQH/BAQDAgEGMB0GA1UdDgQWBBR7W0XPr87Lev0xkhpqtvNG61dIUDANBgkqhkiG9w0BAQUF
AAOCAQEAeRHAS7ORtvzw6WfUDW5FvlXok9LOAz/t2iWwHVfLHjp2oEzsUHboZHIMpKnxuIvW1oeE
uzLlQRHAd9mzYJ3rG9XRbkREqaYB7FViHXe4XI5ISXycO1cRrK1zN44veFyQaEfZYGDm/Ac9IiAX
xPcW6cTYcvnIc3zfFi8VqT79aie2oetaupgf1eNNZAqdE8hhuvU5HIe6uL17In/2/qxAeeWsEG89
jxt5dovEN7MhGITlNgDrYyCZuen+MwS7QcjBAvlEYyCegc5C09Y/LHbTY5xZ3Y+m4Q6gLkH3LpVH
z7z9M/P2C2F+fpErgUfCJzDupxBdN49cOSvkBPB7jVaMaA==
-----END CERTIFICATE-----

VeriSign Class 3 Public Primary Certification Authority - G5
============================================================
-----BEGIN CERTIFICATE-----
MIIE0zCCA7ugAwIBAgIQGNrRniZ96LtKIVjNzGs7SjANBgkqhkiG9w0BAQUFADCByjELMAkGA1UE
BhMCVVMxFzAVBgNVBAoTDlZlcmlTaWduLCBJbmMuMR8wHQYDVQQLExZWZXJpU2lnbiBUcnVzdCBO
ZXR3b3JrMTowOAYDVQQLEzEoYykgMjAwNiBWZXJpU2lnbiwgSW5jLiAtIEZvciBhdXRob3JpemVk
IHVzZSBvbmx5MUUwQwYDVQQDEzxWZXJpU2lnbiBDbGFzcyAzIFB1YmxpYyBQcmltYXJ5IENlcnRp
ZmljYXRpb24gQXV0aG9yaXR5IC0gRzUwHhcNMDYxMTA4MDAwMDAwWhcNMzYwNzE2MjM1OTU5WjCB
yjELMAkGA1UEBhMCVVMxFzAVBgNVBAoTDlZlcmlTaWduLCBJbmMuMR8wHQYDVQQLExZWZXJpU2ln
biBUcnVzdCBOZXR3b3JrMTowOAYDVQQLEzEoYykgMjAwNiBWZXJpU2lnbiwgSW5jLiAtIEZvciBh
dXRob3JpemVkIHVzZSBvbmx5MUUwQwYDVQQDEzxWZXJpU2lnbiBDbGFzcyAzIFB1YmxpYyBQcmlt
YXJ5IENlcnRpZmljYXRpb24gQXV0aG9yaXR5IC0gRzUwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAw
ggEKAoIBAQCvJAgIKXo1nmAMqudLO07cfLw8RRy7K+D+KQL5VwijZIUVJ/XxrcgxiV0i6CqqpkKz
j/i5Vbext0uz/o9+B1fs70PbZmIVYc9gDaTY3vjgw2IIPVQT60nKWVSFJuUrjxuf6/WhkcIzSdhD
Y2pSS9KP6HBRTdGJaXvHcPaz3BJ023tdS1bTlr8Vd6Gw9KIl8q8ckmcY5fQGBO+QueQA5N06tRn/
Arr0PO7gi+s3i+z016zy9vA9r911kTMZHRxAy3QkGSGT2RT+rCpSx4/VBEnkjWNHiDxpg8v+R70r
fk/Fla4OndTRQ8Bnc+MUCH7lP59zuDMKz10/NIeWiu5T6CUVAgMBAAGjgbIwga8wDwYDVR0TAQH/
BAUwAwEB/zAOBgNVHQ8BAf8EBAMCAQYwbQYIKwYBBQUHAQwEYTBfoV2gWzBZMFcwVRYJaW1hZ2Uv
Z2lmMCEwHzAHBgUrDgMCGgQUj+XTGoasjY5rw8+AatRIGCx7GS4wJRYjaHR0cDovL2xvZ28udmVy
aXNpZ24uY29tL3ZzbG9nby5naWYwHQYDVR0OBBYEFH/TZafC3ey78DAJ80M5+gKvMzEzMA0GCSqG
SIb3DQEBBQUAA4IBAQCTJEowX2LP2BqYLz3q3JktvXf2pXkiOOzEp6B4Eq1iDkVwZMXnl2YtmAl+
X6/WzChl8gGqCBpH3vn5fJJaCGkgDdk+bW48DW7Y5gaRQBi5+MHt39tBquCWIMnNZBU4gcmU7qKE
KQsTb47bDN0lAtukixlE0kF6BWlKWE9gyn6CagsCqiUXObXbf+eEZSqVir2G3l6BFoMtEMze/aiC
Km0oHw0LxOXnGiYZ4fQRbxC1lfznQgUy286dUV4otp6F01vvpX1FQHKOtw5rDgb7MzVIcbidJ4vE
ZV8NhnacRHr2lVz2XTIIM6RUthg/aFzyQkqFOFSDX9HoLPKsEdao7WNq
-----END CERTIFICATE-----

SecureTrust CA
==============
-----BEGIN CERTIFICATE-----
MIIDuDCCAqCgAwIBAgIQDPCOXAgWpa1Cf/DrJxhZ0DANBgkqhkiG9w0BAQUFADBIMQswCQYDVQQG
EwJVUzEgMB4GA1UEChMXU2VjdXJlVHJ1c3QgQ29ycG9yYXRpb24xFzAVBgNVBAMTDlNlY3VyZVRy
dXN0IENBMB4XDTA2MTEwNzE5MzExOFoXDTI5MTIzMTE5NDA1NVowSDELMAkGA1UEBhMCVVMxIDAe
BgNVBAoTF1NlY3VyZVRydXN0IENvcnBvcmF0aW9uMRcwFQYDVQQDEw5TZWN1cmVUcnVzdCBDQTCC
ASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAKukgeWVzfX2FI7CT8rU4niVWJxB4Q2ZQCQX
OZEzZum+4YOvYlyJ0fwkW2Gz4BERQRwdbvC4u/jep4G6pkjGnx29vo6pQT64lO0pGtSO0gMdA+9t
DWccV9cGrcrI9f4Or2YlSASWC12juhbDCE/RRvgUXPLIXgGZbf2IzIaowW8xQmxSPmjL8xk037uH
GFaAJsTQ3MBv396gwpEWoGQRS0S8Hvbn+mPeZqx2pHGj7DaUaHp3pLHnDi+BeuK1cobvomuL8A/b
01k/unK8RCSc43Oz969XL0Imnal0ugBS8kvNU3xHCzaFDmapCJcWNFfBZveA4+1wVMeT4C4oFVmH
ursCAwEAAaOBnTCBmjATBgkrBgEEAYI3FAIEBh4EAEMAQTALBgNVHQ8EBAMCAYYwDwYDVR0TAQH/
BAUwAwEB/zAdBgNVHQ4EFgQUQjK2FvoE/f5dS3rD/fdMQB1aQ68wNAYDVR0fBC0wKzApoCegJYYj
aHR0cDovL2NybC5zZWN1cmV0cnVzdC5jb20vU1RDQS5jcmwwEAYJKwYBBAGCNxUBBAMCAQAwDQYJ
KoZIhvcNAQEFBQADggEBADDtT0rhWDpSclu1pqNlGKa7UTt36Z3q059c4EVlew3KW+JwULKUBRSu
SceNQQcSc5R+DCMh/bwQf2AQWnL1mA6s7Ll/3XpvXdMc9P+IBWlCqQVxyLesJugutIxq/3HcuLHf
mbx8IVQr5Fiiu1cprp6poxkmD5kuCLDv/WnPmRoJjeOnnyvJNjR7JLN4TJUXpAYmHrZkUjZfYGfZ
nMUFdAvnZyPSCPyI6a6Lf+Ew9Dd+/cYy2i2eRDAwbO4H3tI0/NL/QPZL9GZGBlSm8jIKYyYwa5vR
3ItHuuG51WLQoqD0ZwV4KWMabwTW+MZMo5qxN7SN5ShLHZ4swrhovO0C7jE=
-----END CERTIFICATE-----

Secure Global CA
================
-----BEGIN CERTIFICATE-----
MIIDvDCCAqSgAwIBAgIQB1YipOjUiolN9BPI8PjqpTANBgkqhkiG9w0BAQUFADBKMQswCQYDVQQG
EwJVUzEgMB4GA1UEChMXU2VjdXJlVHJ1c3QgQ29ycG9yYXRpb24xGTAXBgNVBAMTEFNlY3VyZSBH
bG9iYWwgQ0EwHhcNMDYxMTA3MTk0MjI4WhcNMjkxMjMxMTk1MjA2WjBKMQswCQYDVQQGEwJVUzEg
MB4GA1UEChMXU2VjdXJlVHJ1c3QgQ29ycG9yYXRpb24xGTAXBgNVBAMTEFNlY3VyZSBHbG9iYWwg
Q0EwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQCvNS7YrGxVaQZx5RNoJLNP2MwhR/jx
YDiJiQPpvepeRlMJ3Fz1Wuj3RSoC6zFh1ykzTM7HfAo3fg+6MpjhHZevj8fcyTiW89sa/FHtaMbQ
bqR8JNGuQsiWUGMu4P51/pinX0kuleM5M2SOHqRfkNJnPLLZ/kG5VacJjnIFHovdRIWCQtBJwB1g
8NEXLJXr9qXBkqPFwqcIYA1gBBCWeZ4WNOaptvolRTnIHmX5k/Wq8VLcmZg9pYYaDDUz+kulBAYV
HDGA76oYa8J719rO+TMg1fW9ajMtgQT7sFzUnKPiXB3jqUJ1XnvUd+85VLrJChgbEplJL4hL/VBi
0XPnj3pDAgMBAAGjgZ0wgZowEwYJKwYBBAGCNxQCBAYeBABDAEEwCwYDVR0PBAQDAgGGMA8GA1Ud
EwEB/wQFMAMBAf8wHQYDVR0OBBYEFK9EBMJBfkiD2045AuzshHrmzsmkMDQGA1UdHwQtMCswKaAn
oCWGI2h0dHA6Ly9jcmwuc2VjdXJldHJ1c3QuY29tL1NHQ0EuY3JsMBAGCSsGAQQBgjcVAQQDAgEA
MA0GCSqGSIb3DQEBBQUAA4IBAQBjGghAfaReUw132HquHw0LURYD7xh8yOOvaliTFGCRsoTciE6+
OYo68+aCiV0BN7OrJKQVDpI1WkpEXk5X+nXOH0jOZvQ8QCaSmGwb7iRGDBezUqXbpZGRzzfTb+cn
CDpOGR86p1hcF895P4vkp9MmI50mD1hp/Ed+stCNi5O/KU9DaXR2Z0vPB4zmAve14bRDtUstFJ/5
3CYNv6ZHdAbYiNE6KTCEztI5gGIbqMdXSbxqVVFnFUq+NQfk1XWYN3kwFNspnWzFacxHVaIw98xc
f8LDmBxrThaA63p4ZUWiABqvDA1VZDRIuJK58bRQKfJPIx/abKwfROHdI3hRW8cW
-----END CERTIFICATE-----

COMODO Certification Authority
==============================
-----BEGIN CERTIFICATE-----
MIIEHTCCAwWgAwIBAgIQToEtioJl4AsC7j41AkblPTANBgkqhkiG9w0BAQUFADCBgTELMAkGA1UE
BhMCR0IxGzAZBgNVBAgTEkdyZWF0ZXIgTWFuY2hlc3RlcjEQMA4GA1UEBxMHU2FsZm9yZDEaMBgG
A1UEChMRQ09NT0RPIENBIExpbWl0ZWQxJzAlBgNVBAMTHkNPTU9ETyBDZXJ0aWZpY2F0aW9uIEF1
dGhvcml0eTAeFw0wNjEyMDEwMDAwMDBaFw0yOTEyMzEyMzU5NTlaMIGBMQswCQYDVQQGEwJHQjEb
MBkGA1UECBMSR3JlYXRlciBNYW5jaGVzdGVyMRAwDgYDVQQHEwdTYWxmb3JkMRowGAYDVQQKExFD
T01PRE8gQ0EgTGltaXRlZDEnMCUGA1UEAxMeQ09NT0RPIENlcnRpZmljYXRpb24gQXV0aG9yaXR5
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0ECLi3LjkRv3UcEbVASY06m/weaKXTuH
+7uIzg3jLz8GlvCiKVCZrts7oVewdFFxze1CkU1B/qnI2GqGd0S7WWaXUF601CxwRM/aN5VCaTww
xHGzUvAhTaHYujl8HJ6jJJ3ygxaYqhZ8Q5sVW7euNJH+1GImGEaaP+vB+fGQV+useg2L23IwambV
4EajcNxo2f8ESIl33rXp+2dtQem8Ob0y2WIC8bGoPW43nOIv4tOiJovGuFVDiOEjPqXSJDlqR6sA
1KGzqSX+DT+nHbrTUcELpNqsOO9VUCQFZUaTNE8tja3G1CEZ0o7KBWFxB3NH5YoZEr0ETc5OnKVI
rLsm9wIDAQABo4GOMIGLMB0GA1UdDgQWBBQLWOWLxkwVN6RAqTCpIb5HNlpW/zAOBgNVHQ8BAf8E
BAMCAQYwDwYDVR0TAQH/BAUwAwEB/zBJBgNVHR8EQjBAMD6gPKA6hjhodHRwOi8vY3JsLmNvbW9k
b2NhLmNvbS9DT01PRE9DZXJ0aWZpY2F0aW9uQXV0aG9yaXR5LmNybDANBgkqhkiG9w0BAQUFAAOC
AQEAPpiem/Yb6dc5t3iuHXIYSdOH5EOC6z/JqvWote9VfCFSZfnVDeFs9D6Mk3ORLgLETgdxb8CP
OGEIqB6BCsAvIC9Bi5HcSEW88cbeunZrM8gALTFGTO3nnc+IlP8zwFboJIYmuNg4ON8qa90SzMc/
RxdMosIGlgnW2/4/PEZB31jiVg88O8EckzXZOFKs7sjsLjBOlDW0JB9LeGna8gI4zJVSk/BwJVmc
IGfE7vmLV2H0knZ9P4SNVbfo5azV8fUZVqZa+5Acr5Pr5RzUZ5ddBA6+C4OmF4O5MBKgxTMVBbkN
+8cFduPYSo38NBejxiEovjBFMR7HeL5YYTisO+IBZQ==
-----END CERTIFICATE-----

Network Solutions Certificate Authority
=======================================
-----BEGIN CERTIFICATE-----
MIID5jCCAs6gAwIBAgIQV8szb8JcFuZHFhfjkDFo4DANBgkqhkiG9w0BAQUFADBiMQswCQYDVQQG
EwJVUzEhMB8GA1UEChMYTmV0d29yayBTb2x1dGlvbnMgTC5MLkMuMTAwLgYDVQQDEydOZXR3b3Jr
IFNvbHV0aW9ucyBDZXJ0aWZpY2F0ZSBBdXRob3JpdHkwHhcNMDYxMjAxMDAwMDAwWhcNMjkxMjMx
MjM1OTU5WjBiMQswCQYDVQQGEwJVUzEhMB8GA1UEChMYTmV0d29yayBTb2x1dGlvbnMgTC5MLkMu
MTAwLgYDVQQDEydOZXR3b3JrIFNvbHV0aW9ucyBDZXJ0aWZpY2F0ZSBBdXRob3JpdHkwggEiMA0G
CSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQDkvH6SMG3G2I4rC7xGzuAnlt7e+foS0zwzc7MEL7xx
jOWftiJgPl9dzgn/ggwbmlFQGiaJ3dVhXRncEg8tCqJDXRfQNJIg6nPPOCwGJgl6cvf6UDL4wpPT
aaIjzkGxzOTVHzbRijr4jGPiFFlp7Q3Tf2vouAPlT2rlmGNpSAW+Lv8ztumXWWn4Zxmuk2GWRBXT
crA/vGp97Eh/jcOrqnErU2lBUzS1sLnFBgrEsEX1QV1uiUV7PTsmjHTC5dLRfbIR1PtYMiKagMnc
/Qzpf14Dl847ABSHJ3A4qY5usyd2mFHgBeMhqxrVhSI8KbWaFsWAqPS7azCPL0YCorEMIuDTAgMB
AAGjgZcwgZQwHQYDVR0OBBYEFCEwyfsA106Y2oeqKtCnLrFAMadMMA4GA1UdDwEB/wQEAwIBBjAP
BgNVHRMBAf8EBTADAQH/MFIGA1UdHwRLMEkwR6BFoEOGQWh0dHA6Ly9jcmwubmV0c29sc3NsLmNv
bS9OZXR3b3JrU29sdXRpb25zQ2VydGlmaWNhdGVBdXRob3JpdHkuY3JsMA0GCSqGSIb3DQEBBQUA
A4IBAQC7rkvnt1frf6ott3NHhWrB5KUd5Oc86fRZZXe1eltajSU24HqXLjjAV2CDmAaDn7l2em5Q
4LqILPxFzBiwmZVRDuwduIj/h1AcgsLj4DKAv6ALR8jDMe+ZZzKATxcheQxpXN5eNK4CtSbqUN9/
GGUsyfJj4akH/nxxH2szJGoeBfcFaMBqEssuXmHLrijTfsK0ZpEmXzwuJF/LWA/rKOyvEZbz3Htv
wKeI8lN3s2Berq4o2jUsbzRF0ybh3uxbTydrFny9RAQYgrOJeRcQcT16ohZO9QHNpGxlaKFJdlxD
ydi8NmdspZS11My5vWo1ViHe2MPr+8ukYEywVaCge1ey
-----END CERTIFICATE-----

WellsSecure Public Root Certificate Authority
=============================================
-----BEGIN CERTIFICATE-----
MIIEvTCCA6WgAwIBAgIBATANBgkqhkiG9w0BAQUFADCBhTELMAkGA1UEBhMCVVMxIDAeBgNVBAoM
F1dlbGxzIEZhcmdvIFdlbGxzU2VjdXJlMRwwGgYDVQQLDBNXZWxscyBGYXJnbyBCYW5rIE5BMTYw
NAYDVQQDDC1XZWxsc1NlY3VyZSBQdWJsaWMgUm9vdCBDZXJ0aWZpY2F0ZSBBdXRob3JpdHkwHhcN
MDcxMjEzMTcwNzU0WhcNMjIxMjE0MDAwNzU0WjCBhTELMAkGA1UEBhMCVVMxIDAeBgNVBAoMF1dl
bGxzIEZhcmdvIFdlbGxzU2VjdXJlMRwwGgYDVQQLDBNXZWxscyBGYXJnbyBCYW5rIE5BMTYwNAYD
VQQDDC1XZWxsc1NlY3VyZSBQdWJsaWMgUm9vdCBDZXJ0aWZpY2F0ZSBBdXRob3JpdHkwggEiMA0G
CSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQDub7S9eeKPCCGeOARBJe+rWxxTkqxtnt3CxC5FlAM1
iGd0V+PfjLindo8796jE2yljDpFoNoqXjopxaAkH5OjUDk/41itMpBb570OYj7OeUt9tkTmPOL13
i0Nj67eT/DBMHAGTthP796EfvyXhdDcsHqRePGj4S78NuR4uNuip5Kf4D8uCdXw1LSLWwr8L87T8
bJVhHlfXBIEyg1J55oNjz7fLY4sR4r1e6/aN7ZVyKLSsEmLpSjPmgzKuBXWVvYSV2ypcm44uDLiB
K0HmOFafSZtsdvqKXfcBeYF8wYNABf5x/Qw/zE5gCQ5lRxAvAcAFP4/4s0HvWkJ+We/SlwxlAgMB
AAGjggE0MIIBMDAPBgNVHRMBAf8EBTADAQH/MDkGA1UdHwQyMDAwLqAsoCqGKGh0dHA6Ly9jcmwu
cGtpLndlbGxzZmFyZ28uY29tL3dzcHJjYS5jcmwwDgYDVR0PAQH/BAQDAgHGMB0GA1UdDgQWBBQm
lRkQ2eihl5H/3BnZtQQ+0nMKajCBsgYDVR0jBIGqMIGngBQmlRkQ2eihl5H/3BnZtQQ+0nMKaqGB
i6SBiDCBhTELMAkGA1UEBhMCVVMxIDAeBgNVBAoMF1dlbGxzIEZhcmdvIFdlbGxzU2VjdXJlMRww
GgYDVQQLDBNXZWxscyBGYXJnbyBCYW5rIE5BMTYwNAYDVQQDDC1XZWxsc1NlY3VyZSBQdWJsaWMg
Um9vdCBDZXJ0aWZpY2F0ZSBBdXRob3JpdHmCAQEwDQYJKoZIhvcNAQEFBQADggEBALkVsUSRzCPI
K0134/iaeycNzXK7mQDKfGYZUMbVmO2rvwNa5U3lHshPcZeG1eMd/ZDJPHV3V3p9+N701NX3leZ0
bh08rnyd2wIDBSxxSyU+B+NemvVmFymIGjifz6pBA4SXa5M4esowRBskRDPQ5NHcKDj0E0M1NSlj
qHyita04pO2t/caaH/+Xc/77szWnk4bGdpEA5qxRFsQnMlzbc9qlk1eOPm01JghZ1edE13YgY+es
E2fDbbFwRnzVlhE9iW9dqKHrjQrawx0zbKPqZxmamX9LPYNRKh3KL4YMon4QLSvUFpULB6ouFJJJ
tylv2G0xffX8oRAHh84vWdw+WNs=
-----END CERTIFICATE-----

COMODO ECC Certification Authority
==================================
-----BEGIN CERTIFICATE-----
MIICiTCCAg+gAwIBAgIQH0evqmIAcFBUTAGem2OZKjAKBggqhkjOPQQDAzCBhTELMAkGA1UEBhMC
R0IxGzAZBgNVBAgTEkdyZWF0ZXIgTWFuY2hlc3RlcjEQMA4GA1UEBxMHU2FsZm9yZDEaMBgGA1UE
ChMRQ09NT0RPIENBIExpbWl0ZWQxKzApBgNVBAMTIkNPTU9ETyBFQ0MgQ2VydGlmaWNhdGlvbiBB
dXRob3JpdHkwHhcNMDgwMzA2MDAwMDAwWhcNMzgwMTE4MjM1OTU5WjCBhTELMAkGA1UEBhMCR0Ix
GzAZBgNVBAgTEkdyZWF0ZXIgTWFuY2hlc3RlcjEQMA4GA1UEBxMHU2FsZm9yZDEaMBgGA1UEChMR
Q09NT0RPIENBIExpbWl0ZWQxKzApBgNVBAMTIkNPTU9ETyBFQ0MgQ2VydGlmaWNhdGlvbiBBdXRo
b3JpdHkwdjAQBgcqhkjOPQIBBgUrgQQAIgNiAAQDR3svdcmCFYX7deSRFtSrYpn1PlILBs5BAH+X
4QokPB0BBO490o0JlwzgdeT6+3eKKvUDYEs2ixYjFq0JcfRK9ChQtP6IHG4/bC8vCVlbpVsLM5ni
wz2J+Wos77LTBumjQjBAMB0GA1UdDgQWBBR1cacZSBm8nZ3qQUfflMRId5nTeTAOBgNVHQ8BAf8E
BAMCAQYwDwYDVR0TAQH/BAUwAwEB/zAKBggqhkjOPQQDAwNoADBlAjEA7wNbeqy3eApyt4jf/7VG
FAkK+qDmfQjGGoe9GKhzvSbKYAydzpmfz1wPMOG+FDHqAjAU9JM8SaczepBGR7NjfRObTrdvGDeA
U/7dIOA1mjbRxwG55tzd8/8dLDoWV9mSOdY=
-----END CERTIFICATE-----

IGC/A
=====
-----BEGIN CERTIFICATE-----
MIIEAjCCAuqgAwIBAgIFORFFEJQwDQYJKoZIhvcNAQEFBQAwgYUxCzAJBgNVBAYTAkZSMQ8wDQYD
VQQIEwZGcmFuY2UxDjAMBgNVBAcTBVBhcmlzMRAwDgYDVQQKEwdQTS9TR0ROMQ4wDAYDVQQLEwVE
Q1NTSTEOMAwGA1UEAxMFSUdDL0ExIzAhBgkqhkiG9w0BCQEWFGlnY2FAc2dkbi5wbS5nb3V2LmZy
MB4XDTAyMTIxMzE0MjkyM1oXDTIwMTAxNzE0MjkyMlowgYUxCzAJBgNVBAYTAkZSMQ8wDQYDVQQI
EwZGcmFuY2UxDjAMBgNVBAcTBVBhcmlzMRAwDgYDVQQKEwdQTS9TR0ROMQ4wDAYDVQQLEwVEQ1NT
STEOMAwGA1UEAxMFSUdDL0ExIzAhBgkqhkiG9w0BCQEWFGlnY2FAc2dkbi5wbS5nb3V2LmZyMIIB
IjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAsh/R0GLFMzvABIaIs9z4iPf930Pfeo2aSVz2
TqrMHLmh6yeJ8kbpO0px1R2OLc/mratjUMdUC24SyZA2xtgv2pGqaMVy/hcKshd+ebUyiHDKcMCW
So7kVc0dJ5S/znIq7Fz5cyD+vfcuiWe4u0dzEvfRNWk68gq5rv9GQkaiv6GFGvm/5P9JhfejcIYy
HF2fYPepraX/z9E0+X1bF8bc1g4oa8Ld8fUzaJ1O/Id8NhLWo4DoQw1VYZTqZDdH6nfK0LJYBcNd
frGoRpAxVs5wKpayMLh35nnAvSk7/ZR3TL0gzUEl4C7HG7vupARB0l2tEmqKm0f7yd1GQOGdPDPQ
tQIDAQABo3cwdTAPBgNVHRMBAf8EBTADAQH/MAsGA1UdDwQEAwIBRjAVBgNVHSAEDjAMMAoGCCqB
egF5AQEBMB0GA1UdDgQWBBSjBS8YYFDCiQrdKyFP/45OqDAxNjAfBgNVHSMEGDAWgBSjBS8YYFDC
iQrdKyFP/45OqDAxNjANBgkqhkiG9w0BAQUFAAOCAQEABdwm2Pp3FURo/C9mOnTgXeQp/wYHE4RK
q89toB9RlPhJy3Q2FLwV3duJL92PoF189RLrn544pEfMs5bZvpwlqwN+Mw+VgQ39FuCIvjfwbF3Q
MZsyK10XZZOYYLxuj7GoPB7ZHPOpJkL5ZB3C55L29B5aqhlSXa/oovdgoPaN8In1buAKBQGVyYsg
Crpa/JosPL3Dt8ldeCUFP1YUmwza+zpI/pdpXsoQhvdOlgQITeywvl3cO45Pwf2aNjSaTFR+FwNI
lQgRHAdvhQh+XU3Endv7rs6y0bO4g2wdsrN58dhwmX7wEwLOXt1R0982gaEbeC9xs/FZTEYYKKuF
0mBWWg==
-----END CERTIFICATE-----

Security Communication EV RootCA1
=================================
-----BEGIN CERTIFICATE-----
MIIDfTCCAmWgAwIBAgIBADANBgkqhkiG9w0BAQUFADBgMQswCQYDVQQGEwJKUDElMCMGA1UEChMc
U0VDT00gVHJ1c3QgU3lzdGVtcyBDTy4sTFRELjEqMCgGA1UECxMhU2VjdXJpdHkgQ29tbXVuaWNh
dGlvbiBFViBSb290Q0ExMB4XDTA3MDYwNjAyMTIzMloXDTM3MDYwNjAyMTIzMlowYDELMAkGA1UE
BhMCSlAxJTAjBgNVBAoTHFNFQ09NIFRydXN0IFN5c3RlbXMgQ08uLExURC4xKjAoBgNVBAsTIVNl
Y3VyaXR5IENvbW11bmljYXRpb24gRVYgUm9vdENBMTCCASIwDQYJKoZIhvcNAQEBBQADggEPADCC
AQoCggEBALx/7FebJOD+nLpCeamIivqA4PUHKUPqjgo0No0c+qe1OXj/l3X3L+SqawSERMqm4miO
/VVQYg+kcQ7OBzgtQoVQrTyWb4vVog7P3kmJPdZkLjjlHmy1V4qe70gOzXppFodEtZDkBp2uoQSX
WHnvIEqCa4wiv+wfD+mEce3xDuS4GBPMVjZd0ZoeUWs5bmB2iDQL87PRsJ3KYeJkHcFGB7hj3R4z
ZbOOCVVSPbW9/wfrrWFVGCypaZhKqkDFMxRldAD5kd6vA0jFQFTcD4SQaCDFkpbcLuUCRarAX1T4
bepJz11sS6/vmsJWXMY1VkJqMF/Cq/biPT+zyRGPMUzXn0kCAwEAAaNCMEAwHQYDVR0OBBYEFDVK
9U2vP9eCOKyrcWUXdYydVZPmMA4GA1UdDwEB/wQEAwIBBjAPBgNVHRMBAf8EBTADAQH/MA0GCSqG
SIb3DQEBBQUAA4IBAQCoh+ns+EBnXcPBZsdAS5f8hxOQWsTvoMpfi7ent/HWtWS3irO4G8za+6xm
iEHO6Pzk2x6Ipu0nUBsCMCRGef4Eh3CXQHPRwMFXGZpppSeZq51ihPZRwSzJIxXYKLerJRO1RuGG
Av8mjMSIkh1W/hln8lXkgKNrnKt34VFxDSDbEJrbvXZ5B3eZKK2aXtqxT0QsNY6llsf9g/BYxnnW
mHyojf6GPgcWkuF75x3sM3Z+Qi5KhfmRiWiEA4Glm5q+4zfFVKtWOxgtQaQM+ELbmaDgcm+7XeEW
T1MKZPlO9L9OVL14bIjqv5wTJMJwaaJ/D8g8rQjJsJhAoyrniIPtd490
-----END CERTIFICATE-----

OISTE WISeKey Global Root GA CA
===============================
-----BEGIN CERTIFICATE-----
MIID8TCCAtmgAwIBAgIQQT1yx/RrH4FDffHSKFTfmjANBgkqhkiG9w0BAQUFADCBijELMAkGA1UE
BhMCQ0gxEDAOBgNVBAoTB1dJU2VLZXkxGzAZBgNVBAsTEkNvcHlyaWdodCAoYykgMjAwNTEiMCAG
A1UECxMZT0lTVEUgRm91bmRhdGlvbiBFbmRvcnNlZDEoMCYGA1UEAxMfT0lTVEUgV0lTZUtleSBH
bG9iYWwgUm9vdCBHQSBDQTAeFw0wNTEyMTExNjAzNDRaFw0zNzEyMTExNjA5NTFaMIGKMQswCQYD
VQQGEwJDSDEQMA4GA1UEChMHV0lTZUtleTEbMBkGA1UECxMSQ29weXJpZ2h0IChjKSAyMDA1MSIw
IAYDVQQLExlPSVNURSBGb3VuZGF0aW9uIEVuZG9yc2VkMSgwJgYDVQQDEx9PSVNURSBXSVNlS2V5
IEdsb2JhbCBSb290IEdBIENBMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAy0+zAJs9
Nt350UlqaxBJH+zYK7LG+DKBKUOVTJoZIyEVRd7jyBxRVVuuk+g3/ytr6dTqvirdqFEr12bDYVxg
Asj1znJ7O7jyTmUIms2kahnBAbtzptf2w93NvKSLtZlhuAGio9RN1AU9ka34tAhxZK9w8RxrfvbD
d50kc3vkDIzh2TbhmYsFmQvtRTEJysIA2/dyoJaqlYfQjse2YXMNdmaM3Bu0Y6Kff5MTMPGhJ9vZ
/yxViJGg4E8HsChWjBgbl0SOid3gF27nKu+POQoxhILYQBRJLnpB5Kf+42TMwVlxSywhp1t94B3R
LoGbw9ho972WG6xwsRYUC9tguSYBBQIDAQABo1EwTzALBgNVHQ8EBAMCAYYwDwYDVR0TAQH/BAUw
AwEB/zAdBgNVHQ4EFgQUswN+rja8sHnR3JQmthG+IbJphpQwEAYJKwYBBAGCNxUBBAMCAQAwDQYJ
KoZIhvcNAQEFBQADggEBAEuh/wuHbrP5wUOxSPMowB0uyQlB+pQAHKSkq0lPjz0e701vvbyk9vIm
MMkQyh2I+3QZH4VFvbBsUfk2ftv1TDI6QU9bR8/oCy22xBmddMVHxjtqD6wU2zz0c5ypBd8A3HR4
+vg1YFkCExh8vPtNsCBtQ7tgMHpnM1zFmdH4LTlSc/uMqpclXHLZCB6rTjzjgTGfA6b7wP4piFXa
hNVQA7bihKOmNqoROgHhGEvWRGizPflTdISzRpFGlgC3gCy24eMQ4tui5yiPAZZiFj4A4xylNoEY
okxSdsARo27mHbrjWr42U8U+dY+GaSlYU7Wcu2+fXMUY7N0v4ZjJ/L7fCg0=
-----END CERTIFICATE-----

Microsec e-Szigno Root CA
=========================
-----BEGIN CERTIFICATE-----
MIIHqDCCBpCgAwIBAgIRAMy4579OKRr9otxmpRwsDxEwDQYJKoZIhvcNAQEFBQAwcjELMAkGA1UE
BhMCSFUxETAPBgNVBAcTCEJ1ZGFwZXN0MRYwFAYDVQQKEw1NaWNyb3NlYyBMdGQuMRQwEgYDVQQL
EwtlLVN6aWdubyBDQTEiMCAGA1UEAxMZTWljcm9zZWMgZS1Temlnbm8gUm9vdCBDQTAeFw0wNTA0
MDYxMjI4NDRaFw0xNzA0MDYxMjI4NDRaMHIxCzAJBgNVBAYTAkhVMREwDwYDVQQHEwhCdWRhcGVz
dDEWMBQGA1UEChMNTWljcm9zZWMgTHRkLjEUMBIGA1UECxMLZS1Temlnbm8gQ0ExIjAgBgNVBAMT
GU1pY3Jvc2VjIGUtU3ppZ25vIFJvb3QgQ0EwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIB
AQDtyADVgXvNOABHzNuEwSFpLHSQDCHZU4ftPkNEU6+r+ICbPHiN1I2uuO/TEdyB5s87lozWbxXG
d36hL+BfkrYn13aaHUM86tnsL+4582pnS4uCzyL4ZVX+LMsvfUh6PXX5qqAnu3jCBspRwn5mS6/N
oqdNAoI/gqyFxuEPkEeZlApxcpMqyabAvjxWTHOSJ/FrtfX9/DAFYJLG65Z+AZHCabEeHXtTRbjc
QR/Ji3HWVBTji1R4P770Yjtb9aPs1ZJ04nQw7wHb4dSrmZsqa/i9phyGI0Jf7Enemotb9HI6QMVJ
PqW+jqpx62z69Rrkav17fVVA71hu5tnVvCSrwe+3AgMBAAGjggQ3MIIEMzBnBggrBgEFBQcBAQRb
MFkwKAYIKwYBBQUHMAGGHGh0dHBzOi8vcmNhLmUtc3ppZ25vLmh1L29jc3AwLQYIKwYBBQUHMAKG
IWh0dHA6Ly93d3cuZS1zemlnbm8uaHUvUm9vdENBLmNydDAPBgNVHRMBAf8EBTADAQH/MIIBcwYD
VR0gBIIBajCCAWYwggFiBgwrBgEEAYGoGAIBAQEwggFQMCgGCCsGAQUFBwIBFhxodHRwOi8vd3d3
LmUtc3ppZ25vLmh1L1NaU1ovMIIBIgYIKwYBBQUHAgIwggEUHoIBEABBACAAdABhAG4A+gBzAO0A
dAB2AOEAbgB5ACAA6QByAHQAZQBsAG0AZQB6AOkAcwDpAGgAZQB6ACAA6QBzACAAZQBsAGYAbwBn
AGEAZADhAHMA4QBoAG8AegAgAGEAIABTAHoAbwBsAGcA4QBsAHQAYQB0APMAIABTAHoAbwBsAGcA
4QBsAHQAYQB0AOEAcwBpACAAUwB6AGEAYgDhAGwAeQB6AGEAdABhACAAcwB6AGUAcgBpAG4AdAAg
AGsAZQBsAGwAIABlAGwAagDhAHIAbgBpADoAIABoAHQAdABwADoALwAvAHcAdwB3AC4AZQAtAHMA
egBpAGcAbgBvAC4AaAB1AC8AUwBaAFMAWgAvMIHIBgNVHR8EgcAwgb0wgbqggbeggbSGIWh0dHA6
Ly93d3cuZS1zemlnbm8uaHUvUm9vdENBLmNybIaBjmxkYXA6Ly9sZGFwLmUtc3ppZ25vLmh1L0NO
PU1pY3Jvc2VjJTIwZS1Temlnbm8lMjBSb290JTIwQ0EsT1U9ZS1Temlnbm8lMjBDQSxPPU1pY3Jv
c2VjJTIwTHRkLixMPUJ1ZGFwZXN0LEM9SFU/Y2VydGlmaWNhdGVSZXZvY2F0aW9uTGlzdDtiaW5h
cnkwDgYDVR0PAQH/BAQDAgEGMIGWBgNVHREEgY4wgYuBEGluZm9AZS1zemlnbm8uaHWkdzB1MSMw
IQYDVQQDDBpNaWNyb3NlYyBlLVN6aWduw7MgUm9vdCBDQTEWMBQGA1UECwwNZS1TemlnbsOzIEhT
WjEWMBQGA1UEChMNTWljcm9zZWMgS2Z0LjERMA8GA1UEBxMIQnVkYXBlc3QxCzAJBgNVBAYTAkhV
MIGsBgNVHSMEgaQwgaGAFMegSXUWYYTbMUuE0vE3QJDvTtz3oXakdDByMQswCQYDVQQGEwJIVTER
MA8GA1UEBxMIQnVkYXBlc3QxFjAUBgNVBAoTDU1pY3Jvc2VjIEx0ZC4xFDASBgNVBAsTC2UtU3pp
Z25vIENBMSIwIAYDVQQDExlNaWNyb3NlYyBlLVN6aWdubyBSb290IENBghEAzLjnv04pGv2i3Gal
HCwPETAdBgNVHQ4EFgQUx6BJdRZhhNsxS4TS8TdAkO9O3PcwDQYJKoZIhvcNAQEFBQADggEBANMT
nGZjWS7KXHAM/IO8VbH0jgdsZifOwTsgqRy7RlRw7lrMoHfqaEQn6/Ip3Xep1fvj1KcExJW4C+FE
aGAHQzAxQmHl7tnlJNUb3+FKG6qfx1/4ehHqE5MAyopYse7tDk2016g2JnzgOsHVV4Lxdbb9iV/a
86g4nzUGCM4ilb7N1fy+W955a9x6qWVmvrElWl/tftOsRm1M9DKHtCAE4Gx4sHfRhUZLphK3dehK
yVZs15KrnfVJONJPU+NVkBHbmJbGSfI+9J8b4PeI3CVimUTYc78/MPMMNz7UwiiAc7EBt51alhQB
S6kRnSlqLtBdgcDPsiBDxwPgN05dCtxZICU=
-----END CERTIFICATE-----

Certigna
========
-----BEGIN CERTIFICATE-----
MIIDqDCCApCgAwIBAgIJAP7c4wEPyUj/MA0GCSqGSIb3DQEBBQUAMDQxCzAJBgNVBAYTAkZSMRIw
EAYDVQQKDAlEaGlteW90aXMxETAPBgNVBAMMCENlcnRpZ25hMB4XDTA3MDYyOTE1MTMwNVoXDTI3
MDYyOTE1MTMwNVowNDELMAkGA1UEBhMCRlIxEjAQBgNVBAoMCURoaW15b3RpczERMA8GA1UEAwwI
Q2VydGlnbmEwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQDIaPHJ1tazNHUmgh7stL7q
XOEm7RFHYeGifBZ4QCHkYJ5ayGPhxLGWkv8YbWkj4Sti993iNi+RB7lIzw7sebYs5zRLcAglozyH
GxnygQcPOJAZ0xH+hrTy0V4eHpbNgGzOOzGTtvKg0KmVEn2lmsxryIRWijOp5yIVUxbwzBfsV1/p
ogqYCd7jX5xv3EjjhQsVWqa6n6xI4wmy9/Qy3l40vhx4XUJbzg4ij02Q130yGLMLLGq/jj8UEYkg
DncUtT2UCIf3JR7VsmAA7G8qKCVuKj4YYxclPz5EIBb2JsglrgVKtOdjLPOMFlN+XPsRGgjBRmKf
Irjxwo1p3Po6WAbfAgMBAAGjgbwwgbkwDwYDVR0TAQH/BAUwAwEB/zAdBgNVHQ4EFgQUGu3+QTmQ
tCRZvgHyUtVF9lo53BEwZAYDVR0jBF0wW4AUGu3+QTmQtCRZvgHyUtVF9lo53BGhOKQ2MDQxCzAJ
BgNVBAYTAkZSMRIwEAYDVQQKDAlEaGlteW90aXMxETAPBgNVBAMMCENlcnRpZ25hggkA/tzjAQ/J
SP8wDgYDVR0PAQH/BAQDAgEGMBEGCWCGSAGG+EIBAQQEAwIABzANBgkqhkiG9w0BAQUFAAOCAQEA
hQMeknH2Qq/ho2Ge6/PAD/Kl1NqV5ta+aDY9fm4fTIrv0Q8hbV6lUmPOEvjvKtpv6zf+EwLHyzs+
ImvaYS5/1HI93TDhHkxAGYwP15zRgzB7mFncfca5DClMoTOi62c6ZYTTluLtdkVwj7Ur3vkj1klu
PBS1xp81HlDQwY9qcEQCYsuuHWhBp6pX6FOqB9IG9tUUBguRA3UsbHK1YZWaDYu5Def131TN3ubY
1gkIl2PlwS6wt0QmwCbAr1UwnjvVNioZBPRcHv/PLLf/0P2HQBHVESO7SMAhqaQoLf0V+LBOK/Qw
WyH8EZE0vkHve52Xdf+XlcCWWC/qu0bXu+TZLg==
-----END CERTIFICATE-----

Deutsche Telekom Root CA 2
==========================
-----BEGIN CERTIFICATE-----
MIIDnzCCAoegAwIBAgIBJjANBgkqhkiG9w0BAQUFADBxMQswCQYDVQQGEwJERTEcMBoGA1UEChMT
RGV1dHNjaGUgVGVsZWtvbSBBRzEfMB0GA1UECxMWVC1UZWxlU2VjIFRydXN0IENlbnRlcjEjMCEG
A1UEAxMaRGV1dHNjaGUgVGVsZWtvbSBSb290IENBIDIwHhcNOTkwNzA5MTIxMTAwWhcNMTkwNzA5
MjM1OTAwWjBxMQswCQYDVQQGEwJERTEcMBoGA1UEChMTRGV1dHNjaGUgVGVsZWtvbSBBRzEfMB0G
A1UECxMWVC1UZWxlU2VjIFRydXN0IENlbnRlcjEjMCEGA1UEAxMaRGV1dHNjaGUgVGVsZWtvbSBS
b290IENBIDIwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQCrC6M14IspFLEUha88EOQ5
bzVdSq7d6mGNlUn0b2SjGmBmpKlAIoTZ1KXleJMOaAGtuU1cOs7TuKhCQN/Po7qCWWqSG6wcmtoI
KyUn+WkjR/Hg6yx6m/UTAtB+NHzCnjwAWav12gz1MjwrrFDa1sPeg5TKqAyZMg4ISFZbavva4VhY
AUlfckE8FQYBjl2tqriTtM2e66foai1SNNs671x1Udrb8zH57nGYMsRUFUQM+ZtV7a3fGAigo4aK
Se5TBY8ZTNXeWHmb0mocQqvF1afPaA+W5OFhmHZhyJF81j4A4pFQh+GdCuatl9Idxjp9y7zaAzTV
jlsB9WoHtxa2bkp/AgMBAAGjQjBAMB0GA1UdDgQWBBQxw3kbuvVT1xfgiXotF2wKsyudMzAPBgNV
HRMECDAGAQH/AgEFMA4GA1UdDwEB/wQEAwIBBjANBgkqhkiG9w0BAQUFAAOCAQEAlGRZrTlk5ynr
E/5aw4sTV8gEJPB0d8Bg42f76Ymmg7+Wgnxu1MM9756AbrsptJh6sTtU6zkXR34ajgv8HzFZMQSy
zhfzLMdiNlXiItiJVbSYSKpk+tYcNthEeFpaIzpXl/V6ME+un2pMSyuOoAPjPuCp1NJ70rOo4nI8
rZ7/gFnkm0W09juwzTkZmDLl6iFhkOQxIY40sfcvNUqFENrnijchvllj4PKFiDFT1FQUhXB59C4G
dyd1Lx+4ivn+xbrYNuSD7Odlt79jWvNGr4GUN9RBjNYj1h7P9WgbRGOiWrqnNVmh5XAFmw4jV5mU
Cm26OWMohpLzGITY+9HPBVZkVw==
-----END CERTIFICATE-----

Cybertrust Global Root
======================
-----BEGIN CERTIFICATE-----
MIIDoTCCAomgAwIBAgILBAAAAAABD4WqLUgwDQYJKoZIhvcNAQEFBQAwOzEYMBYGA1UEChMPQ3li
ZXJ0cnVzdCwgSW5jMR8wHQYDVQQDExZDeWJlcnRydXN0IEdsb2JhbCBSb290MB4XDTA2MTIxNTA4
MDAwMFoXDTIxMTIxNTA4MDAwMFowOzEYMBYGA1UEChMPQ3liZXJ0cnVzdCwgSW5jMR8wHQYDVQQD
ExZDeWJlcnRydXN0IEdsb2JhbCBSb290MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA
+Mi8vRRQZhP/8NN57CPytxrHjoXxEnOmGaoQ25yiZXRadz5RfVb23CO21O1fWLE3TdVJDm71aofW
0ozSJ8bi/zafmGWgE07GKmSb1ZASzxQG9Dvj1Ci+6A74q05IlG2OlTEQXO2iLb3VOm2yHLtgwEZL
AfVJrn5GitB0jaEMAs7u/OePuGtm839EAL9mJRQr3RAwHQeWP032a7iPt3sMpTjr3kfb1V05/Iin
89cqdPHoWqI7n1C6poxFNcJQZZXcY4Lv3b93TZxiyWNzFtApD0mpSPCzqrdsxacwOUBdrsTiXSZT
8M4cIwhhqJQZugRiQOwfOHB3EgZxpzAYXSUnpQIDAQABo4GlMIGiMA4GA1UdDwEB/wQEAwIBBjAP
BgNVHRMBAf8EBTADAQH/MB0GA1UdDgQWBBS2CHsNesysIEyGVjJez6tuhS1wVzA/BgNVHR8EODA2
MDSgMqAwhi5odHRwOi8vd3d3Mi5wdWJsaWMtdHJ1c3QuY29tL2NybC9jdC9jdHJvb3QuY3JsMB8G
A1UdIwQYMBaAFLYIew16zKwgTIZWMl7Pq26FLXBXMA0GCSqGSIb3DQEBBQUAA4IBAQBW7wojoFRO
lZfJ+InaRcHUowAl9B8Tq7ejhVhpwjCt2BWKLePJzYFa+HMjWqd8BfP9IjsO0QbE2zZMcwSO5bAi
5MXzLqXZI+O4Tkogp24CJJ8iYGd7ix1yCcUxXOl5n4BHPa2hCwcUPUf/A2kaDAtE52Mlp3+yybh2
hO0j9n0Hq0V+09+zv+mKts2oomcrUtW3ZfA5TGOgkXmTUg9U3YO7n9GPp1Nzw8v/MOx8BLjYRB+T
X3EJIrduPuocA06dGiBh+4E37F78CkWr1+cXVdCg6mCbpvbjjFspwgZgFJ0tl0ypkxWdYcQBX0jW
WL1WMRJOEcgh4LMRkWXbtKaIOM5V
-----END CERTIFICATE-----

ePKI Root Certification Authority
=================================
-----BEGIN CERTIFICATE-----
MIIFsDCCA5igAwIBAgIQFci9ZUdcr7iXAF7kBtK8nTANBgkqhkiG9w0BAQUFADBeMQswCQYDVQQG
EwJUVzEjMCEGA1UECgwaQ2h1bmdod2EgVGVsZWNvbSBDby4sIEx0ZC4xKjAoBgNVBAsMIWVQS0kg
Um9vdCBDZXJ0aWZpY2F0aW9uIEF1dGhvcml0eTAeFw0wNDEyMjAwMjMxMjdaFw0zNDEyMjAwMjMx
MjdaMF4xCzAJBgNVBAYTAlRXMSMwIQYDVQQKDBpDaHVuZ2h3YSBUZWxlY29tIENvLiwgTHRkLjEq
MCgGA1UECwwhZVBLSSBSb290IENlcnRpZmljYXRpb24gQXV0aG9yaXR5MIICIjANBgkqhkiG9w0B
AQEFAAOCAg8AMIICCgKCAgEA4SUP7o3biDN1Z82tH306Tm2d0y8U82N0ywEhajfqhFAHSyZbCUNs
IZ5qyNUD9WBpj8zwIuQf5/dqIjG3LBXy4P4AakP/h2XGtRrBp0xtInAhijHyl3SJCRImHJ7K2RKi
lTza6We/CKBk49ZCt0Xvl/T29de1ShUCWH2YWEtgvM3XDZoTM1PRYfl61dd4s5oz9wCGzh1NlDiv
qOx4UXCKXBCDUSH3ET00hl7lSM2XgYI1TBnsZfZrxQWh7kcT1rMhJ5QQCtkkO7q+RBNGMD+XPNjX
12ruOzjjK9SXDrkb5wdJfzcq+Xd4z1TtW0ado4AOkUPB1ltfFLqfpo0kR0BZv3I4sjZsN/+Z0V0O
WQqraffAsgRFelQArr5T9rXn4fg8ozHSqf4hUmTFpmfwdQcGlBSBVcYn5AGPF8Fqcde+S/uUWH1+
ETOxQvdibBjWzwloPn9s9h6PYq2lY9sJpx8iQkEeb5mKPtf5P0B6ebClAZLSnT0IFaUQAS2zMnao
lQ2zepr7BxB4EW/hj8e6DyUadCrlHJhBmd8hh+iVBmoKs2pHdmX2Os+PYhcZewoozRrSgx4hxyy/
vv9haLdnG7t4TY3OZ+XkwY63I2binZB1NJipNiuKmpS5nezMirH4JYlcWrYvjB9teSSnUmjDhDXi
Zo1jDiVN1Rmy5nk3pyKdVDECAwEAAaNqMGgwHQYDVR0OBBYEFB4M97Zn8uGSJglFwFU5Lnc/Qkqi
MAwGA1UdEwQFMAMBAf8wOQYEZyoHAAQxMC8wLQIBADAJBgUrDgMCGgUAMAcGBWcqAwAABBRFsMLH
ClZ87lt4DJX5GFPBphzYEDANBgkqhkiG9w0BAQUFAAOCAgEACbODU1kBPpVJufGBuvl2ICO1J2B0
1GqZNF5sAFPZn/KmsSQHRGoqxqWOeBLoR9lYGxMqXnmbnwoqZ6YlPwZpVnPDimZI+ymBV3QGypzq
KOg4ZyYr8dW1P2WT+DZdjo2NQCCHGervJ8A9tDkPJXtoUHRVnAxZfVo9QZQlUgjgRywVMRnVvwdV
xrsStZf0X4OFunHB2WyBEXYKCrC/gpf36j36+uwtqSiUO1bd0lEursC9CBWMd1I0ltabrNMdjmEP
NXubrjlpC2JgQCA2j6/7Nu4tCEoduL+bXPjqpRugc6bY+G7gMwRfaKonh+3ZwZCc7b3jajWvY9+r
GNm65ulK6lCKD2GTHuItGeIwlDWSXQ62B68ZgI9HkFFLLk3dheLSClIKF5r8GrBQAuUBo2M3IUxE
xJtRmREOc5wGj1QupyheRDmHVi03vYVElOEMSyycw5KFNGHLD7ibSkNS/jQ6fbjpKdx2qcgw+BRx
gMYeNkh0IkFch4LoGHGLQYlE535YW6i4jRPpp2zDR+2zGp1iro2C6pSe3VkQw63d4k3jMdXH7Ojy
sP6SHhYKGvzZ8/gntsm+HbRsZJB/9OTEW9c3rkIO3aQab3yIVMUWbuF6aC74Or8NpDyJO3inTmOD
BCEIZ43ygknQW/2xzQ+DhNQ+IIX3Sj0rnP0qCglN6oH4EZw=
-----END CERTIFICATE-----

T\xc3\x9c\x42\xC4\xB0TAK UEKAE K\xC3\xB6k Sertifika Hizmet Sa\xC4\x9Flay\xc4\xb1\x63\xc4\xb1s\xc4\xb1 - S\xC3\xBCr\xC3\xBCm 3
=============================================================================================================================
-----BEGIN CERTIFICATE-----
MIIFFzCCA/+gAwIBAgIBETANBgkqhkiG9w0BAQUFADCCASsxCzAJBgNVBAYTAlRSMRgwFgYDVQQH
DA9HZWJ6ZSAtIEtvY2FlbGkxRzBFBgNVBAoMPlTDvHJraXllIEJpbGltc2VsIHZlIFRla25vbG9q
aWsgQXJhxZ90xLFybWEgS3VydW11IC0gVMOcQsSwVEFLMUgwRgYDVQQLDD9VbHVzYWwgRWxla3Ry
b25payB2ZSBLcmlwdG9sb2ppIEFyYcWfdMSxcm1hIEVuc3RpdMO8c8O8IC0gVUVLQUUxIzAhBgNV
BAsMGkthbXUgU2VydGlmaWthc3lvbiBNZXJrZXppMUowSAYDVQQDDEFUw5xCxLBUQUsgVUVLQUUg
S8O2ayBTZXJ0aWZpa2EgSGl6bWV0IFNhxJ9sYXnEsWPEsXPEsSAtIFPDvHLDvG0gMzAeFw0wNzA4
MjQxMTM3MDdaFw0xNzA4MjExMTM3MDdaMIIBKzELMAkGA1UEBhMCVFIxGDAWBgNVBAcMD0dlYnpl
IC0gS29jYWVsaTFHMEUGA1UECgw+VMO8cmtpeWUgQmlsaW1zZWwgdmUgVGVrbm9sb2ppayBBcmHF
n3TEsXJtYSBLdXJ1bXUgLSBUw5xCxLBUQUsxSDBGBgNVBAsMP1VsdXNhbCBFbGVrdHJvbmlrIHZl
IEtyaXB0b2xvamkgQXJhxZ90xLFybWEgRW5zdGl0w7xzw7wgLSBVRUtBRTEjMCEGA1UECwwaS2Ft
dSBTZXJ0aWZpa2FzeW9uIE1lcmtlemkxSjBIBgNVBAMMQVTDnELEsFRBSyBVRUtBRSBLw7ZrIFNl
cnRpZmlrYSBIaXptZXQgU2HEn2xhecSxY8Sxc8SxIC0gU8O8csO8bSAzMIIBIjANBgkqhkiG9w0B
AQEFAAOCAQ8AMIIBCgKCAQEAim1L/xCIOsP2fpTo6iBkcK4hgb46ezzb8R1Sf1n68yJMlaCQvEhO
Eav7t7WNeoMojCZG2E6VQIdhn8WebYGHV2yKO7Rm6sxA/OOqbLLLAdsyv9Lrhc+hDVXDWzhXcLh1
xnnRFDDtG1hba+818qEhTsXOfJlfbLm4IpNQp81McGq+agV/E5wrHur+R84EpW+sky58K5+eeROR
6Oqeyjh1jmKwlZMq5d/pXpduIF9fhHpEORlAHLpVK/swsoHvhOPc7Jg4OQOFCKlUAwUp8MmPi+oL
hmUZEdPpCSPeaJMDyTYcIW7OjGbxmTDY17PDHfiBLqi9ggtm/oLL4eAagsNAgQIDAQABo0IwQDAd
BgNVHQ4EFgQUvYiHyY/2pAoLquvF/pEjnatKijIwDgYDVR0PAQH/BAQDAgEGMA8GA1UdEwEB/wQF
MAMBAf8wDQYJKoZIhvcNAQEFBQADggEBAB18+kmPNOm3JpIWmgV050vQbTlswyb2zrgxvMTfvCr4
N5EY3ATIZJkrGG2AA1nJrvhY0D7twyOfaTyGOBye79oneNGEN3GKPEs5z35FBtYt2IpNeBLWrcLT
y9LQQfMmNkqblWwM7uXRQydmwYj3erMgbOqwaSvHIOgMA8RBBZniP+Rr+KCGgceExh/VS4ESshYh
LBOhgLJeDEoTniDYYkCrkOpkSi+sDQESeUWoL4cZaMjihccwsnX5OD+ywJO0a+IDRM5noN+J1q2M
dqMTw5RhK2vZbMEHCiIHhWyFJEapvj+LeISCfiQMnf2BN+MlqO02TpUsyZyQ2uypQjyttgI=
-----END CERTIFICATE-----

Buypass Class 2 CA 1
====================
-----BEGIN CERTIFICATE-----
MIIDUzCCAjugAwIBAgIBATANBgkqhkiG9w0BAQUFADBLMQswCQYDVQQGEwJOTzEdMBsGA1UECgwU
QnV5cGFzcyBBUy05ODMxNjMzMjcxHTAbBgNVBAMMFEJ1eXBhc3MgQ2xhc3MgMiBDQSAxMB4XDTA2
MTAxMzEwMjUwOVoXDTE2MTAxMzEwMjUwOVowSzELMAkGA1UEBhMCTk8xHTAbBgNVBAoMFEJ1eXBh
c3MgQVMtOTgzMTYzMzI3MR0wGwYDVQQDDBRCdXlwYXNzIENsYXNzIDIgQ0EgMTCCASIwDQYJKoZI
hvcNAQEBBQADggEPADCCAQoCggEBAIs8B0XY9t/mx8q6jUPFR42wWsE425KEHK8T1A9vNkYgxC7M
cXA0ojTTNy7Y3Tp3L8DrKehc0rWpkTSHIln+zNvnma+WwajHQN2lFYxuyHyXA8vmIPLXl18xoS83
0r7uvqmtqEyeIWZDO6i88wmjONVZJMHCR3axiFyCO7srpgTXjAePzdVBHfCuuCkslFJgNJQ72uA4
0Z0zPhX0kzLFANq1KWYOOngPIVJfAuWSeyXTkh4vFZ2B5J2O6O+JzhRMVB0cgRJNcKi+EAUXfh/R
uFdV7c27UsKwHnjCTTZoy1YmwVLBvXb3WNVyfh9EdrsAiR0WnVE1703CVu9r4Iw7DekCAwEAAaNC
MEAwDwYDVR0TAQH/BAUwAwEB/zAdBgNVHQ4EFgQUP42aWYv8e3uco684sDntkHGA1sgwDgYDVR0P
AQH/BAQDAgEGMA0GCSqGSIb3DQEBBQUAA4IBAQAVGn4TirnoB6NLJzKyQJHyIdFkhb5jatLPgcIV
1Xp+DCmsNx4cfHZSldq1fyOhKXdlyTKdqC5Wq2B2zha0jX94wNWZUYN/Xtm+DKhQ7SLHrQVMdvvt
7h5HZPb3J31cKA9FxVxiXqaakZG3Uxcu3K1gnZZkOb1naLKuBctN518fV4bVIJwo+28TOPX2EZL2
fZleHwzoq0QkKXJAPTZSr4xYkHPB7GEseaHsh7U/2k3ZIQAw3pDaDtMaSKk+hQsUi4y8QZ5q9w5w
wDX3OaJdZtB7WZ+oRxKaJyOkLY4ng5IgodcVf/EuGO70SH8vf/GhGLWhC5SgYiAynB321O+/TIho
-----END CERTIFICATE-----

EBG Elektronik Sertifika Hizmet Sa\xC4\x9Flay\xc4\xb1\x63\xc4\xb1s\xc4\xb1
==========================================================================
-----BEGIN CERTIFICATE-----
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
-----END CERTIFICATE-----

certSIGN ROOT CA
================
-----BEGIN CERTIFICATE-----
MIIDODCCAiCgAwIBAgIGIAYFFnACMA0GCSqGSIb3DQEBBQUAMDsxCzAJBgNVBAYTAlJPMREwDwYD
VQQKEwhjZXJ0U0lHTjEZMBcGA1UECxMQY2VydFNJR04gUk9PVCBDQTAeFw0wNjA3MDQxNzIwMDRa
Fw0zMTA3MDQxNzIwMDRaMDsxCzAJBgNVBAYTAlJPMREwDwYDVQQKEwhjZXJ0U0lHTjEZMBcGA1UE
CxMQY2VydFNJR04gUk9PVCBDQTCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBALczuX7I
JUqOtdu0KBuqV5Do0SLTZLrTk+jUrIZhQGpgV2hUhE28alQCBf/fm5oqrl0Hj0rDKH/v+yv6efHH
rfAQUySQi2bJqIirr1qjAOm+ukbuW3N7LBeCgV5iLKECZbO9xSsAfsT8AzNXDe3i+s5dRdY4zTW2
ssHQnIFKquSyAVwdj1+ZxLGt24gh65AIgoDzMKND5pCCrlUoSe1b16kQOA7+j0xbm0bqQfWwCHTD
0IgztnzXdN/chNFDDnU5oSVAKOp4yw4sLjmdjItuFhwvJoIQ4uNllAoEwF73XVv4EOLQunpL+943
AAAaWyjj0pxzPjKHmKHJUS/X3qwzs08CAwEAAaNCMEAwDwYDVR0TAQH/BAUwAwEB/zAOBgNVHQ8B
Af8EBAMCAcYwHQYDVR0OBBYEFOCMm9slSbPxfIbWskKHC9BroNnkMA0GCSqGSIb3DQEBBQUAA4IB
AQA+0hyJLjX8+HXd5n9liPRyTMks1zJO890ZeUe9jjtbkw9QSSQTaxQGcu8J06Gh40CEyecYMnQ8
SG4Pn0vU9x7Tk4ZkVJdjclDVVc/6IJMCopvDI5NOFlV2oHB5bc0hH88vLbwZ44gx+FkagQnIl6Z0
x2DEW8xXjrJ1/RsCCdtZb3KTafcxQdaIOL+Hsr0Wefmq5L6IJd1hJyMctTEHBDa0GpC9oHRxUIlt
vBTjD4au8as+x6AJzKNI0eDbZOeStc+vckNwi/nDhDwTqn6Sm1dTk/pwwpEOMfmbZ13pljheX7Nz
TogVZ96edhBiIL5VaZVDADlN9u6wWk5JRFRYX0KD
-----END CERTIFICATE-----

CNNIC ROOT
==========
-----BEGIN CERTIFICATE-----
MIIDVTCCAj2gAwIBAgIESTMAATANBgkqhkiG9w0BAQUFADAyMQswCQYDVQQGEwJDTjEOMAwGA1UE
ChMFQ05OSUMxEzARBgNVBAMTCkNOTklDIFJPT1QwHhcNMDcwNDE2MDcwOTE0WhcNMjcwNDE2MDcw
OTE0WjAyMQswCQYDVQQGEwJDTjEOMAwGA1UEChMFQ05OSUMxEzARBgNVBAMTCkNOTklDIFJPT1Qw
ggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQDTNfc/c3et6FtzF8LRb+1VvG7q6KR5smzD
o+/hn7E7SIX1mlwhIhAsxYLO2uOabjfhhyzcuQxauohV3/2q2x8x6gHx3zkBwRP9SFIhxFXf2tiz
VHa6dLG3fdfA6PZZxU3Iva0fFNrfWEQlMhkqx35+jq44sDB7R3IJMfAw28Mbdim7aXZOV/kbZKKT
VrdvmW7bCgScEeOAH8tjlBAKqeFkgjH5jCftppkA9nCTGPihNIaj3XrCGHn2emU1z5DrvTOTn1Or
czvmmzQgLx3vqR1jGqCA2wMv+SYahtKNu6m+UjqHZ0gNv7Sg2Ca+I19zN38m5pIEo3/PIKe38zrK
y5nLAgMBAAGjczBxMBEGCWCGSAGG+EIBAQQEAwIABzAfBgNVHSMEGDAWgBRl8jGtKvf33VKWCscC
wQ7vptU7ETAPBgNVHRMBAf8EBTADAQH/MAsGA1UdDwQEAwIB/jAdBgNVHQ4EFgQUZfIxrSr3991S
lgrHAsEO76bVOxEwDQYJKoZIhvcNAQEFBQADggEBAEs17szkrr/Dbq2flTtLP1se31cpolnKOOK5
Gv+e5m4y3R6u6jW39ZORTtpC4cMXYFDy0VwmuYK36m3knITnA3kXr5g9lNvHugDnuL8BV8F3RTIM
O/G0HAiw/VGgod2aHRM2mm23xzy54cXZF/qD1T0VoDy7HgviyJA/qIYM/PmLXoXLT1tLYhFHxUV8
BS9BsZ4QaRuZluBVeftOhpm4lNqGOGqTo+fLbuXf6iFViZx9fX+Y9QCJ7uOEwFyWtcVG6kbghVW2
G8kS1sHNzYDzAgE8yGnLRUhj2JTQ7IUOO04RZfSCjKY9ri4ilAnIXOo8gV0WKgOXFlUJ24pBgp5m
mxE=
-----END CERTIFICATE-----

ApplicationCA - Japanese Government
===================================
-----BEGIN CERTIFICATE-----
MIIDoDCCAoigAwIBAgIBMTANBgkqhkiG9w0BAQUFADBDMQswCQYDVQQGEwJKUDEcMBoGA1UEChMT
SmFwYW5lc2UgR292ZXJubWVudDEWMBQGA1UECxMNQXBwbGljYXRpb25DQTAeFw0wNzEyMTIxNTAw
MDBaFw0xNzEyMTIxNTAwMDBaMEMxCzAJBgNVBAYTAkpQMRwwGgYDVQQKExNKYXBhbmVzZSBHb3Zl
cm5tZW50MRYwFAYDVQQLEw1BcHBsaWNhdGlvbkNBMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIB
CgKCAQEAp23gdE6Hj6UG3mii24aZS2QNcfAKBZuOquHMLtJqO8F6tJdhjYq+xpqcBrSGUeQ3DnR4
fl+Kf5Sk10cI/VBaVuRorChzoHvpfxiSQE8tnfWuREhzNgaeZCw7NCPbXCbkcXmP1G55IrmTwcrN
wVbtiGrXoDkhBFcsovW8R0FPXjQilbUfKW1eSvNNcr5BViCH/OlQR9cwFO5cjFW6WY2H/CPek9AE
jP3vbb3QesmlOmpyM8ZKDQUXKi17safY1vC+9D/qDihtQWEjdnjDuGWk81quzMKq2edY3rZ+nYVu
nyoKb58DKTCXKB28t89UKU5RMfkntigm/qJj5kEW8DOYRwIDAQABo4GeMIGbMB0GA1UdDgQWBBRU
WssmP3HMlEYNllPqa0jQk/5CdTAOBgNVHQ8BAf8EBAMCAQYwWQYDVR0RBFIwUKROMEwxCzAJBgNV
BAYTAkpQMRgwFgYDVQQKDA/ml6XmnKzlm73mlL/lupwxIzAhBgNVBAsMGuOCouODl+ODquOCseOD
vOOCt+ODp+ODs0NBMA8GA1UdEwEB/wQFMAMBAf8wDQYJKoZIhvcNAQEFBQADggEBADlqRHZ3ODrs
o2dGD/mLBqj7apAxzn7s2tGJfHrrLgy9mTLnsCTWw//1sogJhyzjVOGjprIIC8CFqMjSnHH2HZ9g
/DgzE+Ge3Atf2hZQKXsvcJEPmbo0NI2VdMV+eKlmXb3KIXdCEKxmJj3ekav9FfBv7WxfEPjzFvYD
io+nEhEMy/0/ecGc/WLuo89UDNErXxc+4z6/wCs+CZv+iKZ+tJIX/COUgb1up8WMwusRRdv4QcmW
dupwX3kSa+SjB1oF7ydJzyGfikwJcGapJsErEU4z0g781mzSDjJkaP+tBXhfAx2o45CsJOAPQKdL
rosot4LKGAfmt1t06SAZf7IbiVQ=
-----END CERTIFICATE-----

GeoTrust Primary Certification Authority - G3
=============================================
-----BEGIN CERTIFICATE-----
MIID/jCCAuagAwIBAgIQFaxulBmyeUtB9iepwxgPHzANBgkqhkiG9w0BAQsFADCBmDELMAkGA1UE
BhMCVVMxFjAUBgNVBAoTDUdlb1RydXN0IEluYy4xOTA3BgNVBAsTMChjKSAyMDA4IEdlb1RydXN0
IEluYy4gLSBGb3IgYXV0aG9yaXplZCB1c2Ugb25seTE2MDQGA1UEAxMtR2VvVHJ1c3QgUHJpbWFy
eSBDZXJ0aWZpY2F0aW9uIEF1dGhvcml0eSAtIEczMB4XDTA4MDQwMjAwMDAwMFoXDTM3MTIwMTIz
NTk1OVowgZgxCzAJBgNVBAYTAlVTMRYwFAYDVQQKEw1HZW9UcnVzdCBJbmMuMTkwNwYDVQQLEzAo
YykgMjAwOCBHZW9UcnVzdCBJbmMuIC0gRm9yIGF1dGhvcml6ZWQgdXNlIG9ubHkxNjA0BgNVBAMT
LUdlb1RydXN0IFByaW1hcnkgQ2VydGlmaWNhdGlvbiBBdXRob3JpdHkgLSBHMzCCASIwDQYJKoZI
hvcNAQEBBQADggEPADCCAQoCggEBANziXmJYHTNXOTIz+uvLh4yn1ErdBojqZI4xmKU4kB6Yzy5j
K/BGvESyiaHAKAxJcCGVn2TAppMSAmUmhsalifD614SgcK9PGpc/BkTVyetyEH3kMSj7HGHmKAdE
c5IiaacDiGydY8hS2pgn5whMcD60yRLBxWeDXTPzAxHsatBT4tG6NmCUgLthY2xbF37fQJQeqw3C
IShwiP/WJmxsYAQlTlV+fe+/lEjetx3dcI0FX4ilm/LC7urRQEFtYjgdVgbFA0dRIBn8exALDmKu
dlW/X3e+PkkBUz2YJQN2JFodtNuJ6nnltrM7P7pMKEF/BqxqjsHQ9gUdfeZChuOl1UcCAwEAAaNC
MEAwDwYDVR0TAQH/BAUwAwEB/zAOBgNVHQ8BAf8EBAMCAQYwHQYDVR0OBBYEFMR5yo6hTgMdHNxr
2zFblD4/MH8tMA0GCSqGSIb3DQEBCwUAA4IBAQAtxRPPVoB7eni9n64smefv2t+UXglpp+duaIy9
cr5HqQ6XErhK8WTTOd8lNNTBzU6B8A8ExCSzNJbGpqow32hhc9f5joWJ7w5elShKKiePEI4ufIbE
Ap7aDHdlDkQNkv39sxY2+hENHYwOB4lqKVb3cvTdFZx3NWZXqxNT2I7BQMXXExZacse3aQHEerGD
AWh9jUGhlBjBJVz88P6DAod8DQ3PLghcSkANPuyBYeYk28rgDi0Hsj5W3I31QYUHSJsMC8tJP33s
t/3LjWeJGqvtux6jAAgIFyqCXDFdRootD4abdNlF+9RAsXqqaC2Gspki4cErx5z481+oghLrGREt
-----END CERTIFICATE-----

thawte Primary Root CA - G2
===========================
-----BEGIN CERTIFICATE-----
MIICiDCCAg2gAwIBAgIQNfwmXNmET8k9Jj1Xm67XVjAKBggqhkjOPQQDAzCBhDELMAkGA1UEBhMC
VVMxFTATBgNVBAoTDHRoYXd0ZSwgSW5jLjE4MDYGA1UECxMvKGMpIDIwMDcgdGhhd3RlLCBJbmMu
IC0gRm9yIGF1dGhvcml6ZWQgdXNlIG9ubHkxJDAiBgNVBAMTG3RoYXd0ZSBQcmltYXJ5IFJvb3Qg
Q0EgLSBHMjAeFw0wNzExMDUwMDAwMDBaFw0zODAxMTgyMzU5NTlaMIGEMQswCQYDVQQGEwJVUzEV
MBMGA1UEChMMdGhhd3RlLCBJbmMuMTgwNgYDVQQLEy8oYykgMjAwNyB0aGF3dGUsIEluYy4gLSBG
b3IgYXV0aG9yaXplZCB1c2Ugb25seTEkMCIGA1UEAxMbdGhhd3RlIFByaW1hcnkgUm9vdCBDQSAt
IEcyMHYwEAYHKoZIzj0CAQYFK4EEACIDYgAEotWcgnuVnfFSeIf+iha/BebfowJPDQfGAFG6DAJS
LSKkQjnE/o/qycG+1E3/n3qe4rF8mq2nhglzh9HnmuN6papu+7qzcMBniKI11KOasf2twu8x+qi5
8/sIxpHR+ymVo0IwQDAPBgNVHRMBAf8EBTADAQH/MA4GA1UdDwEB/wQEAwIBBjAdBgNVHQ4EFgQU
mtgAMADna3+FGO6Lts6KDPgR4bswCgYIKoZIzj0EAwMDaQAwZgIxAN344FdHW6fmCsO99YCKlzUN
G4k8VIZ3KMqh9HneteY4sPBlcIx/AlTCv//YoT7ZzwIxAMSNlPzcU9LcnXgWHxUzI1NS41oxXZ3K
rr0TKUQNJ1uo52icEvdYPy5yAlejj6EULg==
-----END CERTIFICATE-----

thawte Primary Root CA - G3
===========================
-----BEGIN CERTIFICATE-----
MIIEKjCCAxKgAwIBAgIQYAGXt0an6rS0mtZLL/eQ+zANBgkqhkiG9w0BAQsFADCBrjELMAkGA1UE
BhMCVVMxFTATBgNVBAoTDHRoYXd0ZSwgSW5jLjEoMCYGA1UECxMfQ2VydGlmaWNhdGlvbiBTZXJ2
aWNlcyBEaXZpc2lvbjE4MDYGA1UECxMvKGMpIDIwMDggdGhhd3RlLCBJbmMuIC0gRm9yIGF1dGhv
cml6ZWQgdXNlIG9ubHkxJDAiBgNVBAMTG3RoYXd0ZSBQcmltYXJ5IFJvb3QgQ0EgLSBHMzAeFw0w
ODA0MDIwMDAwMDBaFw0zNzEyMDEyMzU5NTlaMIGuMQswCQYDVQQGEwJVUzEVMBMGA1UEChMMdGhh
d3RlLCBJbmMuMSgwJgYDVQQLEx9DZXJ0aWZpY2F0aW9uIFNlcnZpY2VzIERpdmlzaW9uMTgwNgYD
VQQLEy8oYykgMjAwOCB0aGF3dGUsIEluYy4gLSBGb3IgYXV0aG9yaXplZCB1c2Ugb25seTEkMCIG
A1UEAxMbdGhhd3RlIFByaW1hcnkgUm9vdCBDQSAtIEczMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8A
MIIBCgKCAQEAsr8nLPvb2FvdeHsbnndmgcs+vHyu86YnmjSjaDFxODNi5PNxZnmxqWWjpYvVj2At
P0LMqmsywCPLLEHd5N/8YZzic7IilRFDGF/Eth9XbAoFWCLINkw6fKXRz4aviKdEAhN0cXMKQlkC
+BsUa0Lfb1+6a4KinVvnSr0eAXLbS3ToO39/fR8EtCab4LRarEc9VbjXsCZSKAExQGbY2SS99irY
7CFJXJv2eul/VTV+lmuNk5Mny5K76qxAwJ/C+IDPXfRa3M50hqY+bAtTyr2SzhkGcuYMXDhpxwTW
vGzOW/b3aJzcJRVIiKHpqfiYnODz1TEoYRFsZ5aNOZnLwkUkOQIDAQABo0IwQDAPBgNVHRMBAf8E
BTADAQH/MA4GA1UdDwEB/wQEAwIBBjAdBgNVHQ4EFgQUrWyqlGCc7eT/+j4KdCtjA/e2Wb8wDQYJ
KoZIhvcNAQELBQADggEBABpA2JVlrAmSicY59BDlqQ5mU1143vokkbvnRFHfxhY0Cu9qRFHqKweK
A3rD6z8KLFIWoCtDuSWQP3CpMyVtRRooOyfPqsMpQhvfO0zAMzRbQYi/aytlryjvsvXDqmbOe1bu
t8jLZ8HJnBoYuMTDSQPxYA5QzUbF83d597YV4Djbxy8ooAw/dyZ02SUS2jHaGh7cKUGRIjxpp7sC
8rZcJwOJ9Abqm+RyguOhCcHpABnTPtRwa7pxpqpYrvS76Wy274fMm7v/OeZWYdMKp8RcTGB7BXcm
er/YB1IsYvdwY9k5vG8cwnncdimvzsUsZAReiDZuMdRAGmI0Nj81Aa6sY6A=
-----END CERTIFICATE-----

GeoTrust Primary Certification Authority - G2
=============================================
-----BEGIN CERTIFICATE-----
MIICrjCCAjWgAwIBAgIQPLL0SAoA4v7rJDteYD7DazAKBggqhkjOPQQDAzCBmDELMAkGA1UEBhMC
VVMxFjAUBgNVBAoTDUdlb1RydXN0IEluYy4xOTA3BgNVBAsTMChjKSAyMDA3IEdlb1RydXN0IElu
Yy4gLSBGb3IgYXV0aG9yaXplZCB1c2Ugb25seTE2MDQGA1UEAxMtR2VvVHJ1c3QgUHJpbWFyeSBD
ZXJ0aWZpY2F0aW9uIEF1dGhvcml0eSAtIEcyMB4XDTA3MTEwNTAwMDAwMFoXDTM4MDExODIzNTk1
OVowgZgxCzAJBgNVBAYTAlVTMRYwFAYDVQQKEw1HZW9UcnVzdCBJbmMuMTkwNwYDVQQLEzAoYykg
MjAwNyBHZW9UcnVzdCBJbmMuIC0gRm9yIGF1dGhvcml6ZWQgdXNlIG9ubHkxNjA0BgNVBAMTLUdl
b1RydXN0IFByaW1hcnkgQ2VydGlmaWNhdGlvbiBBdXRob3JpdHkgLSBHMjB2MBAGByqGSM49AgEG
BSuBBAAiA2IABBWx6P0DFUPlrOuHNxFi79KDNlJ9RVcLSo17VDs6bl8VAsBQps8lL33KSLjHUGMc
KiEIfJo22Av+0SbFWDEwKCXzXV2juLaltJLtbCyf691DiaI8S0iRHVDsJt/WYC69IaNCMEAwDwYD
VR0TAQH/BAUwAwEB/zAOBgNVHQ8BAf8EBAMCAQYwHQYDVR0OBBYEFBVfNVdRVfslsq0DafwBo/q+
EVXVMAoGCCqGSM49BAMDA2cAMGQCMGSWWaboCd6LuvpaiIjwH5HTRqjySkwCY/tsXzjbLkGTqQ7m
ndwxHLKgpxgceeHHNgIwOlavmnRs9vuD4DPTCF+hnMJbn0bWtsuRBmOiBuczrD6ogRLQy7rQkgu2
npaqBA+K
-----END CERTIFICATE-----

VeriSign Universal Root Certification Authority
===============================================
-----BEGIN CERTIFICATE-----
MIIEuTCCA6GgAwIBAgIQQBrEZCGzEyEDDrvkEhrFHTANBgkqhkiG9w0BAQsFADCBvTELMAkGA1UE
BhMCVVMxFzAVBgNVBAoTDlZlcmlTaWduLCBJbmMuMR8wHQYDVQQLExZWZXJpU2lnbiBUcnVzdCBO
ZXR3b3JrMTowOAYDVQQLEzEoYykgMjAwOCBWZXJpU2lnbiwgSW5jLiAtIEZvciBhdXRob3JpemVk
IHVzZSBvbmx5MTgwNgYDVQQDEy9WZXJpU2lnbiBVbml2ZXJzYWwgUm9vdCBDZXJ0aWZpY2F0aW9u
IEF1dGhvcml0eTAeFw0wODA0MDIwMDAwMDBaFw0zNzEyMDEyMzU5NTlaMIG9MQswCQYDVQQGEwJV
UzEXMBUGA1UEChMOVmVyaVNpZ24sIEluYy4xHzAdBgNVBAsTFlZlcmlTaWduIFRydXN0IE5ldHdv
cmsxOjA4BgNVBAsTMShjKSAyMDA4IFZlcmlTaWduLCBJbmMuIC0gRm9yIGF1dGhvcml6ZWQgdXNl
IG9ubHkxODA2BgNVBAMTL1ZlcmlTaWduIFVuaXZlcnNhbCBSb290IENlcnRpZmljYXRpb24gQXV0
aG9yaXR5MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAx2E3XrEBNNti1xWb/1hajCMj
1mCOkdeQmIN65lgZOIzF9uVkhbSicfvtvbnazU0AtMgtc6XHaXGVHzk8skQHnOgO+k1KxCHfKWGP
MiJhgsWHH26MfF8WIFFE0XBPV+rjHOPMee5Y2A7Cs0WTwCznmhcrewA3ekEzeOEz4vMQGn+HLL72
9fdC4uW/h2KJXwBL38Xd5HVEMkE6HnFuacsLdUYI0crSK5XQz/u5QGtkjFdN/BMReYTtXlT2NJ8I
AfMQJQYXStrxHXpma5hgZqTZ79IugvHw7wnqRMkVauIDbjPTrJ9VAMf2CGqUuV/c4DPxhGD5WycR
tPwW8rtWaoAljQIDAQABo4GyMIGvMA8GA1UdEwEB/wQFMAMBAf8wDgYDVR0PAQH/BAQDAgEGMG0G
CCsGAQUFBwEMBGEwX6FdoFswWTBXMFUWCWltYWdlL2dpZjAhMB8wBwYFKw4DAhoEFI/l0xqGrI2O
a8PPgGrUSBgsexkuMCUWI2h0dHA6Ly9sb2dvLnZlcmlzaWduLmNvbS92c2xvZ28uZ2lmMB0GA1Ud
DgQWBBS2d/ppSEefUxLVwuoHMnYH0ZcHGTANBgkqhkiG9w0BAQsFAAOCAQEASvj4sAPmLGd75JR3
Y8xuTPl9Dg3cyLk1uXBPY/ok+myDjEedO2Pzmvl2MpWRsXe8rJq+seQxIcaBlVZaDrHC1LGmWazx
Y8u4TB1ZkErvkBYoH1quEPuBUDgMbMzxPcP1Y+Oz4yHJJDnp/RVmRvQbEdBNc6N9Rvk97ahfYtTx
P/jgdFcrGJ2BtMQo2pSXpXDrrB2+BxHw1dvd5Yzw1TKwg+ZX4o+/vqGqvz0dtdQ46tewXDpPaj+P
wGZsY6rp2aQW9IHRlRQOfc2VNNnSj3BzgXucfr2YYdhFh5iQxeuGMMY1v/D/w1WIg0vvBZIGcfK4
mJO37M2CYfE45k+XmCpajQ==
-----END CERTIFICATE-----

VeriSign Class 3 Public Primary Certification Authority - G4
============================================================
-----BEGIN CERTIFICATE-----
MIIDhDCCAwqgAwIBAgIQL4D+I4wOIg9IZxIokYesszAKBggqhkjOPQQDAzCByjELMAkGA1UEBhMC
VVMxFzAVBgNVBAoTDlZlcmlTaWduLCBJbmMuMR8wHQYDVQQLExZWZXJpU2lnbiBUcnVzdCBOZXR3
b3JrMTowOAYDVQQLEzEoYykgMjAwNyBWZXJpU2lnbiwgSW5jLiAtIEZvciBhdXRob3JpemVkIHVz
ZSBvbmx5MUUwQwYDVQQDEzxWZXJpU2lnbiBDbGFzcyAzIFB1YmxpYyBQcmltYXJ5IENlcnRpZmlj
YXRpb24gQXV0aG9yaXR5IC0gRzQwHhcNMDcxMTA1MDAwMDAwWhcNMzgwMTE4MjM1OTU5WjCByjEL
MAkGA1UEBhMCVVMxFzAVBgNVBAoTDlZlcmlTaWduLCBJbmMuMR8wHQYDVQQLExZWZXJpU2lnbiBU
cnVzdCBOZXR3b3JrMTowOAYDVQQLEzEoYykgMjAwNyBWZXJpU2lnbiwgSW5jLiAtIEZvciBhdXRo
b3JpemVkIHVzZSBvbmx5MUUwQwYDVQQDEzxWZXJpU2lnbiBDbGFzcyAzIFB1YmxpYyBQcmltYXJ5
IENlcnRpZmljYXRpb24gQXV0aG9yaXR5IC0gRzQwdjAQBgcqhkjOPQIBBgUrgQQAIgNiAASnVnp8
Utpkmw4tXNherJI9/gHmGUo9FANL+mAnINmDiWn6VMaaGF5VKmTeBvaNSjutEDxlPZCIBIngMGGz
rl0Bp3vefLK+ymVhAIau2o970ImtTR1ZmkGxvEeA3J5iw/mjgbIwga8wDwYDVR0TAQH/BAUwAwEB
/zAOBgNVHQ8BAf8EBAMCAQYwbQYIKwYBBQUHAQwEYTBfoV2gWzBZMFcwVRYJaW1hZ2UvZ2lmMCEw
HzAHBgUrDgMCGgQUj+XTGoasjY5rw8+AatRIGCx7GS4wJRYjaHR0cDovL2xvZ28udmVyaXNpZ24u
Y29tL3ZzbG9nby5naWYwHQYDVR0OBBYEFLMWkf3upm7ktS5Jj4d4gYDs5bG1MAoGCCqGSM49BAMD
A2gAMGUCMGYhDBgmYFo4e1ZC4Kf8NoRRkSAsdk1DPcQdhCPQrNZ8NQbOzWm9kA3bbEhCHQ6qQgIx
AJw9SDkjOVgaFRJZap7v1VmyHVIsmXHNxynfGyphe3HR3vPA5Q06Sqotp9iGKt0uEA==
-----END CERTIFICATE-----

NetLock Arany (Class Gold) Főtanúsítvány
============================================
-----BEGIN CERTIFICATE-----
MIIEFTCCAv2gAwIBAgIGSUEs5AAQMA0GCSqGSIb3DQEBCwUAMIGnMQswCQYDVQQGEwJIVTERMA8G
A1UEBwwIQnVkYXBlc3QxFTATBgNVBAoMDE5ldExvY2sgS2Z0LjE3MDUGA1UECwwuVGFuw7pzw610
dsOhbnlraWFkw7NrIChDZXJ0aWZpY2F0aW9uIFNlcnZpY2VzKTE1MDMGA1UEAwwsTmV0TG9jayBB
cmFueSAoQ2xhc3MgR29sZCkgRsWRdGFuw7pzw610dsOhbnkwHhcNMDgxMjExMTUwODIxWhcNMjgx
MjA2MTUwODIxWjCBpzELMAkGA1UEBhMCSFUxETAPBgNVBAcMCEJ1ZGFwZXN0MRUwEwYDVQQKDAxO
ZXRMb2NrIEtmdC4xNzA1BgNVBAsMLlRhbsO6c8OtdHbDoW55a2lhZMOzayAoQ2VydGlmaWNhdGlv
biBTZXJ2aWNlcykxNTAzBgNVBAMMLE5ldExvY2sgQXJhbnkgKENsYXNzIEdvbGQpIEbFkXRhbsO6
c8OtdHbDoW55MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAxCRec75LbRTDofTjl5Bu
0jBFHjzuZ9lk4BqKf8owyoPjIMHj9DrTlF8afFttvzBPhCf2nx9JvMaZCpDyD/V/Q4Q3Y1GLeqVw
/HpYzY6b7cNGbIRwXdrzAZAj/E4wqX7hJ2Pn7WQ8oLjJM2P+FpD/sLj916jAwJRDC7bVWaaeVtAk
H3B5r9s5VA1lddkVQZQBr17s9o3x/61k/iCa11zr/qYfCGSji3ZVrR47KGAuhyXoqq8fxmRGILdw
fzzeSNuWU7c5d+Qa4scWhHaXWy+7GRWF+GmF9ZmnqfI0p6m2pgP8b4Y9VHx2BJtr+UBdADTHLpl1
neWIA6pN+APSQnbAGwIDAKiLo0UwQzASBgNVHRMBAf8ECDAGAQH/AgEEMA4GA1UdDwEB/wQEAwIB
BjAdBgNVHQ4EFgQUzPpnk/C2uNClwB7zU/2MU9+D15YwDQYJKoZIhvcNAQELBQADggEBAKt/7hwW
qZw8UQCgwBEIBaeZ5m8BiFRhbvG5GK1Krf6BQCOUL/t1fC8oS2IkgYIL9WHxHG64YTjrgfpioTta
YtOUZcTh5m2C+C8lcLIhJsFyUR+MLMOEkMNaj7rP9KdlpeuY0fsFskZ1FSNqb4VjMIDw1Z4fKRzC
bLBQWV2QWzuoDTDPv31/zvGdg73JRm4gpvlhUbohL3u+pRVjodSVh/GeufOJ8z2FuLjbvrW5Kfna
NwUASZQDhETnv0Mxz3WLJdH0pmT1kvarBes96aULNmLazAZfNou2XjG4Kvte9nHfRCaexOYNkbQu
dZWAUWpLMKawYqGT8ZvYzsRjdT9ZR7E=
-----END CERTIFICATE-----

Staat der Nederlanden Root CA - G2
==================================
-----BEGIN CERTIFICATE-----
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
66+KAQ==
-----END CERTIFICATE-----

CA Disig
========
-----BEGIN CERTIFICATE-----
MIIEDzCCAvegAwIBAgIBATANBgkqhkiG9w0BAQUFADBKMQswCQYDVQQGEwJTSzETMBEGA1UEBxMK
QnJhdGlzbGF2YTETMBEGA1UEChMKRGlzaWcgYS5zLjERMA8GA1UEAxMIQ0EgRGlzaWcwHhcNMDYw
MzIyMDEzOTM0WhcNMTYwMzIyMDEzOTM0WjBKMQswCQYDVQQGEwJTSzETMBEGA1UEBxMKQnJhdGlz
bGF2YTETMBEGA1UEChMKRGlzaWcgYS5zLjERMA8GA1UEAxMIQ0EgRGlzaWcwggEiMA0GCSqGSIb3
DQEBAQUAA4IBDwAwggEKAoIBAQCS9jHBfYj9mQGp2HvycXXxMcbzdWb6UShGhJd4NLxs/LxFWYgm
GErENx+hSkS943EE9UQX4j/8SFhvXJ56CbpRNyIjZkMhsDxkovhqFQ4/61HhVKndBpnXmjxUizkD
Pw/Fzsbrg3ICqB9x8y34dQjbYkzo+s7552oftms1grrijxaSfQUMbEYDXcDtab86wYqg6I7ZuUUo
hwjstMoVvoLdtUSLLa2GDGhibYVW8qwUYzrG0ZmsNHhWS8+2rT+MitcE5eN4TPWGqvWP+j1scaMt
ymfraHtuM6kMgiioTGohQBUgDCZbg8KpFhXAJIJdKxatymP2dACw30PEEGBWZ2NFAgMBAAGjgf8w
gfwwDwYDVR0TAQH/BAUwAwEB/zAdBgNVHQ4EFgQUjbJJaJ1yCCW5wCf1UJNWSEZx+Y8wDgYDVR0P
AQH/BAQDAgEGMDYGA1UdEQQvMC2BE2Nhb3BlcmF0b3JAZGlzaWcuc2uGFmh0dHA6Ly93d3cuZGlz
aWcuc2svY2EwZgYDVR0fBF8wXTAtoCugKYYnaHR0cDovL3d3dy5kaXNpZy5zay9jYS9jcmwvY2Ff
ZGlzaWcuY3JsMCygKqAohiZodHRwOi8vY2EuZGlzaWcuc2svY2EvY3JsL2NhX2Rpc2lnLmNybDAa
BgNVHSAEEzARMA8GDSuBHpGT5goAAAABAQEwDQYJKoZIhvcNAQEFBQADggEBAF00dGFMrzvY/59t
WDYcPQuBDRIrRhCA/ec8J9B6yKm2fnQwM6M6int0wHl5QpNt/7EpFIKrIYwvF/k/Ji/1WcbvgAa3
mkkp7M5+cTxqEEHA9tOasnxakZzArFvITV734VP/Q3f8nktnbNfzg9Gg4H8l37iYC5oyOGwwoPP/
CBUz91BKez6jPiCp3C9WgArtQVCwyfTssuMmRAAOb54GvCKWU3BlxFAKRmukLyeBEicTXxChds6K
ezfqwzlhA5WYOudsiCUI/HloDYd9Yvi0X/vF2Ey9WLw/Q1vUHgFNPGO+I++MzVpQuGhU+QqZMxEA
4Z7CRneC9VkGjCFMhwnN5ag=
-----END CERTIFICATE-----

Juur-SK
=======
-----BEGIN CERTIFICATE-----
MIIE5jCCA86gAwIBAgIEO45L/DANBgkqhkiG9w0BAQUFADBdMRgwFgYJKoZIhvcNAQkBFglwa2lA
c2suZWUxCzAJBgNVBAYTAkVFMSIwIAYDVQQKExlBUyBTZXJ0aWZpdHNlZXJpbWlza2Vza3VzMRAw
DgYDVQQDEwdKdXVyLVNLMB4XDTAxMDgzMDE0MjMwMVoXDTE2MDgyNjE0MjMwMVowXTEYMBYGCSqG
SIb3DQEJARYJcGtpQHNrLmVlMQswCQYDVQQGEwJFRTEiMCAGA1UEChMZQVMgU2VydGlmaXRzZWVy
aW1pc2tlc2t1czEQMA4GA1UEAxMHSnV1ci1TSzCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoC
ggEBAIFxNj4zB9bjMI0TfncyRsvPGbJgMUaXhvSYRqTCZUXP00B841oiqBB4M8yIsdOBSvZiF3tf
TQou0M+LI+5PAk676w7KvRhj6IAcjeEcjT3g/1tf6mTll+g/mX8MCgkzABpTpyHhOEvWgxutr2TC
+Rx6jGZITWYfGAriPrsfB2WThbkasLnE+w0R9vXW+RvHLCu3GFH+4Hv2qEivbDtPL+/40UceJlfw
UR0zlv/vWT3aTdEVNMfqPxZIe5EcgEMPPbgFPtGzlc3Yyg/CQ2fbt5PgIoIuvvVoKIO5wTtpeyDa
Tpxt4brNj3pssAki14sL2xzVWiZbDcDq5WDQn/413z8CAwEAAaOCAawwggGoMA8GA1UdEwEB/wQF
MAMBAf8wggEWBgNVHSAEggENMIIBCTCCAQUGCisGAQQBzh8BAQEwgfYwgdAGCCsGAQUFBwICMIHD
HoHAAFMAZQBlACAAcwBlAHIAdABpAGYAaQBrAGEAYQB0ACAAbwBuACAAdgDkAGwAagBhAHMAdABh
AHQAdQBkACAAQQBTAC0AaQBzACAAUwBlAHIAdABpAGYAaQB0AHMAZQBlAHIAaQBtAGkAcwBrAGUA
cwBrAHUAcwAgAGEAbABhAG0ALQBTAEsAIABzAGUAcgB0AGkAZgBpAGsAYQBhAHQAaQBkAGUAIABr
AGkAbgBuAGkAdABhAG0AaQBzAGUAawBzMCEGCCsGAQUFBwIBFhVodHRwOi8vd3d3LnNrLmVlL2Nw
cy8wKwYDVR0fBCQwIjAgoB6gHIYaaHR0cDovL3d3dy5zay5lZS9qdXVyL2NybC8wHQYDVR0OBBYE
FASqekej5ImvGs8KQKcYP2/v6X2+MB8GA1UdIwQYMBaAFASqekej5ImvGs8KQKcYP2/v6X2+MA4G
A1UdDwEB/wQEAwIB5jANBgkqhkiG9w0BAQUFAAOCAQEAe8EYlFOiCfP+JmeaUOTDBS8rNXiRTHyo
ERF5TElZrMj3hWVcRrs7EKACr81Ptcw2Kuxd/u+gkcm2k298gFTsxwhwDY77guwqYHhpNjbRxZyL
abVAyJRld/JXIWY7zoVAtjNjGr95HvxcHdMdkxuLDF2FvZkwMhgJkVLpfKG6/2SSmuz+Ne6ML678
IIbsSt4beDI3poHSna9aEhbKmVv8b20OxaAehsmR0FyYgl9jDIpaq9iVpszLita/ZEuOyoqysOkh
Mp6qqIWYNIE5ITuoOlIyPfZrN4YGWhWY3PARZv40ILcD9EEQfTmEeZZyY7aWAuVrua0ZTbvGRNs2
yyqcjg==
-----END CERTIFICATE-----

Hongkong Post Root CA 1
=======================
-----BEGIN CERTIFICATE-----
MIIDMDCCAhigAwIBAgICA+gwDQYJKoZIhvcNAQEFBQAwRzELMAkGA1UEBhMCSEsxFjAUBgNVBAoT
DUhvbmdrb25nIFBvc3QxIDAeBgNVBAMTF0hvbmdrb25nIFBvc3QgUm9vdCBDQSAxMB4XDTAzMDUx
NTA1MTMxNFoXDTIzMDUxNTA0NTIyOVowRzELMAkGA1UEBhMCSEsxFjAUBgNVBAoTDUhvbmdrb25n
IFBvc3QxIDAeBgNVBAMTF0hvbmdrb25nIFBvc3QgUm9vdCBDQSAxMIIBIjANBgkqhkiG9w0BAQEF
AAOCAQ8AMIIBCgKCAQEArP84tulmAknjorThkPlAj3n54r15/gK97iSSHSL22oVyaf7XPwnU3ZG1
ApzQjVrhVcNQhrkpJsLj2aDxaQMoIIBFIi1WpztUlVYiWR8o3x8gPW2iNr4joLFutbEnPzlTCeqr
auh0ssJlXI6/fMN4hM2eFvz1Lk8gKgifd/PFHsSaUmYeSF7jEAaPIpjhZY4bXSNmO7ilMlHIhqqh
qZ5/dpTCpmy3QfDVyAY45tQM4vM7TG1QjMSDJ8EThFk9nnV0ttgCXjqQesBCNnLsak3c78QA3xMY
V18meMjWCnl3v/evt3a5pQuEF10Q6m/hq5URX208o1xNg1vysxmKgIsLhwIDAQABoyYwJDASBgNV
HRMBAf8ECDAGAQH/AgEDMA4GA1UdDwEB/wQEAwIBxjANBgkqhkiG9w0BAQUFAAOCAQEADkbVPK7i
h9legYsCmEEIjEy82tvuJxuC52pF7BaLT4Wg87JwvVqWuspube5Gi27nKi6Wsxkz67SfqLI37pio
l7Yutmcn1KZJ/RyTZXaeQi/cImyaT/JaFTmxcdcrUehtHJjA2Sr0oYJ71clBoiMBdDhViw+5Lmei
IAQ32pwL0xch4I+XeTRvhEgCIDMb5jREn5Fw9IBehEPCKdJsEhTkYY2sEJCehFC78JZvRZ+K88ps
T/oROhUVRsPNH4NbLUES7VBnQRM9IauUiqpOfMGx+6fWtScvl6tu4B3i0RwsH0Ti/L6RoZz71ilT
c4afU9hDDl3WY4JxHYB0yvbiAmvZWg==
-----END CERTIFICATE-----

SecureSign RootCA11
===================
-----BEGIN CERTIFICATE-----
MIIDbTCCAlWgAwIBAgIBATANBgkqhkiG9w0BAQUFADBYMQswCQYDVQQGEwJKUDErMCkGA1UEChMi
SmFwYW4gQ2VydGlmaWNhdGlvbiBTZXJ2aWNlcywgSW5jLjEcMBoGA1UEAxMTU2VjdXJlU2lnbiBS
b290Q0ExMTAeFw0wOTA0MDgwNDU2NDdaFw0yOTA0MDgwNDU2NDdaMFgxCzAJBgNVBAYTAkpQMSsw
KQYDVQQKEyJKYXBhbiBDZXJ0aWZpY2F0aW9uIFNlcnZpY2VzLCBJbmMuMRwwGgYDVQQDExNTZWN1
cmVTaWduIFJvb3RDQTExMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA/XeqpRyQBTvL
TJszi1oURaTnkBbR31fSIRCkF/3frNYfp+TbfPfs37gD2pRY/V1yfIw/XwFndBWW4wI8h9uuywGO
wvNmxoVF9ALGOrVisq/6nL+k5tSAMJjzDbaTj6nU2DbysPyKyiyhFTOVMdrAG/LuYpmGYz+/3ZMq
g6h2uRMft85OQoWPIucuGvKVCbIFtUROd6EgvanyTgp9UK31BQ1FT0Zx/Sg+U/sE2C3XZR1KG/rP
O7AxmjVuyIsG0wCR8pQIZUyxNAYAeoni8McDWc/V1uinMrPmmECGxc0nEovMe863ETxiYAcjPitA
bpSACW22s293bzUIUPsCh8U+iQIDAQABo0IwQDAdBgNVHQ4EFgQUW/hNT7KlhtQ60vFjmqC+CfZX
t94wDgYDVR0PAQH/BAQDAgEGMA8GA1UdEwEB/wQFMAMBAf8wDQYJKoZIhvcNAQEFBQADggEBAKCh
OBZmLqdWHyGcBvod7bkixTgm2E5P7KN/ed5GIaGHd48HCJqypMWvDzKYC3xmKbabfSVSSUOrTC4r
bnpwrxYO4wJs+0LmGJ1F2FXI6Dvd5+H0LgscNFxsWEr7jIhQX5Ucv+2rIrVls4W6ng+4reV6G4pQ
Oh29Dbx7VFALuUKvVaAYga1lme++5Jy/xIWrQbJUb9wlze144o4MjQlJ3WN7WmmWAiGovVJZ6X01
y8hSyn+B/tlr0/cR7SXf+Of5pPpyl4RTDaXQMhhRdlkUbA/r7F+AjHVDg8OFmP9Mni0N5HeDk061
lgeLKBObjBmNQSdJQO7e5iNEOdyhIta6A/I=
-----END CERTIFICATE-----

ACEDICOM Root
=============
-----BEGIN CERTIFICATE-----
MIIFtTCCA52gAwIBAgIIYY3HhjsBggUwDQYJKoZIhvcNAQEFBQAwRDEWMBQGA1UEAwwNQUNFRElD
T00gUm9vdDEMMAoGA1UECwwDUEtJMQ8wDQYDVQQKDAZFRElDT00xCzAJBgNVBAYTAkVTMB4XDTA4
MDQxODE2MjQyMloXDTI4MDQxMzE2MjQyMlowRDEWMBQGA1UEAwwNQUNFRElDT00gUm9vdDEMMAoG
A1UECwwDUEtJMQ8wDQYDVQQKDAZFRElDT00xCzAJBgNVBAYTAkVTMIICIjANBgkqhkiG9w0BAQEF
AAOCAg8AMIICCgKCAgEA/5KV4WgGdrQsyFhIyv2AVClVYyT/kGWbEHV7w2rbYgIB8hiGtXxaOLHk
WLn709gtn70yN78sFW2+tfQh0hOR2QetAQXW8713zl9CgQr5auODAKgrLlUTY4HKRxx7XBZXehuD
YAQ6PmXDzQHe3qTWDLqO3tkE7hdWIpuPY/1NFgu3e3eM+SW10W2ZEi5PGrjm6gSSrj0RuVFCPYew
MYWveVqc/udOXpJPQ/yrOq2lEiZmueIM15jO1FillUAKt0SdE3QrwqXrIhWYENiLxQSfHY9g5QYb
m8+5eaA9oiM/Qj9r+hwDezCNzmzAv+YbX79nuIQZ1RXve8uQNjFiybwCq0Zfm/4aaJQ0PZCOrfbk
HQl/Sog4P75n/TSW9R28MHTLOO7VbKvU/PQAtwBbhTIWdjPp2KOZnQUAqhbm84F9b32qhm2tFXTT
xKJxqvQUfecyuB+81fFOvW8XAjnXDpVCOscAPukmYxHqC9FK/xidstd7LzrZlvvoHpKuE1XI2Sf2
3EgbsCTBheN3nZqk8wwRHQ3ItBTutYJXCb8gWH8vIiPYcMt5bMlL8qkqyPyHK9caUPgn6C9D4zq9
2Fdx/c6mUlv53U3t5fZvie27k5x2IXXwkkwp9y+cAS7+UEaeZAwUswdbxcJzbPEHXEUkFDWug/Fq
TYl6+rPYLWbwNof1K1MCAwEAAaOBqjCBpzAPBgNVHRMBAf8EBTADAQH/MB8GA1UdIwQYMBaAFKaz
4SsrSbbXc6GqlPUB53NlTKxQMA4GA1UdDwEB/wQEAwIBhjAdBgNVHQ4EFgQUprPhKytJttdzoaqU
9QHnc2VMrFAwRAYDVR0gBD0wOzA5BgRVHSAAMDEwLwYIKwYBBQUHAgEWI2h0dHA6Ly9hY2VkaWNv
bS5lZGljb21ncm91cC5jb20vZG9jMA0GCSqGSIb3DQEBBQUAA4ICAQDOLAtSUWImfQwng4/F9tqg
aHtPkl7qpHMyEVNEskTLnewPeUKzEKbHDZ3Ltvo/Onzqv4hTGzz3gvoFNTPhNahXwOf9jU8/kzJP
eGYDdwdY6ZXIfj7QeQCM8htRM5u8lOk6e25SLTKeI6RF+7YuE7CLGLHdztUdp0J/Vb77W7tH1Pwk
zQSulgUV1qzOMPPKC8W64iLgpq0i5ALudBF/TP94HTXa5gI06xgSYXcGCRZj6hitoocf8seACQl1
ThCojz2GuHURwCRiipZ7SkXp7FnFvmuD5uHorLUwHv4FB4D54SMNUI8FmP8sX+g7tq3PgbUhh8oI
KiMnMCArz+2UW6yyetLHKKGKC5tNSixthT8Jcjxn4tncB7rrZXtaAWPWkFtPF2Y9fwsZo5NjEFIq
nxQWWOLcpfShFosOkYuByptZ+thrkQdlVV9SH686+5DdaaVbnG0OLLb6zqylfDJKZ0DcMDQj3dcE
I2bw/FWAp/tmGYI1Z2JwOV5vx+qQQEQIHriy1tvuWacNGHk0vFQYXlPKNFHtRQrmjseCNj6nOGOp
MCwXEGCSn1WHElkQwg9naRHMTh5+Spqtr0CodaxWkHS4oJyleW/c6RrIaQXpuvoDs3zk4E7Czp3o
tkYNbn5XOmeUwssfnHdKZ05phkOTOPu220+DkdRgfks+KzgHVZhepA==
-----END CERTIFICATE-----

Microsec e-Szigno Root CA 2009
==============================
-----BEGIN CERTIFICATE-----
MIIECjCCAvKgAwIBAgIJAMJ+QwRORz8ZMA0GCSqGSIb3DQEBCwUAMIGCMQswCQYDVQQGEwJIVTER
MA8GA1UEBwwIQnVkYXBlc3QxFjAUBgNVBAoMDU1pY3Jvc2VjIEx0ZC4xJzAlBgNVBAMMHk1pY3Jv
c2VjIGUtU3ppZ25vIFJvb3QgQ0EgMjAwOTEfMB0GCSqGSIb3DQEJARYQaW5mb0BlLXN6aWduby5o
dTAeFw0wOTA2MTYxMTMwMThaFw0yOTEyMzAxMTMwMThaMIGCMQswCQYDVQQGEwJIVTERMA8GA1UE
BwwIQnVkYXBlc3QxFjAUBgNVBAoMDU1pY3Jvc2VjIEx0ZC4xJzAlBgNVBAMMHk1pY3Jvc2VjIGUt
U3ppZ25vIFJvb3QgQ0EgMjAwOTEfMB0GCSqGSIb3DQEJARYQaW5mb0BlLXN6aWduby5odTCCASIw
DQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAOn4j/NjrdqG2KfgQvvPkd6mJviZpWNwrZuuyjNA
fW2WbqEORO7hE52UQlKavXWFdCyoDh2Tthi3jCyoz/tccbna7P7ofo/kLx2yqHWH2Leh5TvPmUpG
0IMZfcChEhyVbUr02MelTTMuhTlAdX4UfIASmFDHQWe4oIBhVKZsTh/gnQ4H6cm6M+f+wFUoLAKA
pxn1ntxVUwOXewdI/5n7N4okxFnMUBBjjqqpGrCEGob5X7uxUG6k0QrM1XF+H6cbfPVTbiJfyyvm
1HxdrtbCxkzlBQHZ7Vf8wSN5/PrIJIOV87VqUQHQd9bpEqH5GoP7ghu5sJf0dgYzQ0mg/wu1+rUC
AwEAAaOBgDB+MA8GA1UdEwEB/wQFMAMBAf8wDgYDVR0PAQH/BAQDAgEGMB0GA1UdDgQWBBTLD8bf
QkPMPcu1SCOhGnqmKrs0aDAfBgNVHSMEGDAWgBTLD8bfQkPMPcu1SCOhGnqmKrs0aDAbBgNVHREE
FDASgRBpbmZvQGUtc3ppZ25vLmh1MA0GCSqGSIb3DQEBCwUAA4IBAQDJ0Q5eLtXMs3w+y/w9/w0o
lZMEyL/azXm4Q5DwpL7v8u8hmLzU1F0G9u5C7DBsoKqpyvGvivo/C3NqPuouQH4frlRheesuCDfX
I/OMn74dseGkddug4lQUsbocKaQY9hK6ohQU4zE1yED/t+AFdlfBHFny+L/k7SViXITwfn4fs775
tyERzAMBVnCnEJIeGzSBHq2cGsMEPO0CYdYeBvNfOofyK/FFh+U9rNHHV4S9a67c2Pm2G2JwCz02
yULyMtd6YebS2z3PyKnJm9zbWETXbzivf3jTo60adbocwTZ8jx5tHMN1Rq41Bab2XD0h7lbwyYIi
LXpUq3DDfSJlgnCW
-----END CERTIFICATE-----

GlobalSign Root CA - R3
=======================
-----BEGIN CERTIFICATE-----
MIIDXzCCAkegAwIBAgILBAAAAAABIVhTCKIwDQYJKoZIhvcNAQELBQAwTDEgMB4GA1UECxMXR2xv
YmFsU2lnbiBSb290IENBIC0gUjMxEzARBgNVBAoTCkdsb2JhbFNpZ24xEzARBgNVBAMTCkdsb2Jh
bFNpZ24wHhcNMDkwMzE4MTAwMDAwWhcNMjkwMzE4MTAwMDAwWjBMMSAwHgYDVQQLExdHbG9iYWxT
aWduIFJvb3QgQ0EgLSBSMzETMBEGA1UEChMKR2xvYmFsU2lnbjETMBEGA1UEAxMKR2xvYmFsU2ln
bjCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAMwldpB5BngiFvXAg7aEyiie/QV2EcWt
iHL8RgJDx7KKnQRfJMsuS+FggkbhUqsMgUdwbN1k0ev1LKMPgj0MK66X17YUhhB5uzsTgHeMCOFJ
0mpiLx9e+pZo34knlTifBtc+ycsmWQ1z3rDI6SYOgxXG71uL0gRgykmmKPZpO/bLyCiR5Z2KYVc3
rHQU3HTgOu5yLy6c+9C7v/U9AOEGM+iCK65TpjoWc4zdQQ4gOsC0p6Hpsk+QLjJg6VfLuQSSaGjl
OCZgdbKfd/+RFO+uIEn8rUAVSNECMWEZXriX7613t2Saer9fwRPvm2L7DWzgVGkWqQPabumDk3F2
xmmFghcCAwEAAaNCMEAwDgYDVR0PAQH/BAQDAgEGMA8GA1UdEwEB/wQFMAMBAf8wHQYDVR0OBBYE
FI/wS3+oLkUkrk1Q+mOai97i3Ru8MA0GCSqGSIb3DQEBCwUAA4IBAQBLQNvAUKr+yAzv95ZURUm7
lgAJQayzE4aGKAczymvmdLm6AC2upArT9fHxD4q/c2dKg8dEe3jgr25sbwMpjjM5RcOO5LlXbKr8
EpbsU8Yt5CRsuZRj+9xTaGdWPoO4zzUhw8lo/s7awlOqzJCK6fBdRoyV3XpYKBovHd7NADdBj+1E
bddTKJd+82cEHhXXipa0095MJ6RMG3NzdvQXmcIfeg7jLQitChws/zyrVQ4PkX4268NXSb7hLi18
YIvDQVETI53O9zJrlAGomecsMx86OyXShkDOOyyGeMlhLxS67ttVb9+E7gUJTb0o2HLO02JQZR7r
kpeDMdmztcpHWD9f
-----END CERTIFICATE-----

Autoridad de Certificacion Firmaprofesional CIF A62634068
=========================================================
-----BEGIN CERTIFICATE-----
MIIGFDCCA/ygAwIBAgIIU+w77vuySF8wDQYJKoZIhvcNAQEFBQAwUTELMAkGA1UEBhMCRVMxQjBA
BgNVBAMMOUF1dG9yaWRhZCBkZSBDZXJ0aWZpY2FjaW9uIEZpcm1hcHJvZmVzaW9uYWwgQ0lGIEE2
MjYzNDA2ODAeFw0wOTA1MjAwODM4MTVaFw0zMDEyMzEwODM4MTVaMFExCzAJBgNVBAYTAkVTMUIw
QAYDVQQDDDlBdXRvcmlkYWQgZGUgQ2VydGlmaWNhY2lvbiBGaXJtYXByb2Zlc2lvbmFsIENJRiBB
NjI2MzQwNjgwggIiMA0GCSqGSIb3DQEBAQUAA4ICDwAwggIKAoICAQDKlmuO6vj78aI14H9M2uDD
Utd9thDIAl6zQyrET2qyyhxdKJp4ERppWVevtSBC5IsP5t9bpgOSL/UR5GLXMnE42QQMcas9UX4P
B99jBVzpv5RvwSmCwLTaUbDBPLutN0pcyvFLNg4kq7/DhHf9qFD0sefGL9ItWY16Ck6WaVICqjaY
7Pz6FIMMNx/Jkjd/14Et5cS54D40/mf0PmbR0/RAz15iNA9wBj4gGFrO93IbJWyTdBSTo3OxDqqH
ECNZXyAFGUftaI6SEspd/NYrspI8IM/hX68gvqB2f3bl7BqGYTM+53u0P6APjqK5am+5hyZvQWyI
plD9amML9ZMWGxmPsu2bm8mQ9QEM3xk9Dz44I8kvjwzRAv4bVdZO0I08r0+k8/6vKtMFnXkIoctX
MbScyJCyZ/QYFpM6/EfY0XiWMR+6KwxfXZmtY4laJCB22N/9q06mIqqdXuYnin1oKaPnirjaEbsX
LZmdEyRG98Xi2J+Of8ePdG1asuhy9azuJBCtLxTa/y2aRnFHvkLfuwHb9H/TKI8xWVvTyQKmtFLK
bpf7Q8UIJm+K9Lv9nyiqDdVF8xM6HdjAeI9BZzwelGSuewvF6NkBiDkal4ZkQdU7hwxu+g/GvUgU
vzlN1J5Bto+WHWOWk9mVBngxaJ43BjuAiUVhOSPHG0SjFeUc+JIwuwIDAQABo4HvMIHsMBIGA1Ud
EwEB/wQIMAYBAf8CAQEwDgYDVR0PAQH/BAQDAgEGMB0GA1UdDgQWBBRlzeurNR4APn7VdMActHNH
DhpkLzCBpgYDVR0gBIGeMIGbMIGYBgRVHSAAMIGPMC8GCCsGAQUFBwIBFiNodHRwOi8vd3d3LmZp
cm1hcHJvZmVzaW9uYWwuY29tL2NwczBcBggrBgEFBQcCAjBQHk4AUABhAHMAZQBvACAAZABlACAA
bABhACAAQgBvAG4AYQBuAG8AdgBhACAANAA3ACAAQgBhAHIAYwBlAGwAbwBuAGEAIAAwADgAMAAx
ADcwDQYJKoZIhvcNAQEFBQADggIBABd9oPm03cXF661LJLWhAqvdpYhKsg9VSytXjDvlMd3+xDLx
51tkljYyGOylMnfX40S2wBEqgLk9am58m9Ot/MPWo+ZkKXzR4Tgegiv/J2Wv+xYVxC5xhOW1//qk
R71kMrv2JYSiJ0L1ILDCExARzRAVukKQKtJE4ZYm6zFIEv0q2skGz3QeqUvVhyj5eTSSPi5E6PaP
T481PyWzOdxjKpBrIF/EUhJOlywqrJ2X3kjyo2bbwtKDlaZmp54lD+kLM5FlClrD2VQS3a/DTg4f
Jl4N3LON7NWBcN7STyQF82xO9UxJZo3R/9ILJUFI/lGExkKvgATP0H5kSeTy36LssUzAKh3ntLFl
osS88Zj0qnAHY7S42jtM+kAiMFsRpvAFDsYCA0irhpuF3dvd6qJ2gHN99ZwExEWN57kci57q13XR
crHedUTnQn3iV2t93Jm8PYMo6oCTjcVMZcFwgbg4/EMxsvYDNEeyrPsiBsse3RdHHF9mudMaotoR
saS8I8nkvof/uZS2+F0gStRf571oe2XyFR7SOqkt6dhrJKyXWERHrVkY8SFlcN7ONGCoQPHzPKTD
KCOM/iczQ0CgFzzr6juwcqajuUpLXhZI9LK8yIySxZ2frHI2vDSANGupi5LAuBft7HZT9SQBjLMi
6Et8Vcad+qMUu2WFbm5PEn4KPJ2V
-----END CERTIFICATE-----

Izenpe.com
==========
-----BEGIN CERTIFICATE-----
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
-----END CERTIFICATE-----

Chambers of Commerce Root - 2008
================================
-----BEGIN CERTIFICATE-----
MIIHTzCCBTegAwIBAgIJAKPaQn6ksa7aMA0GCSqGSIb3DQEBBQUAMIGuMQswCQYDVQQGEwJFVTFD
MEEGA1UEBxM6TWFkcmlkIChzZWUgY3VycmVudCBhZGRyZXNzIGF0IHd3dy5jYW1lcmZpcm1hLmNv
bS9hZGRyZXNzKTESMBAGA1UEBRMJQTgyNzQzMjg3MRswGQYDVQQKExJBQyBDYW1lcmZpcm1hIFMu
QS4xKTAnBgNVBAMTIENoYW1iZXJzIG9mIENvbW1lcmNlIFJvb3QgLSAyMDA4MB4XDTA4MDgwMTEy
Mjk1MFoXDTM4MDczMTEyMjk1MFowga4xCzAJBgNVBAYTAkVVMUMwQQYDVQQHEzpNYWRyaWQgKHNl
ZSBjdXJyZW50IGFkZHJlc3MgYXQgd3d3LmNhbWVyZmlybWEuY29tL2FkZHJlc3MpMRIwEAYDVQQF
EwlBODI3NDMyODcxGzAZBgNVBAoTEkFDIENhbWVyZmlybWEgUy5BLjEpMCcGA1UEAxMgQ2hhbWJl
cnMgb2YgQ29tbWVyY2UgUm9vdCAtIDIwMDgwggIiMA0GCSqGSIb3DQEBAQUAA4ICDwAwggIKAoIC
AQCvAMtwNyuAWko6bHiUfaN/Gh/2NdW928sNRHI+JrKQUrpjOyhYb6WzbZSm891kDFX29ufyIiKA
XuFixrYp4YFs8r/lfTJqVKAyGVn+H4vXPWCGhSRv4xGzdz4gljUha7MI2XAuZPeEklPWDrCQiorj
h40G072QDuKZoRuGDtqaCrsLYVAGUvGef3bsyw/QHg3PmTA9HMRFEFis1tPo1+XqxQEHd9ZR5gN/
ikilTWh1uem8nk4ZcfUyS5xtYBkL+8ydddy/Js2Pk3g5eXNeJQ7KXOt3EgfLZEFHcpOrUMPrCXZk
NNI5t3YRCQ12RcSprj1qr7V9ZS+UWBDsXHyvfuK2GNnQm05aSd+pZgvMPMZ4fKecHePOjlO+Bd5g
D2vlGts/4+EhySnB8esHnFIbAURRPHsl18TlUlRdJQfKFiC4reRB7noI/plvg6aRArBsNlVq5331
lubKgdaX8ZSD6e2wsWsSaR6s+12pxZjptFtYer49okQ6Y1nUCyXeG0+95QGezdIp1Z8XGQpvvwyQ
0wlf2eOKNcx5Wk0ZN5K3xMGtr/R5JJqyAQuxr1yW84Ay+1w9mPGgP0revq+ULtlVmhduYJ1jbLhj
ya6BXBg14JC7vjxPNyK5fuvPnnchpj04gftI2jE9K+OJ9dC1vX7gUMQSibMjmhAxhduub+84Mxh2
EQIDAQABo4IBbDCCAWgwEgYDVR0TAQH/BAgwBgEB/wIBDDAdBgNVHQ4EFgQU+SSsD7K1+HnA+mCI
G8TZTQKeFxkwgeMGA1UdIwSB2zCB2IAU+SSsD7K1+HnA+mCIG8TZTQKeFxmhgbSkgbEwga4xCzAJ
BgNVBAYTAkVVMUMwQQYDVQQHEzpNYWRyaWQgKHNlZSBjdXJyZW50IGFkZHJlc3MgYXQgd3d3LmNh
bWVyZmlybWEuY29tL2FkZHJlc3MpMRIwEAYDVQQFEwlBODI3NDMyODcxGzAZBgNVBAoTEkFDIENh
bWVyZmlybWEgUy5BLjEpMCcGA1UEAxMgQ2hhbWJlcnMgb2YgQ29tbWVyY2UgUm9vdCAtIDIwMDiC
CQCj2kJ+pLGu2jAOBgNVHQ8BAf8EBAMCAQYwPQYDVR0gBDYwNDAyBgRVHSAAMCowKAYIKwYBBQUH
AgEWHGh0dHA6Ly9wb2xpY3kuY2FtZXJmaXJtYS5jb20wDQYJKoZIhvcNAQEFBQADggIBAJASryI1
wqM58C7e6bXpeHxIvj99RZJe6dqxGfwWPJ+0W2aeaufDuV2I6A+tzyMP3iU6XsxPpcG1Lawk0lgH
3qLPaYRgM+gQDROpI9CF5Y57pp49chNyM/WqfcZjHwj0/gF/JM8rLFQJ3uIrbZLGOU8W6jx+ekbU
RWpGqOt1glanq6B8aBMz9p0w8G8nOSQjKpD9kCk18pPfNKXG9/jvjA9iSnyu0/VU+I22mlaHFoI6
M6taIgj3grrqLuBHmrS1RaMFO9ncLkVAO+rcf+g769HsJtg1pDDFOqxXnrN2pSB7+R5KBWIBpih1
YJeSDW4+TTdDDZIVnBgizVGZoCkaPF+KMjNbMMeJL0eYD6MDxvbxrN8y8NmBGuScvfaAFPDRLLmF
9dijscilIeUcE5fuDr3fKanvNFNb0+RqE4QGtjICxFKuItLcsiFCGtpA8CnJ7AoMXOLQusxI0zcK
zBIKinmwPQN/aUv0NCB9szTqjktk9T79syNnFQ0EuPAtwQlRPLJsFfClI9eDdOTlLsn+mCdCxqvG
nrDQWzilm1DefhiYtUU79nm06PcaewaD+9CL2rvHvRirCG88gGtAPxkZumWK5r7VXNM21+9AUiRg
OGcEMeyP84LG3rlV8zsxkVrctQgVrXYlCg17LofiDKYGvCYQbTed7N14jHyAxfDZd0jQ
-----END CERTIFICATE-----

Global Chambersign Root - 2008
==============================
-----BEGIN CERTIFICATE-----
MIIHSTCCBTGgAwIBAgIJAMnN0+nVfSPOMA0GCSqGSIb3DQEBBQUAMIGsMQswCQYDVQQGEwJFVTFD
MEEGA1UEBxM6TWFkcmlkIChzZWUgY3VycmVudCBhZGRyZXNzIGF0IHd3dy5jYW1lcmZpcm1hLmNv
bS9hZGRyZXNzKTESMBAGA1UEBRMJQTgyNzQzMjg3MRswGQYDVQQKExJBQyBDYW1lcmZpcm1hIFMu
QS4xJzAlBgNVBAMTHkdsb2JhbCBDaGFtYmVyc2lnbiBSb290IC0gMjAwODAeFw0wODA4MDExMjMx
NDBaFw0zODA3MzExMjMxNDBaMIGsMQswCQYDVQQGEwJFVTFDMEEGA1UEBxM6TWFkcmlkIChzZWUg
Y3VycmVudCBhZGRyZXNzIGF0IHd3dy5jYW1lcmZpcm1hLmNvbS9hZGRyZXNzKTESMBAGA1UEBRMJ
QTgyNzQzMjg3MRswGQYDVQQKExJBQyBDYW1lcmZpcm1hIFMuQS4xJzAlBgNVBAMTHkdsb2JhbCBD
aGFtYmVyc2lnbiBSb290IC0gMjAwODCCAiIwDQYJKoZIhvcNAQEBBQADggIPADCCAgoCggIBAMDf
VtPkOpt2RbQT2//BthmLN0EYlVJH6xedKYiONWwGMi5HYvNJBL99RDaxccy9Wglz1dmFRP+RVyXf
XjaOcNFccUMd2drvXNL7G706tcuto8xEpw2uIRU/uXpbknXYpBI4iRmKt4DS4jJvVpyR1ogQC7N0
ZJJ0YPP2zxhPYLIj0Mc7zmFLmY/CDNBAspjcDahOo7kKrmCgrUVSY7pmvWjg+b4aqIG7HkF4ddPB
/gBVsIdU6CeQNR1MM62X/JcumIS/LMmjv9GYERTtY/jKmIhYF5ntRQOXfjyGHoiMvvKRhI9lNNgA
TH23MRdaKXoKGCQwoze1eqkBfSbW+Q6OWfH9GzO1KTsXO0G2Id3UwD2ln58fQ1DJu7xsepeY7s2M
H/ucUa6LcL0nn3HAa6x9kGbo1106DbDVwo3VyJ2dwW3Q0L9R5OP4wzg2rtandeavhENdk5IMagfe
Ox2YItaswTXbo6Al/3K1dh3ebeksZixShNBFks4c5eUzHdwHU1SjqoI7mjcv3N2gZOnm3b2u/GSF
HTynyQbehP9r6GsaPMWis0L7iwk+XwhSx2LE1AVxv8Rk5Pihg+g+EpuoHtQ2TS9x9o0o9oOpE9Jh
wZG7SMA0j0GMS0zbaRL/UJScIINZc+18ofLx/d33SdNDWKBWY8o9PeU1VlnpDsogzCtLkykPAgMB
AAGjggFqMIIBZjASBgNVHRMBAf8ECDAGAQH/AgEMMB0GA1UdDgQWBBS5CcqcHtvTbDprru1U8VuT
BjUuXjCB4QYDVR0jBIHZMIHWgBS5CcqcHtvTbDprru1U8VuTBjUuXqGBsqSBrzCBrDELMAkGA1UE
BhMCRVUxQzBBBgNVBAcTOk1hZHJpZCAoc2VlIGN1cnJlbnQgYWRkcmVzcyBhdCB3d3cuY2FtZXJm
aXJtYS5jb20vYWRkcmVzcykxEjAQBgNVBAUTCUE4Mjc0MzI4NzEbMBkGA1UEChMSQUMgQ2FtZXJm
aXJtYSBTLkEuMScwJQYDVQQDEx5HbG9iYWwgQ2hhbWJlcnNpZ24gUm9vdCAtIDIwMDiCCQDJzdPp
1X0jzjAOBgNVHQ8BAf8EBAMCAQYwPQYDVR0gBDYwNDAyBgRVHSAAMCowKAYIKwYBBQUHAgEWHGh0
dHA6Ly9wb2xpY3kuY2FtZXJmaXJtYS5jb20wDQYJKoZIhvcNAQEFBQADggIBAICIf3DekijZBZRG
/5BXqfEv3xoNa/p8DhxJJHkn2EaqbylZUohwEurdPfWbU1Rv4WCiqAm57OtZfMY18dwY6fFn5a+6
ReAJ3spED8IXDneRRXozX1+WLGiLwUePmJs9wOzL9dWCkoQ10b42OFZyMVtHLaoXpGNR6woBrX/s
dZ7LoR/xfxKxueRkf2fWIyr0uDldmOghp+G9PUIadJpwr2hsUF1Jz//7Dl3mLEfXgTpZALVza2Mg
9jFFCDkO9HB+QHBaP9BrQql0PSgvAm11cpUJjUhjxsYjV5KTXjXBjfkK9yydYhz2rXzdpjEetrHH
foUm+qRqtdpjMNHvkzeyZi99Bffnt0uYlDXA2TopwZ2yUDMdSqlapskD7+3056huirRXhOukP9Du
qqqHW2Pok+JrqNS4cnhrG+055F3Lm6qH1U9OAP7Zap88MQ8oAgF9mOinsKJknnn4SPIVqczmyETr
P3iZ8ntxPjzxmKfFGBI/5rsoM0LpRQp8bfKGeS/Fghl9CYl8slR2iK7ewfPM4W7bMdaTrpmg7yVq
c5iJWzouE4gev8CSlDQb4ye3ix5vQv/n6TebUB0tovkC7stYWDpxvGjjqsGvHCgfotwjZT+B6q6Z
09gwzxMNTxXJhLynSC34MCN32EZLeW32jO06f2ARePTpm67VVMB0gNELQp/B
-----END CERTIFICATE-----

Go Daddy Root Certificate Authority - G2
========================================
-----BEGIN CERTIFICATE-----
MIIDxTCCAq2gAwIBAgIBADANBgkqhkiG9w0BAQsFADCBgzELMAkGA1UEBhMCVVMxEDAOBgNVBAgT
B0FyaXpvbmExEzARBgNVBAcTClNjb3R0c2RhbGUxGjAYBgNVBAoTEUdvRGFkZHkuY29tLCBJbmMu
MTEwLwYDVQQDEyhHbyBEYWRkeSBSb290IENlcnRpZmljYXRlIEF1dGhvcml0eSAtIEcyMB4XDTA5
MDkwMTAwMDAwMFoXDTM3MTIzMTIzNTk1OVowgYMxCzAJBgNVBAYTAlVTMRAwDgYDVQQIEwdBcml6
b25hMRMwEQYDVQQHEwpTY290dHNkYWxlMRowGAYDVQQKExFHb0RhZGR5LmNvbSwgSW5jLjExMC8G
A1UEAxMoR28gRGFkZHkgUm9vdCBDZXJ0aWZpY2F0ZSBBdXRob3JpdHkgLSBHMjCCASIwDQYJKoZI
hvcNAQEBBQADggEPADCCAQoCggEBAL9xYgjx+lk09xvJGKP3gElY6SKDE6bFIEMBO4Tx5oVJnyfq
9oQbTqC023CYxzIBsQU+B07u9PpPL1kwIuerGVZr4oAH/PMWdYA5UXvl+TW2dE6pjYIT5LY/qQOD
+qK+ihVqf94Lw7YZFAXK6sOoBJQ7RnwyDfMAZiLIjWltNowRGLfTshxgtDj6AozO091GB94KPutd
fMh8+7ArU6SSYmlRJQVhGkSBjCypQ5Yj36w6gZoOKcUcqeldHraenjAKOc7xiID7S13MMuyFYkMl
NAJWJwGRtDtwKj9useiciAF9n9T521NtYJ2/LOdYq7hfRvzOxBsDPAnrSTFcaUaz4EcCAwEAAaNC
MEAwDwYDVR0TAQH/BAUwAwEB/zAOBgNVHQ8BAf8EBAMCAQYwHQYDVR0OBBYEFDqahQcQZyi27/a9
BUFuIMGU2g/eMA0GCSqGSIb3DQEBCwUAA4IBAQCZ21151fmXWWcDYfF+OwYxdS2hII5PZYe096ac
vNjpL9DbWu7PdIxztDhC2gV7+AJ1uP2lsdeu9tfeE8tTEH6KRtGX+rcuKxGrkLAngPnon1rpN5+r
5N9ss4UXnT3ZJE95kTXWXwTrgIOrmgIttRD02JDHBHNA7XIloKmf7J6raBKZV8aPEjoJpL1E/QYV
N8Gb5DKj7Tjo2GTzLH4U/ALqn83/B2gX2yKQOC16jdFU8WnjXzPKej17CuPKf1855eJ1usV2GDPO
LPAvTK33sefOT6jEm0pUBsV/fdUID+Ic/n4XuKxe9tQWskMJDE32p2u0mYRlynqI4uJEvlz36hz1
-----END CERTIFICATE-----

Starfield Root Certificate Authority - G2
=========================================
-----BEGIN CERTIFICATE-----
MIID3TCCAsWgAwIBAgIBADANBgkqhkiG9w0BAQsFADCBjzELMAkGA1UEBhMCVVMxEDAOBgNVBAgT
B0FyaXpvbmExEzARBgNVBAcTClNjb3R0c2RhbGUxJTAjBgNVBAoTHFN0YXJmaWVsZCBUZWNobm9s
b2dpZXMsIEluYy4xMjAwBgNVBAMTKVN0YXJmaWVsZCBSb290IENlcnRpZmljYXRlIEF1dGhvcml0
eSAtIEcyMB4XDTA5MDkwMTAwMDAwMFoXDTM3MTIzMTIzNTk1OVowgY8xCzAJBgNVBAYTAlVTMRAw
DgYDVQQIEwdBcml6b25hMRMwEQYDVQQHEwpTY290dHNkYWxlMSUwIwYDVQQKExxTdGFyZmllbGQg
VGVjaG5vbG9naWVzLCBJbmMuMTIwMAYDVQQDEylTdGFyZmllbGQgUm9vdCBDZXJ0aWZpY2F0ZSBB
dXRob3JpdHkgLSBHMjCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAL3twQP89o/8ArFv
W59I2Z154qK3A2FWGMNHttfKPTUuiUP3oWmb3ooa/RMgnLRJdzIpVv257IzdIvpy3Cdhl+72WoTs
bhm5iSzchFvVdPtrX8WJpRBSiUZV9Lh1HOZ/5FSuS/hVclcCGfgXcVnrHigHdMWdSL5stPSksPNk
N3mSwOxGXn/hbVNMYq/NHwtjuzqd+/x5AJhhdM8mgkBj87JyahkNmcrUDnXMN/uLicFZ8WJ/X7Nf
ZTD4p7dNdloedl40wOiWVpmKs/B/pM293DIxfJHP4F8R+GuqSVzRmZTRouNjWwl2tVZi4Ut0HZbU
JtQIBFnQmA4O5t78w+wfkPECAwEAAaNCMEAwDwYDVR0TAQH/BAUwAwEB/zAOBgNVHQ8BAf8EBAMC
AQYwHQYDVR0OBBYEFHwMMh+n2TB/xH1oo2Kooc6rB1snMA0GCSqGSIb3DQEBCwUAA4IBAQARWfol
TwNvlJk7mh+ChTnUdgWUXuEok21iXQnCoKjUsHU48TRqneSfioYmUeYs0cYtbpUgSpIB7LiKZ3sx
4mcujJUDJi5DnUox9g61DLu34jd/IroAow57UvtruzvE03lRTs2Q9GcHGcg8RnoNAX3FWOdt5oUw
F5okxBDgBPfg8n/Uqgr/Qh037ZTlZFkSIHc40zI+OIF1lnP6aI+xy84fxez6nH7PfrHxBy22/L/K
pL/QlwVKvOoYKAKQvVR4CSFx09F9HdkWsKlhPdAKACL8x3vLCWRFCztAgfd9fDL1mMpYjn0q7pBZ
c2T5NnReJaH1ZgUufzkVqSr7UIuOhWn0
-----END CERTIFICATE-----

Starfield Services Root Certificate Authority - G2
==================================================
-----BEGIN CERTIFICATE-----
MIID7zCCAtegAwIBAgIBADANBgkqhkiG9w0BAQsFADCBmDELMAkGA1UEBhMCVVMxEDAOBgNVBAgT
B0FyaXpvbmExEzARBgNVBAcTClNjb3R0c2RhbGUxJTAjBgNVBAoTHFN0YXJmaWVsZCBUZWNobm9s
b2dpZXMsIEluYy4xOzA5BgNVBAMTMlN0YXJmaWVsZCBTZXJ2aWNlcyBSb290IENlcnRpZmljYXRl
IEF1dGhvcml0eSAtIEcyMB4XDTA5MDkwMTAwMDAwMFoXDTM3MTIzMTIzNTk1OVowgZgxCzAJBgNV
BAYTAlVTMRAwDgYDVQQIEwdBcml6b25hMRMwEQYDVQQHEwpTY290dHNkYWxlMSUwIwYDVQQKExxT
dGFyZmllbGQgVGVjaG5vbG9naWVzLCBJbmMuMTswOQYDVQQDEzJTdGFyZmllbGQgU2VydmljZXMg
Um9vdCBDZXJ0aWZpY2F0ZSBBdXRob3JpdHkgLSBHMjCCASIwDQYJKoZIhvcNAQEBBQADggEPADCC
AQoCggEBANUMOsQq+U7i9b4Zl1+OiFOxHz/Lz58gE20pOsgPfTz3a3Y4Y9k2YKibXlwAgLIvWX/2
h/klQ4bnaRtSmpDhcePYLQ1Ob/bISdm28xpWriu2dBTrz/sm4xq6HZYuajtYlIlHVv8loJNwU4Pa
hHQUw2eeBGg6345AWh1KTs9DkTvnVtYAcMtS7nt9rjrnvDH5RfbCYM8TWQIrgMw0R9+53pBlbQLP
LJGmpufehRhJfGZOozptqbXuNC66DQO4M99H67FrjSXZm86B0UVGMpZwh94CDklDhbZsc7tk6mFB
rMnUVN+HL8cisibMn1lUaJ/8viovxFUcdUBgF4UCVTmLfwUCAwEAAaNCMEAwDwYDVR0TAQH/BAUw
AwEB/zAOBgNVHQ8BAf8EBAMCAQYwHQYDVR0OBBYEFJxfAN+qAdcwKziIorhtSpzyEZGDMA0GCSqG
SIb3DQEBCwUAA4IBAQBLNqaEd2ndOxmfZyMIbw5hyf2E3F/YNoHN2BtBLZ9g3ccaaNnRbobhiCPP
E95Dz+I0swSdHynVv/heyNXBve6SbzJ08pGCL72CQnqtKrcgfU28elUSwhXqvfdqlS5sdJ/PHLTy
xQGjhdByPq1zqwubdQxtRbeOlKyWN7Wg0I8VRw7j6IPdj/3vQQF3zCepYoUz8jcI73HPdwbeyBkd
iEDPfUYd/x7H4c7/I9vG+o1VTqkC50cRRj70/b17KSa7qWFiNyi2LSr2EIZkyXCn0q23KXB56jza
YyWf/Wi3MOxw+3WKt21gZ7IeyLnp2KhvAotnDU0mV3HaIPzBSlCNsSi6
-----END CERTIFICATE-----

AffirmTrust Commercial
======================
-----BEGIN CERTIFICATE-----
MIIDTDCCAjSgAwIBAgIId3cGJyapsXwwDQYJKoZIhvcNAQELBQAwRDELMAkGA1UEBhMCVVMxFDAS
BgNVBAoMC0FmZmlybVRydXN0MR8wHQYDVQQDDBZBZmZpcm1UcnVzdCBDb21tZXJjaWFsMB4XDTEw
MDEyOTE0MDYwNloXDTMwMTIzMTE0MDYwNlowRDELMAkGA1UEBhMCVVMxFDASBgNVBAoMC0FmZmly
bVRydXN0MR8wHQYDVQQDDBZBZmZpcm1UcnVzdCBDb21tZXJjaWFsMIIBIjANBgkqhkiG9w0BAQEF
AAOCAQ8AMIIBCgKCAQEA9htPZwcroRX1BiLLHwGy43NFBkRJLLtJJRTWzsO3qyxPxkEylFf6Eqdb
DuKPHx6GGaeqtS25Xw2Kwq+FNXkyLbscYjfysVtKPcrNcV/pQr6U6Mje+SJIZMblq8Yrba0F8PrV
C8+a5fBQpIs7R6UjW3p6+DM/uO+Zl+MgwdYoic+U+7lF7eNAFxHUdPALMeIrJmqbTFeurCA+ukV6
BfO9m2kVrn1OIGPENXY6BwLJN/3HR+7o8XYdcxXyl6S1yHp52UKqK39c/s4mT6NmgTWvRLpUHhww
MmWd5jyTXlBOeuM61G7MGvv50jeuJCqrVwMiKA1JdX+3KNp1v47j3A55MQIDAQABo0IwQDAdBgNV
HQ4EFgQUnZPGU4teyq8/nx4P5ZmVvCT2lI8wDwYDVR0TAQH/BAUwAwEB/zAOBgNVHQ8BAf8EBAMC
AQYwDQYJKoZIhvcNAQELBQADggEBAFis9AQOzcAN/wr91LoWXym9e2iZWEnStB03TX8nfUYGXUPG
hi4+c7ImfU+TqbbEKpqrIZcUsd6M06uJFdhrJNTxFq7YpFzUf1GO7RgBsZNjvbz4YYCanrHOQnDi
qX0GJX0nof5v7LMeJNrjS1UaADs1tDvZ110w/YETifLCBivtZ8SOyUOyXGsViQK8YvxO8rUzqrJv
0wqiUOP2O+guRMLbZjipM1ZI8W0bM40NjD9gN53Tym1+NH4Nn3J2ixufcv1SNUFFApYvHLKac0kh
sUlHRUe072o0EclNmsxZt9YCnlpOZbWUrhvfKbAW8b8Angc6F2S1BLUjIZkKlTuXfO8=
-----END CERTIFICATE-----

AffirmTrust Networking
======================
-----BEGIN CERTIFICATE-----
MIIDTDCCAjSgAwIBAgIIfE8EORzUmS0wDQYJKoZIhvcNAQEFBQAwRDELMAkGA1UEBhMCVVMxFDAS
BgNVBAoMC0FmZmlybVRydXN0MR8wHQYDVQQDDBZBZmZpcm1UcnVzdCBOZXR3b3JraW5nMB4XDTEw
MDEyOTE0MDgyNFoXDTMwMTIzMTE0MDgyNFowRDELMAkGA1UEBhMCVVMxFDASBgNVBAoMC0FmZmly
bVRydXN0MR8wHQYDVQQDDBZBZmZpcm1UcnVzdCBOZXR3b3JraW5nMIIBIjANBgkqhkiG9w0BAQEF
AAOCAQ8AMIIBCgKCAQEAtITMMxcua5Rsa2FSoOujz3mUTOWUgJnLVWREZY9nZOIG41w3SfYvm4SE
Hi3yYJ0wTsyEheIszx6e/jarM3c1RNg1lho9Nuh6DtjVR6FqaYvZ/Ls6rnla1fTWcbuakCNrmreI
dIcMHl+5ni36q1Mr3Lt2PpNMCAiMHqIjHNRqrSK6mQEubWXLviRmVSRLQESxG9fhwoXA3hA/Pe24
/PHxI1Pcv2WXb9n5QHGNfb2V1M6+oF4nI979ptAmDgAp6zxG8D1gvz9Q0twmQVGeFDdCBKNwV6gb
h+0t+nvujArjqWaJGctB+d1ENmHP4ndGyH329JKBNv3bNPFyfvMMFr20FQIDAQABo0IwQDAdBgNV
HQ4EFgQUBx/S55zawm6iQLSwelAQUHTEyL0wDwYDVR0TAQH/BAUwAwEB/zAOBgNVHQ8BAf8EBAMC
AQYwDQYJKoZIhvcNAQEFBQADggEBAIlXshZ6qML91tmbmzTCnLQyFE2npN/svqe++EPbkTfOtDIu
UFUaNU52Q3Eg75N3ThVwLofDwR1t3Mu1J9QsVtFSUzpE0nPIxBsFZVpikpzuQY0x2+c06lkh1QF6
12S4ZDnNye2v7UsDSKegmQGA3GWjNq5lWUhPgkvIZfFXHeVZLgo/bNjR9eUJtGxUAArgFU2HdW23
WJZa3W3SAKD0m0i+wzekujbgfIeFlxoVot4uolu9rxj5kFDNcFn4J2dHy8egBzp90SxdbBk6ZrV9
/ZFvgrG+CJPbFEfxojfHRZ48x3evZKiT3/Zpg4Jg8klCNO1aAFSFHBY2kgxc+qatv9s=
-----END CERTIFICATE-----

AffirmTrust Premium
===================
-----BEGIN CERTIFICATE-----
MIIFRjCCAy6gAwIBAgIIbYwURrGmCu4wDQYJKoZIhvcNAQEMBQAwQTELMAkGA1UEBhMCVVMxFDAS
BgNVBAoMC0FmZmlybVRydXN0MRwwGgYDVQQDDBNBZmZpcm1UcnVzdCBQcmVtaXVtMB4XDTEwMDEy
OTE0MTAzNloXDTQwMTIzMTE0MTAzNlowQTELMAkGA1UEBhMCVVMxFDASBgNVBAoMC0FmZmlybVRy
dXN0MRwwGgYDVQQDDBNBZmZpcm1UcnVzdCBQcmVtaXVtMIICIjANBgkqhkiG9w0BAQEFAAOCAg8A
MIICCgKCAgEAxBLfqV/+Qd3d9Z+K4/as4Tx4mrzY8H96oDMq3I0gW64tb+eT2TZwamjPjlGjhVtn
BKAQJG9dKILBl1fYSCkTtuG+kU3fhQxTGJoeJKJPj/CihQvL9Cl/0qRY7iZNyaqoe5rZ+jjeRFcV
5fiMyNlI4g0WJx0eyIOFJbe6qlVBzAMiSy2RjYvmia9mx+n/K+k8rNrSs8PhaJyJ+HoAVt70VZVs
+7pk3WKL3wt3MutizCaam7uqYoNMtAZ6MMgpv+0GTZe5HMQxK9VfvFMSF5yZVylmd2EhMQcuJUmd
GPLu8ytxjLW6OQdJd/zvLpKQBY0tL3d770O/Nbua2Plzpyzy0FfuKE4mX4+QaAkvuPjcBukumj5R
p9EixAqnOEhss/n/fauGV+O61oV4d7pD6kh/9ti+I20ev9E2bFhc8e6kGVQa9QPSdubhjL08s9NI
S+LI+H+SqHZGnEJlPqQewQcDWkYtuJfzt9WyVSHvutxMAJf7FJUnM7/oQ0dG0giZFmA7mn7S5u04
6uwBHjxIVkkJx0w3AJ6IDsBz4W9m6XJHMD4Q5QsDyZpCAGzFlH5hxIrff4IaC1nEWTJ3s7xgaVY5
/bQGeyzWZDbZvUjthB9+pSKPKrhC9IK31FOQeE4tGv2Bb0TXOwF0lkLgAOIua+rF7nKsu7/+6qqo
+Nz2snmKtmcCAwEAAaNCMEAwHQYDVR0OBBYEFJ3AZ6YMItkm9UWrpmVSESfYRaxjMA8GA1UdEwEB
/wQFMAMBAf8wDgYDVR0PAQH/BAQDAgEGMA0GCSqGSIb3DQEBDAUAA4ICAQCzV00QYk465KzquByv
MiPIs0laUZx2KI15qldGF9X1Uva3ROgIRL8YhNILgM3FEv0AVQVhh0HctSSePMTYyPtwni94loMg
Nt58D2kTiKV1NpgIpsbfrM7jWNa3Pt668+s0QNiigfV4Py/VpfzZotReBA4Xrf5B8OWycvpEgjNC
6C1Y91aMYj+6QrCcDFx+LmUmXFNPALJ4fqENmS2NuB2OosSw/WDQMKSOyARiqcTtNd56l+0OOF6S
L5Nwpamcb6d9Ex1+xghIsV5n61EIJenmJWtSKZGc0jlzCFfemQa0W50QBuHCAKi4HEoCChTQwUHK
+4w1IX2COPKpVJEZNZOUbWo6xbLQu4mGk+ibyQ86p3q4ofB4Rvr8Ny/lioTz3/4E2aFooC8k4gmV
BtWVyuEklut89pMFu+1z6S3RdTnX5yTb2E5fQ4+e0BQ5v1VwSJlXMbSc7kqYA5YwH2AG7hsj/oFg
IxpHYoWlzBk0gG+zrBrjn/B7SK3VAdlntqlyk+otZrWyuOQ9PLLvTIzq6we/qzWaVYa8GKa1qF60
g2xraUDTn9zxw2lrueFtCfTxqlB2Cnp9ehehVZZCmTEJ3WARjQUwfuaORtGdFNrHF+QFlozEJLUb
zxQHskD4o55BhrwE0GuWyCqANP2/7waj3VjFhT0+j/6eKeC2uAloGRwYQw==
-----END CERTIFICATE-----

AffirmTrust Premium ECC
=======================
-----BEGIN CERTIFICATE-----
MIIB/jCCAYWgAwIBAgIIdJclisc/elQwCgYIKoZIzj0EAwMwRTELMAkGA1UEBhMCVVMxFDASBgNV
BAoMC0FmZmlybVRydXN0MSAwHgYDVQQDDBdBZmZpcm1UcnVzdCBQcmVtaXVtIEVDQzAeFw0xMDAx
MjkxNDIwMjRaFw00MDEyMzExNDIwMjRaMEUxCzAJBgNVBAYTAlVTMRQwEgYDVQQKDAtBZmZpcm1U
cnVzdDEgMB4GA1UEAwwXQWZmaXJtVHJ1c3QgUHJlbWl1bSBFQ0MwdjAQBgcqhkjOPQIBBgUrgQQA
IgNiAAQNMF4bFZ0D0KF5Nbc6PJJ6yhUczWLznCZcBz3lVPqj1swS6vQUX+iOGasvLkjmrBhDeKzQ
N8O9ss0s5kfiGuZjuD0uL3jET9v0D6RoTFVya5UdThhClXjMNzyR4ptlKymjQjBAMB0GA1UdDgQW
BBSaryl6wBE1NSZRMADDav5A1a7WPDAPBgNVHRMBAf8EBTADAQH/MA4GA1UdDwEB/wQEAwIBBjAK
BggqhkjOPQQDAwNnADBkAjAXCfOHiFBar8jAQr9HX/VsaobgxCd05DhT1wV/GzTjxi+zygk8N53X
57hG8f2h4nECMEJZh0PUUd+60wkyWs6Iflc9nF9Ca/UHLbXwgpP5WW+uZPpY5Yse42O+tYHNbwKM
eQ==
-----END CERTIFICATE-----

Certum Trusted Network CA
=========================
-----BEGIN CERTIFICATE-----
MIIDuzCCAqOgAwIBAgIDBETAMA0GCSqGSIb3DQEBBQUAMH4xCzAJBgNVBAYTAlBMMSIwIAYDVQQK
ExlVbml6ZXRvIFRlY2hub2xvZ2llcyBTLkEuMScwJQYDVQQLEx5DZXJ0dW0gQ2VydGlmaWNhdGlv
biBBdXRob3JpdHkxIjAgBgNVBAMTGUNlcnR1bSBUcnVzdGVkIE5ldHdvcmsgQ0EwHhcNMDgxMDIy
MTIwNzM3WhcNMjkxMjMxMTIwNzM3WjB+MQswCQYDVQQGEwJQTDEiMCAGA1UEChMZVW5pemV0byBU
ZWNobm9sb2dpZXMgUy5BLjEnMCUGA1UECxMeQ2VydHVtIENlcnRpZmljYXRpb24gQXV0aG9yaXR5
MSIwIAYDVQQDExlDZXJ0dW0gVHJ1c3RlZCBOZXR3b3JrIENBMIIBIjANBgkqhkiG9w0BAQEFAAOC
AQ8AMIIBCgKCAQEA4/t9o3K6wvDJFIf1awFO4W5AB7ptJ11/91sts1rHUV+rpDKmYYe2bg+G0jAC
l/jXaVehGDldamR5xgFZrDwxSjh80gTSSyjoIF87B6LMTXPb865Px1bVWqeWifrzq2jUI4ZZJ88J
J7ysbnKDHDBy3+Ci6dLhdHUZvSqeexVUBBvXQzmtVSjF4hq79MDkrjhJM8x2hZ85RdKknvISjFH4
fOQtf/WsX+sWn7Et0brMkUJ3TCXJkDhv2/DM+44el1k+1WBO5gUo7Ul5E0u6SNsv+XLTOcr+H9g0
cvW0QM8xAcPs3hEtF10fuFDRXhmnad4HMyjKUJX5p1TLVIZQRan5SQIDAQABo0IwQDAPBgNVHRMB
Af8EBTADAQH/MB0GA1UdDgQWBBQIds3LB/8k9sXN7buQvOKEN0Z19zAOBgNVHQ8BAf8EBAMCAQYw
DQYJKoZIhvcNAQEFBQADggEBAKaorSLOAT2mo/9i0Eidi15ysHhE49wcrwn9I0j6vSrEuVUEtRCj
jSfeC4Jj0O7eDDd5QVsisrCaQVymcODU0HfLI9MA4GxWL+FpDQ3Zqr8hgVDZBqWo/5U30Kr+4rP1
mS1FhIrlQgnXdAIv94nYmem8J9RHjboNRhx3zxSkHLmkMcScKHQDNP8zGSal6Q10tz6XxnboJ5aj
Zt3hrvJBW8qYVoNzcOSGGtIxQbovvi0TWnZvTuhOgQ4/WwMioBK+ZlgRSssDxLQqKi2WF+A5VLxI
03YnnZotBqbJ7DnSq9ufmgsnAjUpsUCV5/nonFWIGUbWtzT1fs45mtk48VH3Tyw=
-----END CERTIFICATE-----

Certinomis - Autorité Racine
=============================
-----BEGIN CERTIFICATE-----
MIIFnDCCA4SgAwIBAgIBATANBgkqhkiG9w0BAQUFADBjMQswCQYDVQQGEwJGUjETMBEGA1UEChMK
Q2VydGlub21pczEXMBUGA1UECxMOMDAwMiA0MzM5OTg5MDMxJjAkBgNVBAMMHUNlcnRpbm9taXMg
LSBBdXRvcml0w6kgUmFjaW5lMB4XDTA4MDkxNzA4Mjg1OVoXDTI4MDkxNzA4Mjg1OVowYzELMAkG
A1UEBhMCRlIxEzARBgNVBAoTCkNlcnRpbm9taXMxFzAVBgNVBAsTDjAwMDIgNDMzOTk4OTAzMSYw
JAYDVQQDDB1DZXJ0aW5vbWlzIC0gQXV0b3JpdMOpIFJhY2luZTCCAiIwDQYJKoZIhvcNAQEBBQAD
ggIPADCCAgoCggIBAJ2Fn4bT46/HsmtuM+Cet0I0VZ35gb5j2CN2DpdUzZlMGvE5x4jYF1AMnmHa
wE5V3udauHpOd4cN5bjr+p5eex7Ezyh0x5P1FMYiKAT5kcOrJ3NqDi5N8y4oH3DfVS9O7cdxbwly
Lu3VMpfQ8Vh30WC8Tl7bmoT2R2FFK/ZQpn9qcSdIhDWerP5pqZ56XjUl+rSnSTV3lqc2W+HN3yNw
2F1MpQiD8aYkOBOo7C+ooWfHpi2GR+6K/OybDnT0K0kCe5B1jPyZOQE51kqJ5Z52qz6WKDgmi92N
jMD2AR5vpTESOH2VwnHu7XSu5DaiQ3XV8QCb4uTXzEIDS3h65X27uK4uIJPT5GHfceF2Z5c/tt9q
c1pkIuVC28+BA5PY9OMQ4HL2AHCs8MF6DwV/zzRpRbWT5BnbUhYjBYkOjUjkJW+zeL9i9Qf6lSTC
lrLooyPCXQP8w9PlfMl1I9f09bze5N/NgL+RiH2nE7Q5uiy6vdFrzPOlKO1Enn1So2+WLhl+HPNb
xxaOu2B9d2ZHVIIAEWBsMsGoOBvrbpgT1u449fCfDu/+MYHB0iSVL1N6aaLwD4ZFjliCK0wi1F6g
530mJ0jfJUaNSih8hp75mxpZuWW/Bd22Ql095gBIgl4g9xGC3srYn+Y3RyYe63j3YcNBZFgCQfna
4NH4+ej9Uji29YnfAgMBAAGjWzBZMA8GA1UdEwEB/wQFMAMBAf8wDgYDVR0PAQH/BAQDAgEGMB0G
A1UdDgQWBBQNjLZh2kS40RR9w759XkjwzspqsDAXBgNVHSAEEDAOMAwGCiqBegFWAgIAAQEwDQYJ
KoZIhvcNAQEFBQADggIBACQ+YAZ+He86PtvqrxyaLAEL9MW12Ukx9F1BjYkMTv9sov3/4gbIOZ/x
WqndIlgVqIrTseYyCYIDbNc/CMf4uboAbbnW/FIyXaR/pDGUu7ZMOH8oMDX/nyNTt7buFHAAQCva
R6s0fl6nVjBhK4tDrP22iCj1a7Y+YEq6QpA0Z43q619FVDsXrIvkxmUP7tCMXWY5zjKn2BCXwH40
nJ+U8/aGH88bc62UeYdocMMzpXDn2NU4lG9jeeu/Cg4I58UvD0KgKxRA/yHgBcUn4YQRE7rWhh1B
CxMjidPJC+iKunqjo3M3NYB9Ergzd0A4wPpeMNLytqOx1qKVl4GbUu1pTP+A5FPbVFsDbVRfsbjv
JL1vnxHDx2TCDyhihWZeGnuyt++uNckZM6i4J9szVb9o4XVIRFb7zdNIu0eJOqxp9YDG5ERQL1TE
qkPFMTFYvZbF6nVsmnWxTfj3l/+WFvKXTej28xH5On2KOG4Ey+HTRRWqpdEdnV1j6CTmNhTih60b
WfVEm/vXd3wfAXBioSAaosUaKPQhA+4u2cGA6rnZgtZbdsLLO7XSAPCjDuGtbkD326C00EauFddE
wk01+dIL8hf2rGbVJLJP0RyZwG71fet0BLj5TXcJ17TPBzAJ8bgAVtkXFhYKK4bfjwEZGuW7gmP/
vgt2Fl43N+bYdJeimUV5
-----END CERTIFICATE-----

Root CA Generalitat Valenciana
==============================
-----BEGIN CERTIFICATE-----
MIIGizCCBXOgAwIBAgIEO0XlaDANBgkqhkiG9w0BAQUFADBoMQswCQYDVQQGEwJFUzEfMB0GA1UE
ChMWR2VuZXJhbGl0YXQgVmFsZW5jaWFuYTEPMA0GA1UECxMGUEtJR1ZBMScwJQYDVQQDEx5Sb290
IENBIEdlbmVyYWxpdGF0IFZhbGVuY2lhbmEwHhcNMDEwNzA2MTYyMjQ3WhcNMjEwNzAxMTUyMjQ3
WjBoMQswCQYDVQQGEwJFUzEfMB0GA1UEChMWR2VuZXJhbGl0YXQgVmFsZW5jaWFuYTEPMA0GA1UE
CxMGUEtJR1ZBMScwJQYDVQQDEx5Sb290IENBIEdlbmVyYWxpdGF0IFZhbGVuY2lhbmEwggEiMA0G
CSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQDGKqtXETcvIorKA3Qdyu0togu8M1JAJke+WmmmO3I2
F0zo37i7L3bhQEZ0ZQKQUgi0/6iMweDHiVYQOTPvaLRfX9ptI6GJXiKjSgbwJ/BXufjpTjJ3Cj9B
ZPPrZe52/lSqfR0grvPXdMIKX/UIKFIIzFVd0g/bmoGlu6GzwZTNVOAydTGRGmKy3nXiz0+J2ZGQ
D0EbtFpKd71ng+CT516nDOeB0/RSrFOyA8dEJvt55cs0YFAQexvba9dHq198aMpunUEDEO5rmXte
JajCq+TA81yc477OMUxkHl6AovWDfgzWyoxVjr7gvkkHD6MkQXpYHYTqWBLI4bft75PelAgxAgMB
AAGjggM7MIIDNzAyBggrBgEFBQcBAQQmMCQwIgYIKwYBBQUHMAGGFmh0dHA6Ly9vY3NwLnBraS5n
dmEuZXMwEgYDVR0TAQH/BAgwBgEB/wIBAjCCAjQGA1UdIASCAiswggInMIICIwYKKwYBBAG/VQIB
ADCCAhMwggHoBggrBgEFBQcCAjCCAdoeggHWAEEAdQB0AG8AcgBpAGQAYQBkACAAZABlACAAQwBl
AHIAdABpAGYAaQBjAGEAYwBpAPMAbgAgAFIAYQDtAHoAIABkAGUAIABsAGEAIABHAGUAbgBlAHIA
YQBsAGkAdABhAHQAIABWAGEAbABlAG4AYwBpAGEAbgBhAC4ADQAKAEwAYQAgAEQAZQBjAGwAYQBy
AGEAYwBpAPMAbgAgAGQAZQAgAFAAcgDhAGMAdABpAGMAYQBzACAAZABlACAAQwBlAHIAdABpAGYA
aQBjAGEAYwBpAPMAbgAgAHEAdQBlACAAcgBpAGcAZQAgAGUAbAAgAGYAdQBuAGMAaQBvAG4AYQBt
AGkAZQBuAHQAbwAgAGQAZQAgAGwAYQAgAHAAcgBlAHMAZQBuAHQAZQAgAEEAdQB0AG8AcgBpAGQA
YQBkACAAZABlACAAQwBlAHIAdABpAGYAaQBjAGEAYwBpAPMAbgAgAHMAZQAgAGUAbgBjAHUAZQBu
AHQAcgBhACAAZQBuACAAbABhACAAZABpAHIAZQBjAGMAaQDzAG4AIAB3AGUAYgAgAGgAdAB0AHAA
OgAvAC8AdwB3AHcALgBwAGsAaQAuAGcAdgBhAC4AZQBzAC8AYwBwAHMwJQYIKwYBBQUHAgEWGWh0
dHA6Ly93d3cucGtpLmd2YS5lcy9jcHMwHQYDVR0OBBYEFHs100DSHHgZZu90ECjcPk+yeAT8MIGV
BgNVHSMEgY0wgYqAFHs100DSHHgZZu90ECjcPk+yeAT8oWykajBoMQswCQYDVQQGEwJFUzEfMB0G
A1UEChMWR2VuZXJhbGl0YXQgVmFsZW5jaWFuYTEPMA0GA1UECxMGUEtJR1ZBMScwJQYDVQQDEx5S
b290IENBIEdlbmVyYWxpdGF0IFZhbGVuY2lhbmGCBDtF5WgwDQYJKoZIhvcNAQEFBQADggEBACRh
TvW1yEICKrNcda3FbcrnlD+laJWIwVTAEGmiEi8YPyVQqHxK6sYJ2fR1xkDar1CdPaUWu20xxsdz
Ckj+IHLtb8zog2EWRpABlUt9jppSCS/2bxzkoXHPjCpaF3ODR00PNvsETUlR4hTJZGH71BTg9J63
NI8KJr2XXPR5OkowGcytT6CYirQxlyric21+eLj4iIlPsSKRZEv1UN4D2+XFducTZnV+ZfsBn5OH
iJ35Rld8TWCvmHMTI6QgkYH60GFmuH3Rr9ZvHmw96RH9qfmCIoaZM3Fa6hlXPZHNqcCjbgcTpsnt
+GijnsNacgmHKNHEc8RzGF9QdRYxn7fofMM=
-----END CERTIFICATE-----

A-Trust-nQual-03
================
-----BEGIN CERTIFICATE-----
MIIDzzCCAregAwIBAgIDAWweMA0GCSqGSIb3DQEBBQUAMIGNMQswCQYDVQQGEwJBVDFIMEYGA1UE
Cgw/QS1UcnVzdCBHZXMuIGYuIFNpY2hlcmhlaXRzc3lzdGVtZSBpbSBlbGVrdHIuIERhdGVudmVy
a2VociBHbWJIMRkwFwYDVQQLDBBBLVRydXN0LW5RdWFsLTAzMRkwFwYDVQQDDBBBLVRydXN0LW5R
dWFsLTAzMB4XDTA1MDgxNzIyMDAwMFoXDTE1MDgxNzIyMDAwMFowgY0xCzAJBgNVBAYTAkFUMUgw
RgYDVQQKDD9BLVRydXN0IEdlcy4gZi4gU2ljaGVyaGVpdHNzeXN0ZW1lIGltIGVsZWt0ci4gRGF0
ZW52ZXJrZWhyIEdtYkgxGTAXBgNVBAsMEEEtVHJ1c3QtblF1YWwtMDMxGTAXBgNVBAMMEEEtVHJ1
c3QtblF1YWwtMDMwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQCtPWFuA/OQO8BBC4SA
zewqo51ru27CQoT3URThoKgtUaNR8t4j8DRE/5TrzAUjlUC5B3ilJfYKvUWG6Nm9wASOhURh73+n
yfrBJcyFLGM/BWBzSQXgYHiVEEvc+RFZznF/QJuKqiTfC0Li21a8StKlDJu3Qz7dg9MmEALP6iPE
SU7l0+m0iKsMrmKS1GWH2WrX9IWf5DMiJaXlyDO6w8dB3F/GaswADm0yqLaHNgBid5seHzTLkDx4
iHQF63n1k3Flyp3HaxgtPVxO59X4PzF9j4fsCiIvI+n+u33J4PTs63zEsMMtYrWacdaxaujs2e3V
cuy+VwHOBVWf3tFgiBCzAgMBAAGjNjA0MA8GA1UdEwEB/wQFMAMBAf8wEQYDVR0OBAoECERqlWdV
eRFPMA4GA1UdDwEB/wQEAwIBBjANBgkqhkiG9w0BAQUFAAOCAQEAVdRU0VlIXLOThaq/Yy/kgM40
ozRiPvbY7meIMQQDbwvUB/tOdQ/TLtPAF8fGKOwGDREkDg6lXb+MshOWcdzUzg4NCmgybLlBMRmr
sQd7TZjTXLDR8KdCoLXEjq/+8T/0709GAHbrAvv5ndJAlseIOrifEXnzgGWovR/TeIGgUUw3tKZd
JXDRZslo+S4RFGjxVJgIrCaSD96JntT6s3kr0qN51OyLrIdTaEJMUVF0HhsnLuP1Hyl0Te2v9+GS
mYHovjrHF1D2t8b8m7CKa9aIA5GPBnc6hQLdmNVDeD/GMBWsm2vLV7eJUYs66MmEDNuxUCAKGkq6
ahq97BvIxYSazQ==
-----END CERTIFICATE-----

TWCA Root Certification Authority
=================================
-----BEGIN CERTIFICATE-----
MIIDezCCAmOgAwIBAgIBATANBgkqhkiG9w0BAQUFADBfMQswCQYDVQQGEwJUVzESMBAGA1UECgwJ
VEFJV0FOLUNBMRAwDgYDVQQLDAdSb290IENBMSowKAYDVQQDDCFUV0NBIFJvb3QgQ2VydGlmaWNh
dGlvbiBBdXRob3JpdHkwHhcNMDgwODI4MDcyNDMzWhcNMzAxMjMxMTU1OTU5WjBfMQswCQYDVQQG
EwJUVzESMBAGA1UECgwJVEFJV0FOLUNBMRAwDgYDVQQLDAdSb290IENBMSowKAYDVQQDDCFUV0NB
IFJvb3QgQ2VydGlmaWNhdGlvbiBBdXRob3JpdHkwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEK
AoIBAQCwfnK4pAOU5qfeCTiRShFAh6d8WWQUe7UREN3+v9XAu1bihSX0NXIP+FPQQeFEAcK0HMMx
QhZHhTMidrIKbw/lJVBPhYa+v5guEGcevhEFhgWQxFnQfHgQsIBct+HHK3XLfJ+utdGdIzdjp9xC
oi2SBBtQwXu4PhvJVgSLL1KbralW6cH/ralYhzC2gfeXRfwZVzsrb+RH9JlF/h3x+JejiB03HFyP
4HYlmlD4oFT/RJB2I9IyxsOrBr/8+7/zrX2SYgJbKdM1o5OaQ2RgXbL6Mv87BK9NQGr5x+PvI/1r
y+UPizgN7gr8/g+YnzAx3WxSZfmLgb4i4RxYA7qRG4kHAgMBAAGjQjBAMA4GA1UdDwEB/wQEAwIB
BjAPBgNVHRMBAf8EBTADAQH/MB0GA1UdDgQWBBRqOFsmjd6LWvJPelSDGRjjCDWmujANBgkqhkiG
9w0BAQUFAAOCAQEAPNV3PdrfibqHDAhUaiBQkr6wQT25JmSDCi/oQMCXKCeCMErJk/9q56YAf4lC
mtYR5VPOL8zy2gXE/uJQxDqGfczafhAJO5I1KlOy/usrBdlsXebQ79NqZp4VKIV66IIArB6nCWlW
QtNoURi+VJq/REG6Sb4gumlc7rh3zc5sH62Dlhh9DrUUOYTxKOkto557HnpyWoOzeW/vtPzQCqVY
T0bf+215WfKEIlKuD8z7fDvnaspHYcN6+NOSBB+4IIThNlQWx0DeO4pz3N/GCUzf7Nr/1FNCocny
Yh0igzyXxfkZYiesZSLX0zzG5Y6yU8xJzrww/nsOM5D77dIUkR8Hrw==
-----END CERTIFICATE-----

Security Communication RootCA2
==============================
-----BEGIN CERTIFICATE-----
MIIDdzCCAl+gAwIBAgIBADANBgkqhkiG9w0BAQsFADBdMQswCQYDVQQGEwJKUDElMCMGA1UEChMc
U0VDT00gVHJ1c3QgU3lzdGVtcyBDTy4sTFRELjEnMCUGA1UECxMeU2VjdXJpdHkgQ29tbXVuaWNh
dGlvbiBSb290Q0EyMB4XDTA5MDUyOTA1MDAzOVoXDTI5MDUyOTA1MDAzOVowXTELMAkGA1UEBhMC
SlAxJTAjBgNVBAoTHFNFQ09NIFRydXN0IFN5c3RlbXMgQ08uLExURC4xJzAlBgNVBAsTHlNlY3Vy
aXR5IENvbW11bmljYXRpb24gUm9vdENBMjCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEB
ANAVOVKxUrO6xVmCxF1SrjpDZYBLx/KWvNs2l9amZIyoXvDjChz335c9S672XewhtUGrzbl+dp++
+T42NKA7wfYxEUV0kz1XgMX5iZnK5atq1LXaQZAQwdbWQonCv/Q4EpVMVAX3NuRFg3sUZdbcDE3R
3n4MqzvEFb46VqZab3ZpUql6ucjrappdUtAtCms1FgkQhNBqyjoGADdH5H5XTz+L62e4iKrFvlNV
spHEfbmwhRkGeC7bYRr6hfVKkaHnFtWOojnflLhwHyg/i/xAXmODPIMqGplrz95Zajv8bxbXH/1K
EOtOghY6rCcMU/Gt1SSwawNQwS08Ft1ENCcadfsCAwEAAaNCMEAwHQYDVR0OBBYEFAqFqXdlBZh8
QIH4D5csOPEK7DzPMA4GA1UdDwEB/wQEAwIBBjAPBgNVHRMBAf8EBTADAQH/MA0GCSqGSIb3DQEB
CwUAA4IBAQBMOqNErLlFsceTfsgLCkLfZOoc7llsCLqJX2rKSpWeeo8HxdpFcoJxDjrSzG+ntKEj
u/Ykn8sX/oymzsLS28yN/HH8AynBbF0zX2S2ZTuJbxh2ePXcokgfGT+Ok+vx+hfuzU7jBBJV1uXk
3fs+BXziHV7Gp7yXT2g69ekuCkO2r1dcYmh8t/2jioSgrGK+KwmHNPBqAbubKVY8/gA3zyNs8U6q
tnRGEmyR7jTV7JqR50S+kDFy1UkC9gLl9B/rfNmWVan/7Ir5mUf/NVoCqgTLiluHcSmRvaS0eg29
mvVXIwAHIRc/SjnRBUkLp7Y3gaVdjKozXoEofKd9J+sAro03
-----END CERTIFICATE-----

EC-ACC
======
-----BEGIN CERTIFICATE-----
MIIFVjCCBD6gAwIBAgIQ7is969Qh3hSoYqwE893EATANBgkqhkiG9w0BAQUFADCB8zELMAkGA1UE
BhMCRVMxOzA5BgNVBAoTMkFnZW5jaWEgQ2F0YWxhbmEgZGUgQ2VydGlmaWNhY2lvIChOSUYgUS0w
ODAxMTc2LUkpMSgwJgYDVQQLEx9TZXJ2ZWlzIFB1YmxpY3MgZGUgQ2VydGlmaWNhY2lvMTUwMwYD
VQQLEyxWZWdldSBodHRwczovL3d3dy5jYXRjZXJ0Lm5ldC92ZXJhcnJlbCAoYykwMzE1MDMGA1UE
CxMsSmVyYXJxdWlhIEVudGl0YXRzIGRlIENlcnRpZmljYWNpbyBDYXRhbGFuZXMxDzANBgNVBAMT
BkVDLUFDQzAeFw0wMzAxMDcyMzAwMDBaFw0zMTAxMDcyMjU5NTlaMIHzMQswCQYDVQQGEwJFUzE7
MDkGA1UEChMyQWdlbmNpYSBDYXRhbGFuYSBkZSBDZXJ0aWZpY2FjaW8gKE5JRiBRLTA4MDExNzYt
SSkxKDAmBgNVBAsTH1NlcnZlaXMgUHVibGljcyBkZSBDZXJ0aWZpY2FjaW8xNTAzBgNVBAsTLFZl
Z2V1IGh0dHBzOi8vd3d3LmNhdGNlcnQubmV0L3ZlcmFycmVsIChjKTAzMTUwMwYDVQQLEyxKZXJh
cnF1aWEgRW50aXRhdHMgZGUgQ2VydGlmaWNhY2lvIENhdGFsYW5lczEPMA0GA1UEAxMGRUMtQUND
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAsyLHT+KXQpWIR4NA9h0X84NzJB5R85iK
w5K4/0CQBXCHYMkAqbWUZRkiFRfCQ2xmRJoNBD45b6VLeqpjt4pEndljkYRm4CgPukLjbo73FCeT
ae6RDqNfDrHrZqJyTxIThmV6PttPB/SnCWDaOkKZx7J/sxaVHMf5NLWUhdWZXqBIoH7nF2W4onW4
HvPlQn2v7fOKSGRdghST2MDk/7NQcvJ29rNdQlB50JQ+awwAvthrDk4q7D7SzIKiGGUzE3eeml0a
E9jD2z3Il3rucO2n5nzbcc8tlGLfbdb1OL4/pYUKGbio2Al1QnDE6u/LDsg0qBIimAy4E5S2S+zw
0JDnJwIDAQABo4HjMIHgMB0GA1UdEQQWMBSBEmVjX2FjY0BjYXRjZXJ0Lm5ldDAPBgNVHRMBAf8E
BTADAQH/MA4GA1UdDwEB/wQEAwIBBjAdBgNVHQ4EFgQUoMOLRKo3pUW/l4Ba0fF4opvpXY0wfwYD
VR0gBHgwdjB0BgsrBgEEAfV4AQMBCjBlMCwGCCsGAQUFBwIBFiBodHRwczovL3d3dy5jYXRjZXJ0
Lm5ldC92ZXJhcnJlbDA1BggrBgEFBQcCAjApGidWZWdldSBodHRwczovL3d3dy5jYXRjZXJ0Lm5l
dC92ZXJhcnJlbCAwDQYJKoZIhvcNAQEFBQADggEBAKBIW4IB9k1IuDlVNZyAelOZ1Vr/sXE7zDkJ
lF7W2u++AVtd0x7Y/X1PzaBB4DSTv8vihpw3kpBWHNzrKQXlxJ7HNd+KDM3FIUPpqojlNcAZQmNa
Al6kSBg6hW/cnbw/nZzBh7h6YQjpdwt/cKt63dmXLGQehb+8dJahw3oS7AwaboMMPOhyRp/7SNVe
l+axofjk70YllJyJ22k4vuxcDlbHZVHlUIiIv0LVKz3l+bqeLrPK9HOSAgu+TGbrIP65y7WZf+a2
E/rKS03Z7lNGBjvGTq2TWoF+bCpLagVFjPIhpDGQh2xlnJ2lYJU6Un/10asIbvPuW/mIPX64b24D
5EI=
-----END CERTIFICATE-----

Hellenic Academic and Research Institutions RootCA 2011
=======================================================
-----BEGIN CERTIFICATE-----
MIIEMTCCAxmgAwIBAgIBADANBgkqhkiG9w0BAQUFADCBlTELMAkGA1UEBhMCR1IxRDBCBgNVBAoT
O0hlbGxlbmljIEFjYWRlbWljIGFuZCBSZXNlYXJjaCBJbnN0aXR1dGlvbnMgQ2VydC4gQXV0aG9y
aXR5MUAwPgYDVQQDEzdIZWxsZW5pYyBBY2FkZW1pYyBhbmQgUmVzZWFyY2ggSW5zdGl0dXRpb25z
IFJvb3RDQSAyMDExMB4XDTExMTIwNjEzNDk1MloXDTMxMTIwMTEzNDk1MlowgZUxCzAJBgNVBAYT
AkdSMUQwQgYDVQQKEztIZWxsZW5pYyBBY2FkZW1pYyBhbmQgUmVzZWFyY2ggSW5zdGl0dXRpb25z
IENlcnQuIEF1dGhvcml0eTFAMD4GA1UEAxM3SGVsbGVuaWMgQWNhZGVtaWMgYW5kIFJlc2VhcmNo
IEluc3RpdHV0aW9ucyBSb290Q0EgMjAxMTCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEB
AKlTAOMupvaO+mDYLZU++CwqVE7NuYRhlFhPjz2L5EPzdYmNUeTDN9KKiE15HrcS3UN4SoqS5tdI
1Q+kOilENbgH9mgdVc04UfCMJDGFr4PJfel3r+0ae50X+bOdOFAPplp5kYCvN66m0zH7tSYJnTxa
71HFK9+WXesyHgLacEnsbgzImjeN9/E2YEsmLIKe0HjzDQ9jpFEw4fkrJxIH2Oq9GGKYsFk3fb7u
8yBRQlqD75O6aRXxYp2fmTmCobd0LovUxQt7L/DICto9eQqakxylKHJzkUOap9FNhYS5qXSPFEDH
3N6sQWRstBmbAmNtJGSPRLIl6s5ddAxjMlyNh+UCAwEAAaOBiTCBhjAPBgNVHRMBAf8EBTADAQH/
MAsGA1UdDwQEAwIBBjAdBgNVHQ4EFgQUppFC/RNhSiOeCKQp5dgTBCPuQSUwRwYDVR0eBEAwPqA8
MAWCAy5ncjAFggMuZXUwBoIELmVkdTAGggQub3JnMAWBAy5ncjAFgQMuZXUwBoEELmVkdTAGgQQu
b3JnMA0GCSqGSIb3DQEBBQUAA4IBAQAf73lB4XtuP7KMhjdCSk4cNx6NZrokgclPEg8hwAOXhiVt
XdMiKahsog2p6z0GW5k6x8zDmjR/qw7IThzh+uTczQ2+vyT+bOdrwg3IBp5OjWEopmr95fZi6hg8
TqBTnbI6nOulnJEWtk2C4AwFSKls9cz4y51JtPACpf1wA+2KIaWuE4ZJwzNzvoc7dIsXRSZMFpGD
/md9zU1jZ/rzAxKWeAaNsWftjj++n08C9bMJL/NMh98qy5V8AcysNnq/onN694/BtZqhFLKPM58N
7yLcZnuEvUUXBj08yrl3NI/K6s8/MT7jiOOASSXIl7WdmplNsDz4SgCbZN2fOUvRJ9e4
-----END CERTIFICATE-----

Actalis Authentication Root CA
==============================
-----BEGIN CERTIFICATE-----
MIIFuzCCA6OgAwIBAgIIVwoRl0LE48wwDQYJKoZIhvcNAQELBQAwazELMAkGA1UEBhMCSVQxDjAM
BgNVBAcMBU1pbGFuMSMwIQYDVQQKDBpBY3RhbGlzIFMucC5BLi8wMzM1ODUyMDk2NzEnMCUGA1UE
AwweQWN0YWxpcyBBdXRoZW50aWNhdGlvbiBSb290IENBMB4XDTExMDkyMjExMjIwMloXDTMwMDky
MjExMjIwMlowazELMAkGA1UEBhMCSVQxDjAMBgNVBAcMBU1pbGFuMSMwIQYDVQQKDBpBY3RhbGlz
IFMucC5BLi8wMzM1ODUyMDk2NzEnMCUGA1UEAwweQWN0YWxpcyBBdXRoZW50aWNhdGlvbiBSb290
IENBMIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAp8bEpSmkLO/lGMWwUKNvUTufClrJ
wkg4CsIcoBh/kbWHuUA/3R1oHwiD1S0eiKD4j1aPbZkCkpAW1V8IbInX4ay8IMKx4INRimlNAJZa
by/ARH6jDuSRzVju3PvHHkVH3Se5CAGfpiEd9UEtL0z9KK3giq0itFZljoZUj5NDKd45RnijMCO6
zfB9E1fAXdKDa0hMxKufgFpbOr3JpyI/gCczWw63igxdBzcIy2zSekciRDXFzMwujt0q7bd9Zg1f
YVEiVRvjRuPjPdA1YprbrxTIW6HMiRvhMCb8oJsfgadHHwTrozmSBp+Z07/T6k9QnBn+locePGX2
oxgkg4YQ51Q+qDp2JE+BIcXjDwL4k5RHILv+1A7TaLndxHqEguNTVHnd25zS8gebLra8Pu2Fbe8l
EfKXGkJh90qX6IuxEAf6ZYGyojnP9zz/GPvG8VqLWeICrHuS0E4UT1lF9gxeKF+w6D9Fz8+vm2/7
hNN3WpVvrJSEnu68wEqPSpP4RCHiMUVhUE4Q2OM1fEwZtN4Fv6MGn8i1zeQf1xcGDXqVdFUNaBr8
EBtiZJ1t4JWgw5QHVw0U5r0F+7if5t+L4sbnfpb2U8WANFAoWPASUHEXMLrmeGO89LKtmyuy/uE5
jF66CyCU3nuDuP/jVo23Eek7jPKxwV2dpAtMK9myGPW1n0sCAwEAAaNjMGEwHQYDVR0OBBYEFFLY
iDrIn3hm7YnzezhwlMkCAjbQMA8GA1UdEwEB/wQFMAMBAf8wHwYDVR0jBBgwFoAUUtiIOsifeGbt
ifN7OHCUyQICNtAwDgYDVR0PAQH/BAQDAgEGMA0GCSqGSIb3DQEBCwUAA4ICAQALe3KHwGCmSUyI
WOYdiPcUZEim2FgKDk8TNd81HdTtBjHIgT5q1d07GjLukD0R0i70jsNjLiNmsGe+b7bAEzlgqqI0
JZN1Ut6nna0Oh4lScWoWPBkdg/iaKWW+9D+a2fDzWochcYBNy+A4mz+7+uAwTc+G02UQGRjRlwKx
K3JCaKygvU5a2hi/a5iB0P2avl4VSM0RFbnAKVy06Ij3Pjaut2L9HmLecHgQHEhb2rykOLpn7VU+
Xlff1ANATIGk0k9jpwlCCRT8AKnCgHNPLsBA2RF7SOp6AsDT6ygBJlh0wcBzIm2Tlf05fbsq4/aC
4yyXX04fkZT6/iyj2HYauE2yOE+b+h1IYHkm4vP9qdCa6HCPSXrW5b0KDtst842/6+OkfcvHlXHo
2qN8xcL4dJIEG4aspCJTQLas/kx2z/uUMsA1n3Y/buWQbqCmJqK4LL7RK4X9p2jIugErsWx0Hbhz
lefut8cl8ABMALJ+tguLHPPAUJ4lueAI3jZm/zel0btUZCzJJ7VLkn5l/9Mt4blOvH+kQSGQQXem
OR/qnuOf0GZvBeyqdn6/axag67XH/JJULysRJyU3eExRarDzzFhdFPFqSBX/wge2sY0PjlxQRrM9
vwGYT7JZVEc+NHt4bVaTLnPqZih4zR0Uv6CPLy64Lo7yFIrM6bV8+2ydDKXhlg==
-----END CERTIFICATE-----

Trustis FPS Root CA
===================
-----BEGIN CERTIFICATE-----
MIIDZzCCAk+gAwIBAgIQGx+ttiD5JNM2a/fH8YygWTANBgkqhkiG9w0BAQUFADBFMQswCQYDVQQG
EwJHQjEYMBYGA1UEChMPVHJ1c3RpcyBMaW1pdGVkMRwwGgYDVQQLExNUcnVzdGlzIEZQUyBSb290
IENBMB4XDTAzMTIyMzEyMTQwNloXDTI0MDEyMTExMzY1NFowRTELMAkGA1UEBhMCR0IxGDAWBgNV
BAoTD1RydXN0aXMgTGltaXRlZDEcMBoGA1UECxMTVHJ1c3RpcyBGUFMgUm9vdCBDQTCCASIwDQYJ
KoZIhvcNAQEBBQADggEPADCCAQoCggEBAMVQe547NdDfxIzNjpvto8A2mfRC6qc+gIMPpqdZh8mQ
RUN+AOqGeSoDvT03mYlmt+WKVoaTnGhLaASMk5MCPjDSNzoiYYkchU59j9WvezX2fihHiTHcDnlk
H5nSW7r+f2C/revnPDgpai/lkQtV/+xvWNUtyd5MZnGPDNcE2gfmHhjjvSkCqPoc4Vu5g6hBSLwa
cY3nYuUtsuvffM/bq1rKMfFMIvMFE/eC+XN5DL7XSxzA0RU8k0Fk0ea+IxciAIleH2ulrG6nS4zt
o3Lmr2NNL4XSFDWaLk6M6jKYKIahkQlBOrTh4/L68MkKokHdqeMDx4gVOxzUGpTXn2RZEm0CAwEA
AaNTMFEwDwYDVR0TAQH/BAUwAwEB/zAfBgNVHSMEGDAWgBS6+nEleYtXQSUhhgtx67JkDoshZzAd
BgNVHQ4EFgQUuvpxJXmLV0ElIYYLceuyZA6LIWcwDQYJKoZIhvcNAQEFBQADggEBAH5Y//01GX2c
GE+esCu8jowU/yyg2kdbw++BLa8F6nRIW/M+TgfHbcWzk88iNVy2P3UnXwmWzaD+vkAMXBJV+JOC
yinpXj9WV4s4NvdFGkwozZ5BuO1WTISkQMi4sKUraXAEasP41BIy+Q7DsdwyhEQsb8tGD+pmQQ9P
8Vilpg0ND2HepZ5dfWWhPBfnqFVO76DH7cZEf1T1o+CP8HxVIo8ptoGj4W1OLBuAZ+ytIJ8MYmHV
l/9D7S3B2l0pKoU/rGXuhg8FjZBf3+6f9L/uHfuY5H+QK4R4EA5sSVPvFVtlRkpdr7r7OnIdzfYl
iB6XzCGcKQENZetX2fNXlrtIzYE=
-----END CERTIFICATE-----

StartCom Certification Authority
================================
-----BEGIN CERTIFICATE-----
MIIHhzCCBW+gAwIBAgIBLTANBgkqhkiG9w0BAQsFADB9MQswCQYDVQQGEwJJTDEWMBQGA1UEChMN
U3RhcnRDb20gTHRkLjErMCkGA1UECxMiU2VjdXJlIERpZ2l0YWwgQ2VydGlmaWNhdGUgU2lnbmlu
ZzEpMCcGA1UEAxMgU3RhcnRDb20gQ2VydGlmaWNhdGlvbiBBdXRob3JpdHkwHhcNMDYwOTE3MTk0
NjM3WhcNMzYwOTE3MTk0NjM2WjB9MQswCQYDVQQGEwJJTDEWMBQGA1UEChMNU3RhcnRDb20gTHRk
LjErMCkGA1UECxMiU2VjdXJlIERpZ2l0YWwgQ2VydGlmaWNhdGUgU2lnbmluZzEpMCcGA1UEAxMg
U3RhcnRDb20gQ2VydGlmaWNhdGlvbiBBdXRob3JpdHkwggIiMA0GCSqGSIb3DQEBAQUAA4ICDwAw
ggIKAoICAQDBiNsJvGxGfHiflXu1M5DycmLWwTYgIiRezul38kMKogZkpMyONvg45iPwbm2xPN1y
o4UcodM9tDMr0y+v/uqwQVlntsQGfQqedIXWeUyAN3rfOQVSWff0G0ZDpNKFhdLDcfN1YjS6LIp/
Ho/u7TTQEceWzVI9ujPW3U3eCztKS5/CJi/6tRYccjV3yjxd5srhJosaNnZcAdt0FCX+7bWgiA/d
eMotHweXMAEtcnn6RtYTKqi5pquDSR3l8u/d5AGOGAqPY1MWhWKpDhk6zLVmpsJrdAfkK+F2PrRt
2PZE4XNiHzvEvqBTViVsUQn3qqvKv3b9bZvzndu/PWa8DFaqr5hIlTpL36dYUNk4dalb6kMMAv+Z
6+hsTXBbKWWc3apdzK8BMewM69KN6Oqce+Zu9ydmDBpI125C4z/eIT574Q1w+2OqqGwaVLRcJXrJ
osmLFqa7LH4XXgVNWG4SHQHuEhANxjJ/GP/89PrNbpHoNkm+Gkhpi8KWTRoSsmkXwQqQ1vp5Iki/
untp+HDH+no32NgN0nZPV/+Qt+OR0t3vwmC3Zzrd/qqc8NSLf3Iizsafl7b4r4qgEKjZ+xjGtrVc
UjyJthkqcwEKDwOzEmDyei+B26Nu/yYwl/WL3YlXtq09s68rxbd2AvCl1iuahhQqcvbjM4xdCUsT
37uMdBNSSwIDAQABo4ICEDCCAgwwDwYDVR0TAQH/BAUwAwEB/zAOBgNVHQ8BAf8EBAMCAQYwHQYD
VR0OBBYEFE4L7xqkQFulF2mHMMo0aEPQQa7yMB8GA1UdIwQYMBaAFE4L7xqkQFulF2mHMMo0aEPQ
Qa7yMIIBWgYDVR0gBIIBUTCCAU0wggFJBgsrBgEEAYG1NwEBATCCATgwLgYIKwYBBQUHAgEWImh0
dHA6Ly93d3cuc3RhcnRzc2wuY29tL3BvbGljeS5wZGYwNAYIKwYBBQUHAgEWKGh0dHA6Ly93d3cu
c3RhcnRzc2wuY29tL2ludGVybWVkaWF0ZS5wZGYwgc8GCCsGAQUFBwICMIHCMCcWIFN0YXJ0IENv
bW1lcmNpYWwgKFN0YXJ0Q29tKSBMdGQuMAMCAQEagZZMaW1pdGVkIExpYWJpbGl0eSwgcmVhZCB0
aGUgc2VjdGlvbiAqTGVnYWwgTGltaXRhdGlvbnMqIG9mIHRoZSBTdGFydENvbSBDZXJ0aWZpY2F0
aW9uIEF1dGhvcml0eSBQb2xpY3kgYXZhaWxhYmxlIGF0IGh0dHA6Ly93d3cuc3RhcnRzc2wuY29t
L3BvbGljeS5wZGYwEQYJYIZIAYb4QgEBBAQDAgAHMDgGCWCGSAGG+EIBDQQrFilTdGFydENvbSBG
cmVlIFNTTCBDZXJ0aWZpY2F0aW9uIEF1dGhvcml0eTANBgkqhkiG9w0BAQsFAAOCAgEAjo/n3JR5
fPGFf59Jb2vKXfuM/gTFwWLRfUKKvFO3lANmMD+x5wqnUCBVJX92ehQN6wQOQOY+2IirByeDqXWm
N3PH/UvSTa0XQMhGvjt/UfzDtgUx3M2FIk5xt/JxXrAaxrqTi3iSSoX4eA+D/i+tLPfkpLst0OcN
Org+zvZ49q5HJMqjNTbOx8aHmNrs++myziebiMMEofYLWWivydsQD032ZGNcpRJvkrKTlMeIFw6T
tn5ii5B/q06f/ON1FE8qMt9bDeD1e5MNq6HPh+GlBEXoPBKlCcWw0bdT82AUuoVpaiF8H3VhFyAX
e2w7QSlc4axa0c2Mm+tgHRns9+Ww2vl5GKVFP0lDV9LdJNUso/2RjSe15esUBppMeyG7Oq0wBhjA
2MFrLH9ZXF2RsXAiV+uKa0hK1Q8p7MZAwC+ITGgBF3f0JBlPvfrhsiAhS90a2Cl9qrjeVOwhVYBs
HvUwyKMQ5bLmKhQxw4UtjJixhlpPiVktucf3HMiKf8CdBUrmQk9io20ppB+Fq9vlgcitKj1MXVuE
JnHEhV5xJMqlG2zYYdMa4FTbzrqpMrUi9nNBCV24F10OD5mQ1kfabwo6YigUZ4LZ8dCAWZvLMdib
D4x3TrVoivJs9iQOLWxwxXPR3hTQcY+203sC9uO41Alua551hDnmfyWl8kgAwKQB2j8=
-----END CERTIFICATE-----

StartCom Certification Authority G2
===================================
-----BEGIN CERTIFICATE-----
MIIFYzCCA0ugAwIBAgIBOzANBgkqhkiG9w0BAQsFADBTMQswCQYDVQQGEwJJTDEWMBQGA1UEChMN
U3RhcnRDb20gTHRkLjEsMCoGA1UEAxMjU3RhcnRDb20gQ2VydGlmaWNhdGlvbiBBdXRob3JpdHkg
RzIwHhcNMTAwMTAxMDEwMDAxWhcNMzkxMjMxMjM1OTAxWjBTMQswCQYDVQQGEwJJTDEWMBQGA1UE
ChMNU3RhcnRDb20gTHRkLjEsMCoGA1UEAxMjU3RhcnRDb20gQ2VydGlmaWNhdGlvbiBBdXRob3Jp
dHkgRzIwggIiMA0GCSqGSIb3DQEBAQUAA4ICDwAwggIKAoICAQC2iTZbB7cgNr2Cu+EWIAOVeq8O
o1XJJZlKxdBWQYeQTSFgpBSHO839sj60ZwNq7eEPS8CRhXBF4EKe3ikj1AENoBB5uNsDvfOpL9HG
4A/LnooUCri99lZi8cVytjIl2bLzvWXFDSxu1ZJvGIsAQRSCb0AgJnooD/Uefyf3lLE3PbfHkffi
Aez9lInhzG7TNtYKGXmu1zSCZf98Qru23QumNK9LYP5/Q0kGi4xDuFby2X8hQxfqp0iVAXV16iul
Q5XqFYSdCI0mblWbq9zSOdIxHWDirMxWRST1HFSr7obdljKF+ExP6JV2tgXdNiNnvP8V4so75qbs
O+wmETRIjfaAKxojAuuKHDp2KntWFhxyKrOq42ClAJ8Em+JvHhRYW6Vsi1g8w7pOOlz34ZYrPu8H
vKTlXcxNnw3h3Kq74W4a7I/htkxNeXJdFzULHdfBR9qWJODQcqhaX2YtENwvKhOuJv4KHBnM0D4L
nMgJLvlblnpHnOl68wVQdJVznjAJ85eCXuaPOQgeWeU1FEIT/wCc976qUM/iUUjXuG+v+E5+M5iS
FGI6dWPPe/regjupuznixL0sAA7IF6wT700ljtizkC+p2il9Ha90OrInwMEePnWjFqmveiJdnxMa
z6eg6+OGCtP95paV1yPIN93EfKo2rJgaErHgTuixO/XWb/Ew1wIDAQABo0IwQDAPBgNVHRMBAf8E
BTADAQH/MA4GA1UdDwEB/wQEAwIBBjAdBgNVHQ4EFgQUS8W0QGutHLOlHGVuRjaJhwUMDrYwDQYJ
KoZIhvcNAQELBQADggIBAHNXPyzVlTJ+N9uWkusZXn5T50HsEbZH77Xe7XRcxfGOSeD8bpkTzZ+K
2s06Ctg6Wgk/XzTQLwPSZh0avZyQN8gMjgdalEVGKua+etqhqaRpEpKwfTbURIfXUfEpY9Z1zRbk
J4kd+MIySP3bmdCPX1R0zKxnNBFi2QwKN4fRoxdIjtIXHfbX/dtl6/2o1PXWT6RbdejF0mCy2wl+
JYt7ulKSnj7oxXehPOBKc2thz4bcQ///If4jXSRK9dNtD2IEBVeC2m6kMyV5Sy5UGYvMLD0w6dEG
/+gyRr61M3Z3qAFdlsHB1b6uJcDJHgoJIIihDsnzb02CVAAgp9KP5DlUFy6NHrgbuxu9mk47EDTc
nIhT76IxW1hPkWLIwpqazRVdOKnWvvgTtZ8SafJQYqz7Fzf07rh1Z2AQ+4NQ+US1dZxAF7L+/Xld
blhYXzD8AK6vM8EOTmy6p6ahfzLbOOCxchcKK5HsamMm7YnUeMx0HgX4a/6ManY5Ka5lIxKVCCIc
l85bBu4M4ru8H0ST9tg4RQUh7eStqxK2A6RCLi3ECToDZ2mEmuFZkIoohdVddLHRDiBYmxOlsGOm
7XtH/UVVMKTumtTm4ofvmMkyghEpIrwACjFeLQ/Ajulrso8uBtjRkcfGEvRM/TAXw8HaOFvjqerm
obp573PYtlNXLfbQ4ddI
-----END CERTIFICATE-----

Buypass Class 2 Root CA
=======================
-----BEGIN CERTIFICATE-----
MIIFWTCCA0GgAwIBAgIBAjANBgkqhkiG9w0BAQsFADBOMQswCQYDVQQGEwJOTzEdMBsGA1UECgwU
QnV5cGFzcyBBUy05ODMxNjMzMjcxIDAeBgNVBAMMF0J1eXBhc3MgQ2xhc3MgMiBSb290IENBMB4X
DTEwMTAyNjA4MzgwM1oXDTQwMTAyNjA4MzgwM1owTjELMAkGA1UEBhMCTk8xHTAbBgNVBAoMFEJ1
eXBhc3MgQVMtOTgzMTYzMzI3MSAwHgYDVQQDDBdCdXlwYXNzIENsYXNzIDIgUm9vdCBDQTCCAiIw
DQYJKoZIhvcNAQEBBQADggIPADCCAgoCggIBANfHXvfBB9R3+0Mh9PT1aeTuMgHbo4Yf5FkNuud1
g1Lr6hxhFUi7HQfKjK6w3Jad6sNgkoaCKHOcVgb/S2TwDCo3SbXlzwx87vFKu3MwZfPVL4O2fuPn
9Z6rYPnT8Z2SdIrkHJasW4DptfQxh6NR/Md+oW+OU3fUl8FVM5I+GC911K2GScuVr1QGbNgGE41b
/+EmGVnAJLqBcXmQRFBoJJRfuLMR8SlBYaNByyM21cHxMlAQTn/0hpPshNOOvEu/XAFOBz3cFIqU
CqTqc/sLUegTBxj6DvEr0VQVfTzh97QZQmdiXnfgolXsttlpF9U6r0TtSsWe5HonfOV116rLJeff
awrbD02TTqigzXsu8lkBarcNuAeBfos4GzjmCleZPe4h6KP1DBbdi+w0jpwqHAAVF41og9JwnxgI
zRFo1clrUs3ERo/ctfPYV3Me6ZQ5BL/T3jjetFPsaRyifsSP5BtwrfKi+fv3FmRmaZ9JUaLiFRhn
Bkp/1Wy1TbMz4GHrXb7pmA8y1x1LPC5aAVKRCfLf6o3YBkBjqhHk/sM3nhRSP/TizPJhk9H9Z2vX
Uq6/aKtAQ6BXNVN48FP4YUIHZMbXb5tMOA1jrGKvNouicwoN9SG9dKpN6nIDSdvHXx1iY8f93ZHs
M+71bbRuMGjeyNYmsHVee7QHIJihdjK4TWxPAgMBAAGjQjBAMA8GA1UdEwEB/wQFMAMBAf8wHQYD
VR0OBBYEFMmAd+BikoL1RpzzuvdMw964o605MA4GA1UdDwEB/wQEAwIBBjANBgkqhkiG9w0BAQsF
AAOCAgEAU18h9bqwOlI5LJKwbADJ784g7wbylp7ppHR/ehb8t/W2+xUbP6umwHJdELFx7rxP462s
A20ucS6vxOOto70MEae0/0qyexAQH6dXQbLArvQsWdZHEIjzIVEpMMpghq9Gqx3tOluwlN5E40EI
osHsHdb9T7bWR9AUC8rmyrV7d35BH16Dx7aMOZawP5aBQW9gkOLo+fsicdl9sz1Gv7SEr5AcD48S
aq/v7h56rgJKihcrdv6sVIkkLE8/trKnToyokZf7KcZ7XC25y2a2t6hbElGFtQl+Ynhw/qlqYLYd
DnkM/crqJIByw5c/8nerQyIKx+u2DISCLIBrQYoIwOula9+ZEsuK1V6ADJHgJgg2SMX6OBE1/yWD
LfJ6v9r9jv6ly0UsH8SIU653DtmadsWOLB2jutXsMq7Aqqz30XpN69QH4kj3Io6wpJ9qzo6ysmD0
oyLQI+uUWnpp3Q+/QFesa1lQ2aOZ4W7+jQF5JyMV3pKdewlNWudLSDBaGOYKbeaP4NK75t98biGC
wWg5TbSYWGZizEqQXsP6JwSxeRV0mcy+rSDeJmAc61ZRpqPq5KM/p/9h3PFaTWwyI0PurKju7koS
CTxdccK+efrCh2gdC/1cacwG0Jp9VJkqyTkaGa9LKkPzY11aWOIv4x3kqdbQCtCev9eBCfHJxyYN
rJgWVqA=
-----END CERTIFICATE-----

Buypass Class 3 Root CA
=======================
-----BEGIN CERTIFICATE-----
MIIFWTCCA0GgAwIBAgIBAjANBgkqhkiG9w0BAQsFADBOMQswCQYDVQQGEwJOTzEdMBsGA1UECgwU
QnV5cGFzcyBBUy05ODMxNjMzMjcxIDAeBgNVBAMMF0J1eXBhc3MgQ2xhc3MgMyBSb290IENBMB4X
DTEwMTAyNjA4Mjg1OFoXDTQwMTAyNjA4Mjg1OFowTjELMAkGA1UEBhMCTk8xHTAbBgNVBAoMFEJ1
eXBhc3MgQVMtOTgzMTYzMzI3MSAwHgYDVQQDDBdCdXlwYXNzIENsYXNzIDMgUm9vdCBDQTCCAiIw
DQYJKoZIhvcNAQEBBQADggIPADCCAgoCggIBAKXaCpUWUOOV8l6ddjEGMnqb8RB2uACatVI2zSRH
sJ8YZLya9vrVediQYkwiL944PdbgqOkcLNt4EemOaFEVcsfzM4fkoF0LXOBXByow9c3EN3coTRiR
5r/VUv1xLXA+58bEiuPwKAv0dpihi4dVsjoT/Lc+JzeOIuOoTyrvYLs9tznDDgFHmV0ST9tD+leh
7fmdvhFHJlsTmKtdFoqwNxxXnUX/iJY2v7vKB3tvh2PX0DJq1l1sDPGzbjniazEuOQAnFN44wOwZ
ZoYS6J1yFhNkUsepNxz9gjDthBgd9K5c/3ATAOux9TN6S9ZV+AWNS2mw9bMoNlwUxFFzTWsL8TQH
2xc519woe2v1n/MuwU8XKhDzzMro6/1rqy6any2CbgTUUgGTLT2G/H783+9CHaZr77kgxve9oKeV
/afmiSTYzIw0bOIjL9kSGiG5VZFvC5F5GQytQIgLcOJ60g7YaEi7ghM5EFjp2CoHxhLbWNvSO1UQ
RwUVZ2J+GGOmRj8JDlQyXr8NYnon74Do29lLBlo3WiXQCBJ31G8JUJc9yB3D34xFMFbG02SrZvPA
Xpacw8Tvw3xrizp5f7NJzz3iiZ+gMEuFuZyUJHmPfWupRWgPK9Dx2hzLabjKSWJtyNBjYt1gD1iq
j6G8BaVmos8bdrKEZLFMOVLAMLrwjEsCsLa3AgMBAAGjQjBAMA8GA1UdEwEB/wQFMAMBAf8wHQYD
VR0OBBYEFEe4zf/lb+74suwvTg75JbCOPGvDMA4GA1UdDwEB/wQEAwIBBjANBgkqhkiG9w0BAQsF
AAOCAgEAACAjQTUEkMJAYmDv4jVM1z+s4jSQuKFvdvoWFqRINyzpkMLyPPgKn9iB5btb2iUspKdV
cSQy9sgL8rxq+JOssgfCX5/bzMiKqr5qb+FJEMwx14C7u8jYog5kV+qi9cKpMRXSIGrs/CIBKM+G
uIAeqcwRpTzyFrNHnfzSgCHEy9BHcEGhyoMZCCxt8l13nIoUE9Q2HJLw5QY33KbmkJs4j1xrG0aG
Q0JfPgEHU1RdZX33inOhmlRaHylDFCfChQ+1iHsaO5S3HWCntZznKWlXWpuTekMwGwPXYshApqr8
ZORK15FTAaggiG6cX0S5y2CBNOxv033aSF/rtJC8LakcC6wc1aJoIIAE1vyxjy+7SjENSoYc6+I2
KSb12tjE8nVhz36udmNKekBlk4f4HoCMhuWG1o8O/FMsYOgWYRqiPkN7zTlgVGr18okmAWiDSKIz
6MkEkbIRNBE+6tBDGR8Dk5AM/1E9V/RBbuHLoL7ryWPNbczk+DaqaJ3tvV2XcEQNtg413OEMXbug
UZTLfhbrES+jkkXITHHZvMmZUldGL1DPvTVp9D0VzgalLA8+9oG6lLvDu79leNKGef9JOxqDDPDe
eOzI8k1MGt6CKfjBWtrt7uYnXuhF0J0cUahoq0Tj0Itq4/g7u9xN12TyUb7mqqta6THuBrxzvxNi
Cp/HuZc=
-----END CERTIFICATE-----

T-TeleSec GlobalRoot Class 3
============================
-----BEGIN CERTIFICATE-----
MIIDwzCCAqugAwIBAgIBATANBgkqhkiG9w0BAQsFADCBgjELMAkGA1UEBhMCREUxKzApBgNVBAoM
IlQtU3lzdGVtcyBFbnRlcnByaXNlIFNlcnZpY2VzIEdtYkgxHzAdBgNVBAsMFlQtU3lzdGVtcyBU
cnVzdCBDZW50ZXIxJTAjBgNVBAMMHFQtVGVsZVNlYyBHbG9iYWxSb290IENsYXNzIDMwHhcNMDgx
MDAxMTAyOTU2WhcNMzMxMDAxMjM1OTU5WjCBgjELMAkGA1UEBhMCREUxKzApBgNVBAoMIlQtU3lz
dGVtcyBFbnRlcnByaXNlIFNlcnZpY2VzIEdtYkgxHzAdBgNVBAsMFlQtU3lzdGVtcyBUcnVzdCBD
ZW50ZXIxJTAjBgNVBAMMHFQtVGVsZVNlYyBHbG9iYWxSb290IENsYXNzIDMwggEiMA0GCSqGSIb3
DQEBAQUAA4IBDwAwggEKAoIBAQC9dZPwYiJvJK7genasfb3ZJNW4t/zN8ELg63iIVl6bmlQdTQyK
9tPPcPRStdiTBONGhnFBSivwKixVA9ZIw+A5OO3yXDw/RLyTPWGrTs0NvvAgJ1gORH8EGoel15YU
NpDQSXuhdfsaa3Ox+M6pCSzyU9XDFES4hqX2iys52qMzVNn6chr3IhUciJFrf2blw2qAsCTz34ZF
iP0Zf3WHHx+xGwpzJFu5ZeAsVMhg02YXP+HMVDNzkQI6pn97djmiH5a2OK61yJN0HZ65tOVgnS9W
0eDrXltMEnAMbEQgqxHY9Bn20pxSN+f6tsIxO0rUFJmtxxr1XV/6B7h8DR/Wgx6zAgMBAAGjQjBA
MA8GA1UdEwEB/wQFMAMBAf8wDgYDVR0PAQH/BAQDAgEGMB0GA1UdDgQWBBS1A/d2O2GCahKqGFPr
AyGUv/7OyjANBgkqhkiG9w0BAQsFAAOCAQEAVj3vlNW92nOyWL6ukK2YJ5f+AbGwUgC4TeQbIXQb
fsDuXmkqJa9c1h3a0nnJ85cp4IaH3gRZD/FZ1GSFS5mvJQQeyUapl96Cshtwn5z2r3Ex3XsFpSzT
ucpH9sry9uetuUg/vBa3wW306gmv7PO15wWeph6KU1HWk4HMdJP2udqmJQV0eVp+QD6CSyYRMG7h
P0HHRwA11fXT91Q+gT3aSWqas+8QPebrb9HIIkfLzM8BMZLZGOMivgkeGj5asuRrDFR6fUNOuIml
e9eiPZaGzPImNC1qkp2aGtAw4l1OBLBfiyB+d8E9lYLRRpo7PHi4b6HQDWSieB4pTpPDpFQUWw==
-----END CERTIFICATE-----

EE Certification Centre Root CA
===============================
-----BEGIN CERTIFICATE-----
MIIEAzCCAuugAwIBAgIQVID5oHPtPwBMyonY43HmSjANBgkqhkiG9w0BAQUFADB1MQswCQYDVQQG
EwJFRTEiMCAGA1UECgwZQVMgU2VydGlmaXRzZWVyaW1pc2tlc2t1czEoMCYGA1UEAwwfRUUgQ2Vy
dGlmaWNhdGlvbiBDZW50cmUgUm9vdCBDQTEYMBYGCSqGSIb3DQEJARYJcGtpQHNrLmVlMCIYDzIw
MTAxMDMwMTAxMDMwWhgPMjAzMDEyMTcyMzU5NTlaMHUxCzAJBgNVBAYTAkVFMSIwIAYDVQQKDBlB
UyBTZXJ0aWZpdHNlZXJpbWlza2Vza3VzMSgwJgYDVQQDDB9FRSBDZXJ0aWZpY2F0aW9uIENlbnRy
ZSBSb290IENBMRgwFgYJKoZIhvcNAQkBFglwa2lAc2suZWUwggEiMA0GCSqGSIb3DQEBAQUAA4IB
DwAwggEKAoIBAQDIIMDs4MVLqwd4lfNE7vsLDP90jmG7sWLqI9iroWUyeuuOF0+W2Ap7kaJjbMeM
TC55v6kF/GlclY1i+blw7cNRfdCT5mzrMEvhvH2/UpvObntl8jixwKIy72KyaOBhU8E2lf/slLo2
rpwcpzIP5Xy0xm90/XsY6KxX7QYgSzIwWFv9zajmofxwvI6Sc9uXp3whrj3B9UiHbCe9nyV0gVWw
93X2PaRka9ZP585ArQ/dMtO8ihJTmMmJ+xAdTX7Nfh9WDSFwhfYggx/2uh8Ej+p3iDXE/+pOoYtN
P2MbRMNE1CV2yreN1x5KZmTNXMWcg+HCCIia7E6j8T4cLNlsHaFLAgMBAAGjgYowgYcwDwYDVR0T
AQH/BAUwAwEB/zAOBgNVHQ8BAf8EBAMCAQYwHQYDVR0OBBYEFBLyWj7qVhy/zQas8fElyalL1BSZ
MEUGA1UdJQQ+MDwGCCsGAQUFBwMCBggrBgEFBQcDAQYIKwYBBQUHAwMGCCsGAQUFBwMEBggrBgEF
BQcDCAYIKwYBBQUHAwkwDQYJKoZIhvcNAQEFBQADggEBAHv25MANqhlHt01Xo/6tu7Fq1Q+e2+Rj
xY6hUFaTlrg4wCQiZrxTFGGVv9DHKpY5P30osxBAIWrEr7BSdxjhlthWXePdNl4dp1BUoMUq5KqM
lIpPnTX/dqQGE5Gion0ARD9V04I8GtVbvFZMIi5GQ4okQC3zErg7cBqklrkar4dBGmoYDQZPxz5u
uSlNDUmJEYcyW+ZLBMjkXOZ0c5RdFpgTlf7727FE5TpwrDdr5rMzcijJs1eg9gIWiAYLtqZLICjU
3j2LrTcFU3T+bsy8QxdxXvnFzBqpYe73dgzzcvRyrc9yAjYHR8/vGVCJYMzpJJUPwssd8m92kMfM
dcGWxZ0=
-----END CERTIFICATE-----

TURKTRUST Certificate Services Provider Root 2007
=================================================
-----BEGIN CERTIFICATE-----
MIIEPTCCAyWgAwIBAgIBATANBgkqhkiG9w0BAQUFADCBvzE/MD0GA1UEAww2VMOcUktUUlVTVCBF
bGVrdHJvbmlrIFNlcnRpZmlrYSBIaXptZXQgU2HEn2xhecSxY8Sxc8SxMQswCQYDVQQGEwJUUjEP
MA0GA1UEBwwGQW5rYXJhMV4wXAYDVQQKDFVUw5xSS1RSVVNUIEJpbGdpIMSwbGV0acWfaW0gdmUg
QmlsacWfaW0gR8O8dmVubGnEn2kgSGl6bWV0bGVyaSBBLsWeLiAoYykgQXJhbMSxayAyMDA3MB4X
DTA3MTIyNTE4MzcxOVoXDTE3MTIyMjE4MzcxOVowgb8xPzA9BgNVBAMMNlTDnFJLVFJVU1QgRWxl
a3Ryb25payBTZXJ0aWZpa2EgSGl6bWV0IFNhxJ9sYXnEsWPEsXPEsTELMAkGA1UEBhMCVFIxDzAN
BgNVBAcMBkFua2FyYTFeMFwGA1UECgxVVMOcUktUUlVTVCBCaWxnaSDEsGxldGnFn2ltIHZlIEJp
bGnFn2ltIEfDvHZlbmxpxJ9pIEhpem1ldGxlcmkgQS7Fni4gKGMpIEFyYWzEsWsgMjAwNzCCASIw
DQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAKu3PgqMyKVYFeaK7yc9SrToJdPNM8Ig3BnuiD9N
YvDdE3ePYakqtdTyuTFYKTsvP2qcb3N2Je40IIDu6rfwxArNK4aUyeNgsURSsloptJGXg9i3phQv
KUmi8wUG+7RP2qFsmmaf8EMJyupyj+sA1zU511YXRxcw9L6/P8JorzZAwan0qafoEGsIiveGHtya
KhUG9qPw9ODHFNRRf8+0222vR5YXm3dx2KdxnSQM9pQ/hTEST7ruToK4uT6PIzdezKKqdfcYbwnT
rqdUKDT74eA7YH2gvnmJhsifLfkKS8RQouf9eRbHegsYz85M733WB2+Y8a+xwXrXgTW4qhe04MsC
AwEAAaNCMEAwHQYDVR0OBBYEFCnFkKslrxHkYb+j/4hhkeYO/pyBMA4GA1UdDwEB/wQEAwIBBjAP
BgNVHRMBAf8EBTADAQH/MA0GCSqGSIb3DQEBBQUAA4IBAQAQDdr4Ouwo0RSVgrESLFF6QSU2TJ/s
Px+EnWVUXKgWAkD6bho3hO9ynYYKVZ1WKKxmLNA6VpM0ByWtCLCPyA8JWcqdmBzlVPi5RX9ql2+I
aE1KBiY3iAIOtsbWcpnOa3faYjGkVh+uX4132l32iPwa2Z61gfAyuOOI0JzzaqC5mxRZNTZPz/OO
Xl0XrRWV2N2y1RVuAE6zS89mlOTgzbUF2mNXi+WzqtvALhyQRNsaXRik7r4EW5nVcV9VZWRi1aKb
BFmGyGJ353yCRWo9F7/snXUMrqNvWtMvmDb08PUZqxFdyKbjKlhqQgnDvZImZjINXQhVdP+MmNAK
poRq0Tl9
-----END CERTIFICATE-----

D-TRUST Root Class 3 CA 2 2009
==============================
-----BEGIN CERTIFICATE-----
MIIEMzCCAxugAwIBAgIDCYPzMA0GCSqGSIb3DQEBCwUAME0xCzAJBgNVBAYTAkRFMRUwEwYDVQQK
DAxELVRydXN0IEdtYkgxJzAlBgNVBAMMHkQtVFJVU1QgUm9vdCBDbGFzcyAzIENBIDIgMjAwOTAe
Fw0wOTExMDUwODM1NThaFw0yOTExMDUwODM1NThaME0xCzAJBgNVBAYTAkRFMRUwEwYDVQQKDAxE
LVRydXN0IEdtYkgxJzAlBgNVBAMMHkQtVFJVU1QgUm9vdCBDbGFzcyAzIENBIDIgMjAwOTCCASIw
DQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBANOySs96R+91myP6Oi/WUEWJNTrGa9v+2wBoqOAD
ER03UAifTUpolDWzU9GUY6cgVq/eUXjsKj3zSEhQPgrfRlWLJ23DEE0NkVJD2IfgXU42tSHKXzlA
BF9bfsyjxiupQB7ZNoTWSPOSHjRGICTBpFGOShrvUD9pXRl/RcPHAY9RySPocq60vFYJfxLLHLGv
KZAKyVXMD9O0Gu1HNVpK7ZxzBCHQqr0ME7UAyiZsxGsMlFqVlNpQmvH/pStmMaTJOKDfHR+4CS7z
p+hnUquVH+BGPtikw8paxTGA6Eian5Rp/hnd2HN8gcqW3o7tszIFZYQ05ub9VxC1X3a/L7AQDcUC
AwEAAaOCARowggEWMA8GA1UdEwEB/wQFMAMBAf8wHQYDVR0OBBYEFP3aFMSfMN4hvR5COfyrYyNJ
4PGEMA4GA1UdDwEB/wQEAwIBBjCB0wYDVR0fBIHLMIHIMIGAoH6gfIZ6bGRhcDovL2RpcmVjdG9y
eS5kLXRydXN0Lm5ldC9DTj1ELVRSVVNUJTIwUm9vdCUyMENsYXNzJTIwMyUyMENBJTIwMiUyMDIw
MDksTz1ELVRydXN0JTIwR21iSCxDPURFP2NlcnRpZmljYXRlcmV2b2NhdGlvbmxpc3QwQ6BBoD+G
PWh0dHA6Ly93d3cuZC10cnVzdC5uZXQvY3JsL2QtdHJ1c3Rfcm9vdF9jbGFzc18zX2NhXzJfMjAw
OS5jcmwwDQYJKoZIhvcNAQELBQADggEBAH+X2zDI36ScfSF6gHDOFBJpiBSVYEQBrLLpME+bUMJm
2H6NMLVwMeniacfzcNsgFYbQDfC+rAF1hM5+n02/t2A7nPPKHeJeaNijnZflQGDSNiH+0LS4F9p0
o3/U37CYAqxva2ssJSRyoWXuJVrl5jLn8t+rSfrzkGkj2wTZ51xY/GXUl77M/C4KzCUqNQT4YJEV
dT1B/yMfGchs64JTBKbkTCJNjYy6zltz7GRUUG3RnFX7acM2w4y8PIWmawomDeCTmGCufsYkl4ph
X5GOZpIJhzbNi5stPvZR1FDUWSi9g/LMKHtThm3YJohw1+qRzT65ysCQblrGXnRl11z+o+I=
-----END CERTIFICATE-----

D-TRUST Root Class 3 CA 2 EV 2009
=================================
-----BEGIN CERTIFICATE-----
MIIEQzCCAyugAwIBAgIDCYP0MA0GCSqGSIb3DQEBCwUAMFAxCzAJBgNVBAYTAkRFMRUwEwYDVQQK
DAxELVRydXN0IEdtYkgxKjAoBgNVBAMMIUQtVFJVU1QgUm9vdCBDbGFzcyAzIENBIDIgRVYgMjAw
OTAeFw0wOTExMDUwODUwNDZaFw0yOTExMDUwODUwNDZaMFAxCzAJBgNVBAYTAkRFMRUwEwYDVQQK
DAxELVRydXN0IEdtYkgxKjAoBgNVBAMMIUQtVFJVU1QgUm9vdCBDbGFzcyAzIENBIDIgRVYgMjAw
OTCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAJnxhDRwui+3MKCOvXwEz75ivJn9gpfS
egpnljgJ9hBOlSJzmY3aFS3nBfwZcyK3jpgAvDw9rKFs+9Z5JUut8Mxk2og+KbgPCdM03TP1YtHh
zRnp7hhPTFiu4h7WDFsVWtg6uMQYZB7jM7K1iXdODL/ZlGsTl28So/6ZqQTMFexgaDbtCHu39b+T
7WYxg4zGcTSHThfqr4uRjRxWQa4iN1438h3Z0S0NL2lRp75mpoo6Kr3HGrHhFPC+Oh25z1uxav60
sUYgovseO3Dvk5h9jHOW8sXvhXCtKSb8HgQ+HKDYD8tSg2J87otTlZCpV6LqYQXY+U3EJ/pure35
11H3a6UCAwEAAaOCASQwggEgMA8GA1UdEwEB/wQFMAMBAf8wHQYDVR0OBBYEFNOUikxiEyoZLsyv
cop9NteaHNxnMA4GA1UdDwEB/wQEAwIBBjCB3QYDVR0fBIHVMIHSMIGHoIGEoIGBhn9sZGFwOi8v
ZGlyZWN0b3J5LmQtdHJ1c3QubmV0L0NOPUQtVFJVU1QlMjBSb290JTIwQ2xhc3MlMjAzJTIwQ0El
MjAyJTIwRVYlMjAyMDA5LE89RC1UcnVzdCUyMEdtYkgsQz1ERT9jZXJ0aWZpY2F0ZXJldm9jYXRp
b25saXN0MEagRKBChkBodHRwOi8vd3d3LmQtdHJ1c3QubmV0L2NybC9kLXRydXN0X3Jvb3RfY2xh
c3NfM19jYV8yX2V2XzIwMDkuY3JsMA0GCSqGSIb3DQEBCwUAA4IBAQA07XtaPKSUiO8aEXUHL7P+
PPoeUSbrh/Yp3uDx1MYkCenBz1UbtDDZzhr+BlGmFaQt77JLvyAoJUnRpjZ3NOhk31KxEcdzes05
nsKtjHEh8lprr988TlWvsoRlFIm5d8sqMb7Po23Pb0iUMkZv53GMoKaEGTcH8gNFCSuGdXzfX2lX
ANtu2KZyIktQ1HWYVt+3GP9DQ1CuekR78HlR10M9p9OB0/DJT7naxpeG0ILD5EJt/rDiZE4OJudA
NCa1CInXCGNjOCd1HjPqbqjdn5lPdE2BiYBL3ZqXKVwvvoFBuYz/6n1gBp7N1z3TLqMVvKjmJuVv
w9y4AyHqnxbxLFS1
-----END CERTIFICATE-----

PSCProcert
==========
-----BEGIN CERTIFICATE-----
MIIJhjCCB26gAwIBAgIBCzANBgkqhkiG9w0BAQsFADCCAR4xPjA8BgNVBAMTNUF1dG9yaWRhZCBk
ZSBDZXJ0aWZpY2FjaW9uIFJhaXogZGVsIEVzdGFkbyBWZW5lem9sYW5vMQswCQYDVQQGEwJWRTEQ
MA4GA1UEBxMHQ2FyYWNhczEZMBcGA1UECBMQRGlzdHJpdG8gQ2FwaXRhbDE2MDQGA1UEChMtU2lz
dGVtYSBOYWNpb25hbCBkZSBDZXJ0aWZpY2FjaW9uIEVsZWN0cm9uaWNhMUMwQQYDVQQLEzpTdXBl
cmludGVuZGVuY2lhIGRlIFNlcnZpY2lvcyBkZSBDZXJ0aWZpY2FjaW9uIEVsZWN0cm9uaWNhMSUw
IwYJKoZIhvcNAQkBFhZhY3JhaXpAc3VzY2VydGUuZ29iLnZlMB4XDTEwMTIyODE2NTEwMFoXDTIw
MTIyNTIzNTk1OVowgdExJjAkBgkqhkiG9w0BCQEWF2NvbnRhY3RvQHByb2NlcnQubmV0LnZlMQ8w
DQYDVQQHEwZDaGFjYW8xEDAOBgNVBAgTB01pcmFuZGExKjAoBgNVBAsTIVByb3ZlZWRvciBkZSBD
ZXJ0aWZpY2Fkb3MgUFJPQ0VSVDE2MDQGA1UEChMtU2lzdGVtYSBOYWNpb25hbCBkZSBDZXJ0aWZp
Y2FjaW9uIEVsZWN0cm9uaWNhMQswCQYDVQQGEwJWRTETMBEGA1UEAxMKUFNDUHJvY2VydDCCAiIw
DQYJKoZIhvcNAQEBBQADggIPADCCAgoCggIBANW39KOUM6FGqVVhSQ2oh3NekS1wwQYalNo97BVC
wfWMrmoX8Yqt/ICV6oNEolt6Vc5Pp6XVurgfoCfAUFM+jbnADrgV3NZs+J74BCXfgI8Qhd19L3uA
3VcAZCP4bsm+lU/hdezgfl6VzbHvvnpC2Mks0+saGiKLt38GieU89RLAu9MLmV+QfI4tL3czkkoh
RqipCKzx9hEC2ZUWno0vluYC3XXCFCpa1sl9JcLB/KpnheLsvtF8PPqv1W7/U0HU9TI4seJfxPmO
EO8GqQKJ/+MMbpfg353bIdD0PghpbNjU5Db4g7ayNo+c7zo3Fn2/omnXO1ty0K+qP1xmk6wKImG2
0qCZyFSTXai20b1dCl53lKItwIKOvMoDKjSuc/HUtQy9vmebVOvh+qBa7Dh+PsHMosdEMXXqP+UH
0quhJZb25uSgXTcYOWEAM11G1ADEtMo88aKjPvM6/2kwLkDd9p+cJsmWN63nOaK/6mnbVSKVUyqU
td+tFjiBdWbjxywbk5yqjKPK2Ww8F22c3HxT4CAnQzb5EuE8XL1mv6JpIzi4mWCZDlZTOpx+FIyw
Bm/xhnaQr/2v/pDGj59/i5IjnOcVdo/Vi5QTcmn7K2FjiO/mpF7moxdqWEfLcU8UC17IAggmosvp
r2uKGcfLFFb14dq12fy/czja+eevbqQ34gcnAgMBAAGjggMXMIIDEzASBgNVHRMBAf8ECDAGAQH/
AgEBMDcGA1UdEgQwMC6CD3N1c2NlcnRlLmdvYi52ZaAbBgVghl4CAqASDBBSSUYtRy0yMDAwNDAz
Ni0wMB0GA1UdDgQWBBRBDxk4qpl/Qguk1yeYVKIXTC1RVDCCAVAGA1UdIwSCAUcwggFDgBStuyId
xuDSAaj9dlBSk+2YwU2u06GCASakggEiMIIBHjE+MDwGA1UEAxM1QXV0b3JpZGFkIGRlIENlcnRp
ZmljYWNpb24gUmFpeiBkZWwgRXN0YWRvIFZlbmV6b2xhbm8xCzAJBgNVBAYTAlZFMRAwDgYDVQQH
EwdDYXJhY2FzMRkwFwYDVQQIExBEaXN0cml0byBDYXBpdGFsMTYwNAYDVQQKEy1TaXN0ZW1hIE5h
Y2lvbmFsIGRlIENlcnRpZmljYWNpb24gRWxlY3Ryb25pY2ExQzBBBgNVBAsTOlN1cGVyaW50ZW5k
ZW5jaWEgZGUgU2VydmljaW9zIGRlIENlcnRpZmljYWNpb24gRWxlY3Ryb25pY2ExJTAjBgkqhkiG
9w0BCQEWFmFjcmFpekBzdXNjZXJ0ZS5nb2IudmWCAQowDgYDVR0PAQH/BAQDAgEGME0GA1UdEQRG
MESCDnByb2NlcnQubmV0LnZloBUGBWCGXgIBoAwMClBTQy0wMDAwMDKgGwYFYIZeAgKgEgwQUklG
LUotMzE2MzUzNzMtNzB2BgNVHR8EbzBtMEagRKBChkBodHRwOi8vd3d3LnN1c2NlcnRlLmdvYi52
ZS9sY3IvQ0VSVElGSUNBRE8tUkFJWi1TSEEzODRDUkxERVIuY3JsMCOgIaAfhh1sZGFwOi8vYWNy
YWl6LnN1c2NlcnRlLmdvYi52ZTA3BggrBgEFBQcBAQQrMCkwJwYIKwYBBQUHMAGGG2h0dHA6Ly9v
Y3NwLnN1c2NlcnRlLmdvYi52ZTBBBgNVHSAEOjA4MDYGBmCGXgMBAjAsMCoGCCsGAQUFBwIBFh5o
dHRwOi8vd3d3LnN1c2NlcnRlLmdvYi52ZS9kcGMwDQYJKoZIhvcNAQELBQADggIBACtZ6yKZu4Sq
T96QxtGGcSOeSwORR3C7wJJg7ODU523G0+1ng3dS1fLld6c2suNUvtm7CpsR72H0xpkzmfWvADmN
g7+mvTV+LFwxNG9s2/NkAZiqlCxB3RWGymspThbASfzXg0gTB1GEMVKIu4YXx2sviiCtxQuPcD4q
uxtxj7mkoP3YldmvWb8lK5jpY5MvYB7Eqvh39YtsL+1+LrVPQA3uvFd359m21D+VJzog1eWuq2w1
n8GhHVnchIHuTQfiSLaeS5UtQbHh6N5+LwUeaO6/u5BlOsju6rEYNxxik6SgMexxbJHmpHmJWhSn
FFAFTKQAVzAswbVhltw+HoSvOULP5dAssSS830DD7X9jSr3hTxJkhpXzsOfIt+FTvZLm8wyWuevo
5pLtp4EJFAv8lXrPj9Y0TzYS3F7RNHXGRoAvlQSMx4bEqCaJqD8Zm4G7UaRKhqsLEQ+xrmNTbSjq
3TNWOByyrYDT13K9mmyZY+gAu0F2BbdbmRiKw7gSXFbPVgx96OLP7bx0R/vu0xdOIk9W/1DzLuY5
poLWccret9W6aAjtmcz9opLLabid+Qqkpj5PkygqYWwHJgD/ll9ohri4zspV4KuxPX+Y1zMOWj3Y
eMLEYC/HYvBhkdI4sPaeVdtAgAUSM84dkpvRabP/v/GSCmE1P93+hvS84Bpxs2Km
-----END CERTIFICATE-----

China Internet Network Information Center EV Certificates Root
==============================================================
-----BEGIN CERTIFICATE-----
MIID9zCCAt+gAwIBAgIESJ8AATANBgkqhkiG9w0BAQUFADCBijELMAkGA1UEBhMCQ04xMjAwBgNV
BAoMKUNoaW5hIEludGVybmV0IE5ldHdvcmsgSW5mb3JtYXRpb24gQ2VudGVyMUcwRQYDVQQDDD5D
aGluYSBJbnRlcm5ldCBOZXR3b3JrIEluZm9ybWF0aW9uIENlbnRlciBFViBDZXJ0aWZpY2F0ZXMg
Um9vdDAeFw0xMDA4MzEwNzExMjVaFw0zMDA4MzEwNzExMjVaMIGKMQswCQYDVQQGEwJDTjEyMDAG
A1UECgwpQ2hpbmEgSW50ZXJuZXQgTmV0d29yayBJbmZvcm1hdGlvbiBDZW50ZXIxRzBFBgNVBAMM
PkNoaW5hIEludGVybmV0IE5ldHdvcmsgSW5mb3JtYXRpb24gQ2VudGVyIEVWIENlcnRpZmljYXRl
cyBSb290MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAm35z7r07eKpkQ0H1UN+U8i6y
jUqORlTSIRLIOTJCBumD1Z9S7eVnAztUwYyZmczpwA//DdmEEbK40ctb3B75aDFk4Zv6dOtouSCV
98YPjUesWgbdYavi7NifFy2cyjw1l1VxzUOFsUcW9SxTgHbP0wBkvUCZ3czY28Sf1hNfQYOL+Q2H
klY0bBoQCxfVWhyXWIQ8hBouXJE0bhlffxdpxWXvayHG1VA6v2G5BY3vbzQ6sm8UY78WO5upKv23
KzhmBsUs4qpnHkWnjQRmQvaPK++IIGmPMowUc9orhpFjIpryp9vOiYurXccUwVswah+xt54ugQEC
7c+WXmPbqOY4twIDAQABo2MwYTAfBgNVHSMEGDAWgBR8cks5x8DbYqVPm6oYNJKiyoOCWTAPBgNV
HRMBAf8EBTADAQH/MA4GA1UdDwEB/wQEAwIBBjAdBgNVHQ4EFgQUfHJLOcfA22KlT5uqGDSSosqD
glkwDQYJKoZIhvcNAQEFBQADggEBACrDx0M3j92tpLIM7twUbY8opJhJywyA6vPtI2Z1fcXTIWd5
0XPFtQO3WKwMVC/GVhMPMdoG52U7HW8228gd+f2ABsqjPWYWqJ1MFn3AlUa1UeTiH9fqBk1jjZaM
7+czV0I664zBechNdn3e9rG3geCg+aF4RhcaVpjwTj2rHO3sOdwHSPdj/gauwqRcalsyiMXHM4Ws
ZkJHwlgkmeHlPuV1LI5D1l08eB6olYIpUNHRFrrvwb562bTYzB5MRuF3sTGrvSrIzo9uoV1/A3U0
5K2JRVRevq4opbs/eHnrc7MKDf2+yfdWrPa37S+bISnHOLaVxATywy39FCqQmbkHzJ8=
-----END CERTIFICATE-----

Swisscom Root CA 2
==================
-----BEGIN CERTIFICATE-----
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
5OfNeOI5wSsSnqaeG8XmDtkx2Q==
-----END CERTIFICATE-----

Swisscom Root EV CA 2
=====================
-----BEGIN CERTIFICATE-----
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
l8PVP3wbI+2ksx0WckNLIOFZfsLorSa/ovc=
-----END CERTIFICATE-----

CA Disig Root R1
================
-----BEGIN CERTIFICATE-----
MIIFaTCCA1GgAwIBAgIJAMMDmu5QkG4oMA0GCSqGSIb3DQEBBQUAMFIxCzAJBgNVBAYTAlNLMRMw
EQYDVQQHEwpCcmF0aXNsYXZhMRMwEQYDVQQKEwpEaXNpZyBhLnMuMRkwFwYDVQQDExBDQSBEaXNp
ZyBSb290IFIxMB4XDTEyMDcxOTA5MDY1NloXDTQyMDcxOTA5MDY1NlowUjELMAkGA1UEBhMCU0sx
EzARBgNVBAcTCkJyYXRpc2xhdmExEzARBgNVBAoTCkRpc2lnIGEucy4xGTAXBgNVBAMTEENBIERp
c2lnIFJvb3QgUjEwggIiMA0GCSqGSIb3DQEBAQUAA4ICDwAwggIKAoICAQCqw3j33Jijp1pedxiy
3QRkD2P9m5YJgNXoqqXinCaUOuiZc4yd39ffg/N4T0Dhf9Kn0uXKE5Pn7cZ3Xza1lK/oOI7bm+V8
u8yN63Vz4STN5qctGS7Y1oprFOsIYgrY3LMATcMjfF9DCCMyEtztDK3AfQ+lekLZWnDZv6fXARz2
m6uOt0qGeKAeVjGu74IKgEH3G8muqzIm1Cxr7X1r5OJeIgpFy4QxTaz+29FHuvlglzmxZcfe+5nk
CiKxLU3lSCZpq+Kq8/v8kiky6bM+TR8noc2OuRf7JT7JbvN32g0S9l3HuzYQ1VTW8+DiR0jm3hTa
YVKvJrT1cU/J19IG32PK/yHoWQbgCNWEFVP3Q+V8xaCJmGtzxmjOZd69fwX3se72V6FglcXM6pM6
vpmumwKjrckWtc7dXpl4fho5frLABaTAgqWjR56M6ly2vGfb5ipN0gTco65F97yLnByn1tUD3AjL
LhbKXEAz6GfDLuemROoRRRw1ZS0eRWEkG4IupZ0zXWX4Qfkuy5Q/H6MMMSRE7cderVC6xkGbrPAX
ZcD4XW9boAo0PO7X6oifmPmvTiT6l7Jkdtqr9O3jw2Dv1fkCyC2fg69naQanMVXVz0tv/wQFx1is
XxYb5dKj6zHbHzMVTdDypVP1y+E9Tmgt2BLdqvLmTZtJ5cUoobqwWsagtQIDAQABo0IwQDAPBgNV
HRMBAf8EBTADAQH/MA4GA1UdDwEB/wQEAwIBBjAdBgNVHQ4EFgQUiQq0OJMa5qvum5EY+fU8PjXQ
04IwDQYJKoZIhvcNAQEFBQADggIBADKL9p1Kyb4U5YysOMo6CdQbzoaz3evUuii+Eq5FLAR0rBNR
xVgYZk2C2tXck8An4b58n1KeElb21Zyp9HWc+jcSjxyT7Ff+Bw+r1RL3D65hXlaASfX8MPWbTx9B
LxyE04nH4toCdu0Jz2zBuByDHBb6lM19oMgY0sidbvW9adRtPTXoHqJPYNcHKfyyo6SdbhWSVhlM
CrDpfNIZTUJG7L399ldb3Zh+pE3McgODWF3vkzpBemOqfDqo9ayk0d2iLbYq/J8BjuIQscTK5Gfb
VSUZP/3oNn6z4eGBrxEWi1CXYBmCAMBrTXO40RMHPuq2MU/wQppt4hF05ZSsjYSVPCGvxdpHyN85
YmLLW1AL14FABZyb7bq2ix4Eb5YgOe2kfSnbSM6C3NQCjR0EMVrHS/BsYVLXtFHCgWzN4funodKS
ds+xDzdYpPJScWc/DIh4gInByLUfkmO+p3qKViwaqKactV2zY9ATIKHrkWzQjX2v3wvkF7mGnjix
lAxYjOBVqjtjbZqJYLhkKpLGN/R+Q0O3c+gB53+XD9fyexn9GtePyfqFa3qdnom2piiZk4hA9z7N
UaPK6u95RyG1/jLix8NRb76AdPCkwzryT+lf3xkK8jsTQ6wxpLPn6/wY1gGp8yqPNg7rtLG8t0zJ
a7+h89n07eLw4+1knj0vllJPgFOL
-----END CERTIFICATE-----

CA Disig Root R2
================
-----BEGIN CERTIFICATE-----
MIIFaTCCA1GgAwIBAgIJAJK4iNuwisFjMA0GCSqGSIb3DQEBCwUAMFIxCzAJBgNVBAYTAlNLMRMw
EQYDVQQHEwpCcmF0aXNsYXZhMRMwEQYDVQQKEwpEaXNpZyBhLnMuMRkwFwYDVQQDExBDQSBEaXNp
ZyBSb290IFIyMB4XDTEyMDcxOTA5MTUzMFoXDTQyMDcxOTA5MTUzMFowUjELMAkGA1UEBhMCU0sx
EzARBgNVBAcTCkJyYXRpc2xhdmExEzARBgNVBAoTCkRpc2lnIGEucy4xGTAXBgNVBAMTEENBIERp
c2lnIFJvb3QgUjIwggIiMA0GCSqGSIb3DQEBAQUAA4ICDwAwggIKAoICAQCio8QACdaFXS1tFPbC
w3OeNcJxVX6B+6tGUODBfEl45qt5WDza/3wcn9iXAng+a0EE6UG9vgMsRfYvZNSrXaNHPWSb6Wia
xswbP7q+sos0Ai6YVRn8jG+qX9pMzk0DIaPY0jSTVpbLTAwAFjxfGs3Ix2ymrdMxp7zo5eFm1tL7
A7RBZckQrg4FY8aAamkw/dLukO8NJ9+flXP04SXabBbeQTg06ov80egEFGEtQX6sx3dOy1FU+16S
GBsEWmjGycT6txOgmLcRK7fWV8x8nhfRyyX+hk4kLlYMeE2eARKmK6cBZW58Yh2EhN/qwGu1pSqV
g8NTEQxzHQuyRpDRQjrOQG6Vrf/GlK1ul4SOfW+eioANSW1z4nuSHsPzwfPrLgVv2RvPN3YEyLRa
5Beny912H9AZdugsBbPWnDTYltxhh5EF5EQIM8HauQhl1K6yNg3ruji6DOWbnuuNZt2Zz9aJQfYE
koopKW1rOhzndX0CcQ7zwOe9yxndnWCywmZgtrEE7snmhrmaZkCo5xHtgUUDi/ZnWejBBhG93c+A
Ak9lQHhcR1DIm+YfgXvkRKhbhZri3lrVx/k6RGZL5DJUfORsnLMOPReisjQS1n6yqEm70XooQL6i
Fh/f5DcfEXP7kAplQ6INfPgGAVUzfbANuPT1rqVCV3w2EYx7XsQDnYx5nQIDAQABo0IwQDAPBgNV
HRMBAf8EBTADAQH/MA4GA1UdDwEB/wQEAwIBBjAdBgNVHQ4EFgQUtZn4r7CU9eMg1gqtzk5WpC5u
Qu0wDQYJKoZIhvcNAQELBQADggIBACYGXnDnZTPIgm7ZnBc6G3pmsgH2eDtpXi/q/075KMOYKmFM
tCQSin1tERT3nLXK5ryeJ45MGcipvXrA1zYObYVybqjGom32+nNjf7xueQgcnYqfGopTpti72TVV
sRHFqQOzVju5hJMiXn7B9hJSi+osZ7z+Nkz1uM/Rs0mSO9MpDpkblvdhuDvEK7Z4bLQjb/D907Je
dR+Zlais9trhxTF7+9FGs9K8Z7RiVLoJ92Owk6Ka+elSLotgEqv89WBW7xBci8QaQtyDW2QOy7W8
1k/BfDxujRNt+3vrMNDcTa/F1balTFtxyegxvug4BkihGuLq0t4SOVga/4AOgnXmt8kHbA7v/zjx
mHHEt38OFdAlab0inSvtBfZGR6ztwPDUO+Ls7pZbkBNOHlY667DvlruWIxG68kOGdGSVyCh13x01
utI3gzhTODY7z2zp+WsO0PsE6E9312UBeIYMej4hYvF/Y3EMyZ9E26gnonW+boE+18DrG5gPcFw0
sorMwIUY6256s/daoQe/qUKS82Ail+QUoQebTnbAjn39pCXHR+3/H3OszMOl6W8KjptlwlCFtaOg
UxLMVYdh84GuEEZhvUQhuMI9dM9+JDX6HAcOmz0iyu8xL4ysEr3vQCj8KWefshNPZiTEUxnpHikV
7+ZtsH8tZ/3zbBt1RqPlShfppNcL
-----END CERTIFICATE-----

ACCVRAIZ1
=========
-----BEGIN CERTIFICATE-----
MIIH0zCCBbugAwIBAgIIXsO3pkN/pOAwDQYJKoZIhvcNAQEFBQAwQjESMBAGA1UEAwwJQUNDVlJB
SVoxMRAwDgYDVQQLDAdQS0lBQ0NWMQ0wCwYDVQQKDARBQ0NWMQswCQYDVQQGEwJFUzAeFw0xMTA1
MDUwOTM3MzdaFw0zMDEyMzEwOTM3MzdaMEIxEjAQBgNVBAMMCUFDQ1ZSQUlaMTEQMA4GA1UECwwH
UEtJQUNDVjENMAsGA1UECgwEQUNDVjELMAkGA1UEBhMCRVMwggIiMA0GCSqGSIb3DQEBAQUAA4IC
DwAwggIKAoICAQCbqau/YUqXry+XZpp0X9DZlv3P4uRm7x8fRzPCRKPfmt4ftVTdFXxpNRFvu8gM
jmoYHtiP2Ra8EEg2XPBjs5BaXCQ316PWywlxufEBcoSwfdtNgM3802/J+Nq2DoLSRYWoG2ioPej0
RGy9ocLLA76MPhMAhN9KSMDjIgro6TenGEyxCQ0jVn8ETdkXhBilyNpAlHPrzg5XPAOBOp0KoVdD
aaxXbXmQeOW1tDvYvEyNKKGno6e6Ak4l0Squ7a4DIrhrIA8wKFSVf+DuzgpmndFALW4ir50awQUZ
0m/A8p/4e7MCQvtQqR0tkw8jq8bBD5L/0KIV9VMJcRz/RROE5iZe+OCIHAr8Fraocwa48GOEAqDG
WuzndN9wrqODJerWx5eHk6fGioozl2A3ED6XPm4pFdahD9GILBKfb6qkxkLrQaLjlUPTAYVtjrs7
8yM2x/474KElB0iryYl0/wiPgL/AlmXz7uxLaL2diMMxs0Dx6M/2OLuc5NF/1OVYm3z61PMOm3WR
5LpSLhl+0fXNWhn8ugb2+1KoS5kE3fj5tItQo05iifCHJPqDQsGH+tUtKSpacXpkatcnYGMN285J
9Y0fkIkyF/hzQ7jSWpOGYdbhdQrqeWZ2iE9x6wQl1gpaepPluUsXQA+xtrn13k/c4LOsOxFwYIRK
Q26ZIMApcQrAZQIDAQABo4ICyzCCAscwfQYIKwYBBQUHAQEEcTBvMEwGCCsGAQUFBzAChkBodHRw
Oi8vd3d3LmFjY3YuZXMvZmlsZWFkbWluL0FyY2hpdm9zL2NlcnRpZmljYWRvcy9yYWl6YWNjdjEu
Y3J0MB8GCCsGAQUFBzABhhNodHRwOi8vb2NzcC5hY2N2LmVzMB0GA1UdDgQWBBTSh7Tj3zcnk1X2
VuqB5TbMjB4/vTAPBgNVHRMBAf8EBTADAQH/MB8GA1UdIwQYMBaAFNKHtOPfNyeTVfZW6oHlNsyM
Hj+9MIIBcwYDVR0gBIIBajCCAWYwggFiBgRVHSAAMIIBWDCCASIGCCsGAQUFBwICMIIBFB6CARAA
QQB1AHQAbwByAGkAZABhAGQAIABkAGUAIABDAGUAcgB0AGkAZgBpAGMAYQBjAGkA8wBuACAAUgBh
AO0AegAgAGQAZQAgAGwAYQAgAEEAQwBDAFYAIAAoAEEAZwBlAG4AYwBpAGEAIABkAGUAIABUAGUA
YwBuAG8AbABvAGcA7QBhACAAeQAgAEMAZQByAHQAaQBmAGkAYwBhAGMAaQDzAG4AIABFAGwAZQBj
AHQAcgDzAG4AaQBjAGEALAAgAEMASQBGACAAUQA0ADYAMAAxADEANQA2AEUAKQAuACAAQwBQAFMA
IABlAG4AIABoAHQAdABwADoALwAvAHcAdwB3AC4AYQBjAGMAdgAuAGUAczAwBggrBgEFBQcCARYk
aHR0cDovL3d3dy5hY2N2LmVzL2xlZ2lzbGFjaW9uX2MuaHRtMFUGA1UdHwROMEwwSqBIoEaGRGh0
dHA6Ly93d3cuYWNjdi5lcy9maWxlYWRtaW4vQXJjaGl2b3MvY2VydGlmaWNhZG9zL3JhaXphY2N2
MV9kZXIuY3JsMA4GA1UdDwEB/wQEAwIBBjAXBgNVHREEEDAOgQxhY2N2QGFjY3YuZXMwDQYJKoZI
hvcNAQEFBQADggIBAJcxAp/n/UNnSEQU5CmH7UwoZtCPNdpNYbdKl02125DgBS4OxnnQ8pdpD70E
R9m+27Up2pvZrqmZ1dM8MJP1jaGo/AaNRPTKFpV8M9xii6g3+CfYCS0b78gUJyCpZET/LtZ1qmxN
YEAZSUNUY9rizLpm5U9EelvZaoErQNV/+QEnWCzI7UiRfD+mAM/EKXMRNt6GGT6d7hmKG9Ww7Y49
nCrADdg9ZuM8Db3VlFzi4qc1GwQA9j9ajepDvV+JHanBsMyZ4k0ACtrJJ1vnE5Bc5PUzolVt3OAJ
TS+xJlsndQAJxGJ3KQhfnlmstn6tn1QwIgPBHnFk/vk4CpYY3QIUrCPLBhwepH2NDd4nQeit2hW3
sCPdK6jT2iWH7ehVRE2I9DZ+hJp4rPcOVkkO1jMl1oRQQmwgEh0q1b688nCBpHBgvgW1m54ERL5h
I6zppSSMEYCUWqKiuUnSwdzRp+0xESyeGabu4VXhwOrPDYTkF7eifKXeVSUG7szAh1xA2syVP1Xg
Nce4hL60Xc16gwFy7ofmXx2utYXGJt/mwZrpHgJHnyqobalbz+xFd3+YJ5oyXSrjhO7FmGYvliAd
3djDJ9ew+f7Zfc3Qn48LFFhRny+Lwzgt3uiP1o2HpPVWQxaZLPSkVrQ0uGE3ycJYgBugl6H8WY3p
EfbRD0tVNEYqi4Y7
-----END CERTIFICATE-----

TWCA Global Root CA
===================
-----BEGIN CERTIFICATE-----
MIIFQTCCAymgAwIBAgICDL4wDQYJKoZIhvcNAQELBQAwUTELMAkGA1UEBhMCVFcxEjAQBgNVBAoT
CVRBSVdBTi1DQTEQMA4GA1UECxMHUm9vdCBDQTEcMBoGA1UEAxMTVFdDQSBHbG9iYWwgUm9vdCBD
QTAeFw0xMjA2MjcwNjI4MzNaFw0zMDEyMzExNTU5NTlaMFExCzAJBgNVBAYTAlRXMRIwEAYDVQQK
EwlUQUlXQU4tQ0ExEDAOBgNVBAsTB1Jvb3QgQ0ExHDAaBgNVBAMTE1RXQ0EgR2xvYmFsIFJvb3Qg
Q0EwggIiMA0GCSqGSIb3DQEBAQUAA4ICDwAwggIKAoICAQCwBdvI64zEbooh745NnHEKH1Jw7W2C
nJfF10xORUnLQEK1EjRsGcJ0pDFfhQKX7EMzClPSnIyOt7h52yvVavKOZsTuKwEHktSz0ALfUPZV
r2YOy+BHYC8rMjk1Ujoog/h7FsYYuGLWRyWRzvAZEk2tY/XTP3VfKfChMBwqoJimFb3u/Rk28OKR
Q4/6ytYQJ0lM793B8YVwm8rqqFpD/G2Gb3PpN0Wp8DbHzIh1HrtsBv+baz4X7GGqcXzGHaL3SekV
tTzWoWH1EfcFbx39Eb7QMAfCKbAJTibc46KokWofwpFFiFzlmLhxpRUZyXx1EcxwdE8tmx2RRP1W
KKD+u4ZqyPpcC1jcxkt2yKsi2XMPpfRaAok/T54igu6idFMqPVMnaR1sjjIsZAAmY2E2TqNGtz99
sy2sbZCilaLOz9qC5wc0GZbpuCGqKX6mOL6OKUohZnkfs8O1CWfe1tQHRvMq2uYiN2DLgbYPoA/p
yJV/v1WRBXrPPRXAb94JlAGD1zQbzECl8LibZ9WYkTunhHiVJqRaCPgrdLQABDzfuBSO6N+pjWxn
kjMdwLfS7JLIvgm/LCkFbwJrnu+8vyq8W8BQj0FwcYeyTbcEqYSjMq+u7msXi7Kx/mzhkIyIqJdI
zshNy/MGz19qCkKxHh53L46g5pIOBvwFItIm4TFRfTLcDwIDAQABoyMwITAOBgNVHQ8BAf8EBAMC
AQYwDwYDVR0TAQH/BAUwAwEB/zANBgkqhkiG9w0BAQsFAAOCAgEAXzSBdu+WHdXltdkCY4QWwa6g
cFGn90xHNcgL1yg9iXHZqjNB6hQbbCEAwGxCGX6faVsgQt+i0trEfJdLjbDorMjupWkEmQqSpqsn
LhpNgb+E1HAerUf+/UqdM+DyucRFCCEK2mlpc3INvjT+lIutwx4116KD7+U4x6WFH6vPNOw/KP4M
8VeGTslV9xzU2KV9Bnpv1d8Q34FOIWWxtuEXeZVFBs5fzNxGiWNoRI2T9GRwoD2dKAXDOXC4Ynsg
/eTb6QihuJ49CcdP+yz4k3ZB3lLg4VfSnQO8d57+nile98FRYB/e2guyLXW3Q0iT5/Z5xoRdgFlg
lPx4mI88k1HtQJAH32RjJMtOcQWh15QaiDLxInQirqWm2BJpTGCjAu4r7NRjkgtevi92a6O2JryP
A9gK8kxkRr05YuWW6zRjESjMlfGt7+/cgFhI6Uu46mWs6fyAtbXIRfmswZ/ZuepiiI7E8UuDEq3m
i4TWnsLrgxifarsbJGAzcMzs9zLzXNl5fe+epP7JI8Mk7hWSsT2RTyaGvWZzJBPqpK5jwa19hAM8
EHiGG3njxPPyBJUgriOCxLM6AGK/5jYk4Ve6xx6QddVfP5VhK8E7zeWzaGHQRiapIVJpLesux+t3
zqY6tQMzT3bR51xUAV3LePTJDL/PEo4XLSNolOer/qmyKwbQBM0=
-----END CERTIFICATE-----

TeliaSonera Root CA v1
======================
-----BEGIN CERTIFICATE-----
MIIFODCCAyCgAwIBAgIRAJW+FqD3LkbxezmCcvqLzZYwDQYJKoZIhvcNAQEFBQAwNzEUMBIGA1UE
CgwLVGVsaWFTb25lcmExHzAdBgNVBAMMFlRlbGlhU29uZXJhIFJvb3QgQ0EgdjEwHhcNMDcxMDE4
MTIwMDUwWhcNMzIxMDE4MTIwMDUwWjA3MRQwEgYDVQQKDAtUZWxpYVNvbmVyYTEfMB0GA1UEAwwW
VGVsaWFTb25lcmEgUm9vdCBDQSB2MTCCAiIwDQYJKoZIhvcNAQEBBQADggIPADCCAgoCggIBAMK+
6yfwIaPzaSZVfp3FVRaRXP3vIb9TgHot0pGMYzHw7CTww6XScnwQbfQ3t+XmfHnqjLWCi65ItqwA
3GV17CpNX8GH9SBlK4GoRz6JI5UwFpB/6FcHSOcZrr9FZ7E3GwYq/t75rH2D+1665I+XZ75Ljo1k
B1c4VWk0Nj0TSO9P4tNmHqTPGrdeNjPUtAa9GAH9d4RQAEX1jF3oI7x+/jXh7VB7qTCNGdMJjmhn
Xb88lxhTuylixcpecsHHltTbLaC0H2kD7OriUPEMPPCs81Mt8Bz17Ww5OXOAFshSsCPN4D7c3TxH
oLs1iuKYaIu+5b9y7tL6pe0S7fyYGKkmdtwoSxAgHNN/Fnct7W+A90m7UwW7XWjH1Mh1Fj+JWov3
F0fUTPHSiXk+TT2YqGHeOh7S+F4D4MHJHIzTjU3TlTazN19jY5szFPAtJmtTfImMMsJu7D0hADnJ
oWjiUIMusDor8zagrC/kb2HCUQk5PotTubtn2txTuXZZNp1D5SDgPTJghSJRt8czu90VL6R4pgd7
gUY2BIbdeTXHlSw7sKMXNeVzH7RcWe/a6hBle3rQf5+ztCo3O3CLm1u5K7fsslESl1MpWtTwEhDc
TwK7EpIvYtQ/aUN8Ddb8WHUBiJ1YFkveupD/RwGJBmr2X7KQarMCpgKIv7NHfirZ1fpoeDVNAgMB
AAGjPzA9MA8GA1UdEwEB/wQFMAMBAf8wCwYDVR0PBAQDAgEGMB0GA1UdDgQWBBTwj1k4ALP1j5qW
DNXr+nuqF+gTEjANBgkqhkiG9w0BAQUFAAOCAgEAvuRcYk4k9AwI//DTDGjkk0kiP0Qnb7tt3oNm
zqjMDfz1mgbldxSR651Be5kqhOX//CHBXfDkH1e3damhXwIm/9fH907eT/j3HEbAek9ALCI18Bmx
0GtnLLCo4MBANzX2hFxc469CeP6nyQ1Q6g2EdvZR74NTxnr/DlZJLo961gzmJ1TjTQpgcmLNkQfW
pb/ImWvtxBnmq0wROMVvMeJuScg/doAmAyYp4Db29iBT4xdwNBedY2gea+zDTYa4EzAvXUYNR0PV
G6pZDrlcjQZIrXSHX8f8MVRBE+LHIQ6e4B4N4cB7Q4WQxYpYxmUKeFfyxiMPAdkgS94P+5KFdSpc
c41teyWRyu5FrgZLAMzTsVlQ2jqIOylDRl6XK1TOU2+NSueW+r9xDkKLfP0ooNBIytrEgUy7onOT
JsjrDNYmiLbAJM+7vVvrdX3pCI6GMyx5dwlppYn8s3CQh3aP0yK7Qs69cwsgJirQmz1wHiRszYd2
qReWt88NkvuOGKmYSdGe/mBEciG5Ge3C9THxOUiIkCR1VBatzvT4aRRkOfujuLpwQMcnHL/EVlP6
Y2XQ8xwOFvVrhlhNGNTkDY6lnVuR3HYkUD/GKvvZt5y11ubQ2egZixVxSK236thZiNSQvxaz2ems
WWFUyBy6ysHK4bkgTI86k4mloMy/0/Z1pHWWbVY=
-----END CERTIFICATE-----

E-Tugra Certification Authority
===============================
-----BEGIN CERTIFICATE-----
MIIGSzCCBDOgAwIBAgIIamg+nFGby1MwDQYJKoZIhvcNAQELBQAwgbIxCzAJBgNVBAYTAlRSMQ8w
DQYDVQQHDAZBbmthcmExQDA+BgNVBAoMN0UtVHXEn3JhIEVCRyBCaWxpxZ9pbSBUZWtub2xvamls
ZXJpIHZlIEhpem1ldGxlcmkgQS7Fni4xJjAkBgNVBAsMHUUtVHVncmEgU2VydGlmaWthc3lvbiBN
ZXJrZXppMSgwJgYDVQQDDB9FLVR1Z3JhIENlcnRpZmljYXRpb24gQXV0aG9yaXR5MB4XDTEzMDMw
NTEyMDk0OFoXDTIzMDMwMzEyMDk0OFowgbIxCzAJBgNVBAYTAlRSMQ8wDQYDVQQHDAZBbmthcmEx
QDA+BgNVBAoMN0UtVHXEn3JhIEVCRyBCaWxpxZ9pbSBUZWtub2xvamlsZXJpIHZlIEhpem1ldGxl
cmkgQS7Fni4xJjAkBgNVBAsMHUUtVHVncmEgU2VydGlmaWthc3lvbiBNZXJrZXppMSgwJgYDVQQD
DB9FLVR1Z3JhIENlcnRpZmljYXRpb24gQXV0aG9yaXR5MIICIjANBgkqhkiG9w0BAQEFAAOCAg8A
MIICCgKCAgEA4vU/kwVRHoViVF56C/UYB4Oufq9899SKa6VjQzm5S/fDxmSJPZQuVIBSOTkHS0vd
hQd2h8y/L5VMzH2nPbxHD5hw+IyFHnSOkm0bQNGZDbt1bsipa5rAhDGvykPL6ys06I+XawGb1Q5K
CKpbknSFQ9OArqGIW66z6l7LFpp3RMih9lRozt6Plyu6W0ACDGQXwLWTzeHxE2bODHnv0ZEoq1+g
ElIwcxmOj+GMB6LDu0rw6h8VqO4lzKRG+Bsi77MOQ7osJLjFLFzUHPhdZL3Dk14opz8n8Y4e0ypQ
BaNV2cvnOVPAmJ6MVGKLJrD3fY185MaeZkJVgkfnsliNZvcHfC425lAcP9tDJMW/hkd5s3kc91r0
E+xs+D/iWR+V7kI+ua2oMoVJl0b+SzGPWsutdEcf6ZG33ygEIqDUD13ieU/qbIWGvaimzuT6w+Gz
rt48Ue7LE3wBf4QOXVGUnhMMti6lTPk5cDZvlsouDERVxcr6XQKj39ZkjFqzAQqptQpHF//vkUAq
jqFGOjGY5RH8zLtJVor8udBhmm9lbObDyz51Sf6Pp+KJxWfXnUYTTjF2OySznhFlhqt/7x3U+Lzn
rFpct1pHXFXOVbQicVtbC/DP3KBhZOqp12gKY6fgDT+gr9Oq0n7vUaDmUStVkhUXU8u3Zg5mTPj5
dUyQ5xJwx0UCAwEAAaNjMGEwHQYDVR0OBBYEFC7j27JJ0JxUeVz6Jyr+zE7S6E5UMA8GA1UdEwEB
/wQFMAMBAf8wHwYDVR0jBBgwFoAULuPbsknQnFR5XPonKv7MTtLoTlQwDgYDVR0PAQH/BAQDAgEG
MA0GCSqGSIb3DQEBCwUAA4ICAQAFNzr0TbdF4kV1JI+2d1LoHNgQk2Xz8lkGpD4eKexd0dCrfOAK
kEh47U6YA5n+KGCRHTAduGN8qOY1tfrTYXbm1gdLymmasoR6d5NFFxWfJNCYExL/u6Au/U5Mh/jO
XKqYGwXgAEZKgoClM4so3O0409/lPun++1ndYYRP0lSWE2ETPo+Aab6TR7U1Q9Jauz1c77NCR807
VRMGsAnb/WP2OogKmW9+4c4bU2pEZiNRCHu8W1Ki/QY3OEBhj0qWuJA3+GbHeJAAFS6LrVE1Uweo
a2iu+U48BybNCAVwzDk/dr2l02cmAYamU9JgO3xDf1WKvJUawSg5TB9D0pH0clmKuVb8P7Sd2nCc
dlqMQ1DujjByTd//SffGqWfZbawCEeI6FiWnWAjLb1NBnEg4R2gz0dfHj9R0IdTDBZB6/86WiLEV
KV0jq9BgoRJP3vQXzTLlyb/IQ639Lo7xr+L0mPoSHyDYwKcMhcWQ9DstliaxLL5Mq+ux0orJ23gT
Dx4JnW2PAJ8C2sH6H3p6CcRK5ogql5+Ji/03X186zjhZhkuvcQu02PJwT58yE+Owp1fl2tpDy4Q0
8ijE6m30Ku/Ba3ba+367hTzSU8JNvnHhRdH9I2cNE3X7z2VnIp2usAnRCf8dNL/+I5c30jn6PQ0G
C7TbO6Orb1wdtn7os4I07QZcJA==
-----END CERTIFICATE-----

T-TeleSec GlobalRoot Class 2
============================
-----BEGIN CERTIFICATE-----
MIIDwzCCAqugAwIBAgIBATANBgkqhkiG9w0BAQsFADCBgjELMAkGA1UEBhMCREUxKzApBgNVBAoM
IlQtU3lzdGVtcyBFbnRlcnByaXNlIFNlcnZpY2VzIEdtYkgxHzAdBgNVBAsMFlQtU3lzdGVtcyBU
cnVzdCBDZW50ZXIxJTAjBgNVBAMMHFQtVGVsZVNlYyBHbG9iYWxSb290IENsYXNzIDIwHhcNMDgx
MDAxMTA0MDE0WhcNMzMxMDAxMjM1OTU5WjCBgjELMAkGA1UEBhMCREUxKzApBgNVBAoMIlQtU3lz
dGVtcyBFbnRlcnByaXNlIFNlcnZpY2VzIEdtYkgxHzAdBgNVBAsMFlQtU3lzdGVtcyBUcnVzdCBD
ZW50ZXIxJTAjBgNVBAMMHFQtVGVsZVNlYyBHbG9iYWxSb290IENsYXNzIDIwggEiMA0GCSqGSIb3
DQEBAQUAA4IBDwAwggEKAoIBAQCqX9obX+hzkeXaXPSi5kfl82hVYAUdAqSzm1nzHoqvNK38DcLZ
SBnuaY/JIPwhqgcZ7bBcrGXHX+0CfHt8LRvWurmAwhiCFoT6ZrAIxlQjgeTNuUk/9k9uN0goOA/F
vudocP05l03Sx5iRUKrERLMjfTlH6VJi1hKTXrcxlkIF+3anHqP1wvzpesVsqXFP6st4vGCvx970
2cu+fjOlbpSD8DT6IavqjnKgP6TeMFvvhk1qlVtDRKgQFRzlAVfFmPHmBiiRqiDFt1MmUUOyCxGV
WOHAD3bZwI18gfNycJ5v/hqO2V81xrJvNHy+SE/iWjnX2J14np+GPgNeGYtEotXHAgMBAAGjQjBA
MA8GA1UdEwEB/wQFMAMBAf8wDgYDVR0PAQH/BAQDAgEGMB0GA1UdDgQWBBS/WSA2AHmgoCJrjNXy
YdK4LMuCSjANBgkqhkiG9w0BAQsFAAOCAQEAMQOiYQsfdOhyNsZt+U2e+iKo4YFWz827n+qrkRk4
r6p8FU3ztqONpfSO9kSpp+ghla0+AGIWiPACuvxhI+YzmzB6azZie60EI4RYZeLbK4rnJVM3YlNf
vNoBYimipidx5joifsFvHZVwIEoHNN/q/xWA5brXethbdXwFeilHfkCoMRN3zUA7tFFHei4R40cR
3p1m0IvVVGb6g1XqfMIpiRvpb7PO4gWEyS8+eIVibslfwXhjdFjASBgMmTnrpMwatXlajRWc2BQN
9noHV8cigwUtPJslJj0Ys6lDfMjIq2SPDqO/nBudMNva0Bkuqjzx+zOAduTNrRlPBSeOE6Fuwg==
-----END CERTIFICATE-----

Atos TrustedRoot 2011
=====================
-----BEGIN CERTIFICATE-----
MIIDdzCCAl+gAwIBAgIIXDPLYixfszIwDQYJKoZIhvcNAQELBQAwPDEeMBwGA1UEAwwVQXRvcyBU
cnVzdGVkUm9vdCAyMDExMQ0wCwYDVQQKDARBdG9zMQswCQYDVQQGEwJERTAeFw0xMTA3MDcxNDU4
MzBaFw0zMDEyMzEyMzU5NTlaMDwxHjAcBgNVBAMMFUF0b3MgVHJ1c3RlZFJvb3QgMjAxMTENMAsG
A1UECgwEQXRvczELMAkGA1UEBhMCREUwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQCV
hTuXbyo7LjvPpvMpNb7PGKw+qtn4TaA+Gke5vJrf8v7MPkfoepbCJI419KkM/IL9bcFyYie96mvr
54rMVD6QUM+A1JX76LWC1BTFtqlVJVfbsVD2sGBkWXppzwO3bw2+yj5vdHLqqjAqc2K+SZFhyBH+
DgMq92og3AIVDV4VavzjgsG1xZ1kCWyjWZgHJ8cblithdHFsQ/H3NYkQ4J7sVaE3IqKHBAUsR320
HLliKWYoyrfhk/WklAOZuXCFteZI6o1Q/NnezG8HDt0Lcp2AMBYHlT8oDv3FdU9T1nSatCQujgKR
z3bFmx5VdJx4IbHwLfELn8LVlhgf8FQieowHAgMBAAGjfTB7MB0GA1UdDgQWBBSnpQaxLKYJYO7R
l+lwrrw7GWzbITAPBgNVHRMBAf8EBTADAQH/MB8GA1UdIwQYMBaAFKelBrEspglg7tGX6XCuvDsZ
bNshMBgGA1UdIAQRMA8wDQYLKwYBBAGwLQMEAQEwDgYDVR0PAQH/BAQDAgGGMA0GCSqGSIb3DQEB
CwUAA4IBAQAmdzTblEiGKkGdLD4GkGDEjKwLVLgfuXvTBznk+j57sj1O7Z8jvZfza1zv7v1Apt+h
k6EKhqzvINB5Ab149xnYJDE0BAGmuhWawyfc2E8PzBhj/5kPDpFrdRbhIfzYJsdHt6bPWHJxfrrh
TZVHO8mvbaG0weyJ9rQPOLXiZNwlz6bb65pcmaHFCN795trV1lpFDMS3wrUU77QR/w4VtfX128a9
61qn8FYiqTxlVMYVqL2Gns2Dlmh6cYGJ4Qvh6hEbaAjMaZ7snkGeRDImeuKHCnE96+RapNLbxc3G
3mB/ufNPRJLvKrcYPqcZ2Qt9sTdBQrC6YB3y/gkRsPCHe6ed
-----END CERTIFICATE-----

QuoVadis Root CA 1 G3
=====================
-----BEGIN CERTIFICATE-----
MIIFYDCCA0igAwIBAgIUeFhfLq0sGUvjNwc1NBMotZbUZZMwDQYJKoZIhvcNAQELBQAwSDELMAkG
A1UEBhMCQk0xGTAXBgNVBAoTEFF1b1ZhZGlzIExpbWl0ZWQxHjAcBgNVBAMTFVF1b1ZhZGlzIFJv
b3QgQ0EgMSBHMzAeFw0xMjAxMTIxNzI3NDRaFw00MjAxMTIxNzI3NDRaMEgxCzAJBgNVBAYTAkJN
MRkwFwYDVQQKExBRdW9WYWRpcyBMaW1pdGVkMR4wHAYDVQQDExVRdW9WYWRpcyBSb290IENBIDEg
RzMwggIiMA0GCSqGSIb3DQEBAQUAA4ICDwAwggIKAoICAQCgvlAQjunybEC0BJyFuTHK3C3kEakE
PBtVwedYMB0ktMPvhd6MLOHBPd+C5k+tR4ds7FtJwUrVu4/sh6x/gpqG7D0DmVIB0jWerNrwU8lm
PNSsAgHaJNM7qAJGr6Qc4/hzWHa39g6QDbXwz8z6+cZM5cOGMAqNF34168Xfuw6cwI2H44g4hWf6
Pser4BOcBRiYz5P1sZK0/CPTz9XEJ0ngnjybCKOLXSoh4Pw5qlPafX7PGglTvF0FBM+hSo+LdoIN
ofjSxxR3W5A2B4GbPgb6Ul5jxaYA/qXpUhtStZI5cgMJYr2wYBZupt0lwgNm3fME0UDiTouG9G/l
g6AnhF4EwfWQvTA9xO+oabw4m6SkltFi2mnAAZauy8RRNOoMqv8hjlmPSlzkYZqn0ukqeI1RPToV
7qJZjqlc3sX5kCLliEVx3ZGZbHqfPT2YfF72vhZooF6uCyP8Wg+qInYtyaEQHeTTRCOQiJ/GKubX
9ZqzWB4vMIkIG1SitZgj7Ah3HJVdYdHLiZxfokqRmu8hqkkWCKi9YSgxyXSthfbZxbGL0eUQMk1f
iyA6PEkfM4VZDdvLCXVDaXP7a3F98N/ETH3Goy7IlXnLc6KOTk0k+17kBL5yG6YnLUlamXrXXAkg
t3+UuU/xDRxeiEIbEbfnkduebPRq34wGmAOtzCjvpUfzUwIDAQABo0IwQDAPBgNVHRMBAf8EBTAD
AQH/MA4GA1UdDwEB/wQEAwIBBjAdBgNVHQ4EFgQUo5fW816iEOGrRZ88F2Q87gFwnMwwDQYJKoZI
hvcNAQELBQADggIBABj6W3X8PnrHX3fHyt/PX8MSxEBd1DKquGrX1RUVRpgjpeaQWxiZTOOtQqOC
MTaIzen7xASWSIsBx40Bz1szBpZGZnQdT+3Btrm0DWHMY37XLneMlhwqI2hrhVd2cDMT/uFPpiN3
GPoajOi9ZcnPP/TJF9zrx7zABC4tRi9pZsMbj/7sPtPKlL92CiUNqXsCHKnQO18LwIE6PWThv6ct
Tr1NxNgpxiIY0MWscgKCP6o6ojoilzHdCGPDdRS5YCgtW2jgFqlmgiNR9etT2DGbe+m3nUvriBbP
+V04ikkwj+3x6xn0dxoxGE1nVGwvb2X52z3sIexe9PSLymBlVNFxZPT5pqOBMzYzcfCkeF9OrYMh
3jRJjehZrJ3ydlo28hP0r+AJx2EqbPfgna67hkooby7utHnNkDPDs3b69fBsnQGQ+p6Q9pxyz0fa
wx/kNSBT8lTR32GDpgLiJTjehTItXnOQUl1CxM49S+H5GYQd1aJQzEH7QRTDvdbJWqNjZgKAvQU6
O0ec7AAmTPWIUb+oI38YB7AL7YsmoWTTYUrrXJ/es69nA7Mf3W1daWhpq1467HxpvMc7hU6eFbm0
FU/DlXpY18ls6Wy58yljXrQs8C097Vpl4KlbQMJImYFtnh8GKjwStIsPm6Ik8KaN1nrgS7ZklmOV
hMJKzRwuJIczYOXD
-----END CERTIFICATE-----

QuoVadis Root CA 2 G3
=====================
-----BEGIN CERTIFICATE-----
MIIFYDCCA0igAwIBAgIURFc0JFuBiZs18s64KztbpybwdSgwDQYJKoZIhvcNAQELBQAwSDELMAkG
A1UEBhMCQk0xGTAXBgNVBAoTEFF1b1ZhZGlzIExpbWl0ZWQxHjAcBgNVBAMTFVF1b1ZhZGlzIFJv
b3QgQ0EgMiBHMzAeFw0xMjAxMTIxODU5MzJaFw00MjAxMTIxODU5MzJaMEgxCzAJBgNVBAYTAkJN
MRkwFwYDVQQKExBRdW9WYWRpcyBMaW1pdGVkMR4wHAYDVQQDExVRdW9WYWRpcyBSb290IENBIDIg
RzMwggIiMA0GCSqGSIb3DQEBAQUAA4ICDwAwggIKAoICAQChriWyARjcV4g/Ruv5r+LrI3HimtFh
ZiFfqq8nUeVuGxbULX1QsFN3vXg6YOJkApt8hpvWGo6t/x8Vf9WVHhLL5hSEBMHfNrMWn4rjyduY
NM7YMxcoRvynyfDStNVNCXJJ+fKH46nafaF9a7I6JaltUkSs+L5u+9ymc5GQYaYDFCDy54ejiK2t
oIz/pgslUiXnFgHVy7g1gQyjO/Dh4fxaXc6AcW34Sas+O7q414AB+6XrW7PFXmAqMaCvN+ggOp+o
MiwMzAkd056OXbxMmO7FGmh77FOm6RQ1o9/NgJ8MSPsc9PG/Srj61YxxSscfrf5BmrODXfKEVu+l
V0POKa2Mq1W/xPtbAd0jIaFYAI7D0GoT7RPjEiuA3GfmlbLNHiJuKvhB1PLKFAeNilUSxmn1uIZo
L1NesNKqIcGY5jDjZ1XHm26sGahVpkUG0CM62+tlXSoREfA7T8pt9DTEceT/AFr2XK4jYIVz8eQQ
sSWu1ZK7E8EM4DnatDlXtas1qnIhO4M15zHfeiFuuDIIfR0ykRVKYnLP43ehvNURG3YBZwjgQQvD
6xVu+KQZ2aKrr+InUlYrAoosFCT5v0ICvybIxo/gbjh9Uy3l7ZizlWNof/k19N+IxWA1ksB8aRxh
lRbQ694Lrz4EEEVlWFA4r0jyWbYW8jwNkALGcC4BrTwV1wIDAQABo0IwQDAPBgNVHRMBAf8EBTAD
AQH/MA4GA1UdDwEB/wQEAwIBBjAdBgNVHQ4EFgQU7edvdlq/YOxJW8ald7tyFnGbxD0wDQYJKoZI
hvcNAQELBQADggIBAJHfgD9DCX5xwvfrs4iP4VGyvD11+ShdyLyZm3tdquXK4Qr36LLTn91nMX66
AarHakE7kNQIXLJgapDwyM4DYvmL7ftuKtwGTTwpD4kWilhMSA/ohGHqPHKmd+RCroijQ1h5fq7K
pVMNqT1wvSAZYaRsOPxDMuHBR//47PERIjKWnML2W2mWeyAMQ0GaW/ZZGYjeVYg3UQt4XAoeo0L9
x52ID8DyeAIkVJOviYeIyUqAHerQbj5hLja7NQ4nlv1mNDthcnPxFlxHBlRJAHpYErAK74X9sbgz
dWqTHBLmYF5vHX/JHyPLhGGfHoJE+V+tYlUkmlKY7VHnoX6XOuYvHxHaU4AshZ6rNRDbIl9qxV6X
U/IyAgkwo1jwDQHVcsaxfGl7w/U2Rcxhbl5MlMVerugOXou/983g7aEOGzPuVBj+D77vfoRrQ+Nw
mNtddbINWQeFFSM51vHfqSYP1kjHs6Yi9TM3WpVHn3u6GBVv/9YUZINJ0gpnIdsPNWNgKCLjsZWD
zYWm3S8P52dSbrsvhXz1SnPnxT7AvSESBT/8twNJAlvIJebiVDj1eYeMHVOyToV7BjjHLPj4sHKN
JeV3UvQDHEimUF+IIDBu8oJDqz2XhOdT+yHBTw8imoa4WSr2Rz0ZiC3oheGe7IUIarFsNMkd7Egr
O3jtZsSOeWmD3n+M
-----END CERTIFICATE-----

QuoVadis Root CA 3 G3
=====================
-----BEGIN CERTIFICATE-----
MIIFYDCCA0igAwIBAgIULvWbAiin23r/1aOp7r0DoM8Sah0wDQYJKoZIhvcNAQELBQAwSDELMAkG
A1UEBhMCQk0xGTAXBgNVBAoTEFF1b1ZhZGlzIExpbWl0ZWQxHjAcBgNVBAMTFVF1b1ZhZGlzIFJv
b3QgQ0EgMyBHMzAeFw0xMjAxMTIyMDI2MzJaFw00MjAxMTIyMDI2MzJaMEgxCzAJBgNVBAYTAkJN
MRkwFwYDVQQKExBRdW9WYWRpcyBMaW1pdGVkMR4wHAYDVQQDExVRdW9WYWRpcyBSb290IENBIDMg
RzMwggIiMA0GCSqGSIb3DQEBAQUAA4ICDwAwggIKAoICAQCzyw4QZ47qFJenMioKVjZ/aEzHs286
IxSR/xl/pcqs7rN2nXrpixurazHb+gtTTK/FpRp5PIpM/6zfJd5O2YIyC0TeytuMrKNuFoM7pmRL
Mon7FhY4futD4tN0SsJiCnMK3UmzV9KwCoWdcTzeo8vAMvMBOSBDGzXRU7Ox7sWTaYI+FrUoRqHe
6okJ7UO4BUaKhvVZR74bbwEhELn9qdIoyhA5CcoTNs+cra1AdHkrAj80//ogaX3T7mH1urPnMNA3
I4ZyYUUpSFlob3emLoG+B01vr87ERRORFHAGjx+f+IdpsQ7vw4kZ6+ocYfx6bIrc1gMLnia6Et3U
VDmrJqMz6nWB2i3ND0/kA9HvFZcba5DFApCTZgIhsUfei5pKgLlVj7WiL8DWM2fafsSntARE60f7
5li59wzweyuxwHApw0BiLTtIadwjPEjrewl5qW3aqDCYz4ByA4imW0aucnl8CAMhZa634RylsSqi
Md5mBPfAdOhx3v89WcyWJhKLhZVXGqtrdQtEPREoPHtht+KPZ0/l7DxMYIBpVzgeAVuNVejH38DM
dyM0SXV89pgR6y3e7UEuFAUCf+D+IOs15xGsIs5XPd7JMG0QA4XN8f+MFrXBsj6IbGB/kE+V9/Yt
rQE5BwT6dYB9v0lQ7e/JxHwc64B+27bQ3RP+ydOc17KXqQIDAQABo0IwQDAPBgNVHRMBAf8EBTAD
AQH/MA4GA1UdDwEB/wQEAwIBBjAdBgNVHQ4EFgQUxhfQvKjqAkPyGwaZXSuQILnXnOQwDQYJKoZI
hvcNAQELBQADggIBADRh2Va1EodVTd2jNTFGu6QHcrxfYWLopfsLN7E8trP6KZ1/AvWkyaiTt3px
KGmPc+FSkNrVvjrlt3ZqVoAh313m6Tqe5T72omnHKgqwGEfcIHB9UqM+WXzBusnIFUBhynLWcKzS
t/Ac5IYp8M7vaGPQtSCKFWGafoaYtMnCdvvMujAWzKNhxnQT5WvvoxXqA/4Ti2Tk08HS6IT7SdEQ
TXlm66r99I0xHnAUrdzeZxNMgRVhvLfZkXdxGYFgu/BYpbWcC/ePIlUnwEsBbTuZDdQdm2NnL9Du
DcpmvJRPpq3t/O5jrFc/ZSXPsoaP0Aj/uHYUbt7lJ+yreLVTubY/6CD50qi+YUbKh4yE8/nxoGib
Ih6BJpsQBJFxwAYf3KDTuVan45gtf4Od34wrnDKOMpTwATwiKp9Dwi7DmDkHOHv8XgBCH/MyJnmD
hPbl8MFREsALHgQjDFSlTC9JxUrRtm5gDWv8a4uFJGS3iQ6rJUdbPM9+Sb3H6QrG2vd+DhcI00iX
0HGS8A85PjRqHH3Y8iKuu2n0M7SmSFXRDw4m6Oy2Cy2nhTXN/VnIn9HNPlopNLk9hM6xZdRZkZFW
dSHBd575euFgndOtBBj0fOtek49TSiIp+EgrPk2GrFt/ywaZWWDYWGWVjUTR939+J399roD1B0y2
PpxxVJkES/1Y+Zj0
-----END CERTIFICATE-----

DigiCert Assured ID Root G2
===========================
-----BEGIN CERTIFICATE-----
MIIDljCCAn6gAwIBAgIQC5McOtY5Z+pnI7/Dr5r0SzANBgkqhkiG9w0BAQsFADBlMQswCQYDVQQG
EwJVUzEVMBMGA1UEChMMRGlnaUNlcnQgSW5jMRkwFwYDVQQLExB3d3cuZGlnaWNlcnQuY29tMSQw
IgYDVQQDExtEaWdpQ2VydCBBc3N1cmVkIElEIFJvb3QgRzIwHhcNMTMwODAxMTIwMDAwWhcNMzgw
MTE1MTIwMDAwWjBlMQswCQYDVQQGEwJVUzEVMBMGA1UEChMMRGlnaUNlcnQgSW5jMRkwFwYDVQQL
ExB3d3cuZGlnaWNlcnQuY29tMSQwIgYDVQQDExtEaWdpQ2VydCBBc3N1cmVkIElEIFJvb3QgRzIw
ggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQDZ5ygvUj82ckmIkzTz+GoeMVSAn61UQbVH
35ao1K+ALbkKz3X9iaV9JPrjIgwrvJUXCzO/GU1BBpAAvQxNEP4HteccbiJVMWWXvdMX0h5i89vq
bFCMP4QMls+3ywPgym2hFEwbid3tALBSfK+RbLE4E9HpEgjAALAcKxHad3A2m67OeYfcgnDmCXRw
VWmvo2ifv922ebPynXApVfSr/5Vh88lAbx3RvpO704gqu52/clpWcTs/1PPRCv4o76Pu2ZmvA9OP
YLfykqGxvYmJHzDNw6YuYjOuFgJ3RFrngQo8p0Quebg/BLxcoIfhG69Rjs3sLPr4/m3wOnyqi+Rn
lTGNAgMBAAGjQjBAMA8GA1UdEwEB/wQFMAMBAf8wDgYDVR0PAQH/BAQDAgGGMB0GA1UdDgQWBBTO
w0q5mVXyuNtgv6l+vVa1lzan1jANBgkqhkiG9w0BAQsFAAOCAQEAyqVVjOPIQW5pJ6d1Ee88hjZv
0p3GeDgdaZaikmkuOGybfQTUiaWxMTeKySHMq2zNixya1r9I0jJmwYrA8y8678Dj1JGG0VDjA9tz
d29KOVPt3ibHtX2vK0LRdWLjSisCx1BL4GnilmwORGYQRI+tBev4eaymG+g3NJ1TyWGqolKvSnAW
hsI6yLETcDbYz+70CjTVW0z9B5yiutkBclzzTcHdDrEcDcRjvq30FPuJ7KJBDkzMyFdA0G4Dqs0M
jomZmWzwPDCvON9vvKO+KSAnq3T/EyJ43pdSVR6DtVQgA+6uwE9W3jfMw3+qBCe703e4YtsXfJwo
IhNzbM8m9Yop5w==
-----END CERTIFICATE-----

DigiCert Assured ID Root G3
===========================
-----BEGIN CERTIFICATE-----
MIICRjCCAc2gAwIBAgIQC6Fa+h3foLVJRK/NJKBs7DAKBggqhkjOPQQDAzBlMQswCQYDVQQGEwJV
UzEVMBMGA1UEChMMRGlnaUNlcnQgSW5jMRkwFwYDVQQLExB3d3cuZGlnaWNlcnQuY29tMSQwIgYD
VQQDExtEaWdpQ2VydCBBc3N1cmVkIElEIFJvb3QgRzMwHhcNMTMwODAxMTIwMDAwWhcNMzgwMTE1
MTIwMDAwWjBlMQswCQYDVQQGEwJVUzEVMBMGA1UEChMMRGlnaUNlcnQgSW5jMRkwFwYDVQQLExB3
d3cuZGlnaWNlcnQuY29tMSQwIgYDVQQDExtEaWdpQ2VydCBBc3N1cmVkIElEIFJvb3QgRzMwdjAQ
BgcqhkjOPQIBBgUrgQQAIgNiAAQZ57ysRGXtzbg/WPuNsVepRC0FFfLvC/8QdJ+1YlJfZn4f5dwb
RXkLzMZTCp2NXQLZqVneAlr2lSoOjThKiknGvMYDOAdfVdp+CW7if17QRSAPWXYQ1qAk8C3eNvJs
KTmjQjBAMA8GA1UdEwEB/wQFMAMBAf8wDgYDVR0PAQH/BAQDAgGGMB0GA1UdDgQWBBTL0L2p4ZgF
UaFNN6KDec6NHSrkhDAKBggqhkjOPQQDAwNnADBkAjAlpIFFAmsSS3V0T8gj43DydXLefInwz5Fy
YZ5eEJJZVrmDxxDnOOlYJjZ91eQ0hjkCMHw2U/Aw5WJjOpnitqM7mzT6HtoQknFekROn3aRukswy
1vUhZscv6pZjamVFkpUBtA==
-----END CERTIFICATE-----

DigiCert Global Root G2
=======================
-----BEGIN CERTIFICATE-----
MIIDjjCCAnagAwIBAgIQAzrx5qcRqaC7KGSxHQn65TANBgkqhkiG9w0BAQsFADBhMQswCQYDVQQG
EwJVUzEVMBMGA1UEChMMRGlnaUNlcnQgSW5jMRkwFwYDVQQLExB3d3cuZGlnaWNlcnQuY29tMSAw
HgYDVQQDExdEaWdpQ2VydCBHbG9iYWwgUm9vdCBHMjAeFw0xMzA4MDExMjAwMDBaFw0zODAxMTUx
MjAwMDBaMGExCzAJBgNVBAYTAlVTMRUwEwYDVQQKEwxEaWdpQ2VydCBJbmMxGTAXBgNVBAsTEHd3
dy5kaWdpY2VydC5jb20xIDAeBgNVBAMTF0RpZ2lDZXJ0IEdsb2JhbCBSb290IEcyMIIBIjANBgkq
hkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuzfNNNx7a8myaJCtSnX/RrohCgiN9RlUyfuI2/Ou8jqJ
kTx65qsGGmvPrC3oXgkkRLpimn7Wo6h+4FR1IAWsULecYxpsMNzaHxmx1x7e/dfgy5SDN67sH0NO
3Xss0r0upS/kqbitOtSZpLYl6ZtrAGCSYP9PIUkY92eQq2EGnI/yuum06ZIya7XzV+hdG82MHauV
BJVJ8zUtluNJbd134/tJS7SsVQepj5WztCO7TG1F8PapspUwtP1MVYwnSlcUfIKdzXOS0xZKBgyM
UNGPHgm+F6HmIcr9g+UQvIOlCsRnKPZzFBQ9RnbDhxSJITRNrw9FDKZJobq7nMWxM4MphQIDAQAB
o0IwQDAPBgNVHRMBAf8EBTADAQH/MA4GA1UdDwEB/wQEAwIBhjAdBgNVHQ4EFgQUTiJUIBiV5uNu
5g/6+rkS7QYXjzkwDQYJKoZIhvcNAQELBQADggEBAGBnKJRvDkhj6zHd6mcY1Yl9PMWLSn/pvtsr
F9+wX3N3KjITOYFnQoQj8kVnNeyIv/iPsGEMNKSuIEyExtv4NeF22d+mQrvHRAiGfzZ0JFrabA0U
WTW98kndth/Jsw1HKj2ZL7tcu7XUIOGZX1NGFdtom/DzMNU+MeKNhJ7jitralj41E6Vf8PlwUHBH
QRFXGU7Aj64GxJUTFy8bJZ918rGOmaFvE7FBcf6IKshPECBV1/MUReXgRPTqh5Uykw7+U0b6LJ3/
iyK5S9kJRaTepLiaWN0bfVKfjllDiIGknibVb63dDcY3fe0Dkhvld1927jyNxF1WW6LZZm6zNTfl
MrY=
-----END CERTIFICATE-----

DigiCert Global Root G3
=======================
-----BEGIN CERTIFICATE-----
MIICPzCCAcWgAwIBAgIQBVVWvPJepDU1w6QP1atFcjAKBggqhkjOPQQDAzBhMQswCQYDVQQGEwJV
UzEVMBMGA1UEChMMRGlnaUNlcnQgSW5jMRkwFwYDVQQLExB3d3cuZGlnaWNlcnQuY29tMSAwHgYD
VQQDExdEaWdpQ2VydCBHbG9iYWwgUm9vdCBHMzAeFw0xMzA4MDExMjAwMDBaFw0zODAxMTUxMjAw
MDBaMGExCzAJBgNVBAYTAlVTMRUwEwYDVQQKEwxEaWdpQ2VydCBJbmMxGTAXBgNVBAsTEHd3dy5k
aWdpY2VydC5jb20xIDAeBgNVBAMTF0RpZ2lDZXJ0IEdsb2JhbCBSb290IEczMHYwEAYHKoZIzj0C
AQYFK4EEACIDYgAE3afZu4q4C/sLfyHS8L6+c/MzXRq8NOrexpu80JX28MzQC7phW1FGfp4tn+6O
YwwX7Adw9c+ELkCDnOg/QW07rdOkFFk2eJ0DQ+4QE2xy3q6Ip6FrtUPOZ9wj/wMco+I+o0IwQDAP
BgNVHRMBAf8EBTADAQH/MA4GA1UdDwEB/wQEAwIBhjAdBgNVHQ4EFgQUs9tIpPmhxdiuNkHMEWNp
Yim8S8YwCgYIKoZIzj0EAwMDaAAwZQIxAK288mw/EkrRLTnDCgmXc/SINoyIJ7vmiI1Qhadj+Z4y
3maTD/HMsQmP3Wyr+mt/oAIwOWZbwmSNuJ5Q3KjVSaLtx9zRSX8XAbjIho9OjIgrqJqpisXRAL34
VOKa5Vt8sycX
-----END CERTIFICATE-----

DigiCert Trusted Root G4
========================
-----BEGIN CERTIFICATE-----
MIIFkDCCA3igAwIBAgIQBZsbV56OITLiOQe9p3d1XDANBgkqhkiG9w0BAQwFADBiMQswCQYDVQQG
EwJVUzEVMBMGA1UEChMMRGlnaUNlcnQgSW5jMRkwFwYDVQQLExB3d3cuZGlnaWNlcnQuY29tMSEw
HwYDVQQDExhEaWdpQ2VydCBUcnVzdGVkIFJvb3QgRzQwHhcNMTMwODAxMTIwMDAwWhcNMzgwMTE1
MTIwMDAwWjBiMQswCQYDVQQGEwJVUzEVMBMGA1UEChMMRGlnaUNlcnQgSW5jMRkwFwYDVQQLExB3
d3cuZGlnaWNlcnQuY29tMSEwHwYDVQQDExhEaWdpQ2VydCBUcnVzdGVkIFJvb3QgRzQwggIiMA0G
CSqGSIb3DQEBAQUAA4ICDwAwggIKAoICAQC/5pBzaN675F1KPDAiMGkz7MKnJS7JIT3yithZwuEp
pz1Yq3aaza57G4QNxDAf8xukOBbrVsaXbR2rsnnyyhHS5F/WBTxSD1Ifxp4VpX6+n6lXFllVcq9o
k3DCsrp1mWpzMpTREEQQLt+C8weE5nQ7bXHiLQwb7iDVySAdYyktzuxeTsiT+CFhmzTrBcZe7Fsa
vOvJz82sNEBfsXpm7nfISKhmV1efVFiODCu3T6cw2Vbuyntd463JT17lNecxy9qTXtyOj4DatpGY
QJB5w3jHtrHEtWoYOAMQjdjUN6QuBX2I9YI+EJFwq1WCQTLX2wRzKm6RAXwhTNS8rhsDdV14Ztk6
MUSaM0C/CNdaSaTC5qmgZ92kJ7yhTzm1EVgX9yRcRo9k98FpiHaYdj1ZXUJ2h4mXaXpI8OCiEhtm
mnTK3kse5w5jrubU75KSOp493ADkRSWJtppEGSt+wJS00mFt6zPZxd9LBADMfRyVw4/3IbKyEbe7
f/LVjHAsQWCqsWMYRJUadmJ+9oCw++hkpjPRiQfhvbfmQ6QYuKZ3AeEPlAwhHbJUKSWJbOUOUlFH
dL4mrLZBdd56rF+NP8m800ERElvlEFDrMcXKchYiCd98THU/Y+whX8QgUWtvsauGi0/C1kVfnSD8
oR7FwI+isX4KJpn15GkvmB0t9dmpsh3lGwIDAQABo0IwQDAPBgNVHRMBAf8EBTADAQH/MA4GA1Ud
DwEB/wQEAwIBhjAdBgNVHQ4EFgQU7NfjgtJxXWRM3y5nP+e6mK4cD08wDQYJKoZIhvcNAQEMBQAD
ggIBALth2X2pbL4XxJEbw6GiAI3jZGgPVs93rnD5/ZpKmbnJeFwMDF/k5hQpVgs2SV1EY+CtnJYY
ZhsjDT156W1r1lT40jzBQ0CuHVD1UvyQO7uYmWlrx8GnqGikJ9yd+SeuMIW59mdNOj6PWTkiU0Tr
yF0Dyu1Qen1iIQqAyHNm0aAFYF/opbSnr6j3bTWcfFqK1qI4mfN4i/RN0iAL3gTujJtHgXINwBQy
7zBZLq7gcfJW5GqXb5JQbZaNaHqasjYUegbyJLkJEVDXCLG4iXqEI2FCKeWjzaIgQdfRnGTZ6iah
ixTXTBmyUEFxPT9NcCOGDErcgdLMMpSEDQgJlxxPwO5rIHQw0uA5NBCFIRUBCOhVMt5xSdkoF1BN
5r5N0XWs0Mr7QbhDparTwwVETyw2m+L64kW4I1NsBm9nVX9GtUw/bihaeSbSpKhil9Ie4u1Ki7wb
/UdKDd9nZn6yW0HQO+T0O/QEY+nvwlQAUaCKKsnOeMzV6ocEGLPOr0mIr/OSmbaz5mEP0oUA51Aa
5BuVnRmhuZyxm7EAHu/QD09CbMkKvO5D+jpxpchNJqU1/YldvIViHTLSoCtU7ZpXwdv6EM8Zt4tK
G48BtieVU+i2iW1bvGjUI+iLUaJW+fCmgKDWHrO8Dw9TdSmq6hN35N6MgSGtBxBHEa2HPQfRdbzP
82Z+
-----END CERTIFICATE-----

WoSign
======
-----BEGIN CERTIFICATE-----
MIIFdjCCA16gAwIBAgIQXmjWEXGUY1BWAGjzPsnFkTANBgkqhkiG9w0BAQUFADBVMQswCQYDVQQG
EwJDTjEaMBgGA1UEChMRV29TaWduIENBIExpbWl0ZWQxKjAoBgNVBAMTIUNlcnRpZmljYXRpb24g
QXV0aG9yaXR5IG9mIFdvU2lnbjAeFw0wOTA4MDgwMTAwMDFaFw0zOTA4MDgwMTAwMDFaMFUxCzAJ
BgNVBAYTAkNOMRowGAYDVQQKExFXb1NpZ24gQ0EgTGltaXRlZDEqMCgGA1UEAxMhQ2VydGlmaWNh
dGlvbiBBdXRob3JpdHkgb2YgV29TaWduMIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA
vcqNrLiRFVaXe2tcesLea9mhsMMQI/qnobLMMfo+2aYpbxY94Gv4uEBf2zmoAHqLoE1UfcIiePyO
CbiohdfMlZdLdNiefvAA5A6JrkkoRBoQmTIPJYhTpA2zDxIIFgsDcSccf+Hb0v1naMQFXQoOXXDX
2JegvFNBmpGN9J42Znp+VsGQX+axaCA2pIwkLCxHC1l2ZjC1vt7tj/id07sBMOby8w7gLJKA84X5
KIq0VC6a7fd2/BVoFutKbOsuEo/Uz/4Mx1wdC34FMr5esAkqQtXJTpCzWQ27en7N1QhatH/YHGkR
+ScPewavVIMYe+HdVHpRaG53/Ma/UkpmRqGyZxq7o093oL5d//xWC0Nyd5DKnvnyOfUNqfTq1+ez
EC8wQjchzDBwyYaYD8xYTYO7feUapTeNtqwylwA6Y3EkHp43xP901DfA4v6IRmAR3Qg/UDaruHqk
lWJqbrDKaiFaafPz+x1wOZXzp26mgYmhiMU7ccqjUu6Du/2gd/Tkb+dC221KmYo0SLwX3OSACCK2
8jHAPwQ+658geda4BmRkAjHXqc1S+4RFaQkAKtxVi8QGRkvASh0JWzko/amrzgD5LkhLJuYwTKVY
yrREgk/nkR4zw7CT/xH8gdLKH3Ep3XZPkiWvHYG3Dy+MwwbMLyejSuQOmbp8HkUff6oZRZb9/D0C
AwEAAaNCMEAwDgYDVR0PAQH/BAQDAgEGMA8GA1UdEwEB/wQFMAMBAf8wHQYDVR0OBBYEFOFmzw7R
8bNLtwYgFP6HEtX2/vs+MA0GCSqGSIb3DQEBBQUAA4ICAQCoy3JAsnbBfnv8rWTjMnvMPLZdRtP1
LOJwXcgu2AZ9mNELIaCJWSQBnfmvCX0KI4I01fx8cpm5o9dU9OpScA7F9dY74ToJMuYhOZO9sxXq
T2r09Ys/L3yNWC7F4TmgPsc9SnOeQHrAK2GpZ8nzJLmzbVUsWh2eJXLOC62qx1ViC777Y7NhRCOj
y+EaDveaBk3e1CNOIZZbOVtXHS9dCF4Jef98l7VNg64N1uajeeAz0JmWAjCnPv/So0M/BVoG6kQC
2nz4SNAzqfkHx5Xh9T71XXG68pWpdIhhWeO/yloTunK0jF02h+mmxTwTv97QRCbut+wucPrXnbes
5cVAWubXbHssw1abR80LzvobtCHXt2a49CUwi1wNuepnsvRtrtWhnk/Yn+knArAdBtaP4/tIEp9/
EaEQPkxROpaw0RPxx9gmrjrKkcRpnd8BKWRRb2jaFOwIQZeQjdCygPLPwj2/kWjFgGcexGATVdVh
mVd8upUPYUk6ynW8yQqTP2cOEvIo4jEbwFcW3wh8GcF+Dx+FHgo2fFt+J7x6v+Db9NpSvd4MVHAx
kUOVyLzwPt0JfjBkUO1/AaQzZ01oT74V77D2AhGiGxMlOtzCWfHjXEa7ZywCRuoeSKbmW9m1vFGi
kpbbqsY3Iqb+zCB0oy2pLmvLwIIRIbWTee5Ehr7XHuQe+w==
-----END CERTIFICATE-----

WoSign China
============
-----BEGIN CERTIFICATE-----
MIIFWDCCA0CgAwIBAgIQUHBrzdgT/BtOOzNy0hFIjTANBgkqhkiG9w0BAQsFADBGMQswCQYDVQQG
EwJDTjEaMBgGA1UEChMRV29TaWduIENBIExpbWl0ZWQxGzAZBgNVBAMMEkNBIOayg+mAmuagueiv
geS5pjAeFw0wOTA4MDgwMTAwMDFaFw0zOTA4MDgwMTAwMDFaMEYxCzAJBgNVBAYTAkNOMRowGAYD
VQQKExFXb1NpZ24gQ0EgTGltaXRlZDEbMBkGA1UEAwwSQ0Eg5rKD6YCa5qC56K+B5LmmMIICIjAN
BgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA0EkhHiX8h8EqwqzbdoYGTufQdDTc7WU1/FDWiD+k
8H/rD195L4mx/bxjWDeTmzj4t1up+thxx7S8gJeNbEvxUNUqKaqoGXqW5pWOdO2XCld19AXbbQs5
uQF/qvbW2mzmBeCkTVL829B0txGMe41P/4eDrv8FAxNXUDf+jJZSEExfv5RxadmWPgxDT74wwJ85
dE8GRV2j1lY5aAfMh09Qd5Nx2UQIsYo06Yms25tO4dnkUkWMLhQfkWsZHWgpLFbE4h4TV2TwYeO5
Ed+w4VegG63XX9Gv2ystP9Bojg/qnw+LNVgbExz03jWhCl3W6t8Sb8D7aQdGctyB9gQjF+BNdeFy
b7Ao65vh4YOhn0pdr8yb+gIgthhid5E7o9Vlrdx8kHccREGkSovrlXLp9glk3Kgtn3R46MGiCWOc
76DbT52VqyBPt7D3h1ymoOQ3OMdc4zUPLK2jgKLsLl3Az+2LBcLmc272idX10kaO6m1jGx6KyX2m
+Jzr5dVjhU1zZmkR/sgO9MHHZklTfuQZa/HpelmjbX7FF+Ynxu8b22/8DU0GAbQOXDBGVWCvOGU6
yke6rCzMRh+yRpY/8+0mBe53oWprfi1tWFxK1I5nuPHa1UaKJ/kR8slC/k7e3x9cxKSGhxYzoacX
GKUN5AXlK8IrC6KVkLn9YDxOiT7nnO4fuwECAwEAAaNCMEAwDgYDVR0PAQH/BAQDAgEGMA8GA1Ud
EwEB/wQFMAMBAf8wHQYDVR0OBBYEFOBNv9ybQV0T6GTwp+kVpOGBwboxMA0GCSqGSIb3DQEBCwUA
A4ICAQBqinA4WbbaixjIvirTthnVZil6Xc1bL3McJk6jfW+rtylNpumlEYOnOXOvEESS5iVdT2H6
yAa+Tkvv/vMx/sZ8cApBWNromUuWyXi8mHwCKe0JgOYKOoICKuLJL8hWGSbueBwj/feTZU7n85iY
r83d2Z5AiDEoOqsuC7CsDCT6eiaY8xJhEPRdF/d+4niXVOKM6Cm6jBAyvd0zaziGfjk9DgNyp115
j0WKWa5bIW4xRtVZjc8VX90xJc/bYNaBRHIpAlf2ltTW/+op2znFuCyKGo3Oy+dCMYYFaA6eFN0A
kLppRQjbbpCBhqcqBT/mhDn4t/lXX0ykeVoQDF7Va/81XwVRHmyjdanPUIPTfPRm94KNPQx96N97
qA4bLJyuQHCH2u2nFoJavjVsIE4iYdm8UXrNemHcSxH5/mc0zy4EZmFcV5cjjPOGG0jfKq+nwf/Y
jj4Du9gqsPoUJbJRa4ZDhS4HIxaAjUz7tGM7zMN07RujHv41D198HRaG9Q7DlfEvr10lO1Hm13ZB
ONFLAzkopR6RctR9q5czxNM+4Gm2KHmgCY0c0f9BckgG/Jou5yD5m6Leie2uPAmvylezkolwQOQv
T8Jwg0DXJCxr5wkf09XHwQj02w47HAcLQxGEIYbpgNR12KvxAmLBsX5VYc8T1yaw15zLKYs4SgsO
kI26oQ==
-----END CERTIFICATE-----

COMODO RSA Certification Authority
==================================
-----BEGIN CERTIFICATE-----
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
LaZRfyHBNVOFBkpdn627G190
-----END CERTIFICATE-----

USERTrust RSA Certification Authority
=====================================
-----BEGIN CERTIFICATE-----
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
Q+6IHdfGjjxDah2nGN59PRbxYvnKkKj9
-----END CERTIFICATE-----

USERTrust ECC Certification Authority
=====================================
-----BEGIN CERTIFICATE-----
MIICjzCCAhWgAwIBAgIQXIuZxVqUxdJxVt7NiYDMJjAKBggqhkjOPQQDAzCBiDELMAkGA1UEBhMC
VVMxEzARBgNVBAgTCk5ldyBKZXJzZXkxFDASBgNVBAcTC0plcnNleSBDaXR5MR4wHAYDVQQKExVU
aGUgVVNFUlRSVVNUIE5ldHdvcmsxLjAsBgNVBAMTJVVTRVJUcnVzdCBFQ0MgQ2VydGlmaWNhdGlv
biBBdXRob3JpdHkwHhcNMTAwMjAxMDAwMDAwWhcNMzgwMTE4MjM1OTU5WjCBiDELMAkGA1UEBhMC
VVMxEzARBgNVBAgTCk5ldyBKZXJzZXkxFDASBgNVBAcTC0plcnNleSBDaXR5MR4wHAYDVQQKExVU
aGUgVVNFUlRSVVNUIE5ldHdvcmsxLjAsBgNVBAMTJVVTRVJUcnVzdCBFQ0MgQ2VydGlmaWNhdGlv
biBBdXRob3JpdHkwdjAQBgcqhkjOPQIBBgUrgQQAIgNiAAQarFRaqfloI+d61SRvU8Za2EurxtW2
0eZzca7dnNYMYf3boIkDuAUU7FfO7l0/4iGzzvfUinngo4N+LZfQYcTxmdwlkWOrfzCjtHDix6Ez
nPO/LlxTsV+zfTJ/ijTjeXmjQjBAMB0GA1UdDgQWBBQ64QmG1M8ZwpZ2dEl23OA1xmNjmjAOBgNV
HQ8BAf8EBAMCAQYwDwYDVR0TAQH/BAUwAwEB/zAKBggqhkjOPQQDAwNoADBlAjA2Z6EWCNzklwBB
HU6+4WMBzzuqQhFkoJ2UOQIReVx7Hfpkue4WQrO/isIJxOzksU0CMQDpKmFHjFJKS04YcPbWRNZu
9YO6bVi9JNlWSOrvxKJGgYhqOkbRqZtNyWHa0V1Xahg=
-----END CERTIFICATE-----

GlobalSign ECC Root CA - R4
===========================
-----BEGIN CERTIFICATE-----
MIIB4TCCAYegAwIBAgIRKjikHJYKBN5CsiilC+g0mAIwCgYIKoZIzj0EAwIwUDEkMCIGA1UECxMb
R2xvYmFsU2lnbiBFQ0MgUm9vdCBDQSAtIFI0MRMwEQYDVQQKEwpHbG9iYWxTaWduMRMwEQYDVQQD
EwpHbG9iYWxTaWduMB4XDTEyMTExMzAwMDAwMFoXDTM4MDExOTAzMTQwN1owUDEkMCIGA1UECxMb
R2xvYmFsU2lnbiBFQ0MgUm9vdCBDQSAtIFI0MRMwEQYDVQQKEwpHbG9iYWxTaWduMRMwEQYDVQQD
EwpHbG9iYWxTaWduMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEuMZ5049sJQ6fLjkZHAOkrprl
OQcJFspjsbmG+IpXwVfOQvpzofdlQv8ewQCybnMO/8ch5RikqtlxP6jUuc6MHaNCMEAwDgYDVR0P
AQH/BAQDAgEGMA8GA1UdEwEB/wQFMAMBAf8wHQYDVR0OBBYEFFSwe61FuOJAf/sKbvu+M8k8o4TV
MAoGCCqGSM49BAMCA0gAMEUCIQDckqGgE6bPA7DmxCGXkPoUVy0D7O48027KqGx2vKLeuwIgJ6iF
JzWbVsaj8kfSt24bAgAXqmemFZHe+pTsewv4n4Q=
-----END CERTIFICATE-----

GlobalSign ECC Root CA - R5
===========================
-----BEGIN CERTIFICATE-----
MIICHjCCAaSgAwIBAgIRYFlJ4CYuu1X5CneKcflK2GwwCgYIKoZIzj0EAwMwUDEkMCIGA1UECxMb
R2xvYmFsU2lnbiBFQ0MgUm9vdCBDQSAtIFI1MRMwEQYDVQQKEwpHbG9iYWxTaWduMRMwEQYDVQQD
EwpHbG9iYWxTaWduMB4XDTEyMTExMzAwMDAwMFoXDTM4MDExOTAzMTQwN1owUDEkMCIGA1UECxMb
R2xvYmFsU2lnbiBFQ0MgUm9vdCBDQSAtIFI1MRMwEQYDVQQKEwpHbG9iYWxTaWduMRMwEQYDVQQD
EwpHbG9iYWxTaWduMHYwEAYHKoZIzj0CAQYFK4EEACIDYgAER0UOlvt9Xb/pOdEh+J8LttV7HpI6
SFkc8GIxLcB6KP4ap1yztsyX50XUWPrRd21DosCHZTQKH3rd6zwzocWdTaRvQZU4f8kehOvRnkmS
h5SHDDqFSmafnVmTTZdhBoZKo0IwQDAOBgNVHQ8BAf8EBAMCAQYwDwYDVR0TAQH/BAUwAwEB/zAd
BgNVHQ4EFgQUPeYpSJvqB8ohREom3m7e0oPQn1kwCgYIKoZIzj0EAwMDaAAwZQIxAOVpEslu28Yx
uglB4Zf4+/2a4n0Sye18ZNPLBSWLVtmg515dTguDnFt2KaAJJiFqYgIwcdK1j1zqO+F4CYWodZI7
yFz9SO8NdCKoCOJuxUnOxwy8p2Fp8fc74SrL+SvzZpA3
-----END CERTIFICATE-----

Staat der Nederlanden Root CA - G3
==================================
-----BEGIN CERTIFICATE-----
MIIFdDCCA1ygAwIBAgIEAJiiOTANBgkqhkiG9w0BAQsFADBaMQswCQYDVQQGEwJOTDEeMBwGA1UE
CgwVU3RhYXQgZGVyIE5lZGVybGFuZGVuMSswKQYDVQQDDCJTdGFhdCBkZXIgTmVkZXJsYW5kZW4g
Um9vdCBDQSAtIEczMB4XDTEzMTExNDExMjg0MloXDTI4MTExMzIzMDAwMFowWjELMAkGA1UEBhMC
TkwxHjAcBgNVBAoMFVN0YWF0IGRlciBOZWRlcmxhbmRlbjErMCkGA1UEAwwiU3RhYXQgZGVyIE5l
ZGVybGFuZGVuIFJvb3QgQ0EgLSBHMzCCAiIwDQYJKoZIhvcNAQEBBQADggIPADCCAgoCggIBAL4y
olQPcPssXFnrbMSkUeiFKrPMSjTysF/zDsccPVMeiAho2G89rcKezIJnByeHaHE6n3WWIkYFsO2t
x1ueKt6c/DrGlaf1F2cY5y9JCAxcz+bMNO14+1Cx3Gsy8KL+tjzk7FqXxz8ecAgwoNzFs21v0IJy
EavSgWhZghe3eJJg+szeP4TrjTgzkApyI/o1zCZxMdFyKJLZWyNtZrVtB0LrpjPOktvA9mxjeM3K
Tj215VKb8b475lRgsGYeCasH/lSJEULR9yS6YHgamPfJEf0WwTUaVHXvQ9Plrk7O53vDxk5hUUur
mkVLoR9BvUhTFXFkC4az5S6+zqQbwSmEorXLCCN2QyIkHxcE1G6cxvx/K2Ya7Irl1s9N9WMJtxU5
1nus6+N86U78dULI7ViVDAZCopz35HCz33JvWjdAidiFpNfxC95DGdRKWCyMijmev4SH8RY7Ngzp
07TKbBlBUgmhHbBqv4LvcFEhMtwFdozL92TkA1CvjJFnq8Xy7ljY3r735zHPbMk7ccHViLVlvMDo
FxcHErVc0qsgk7TmgoNwNsXNo42ti+yjwUOH5kPiNL6VizXtBznaqB16nzaeErAMZRKQFWDZJkBE
41ZgpRDUajz9QdwOWke275dhdU/Z/seyHdTtXUmzqWrLZoQT1Vyg3N9udwbRcXXIV2+vD3dbAgMB
AAGjQjBAMA8GA1UdEwEB/wQFMAMBAf8wDgYDVR0PAQH/BAQDAgEGMB0GA1UdDgQWBBRUrfrHkleu
yjWcLhL75LpdINyUVzANBgkqhkiG9w0BAQsFAAOCAgEAMJmdBTLIXg47mAE6iqTnB/d6+Oea31BD
U5cqPco8R5gu4RV78ZLzYdqQJRZlwJ9UXQ4DO1t3ApyEtg2YXzTdO2PCwyiBwpwpLiniyMMB8jPq
KqrMCQj3ZWfGzd/TtiunvczRDnBfuCPRy5FOCvTIeuXZYzbB1N/8Ipf3YF3qKS9Ysr1YvY2WTxB1
v0h7PVGHoTx0IsL8B3+A3MSs/mrBcDCw6Y5p4ixpgZQJut3+TcCDjJRYwEYgr5wfAvg1VUkvRtTA
8KCWAg8zxXHzniN9lLf9OtMJgwYh/WA9rjLA0u6NpvDntIJ8CsxwyXmA+P5M9zWEGYox+wrZ13+b
8KKaa8MFSu1BYBQw0aoRQm7TIwIEC8Zl3d1Sd9qBa7Ko+gE4uZbqKmxnl4mUnrzhVNXkanjvSr0r
mj1AfsbAddJu+2gw7OyLnflJNZoaLNmzlTnVHpL3prllL+U9bTpITAjc5CgSKL59NVzq4BZ+Extq
1z7XnvwtdbLBFNUjA9tbbws+eC8N3jONFrdI54OagQ97wUNNVQQXOEpR1VmiiXTTn74eS9fGbbeI
JG9gkaSChVtWQbzQRKtqE77RLFi3EjNYsjdj3BP1lB0/QFH1T/U67cjF68IeHRaVesd+QnGTbksV
tzDfqu1XhUisHWrdOWnk4Xl4vs4Fv6EM94B7IWcnMFk=
-----END CERTIFICATE-----

Staat der Nederlanden EV Root CA
================================
-----BEGIN CERTIFICATE-----
MIIFcDCCA1igAwIBAgIEAJiWjTANBgkqhkiG9w0BAQsFADBYMQswCQYDVQQGEwJOTDEeMBwGA1UE
CgwVU3RhYXQgZGVyIE5lZGVybGFuZGVuMSkwJwYDVQQDDCBTdGFhdCBkZXIgTmVkZXJsYW5kZW4g
RVYgUm9vdCBDQTAeFw0xMDEyMDgxMTE5MjlaFw0yMjEyMDgxMTEwMjhaMFgxCzAJBgNVBAYTAk5M
MR4wHAYDVQQKDBVTdGFhdCBkZXIgTmVkZXJsYW5kZW4xKTAnBgNVBAMMIFN0YWF0IGRlciBOZWRl
cmxhbmRlbiBFViBSb290IENBMIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA48d+ifkk
SzrSM4M1LGns3Amk41GoJSt5uAg94JG6hIXGhaTK5skuU6TJJB79VWZxXSzFYGgEt9nCUiY4iKTW
O0Cmws0/zZiTs1QUWJZV1VD+hq2kY39ch/aO5ieSZxeSAgMs3NZmdO3dZ//BYY1jTw+bbRcwJu+r
0h8QoPnFfxZpgQNH7R5ojXKhTbImxrpsX23Wr9GxE46prfNeaXUmGD5BKyF/7otdBwadQ8QpCiv8
Kj6GyzyDOvnJDdrFmeK8eEEzduG/L13lpJhQDBXd4Pqcfzho0LKmeqfRMb1+ilgnQ7O6M5HTp5gV
XJrm0w912fxBmJc+qiXbj5IusHsMX/FjqTf5m3VpTCgmJdrV8hJwRVXj33NeN/UhbJCONVrJ0yPr
08C+eKxCKFhmpUZtcALXEPlLVPxdhkqHz3/KRawRWrUgUY0viEeXOcDPusBCAUCZSCELa6fS/ZbV
0b5GnUngC6agIk440ME8MLxwjyx1zNDFjFE7PZQIZCZhfbnDZY8UnCHQqv0XcgOPvZuM5l5Tnrmd
74K74bzickFbIZTTRTeU0d8JOV3nI6qaHcptqAqGhYqCvkIH1vI4gnPah1vlPNOePqc7nvQDs/nx
fRN0Av+7oeX6AHkcpmZBiFxgV6YuCcS6/ZrPpx9Aw7vMWgpVSzs4dlG4Y4uElBbmVvMCAwEAAaNC
MEAwDwYDVR0TAQH/BAUwAwEB/zAOBgNVHQ8BAf8EBAMCAQYwHQYDVR0OBBYEFP6rAJCYniT8qcwa
ivsnuL8wbqg7MA0GCSqGSIb3DQEBCwUAA4ICAQDPdyxuVr5Os7aEAJSrR8kN0nbHhp8dB9O2tLsI
eK9p0gtJ3jPFrK3CiAJ9Brc1AsFgyb/E6JTe1NOpEyVa/m6irn0F3H3zbPB+po3u2dfOWBfoqSmu
c0iH55vKbimhZF8ZE/euBhD/UcabTVUlT5OZEAFTdfETzsemQUHSv4ilf0X8rLiltTMMgsT7B/Zq
5SWEXwbKwYY5EdtYzXc7LMJMD16a4/CrPmEbUCTCwPTxGfARKbalGAKb12NMcIxHowNDXLldRqAN
b/9Zjr7dn3LDWyvfjFvO5QxGbJKyCqNMVEIYFRIYvdr8unRu/8G2oGTYqV9Vrp9canaW2HNnh/tN
f1zuacpzEPuKqf2evTY4SUmH9A4U8OmHuD+nT3pajnnUk+S7aFKErGzp85hwVXIy+TSrK0m1zSBi
5Dp6Z2Orltxtrpfs/J92VoguZs9btsmksNcFuuEnL5O7Jiqik7Ab846+HUCjuTaPPoIaGl6I6lD4
WeKDRikL40Rc4ZW2aZCaFG+XroHPaO+Zmr615+F/+PoTRxZMzG0IQOeLeG9QgkRQP2YGiqtDhFZK
DyAthg710tvSeopLzaXoTvFeJiUBWSOgftL2fiFX1ye8FVdMpEbB4IMeDExNH08GGeL5qPQ6gqGy
eUN51q1veieQA6TqJIc/2b3Z6fJfUEkc7uzXLg==
-----END CERTIFICATE-----

IdenTrust Commercial Root CA 1
==============================
-----BEGIN CERTIFICATE-----
MIIFYDCCA0igAwIBAgIQCgFCgAAAAUUjyES1AAAAAjANBgkqhkiG9w0BAQsFADBKMQswCQYDVQQG
EwJVUzESMBAGA1UEChMJSWRlblRydXN0MScwJQYDVQQDEx5JZGVuVHJ1c3QgQ29tbWVyY2lhbCBS
b290IENBIDEwHhcNMTQwMTE2MTgxMjIzWhcNMzQwMTE2MTgxMjIzWjBKMQswCQYDVQQGEwJVUzES
MBAGA1UEChMJSWRlblRydXN0MScwJQYDVQQDEx5JZGVuVHJ1c3QgQ29tbWVyY2lhbCBSb290IENB
IDEwggIiMA0GCSqGSIb3DQEBAQUAA4ICDwAwggIKAoICAQCnUBneP5k91DNG8W9RYYKyqU+PZ4ld
hNlT3Qwo2dfw/66VQ3KZ+bVdfIrBQuExUHTRgQ18zZshq0PirK1ehm7zCYofWjK9ouuU+ehcCuz/
mNKvcbO0U59Oh++SvL3sTzIwiEsXXlfEU8L2ApeN2WIrvyQfYo3fw7gpS0l4PJNgiCL8mdo2yMKi
1CxUAGc1bnO/AljwpN3lsKImesrgNqUZFvX9t++uP0D1bVoE/c40yiTcdCMbXTMTEl3EASX2MN0C
XZ/g1Ue9tOsbobtJSdifWwLziuQkkORiT0/Br4sOdBeo0XKIanoBScy0RnnGF7HamB4HWfp1IYVl
3ZBWzvurpWCdxJ35UrCLvYf5jysjCiN2O/cz4ckA82n5S6LgTrx+kzmEB/dEcH7+B1rlsazRGMzy
NeVJSQjKVsk9+w8YfYs7wRPCTY/JTw436R+hDmrfYi7LNQZReSzIJTj0+kuniVyc0uMNOYZKdHzV
WYfCP04MXFL0PfdSgvHqo6z9STQaKPNBiDoT7uje/5kdX7rL6B7yuVBgwDHTc+XvvqDtMwt0viAg
xGds8AgDelWAf0ZOlqf0Hj7h9tgJ4TNkK2PXMl6f+cB7D3hvl7yTmvmcEpB4eoCHFddydJxVdHix
uuFucAS6T6C6aMN7/zHwcz09lCqxC0EOoP5NiGVreTO01wIDAQABo0IwQDAOBgNVHQ8BAf8EBAMC
AQYwDwYDVR0TAQH/BAUwAwEB/zAdBgNVHQ4EFgQU7UQZwNPwBovupHu+QucmVMiONnYwDQYJKoZI
hvcNAQELBQADggIBAA2ukDL2pkt8RHYZYR4nKM1eVO8lvOMIkPkp165oCOGUAFjvLi5+U1KMtlwH
6oi6mYtQlNeCgN9hCQCTrQ0U5s7B8jeUeLBfnLOic7iPBZM4zY0+sLj7wM+x8uwtLRvM7Kqas6pg
ghstO8OEPVeKlh6cdbjTMM1gCIOQ045U8U1mwF10A0Cj7oV+wh93nAbowacYXVKV7cndJZ5t+qnt
ozo00Fl72u1Q8zW/7esUTTHHYPTa8Yec4kjixsU3+wYQ+nVZZjFHKdp2mhzpgq7vmrlR94gjmmmV
YjzlVYA211QC//G5Xc7UI2/YRYRKW2XviQzdFKcgyxilJbQN+QHwotL0AMh0jqEqSI5l2xPE4iUX
feu+h1sXIFRRk0pTAwvsXcoz7WL9RccvW9xYoIA55vrX/hMUpu09lEpCdNTDd1lzzY9GvlU47/ro
kTLql1gEIt44w8y8bckzOmoKaT+gyOpyj4xjhiO9bTyWnpXgSUyqorkqG5w2gXjtw+hG4iZZRHUe
2XWJUc0QhJ1hYMtd+ZciTY6Y5uN/9lu7rs3KSoFrXgvzUeF0K+l+J6fZmUlO+KWA2yUPHGNiiskz
Z2s8EIPGrd6ozRaOjfAHN3Gf8qv8QfXBi+wAN10J5U6A7/qxXDgGpRtK4dw4LTzcqx+QGtVKnO7R
cGzM7vRX+Bi6hG6H
-----END CERTIFICATE-----

IdenTrust Public Sector Root CA 1
=================================
-----BEGIN CERTIFICATE-----
MIIFZjCCA06gAwIBAgIQCgFCgAAAAUUjz0Z8AAAAAjANBgkqhkiG9w0BAQsFADBNMQswCQYDVQQG
EwJVUzESMBAGA1UEChMJSWRlblRydXN0MSowKAYDVQQDEyFJZGVuVHJ1c3QgUHVibGljIFNlY3Rv
ciBSb290IENBIDEwHhcNMTQwMTE2MTc1MzMyWhcNMzQwMTE2MTc1MzMyWjBNMQswCQYDVQQGEwJV
UzESMBAGA1UEChMJSWRlblRydXN0MSowKAYDVQQDEyFJZGVuVHJ1c3QgUHVibGljIFNlY3RvciBS
b290IENBIDEwggIiMA0GCSqGSIb3DQEBAQUAA4ICDwAwggIKAoICAQC2IpT8pEiv6EdrCvsnduTy
P4o7ekosMSqMjbCpwzFrqHd2hCa2rIFCDQjrVVi7evi8ZX3yoG2LqEfpYnYeEe4IFNGyRBb06tD6
Hi9e28tzQa68ALBKK0CyrOE7S8ItneShm+waOh7wCLPQ5CQ1B5+ctMlSbdsHyo+1W/CD80/HLaXI
rcuVIKQxKFdYWuSNG5qrng0M8gozOSI5Cpcu81N3uURF/YTLNiCBWS2ab21ISGHKTN9T0a9SvESf
qy9rg3LvdYDaBjMbXcjaY8ZNzaxmMc3R3j6HEDbhuaR672BQssvKplbgN6+rNBM5Jeg5ZuSYeqoS
mJxZZoY+rfGwyj4GD3vwEUs3oERte8uojHH01bWRNszwFcYr3lEXsZdMUD2xlVl8BX0tIdUAvwFn
ol57plzy9yLxkA2T26pEUWbMfXYD62qoKjgZl3YNa4ph+bz27nb9cCvdKTz4Ch5bQhyLVi9VGxyh
LrXHFub4qjySjmm2AcG1hp2JDws4lFTo6tyePSW8Uybt1as5qsVATFSrsrTZ2fjXctscvG29ZV/v
iDUqZi/u9rNl8DONfJhBaUYPQxxp+pu10GFqzcpL2UyQRqsVWaFHVCkugyhfHMKiq3IXAAaOReyL
4jM9f9oZRORicsPfIsbyVtTdX5Vy7W1f90gDW/3FKqD2cyOEEBsB5wIDAQABo0IwQDAOBgNVHQ8B
Af8EBAMCAQYwDwYDVR0TAQH/BAUwAwEB/zAdBgNVHQ4EFgQU43HgntinQtnbcZFrlJPrw6PRFKMw
DQYJKoZIhvcNAQELBQADggIBAEf63QqwEZE4rU1d9+UOl1QZgkiHVIyqZJnYWv6IAcVYpZmxI1Qj
t2odIFflAWJBF9MJ23XLblSQdf4an4EKwt3X9wnQW3IV5B4Jaj0z8yGa5hV+rVHVDRDtfULAj+7A
mgjVQdZcDiFpboBhDhXAuM/FSRJSzL46zNQuOAXeNf0fb7iAaJg9TaDKQGXSc3z1i9kKlT/YPyNt
GtEqJBnZhbMX73huqVjRI9PHE+1yJX9dsXNw0H8GlwmEKYBhHfpe/3OsoOOJuBxxFcbeMX8S3OFt
m6/n6J91eEyrRjuazr8FGF1NFTwWmhlQBJqymm9li1JfPFgEKCXAZmExfrngdbkaqIHWchezxQMx
NRF4eKLg6TCMf4DfWN88uieW4oA0beOY02QnrEh+KHdcxiVhJfiFDGX6xDIvpZgF5PgLZxYWxoK4
Mhn5+bl53B/N66+rDt0b20XkeucC4pVd/GnwU2lhlXV5C15V5jgclKlZM57IcXR5f1GJtshquDDI
ajjDbp7hNxbqBWJMWxJH7ae0s1hWx0nzfxJoCTFx8G34Tkf71oXuxVhAGaQdp/lLQzfcaFpPz+vC
ZHTetBXZ9FRUGi8c15dxVJCO2SCdUyt/q4/i6jC8UDfv8Ue1fXwsBOxonbRJRBD0ckscZOf85muQ
3Wl9af0AVqW3rLatt8o+Ae+c
-----END CERTIFICATE-----

Entrust Root Certification Authority - G2
=========================================
-----BEGIN CERTIFICATE-----
MIIEPjCCAyagAwIBAgIESlOMKDANBgkqhkiG9w0BAQsFADCBvjELMAkGA1UEBhMCVVMxFjAUBgNV
BAoTDUVudHJ1c3QsIEluYy4xKDAmBgNVBAsTH1NlZSB3d3cuZW50cnVzdC5uZXQvbGVnYWwtdGVy
bXMxOTA3BgNVBAsTMChjKSAyMDA5IEVudHJ1c3QsIEluYy4gLSBmb3IgYXV0aG9yaXplZCB1c2Ug
b25seTEyMDAGA1UEAxMpRW50cnVzdCBSb290IENlcnRpZmljYXRpb24gQXV0aG9yaXR5IC0gRzIw
HhcNMDkwNzA3MTcyNTU0WhcNMzAxMjA3MTc1NTU0WjCBvjELMAkGA1UEBhMCVVMxFjAUBgNVBAoT
DUVudHJ1c3QsIEluYy4xKDAmBgNVBAsTH1NlZSB3d3cuZW50cnVzdC5uZXQvbGVnYWwtdGVybXMx
OTA3BgNVBAsTMChjKSAyMDA5IEVudHJ1c3QsIEluYy4gLSBmb3IgYXV0aG9yaXplZCB1c2Ugb25s
eTEyMDAGA1UEAxMpRW50cnVzdCBSb290IENlcnRpZmljYXRpb24gQXV0aG9yaXR5IC0gRzIwggEi
MA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQC6hLZy254Ma+KZ6TABp3bqMriVQRrJ2mFOWHLP
/vaCeb9zYQYKpSfYs1/TRU4cctZOMvJyig/3gxnQaoCAAEUesMfnmr8SVycco2gvCoe9amsOXmXz
HHfV1IWNcCG0szLni6LVhjkCsbjSR87kyUnEO6fe+1R9V77w6G7CebI6C1XiUJgWMhNcL3hWwcKU
s/Ja5CeanyTXxuzQmyWC48zCxEXFjJd6BmsqEZ+pCm5IO2/b1BEZQvePB7/1U1+cPvQXLOZprE4y
TGJ36rfo5bs0vBmLrpxR57d+tVOxMyLlbc9wPBr64ptntoP0jaWvYkxN4FisZDQSA/i2jZRjJKRx
AgMBAAGjQjBAMA4GA1UdDwEB/wQEAwIBBjAPBgNVHRMBAf8EBTADAQH/MB0GA1UdDgQWBBRqciZ6
0B7vfec7aVHUbI2fkBJmqzANBgkqhkiG9w0BAQsFAAOCAQEAeZ8dlsa2eT8ijYfThwMEYGprmi5Z
iXMRrEPR9RP/jTkrwPK9T3CMqS/qF8QLVJ7UG5aYMzyorWKiAHarWWluBh1+xLlEjZivEtRh2woZ
Rkfz6/djwUAFQKXSt/S1mja/qYh2iARVBCuch38aNzx+LaUa2NSJXsq9rD1s2G2v1fN2D807iDgi
nWyTmsQ9v4IbZT+mD12q/OWyFcq1rca8PdCE6OoGcrBNOTJ4vz4RnAuknZoh8/CbCzB428Hch0P+
vGOaysXCHMnHjf87ElgI5rY97HosTvuDls4MPGmHVHOkc8KT/1EQrBVUAdj8BbGJoX90g5pJ19xO
e4pIb4tF9g==
-----END CERTIFICATE-----

Entrust Root Certification Authority - EC1
==========================================
-----BEGIN CERTIFICATE-----
MIIC+TCCAoCgAwIBAgINAKaLeSkAAAAAUNCR+TAKBggqhkjOPQQDAzCBvzELMAkGA1UEBhMCVVMx
FjAUBgNVBAoTDUVudHJ1c3QsIEluYy4xKDAmBgNVBAsTH1NlZSB3d3cuZW50cnVzdC5uZXQvbGVn
YWwtdGVybXMxOTA3BgNVBAsTMChjKSAyMDEyIEVudHJ1c3QsIEluYy4gLSBmb3IgYXV0aG9yaXpl
ZCB1c2Ugb25seTEzMDEGA1UEAxMqRW50cnVzdCBSb290IENlcnRpZmljYXRpb24gQXV0aG9yaXR5
IC0gRUMxMB4XDTEyMTIxODE1MjUzNloXDTM3MTIxODE1NTUzNlowgb8xCzAJBgNVBAYTAlVTMRYw
FAYDVQQKEw1FbnRydXN0LCBJbmMuMSgwJgYDVQQLEx9TZWUgd3d3LmVudHJ1c3QubmV0L2xlZ2Fs
LXRlcm1zMTkwNwYDVQQLEzAoYykgMjAxMiBFbnRydXN0LCBJbmMuIC0gZm9yIGF1dGhvcml6ZWQg
dXNlIG9ubHkxMzAxBgNVBAMTKkVudHJ1c3QgUm9vdCBDZXJ0aWZpY2F0aW9uIEF1dGhvcml0eSAt
IEVDMTB2MBAGByqGSM49AgEGBSuBBAAiA2IABIQTydC6bUF74mzQ61VfZgIaJPRbiWlH47jCffHy
AsWfoPZb1YsGGYZPUxBtByQnoaD41UcZYUx9ypMn6nQM72+WCf5j7HBdNq1nd67JnXxVRDqiY1Ef
9eNi1KlHBz7MIKNCMEAwDgYDVR0PAQH/BAQDAgEGMA8GA1UdEwEB/wQFMAMBAf8wHQYDVR0OBBYE
FLdj5xrdjekIplWDpOBqUEFlEUJJMAoGCCqGSM49BAMDA2cAMGQCMGF52OVCR98crlOZF7ZvHH3h
vxGU0QOIdeSNiaSKd0bebWHvAvX7td/M/k7//qnmpwIwW5nXhTcGtXsI/esni0qU+eH6p44mCOh8
kmhtc9hvJqwhAriZtyZBWyVgrtBIGu4G
-----END CERTIFICATE-----

CFCA EV ROOT
============
-----BEGIN CERTIFICATE-----
MIIFjTCCA3WgAwIBAgIEGErM1jANBgkqhkiG9w0BAQsFADBWMQswCQYDVQQGEwJDTjEwMC4GA1UE
CgwnQ2hpbmEgRmluYW5jaWFsIENlcnRpZmljYXRpb24gQXV0aG9yaXR5MRUwEwYDVQQDDAxDRkNB
IEVWIFJPT1QwHhcNMTIwODA4MDMwNzAxWhcNMjkxMjMxMDMwNzAxWjBWMQswCQYDVQQGEwJDTjEw
MC4GA1UECgwnQ2hpbmEgRmluYW5jaWFsIENlcnRpZmljYXRpb24gQXV0aG9yaXR5MRUwEwYDVQQD
DAxDRkNBIEVWIFJPT1QwggIiMA0GCSqGSIb3DQEBAQUAA4ICDwAwggIKAoICAQDXXWvNED8fBVnV
BU03sQ7smCuOFR36k0sXgiFxEFLXUWRwFsJVaU2OFW2fvwwbwuCjZ9YMrM8irq93VCpLTIpTUnrD
7i7es3ElweldPe6hL6P3KjzJIx1qqx2hp/Hz7KDVRM8Vz3IvHWOX6Jn5/ZOkVIBMUtRSqy5J35DN
uF++P96hyk0g1CXohClTt7GIH//62pCfCqktQT+x8Rgp7hZZLDRJGqgG16iI0gNyejLi6mhNbiyW
ZXvKWfry4t3uMCz7zEasxGPrb382KzRzEpR/38wmnvFyXVBlWY9ps4deMm/DGIq1lY+wejfeWkU7
xzbh72fROdOXW3NiGUgthxwG+3SYIElz8AXSG7Ggo7cbcNOIabla1jj0Ytwli3i/+Oh+uFzJlU9f
py25IGvPa931DfSCt/SyZi4QKPaXWnuWFo8BGS1sbn85WAZkgwGDg8NNkt0yxoekN+kWzqotaK8K
gWU6cMGbrU1tVMoqLUuFG7OA5nBFDWteNfB/O7ic5ARwiRIlk9oKmSJgamNgTnYGmE69g60dWIol
hdLHZR4tjsbftsbhf4oEIRUpdPA+nJCdDC7xij5aqgwJHsfVPKPtl8MeNPo4+QgO48BdK4PRVmrJ
tqhUUy54Mmc9gn900PvhtgVguXDbjgv5E1hvcWAQUhC5wUEJ73IfZzF4/5YFjQIDAQABo2MwYTAf
BgNVHSMEGDAWgBTj/i39KNALtbq2osS/BqoFjJP7LzAPBgNVHRMBAf8EBTADAQH/MA4GA1UdDwEB
/wQEAwIBBjAdBgNVHQ4EFgQU4/4t/SjQC7W6tqLEvwaqBYyT+y8wDQYJKoZIhvcNAQELBQADggIB
ACXGumvrh8vegjmWPfBEp2uEcwPenStPuiB/vHiyz5ewG5zz13ku9Ui20vsXiObTej/tUxPQ4i9q
ecsAIyjmHjdXNYmEwnZPNDatZ8POQQaIxffu2Bq41gt/UP+TqhdLjOztUmCypAbqTuv0axn96/Ua
4CUqmtzHQTb3yHQFhDmVOdYLO6Qn+gjYXB74BGBSESgoA//vU2YApUo0FmZ8/Qmkrp5nGm9BC2sG
E5uPhnEFtC+NiWYzKXZUmhH4J/qyP5Hgzg0b8zAarb8iXRvTvyUFTeGSGn+ZnzxEk8rUQElsgIfX
BDrDMlI1Dlb4pd19xIsNER9Tyx6yF7Zod1rg1MvIB671Oi6ON7fQAUtDKXeMOZePglr4UeWJoBjn
aH9dCi77o0cOPaYjesYBx4/IXr9tgFa+iiS6M+qf4TIRnvHST4D2G0CvOJ4RUHlzEhLN5mydLIhy
PDCBBpEi6lmt2hkuIsKNuYyH4Ga8cyNfIWRjgEj1oDwYPZTISEEdQLpe/v5WOaHIz16eGWRGENoX
kbcFgKyLmZJ956LYBws2J+dIeWCKw9cTXPhyQN9Ky8+ZAAoACxGV2lZFA4gKn2fQ1XmxqI1AbQ3C
ekD6819kR5LLU7m7Wc5P/dAVUwHY3+vZ5nbv0CO7O6l5s9UCKc2Jo5YPSjXnTkLAdc0Hz+Ys63su
-----END CERTIFICATE-----

TÜRKTRUST Elektronik Sertifika Hizmet Sağlayıcısı H5
=========================================================
-----BEGIN CERTIFICATE-----
MIIEJzCCAw+gAwIBAgIHAI4X/iQggTANBgkqhkiG9w0BAQsFADCBsTELMAkGA1UEBhMCVFIxDzAN
BgNVBAcMBkFua2FyYTFNMEsGA1UECgxEVMOcUktUUlVTVCBCaWxnaSDEsGxldGnFn2ltIHZlIEJp
bGnFn2ltIEfDvHZlbmxpxJ9pIEhpem1ldGxlcmkgQS7Fni4xQjBABgNVBAMMOVTDnFJLVFJVU1Qg
RWxla3Ryb25payBTZXJ0aWZpa2EgSGl6bWV0IFNhxJ9sYXnEsWPEsXPEsSBINTAeFw0xMzA0MzAw
ODA3MDFaFw0yMzA0MjgwODA3MDFaMIGxMQswCQYDVQQGEwJUUjEPMA0GA1UEBwwGQW5rYXJhMU0w
SwYDVQQKDERUw5xSS1RSVVNUIEJpbGdpIMSwbGV0acWfaW0gdmUgQmlsacWfaW0gR8O8dmVubGnE
n2kgSGl6bWV0bGVyaSBBLsWeLjFCMEAGA1UEAww5VMOcUktUUlVTVCBFbGVrdHJvbmlrIFNlcnRp
ZmlrYSBIaXptZXQgU2HEn2xhecSxY8Sxc8SxIEg1MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIB
CgKCAQEApCUZ4WWe60ghUEoI5RHwWrom/4NZzkQqL/7hzmAD/I0Dpe3/a6i6zDQGn1k19uwsu537
jVJp45wnEFPzpALFp/kRGml1bsMdi9GYjZOHp3GXDSHHmflS0yxjXVW86B8BSLlg/kJK9siArs1m
ep5Fimh34khon6La8eHBEJ/rPCmBp+EyCNSgBbGM+42WAA4+Jd9ThiI7/PS98wl+d+yG6w8z5UNP
9FR1bSmZLmZaQ9/LXMrI5Tjxfjs1nQ/0xVqhzPMggCTTV+wVunUlm+hkS7M0hO8EuPbJbKoCPrZV
4jI3X/xml1/N1p7HIL9Nxqw/dV8c7TKcfGkAaZHjIxhT6QIDAQABo0IwQDAdBgNVHQ4EFgQUVpkH
HtOsDGlktAxQR95DLL4gwPswDgYDVR0PAQH/BAQDAgEGMA8GA1UdEwEB/wQFMAMBAf8wDQYJKoZI
hvcNAQELBQADggEBAJ5FdnsXSDLyOIspve6WSk6BGLFRRyDN0GSxDsnZAdkJzsiZ3GglE9Rc8qPo
BP5yCccLqh0lVX6Wmle3usURehnmp349hQ71+S4pL+f5bFgWV1Al9j4uPqrtd3GqqpmWRgqujuwq
URawXs3qZwQcWDD1YIq9pr1N5Za0/EKJAWv2cMhQOQwt1WbZyNKzMrcbGW3LM/nfpeYVhDfwwvJl
lpKQd/Ct9JDpEXjXk4nAPQu6KfTomZ1yju2dL+6SfaHx/126M2CFYv4HAqGEVka+lgqaE9chTLd8
B59OTj+RdPsnnRHM3eaxynFNExc5JsUpISuTKWqW+qtB4Uu2NQvAmxU=
-----END CERTIFICATE-----

TÜRKTRUST Elektronik Sertifika Hizmet Sağlayıcısı H6
=========================================================
-----BEGIN CERTIFICATE-----
MIIEJjCCAw6gAwIBAgIGfaHyZeyKMA0GCSqGSIb3DQEBCwUAMIGxMQswCQYDVQQGEwJUUjEPMA0G
A1UEBwwGQW5rYXJhMU0wSwYDVQQKDERUw5xSS1RSVVNUIEJpbGdpIMSwbGV0acWfaW0gdmUgQmls
acWfaW0gR8O8dmVubGnEn2kgSGl6bWV0bGVyaSBBLsWeLjFCMEAGA1UEAww5VMOcUktUUlVTVCBF
bGVrdHJvbmlrIFNlcnRpZmlrYSBIaXptZXQgU2HEn2xhecSxY8Sxc8SxIEg2MB4XDTEzMTIxODA5
MDQxMFoXDTIzMTIxNjA5MDQxMFowgbExCzAJBgNVBAYTAlRSMQ8wDQYDVQQHDAZBbmthcmExTTBL
BgNVBAoMRFTDnFJLVFJVU1QgQmlsZ2kgxLBsZXRpxZ9pbSB2ZSBCaWxpxZ9pbSBHw7x2ZW5sacSf
aSBIaXptZXRsZXJpIEEuxZ4uMUIwQAYDVQQDDDlUw5xSS1RSVVNUIEVsZWt0cm9uaWsgU2VydGlm
aWthIEhpem1ldCBTYcSfbGF5xLFjxLFzxLEgSDYwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEK
AoIBAQCdsGjW6L0UlqMACprx9MfMkU1xeHe59yEmFXNRFpQJRwXiM/VomjX/3EsvMsew7eKC5W/a
2uqsxgbPJQ1BgfbBOCK9+bGlprMBvD9QFyv26WZV1DOzXPhDIHiTVRZwGTLmiddk671IUP320EED
wnS3/faAz1vFq6TWlRKb55cTMgPp1KtDWxbtMyJkKbbSk60vbNg9tvYdDjTu0n2pVQ8g9P0pu5Fb
HH3GQjhtQiht1AH7zYiXSX6484P4tZgvsycLSF5W506jM7NE1qXyGJTtHB6plVxiSvgNZ1GpryHV
+DKdeboaX+UEVU0TRv/yz3THGmNtwx8XEsMeED5gCLMxAgMBAAGjQjBAMB0GA1UdDgQWBBTdVRcT
9qzoSCHK77Wv0QAy7Z6MtTAOBgNVHQ8BAf8EBAMCAQYwDwYDVR0TAQH/BAUwAwEB/zANBgkqhkiG
9w0BAQsFAAOCAQEAb1gNl0OqFlQ+v6nfkkU/hQu7VtMMUszIv3ZnXuaqs6fvuay0EBQNdH49ba3R
fdCaqaXKGDsCQC4qnFAUi/5XfldcEQlLNkVS9z2sFP1E34uXI9TDwe7UU5X+LEr+DXCqu4svLcsy
o4LyVN/Y8t3XSHLuSqMplsNEzm61kod2pLv0kmzOLBQJZo6NrRa1xxsJYTvjIKIDgI6tflEATseW
hvtDmHd9KMeP2Cpu54Rvl0EpABZeTeIT6lnAY2c6RPuY/ATTMHKm9ocJV612ph1jmv3XZch4gyt1
O6VbuA1df74jrlZVlFjvH4GMKrLN5ptjnhi85WsGtAuYSyher4hYyw==
-----END CERTIFICATE-----

Certinomis - Root CA
====================
-----BEGIN CERTIFICATE-----
MIIFkjCCA3qgAwIBAgIBATANBgkqhkiG9w0BAQsFADBaMQswCQYDVQQGEwJGUjETMBEGA1UEChMK
Q2VydGlub21pczEXMBUGA1UECxMOMDAwMiA0MzM5OTg5MDMxHTAbBgNVBAMTFENlcnRpbm9taXMg
LSBSb290IENBMB4XDTEzMTAyMTA5MTcxOFoXDTMzMTAyMTA5MTcxOFowWjELMAkGA1UEBhMCRlIx
EzARBgNVBAoTCkNlcnRpbm9taXMxFzAVBgNVBAsTDjAwMDIgNDMzOTk4OTAzMR0wGwYDVQQDExRD
ZXJ0aW5vbWlzIC0gUm9vdCBDQTCCAiIwDQYJKoZIhvcNAQEBBQADggIPADCCAgoCggIBANTMCQos
P5L2fxSeC5yaah1AMGT9qt8OHgZbn1CF6s2Nq0Nn3rD6foCWnoR4kkjW4znuzuRZWJflLieY6pOo
d5tK8O90gC3rMB+12ceAnGInkYjwSond3IjmFPnVAy//ldu9n+ws+hQVWZUKxkd8aRi5pwP5ynap
z8dvtF4F/u7BUrJ1Mofs7SlmO/NKFoL21prbcpjp3vDFTKWrteoB4owuZH9kb/2jJZOLyKIOSY00
8B/sWEUuNKqEUL3nskoTuLAPrjhdsKkb5nPJWqHZZkCqqU2mNAKthH6yI8H7KsZn9DS2sJVqM09x
RLWtwHkziOC/7aOgFLScCbAK42C++PhmiM1b8XcF4LVzbsF9Ri6OSyemzTUK/eVNfaoqoynHWmgE
6OXWk6RiwsXm9E/G+Z8ajYJJGYrKWUM66A0ywfRMEwNvbqY/kXPLynNvEiCL7sCCeN5LLsJJwx3t
FvYk9CcbXFcx3FXuqB5vbKziRcxXV4p1VxngtViZSTYxPDMBbRZKzbgqg4SGm/lg0h9tkQPTYKbV
PZrdd5A9NaSfD171UkRpucC63M9933zZxKyGIjK8e2uR73r4F2iw4lNVYC2vPsKD2NkJK/DAZNuH
i5HMkesE/Xa0lZrmFAYb1TQdvtj/dBxThZngWVJKYe2InmtJiUZ+IFrZ50rlau7SZRFDAgMBAAGj
YzBhMA4GA1UdDwEB/wQEAwIBBjAPBgNVHRMBAf8EBTADAQH/MB0GA1UdDgQWBBTvkUz1pcMw6C8I
6tNxIqSSaHh02TAfBgNVHSMEGDAWgBTvkUz1pcMw6C8I6tNxIqSSaHh02TANBgkqhkiG9w0BAQsF
AAOCAgEAfj1U2iJdGlg+O1QnurrMyOMaauo++RLrVl89UM7g6kgmJs95Vn6RHJk/0KGRHCwPT5iV
WVO90CLYiF2cN/z7ZMF4jIuaYAnq1fohX9B0ZedQxb8uuQsLrbWwF6YSjNRieOpWauwK0kDDPAUw
Pk2Ut59KA9N9J0u2/kTO+hkzGm2kQtHdzMjI1xZSg081lLMSVX3l4kLr5JyTCcBMWwerx20RoFAX
lCOotQqSD7J6wWAsOMwaplv/8gzjqh8c3LigkyfeY+N/IZ865Z764BNqdeuWXGKRlI5nU7aJ+BIJ
y29SWwNyhlCVCNSNh4YVH5Uk2KRvms6knZtt0rJ2BobGVgjF6wnaNsIbW0G+YSrjcOa4pvi2WsS9
Iff/ql+hbHY5ZtbqTFXhADObE5hjyW/QASAJN1LnDE8+zbz1X5YnpyACleAu6AdBBR8Vbtaw5Bng
DwKTACdyxYvRVB9dSsNAl35VpnzBMwQUAR1JIGkLGZOdblgi90AMRgwjY/M50n92Uaf0yKHxDHYi
I0ZSKS3io0EHVmmY0gUJvGnHWmHNj4FgFU2A3ZDifcRQ8ow7bkrHxuaAKzyBvBGAFhAn1/DNP3nM
cyrDflOR1m749fPH0FFNjkulW+YZFzvWgQncItzujrnEj1PhZ7szuIgVRs/taTX/dQ1G885x4cVr
hkIGuUE=
-----END CERTIFICATE-----
CACERT;
    }

    public function get($url)
    {
        $context = $this->getStreamContext($url);
        $result = file_get_contents($url, false, $context);

        if ($result && extension_loaded('zlib')) {
            $decode = false;
            foreach ($http_response_header as $header) {
                if (preg_match('{^content-encoding: *gzip *$}i', $header)) {
                    $decode = true;
                    continue;
                } elseif (preg_match('{^HTTP/}i', $header)) {
                    $decode = false;
                }
            }

            if ($decode) {
                if (version_compare(PHP_VERSION, '5.4.0', '>=')) {
                    $result = zlib_decode($result);
                } else {
                    // work around issue with gzuncompress & co that do not work with all gzip checksums
                    $result = file_get_contents('compress.zlib://data:application/octet-stream;base64,' . base64_encode($result));
                }

                if (!$result) {
                    throw new RuntimeException('Failed to decode zlib stream');
                }
            }
        }

        return $result;
    }

    protected function getStreamContext($url)
    {
        if ($this->disableTls === false) {
            $host = parse_url($url, PHP_URL_HOST);
            if (PHP_VERSION_ID < 50600) {
                $this->options['ssl']['CN_match'] = $host;
                $this->options['ssl']['SNI_server_name'] = $host;
            }
        }
        // Keeping the above mostly isolated from the code copied from Composer.
        return $this->getMergedStreamContext($url);
    }

    /**
     * function copied from Composer\Util\StreamContextFactory::getContext
     *
     * Any changes should be applied there as well, or backported here.
     *
     * @param string $url URL the context is to be used for
     * @return resource Default context
     * @throws \RuntimeException if https proxy required and OpenSSL uninstalled
     */
    protected function getMergedStreamContext($url)
    {
        $options = $this->options;

        // Handle system proxy
        if (!empty($_SERVER['HTTP_PROXY']) || !empty($_SERVER['http_proxy'])) {
            // Some systems seem to rely on a lowercased version instead...
            $proxy = parse_url(!empty($_SERVER['http_proxy']) ? $_SERVER['http_proxy'] : $_SERVER['HTTP_PROXY']);
        }

        if (!empty($proxy)) {
            $proxyURL = isset($proxy['scheme']) ? $proxy['scheme'] . '://' : '';
            $proxyURL .= isset($proxy['host']) ? $proxy['host'] : '';

            if (isset($proxy['port'])) {
                $proxyURL .= ":" . $proxy['port'];
            } elseif ('http://' == substr($proxyURL, 0, 7)) {
                $proxyURL .= ":80";
            } elseif ('https://' == substr($proxyURL, 0, 8)) {
                $proxyURL .= ":443";
            }

            // http(s):// is not supported in proxy
            $proxyURL = str_replace(array('http://', 'https://'), array('tcp://', 'ssl://'), $proxyURL);

            if (0 === strpos($proxyURL, 'ssl:') && !extension_loaded('openssl')) {
                throw new RuntimeException('You must enable the openssl extension to use a proxy over https');
            }

            $options['http'] = array(
                'proxy' => $proxyURL,
            );

            // enabled request_fulluri unless it is explicitly disabled
            switch (parse_url($url, PHP_URL_SCHEME)) {
                case 'http': // default request_fulluri to true
                    $reqFullUriEnv = getenv('HTTP_PROXY_REQUEST_FULLURI');
                    if ($reqFullUriEnv === false || $reqFullUriEnv === '' || (strtolower($reqFullUriEnv) !== 'false' && (bool)$reqFullUriEnv)) {
                        $options['http']['request_fulluri'] = true;
                    }
                    break;
                case 'https': // default request_fulluri to true
                    $reqFullUriEnv = getenv('HTTPS_PROXY_REQUEST_FULLURI');
                    if ($reqFullUriEnv === false || $reqFullUriEnv === '' || (strtolower($reqFullUriEnv) !== 'false' && (bool)$reqFullUriEnv)) {
                        $options['http']['request_fulluri'] = true;
                    }
                    break;
            }


            if (isset($proxy['user'])) {
                $auth = urldecode($proxy['user']);
                if (isset($proxy['pass'])) {
                    $auth .= ':' . urldecode($proxy['pass']);
                }
                $auth = base64_encode($auth);

                $options['http']['header'] = "Proxy-Authorization: Basic {$auth}\r\n";
            }
        }

        if (isset($options['http']['header'])) {
            $options['http']['header'] .= "Connection: close\r\n";
        } else {
            $options['http']['header'] = "Connection: close\r\n";
        }
        if (extension_loaded('zlib')) {
            $options['http']['header'] .= "Accept-Encoding: gzip\r\n";
        }
        $options['http']['header'] .= "User-Agent: Composer Installer\r\n";
        $options['http']['protocol_version'] = 1.1;

        return stream_context_create($options);
    }

}
