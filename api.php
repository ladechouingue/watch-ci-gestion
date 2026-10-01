<?php
declare(strict_types=1);

/* WCI-GESTION-API - Watch Côte d'Ivoire - Gestion : API (PHP 7.4+ avec SQLite). Aucune configuration nécessaire. */

const UPDATE_REPO = 'ladechouingue/watch-ci-gestion';
const UPDATE_BRANCH = 'main';

$dataDir = __DIR__ . '/data';
$upDir   = __DIR__ . '/uploads';
foreach ([$dataDir, $dataDir . '/sessions', $upDir] as $d) { if (!is_dir($d)) { @mkdir($d, 0755, true); } }
$deny = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
if (!is_file($dataDir . '/.htaccess')) { @file_put_contents($dataDir . '/.htaccess', $deny); }
if (!is_file($upDir . '/.htaccess')) {
    @file_put_contents($upDir . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phtml|php[0-9]|phar)$\">\n" . $deny . "</FilesMatch>\n");
}

ini_set('session.gc_maxlifetime', '2592000');
session_save_path($dataDir . '/sessions');
session_set_cookie_params([
    'lifetime' => 2592000, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_name('wci_gestion');
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail(string $msg, int $code = 400): void { out(['error' => $msg], $code); }

try {
    $pdo = new PDO('sqlite:' . $dataDir . '/gestion.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT)');
    foreach (['products', 'orders', 'expenses'] as $t) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS $t (id TEXT PRIMARY KEY, data TEXT NOT NULL, updated INTEGER NOT NULL)");
    }
} catch (Throwable $e) {
    fail('Base de données indisponible : ' . $e->getMessage(), 500);
}

function setting(PDO $pdo, string $k): ?string {
    $s = $pdo->prepare('SELECT v FROM settings WHERE k=?'); $s->execute([$k]);
    $v = $s->fetchColumn(); return $v === false ? null : (string)$v;
}
function setSetting(PDO $pdo, string $k, string $v): void {
    $pdo->prepare('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$k, $v]);
}
function body(): array {
    $j = json_decode((string)file_get_contents('php://input'), true);
    return is_array($j) ? $j : [];
}

function localVersion(): string { $v = trim((string)@file_get_contents(__DIR__ . '/VERSION')); return $v === '' ? '0' : $v; }
function httpGet(string $url, array $headers = [], int $timeout = 30): array {
    $headers[] = 'User-Agent: watch-ci-gestion';
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => $headers]);
        $body = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
        return [$code, $body === false ? '' : (string)$body];
    }
    $ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => $timeout, 'follow_location' => 1]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) { $code = (int)$m[1]; }
    return [$code, $body === false ? '' : (string)$body];
}
function rrmdir(string $d): void {
    if (!is_dir($d)) { return; }
    foreach (scandir($d) ?: [] as $f) { if ($f === '.' || $f === '..') { continue; } $p = $d . '/' . $f; is_dir($p) ? rrmdir($p) : @unlink($p); }
    @rmdir($d);
}

$r = $_GET['r'] ?? '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost && ($_SERVER['HTTP_X_GESTION'] ?? '') !== '1') { fail('Requête refusée.', 403); }

$hash = setting($pdo, 'password_hash');
$authed = !empty($_SESSION['ok']);

if ($r === 'status') { out(['setup' => $hash === null, 'auth' => $authed, 'version' => localVersion()]); }

if ($r === 'setup') {
    if (!$isPost || $hash !== null) { fail('Déjà configuré.', 403); }
    $p = (string)(body()['password'] ?? '');
    if (strlen($p) < 8) { fail('Choisissez un mot de passe d\'au moins 8 caractères.'); }
    setSetting($pdo, 'password_hash', password_hash($p, PASSWORD_DEFAULT));
    session_regenerate_id(true); $_SESSION['ok'] = true;
    out(['ok' => true]);
}

