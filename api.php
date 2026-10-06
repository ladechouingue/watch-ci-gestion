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

function catOk(string $f): bool { return (bool)preg_match('/^[A-Za-z0-9_-]{1,60}\.pdf$/', $f); }
function catList(PDO $pdo, string $dir): array {
    $rows = [];
    foreach (glob($dir . '/*.pdf') ?: [] as $f) {
        $b = basename($f);
        $rows[] = ['file' => $b, 'label' => setting($pdo, 'catlabel:' . $b) ?? preg_replace('/\.pdf$/i', '', $b),
                   'size' => (int)filesize($f), 'ord' => (int)(setting($pdo, 'catord:' . $b) ?? filemtime($f))];
    }
    usort($rows, function ($a, $b) { return $a['ord'] <=> $b['ord']; });
    return $rows;
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


function bkData(PDO $pdo, bool $photos = true): string {
    $l = function (string $t) use ($pdo): array {
        $rows = [];
        foreach ($pdo->query("SELECT id,data FROM $t") as $row) { $d = json_decode($row['data'], true); if (is_array($d)) { $d['id'] = $row['id']; $rows[] = $d; } }
        return $rows;
    };
    $prods = $l('products');
    $ph = [];
    if ($photos) {
        foreach ($prods as $p) {
            if (!empty($p['photo']) && preg_match('#^uploads/([a-f0-9]{24}\.jpg)$#', (string)$p['photo'], $m)) {
                foreach ([$m[1], 't_' . $m[1]] as $f) {
                    $path = __DIR__ . '/uploads/' . $f;
                    if (!isset($ph[$f]) && is_file($path)) { $ph[$f] = base64_encode((string)file_get_contents($path)); }
                }
            }
        }
    }
    $set = [];
    foreach ($pdo->query('SELECT k,v FROM settings') as $row) { if (!in_array($row['k'], ['lock_until', 'fails', 'bk_try'], true)) { $set[$row['k']] = (string)$row['v']; } }
    return (string)json_encode(['date' => date('c'), 'version' => localVersion(), 'products' => $prods, 'orders' => $l('orders'), 'expenses' => $l('expenses'), 'settings' => $set, 'photos' => $ph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function bkZip(PDO $pdo, bool $withCats): ?string {
    if (!class_exists('ZipArchive')) { return null; }
    $tmp = tempnam(sys_get_temp_dir(), 'wci');
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { return null; }
    $z->addFromString('backup.json', bkData($pdo, false));
    foreach (glob(__DIR__ . '/uploads/*.jpg') ?: [] as $f) { $z->addFile($f, 'uploads/' . basename($f)); }
    if ($withCats) { foreach (glob(__DIR__ . '/uploads/catalogues/*.pdf') ?: [] as $f) { $z->addFile($f, 'uploads/catalogues/' . basename($f)); } }
    $z->close();
    return $tmp;
}
function bkSend(PDO $pdo, string $to): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { return false; }
    @ini_set('memory_limit', '512M'); @set_time_limit(120);
    $host = preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $host = preg_replace('/^gestion\./', '', $host);
    $from = 'gestion@' . preg_replace('/[^A-Za-z0-9.-]/', '', $host);
    $note = '';
    $zip = bkZip($pdo, true);
    if ($zip !== null && filesize($zip) > 17000000) { @unlink($zip); $zip = bkZip($pdo, false); $note = "\nAttention : les catalogues PDF n'ont pas pu être inclus (fichier trop lourd pour un e-mail). Gardez-les de votre côté.\n"; }
    if ($zip !== null) { $data = (string)file_get_contents($zip); @unlink($zip); $name = 'sauvegarde-complete-watch-ci-' . date('Y-m-d') . '.zip'; $ct = 'application/zip'; }
    else { $data = bkData($pdo, true); $name = 'sauvegarde-watch-ci-' . date('Y-m-d') . '.json'; $ct = 'application/json'; }
    $b = 'wci' . bin2hex(random_bytes(8));
    $subject = '=?UTF-8?B?' . base64_encode('Sauvegarde Watch Côte d\'Ivoire - ' . date('d/m/Y')) . '?=';
    $h = "From: Watch CI Gestion <$from>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b\"";
    $msg = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode("Bonjour,\n\nVoici la sauvegarde complète du jour de votre outil de gestion : produits et photos, commandes, dépenses, réglages et catalogues.\nGardez ce mail : dans « Sauvegarde » > « Restaurer », ce fichier remet tout en place sur un nouvel hébergement.\n" . $note)) . "\r\n"
        . "--$b\r\nContent-Type: $ct; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$name\"\r\n\r\n"
        . chunk_split(base64_encode($data)) . "\r\n--$b--";
    unset($data);
    $ok = @mail($to, $subject, $msg, $h, '-f' . $from);
    if (!$ok) { $ok = @mail($to, $subject, $msg, $h); }
    if ($ok) { setSetting($pdo, 'bk_last', date('Y-m-d H:i')); }
    return (bool)$ok;
}
function bkToken(PDO $pdo): string {
    $t = setting($pdo, 'bk_token');
    if ($t === null || $t === '') { $t = bin2hex(random_bytes(16)); setSetting($pdo, 'bk_token', $t); }
    return $t;
}

if ($r === 'bk_cron') {
    $t = (string)($_GET['t'] ?? ''); $real = setting($pdo, 'bk_token');
    if ($real === null || $real === '' || !hash_equals($real, $t)) { fail('Accès refusé.', 403); }
    $to = (string)(setting($pdo, 'bk_email') ?? '');
    if ($to === '') { fail('Aucune adresse enregistrée.'); }
    out(['sent' => bkSend($pdo, $to)]);
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

/* ---------- Facture PDF (sans bibliothèque) ---------- */
function pdfW(string $t, bool $b, float $sz): float {
    static $r = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    static $d = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];
    $a = $b ? $d : $r; $w = 0;
    $x = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t); if ($x === false) { $x = $t; }
    foreach (str_split($x) as $c) { $o = ord($c); $w += ($o >= 32 && $o <= 126) ? $a[$o - 32] : 556; }
    return $w * $sz / 1000;
}
function pdfFit(string $t, bool $b, float $sz, float $max): string {
    if (pdfW($t, $b, $sz) <= $max) { return $t; }
    while ($t !== '' && pdfW($t . '...', $b, $sz) > $max) { $t = mb_substr($t, 0, mb_strlen($t) - 1); }
    return $t . '...';
}
function pdfStr(string $t): string {
    $x = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $t); if ($x === false) { $x = $t; }
    return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $x);
}
function money($n): string { return number_format((float)$n, 0, ',', ' ') . ' F'; }
function logoJpeg(): ?array {
    if (!function_exists('imagecreatefromstring')) { return null; }
    foreach ([__DIR__ . '/uploads/logo.jpg', __DIR__ . '/logo.png', __DIR__ . '/icon-192.png'] as $f) {
        if (!is_file($f)) { continue; }
        $im = @imagecreatefromstring((string)file_get_contents($f)); if (!$im) { continue; }
        $w = imagesx($im); $h = imagesy($im);
        $bg = imagecreatetruecolor($w, $h); imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
        imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
        ob_start(); imagejpeg($bg, null, 88); $bin = (string)ob_get_clean();
        imagedestroy($im); imagedestroy($bg);
        return ['bin' => $bin, 'w' => $w, 'h' => $h];
    }
    return null;
}
function buildInvoice(array $o, string $num, array $biz = []): string {
    $items = is_array($o['items'] ?? null) ? $o['items'] : [];
    $sub = 0; foreach ($items as $i) { $sub += (float)($i['qty'] ?? 0) * (float)($i['price'] ?? 0); }
    $mine = !empty($o['fee']);
    $ship = $mine ? 0.0 : (float)($o['dcost'] ?? 0);
    $total = $sub + $ship;
    $logo = $biz['logo'] ?? null;
    $paid = !empty($o['paid']);
    $render = function (float $H) use ($o, $num, $biz, $items, $sub, $mine, $ship, $total, $logo, $paid): array {
        $c = '';
        $T = function (float $x, float $y, string $t, float $sz = 10, bool $b = false, string $al = 'l', string $col = '0.1 0.13 0.11') use (&$c, $H) {
            if ($al === 'r') { $x -= pdfW($t, $b, $sz); } elseif ($al === 'c') { $x -= pdfW($t, $b, $sz) / 2; }
            $c .= "BT /" . ($b ? 'F2' : 'F1') . " $sz Tf $col rg 1 0 0 1 " . round($x, 2) . ' ' . round($H - $y, 2) . ' Tm (' . pdfStr($t) . ") Tj ET\n";
        };
        $R = function (float $x, float $y, float $w, float $h, string $col) use (&$c, $H) { $c .= "$col rg " . round($x, 2) . ' ' . round($H - $y - $h, 2) . " $w $h re f\n"; };
        $line2 = trim('www.watchcotedivoire.com' . (!empty($biz['phone']) ? '  ·  ' . $biz['phone'] : ''));
        $hh = !empty($biz['addr']) ? 124 : 110;
        $R(0, 0, 595, $hh, '0.87 0.39 0.06');
        $tx = 48;
        if ($logo) {
            $R(48, 23, 64, 64, '1 1 1');
            $k = 52 / max($logo['w'], $logo['h']); $dw = round($logo['w'] * $k, 2); $dh = round($logo['h'] * $k, 2);
            $c .= "q $dw 0 0 $dh " . round(48 + (64 - $dw) / 2, 2) . " " . round($H - 23 - $dh - (64 - $dh) / 2, 2) . " cm /Im1 Do Q\n"; $tx = 126;
        }
        $T($tx, 50, 'Watch Côte d\'Ivoire', 22, true, 'l', '1 1 1');
        $T($tx, 70, 'Bracelets et accessoires Apple Watch', 10, false, 'l', '1 1 1');
        $T($tx, 86, $line2, 10, false, 'l', '1 1 1');
        if (!empty($biz['addr'])) { $T($tx, 102, pdfFit((string)$biz['addr'], false, 10, 380), 10, false, 'l', '1 1 1'); }
        $T(547, 50, 'FACTURE', 22, true, 'r', '1 1 1');
        $T(547, 70, 'N° ' . $num, 11, false, 'r', '1 1 1');
        $T(547, 86, 'Date : ' . date('d/m/Y', strtotime((string)($o['date'] ?? 'now'))), 10, false, 'r', '1 1 1');
        $y0 = $hh + 36;
        $T(48, $y0, 'FACTURÉ À', 9, true, 'l', '0.45 0.5 0.47');
        $T(48, $y0 + 19, pdfFit((string)($o['customer'] ?? 'Client'), true, 14, 360), 14, true);
        $y = $y0 + 33;
        if (!empty($o['phone'])) { $T(48, $y + 4, 'Tél. ' . $o['phone'], 10); $y += 17; }
        $R(447, $y0 - 10, 100, 28, $paid ? '0.84 0.94 0.88' : '0.99 0.9 0.86');
        $T(497, $y0 + 8, $paid ? 'PAYÉE' : 'À PAYER', 11, true, 'c', $paid ? '0.1 0.45 0.25' : '0.7 0.2 0.1');
        $y += 24;
        $R(48, $y, 499, 24, '0.94 0.95 0.94');
        $T(58, $y + 16, 'Article', 9, true); $T(360, $y + 16, 'Qté', 9, true, 'r'); $T(455, $y + 16, 'Prix unit.', 9, true, 'r'); $T(537, $y + 16, 'Total', 9, true, 'r');
        $y += 24;
        foreach ($items as $i) {
            $q = (float)($i['qty'] ?? 0); $p = (float)($i['price'] ?? 0);
            $T(58, $y + 18, pdfFit((string)($i['label'] ?? ''), false, 10, 270), 10);
            $T(360, $y + 18, (string)(int)$q, 10, false, 'r'); $T(455, $y + 18, money($p), 10, false, 'r'); $T(537, $y + 18, money($q * $p), 10, true, 'r');
            $y += 28; $c .= "0.88 0.9 0.89 RG 0.5 w 48 " . round($H - $y, 2) . " m 547 " . round($H - $y, 2) . " l S\n";
        }
        $y += 20;
        $T(455, $y, 'Sous-total', 10, false, 'r'); $T(537, $y, money($sub), 10, false, 'r'); $y += 18;
        $T(455, $y, 'Livraison', 10, false, 'r'); $T(537, $y, ($mine || $ship == 0) ? 'Offerte' : money($ship), 10, false, 'r'); $y += 12;
        $R(330, $y, 217, 34, '0.87 0.39 0.06');
        $T(342, $y + 22, 'TOTAL', 11, true, 'l', '1 1 1'); $T(537, $y + 22, money($total), 14, true, 'r', '1 1 1');
        $y += 34;
        if (!empty($o['note'])) {
            $y += 22; $line = ''; $words = preg_split('/\s+/u', 'Note : ' . trim((string)$o['note'])) ?: [];
            foreach ($words as $w) {
                if ($line !== '' && pdfW($line . ' ' . $w, false, 9) > 499) { $T(48, $y, $line, 9, false, 'l', '0.45 0.5 0.47'); $y += 13; $line = $w; }
                else { $line = $line === '' ? $w : $line . ' ' . $w; }
            }
            if ($line !== '') { $T(48, $y, $line, 9, false, 'l', '0.45 0.5 0.47'); $y += 13; }
            $y += 4;
        } else { $y += 34; }
        $T(297, $y, 'Merci pour votre confiance !', 11, true, 'c', '0.87 0.39 0.06');
        return [$c, $y];
    };
    [, $yEnd] = $render(2000.0);
    $H = (float)ceil($yEnd + 28);
    [$c] = $render($H);
    $objs = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '<< /Type /Pages /Kids [6 0 R] /Count 1 >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>', 4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        5 => "<< /Length " . strlen($c) . " >>\nstream\n" . $c . "endstream"];
    $xo = '';
    if ($logo) {
        $objs[7] = "<< /Type /XObject /Subtype /Image /Width {$logo['w']} /Height {$logo['h']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($logo['bin']) . " >>\nstream\n" . $logo['bin'] . "\nendstream";
        $xo = ' /XObject << /Im1 7 0 R >>';
    }
    $objs[6] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 $H] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>$xo >> /Contents 5 0 R >>";
    ksort($objs);
    $out = "%PDF-1.4\n"; $off = [];
    foreach ($objs as $k => $v) { $off[$k] = strlen($out); $out .= "$k 0 obj\n$v\nendobj\n"; }
    $xr = strlen($out); $mx = max(array_keys($objs)) + 1;
    $out .= "xref\n0 $mx\n0000000000 65535 f \n";
    for ($k = 1; $k < $mx; $k++) { $out .= sprintf("%010d 00000 n \n", $off[$k]); }
    return $out . "trailer\n<< /Size $mx /Root 1 0 R >>\nstartxref\n$xr\n%%EOF";
}

