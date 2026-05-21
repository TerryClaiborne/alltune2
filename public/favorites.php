<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/AppSession.php';
\App\Support\AppSession::start();

require_once dirname(__DIR__) . '/app/Support/Config.php';
require_once dirname(__DIR__) . '/app/Support/AppAuth.php';
require_once dirname(__DIR__) . '/app/Support/AppCsrf.php';

use App\Support\AppAuth;
use App\Support\Config;

$config = new Config(dirname(__DIR__) . '/config.ini');
$auth = new AppAuth($config);
$authEnabled = $auth->isEnabled();
$authLoggedIn = $auth->isLoggedIn();
$authHttpsWarning = $authEnabled && !\App\Support\AppSession::isHttps();
$authCanWrite = !$authEnabled || $authLoggedIn;
$myNode = trim((string) $config->get('MYNODE', ''));
$nodeStatsUrl = $myNode !== ''
    ? 'https://stats.allstarlink.org/stats/' . rawurlencode($myNode)
    : 'https://stats.allstarlink.org/';

$dvswitchCockpitDir = dirname(__DIR__, 2) . '/dvswitch_cockpit';
$dvswitchHref = is_dir($dvswitchCockpitDir)
    ? '/dvswitch_cockpit/'
    : '/dvswitch/';

$dvSwitchNode = trim((string) $config->get('DVSWITCH_NODE', ''));
$hasRealMyNode = !is_placeholder_config_value($myNode);
$hasRealDvSwitchNode = !is_placeholder_config_value($dvSwitchNode);
$dstarAvailable = $hasRealMyNode
    && $hasRealDvSwitchNode
    && config_flag_enabled($config, 'DSTAR_ENABLED')
    && is_file('/opt/MMDVM_Bridge/dvswitch.sh');
$p25Available = $hasRealMyNode
    && $hasRealDvSwitchNode
    && config_flag_enabled($config, 'P25_ENABLED')
    && is_file('/opt/MMDVM_Bridge/dvswitch.sh');
$nxdnAvailable = $hasRealMyNode
    && $hasRealDvSwitchNode
    && config_flag_enabled($config, 'NXDN_ENABLED')
    && is_file('/opt/MMDVM_Bridge/dvswitch.sh');

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function is_placeholder_config_value(mixed $value): bool
{
    $normalized = strtoupper(trim((string) $value));

    if ($normalized === '') {
        return true;
    }

    return in_array($normalized, [
        'CHANGE_ME',
        'YOUR NODE',
        'YOUR DVSWITCH NODE',
        'YOUR_REAL_PASSWORD',
        'YOUR_REAL_KEY',
        'YOUR PASSWORD',
        'YOUR KEY',
    ], true);
}

function config_flag_enabled(Config $config, string $key): bool
{
    $value = strtolower(trim((string) $config->get($key, '0')));
    return in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true);
}

$appName = 'AllTune2';
$dataDir = dirname(__DIR__) . '/data';
$favoritesPath = $dataDir . '/favorites.txt';

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0775, true);
}

if (!is_file($favoritesPath)) {
    @file_put_contents($favoritesPath, '');
}

function normalize_mode(string $mode): string
{
    $value = strtoupper(trim($mode));

    if (in_array($value, ['ALLSTAR', 'ALLSTAR LINK', 'ALLSTARLINK'], true)) {
        return 'ASL';
    }

    if (in_array($value, ['ECHO', 'ECHO LINK', 'ECHOLINK', 'EL', 'E/L'], true)) {
        return 'ECHO';
    }

    if (in_array($value, ['D-STAR', 'D STAR', 'DSTAR'], true)) {
        return 'DSTAR';
    }

    if (in_array($value, ['P-25', 'P 25', 'P25'], true)) {
        return 'P25';
    }

    if (in_array($value, ['N-XDN', 'N XDN', 'NXDN'], true)) {
        return 'NXDN';
    }

    return match ($value) {
        'BM', 'TGIF', 'ASL', 'ECHO', 'YSF', 'DSTAR', 'P25', 'NXDN' => $value,
        default => 'BM',
    };
}

function mode_display_label(string $mode): string
{
    $normalized = normalize_mode($mode);

    return match ($normalized) {
        'ASL' => 'ASL',
        'ECHO' => 'E/L',
        'DSTAR' => 'D-Star',
        'P25' => 'P25',
        'NXDN' => 'NXDN',
        default => $normalized,
    };
}


