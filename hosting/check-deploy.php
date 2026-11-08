<?php
declare(strict_types=1);

$configPath = dirname(__DIR__) . '/private/deploy-config.php';
$privateDirectory = dirname(__DIR__) . '/private';
$webRoot = __DIR__;
$sessionName = 'SURKOVE_CHECK';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

session_name($sessionName);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/check-deploy.php',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();
if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function page(string $body): never
{
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Проверка Surkove</title><style>'
        . 'body{margin:0;background:#f5f5f3;color:#171717;font:16px/1.5 Arial,sans-serif}'
        . 'main{width:min(100% - 28px,680px);margin:32px auto}section{background:white;border:1px solid #ddd;padding:22px;margin:14px 0}'
        . '.row{padding:10px 0;border-top:1px solid #eee;overflow-wrap:anywhere}.ok{color:#176b37}.bad{color:#a22}'
        . 'label,input,button{display:block;width:100%;font:inherit}input,button{box-sizing:border-box;padding:12px;margin-top:8px}button{background:#171717;color:white;border:0}'
        . 'small{color:#666}</style><main><h1>Проверка Surkove</h1>' . $body . '</main></html>';
    exit;
}

function result_row(string $label, bool $ok, string $detail): string
{
    return '<div class="row"><strong>' . h($label) . ':</strong> <span class="' . ($ok ? 'ok' : 'bad') . '">'
        . ($ok ? 'OK' : 'ПРОВЕРИТЬ') . '</span><br>' . h($detail) . '</div>';
}

function github_request(string $url, string $token): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: Surkove-Temporary-Diagnostics/1.0',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    return [$status, is_string($body) ? $body : '', $error];
}

if (!is_file($configPath) || is_link($configPath) || !is_dir($privateDirectory) || is_link($privateDirectory)) {
    page('<section><p class="bad">Не найден private/deploy-config.php рядом с www. Проверка остановлена.</p></section>');
}
$realPrivate = realpath($privateDirectory);
$realWebRoot = realpath($webRoot);
if ($realPrivate === false || $realWebRoot === false || str_starts_with($realPrivate . DIRECTORY_SEPARATOR, $realWebRoot . DIRECTORY_SEPARATOR)) {
    page('<section><p class="bad">Каталог private должен находиться вне публичного www. Проверка остановлена.</p></section>');
}

$config = require $configPath;
if (!is_array($config) || !isset($config['panel_password_hash']) || !is_string($config['panel_password_hash'])
    || password_get_info($config['panel_password_hash'])['algo'] === null) {
    page('<section><p class="bad">В конфиге отсутствует корректный password_hash. Исправьте private/deploy-config.php.</p></section>');
}

