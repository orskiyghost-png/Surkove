<?php
declare(strict_types=1);

const MAX_ARCHIVE_BYTES = 52428800;
const MAX_RELEASE_BYTES = 104857600;
const MAX_FILE_BYTES = 26214400;
const MAX_RELEASE_FILES = 2000;
const GITHUB_API = 'https://api.github.com';

$webRoot = __DIR__;
$privateDirectory = dirname(__DIR__) . '/private';
$configPath = $privateDirectory . '/deploy-config.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Cache-Control: no-store, private');

session_name('SURKOVE_DEPLOY');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();

function escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function render_page(string $content, string $title = 'Surkove · Управление'): never
{
    $safeTitle = escape_html($title);
    echo <<<HTML
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <title>{$safeTitle}</title>
  <style>
    :root{font-family:Arial,Helvetica,sans-serif;color:#171717;background:#f7f7f5;font-size:16px;line-height:1.5;font-synthesis:none;--black:#171717;--gray:#696966;--line:#e2e2df;--paper:#fff;--space:24px}
    *{box-sizing:border-box}body{margin:0;min-width:320px}a{color:inherit}button,input,select{font:inherit}
    .wrap{width:min(100% - 32px,760px);margin:0 auto;padding:32px 0 64px}.top{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line);padding-bottom:18px;margin-bottom:32px}.brand{font-size:20px;font-weight:600;text-decoration:none}.muted,.eyebrow{color:var(--gray);font-size:12px}.eyebrow{text-transform:uppercase;letter-spacing:.04em}.panel{background:var(--paper);border:1px solid var(--line);padding:24px;margin:16px 0}.panel h1,.panel h2{margin:0 0 16px;font-weight:500;line-height:1.2}.panel h1{font:400 36px/1.1 Georgia,'Times New Roman',serif}.panel h2{font-size:18px}.row{display:flex;justify-content:space-between;gap:16px;padding:12px 0;border-top:1px solid var(--line)}.row:first-of-type{border-top:0}.value{overflow-wrap:anywhere;text-align:right}.actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:18px}button{min-height:46px;padding:10px 16px;border:1px solid var(--black);background:var(--black);color:#fff;cursor:pointer}button.secondary{color:var(--black);background:#fff}button:disabled{opacity:.45;cursor:not-allowed}input,select{width:100%;min-height:46px;padding:10px 12px;border:1px solid #c9c9c5;border-radius:0;background:#fff;color:var(--black)}label{display:block;margin:14px 0 6px;font-size:13px}.notice{padding:14px 16px;border:1px solid var(--line);margin:16px 0;background:#fff}.notice.error{border-color:#777}.release{padding:12px 0;border-top:1px solid var(--line);overflow-wrap:anywhere}.release:first-of-type{border-top:0}.sha,code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px}.footer{margin-top:24px;color:var(--gray);font-size:12px}
    :focus-visible{outline:2px solid #171717;outline-offset:3px}
    @media(max-width:520px){.wrap{width:min(100% - 24px,760px);padding-top:18px}.panel{padding:18px}.panel h1{font-size:32px}.row{display:block}.value{text-align:left;margin-top:4px}.actions{display:grid}.actions button{width:100%}.top{margin-bottom:20px}}
    @media(prefers-reduced-motion:reduce){*,*::before,*::after{scroll-behavior:auto!important;transition:none!important}}
  </style>
</head>
<body><main class="wrap"><header class="top"><a class="brand" href="/deploy.php">surkove</a><span class="muted">Управление сайтом</span></header>{$content}</main></body>
</html>
HTML;
    exit;
}

function show_setup_error(string $message): never
{
    render_page('<section class="panel"><p class="eyebrow">Настройка</p><h1>Панель не настроена</h1><p>' . escape_html($message) . '</p></section>', 'Surkove · Настройка');
}

function redirect_panel(): never
{
    header('Location: deploy.php', true, 303);
    exit;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf" value="' . escape_html(csrf_token()) . '">';
}

function private_json_write(string $path, array $data): void
{
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
    $handle = @fopen($temporary, 'xb');
    if ($handle === false) {
        throw new RuntimeException('Не удалось создать защищённый файл состояния.');
    }
    try {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (fwrite($handle, $json) !== strlen($json)) {
            throw new RuntimeException('Не удалось записать файл состояния.');
        }
        fflush($handle);
        if (function_exists('fsync')) {
            fsync($handle);
        }
    } finally {
        fclose($handle);
    }
    @chmod($temporary, 0600);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Не удалось атомарно сохранить состояние.');
    }
}

function read_private_json(string $path): array
{
    if (!is_file($path) || is_link($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
        return [];
    }
    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    } catch (Throwable) {
        return [];
    }
}