function compare_favorites_by_target(array $left, array $right): int
{
    $leftTarget = trim((string) ($left['target'] ?? ''));
    $rightTarget = trim((string) ($right['target'] ?? ''));

    $leftIsNumeric = ctype_digit($leftTarget);
    $rightIsNumeric = ctype_digit($rightTarget);

    if ($leftIsNumeric && $rightIsNumeric) {
        $numberCompare = (int) $leftTarget <=> (int) $rightTarget;

        if ($numberCompare !== 0) {
            return $numberCompare;
        }
    } elseif ($leftIsNumeric !== $rightIsNumeric) {
        return $leftIsNumeric ? -1 : 1;
    } else {
        $naturalCompare = strnatcasecmp($leftTarget, $rightTarget);

        if ($naturalCompare !== 0) {
            return $naturalCompare;
        }
    }

    $modeCompare = strnatcasecmp(
        mode_display_label((string) ($left['mode'] ?? 'BM')),
        mode_display_label((string) ($right['mode'] ?? 'BM'))
    );

    if ($modeCompare !== 0) {
        return $modeCompare;
    }

    $nameCompare = strnatcasecmp(
        (string) ($left['name'] ?? ''),
        (string) ($right['name'] ?? '')
    );

    if ($nameCompare !== 0) {
        return $nameCompare;
    }

    return strnatcasecmp(
        (string) ($left['description'] ?? ''),
        (string) ($right['description'] ?? '')
    );
}

function sort_favorites_by_target(array &$favorites): void
{
    usort($favorites, 'compare_favorites_by_target');
}

function normalize_favorites_sort_key(string $key): string
{
    $key = strtolower(trim($key));

    return in_array($key, ['target', 'mode', 'name', 'description'], true) ? $key : 'target';
}

function normalize_favorites_sort_direction(string $direction): string
{
    return strtolower(trim($direction)) === 'desc' ? 'desc' : 'asc';
}

function favorite_sort_value(array $favorite, string $key): string
{
    if ($key === 'mode') {
        return mode_display_label((string) ($favorite['mode'] ?? ''));
    }

    if ($key === 'description') {
        return (string) ($favorite['description'] ?? '');
    }

    if ($key === 'name') {
        return (string) ($favorite['name'] ?? '');
    }

    return (string) ($favorite['target'] ?? '');
}

function compare_favorites_by_display_sort(array $left, array $right, string $key, string $direction): int
{
    if ($key === 'target') {
        $result = compare_favorites_by_target($left, $right);
    } else {
        $result = strnatcasecmp(
            favorite_sort_value($left, $key),
            favorite_sort_value($right, $key)
        );

        if ($result === 0) {
            $result = compare_favorites_by_target($left, $right);
        }
    }

    return $direction === 'desc' ? -$result : $result;
}

function sort_favorites_for_display(array &$favorites, string $key, string $direction): void
{
    usort(
        $favorites,
        static fn(array $left, array $right): int => compare_favorites_by_display_sort($left, $right, $key, $direction)
    );
}

function favorites_sort_url(string $key, string $currentKey, string $currentDirection): string
{
    $nextDirection = ($key === $currentKey && $currentDirection === 'asc') ? 'desc' : 'asc';

    return '/alltune2/public/favorites.php?sort=' . rawurlencode($key) . '&dir=' . rawurlencode($nextDirection);
}

function favorites_sort_indicator(string $key, string $currentKey, string $currentDirection): string
{
    if ($key !== $currentKey) {
        return '△';
    }

    return $currentDirection === 'asc' ? '▲' : '▼';
}

function load_favorites(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if (!is_array($lines)) {
        return [];
    }

    $favorites = [];

    foreach ($lines as $index => $line) {
        $parts = explode('|', $line);

        $target = trim((string) ($parts[0] ?? ''));
        $name = trim((string) ($parts[1] ?? ''));
        $description = trim((string) ($parts[2] ?? ''));
        $mode = normalize_mode((string) ($parts[3] ?? 'BM'));

        if ($target === '') {
            continue;
        }

        $favorites[] = [
            'id' => (string) $index,
            'target' => $target,
            'name' => $name,
            'description' => $description,
            'mode' => $mode,
        ];
    }

    return array_values($favorites);
}