if (($_SESSION['authorized'] ?? false) !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            page('<section><p class="bad">Форма устарела. Обновите страницу.</p></section>');
        }
        $_SESSION['attempts'] = (int) ($_SESSION['attempts'] ?? 0) + 1;
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if ($_SESSION['attempts'] <= 5 && password_verify($password, $config['panel_password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['authorized'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            header('Location: check-deploy.php', true, 303);
            exit;
        }
        $_SESSION['error'] = $_SESSION['attempts'] > 5 ? 'Слишком много попыток. Закройте браузер и повторите позже.' : 'Пароль не подошёл.';
        header('Location: check-deploy.php', true, 303);
        exit;
    }
    $error = isset($_SESSION['error']) ? '<p class="bad">' . h((string) $_SESSION['error']) . '</p>' : '';
    page('<section>' . $error . '<p>Введите пароль панели. Проверка не устанавливает и не переключает релизы.</p>'
        . '<form method="post"><input type="hidden" name="csrf" value="' . h((string) $_SESSION['csrf']) . '"><label for="password">Пароль панели</label><input id="password" name="password" type="password" autocomplete="current-password" required>'
        . '<button>Проверить</button></form></section>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
    page('<section><p class="bad">Форма устарела. Обновите страницу.</p></section>');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    $_SESSION = [];
    session_regenerate_id(true);
    header('Location: check-deploy.php', true, 303);
    exit;
}

$checks = [];
$checks[] = result_row('PHP', PHP_VERSION_ID >= 80300, PHP_VERSION);
$checks[] = result_row('cURL', extension_loaded('curl'), extension_loaded('curl') ? 'Расширение включено.' : 'Нужно включить PHP cURL у хостинга.');
$checks[] = result_row('ZipArchive', class_exists('ZipArchive'), class_exists('ZipArchive') ? 'Расширение включено.' : 'Нужно включить PHP ZipArchive у хостинга.');
$checks[] = result_row('Хеш пароля', true, 'Хеш распознан; пароль был проверен при входе.');
$checks[] = result_row('PAT для приватного GitHub', isset($config['github_token']) && is_string($config['github_token']) && $config['github_token'] !== '', 'Значение токена не отображается.');
$temporaryBase = sys_get_temp_dir();
$writeProbe = is_dir($temporaryBase) && !is_link($temporaryBase)
    ? @tempnam($temporaryBase, '.surkove-check-')
    : false;
$temporaryWritable = is_string($writeProbe) && $writeProbe !== '';
if ($temporaryWritable) {
    @unlink($writeProbe);
}
$checks[] = result_row('Временная папка PHP', $temporaryWritable, $temporaryWritable ? 'Пробный файл создан и удалён.' : 'PHP не может создавать служебные файлы во временной папке хостинга.');
$checks[] = result_row('mod_rewrite подтверждён', ($config['apache_rewrite_confirmed'] ?? false) === true, ($config['apache_rewrite_confirmed'] ?? false) === true ? 'В конфиге включено подтверждение.' : 'Подтвердите поддержку у Majordomo и выставьте флаг в конфиге.');

$latestSha = '';
if (extension_loaded('curl') && isset($config['github_owner'], $config['github_repository'], $config['deploy_branch'], $config['github_token'])
    && is_string($config['github_token']) && $config['github_token'] !== '') {
    $url = 'https://api.github.com/repos/' . rawurlencode((string) $config['github_owner']) . '/'
        . rawurlencode((string) $config['github_repository']) . '/commits/' . rawurlencode((string) $config['deploy_branch']);
    [$status, $body, $error] = github_request($url, $config['github_token']);
    $data = json_decode($body, true);
    if ($status === 200 && is_array($data) && isset($data['sha']) && is_string($data['sha'])
        && preg_match('/\A[a-f0-9]{40}\z/i', $data['sha'])) {
        $latestSha = strtolower($data['sha']);
        $checks[] = result_row('GitHub API / deploy', true, 'Commit ' . $latestSha);
        $zipUrl = 'https://api.github.com/repos/' . rawurlencode((string) $config['github_owner']) . '/'
            . rawurlencode((string) $config['github_repository']) . '/zipball/' . $latestSha;
        [$zipStatus, , $zipError] = github_request($zipUrl, $config['github_token']);
        $checks[] = result_row('ZIP commit', in_array($zipStatus, [200, 302], true),
            'HTTP ' . $zipStatus . ($zipError !== '' ? '; ' . $zipError : ''));
    } else {
        $checks[] = result_row('GitHub API / deploy', false, 'HTTP ' . $status . ($error !== '' ? '; ' . $error : ''));
        $checks[] = result_row('ZIP commit', false, 'Сначала исправьте доступ к GitHub API.');
    }
} else {
    $checks[] = result_row('GitHub API / deploy', false, 'Нужны включённый cURL и PAT с Contents: Read-only.');
    $checks[] = result_row('ZIP commit', false, 'Проверка пропущена.');
}

$htaccess = @file_get_contents($webRoot . '/.htaccess');
$checks[] = result_row('.htaccess на месте', is_string($htaccess) && str_contains($htaccess, 'RewriteEngine'),
    is_string($htaccess) && str_contains($htaccess, 'RewriteEngine') ? 'Файл содержит правила Rewrite.' : 'Не найдено ожидаемое правило Rewrite.');
$releaseRoot = $webRoot . '/releases';
$releaseCount = 0;
if (is_dir($releaseRoot)) {
    foreach (new DirectoryIterator($releaseRoot) as $entry) {
        if (!$entry->isDot() && $entry->isDir() && preg_match('/\A[a-f0-9]{40}\z/i', $entry->getFilename())) {
            $releaseCount++;
        }
    }
}
$active = '';
if (is_string($htaccess) && preg_match('/^# SURKOVE_ACTIVE_RELEASE: ([a-f0-9]{40})$/m', $htaccess, $activeMatch)) {
    $active = strtolower($activeMatch[1]);
}
$activeDirectory = $active !== '' ? $releaseRoot . '/' . $active : '';
$activeIndex = $activeDirectory !== '' && !is_link($activeDirectory) && is_file($activeDirectory . '/index.html');
$checks[] = result_row('Активный релиз', $activeIndex, $releaseCount . ' сохранено; активная версия: ' . ($active !== '' ? $active : 'нет')
    . ($activeIndex ? '; index.html найден.' : '; сначала установите релиз через панель.'));
$assetsOk = false;
if ($activeIndex) {
    $html = @file_get_contents($activeDirectory . '/index.html');
    $assetPaths = [];
    if (is_string($html) && preg_match_all('/(?:src|href)=["\']\/?(assets\/[^"\']+)["\']/i', $html, $matches)) {
        $assetPaths = $matches[1];
    }
    $assetsOk = $assetPaths !== [];
    foreach ($assetPaths as $assetPath) {
        $assetPath = rawurldecode($assetPath);
        if (str_contains($assetPath, '..') || !is_file($activeDirectory . '/' . $assetPath)) {
            $assetsOk = false;
            break;
        }
    }
}
$checks[] = result_row('Файлы сборки', $assetsOk, $assetsOk ? 'index.html и все указанные assets найдены.' : 'Проверка возможна после установки; либо отсутствует CSS/JS из сборки.');

$rewriteWorks = false;
$rewriteDetail = 'Проверка возможна после установки активного релиза.';
$requestHost = strtolower((string) parse_url('https://' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
if ($activeIndex && in_array($requestHost, ['surkove.ru', 'www.surkove.ru'], true) && extension_loaded('curl')) {
    $probeName = '.surkove-check-' . bin2hex(random_bytes(12)) . '.txt';
    $probeValue = bin2hex(random_bytes(24));
    $probePath = $activeDirectory . '/' . $probeName;
    if (@file_put_contents($probePath, $probeValue, LOCK_EX) === strlen($probeValue)) {
        $probeCurl = curl_init('https://' . $requestHost . '/' . $probeName);
        curl_setopt_array($probeCurl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Surkove-Temporary-Diagnostics/1.0',
        ]);
        $probeResponse = curl_exec($probeCurl);
        $probeStatus = (int) curl_getinfo($probeCurl, CURLINFO_RESPONSE_CODE);
        $probeError = curl_error($probeCurl);
        curl_close($probeCurl);
        @unlink($probePath);
        $rewriteWorks = $probeStatus === 200 && is_string($probeResponse) && hash_equals($probeValue, $probeResponse);
        $rewriteDetail = $rewriteWorks ? 'Временный файл через HTTPS успешно отдался из активного релиза.' : 'HTTP ' . $probeStatus . ($probeError !== '' ? '; ' . $probeError : '');
    } else {
        $rewriteDetail = 'PHP не может создать временный файл проверки в активном релизе.';
    }
}
$checks[] = result_row('Apache Rewrite / сайт', $rewriteWorks, $rewriteDetail);
$checks[] = result_row('Последний релиз', true, $latestSha !== '' ? $latestSha : 'см. статус GitHub API выше');

$csrf = h((string) ($_SESSION['csrf'] ?? ''));
page('<section><p>Проверка не устанавливает и не переключает релизы. Для проверки Apache временный файл создаётся в активном релизе и сразу удаляется.</p>' . implode('', $checks)
    . '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><button name="logout" value="1">Завершить проверку</button></form>'
    . '<p><strong>После проверки удалите check-deploy.php из www.</strong></p></section>');