function login_attempt_count(string $directory, string $ip, string $pepper, bool $failed = false, bool $clear = false): int
{
    $path = $directory . '/.deploy-login-limits.json';
    $handle = @fopen($path, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new RuntimeException('Ограничитель входа недоступен. Проверьте права каталога private.');
    }
    @chmod($path, 0600);
    try {
        rewind($handle);
        $raw = stream_get_contents($handle);
        $data = json_decode(is_string($raw) ? $raw : '', true);
        $data = is_array($data) ? $data : [];
        $key = hash_hmac('sha256', $ip, $pepper);
        $cutoff = time() - 3600;
        foreach ($data as $storedKey => $timestamps) {
            $timestamps = is_array($timestamps) ? array_values(array_filter($timestamps, static fn($time): bool => is_int($time) && $time > $cutoff)) : [];
            if ($timestamps === []) {
                unset($data[$storedKey]);
            } else {
                $data[$storedKey] = $timestamps;
            }
        }
        if ($clear) {
            unset($data[$key]);
        }
        if ($failed) {
            $data[$key] ??= [];
            $data[$key][] = time();
        }
        $attempts = array_values(array_filter($data[$key] ?? [], static fn(int $time): bool => $time > time() - 900));
        $data[$key] = $attempts;
        ftruncate($handle, 0);
        rewind($handle);
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        if (fwrite($handle, $json) !== strlen($json)) {
            throw new RuntimeException('Не удалось обновить ограничитель входа.');
        }
        fflush($handle);
        return count($attempts);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function validate_config(array $config): void
{
    foreach (['github_owner', 'github_repository', 'deploy_branch', 'github_token', 'panel_password_hash', 'apache_rewrite_confirmed'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key])) {
            if ($key === 'apache_rewrite_confirmed' && isset($config[$key]) && is_bool($config[$key])) {
                continue;
            }
            throw new RuntimeException('В конфигурации отсутствует обязательное текстовое поле: ' . $key . '.');
        }
    }
    if (!preg_match('/\A[A-Za-z0-9-]+\z/', $config['github_owner'])
        || !preg_match('/\A[A-Za-z0-9._-]+\z/', $config['github_repository'])
        || !preg_match('/\A[A-Za-z0-9._-]+\z/', $config['deploy_branch'])) {
        throw new RuntimeException('Некорректные имя владельца, репозитория или ветки в конфигурации.');
    }
    if ($config['github_owner'] !== 'orskiyghost-png' || $config['github_repository'] !== 'Surkove' || $config['deploy_branch'] !== 'deploy') {
        throw new RuntimeException('Конфигурация должна указывать на orskiyghost-png/Surkove, ветка deploy.');
    }
    if (str_contains($config['github_token'], "\r") || str_contains($config['github_token'], "\n")) {
        throw new RuntimeException('Некорректное значение GitHub token.');
    }
    if (!is_bool($config['apache_rewrite_confirmed'])) {
        throw new RuntimeException('apache_rewrite_confirmed должен быть true или false.');
    }
    if ($config['panel_password_hash'] === '' || password_get_info($config['panel_password_hash'])['algo'] === null) {
        throw new RuntimeException('Укажите хеш пароля панели, созданный функцией password_hash().');
    }
}

function request_https(string $url, string $token, callable $writer, int $maximumBytes): array
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('На сервере не включено PHP-расширение cURL.');
    }
    if (!str_starts_with($url, 'https://')) {
        throw new RuntimeException('Разрешены только HTTPS-запросы к GitHub.');
    }
    $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $location = '';
    $tooLarge = false;
    $total = 0;
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Surkove-Deploy-Panel/1.0',
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location): int {
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($writer, $maximumBytes, &$total, &$tooLarge): int {
            $length = strlen($chunk);
            if ($total + $length > $maximumBytes) {
                $tooLarge = true;
                return 0;
            }
            $written = $writer($chunk);
            if (!is_int($written) || $written !== $length) {
                return 0;
            }
            $total += $written;
            return $written;
        },
    ]);
    $result = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($result === false) {
        throw new RuntimeException($tooLarge ? 'Ответ GitHub превышает допустимый размер.' : 'Не удалось получить ответ от GitHub по HTTPS.');
    }
    return ['status' => $status, 'location' => $location, 'bytes' => $total];
}

function github_json(array $config, string $path): array
{
    $body = '';
    $response = request_https(GITHUB_API . $path, $config['github_token'], static function (string $chunk) use (&$body): int {
        $body .= $chunk;
        return strlen($chunk);
    }, 1048576);
    if ($response['status'] !== 200) {
        throw new RuntimeException('GitHub API вернул HTTP ' . $response['status'] . '.');
    }
    try {
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        throw new RuntimeException('GitHub API вернул некорректный JSON.');
    }
    if (!is_array($data)) {
        throw new RuntimeException('GitHub API вернул неожиданный ответ.');
    }
    return $data;
}

function latest_commit_sha(array $config): string
{
    $owner = rawurlencode($config['github_owner']);
    $repo = rawurlencode($config['github_repository']);
    $branch = rawurlencode($config['deploy_branch']);
    $data = github_json($config, '/repos/' . $owner . '/' . $repo . '/git/ref/heads/' . $branch);
    $sha = $data['object']['sha'] ?? '';
    if (($data['ref'] ?? '') !== 'refs/heads/' . $config['deploy_branch'] || !is_string($sha) || !preg_match('/\A[a-f0-9]{40}\z/i', $sha)) {
        throw new RuntimeException('GitHub вернул некорректную ссылку или commit SHA.');
    }
    return strtolower($sha);
}