function save_favorites(string $path, array $favorites): bool
{
    $lines = [];

    foreach ($favorites as $favorite) {
        $target = trim((string) ($favorite['target'] ?? ''));
        $name = trim((string) ($favorite['name'] ?? ''));
        $description = trim((string) ($favorite['description'] ?? ''));
        $mode = normalize_mode((string) ($favorite['mode'] ?? 'BM'));

        if ($target === '') {
            continue;
        }

        $lines[] = implode('|', [$target, $name, $description, $mode]);
    }

    $content = $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL;

    return file_put_contents($path, $content, LOCK_EX) !== false;
}

$favorites = load_favorites($favoritesPath);
$favoriteSortKey = normalize_favorites_sort_key((string) ($_POST['sort'] ?? $_GET['sort'] ?? 'target'));
$favoriteSortDirection = normalize_favorites_sort_direction((string) ($_POST['dir'] ?? $_GET['dir'] ?? 'asc'));
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$authCanWrite) {
    $message = 'Login required to change favorites.';
    $messageType = 'error';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $authEnabled && !\App\Support\AppCsrf::validateRequest()) {
    $message = 'Security check failed. Refresh the page and try again.';
    $messageType = 'error';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'save') {
        $editId = trim((string) ($_POST['edit_id'] ?? ''));
        $target = trim((string) ($_POST['target'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $mode = normalize_mode((string) ($_POST['mode'] ?? 'BM'));

        if ($target === '') {
            $message = 'Target is required.';
            $messageType = 'error';
        } elseif ($mode === 'DSTAR' && !$dstarAvailable) {
            $message = 'D-Star favorites cannot be saved until D-Star is enabled in config.ini and the DVSwitch script is available.';
            $messageType = 'error';
        } elseif ($mode === 'P25' && !$p25Available) {
            $message = 'P25 favorites cannot be saved until P25 is enabled in config.ini and the DVSwitch script is available.';
            $messageType = 'error';
        } elseif ($mode === 'NXDN' && !$nxdnAvailable) {
            $message = 'NXDN favorites cannot be saved until NXDN is enabled in config.ini and the DVSwitch script is available.';
            $messageType = 'error';
        } else {
            $updated = false;

            if ($editId !== '') {
                foreach ($favorites as &$favorite) {
                    if ((string) $favorite['id'] === $editId) {
                        $favorite['target'] = $target;
                        $favorite['name'] = $name;
                        $favorite['description'] = $description;
                        $favorite['mode'] = $mode;
                        $updated = true;
                        break;
                    }
                }
                unset($favorite);
            }

            if (!$updated) {
                $favorites[] = [
                    'id' => (string) count($favorites),
                    'target' => $target,
                    'name' => $name,
                    'description' => $description,
                    'mode' => $mode,
                ];
            }

            if (save_favorites($favoritesPath, $favorites)) {
                header('Location: /alltune2/public/favorites.php?saved=1&sort=' . rawurlencode($favoriteSortKey) . '&dir=' . rawurlencode($favoriteSortDirection));
                exit;
            }

            $message = 'Unable to save favorites.txt.';
            $messageType = 'error';
        }
    }

    if ($action === 'remove_selected') {
        $removeIds = $_POST['remove_ids'] ?? [];

        if (!is_array($removeIds) || $removeIds === []) {
            $message = 'No favorites selected.';
            $messageType = 'error';
        } else {
            $removeSet = array_map('strval', $removeIds);

            $favorites = array_values(array_filter(
                $favorites,
                static fn(array $favorite): bool => !in_array((string) $favorite['id'], $removeSet, true)
            ));

            if (save_favorites($favoritesPath, $favorites)) {
                header('Location: /alltune2/public/favorites.php?removed=1&sort=' . rawurlencode($favoriteSortKey) . '&dir=' . rawurlencode($favoriteSortDirection));
                exit;
            }

            $message = 'Unable to update favorites.txt.';
            $messageType = 'error';
        }
    }

    if ($action === 'remove_one') {
        $removeId = trim((string) ($_POST['remove_id'] ?? ''));

        if ($removeId === '') {
            $message = 'No favorite selected.';
            $messageType = 'error';
        } else {
            $favorites = array_values(array_filter(
                $favorites,
                static fn(array $favorite): bool => (string) $favorite['id'] !== $removeId
            ));

            if (save_favorites($favoritesPath, $favorites)) {
                header('Location: /alltune2/public/favorites.php?removed=1&sort=' . rawurlencode($favoriteSortKey) . '&dir=' . rawurlencode($favoriteSortDirection));
                exit;
            }

            $message = 'Unable to update favorites.txt.';
            $messageType = 'error';
        }
    }
}

$favorites = load_favorites($favoritesPath);
sort_favorites_for_display($favorites, $favoriteSortKey, $favoriteSortDirection);

if (isset($_GET['saved'])) {
    $message = 'Favorite saved.';
    $messageType = 'success';
}

if (isset($_GET['removed'])) {
    $message = 'Selected favorites removed.';
    $messageType = 'success';
}

$editFavorite = null;
$editId = trim((string) ($_GET['edit'] ?? ''));

if ($editId !== '' && !$authCanWrite) {
    $editId = '';
    if ($message === '') {
        $message = 'Login required to edit favorites.';
        $messageType = 'error';
    }
}

if ($editId !== '') {
    foreach ($favorites as $favorite) {
        if ((string) $favorite['id'] === $editId) {
            $editFavorite = $favorite;
            break;
        }
    }
}

$formTarget = $editFavorite['target'] ?? '';
$formName = $editFavorite['name'] ?? '';
$formDescription = $editFavorite['description'] ?? '';
$formMode = $editFavorite['mode'] ?? 'BM';
$formDisabled = $authCanWrite ? '' : ' disabled';
$viewOnlyNote = $authCanWrite ? '' : 'Favorites are view-only until you log in.';

$modeOptions = [
    'BM' => 'BrandMeister',
    'TGIF' => 'TGIF',
    'ASL' => 'AllStarLink',
    'ECHO' => 'EchoLink',
    'YSF' => 'YSF',
];

if ($dstarAvailable || $formMode === 'DSTAR') {
    $modeOptions['DSTAR'] = 'D-Star';
}

if ($p25Available || $formMode === 'P25') {
    $modeOptions['P25'] = 'P25';
}

if ($nxdnAvailable || $formMode === 'NXDN') {
    $modeOptions['NXDN'] = 'NXDN';
}

$navItems = [
    ['label' => 'Dashboard', 'href' => '/alltune2/public/index.php', 'active' => false],
    ['label' => 'Favorites', 'href' => '/alltune2/public/favorites.php', 'active' => true],
    ['label' => 'Node Stats', 'href' => $nodeStatsUrl, 'active' => false, 'target' => '_blank'],
    ['label' => 'DVSwitch', 'href' => $dvswitchHref, 'active' => false, 'target' => '_blank'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($appName) ?> Favorites</title>
    <link rel="stylesheet" href="/alltune2/public/assets/css/style.css">
    <style>
        .favorites-page-stack {
            display: grid;
            gap: 14px;
        }

        .favorites-form-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr) minmax(0, 1.25fr) 168px 168px;
            gap: 10px;
            align-items: stretch;
        }

        .message-banner {
            padding: 10px 12px;
            border-radius: 12px;
            font-size: 0.86rem;
            font-weight: 600;
        }

        .message-banner.success {
            background: rgba(28, 255, 83, 0.08);
            border: 1px solid rgba(28, 255, 83, 0.25);
            color: var(--green);
        }

        .message-banner.error {
            background: rgba(141, 18, 11, 0.15);
            border: 1px solid rgba(198, 40, 30, 0.4);
            color: #ffd0d0;
        }

        .favorites-manage-table-wrap {
            overflow-x: auto;
            overflow-y: auto;
            max-height: 430px;
            border-radius: 12px;
            border: 1px solid #2b1d38;
            background: rgba(15, 10, 20, 0.55);
        }

        .favorites-manage-table {
            width: 100%;
            min-width: 1080px;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .favorites-manage-table th,
        .favorites-manage-table td {
            padding: 7px 10px;
            border-bottom: 1px solid #2b1d38;
            text-align: left;
            vertical-align: middle;
        }

        .favorites-manage-table th {
            position: sticky;
            top: 0;
            z-index: 3;
            color: var(--pink);
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            background: linear-gradient(180deg, #56306d 0%, #45255a 100%);
            box-shadow: inset 0 -1px 0 #6d4392;
        }

        .favorites-manage-table td {
            color: var(--text-main);
            font-size: 0.82rem;
            line-height: 1.14;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .favorites-manage-table th:nth-child(1),
        .favorites-manage-table td:nth-child(1) {
            width: 6%;
            text-align: center;
        }

        .favorites-manage-table th:nth-child(2),
        .favorites-manage-table td:nth-child(2) {
            width: 24%;
        }

        .favorites-manage-table th:nth-child(3),
        .favorites-manage-table td:nth-child(3) {
            width: 20%;
        }

        .favorites-manage-table th:nth-child(4),
        .favorites-manage-table td:nth-child(4) {
            width: 30%;
        }

        .favorites-manage-table th:nth-child(5),
        .favorites-manage-table td:nth-child(5) {
            width: 10%;
            text-align: center;
        }

        .favorites-manage-table th:nth-child(6),
        .favorites-manage-table td:nth-child(6) {
            width: 10%;
            text-align: center;
        }

        .favorites-manage-table tbody tr:nth-child(odd) {
            background: rgba(255, 255, 255, 0.02);
        }

        .favorites-manage-table tbody tr:nth-child(even) {
            background: rgba(138, 13, 140, 0.08);
        }

        .favorites-manage-table tbody tr:hover {
            background: rgba(216, 108, 242, 0.14);
        }

        .edit-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 62px;
            height: 24px;
            padding: 0 8px;
            background: #224d83;
            color: #d9edff;
            border: 1px solid var(--blue);
            border-radius: 8px;
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .toolbar-row {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 8px;
        }

        .toolbar-row .btn {
            height: 34px;
            font-size: 0.76rem;
            padding: 0 12px;
        }

        .favorites-page-stack .card-body {
            padding: 10px 12px;
        }

        /* favorites-mode-dropdown-polish */
        .favorites-form-grid select.control {
            appearance: auto;
            -webkit-appearance: menulist;
            -moz-appearance: menulist;
            cursor: pointer;
            padding-right: 34px;
            color: #f3dcff;
            border-color: rgba(88, 166, 255, 0.62);
            background-color: rgba(10, 8, 16, 0.92);
        }

        .favorites-form-grid select.control:hover,
        .favorites-form-grid select.control:focus {
            border-color: rgba(88, 166, 255, 0.95);
            box-shadow: 0 0 0 2px rgba(88, 166, 255, 0.16);
        }

        .favorites-form-grid select.control option {
            background: #090712;
            color: #f3dcff;
        }

        /* favorites-manage-color-polish */
        .favorites-page-stack .card-header {
            border-bottom-color: rgba(216, 108, 242, 0.28);
        }

        .favorites-page-stack .meta-line {
            color: #f3dcff;
        }

        .favorites-form-grid .control {
            border-color: rgba(216, 108, 242, 0.28);
            background: rgba(15, 10, 20, 0.72);
        }

        .favorites-form-grid .control:focus {
            border-color: rgba(255, 125, 242, 0.76);
            box-shadow: 0 0 0 2px rgba(255, 125, 242, 0.14);
        }

        .favorites-page-stack .btn-primary {
            border-color: rgba(46, 204, 113, 0.72);
            background: linear-gradient(180deg, rgba(46, 204, 113, 0.28), rgba(22, 122, 63, 0.28));
            color: #b9ffd0;
        }

        .favorites-page-stack .btn-primary:hover {
            border-color: rgba(46, 204, 113, 0.95);
            background: rgba(46, 204, 113, 0.22);
        }

        .favorites-page-stack .btn-danger {
            border-color: rgba(255, 82, 120, 0.82);
            background: rgba(255, 45, 95, 0.24);
            color: #ffc5d3;
        }

        .favorites-page-stack .btn-danger:hover {
            background: rgba(255, 45, 95, 0.34);
            border-color: rgba(255, 95, 145, 0.96);
        }

        .favorites-note {
            margin-top: 8px;
            padding: 8px 10px;
            border: 1px solid rgba(255, 193, 7, 0.36);
            border-radius: 10px;
            background: rgba(255, 193, 7, 0.08);
            color: #ffd37a;
            font-size: 0.78rem;
            line-height: 1.25;
        }

        .favorites-manage-table th:nth-child(1) {
            color: #ff7df2;
        }

        .favorites-manage-table th:nth-child(2) {
            color: #9ef59e;
        }

        .favorites-manage-table th:nth-child(3) {
            color: #8ff3f7;
        }

        .favorites-manage-table th:nth-child(4) {
            color: #ffd37a;
        }

        .favorites-manage-table th:nth-child(5) {
            color: #d7b7ff;
        }

        .favorites-manage-table th:nth-child(6) {
            color: #a9c8ff;
        }

        .favorites-manage-table .favorite-target {
            color: #9ef59e;
            font-weight: 850;
        }

        .favorites-manage-table .favorite-name {
            color: #8ff3f7;
            font-weight: 750;
        }

        .favorites-manage-table .favorite-description {
            color: #ffd37a;
        }

        .favorites-manage-table .favorite-mode-cell {
            text-align: center;
        }

        .favorites-manage-table .favorite-mode-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 78px;
            padding: 4px 9px;
            border-radius: 999px;
            border: 1px solid rgba(216, 108, 242, 0.38);
            background: rgba(138, 13, 140, 0.22);
            color: #ffffff;
            font-size: 0.68rem;
            font-weight: 850;
            line-height: 1;
            text-transform: uppercase;
            white-space: nowrap;
            box-sizing: border-box;
        }

        .favorites-manage-table tbody tr[data-mode="BM"] .favorite-mode-badge {
            background: rgba(38, 220, 90, 0.34);
            border-color: rgba(38, 220, 90, 0.90);
            color: #b8ffca;
        }

        .favorites-manage-table tbody tr[data-mode="TGIF"] .favorite-mode-badge {
            background: rgba(255, 190, 0, 0.36);
            border-color: rgba(255, 190, 0, 0.92);
            color: #ffe58f;
        }

        .favorites-manage-table tbody tr[data-mode="YSF"] .favorite-mode-badge {
            background: rgba(0, 220, 255, 0.32);
            border-color: rgba(0, 220, 255, 0.92);
            color: #b8f8ff;
        }

        .favorites-manage-table tbody tr[data-mode="DSTAR"] .favorite-mode-badge {
            background: rgba(40, 95, 255, 0.38);
            border-color: rgba(95, 145, 255, 0.95);
            color: #d4e2ff;
        }

        .favorites-manage-table tbody tr[data-mode="P25"] .favorite-mode-badge {
            background: rgba(255, 105, 35, 0.38);
            border-color: rgba(255, 130, 70, 0.95);
            color: #ffd0b5;
        }

        .favorites-manage-table tbody tr[data-mode="NXDN"] .favorite-mode-badge {
            background: rgba(178, 72, 255, 0.40);
            border-color: rgba(205, 128, 255, 0.95);
            color: #edd2ff;
        }

        .favorites-manage-table tbody tr[data-mode="ASL"] .favorite-mode-badge {
            background: rgba(255, 45, 95, 0.38);
            border-color: rgba(255, 90, 130, 0.95);
            color: #ffc5d3;
        }

        .favorites-manage-table tbody tr[data-mode="ECHO"] .favorite-mode-badge {
            background: rgba(255, 55, 180, 0.46);
            border-color: rgba(255, 95, 205, 1);
            color: #ffe1f4;
        }

        .edit-link {
            background: rgba(64, 120, 255, 0.22);
            color: #c7ddff;
            border-color: rgba(88, 166, 255, 0.82);
        }

        .edit-link:hover {
            background: rgba(64, 120, 255, 0.34);
            border-color: rgba(88, 166, 255, 1);
        }

        @media (max-width: 1080px) {
            .favorites-form-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 760px) {
            .favorites-form-grid {
                grid-template-columns: 1fr;
            }

            .favorites-manage-table-wrap {
                max-height: 360px;
            }
        }

        /* favorites-page-row-action-cleanup */
        .favorites-manage-table {
            min-width: 900px;
        }

        .favorites-manage-table-clickable th:nth-child(1),
        .favorites-manage-table-clickable td:nth-child(1) {
            width: 14%;
            text-align: left;
        }

        .favorites-manage-table-clickable th:nth-child(2),
        .favorites-manage-table-clickable td:nth-child(2) {
            width: 10%;
            text-align: center;
        }

        .favorites-manage-table-clickable th:nth-child(3),
        .favorites-manage-table-clickable td:nth-child(3) {
            width: 25%;
        }

        .favorites-manage-table-clickable th:nth-child(4),
        .favorites-manage-table-clickable td:nth-child(4) {
            width: 31%;
        }

        .favorites-manage-table-clickable th:nth-child(5),
        .favorites-manage-table-clickable td:nth-child(5) {
            width: 8%;
            text-align: center;
        }

        .favorites-manage-table-clickable th:nth-child(6),
        .favorites-manage-table-clickable td:nth-child(6) {
            width: 12%;
            text-align: right;
        }

        .favorites-sort-link {
            display: inline-flex;
            align-items: center;
            justify-content: flex-start;
            gap: 5px;
            color: inherit;
            text-decoration: none;
            white-space: nowrap;
        }

        .favorites-sort-link span {
            color: #ffffff;
            font-size: 0.68rem;
            opacity: 0.82;
        }

        .favorites-sort-link:hover,
        .favorites-sort-link:focus-visible {
            color: #ffffff;
            text-shadow: 0 0 8px rgba(255, 255, 255, 0.34);
        }

        .favorite-manage-row[data-edit-url] {
            cursor: pointer;
        }

        .favorite-manage-row[data-edit-url]:focus-within,
        .favorite-manage-row[data-edit-url]:hover {
            outline: 1px solid rgba(216, 108, 242, 0.25);
            outline-offset: -1px;
        }

        .favorite-edit-cell {
            text-align: center;
        }

        .favorite-remove-cell {
            text-align: right;
        }

        .favorite-remove-form {
            display: inline-flex;
            justify-content: flex-end;
            margin: 0;
        }

        .favorite-remove-button {
            min-width: 72px;
            border: 1px solid rgba(255, 90, 130, 0.72);
            border-radius: 999px;
            background: rgba(255, 45, 95, 0.18);
            color: #ffc5d3;
            font-size: 0.68rem;
            font-weight: 850;
            line-height: 1;
            padding: 5px 10px;
            cursor: pointer;
        }

        .favorite-remove-button:hover,
        .favorite-remove-button:focus-visible {
            background: rgba(255, 45, 95, 0.30);
            border-color: rgba(255, 120, 155, 0.95);
            color: #ffffff;
        }

        .favorite-remove-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

    </style>
</head>
<body>
<div class="page">
    <header class="topbar">
        <div class="branding">
            <h1 class="branding-title"><?= e($appName) ?></h1>
            <div class="branding-subtitle"></div>
        </div>

        <nav class="nav" aria-label="Primary">
            <?php foreach ($navItems as $item): ?>
                <a
                    class="nav-button<?= !empty($item['active']) ? ' active' : '' ?>"
                    href="<?= e($item['href']) ?>"
                    <?= isset($item['target']) ? ' target="' . e((string) $item['target']) . '"' : '' ?>
                >
                    <?= e($item['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="auth-status" aria-label="Authentication status">
            <?php if (!$authEnabled): ?>
                <span class="auth-pill auth-pill-normal">Normal Mode</span>
            <?php elseif ($authLoggedIn): ?>
                <span class="auth-pill auth-pill-signed-in">Signed In</span>
                <a class="auth-link" href="/alltune2/public/logout.php">Logout</a>
            <?php else: ?>
                <span class="auth-pill auth-pill-view-only">View Only</span>
                <a class="auth-link" href="/alltune2/public/login.php">Login</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($authHttpsWarning): ?>
        <div class="auth-https-warning">
            Web login is enabled, but this page is not using HTTPS. Use HTTPS or a VPN before allowing outside access.
        </div>
    <?php endif; ?>

    <main class="favorites-page-stack">
        <?php if ($message !== ''): ?>
            <div class="message-banner <?= e($messageType) ?>">
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <article class="card">
            <div class="card-header">
                <span><?= $editFavorite ? 'Edit Favorite' : 'Add Favorite' ?></span>
                <span class="meta-line">Shared favorites.txt</span>
            </div>
            <div class="card-body">
                <?php if ($viewOnlyNote !== ''): ?>
                    <div class="favorites-note"><?= e($viewOnlyNote) ?></div>
                <?php endif; ?>

                <form method="post">
                    <?= \App\Support\AppCsrf::inputHtml() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="edit_id" value="<?= e($editFavorite['id'] ?? '') ?>">
                    <input type="hidden" name="sort" value="<?= e($favoriteSortKey) ?>">
                    <input type="hidden" name="dir" value="<?= e($favoriteSortDirection) ?>">

                    <div class="favorites-form-grid">
                        <input
                            class="control"
                            type="text"
                            name="target"
                            placeholder="TG / Node / YSF / Digital target"
                            value="<?= e($formTarget) ?>"
                            required<?= $formDisabled ?>
                        >

                        <input
                            class="control"
                            type="text"
                            name="name"
                            placeholder="Station Name"
                            value="<?= e($formName) ?>"<?= $formDisabled ?>
                        >

                        <input
                            class="control"
                            type="text"
                            name="description"
                            placeholder="Description"
                            value="<?= e($formDescription) ?>"<?= $formDisabled ?>
                        >

                        <select class="control" name="mode"<?= $formDisabled ?>>
                            <?php foreach ($modeOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $formMode === $value ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="btn btn-primary"<?= $formDisabled ?>>
                            <?= $editFavorite ? 'Save Changes' : 'Save Favorite' ?>
                        </button>
                    </div>

                </form>
            </div>
        </article>

        <article class="card">
            <div class="card-header">
                <span>Saved Favorites</span>
                <span class="meta-line">One shared list</span>
            </div>
            <div class="card-body">
                <div class="favorites-manage-table-wrap">
                    <table class="favorites-manage-table favorites-manage-table-clickable">
                        <thead>
                        <tr>
                            <th>
                                <a class="favorites-sort-link" href="<?= e(favorites_sort_url('target', $favoriteSortKey, $favoriteSortDirection)) ?>">
                                    TG / Node <span><?= e(favorites_sort_indicator('target', $favoriteSortKey, $favoriteSortDirection)) ?></span>
                                </a>
                            </th>
                            <th>
                                <a class="favorites-sort-link" href="<?= e(favorites_sort_url('mode', $favoriteSortKey, $favoriteSortDirection)) ?>">
                                    Mode <span><?= e(favorites_sort_indicator('mode', $favoriteSortKey, $favoriteSortDirection)) ?></span>
                                </a>
                            </th>
                            <th>
                                <a class="favorites-sort-link" href="<?= e(favorites_sort_url('name', $favoriteSortKey, $favoriteSortDirection)) ?>">
                                    Station Name <span><?= e(favorites_sort_indicator('name', $favoriteSortKey, $favoriteSortDirection)) ?></span>
                                </a>
                            </th>
                            <th>
                                <a class="favorites-sort-link" href="<?= e(favorites_sort_url('description', $favoriteSortKey, $favoriteSortDirection)) ?>">
                                    Description <span><?= e(favorites_sort_indicator('description', $favoriteSortKey, $favoriteSortDirection)) ?></span>
                                </a>
                            </th>
                            <th>Edit</th>
                            <th>Remove</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($favorites === []): ?>
                            <tr>
                                <td colspan="6">No favorites saved yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($favorites as $favorite): ?>
                                <?php
                                    $editUrl = '/alltune2/public/favorites.php?edit=' . rawurlencode((string) $favorite['id'])
                                        . '&sort=' . rawurlencode($favoriteSortKey)
                                        . '&dir=' . rawurlencode($favoriteSortDirection);
                                ?>
                                <tr
                                    class="favorite-manage-row"
                                    data-mode="<?= e((string) $favorite['mode']) ?>"
                                    <?= $authCanWrite ? 'data-edit-url="' . e($editUrl) . '"' : '' ?>
                                >
                                    <td class="favorite-target" title="<?= e($favorite['target']) ?>"><?= e($favorite['target']) ?></td>
                                    <td class="favorite-mode-cell">
                                        <span class="favorite-mode-badge"><?= e(mode_display_label((string) $favorite['mode'])) ?></span>
                                    </td>
                                    <td class="favorite-name" title="<?= e($favorite['name']) ?>"><?= e($favorite['name']) ?></td>
                                    <td class="favorite-description" title="<?= e($favorite['description'] !== '' ? $favorite['description'] : '-') ?>"><?= e($favorite['description'] !== '' ? $favorite['description'] : '-') ?></td>
                                    <td class="favorite-edit-cell">
                                        <?php if ($authCanWrite): ?>
                                            <a class="edit-link" href="<?= e($editUrl) ?>">Edit</a>
                                        <?php else: ?>
                                            <span class="edit-link disabled">View</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="favorite-remove-cell">
                                        <form method="post" class="favorite-remove-form">
                                            <?= \App\Support\AppCsrf::inputHtml() ?>
                                            <input type="hidden" name="action" value="remove_one">
                                            <input type="hidden" name="sort" value="<?= e($favoriteSortKey) ?>">
                                            <input type="hidden" name="dir" value="<?= e($favoriteSortDirection) ?>">
                                            <input type="hidden" name="remove_id" value="<?= e($favorite['id']) ?>">
                                            <button type="submit" class="favorite-remove-button"<?= $formDisabled ?>>Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </article>
    </main>
</div>

    <script>
        document.addEventListener('click', function (event) {
            const blocked = event.target.closest('button, input, select, textarea, a, form, label');

            if (blocked) {
                return;
            }

            const row = event.target.closest('.favorite-manage-row[data-edit-url]');

            if (!row) {
                return;
            }

            const url = row.getAttribute('data-edit-url');

            if (url) {
                window.location.href = url;
            }
        });
    </script>

</body>
</html>