if ($r === 'login') {
    if (!$isPost || $hash === null) { fail('Requête invalide.', 400); }
    $until = (int)(setting($pdo, 'lock_until') ?? 0);
    if ($until > time()) { fail('Trop d\'essais. Réessayez dans quelques minutes.', 429); }
    $p = (string)(body()['password'] ?? '');
    if (password_verify($p, $hash)) {
        setSetting($pdo, 'fails', '0');
        session_regenerate_id(true); $_SESSION['ok'] = true;
        out(['ok' => true]);
    }
    $f = (int)(setting($pdo, 'fails') ?? 0) + 1;
    setSetting($pdo, 'fails', (string)$f);
    if ($f >= 8) { setSetting($pdo, 'lock_until', (string)(time() + 900)); setSetting($pdo, 'fails', '0'); }
    usleep(700000);
    fail('Mot de passe incorrect.', 401);
}

if ($r === 'logout') { $_SESSION = []; session_destroy(); out(['ok' => true]); }

if (!$authed) { fail('Connexion requise.', 401); }

function load(PDO $pdo, string $t): array {
    $rows = [];
    foreach ($pdo->query("SELECT id,data FROM $t") as $row) {
        $d = json_decode($row['data'], true);
        if (is_array($d)) { $d['id'] = $row['id']; $rows[] = $d; }
    }
    return $rows;
}
function okId($id): bool { return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) === 1; }
function okColl($c): bool { return $c === 'products' || $c === 'orders' || $c === 'expenses'; }
function photoFiles(string $photo): array {
    if (!preg_match('#^uploads/([a-f0-9]{24}\.jpg)$#', $photo, $m)) { return []; }
    return [__DIR__ . '/uploads/' . $m[1], __DIR__ . '/uploads/t_' . $m[1]];
}
function dropPhoto(PDO $pdo, string $photo, string $exceptId): void {
    $s = $pdo->prepare('SELECT COUNT(*) FROM products WHERE id<>? AND data LIKE ?');
    $s->execute([$exceptId, '%' . str_replace(['%', '_'], ['', ''], $photo) . '%']);
    if ((int)$s->fetchColumn() > 0) { return; }
    foreach (photoFiles($photo) as $f) { if (is_file($f)) { @unlink($f); } }
}