function validate_codeload_url(string $url, string $sha): void
{
    $parts = parse_url($url);
    if (!is_array($parts)
        || ($parts['scheme'] ?? '') !== 'https'
        || strtolower((string) ($parts['host'] ?? '')) !== 'codeload.github.com'
        || isset($parts['user']) || isset($parts['pass'])
        || (isset($parts['port']) && $parts['port'] !== 443)
        || !str_contains((string) ($parts['path'] ?? ''), $sha)) {
        throw new RuntimeException('GitHub вернул недопустимый адрес ZIP-архива.');
    }
}

function probe_zip(array $config, string $sha): bool
{
    $body = '';
    $path = '/repos/' . rawurlencode($config['github_owner']) . '/' . rawurlencode($config['github_repository']) . '/zipball/' . rawurlencode($sha);
    $response = request_https(GITHUB_API . $path, $config['github_token'], static function (string $chunk) use (&$body): int {
        $body .= $chunk;
        return strlen($chunk);
    }, 65536);
    if ($response['status'] === 302) {
        validate_codeload_url($response['location'], $sha);
        return true;
    }
    if ($response['status'] === 200 && str_starts_with($body, "PK\x03\x04")) {
        return true;
    }
    throw new RuntimeException('ZIP endpoint GitHub недоступен (HTTP ' . $response['status'] . ').');
}

function download_zip(array $config, string $sha, string $destination): void
{
    $handle = @fopen($destination, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Не удалось создать временный ZIP в private.');
    }
    try {
        $path = '/repos/' . rawurlencode($config['github_owner']) . '/' . rawurlencode($config['github_repository']) . '/zipball/' . rawurlencode($sha);
        $response = request_https(GITHUB_API . $path, $config['github_token'], static function (string $chunk) use ($handle): int {
            return fwrite($handle, $chunk) ?: 0;
        }, MAX_ARCHIVE_BYTES);
        if ($response['status'] === 302) {
            validate_codeload_url($response['location'], $sha);
            if (!ftruncate($handle, 0) || !rewind($handle)) {
                throw new RuntimeException('Не удалось подготовить временный ZIP.');
            }
            $response = request_https($response['location'], '', static function (string $chunk) use ($handle): int {
                return fwrite($handle, $chunk) ?: 0;
            }, MAX_ARCHIVE_BYTES);
        }
        fflush($handle);
        if ($response['status'] !== 200 || $response['bytes'] < 4 || $response['bytes'] > MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('ZIP не скачан: неверный HTTP-код или размер архива.');
        }
    } finally {
        fclose($handle);
    }
}

function ensure_release_directory(string $webRoot): string
{
    $directory = $webRoot . '/releases';
    if (is_link($directory)) {
        throw new RuntimeException('Каталог releases не должен быть symlink.');
    }
    if (!is_dir($directory) && !@mkdir($directory, 0755)) {
        throw new RuntimeException('Не удалось создать каталог releases в www.');
    }
    if (!is_writable($directory) || realpath($directory) === false || realpath(dirname($directory)) !== realpath($webRoot)) {
        throw new RuntimeException('Каталог releases недоступен или находится вне www.');
    }
    return $directory;
}

function normalize_asset_path(string $root, string $url, string $base = ''): ?string
{
    $url = trim($url);
    if ($url === '' || $url[0] === '#' || str_starts_with($url, '//') || preg_match('/\A(?:[a-z][a-z0-9+.-]*:)/i', $url)) {
        return null;
    }
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return null;
    }
    $path = rawurldecode($path);
    if (str_contains($path, "\0") || str_contains($path, '\\')) {
        throw new RuntimeException('В HTML/CSS найден небезопасный путь ресурса.');
    }
    $segments = str_starts_with($path, '/') ? [] : array_values(array_filter(explode('/', trim($base, '/')), static fn(string $part): bool => $part !== ''));
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if ($segments === []) {
                throw new RuntimeException('Ссылка на ресурс выходит за пределы релиза.');
            }
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }
    if ($segments === []) {
        return null;
    }
    $candidate = $root . '/' . implode('/', $segments);
    $resolved = realpath($candidate);
    $realRoot = realpath($root);
    if ($resolved === false || $realRoot === false || !str_starts_with($resolved, $realRoot . DIRECTORY_SEPARATOR) || !is_file($resolved)) {
        throw new RuntimeException('Ресурс сборки отсутствует или выходит за каталог релиза: ' . implode('/', $segments));
    }
    return $resolved;
}