switch ($r) {
    case 'state':
        $bkTo = (string)(setting($pdo, 'bk_email') ?? '');
        if ($bkTo !== '' && substr((string)setting($pdo, 'bk_last'), 0, 10) !== date('Y-m-d') && (int)setting($pdo, 'bk_try') !== (int)date('Ymd') * 100 + (int)date('G')) {
            setSetting($pdo, 'bk_try', (string)((int)date('Ymd') * 100 + (int)date('G')));
            try { bkSend($pdo, $bkTo); } catch (Throwable $e) { }
        }
        out(['products' => load($pdo, 'products'), 'orders' => load($pdo, 'orders'), 'expenses' => load($pdo, 'expenses')]);

    case 'export':
                @ini_set('memory_limit', '512M');
        $zp = bkZip($pdo, true);
        if ($zp !== null) {
            header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="sauvegarde-complete-watch-ci-' . date('Y-m-d') . '.zip"'); header('Content-Length: ' . filesize($zp));
            readfile($zp); @unlink($zp); exit;
        }
        header('Content-Type: application/json; charset=utf-8'); echo bkData($pdo, true); exit;

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
            if ($rel !== 'VERSION' && !in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), $allowed, true)) { continue; }
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

    case 'catalogues': {
        $dir = $upDir . '/catalogues';
        $list = catList($pdo, $dir);
        $seed = 0;
        if (!$list && setting($pdo, 'cat_seeded') === null) { $seed = 1; }
        out(['catalogues' => $list, 'seed' => $seed]);
    }

    case 'cat_seed': {
        if (!$isPost) { fail('POST requis.', 405); }
        $i = (int)(body()['i'] ?? 0);
        $base = 'https://raw.githubusercontent.com/' . UPDATE_REPO . '/' . UPDATE_BRANCH . '/catalogues-initial/';
        [$code, $man] = httpGet($base . 'manifest.json', [], 30);
        $m = json_decode($man, true);
        if ($code !== 200 || !is_array($m)) { fail('Catalogues d\'origine introuvables (code ' . $code . ').', 502); }
        if (!isset($m[$i])) { setSetting($pdo, 'cat_seeded', '1'); out(['done' => true, 'total' => count($m)]); }
        $f = (string)($m[$i]['file'] ?? ''); $label = (string)($m[$i]['label'] ?? $f);
        if (!catOk($f)) { fail('Nom de fichier invalide.', 500); }
        $dir = $upDir . '/catalogues'; if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        if (!is_file($dir . '/' . $f)) {
            [$c2, $pdf] = httpGet($base . $f, [], 120);
            if ($c2 !== 200 || strncmp($pdf, '%PDF', 4) !== 0) { fail('Téléchargement impossible pour « ' . $label . ' » (code ' . $c2 . ').', 502); }
            if (file_put_contents($dir . '/' . $f, $pdf) === false) { fail('Écriture impossible dans le dossier uploads.', 500); }
            setSetting($pdo, 'catlabel:' . $f, $label); setSetting($pdo, 'catord:' . $f, (string)(1000 + $i));
        }
        $last = !isset($m[$i + 1]); if ($last) { setSetting($pdo, 'cat_seeded', '1'); }
        out(['done' => $last, 'next' => $i + 1, 'total' => count($m)]);
    }

    case 'cat_upload': {
        if (!$isPost) { fail('POST requis.', 405); }
        if (empty($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) { fail('Fichier non reçu (trop lourd pour l\'hébergement ?).'); }
        $tmp = $_FILES['pdf']['tmp_name'];
        if ($_FILES['pdf']['size'] > 40 * 1024 * 1024) { fail('Fichier trop lourd (40 Mo maximum).'); }
        $fh = @fopen($tmp, 'rb'); $magic = $fh ? (string)fread($fh, 4) : ''; if ($fh) { fclose($fh); }
        if ($magic !== '%PDF') { fail('Ce fichier n\'est pas un PDF.'); }
        $label = trim((string)($_POST['label'] ?? ''));
        if ($label === '') { $label = preg_replace('/\.pdf$/i', '', (string)($_FILES['pdf']['name'] ?? 'Catalogue')); }
        $label = mb_substr($label, 0, 80);
        $dir = $upDir . '/catalogues'; if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $name = 'c' . bin2hex(random_bytes(8)) . '.pdf';
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) { fail('Écriture impossible dans le dossier uploads.', 500); }
        setSetting($pdo, 'catlabel:' . $name, $label); setSetting($pdo, 'catord:' . $name, (string)time());
        setSetting($pdo, 'cat_seeded', '1');
        out(['ok' => true]);
    }

    case 'cat_rename': {
        if (!$isPost) { fail('POST requis.', 405); }
        $b = body(); $f = (string)($b['file'] ?? ''); $label = mb_substr(trim((string)($b['label'] ?? '')), 0, 80);
        if (!catOk($f) || !is_file($upDir . '/catalogues/' . $f)) { fail('Catalogue introuvable.'); }
        if ($label === '') { fail('Indiquez un nom.'); }
        setSetting($pdo, 'catlabel:' . $f, $label);
        out(['ok' => true]);
    }

    case 'cat_delete': {
        if (!$isPost) { fail('POST requis.', 405); }
        $f = (string)(body()['file'] ?? '');
        if (!catOk($f)) { fail('Fichier invalide.'); }
        @unlink($upDir . '/catalogues/' . $f);
        $pdo->prepare('DELETE FROM settings WHERE k IN (?,?)')->execute(['catlabel:' . $f, 'catord:' . $f]);
        setSetting($pdo, 'cat_seeded', '1');
        out(['ok' => true]);
    }

    case 'restore': {
        if (!$isPost) { fail('POST requis.', 405); }
        if (empty($_FILES['file']) || (int)$_FILES['file']['error'] !== UPLOAD_ERR_OK) { fail('Fichier non reçu (trop gros pour l\'hébergement ?).'); }
        @ini_set('memory_limit', '512M'); @set_time_limit(300);
        $tmpf = $_FILES['file']['tmp_name']; $zr = null;
        if (substr((string)file_get_contents($tmpf, false, null, 0, 2), 0, 2) === 'PK') {
            if (!class_exists('ZipArchive')) { fail('Le serveur ne sait pas lire les fichiers .zip.'); }
            $zr = new ZipArchive();
            if ($zr->open($tmpf) !== true) { fail('Fichier .zip illisible.'); }
            $j = json_decode((string)$zr->getFromName('backup.json'), true);
        } else { $j = json_decode((string)file_get_contents($tmpf), true); }
        if (!is_array($j) || !isset($j['products'], $j['orders'], $j['expenses']) || !is_array($j['products']) || !is_array($j['orders']) || !is_array($j['expenses'])) { fail('Ce fichier n\'est pas une sauvegarde valide.'); }
        @file_put_contents($dataDir . '/avant-restauration.json', bkData($pdo, false));
        $pdo->beginTransaction();
        try {
            foreach (['products', 'orders', 'expenses'] as $t) {
                $pdo->exec("DELETE FROM $t");
                $ins = $pdo->prepare("INSERT INTO $t(id,data,updated) VALUES(?,?,?)");
                foreach ($j[$t] as $d) {
                    if (!is_array($d) || !okId($d['id'] ?? null)) { continue; }
                    $id = $d['id']; unset($d['id']);
                    $ins->execute([$id, json_encode($d, JSON_UNESCAPED_UNICODE), time()]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); fail('Restauration impossible : ' . $e->getMessage(), 500); }
        $np = 0;
        if (isset($j['photos']) && is_array($j['photos'])) {
            foreach ($j['photos'] as $f => $b64) {
                if (!is_string($f) || !is_string($b64) || !preg_match('/^(t_)?[a-f0-9]{24}\.jpg$/', $f)) { continue; }
                $bin = base64_decode($b64, true);
                if ($bin === false || substr($bin, 0, 2) !== "\xFF\xD8") { continue; }
                file_put_contents($upDir . '/' . $f, $bin); $np++;
            }
        }
        $nc = 0;
        if ($zr !== null) {
            @mkdir($upDir . '/catalogues', 0755, true);
            for ($k = 0; $k < $zr->numFiles; $k++) {
                $en = (string)$zr->getNameIndex($k);
                $isPh = preg_match('#^uploads/((t_)?[a-f0-9]{24}\.jpg|logo\.jpg)$#', $en, $m);
                $isCa = preg_match('#^uploads/catalogues/([A-Za-z0-9_-]{1,60}\.pdf)$#', $en, $m2);
                if (!$isPh && !$isCa) { continue; }
                $bin = (string)$zr->getFromIndex($k);
                if ($isPh && substr($bin, 0, 2) === "\xFF\xD8") { file_put_contents($upDir . '/' . $m[1], $bin); $np++; }
                if ($isCa && substr($bin, 0, 4) === '%PDF') { file_put_contents($upDir . '/catalogues/' . $m2[1], $bin); $nc++; }
            }
            $zr->close();
        }
        if (isset($j['settings']) && is_array($j['settings'])) {
            foreach ($j['settings'] as $k => $v) {
                if (is_string($k) && is_string($v) && preg_match('/^[A-Za-z0-9_:.\-]{1,100}$/', $k) && !in_array($k, ['lock_until', 'fails', 'bk_try'], true)) { setSetting($pdo, $k, $v); }
            }
        }
        out(['ok' => true, 'products' => count($j['products']), 'orders' => count($j['orders']), 'expenses' => count($j['expenses']), 'photos' => $np, 'catalogues' => $nc]);
    }

    case 'invoice': {
        $id = (string)($_GET['id'] ?? '');
        if (!okId($id)) { fail('Commande invalide.'); }
        $st = $pdo->prepare('SELECT data FROM orders WHERE id=?'); $st->execute([$id]);
        $o = json_decode((string)$st->fetchColumn(), true);
        if (!is_array($o)) { fail('Commande introuvable.', 404); }
        if (empty($o['inv'])) {
            $seq = (int)(setting($pdo, 'inv_seq') ?? 0) + 1; setSetting($pdo, 'inv_seq', (string)$seq);
            $o['inv'] = 'F-' . date('Y') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE orders SET data=? WHERE id=?')->execute([json_encode($o, JSON_UNESCAPED_UNICODE), $id]);
        }
        $pdf = buildInvoice($o, (string)$o['inv'], ['phone' => (string)(setting($pdo, 'inv_phone') ?? ''), 'addr' => (string)(setting($pdo, 'inv_addr') ?? ''), 'logo' => logoJpeg()]);
        header('Content-Type: application/pdf'); header('Cache-Control: private, no-store');
        header('Content-Disposition: inline; filename="Facture-' . $o['inv'] . '.pdf"'); header('Content-Length: ' . strlen($pdf));
        echo $pdf; exit;
    }

    case 'inv_get':
        out(['phone' => (string)(setting($pdo, 'inv_phone') ?? ''), 'addr' => (string)(setting($pdo, 'inv_addr') ?? ''), 'logo' => is_file(__DIR__ . '/uploads/logo.jpg') ? 'uploads/logo.jpg?v=' . filemtime(__DIR__ . '/uploads/logo.jpg') : '']);

    case 'inv_set': {
        if (!$isPost) { fail('POST requis.', 405); }
        $b = body();
        setSetting($pdo, 'inv_phone', mb_substr(trim((string)($b['phone'] ?? '')), 0, 40));
        setSetting($pdo, 'inv_addr', mb_substr(trim((string)($b['addr'] ?? '')), 0, 120));
        out(['ok' => true]);
    }

    case 'inv_logo': {
        if (!$isPost) { fail('POST requis.', 405); }
        if (empty($_FILES['logo']) || (int)$_FILES['logo']['error'] !== UPLOAD_ERR_OK) { fail('Image non reçue.'); }
        if (!function_exists('imagecreatefromstring')) { fail('Le serveur ne sait pas traiter les images.'); }
        $im = @imagecreatefromstring((string)file_get_contents($_FILES['logo']['tmp_name']));
        if (!$im) { fail('Image illisible.'); }
        $w = imagesx($im); $h = imagesy($im); $k = min(1, 400 / max($w, $h));
        $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
        $dst = imagecreatetruecolor($nw, $nh); imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagejpeg($dst, __DIR__ . '/uploads/logo.jpg', 90);
        out(['ok' => true]);
    }

    case 'inv_logo_del': {
        if (!$isPost) { fail('POST requis.', 405); }
        @unlink(__DIR__ . '/uploads/logo.jpg');
        out(['ok' => true]);
    }

    case 'bk_get':
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api.php'))), '/');
        out(['email' => (string)(setting($pdo, 'bk_email') ?? ''), 'last' => (string)(setting($pdo, 'bk_last') ?? ''),
             'cron' => 'curl -s "' . $proto . '://' . ($_SERVER['HTTP_HOST'] ?? '') . $dir . '/api.php?r=bk_cron&t=' . bkToken($pdo) . '" > /dev/null 2>&1']);

    case 'bk_set': {
        if (!$isPost) { fail('POST requis.', 405); }
        $e = trim((string)(body()['email'] ?? ''));
        if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) { fail('Adresse e-mail invalide.'); }
        setSetting($pdo, 'bk_email', $e);
        out(['ok' => true]);
    }

    case 'bk_test': {
        if (!$isPost) { fail('POST requis.', 405); }
        $e = (string)(setting($pdo, 'bk_email') ?? '');
        if ($e === '') { fail('Enregistrez d\'abord une adresse e-mail.'); }
        if (!bkSend($pdo, $e)) { fail('Envoi impossible depuis ce serveur. Contactez l\'hébergeur (LWS) ou vérifiez l\'adresse.'); }
        out(['ok' => true, 'last' => (string)setting($pdo, 'bk_last')]);
    }

    default: fail('Route inconnue.', 404);
}