switch ($r) {
    case 'state':
        out(['products' => load($pdo, 'products'), 'orders' => load($pdo, 'orders'), 'expenses' => load($pdo, 'expenses')]);

    case 'export':
        header('Content-Disposition: attachment; filename="sauvegarde-watch-ci-' . date('Y-m-d') . '.json"');
        out(['date' => date('c'), 'products' => load($pdo, 'products'), 'orders' => load($pdo, 'orders'), 'expenses' => load($pdo, 'expenses')]);

    case 'save': {
        if (!$isPost) { fail('POST requis.', 405); }
        $b = body(); $c = $b['coll'] ?? ''; $id = $b['id'] ?? ''; $d = $b['data'] ?? null;
        if (!okColl($c) || !okId($id) || !is_array($d)) { fail('Données invalides.'); }
        unset($d['id']);
        if ($c === 'products') {
            $s = $pdo->prepare('SELECT data FROM products WHERE id=?'); $s->execute([$id]);
            $old = json_decode((string)$s->fetchColumn(), true);
            if (is_array($old) && !empty($old['photo']) && ($d['photo'] ?? '') !== $old['photo']) { dropPhoto($pdo, (string)$old['photo'], $id); }
        }
        $json = json_encode($d, JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 200000) { fail('Document trop volumineux.'); }
        $pdo->prepare("INSERT INTO $c(id,data,updated) VALUES(?,?,?) ON CONFLICT(id) DO UPDATE SET data=excluded.data, updated=excluded.updated")->execute([$id, $json, time()]);
        out(['ok' => true]);
    }

    case 'delete': {
        if (!$isPost) { fail('POST requis.', 405); }
        $b = body(); $c = $b['coll'] ?? ''; $id = $b['id'] ?? '';
        if (!okColl($c) || !okId($id)) { fail('Données invalides.'); }
        if ($c === 'products') {
            $s = $pdo->prepare('SELECT data FROM products WHERE id=?'); $s->execute([$id]);
            $old = json_decode((string)$s->fetchColumn(), true);
            if (is_array($old) && !empty($old['photo'])) { dropPhoto($pdo, (string)$old['photo'], $id); }
        }
        $pdo->prepare("DELETE FROM $c WHERE id=?")->execute([$id]);
        out(['ok' => true]);
    }

    case 'adjust': {
        if (!$isPost) { fail('POST requis.', 405); }
        $items = body()['items'] ?? [];
        if (!is_array($items)) { fail('Données invalides.'); }
        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare('SELECT data FROM products WHERE id=?');
            $upd = $pdo->prepare('UPDATE products SET data=?, updated=? WHERE id=?');
            foreach ($items as $it) {
                $pid = $it['pid'] ?? ''; $delta = (int)($it['delta'] ?? 0);
                if (!okId($pid) || $delta === 0) { continue; }
                $sel->execute([$pid]); $raw = $sel->fetchColumn();
                if ($raw === false) { continue; }
                $d = json_decode((string)$raw, true); if (!is_array($d)) { continue; }
                $d['stock'] = (int)($d['stock'] ?? 0) + $delta;
                $upd->execute([json_encode($d, JSON_UNESCAPED_UNICODE), time(), $pid]);
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); fail('Mise à jour du stock impossible.', 500); }
        out(['ok' => true]);
    }

    case 'upload': {
        if (!$isPost) { fail('POST requis.', 405); }
        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) { fail('Photo non reçue (taille trop grande ?).'); }
        $tmp = $_FILES['photo']['tmp_name'];
        if ($_FILES['photo']['size'] > 12 * 1024 * 1024) { fail('Photo trop lourde (12 Mo maximum).'); }
        $info = @getimagesize($tmp);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) { fail('Format non pris en charge. Utilisez une photo JPEG ou PNG.'); }
        $name = bin2hex(random_bytes(12)) . '.jpg';
        $dest = $upDir . '/' . $name; $thumb = $upDir . '/t_' . $name;
        if (!function_exists('imagecreatefromstring')) {
            if ($info[2] !== IMAGETYPE_JPEG) { fail('Seules les photos JPEG sont acceptées sur cet hébergement.'); }
            move_uploaded_file($tmp, $dest); copy($dest, $thumb);
            out(['photo' => 'uploads/' . $name]);
        }
        $img = @imagecreatefromstring((string)file_get_contents($tmp));
        if (!$img) { fail('Image illisible.'); }
        $fit = function ($src, int $max) {
            $w = imagesx($src); $h = imagesy($src); $k = min(1, $max / max($w, $h));
            $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            return $dst;
        };
        $big = $fit($img, 1000); imagejpeg($big, $dest, 82);
        $sm = $fit($img, 320);   imagejpeg($sm, $thumb, 78);
        imagedestroy($img); imagedestroy($big); imagedestroy($sm);
        out(['photo' => 'uploads/' . $name]);
    }

    case 'update_check': {
        [$code, $body] = httpGet('https://api.github.com/repos/' . UPDATE_REPO . '/contents/VERSION?ref=' . UPDATE_BRANCH, ['Accept: application/vnd.github.raw'], 15);
        $remote = trim($body);
        if ($code !== 200 || !preg_match('/^\d+$/', $remote)) { fail('Impossible de joindre le serveur de mises à jour pour le moment.', 502); }
        $local = localVersion();
        out(['local' => $local, 'remote' => $remote, 'available' => (int)$remote > (int)$local]);
    }

    case 'update_apply': {
        if (!$isPost) { fail('POST requis.', 405); }
        if (!class_exists('ZipArchive')) { fail('Cet hébergement n\'a pas l\'extension ZIP de PHP. Utilisez la mise à jour par zip.', 500); }
        @set_time_limit(120);
        [$code, $zipData] = httpGet('https://codeload.github.com/' . UPDATE_REPO . '/zip/refs/heads/' . UPDATE_BRANCH, [], 60);
        if ($code !== 200 || strlen($zipData) < 1000) { fail('Téléchargement de la mise à jour impossible (code ' . $code . ').', 502); }
        $tmpZip = $dataDir . '/update.zip';
        file_put_contents($tmpZip, $zipData);
        $z = new ZipArchive();
        if ($z->open($tmpZip) !== true) { @unlink($tmpZip); fail('Archive de mise à jour illisible.', 500); }
        $skipTop = ['data', 'uploads', '.git', '.github'];
        $allowed = ['php', 'html', 'png', 'webmanifest', 'txt', 'md', 'css', 'js', 'json', 'ico', 'svg'];
        $files = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $name = $z->getNameIndex($i);
            if ($name === false || substr($name, -1) === '/') { continue; }
            $pos = strpos($name, '/'); if ($pos === false) { continue; }
            $rel = substr($name, $pos + 1);
            if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/' || $rel[0] === '.') { continue; }
            $top = explode('/', $rel)[0];
            if (in_array($top, $skipTop, true) || basename($rel)[0] === '.') { continue; }
            if (!in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), $allowed, true)) { continue; }
            $files[$rel] = (string)$z->getFromIndex($i);
        }
        $z->close(); @unlink($tmpZip);
        foreach (['api.php', 'index.html', 'VERSION'] as $need) { if (!isset($files[$need]) || $files[$need] === '') { fail('Mise à jour incomplète (' . $need . ' manquant). Rien n\'a été modifié.', 500); } }
        if (strpos($files['api.php'], 'WCI-GESTION-API') === false || strpos($files['index.html'], 'Watch CI Gestion') === false) { fail('Archive non reconnue. Rien n\'a été modifié.', 500); }
        foreach ($files as $rel => $content) {
            if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'php') {
                try { token_get_all($content, TOKEN_PARSE); } catch (Throwable $e) { fail('Le nouveau code contient une erreur. Rien n\'a été modifié.', 500); }
            }
        }
        $bk = $dataDir . '/backup'; rrmdir($bk); @mkdir($bk, 0755, true);
        foreach ($files as $rel => $content) {
            $cur = __DIR__ . '/' . $rel;
            if (is_file($cur)) { @mkdir(dirname($bk . '/' . $rel), 0755, true); @copy($cur, $bk . '/' . $rel); }
        }
        uksort($files, function ($a, $b) { return ($a === 'api.php') <=> ($b === 'api.php'); });
        foreach ($files as $rel => $content) {
            $dest = __DIR__ . '/' . $rel; @mkdir(dirname($dest), 0755, true);
            $tmp = $dest . '.new';
            if (file_put_contents($tmp, $content) === false || !@rename($tmp, $dest)) { @unlink($tmp); fail('Écriture impossible sur ' . $rel . '. Vérifiez les droits du dossier.', 500); }
            if (function_exists('opcache_invalidate')) { @opcache_invalidate($dest, true); }
        }
        out(['ok' => true, 'version' => trim($files['VERSION'])]);
    }

    case 'rollback': {
        if (!$isPost) { fail('POST requis.', 405); }
        $bk = $dataDir . '/backup';
        if (!is_dir($bk)) { fail('Aucune version précédente disponible.', 404); }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bk, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) { continue; }
            $rel = substr($f->getPathname(), strlen($bk) + 1);
            if (strpos($rel, '..') !== false) { continue; }
            $dest = __DIR__ . '/' . $rel; @mkdir(dirname($dest), 0755, true);
            @copy($f->getPathname(), $dest . '.new'); @rename($dest . '.new', $dest);
            if (function_exists('opcache_invalidate')) { @opcache_invalidate($dest, true); }
        }
        out(['ok' => true, 'version' => localVersion()]);
    }

    default: fail('Route inconnue.', 404);
}