function validate_static_tree(string $root): array
{
    $index = $root . '/index.html';
    $assets = $root . '/assets';
    if (!is_file($index) || is_link($index) || !is_dir($assets) || is_link($assets)) {
        throw new RuntimeException('Сборка должна содержать index.html и каталог assets/.');
    }
    $files = 0;
    $bytes = 0;
    $hasJs = false;
    $hasCss = false;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if ($item->isLink()) {
            throw new RuntimeException('В релизе обнаружен symlink.');
        }
        if ($item->isDir()) {
            continue;
        }
        if (!$item->isFile()) {
            throw new RuntimeException('В релизе обнаружен специальный файл.');
        }
        $relative = substr($path, strlen($root) + 1);
        if ($relative !== '.surkove-release.json' && preg_match('/(?:^|\/)\./', $relative)) {
            throw new RuntimeException('Скрытые файлы в сборке запрещены.');
        }
        if (preg_match('/\.(?:php\d*|phtml|phar|cgi|pl|py|sh|user\.ini|htaccess|htpasswd)$/i', $relative)) {
            throw new RuntimeException('В статической сборке обнаружен исполняемый или серверный файл.');
        }
        $files++;
        $bytes += $item->getSize();
        if ($files > MAX_RELEASE_FILES || $bytes > MAX_RELEASE_BYTES) {
            throw new RuntimeException('Сборка превышает лимит количества файлов или размера.');
        }
        if (str_starts_with($relative, 'assets/')) {
            $hasJs = $hasJs || preg_match('/\.js$/i', $relative) === 1;
            $hasCss = $hasCss || preg_match('/\.css$/i', $relative) === 1;
        }
    }
    if (!$hasJs || !$hasCss) {
        throw new RuntimeException('В assets/ должны присутствовать файлы JavaScript и CSS.');
    }
    $html = file_get_contents($index);
    if (!is_string($html)) {
        throw new RuntimeException('Не удалось прочитать index.html.');
    }
    preg_match_all('/\b(?:src|href)\s*=\s*(["\'])(.*?)\1/i', $html, $matches);
    foreach ($matches[2] as $url) {
        normalize_asset_path($root, html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    foreach (glob($assets . '/*.css') ?: [] as $cssFile) {
        $css = file_get_contents($cssFile);
        if (!is_string($css)) {
            throw new RuntimeException('Не удалось прочитать CSS-файл сборки.');
        }
        preg_match_all('/url\(\s*(["\']?)(.*?)\1\s*\)/i', $css, $cssMatches);
        foreach ($cssMatches[2] as $url) {
            normalize_asset_path($root, $url, 'assets');
        }
    }
    return ['files' => $files, 'bytes' => $bytes];
}

function extract_zip_safely(string $zipPath, string $stage): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('На сервере не включено PHP-расширение ZipArchive.');
    }
    $archive = new ZipArchive();
    if ($archive->open($zipPath) !== true) {
        throw new RuntimeException('GitHub ZIP не удалось открыть.');
    }
    $seen = [];
    $prefix = null;
    $total = 0;
    $fileCount = 0;
    try {
        if ($archive->numFiles < 1 || $archive->numFiles > MAX_RELEASE_FILES + 100) {
            throw new RuntimeException('ZIP содержит недопустимое количество записей.');
        }
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $stat = $archive->statIndex($index);
            if (!is_array($stat) || !isset($stat['name'], $stat['size'])) {
                throw new RuntimeException('ZIP содержит некорректную запись.');
            }
            $name = $stat['name'];
            if (!is_string($name) || $name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/\A[A-Za-z]:/', $name)) {
                throw new RuntimeException('ZIP содержит абсолютный или некорректный путь.');
            }
            $trimmed = rtrim($name, '/');
            $parts = explode('/', $trimmed);
            if (count($parts) < 1) {
                throw new RuntimeException('ZIP содержит пустой путь.');
            }
            foreach ($parts as $part) {
                if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.') || preg_match('/[<>:"|?*\x00-\x1f]/', $part)) {
                    throw new RuntimeException('ZIP содержит traversal, скрытый или специальный путь.');
                }
            }
            if ($prefix === null) {
                $prefix = $parts[0];
            } elseif ($prefix !== $parts[0]) {
                throw new RuntimeException('ZIP должен содержать один корневой каталог GitHub.');
            }
            if (count($parts) === 1) {
                continue;
            }
            $relative = implode('/', array_slice($parts, 1));
            $unixMode = 0;
            $operatingSystem = 0;
            if (!$archive->getExternalAttributesIndex($index, $operatingSystem, $unixMode)) {
                throw new RuntimeException('Не удалось проверить тип файла в ZIP.');
            }
            $fileType = ($unixMode >> 16) & 0170000;
            if ($operatingSystem === ZipArchive::OPSYS_UNIX && $fileType !== 0 && !in_array($fileType, [0040000, 0100000], true)) {
                throw new RuntimeException('Symlink и специальные файлы в ZIP запрещены.');
            }
            $isDirectory = str_ends_with($name, '/') || $fileType === 0040000;
            if ($fileType === 0100000 && $isDirectory) {
                throw new RuntimeException('ZIP содержит конфликтующий тип файла.');
            }
            $target = $stage . '/' . $relative;
            if (isset($seen[$relative])) {
                throw new RuntimeException('ZIP содержит повторяющиеся пути.');
            }
            $seen[$relative] = true;
            if ($isDirectory) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new RuntimeException('Не удалось создать каталог релиза.');
                }
                continue;
            }
            $size = (int) $stat['size'];
            if ($size < 0 || $size > MAX_FILE_BYTES || $total + $size > MAX_RELEASE_BYTES || ++$fileCount > MAX_RELEASE_FILES) {
                throw new RuntimeException('ZIP превышает лимит размера или количества файлов.');
            }
            $total += $size;
            if (preg_match('/\.(?:php\d*|phtml|phar|cgi|pl|py|sh|user\.ini|htaccess|htpasswd)$/i', $relative)) {
                throw new RuntimeException('ZIP содержит исполняемый или серверный файл.');
            }
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new RuntimeException('Не удалось создать каталог файла релиза.');
            }
            $input = $archive->getStream($name);
            $output = @fopen($target, 'xb');
            if ($input === false || $output === false) {
                if (is_resource($input)) {
                    fclose($input);
                }
                throw new RuntimeException('Не удалось безопасно распаковать файл.');
            }
            $copied = stream_copy_to_stream($input, $output, $size + 1);
            fclose($input);
            fclose($output);
            if ($copied !== $size) {
                throw new RuntimeException('Размер распакованного файла не совпадает с ZIP.');
            }
            @chmod($target, 0644);
        }
    } finally {
        $archive->close();
    }
    if ($prefix === null || $fileCount < 1) {
        throw new RuntimeException('ZIP не содержит файлов сайта.');
    }
    return validate_static_tree($stage);
}

