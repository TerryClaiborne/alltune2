<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/app/Support/Config.php';

use App\Support\Config;

$config = new Config(dirname(__DIR__) . '/config.ini');

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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

    if ($value === 'ALLSTAR') {
        return 'ASL';
    }

    return match ($value) {
        'BM', 'TGIF', 'ASL', 'YSF' => $value,
        default => 'BM',
    };
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
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                header('Location: /alltune2/public/favorites.php?saved=1');
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
                header('Location: /alltune2/public/favorites.php?removed=1');
                exit;
            }

            $message = 'Unable to update favorites.txt.';
            $messageType = 'error';
        }
    }
}

$favorites = load_favorites($favoritesPath);

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

$navItems = [
    ['label' => 'Dashboard', 'href' => '/alltune2/public/index.php', 'active' => false],
    ['label' => 'Favorites', 'href' => '/alltune2/public/favorites.php', 'active' => true],
    ['label' => 'Allscan', 'href' => '/allscan/', 'active' => false, 'target' => '_blank'],
    ['label' => 'DVSwitch', 'href' => '/dvswitch/', 'active' => false, 'target' => '_blank'],
];

$modeOptions = [
    'BM' => 'BrandMeister',
    'TGIF' => 'TGIF',
    'ASL' => 'AllStar',
    'YSF' => 'YSF',
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
            color: var(--pink);
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
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
    </header>

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
                <form method="post">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="edit_id" value="<?= e($editFavorite['id'] ?? '') ?>">

                    <div class="favorites-form-grid">
                        <input
                            class="control"
                            type="text"
                            name="target"
                            placeholder="TG / Node / YSF # or host"
                            value="<?= e($formTarget) ?>"
                            required
                        >

                        <input
                            class="control"
                            type="text"
                            name="name"
                            placeholder="Station Name"
                            value="<?= e($formName) ?>"
                        >

                        <input
                            class="control"
                            type="text"
                            name="description"
                            placeholder="Description"
                            value="<?= e($formDescription) ?>"
                        >

                        <select class="control" name="mode">
                            <?php foreach ($modeOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $formMode === $value ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="btn btn-primary">
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
                <form method="post">
                    <input type="hidden" name="action" value="remove_selected">

                    <div class="toolbar-row">
                        <button type="submit" class="btn btn-danger">Remove Selected</button>
                    </div>

                    <div class="favorites-manage-table-wrap">
                        <table class="favorites-manage-table">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>TG / Node / YSF</th>
                                <th>Station Name</th>
                                <th>Description</th>
                                <th>Mode</th>
                                <th>Edit</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($favorites === []): ?>
                                <tr>
                                    <td colspan="6">No favorites saved yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($favorites as $favorite): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="remove_ids[]" value="<?= e($favorite['id']) ?>">
                                        </td>
                                        <td class="favorite-target"><?= e($favorite['target']) ?></td>
                                        <td><?= e($favorite['name']) ?></td>
                                        <td><?= e($favorite['description'] !== '' ? $favorite['description'] : '-') ?></td>
                                        <td class="favorite-mode"><?= e($favorite['mode']) ?></td>
                                        <td>
                                            <a class="edit-link" href="/alltune2/public/favorites.php?edit=<?= e($favorite['id']) ?>">
                                                Edit
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </article>
    </main>
</div>
</body>
</html>