function set_tree_readonly(string $path): void
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        @chmod($item->getPathname(), $item->isDir() ? 0555 : 0444);
    }
    @chmod($path, 0555);
}

function delete_tree(string $path): void
{
    if (is_link($path) || !is_dir($path)) {
        @unlink($path);
        return;
    }
    @chmod($path, 0755);
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $child = $path . '/' . $name;
        if (is_link($child) || !is_dir($child)) {
            @chmod($child, 0644);
            @unlink($child);
        } else {
            delete_tree($child);
        }
    }
    @rmdir($path);
}

function release_marker(string $directory, string $sha): ?array
{
    if (!preg_match('/\A[a-f0-9]{40}\z/i', $sha) || is_link($directory) || !is_dir($directory)) {
        return null;
    }
    $marker = $directory . '/.surkove-release.json';
    $data = read_private_json($marker);
    if (($data['commit'] ?? '') !== strtolower($sha) || !isset($data['installed_at']) || !is_string($data['installed_at'])) {
        return null;
    }
    return $data;
}

function release_list(string $webRoot): array
{
    $base = $webRoot . '/releases';
    if (!is_dir($base) || is_link($base)) {
        return [];
    }
    $releases = [];
    foreach (scandir($base) ?: [] as $sha) {
        if (!preg_match('/\A[a-f0-9]{40}\z/i', $sha)) {
            continue;
        }
        $marker = release_marker($base . '/' . $sha, $sha);
        if ($marker !== null) {
            $releases[] = ['sha' => strtolower($sha), 'installed_at' => $marker['installed_at']];
        }
    }
    usort($releases, static fn(array $a, array $b): int => strcmp($b['installed_at'], $a['installed_at']) ?: strcmp($b['sha'], $a['sha']));
    return $releases;
}

function valid_release(string $webRoot, string $sha): bool
{
    $directory = $webRoot . '/releases/' . $sha;
    if (release_marker($directory, $sha) === null) {
        return false;
    }
    try {
        validate_static_tree($directory);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function active_sha(string $webRoot): ?string
{
    $path = $webRoot . '/.htaccess';
    if (!is_file($path) || is_link($path)) {
        return null;
    }
    $contents = file_get_contents($path);
    if (!is_string($contents) || !preg_match('/^# SURKOVE_ACTIVE_RELEASE: ([a-f0-9]{40})$/mi', $contents, $match)) {
        return null;
    }
    $sha = strtolower($match[1]);
    return release_marker($webRoot . '/releases/' . $sha, $sha) !== null ? $sha : null;
}

function write_active_htaccess(string $webRoot, string $sha): void
{
    if (!preg_match('/\A[a-f0-9]{40}\z/', $sha) || is_link($webRoot . '/.htaccess') || !is_writable($webRoot)) {
        throw new RuntimeException('Невозможно безопасно обновить .htaccess в www.');
    }
    $contents = "# Managed by Surkove deploy.php\n# SURKOVE_ACTIVE_RELEASE: {$sha}\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteRule ^deploy\\.php$ - [END,NC]\n    RewriteRule ^\\.well-known(?:/|$) - [END]\n    RewriteRule ^releases(?:/|$) - [F,END,NC]\n    RewriteRule ^$ /releases/{$sha}/index.html [END]\n    RewriteRule ^(.*)$ /releases/{$sha}/\$1 [END]\n</IfModule>\n";
    $temporary = $webRoot . '/.htaccess.tmp-' . bin2hex(random_bytes(8));
    $handle = @fopen($temporary, 'xb');
    if ($handle === false) {
        throw new RuntimeException('Не удалось создать временный .htaccess.');
    }
    try {
        if (fwrite($handle, $contents) !== strlen($contents)) {
            throw new RuntimeException('Не удалось записать временный .htaccess.');
        }
        fflush($handle);
    } finally {
        fclose($handle);
    }
    @chmod($temporary, 0644);
    if (!@rename($temporary, $webRoot . '/.htaccess')) {
        @unlink($temporary);
        throw new RuntimeException('Не удалось атомарно активировать релиз; прежний .htaccess сохранён.');
    }
}

function cleanup_releases(string $webRoot, string $active): void
{
    $releases = release_list($webRoot);
    $keep = [$active => true];
    foreach (array_slice($releases, 0, 2) as $release) {
        $keep[$release['sha']] = true;
    }
    foreach ($releases as $release) {
        if (count($keep) >= 5) {
            break;
        }
        $keep[$release['sha']] = true;
    }
    $base = realpath($webRoot . '/releases');
    if ($base === false) {
        return;
    }
    foreach ($releases as $release) {
        $sha = $release['sha'];
        $directory = $base . '/' . $sha;
        if (isset($keep[$sha]) || release_marker($directory, $sha) === null || is_link($directory)) {
            continue;
        }
        $real = realpath($directory);
        if ($real !== false && dirname($real) === $base) {
            delete_tree($real);
        }
    }
}

function activate_release(string $webRoot, string $sha): void
{
    if (!valid_release($webRoot, $sha)) {
        throw new RuntimeException('Выбранный релиз отсутствует или повреждён.');
    }
    write_active_htaccess($webRoot, $sha);
}

function extract_release(array $config, string $webRoot, string $privateDirectory, string $sha): void
{
    if (!extension_loaded('curl') || !class_exists('ZipArchive')) {
        throw new RuntimeException('Для загрузки нужны PHP cURL и ZipArchive.');
    }
    $base = ensure_release_directory($webRoot);
    $final = $base . '/' . $sha;
    if (file_exists($final) || is_link($final)) {
        throw new RuntimeException('Каталог этого SHA уже существует, но не распознан как безопасный релиз.');
    }
    $stage = $base . '/.incoming-' . $sha . '-' . bin2hex(random_bytes(6));
    if (!mkdir($stage, 0700)) {
        throw new RuntimeException('Не удалось создать временный каталог релиза.');
    }
    $archivePath = tempnam($privateDirectory, '.surkove-zip-');
    if ($archivePath === false) {
        delete_tree($stage);
        throw new RuntimeException('Не удалось создать временный файл ZIP в private.');
    }
    try {
        download_zip($config, $sha, $archivePath);
        $stats = extract_zip_safely($archivePath, $stage);
        $metadata = ['commit' => $sha, 'installed_at' => gmdate('c'), 'files' => $stats['files'], 'bytes' => $stats['bytes']];
        if (file_put_contents($stage . '/.surkove-release.json', json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать метаданные релиза.');
        }
        @chmod($stage . '/.surkove-release.json', 0444);
        set_tree_readonly($stage);
        if (!rename($stage, $final)) {
            throw new RuntimeException('Не удалось атомарно сохранить готовый релиз.');
        }
    } catch (Throwable $error) {
        delete_tree($stage);
        throw $error;
    } finally {
        @unlink($archivePath);
    }
}

function operation_lock(string $privateDirectory)
{
    $path = $privateDirectory . '/.deploy-operation.lock';
    $handle = @fopen($path, 'c');
    if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new RuntimeException('Другая операция уже выполняется. Повторите позже.');
    }
    @chmod($path, 0600);
    return $handle;
}

function save_operation_result(string $privateDirectory, string $action, bool $success, string $sha, string $message): void
{
    private_json_write($privateDirectory . '/deploy-state.json', [
        'action' => $action,
        'success' => $success,
        'sha' => $sha,
        'message' => $message,
        'at' => gmdate('c'),
    ]);
}

function install_latest(array $config, string $webRoot, string $privateDirectory): string
{
    if (!$config['apache_rewrite_confirmed']) {
        throw new RuntimeException('Сначала подтвердите у Majordomo поддержку Apache mod_rewrite и разрешение RewriteRule в .htaccess.');
    }
    $sha = latest_commit_sha($config);
    if (release_marker($webRoot . '/releases/' . $sha, $sha) === null) {
        extract_release($config, $webRoot, $privateDirectory, $sha);
    }
    activate_release($webRoot, $sha);
    cleanup_releases($webRoot, $sha);
    return $sha;
}

function perform_rollback(array $config, string $webRoot, string $sha): string
{
    if (!$config['apache_rewrite_confirmed']) {
        throw new RuntimeException('Перед переключением подтвердите у Majordomo поддержку Apache mod_rewrite.');
    }
    if (!preg_match('/\A[a-f0-9]{40}\z/', $sha)) {
        throw new RuntimeException('Некорректный SHA выбранного релиза.');
    }
    activate_release($webRoot, $sha);
    cleanup_releases($webRoot, $sha);
    return $sha;
}

if (!is_dir($privateDirectory) || is_link($privateDirectory) || !is_file($configPath) || is_link($configPath)) {
    show_setup_error('Загрузите deploy-config.php в соседний с www каталог private. Конфигурация не должна находиться внутри публичной папки.');
}
$realPrivate = realpath($privateDirectory);
$realWebRoot = realpath($webRoot);
if ($realPrivate === false || $realWebRoot === false || str_starts_with($realPrivate . DIRECTORY_SEPARATOR, $realWebRoot . DIRECTORY_SEPARATOR)) {
    show_setup_error('Каталог private должен находиться вне публичного www.');
}
$config = require $configPath;
if (!is_array($config)) {
    show_setup_error('Файл private/deploy-config.php должен возвращать PHP-массив.');
}
try {
    validate_config($config);
} catch (Throwable $error) {
    show_setup_error($error->getMessage());
}

if (isset($_SESSION['authenticated'], $_SESSION['last_activity'])
    && $_SESSION['authenticated'] === true
    && is_int($_SESSION['last_activity'])
    && $_SESSION['last_activity'] < time() - 1800) {
    $_SESSION = [];
    session_regenerate_id(true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $submittedCsrf = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
    if (!hash_equals(csrf_token(), $submittedCsrf)) {
        $_SESSION['flash'] = ['error', 'Сессия формы устарела. Обновите страницу и повторите действие.'];
        redirect_panel();
    }
    if ($action === 'login') {
        try {
            $ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
            $pepper = hash('sha256', $config['panel_password_hash']);
            $attempts = login_attempt_count($privateDirectory, $ip, $pepper);
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            if ($attempts >= 5) {
                $_SESSION['flash'] = ['error', 'Слишком много попыток. Попробуйте позже.'];
            } elseif (password_verify($password, $config['panel_password_hash'])) {
                login_attempt_count($privateDirectory, $ip, $pepper, false, true);
                session_regenerate_id(true);
                $_SESSION['authenticated'] = true;
                $_SESSION['last_activity'] = time();
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                $_SESSION['flash'] = ['success', 'Вход выполнен.'];
            } else {
                login_attempt_count($privateDirectory, $ip, $pepper, true);
                $_SESSION['flash'] = ['error', 'Неверный пароль или временно ограничен вход.'];
            }
        } catch (Throwable $error) {
            $_SESSION['flash'] = ['error', $error->getMessage()];
        }
        redirect_panel();
    }
    if (($_SESSION['authenticated'] ?? false) !== true) {
        $_SESSION['flash'] = ['error', 'Сначала войдите в панель.'];
        redirect_panel();
    }
    $_SESSION['last_activity'] = time();
    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['flash'] = ['success', 'Вы вышли из панели.'];
        redirect_panel();
    }
    if ($action === 'install' || $action === 'rollback') {
        $lock = null;
        $sha = '';
        try {
            $lock = operation_lock($privateDirectory);
            if ($action === 'install') {
                $sha = install_latest($config, $webRoot, $privateDirectory);
                $message = 'Релиз установлен и активирован.';
            } else {
                $requestedSha = is_string($_POST['sha'] ?? null) ? strtolower($_POST['sha']) : '';
                $sha = perform_rollback($config, $webRoot, $requestedSha);
                $message = 'Выбранная сохранённая версия активирована.';
            }
            try {
                save_operation_result($privateDirectory, $action, true, $sha, $message);
            } catch (Throwable) {
            }
            $_SESSION['flash'] = ['success', $message . ' SHA: ' . $sha];
        } catch (Throwable $error) {
            $message = $error->getMessage();
            try {
                save_operation_result($privateDirectory, $action, false, $sha, $message);
            } catch (Throwable) {
            }
            $_SESSION['flash'] = ['error', $message];
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        redirect_panel();
    }
    $_SESSION['flash'] = ['error', 'Неизвестная операция.'];
    redirect_panel();
}

$authenticated = ($_SESSION['authenticated'] ?? false) === true;
$flash = is_array($_SESSION['flash'] ?? null) ? $_SESSION['flash'] : null;
unset($_SESSION['flash']);
$content = '';
if ($flash !== null && isset($flash[0], $flash[1])) {
    $class = $flash[0] === 'error' ? 'notice error' : 'notice';
    $content .= '<div class="' . $class . '" role="status">' . escape_html((string) $flash[1]) . '</div>';
}

if (!$authenticated) {
    $content .= '<section class="panel"><p class="eyebrow">Surkove</p><h1>Вход в управление</h1><form method="post" action="deploy.php">'
        . csrf_input() . '<input type="hidden" name="action" value="login"><label for="password">Пароль панели</label>'
        . '<input id="password" name="password" type="password" autocomplete="current-password" required maxlength="256">'
        . '<div class="actions"><button type="submit">Войти</button></div></form></section>';
    render_page($content);
}

$_SESSION['last_activity'] = time();
$currentSha = active_sha($webRoot);
$releases = release_list($webRoot);
$state = read_private_json($privateDirectory . '/deploy-state.json');
$githubStatus = 'Не проверен';
$latestSha = '';
$zipStatus = 'Не проверен';
if (!extension_loaded('curl')) {
    $githubStatus = 'На сервере нет PHP cURL.';
} elseif ($config['github_token'] === '') {
    $githubStatus = 'Репозиторий приватный. Добавьте Fine-grained PAT с Contents: Read-only в private/deploy-config.php.';
} else {
    try {
        $latestSha = latest_commit_sha($config);
        $githubStatus = 'Соединение установлено.';
        try {
            $zipStatus = probe_zip($config, $latestSha) ? 'ZIP доступен для этого commit.' : 'ZIP недоступен.';
        } catch (Throwable $error) {
            $zipStatus = $error->getMessage();
        }
    } catch (Throwable $error) {
        $githubStatus = $error->getMessage();
    }
}
$zipExtensionStatus = class_exists('ZipArchive') ? 'ZipArchive доступен.' : 'На сервере не включён PHP ZipArchive.';
$currentText = $currentSha ?? 'Не установлен';
$latestText = $latestSha !== '' ? $latestSha : 'Не найден';
$content .= '<section class="panel"><p class="eyebrow">Состояние</p><h1>Управление релизами</h1>'
    . '<div class="row"><span>GitHub</span><span class="value">' . escape_html($githubStatus) . '</span></div>'
    . '<div class="row"><span>Последний commit ветки deploy</span><span class="value sha">' . escape_html($latestText) . '</span></div>'
    . '<div class="row"><span>ZIP-сборка</span><span class="value">' . escape_html($zipStatus) . '</span></div>'
    . '<div class="row"><span>PHP-расширение</span><span class="value">' . escape_html($zipExtensionStatus) . '</span></div>'
    . '<div class="row"><span>Apache mod_rewrite</span><span class="value">' . ($config['apache_rewrite_confirmed'] ? 'Поддержка подтверждена' : 'Требуется подтверждение у Majordomo') . '</span></div>'
    . '<div class="row"><span>Установленная версия</span><span class="value sha">' . escape_html($currentText) . '</span></div>';
$canInstall = $config['apache_rewrite_confirmed'] && $latestSha !== '' && $zipStatus === 'ZIP доступен для этого commit.' && class_exists('ZipArchive');
$content .= '<form method="post" action="deploy.php">' . csrf_input() . '<input type="hidden" name="action" value="install">'
    . '<div class="actions"><button type="submit"' . ($canInstall ? '' : ' disabled') . '>Установить / обновить</button></div></form></section>';

$content .= '<section class="panel"><h2>История версий</h2>';
if ($releases === []) {
    $content .= '<p class="muted">Сохранённых релизов пока нет.</p>';
} else {
    foreach ($releases as $release) {
        $installed = escape_html($release['installed_at']);
        $sha = escape_html($release['sha']);
        $active = $release['sha'] === $currentSha ? ' · активна' : '';
        $content .= '<div class="release"><span class="sha">' . $sha . '</span>' . escape_html($active) . '<div class="muted">' . $installed . '</div></div>';
    }
    $rollbackChoices = array_values(array_filter($releases, static fn(array $release): bool => $release['sha'] !== $currentSha));
    if ($rollbackChoices !== [] && $config['apache_rewrite_confirmed']) {
        $content .= '<form method="post" action="deploy.php">' . csrf_input() . '<input type="hidden" name="action" value="rollback">'
            . '<label for="release-sha">Откатить на сохранённую версию</label><select id="release-sha" name="sha" required>';
        foreach ($rollbackChoices as $release) {
            $content .= '<option value="' . escape_html($release['sha']) . '">' . escape_html($release['sha'] . ' · ' . $release['installed_at']) . '</option>';
        }
        $content .= '</select><div class="actions"><button class="secondary" type="submit">Откатить</button></div></form>';
    }
}
$content .= '</section>';

$content .= '<section class="panel"><h2>Результат последней операции</h2>';
if (isset($state['at'], $state['message'])) {
    $content .= '<div class="row"><span>Время</span><span class="value">' . escape_html((string) $state['at']) . '</span></div>'
        . '<div class="row"><span>Результат</span><span class="value">' . escape_html((string) $state['message']) . '</span></div>';
    if (isset($state['sha']) && preg_match('/\A[a-f0-9]{40}\z/i', (string) $state['sha'])) {
        $content .= '<div class="row"><span>Commit</span><span class="value sha">' . escape_html((string) $state['sha']) . '</span></div>';
    }
} else {
    $content .= '<p class="muted">Операций ещё не было.</p>';
}
$content .= '</section><form method="post" action="deploy.php">' . csrf_input() . '<input type="hidden" name="action" value="logout">'
    . '<div class="actions"><button class="secondary" type="submit">Выйти</button></div></form>'
    . '<p class="footer">Панель управляет только статическими релизами. PHP, SQL и системные команды через интерфейс не выполняются.</p>';
render_page($content);