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
/* ---------- Lien WooCommerce (outil -> site) ---------- */
function wooCfg(PDO $pdo): array {
    $u = rtrim((string)(setting($pdo, 'woo_url') ?? ''), '/');
    return ['url' => $u, 'key' => (string)(setting($pdo, 'woo_key') ?? ''), 'secret' => (string)(setting($pdo, 'woo_secret') ?? '')];
}
function wooReq(array $c, string $method, string $path, ?array $data = null): array {
    if ($c['url'] === '' || $c['key'] === '' || $c['secret'] === '') { fail('Renseignez l\'adresse du site et les clés WooCommerce.'); }
    if (!function_exists('curl_init')) { fail('cURL est nécessaire sur l\'hébergement pour ce lien.', 500); }
    $sep = strpos($path, '?') === false ? '?' : '&';
    $url = $c['url'] . '/wp-json/wc/v3/' . $path . $sep . 'consumer_key=' . rawurlencode($c['key']) . '&consumer_secret=' . rawurlencode($c['secret']);
    $ch = curl_init($url);
    $h = ['User-Agent: watch-ci-gestion', 'Accept: application/json'];
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 25, CURLOPT_CUSTOMREQUEST => $method];
    if ($data !== null) { $o[CURLOPT_POSTFIELDS] = json_encode($data); $h[] = 'Content-Type: application/json'; }
    $o[CURLOPT_HTTPHEADER] = $h; curl_setopt_array($ch, $o);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($body === false) { fail('Site injoignable : ' . $err, 502); }
    $j = json_decode((string)$body, true);
    if ($code >= 400) {
        $m = is_array($j) && isset($j['message']) ? strip_tags((string)$j['message']) : ('code ' . $code);
        if ($code === 401 || $code === 403) { $m = 'Clés refusées par le site (' . $m . ')'; }
        fail('WooCommerce : ' . $m, 502);
    }
    return is_array($j) ? $j : [];
}
function wooNorm(string $s): string {
    $s = mb_strtolower($s);
    $s = strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','î'=>'i','ï'=>'i','ô'=>'o','ù'=>'u','û'=>'u','ç'=>'c']);
    $s = preg_replace('/\b(bracelet|apple|watch|pour)\b/', ' ', $s);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}
function wooNums(string $s): array { preg_match_all('/\d+/', $s, $m); return array_values(array_unique($m[0])); }
/* Produits du site (avec variations) */
function wooProducts(array $c, array $withVars = []): array {
    $all = [];
    for ($p = 1; $p <= 10; $p++) {
        $page = wooReq($c, 'GET', 'products?per_page=100&status=publish&_fields=id,name,type,stock_quantity,variations&page=' . $p);
        foreach ($page as $x) { $all[] = $x; }
        if (count($page) < 100) { break; }
    }
    $res = [];
    foreach ($all as $x) {
        $e = ['id' => (int)$x['id'], 'name' => html_entity_decode((string)($x['name'] ?? '')), 'type' => (string)($x['type'] ?? 'simple'), 'stock' => $x['stock_quantity'] ?? null, 'nv' => count((array)($x['variations'] ?? [])), 'vars' => []];
        if ($e['type'] === 'variable' && in_array($e['id'], $withVars, true)) {
            foreach (wooReq($c, 'GET', 'products/' . $e['id'] . '/variations?per_page=100') as $v) {
                $lab = [];
                foreach (($v['attributes'] ?? []) as $a) { $lab[] = (string)($a['option'] ?? ''); }
                $e['vars'][] = ['id' => (int)$v['id'], 'label' => implode(' ', $lab), 'stock' => $v['stock_quantity'] ?? null];
            }
        }
        $res[] = $e;
    }
    return $res;
}
function wooGroups(PDO $pdo): array {
    $m = [];
    foreach ($pdo->query('SELECT data FROM products') as $r) {
        $p = json_decode((string)$r['data'], true); if (!is_array($p)) { continue; }
        $g = (string)($p['g'] ?? ($p['id'] ?? '')); if ($g === '') { continue; }
        $sz = preg_replace('/\s+/', '', (string)($p['size'] ?? ''));
        if (!isset($m[$g])) { $m[$g] = ['g' => $g, 'name' => trim((string)($p['name'] ?? '')), 'color' => trim((string)($p['color'] ?? '')), 'price' => (float)($p['price'] ?? 0), 'cat' => trim((string)($p['category'] ?? '')), 'mat' => trim((string)($p['mat'] ?? '')), 'femme' => !empty($p['femme']), 'photo' => (string)($p['photo'] ?? ''), 'sizes' => []]; }
        if (!isset($m[$g]['sizes'][$sz])) { $m[$g]['sizes'][$sz] = (int)($p['stock'] ?? 0); }
    }
    return array_values($m);
}

/* Catégories WooCommerce : on retrouve l'arborescence existante du site */
function wooAllCats(array $c): array {
    $all = [];
    for ($p = 1; $p <= 5; $p++) {
        $page = wooReq($c, 'GET', 'products/categories?per_page=100&_fields=id,name,parent&page=' . $p);
        foreach ($page as $x) { $all[] = ['id' => (int)$x['id'], 'name' => html_entity_decode((string)$x['name']), 'parent' => (int)$x['parent']]; }
        if (count($page) < 100) { break; }
    }
    return $all;
}
function wooCatIds(PDO $pdo, array $g, array $cats, array $trashed): array {
    $ids = wooCatBase($pdo, $g, $cats, $trashed);
    $fid = 0;
    foreach ($cats as $k) { if (wooNorm($k['name']) === 'femme' || strpos(wooNorm($k['name']), 'bracelet femme') !== false || wooNorm($k['name']) === 'bracelet femme') { $fid = $k['id']; break; } }
    if (!$fid) { foreach ($cats as $k) { if ($k['id'] === 87) { $fid = 87; } } }
    if ($fid) { $ids = array_values(array_diff($ids, [$fid])); if (!empty($g['femme'])) { $ids[] = $fid; } }
    return array_values(array_unique($ids));
}

function wooTrashed(array $c): array {
    $out = [];
    for ($p = 1; $p <= 5; $p++) {
        $page = wooReq($c, 'GET', 'products?per_page=100&status=trash&_fields=id,name,categories&page=' . $p);
        foreach ($page as $x) { $out[] = ['name' => html_entity_decode((string)$x['name']), 'cats' => array_map(function ($k) { return (int)$k['id']; }, (array)($x['categories'] ?? []))]; }
        if (count($page) < 100) { break; }
    }
    return $out;
}
function wooCatBase(PDO $pdo, array $g, array $cats, array $trashed): array {
    $stored = setting($pdo, 'woocat:' . $g['g']);
    if ($stored !== null) { $j = json_decode($stored, true); if (is_array($j) && $j) { return array_map('intval', $j); } }
    $want = wooNorm($g['name'] . ' ' . $g['color']);
    /* 1) mêmes catégories que l'ancien produit du site (corbeille), sauf catégories créées par erreur */
    foreach ($trashed as $t) { if (wooNorm($t['name']) === $want && $t['cats']) { return $t['cats']; } }
    foreach ($trashed as $t) {
        $n = wooNorm($t['name']);
        if ($t['cats'] && $n !== '' && $want !== '' && (strpos($n, $want) !== false || strpos($want, $n) !== false)) { return $t['cats']; }
    }
    /* 2) à défaut : règles (taille + matière, ou accessoire) */
    $byName = function ($needle, $parent = null) use ($cats) {
        foreach ($cats as $k) {
            if ($parent !== null && $k['parent'] !== $parent) { continue; }
            if (strpos(wooNorm($k['name']), $needle) !== false) { return $k['id']; }
        }
        return 0;
    };
    $nm = wooNorm($g['name'] . ' ' . $g['color']); $ids = [];
    $isBr = mb_strtolower($g['cat']) === 'bracelet' || $g['cat'] === '';
    if ($isBr && strpos($nm, 'chargeur') === false) {
        $mat = wooNorm($g['mat'] ?? '');
        if ($mat === '') { foreach (['cuir', 'metal', 'nylon', 'silicone'] as $m) { if (strpos($nm, $m) !== false) { $mat = $m; break; } } }
        $small = false; $large = false;
        foreach (array_keys($g['sizes']) as $sz) { $n = wooNums((string)$sz); if (in_array('38', $n, true)) { $small = true; } else { $large = true; } }
        foreach ([[$small, '38'], [$large, '44']] as $pair) {
            if (!$pair[0]) { continue; }
            $par = $byName($pair[1] === '38' ? '38 40 41 42' : '42 44 45 46 49');
            if ($par) { $ids[] = $par; if ($mat !== '') { $ch = $byName($mat, $par); if ($ch) { $ids[] = $ch; } } }
        }
    } else {
        $t = strpos($nm, 'chargeur') !== false ? 'chargeur' : wooNorm($g['cat']);
        $id = $byName($t); if ($id) { $ids[] = $id; }
    }
    return array_values(array_unique($ids));
}

/* Crée un produit de l'outil sur le site (variable si plusieurs tailles) */
function wooCreate(PDO $pdo, array $c, array $g, array $cats, array $trashed): string {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api.php'))), '/');
    $name = $g['name'];
    if ($g['color'] !== '' && stripos(wooNorm($name), wooNorm($g['color'])) === false) { $name .= ' ' . $g['color']; }
    $data = ['name' => $name, 'status' => 'publish'];
    $cl = wooCatIds($pdo, $g, $cats, $trashed);
    if ($cl) { $data['categories'] = array_map(function ($i) { return ['id' => $i]; }, $cl); }
    if (preg_match('#^uploads/[a-f0-9]{24}\.jpg$#', $g['photo'])) { $data['images'] = [['src' => $proto . '://' . ($_SERVER['HTTP_HOST'] ?? '') . $dir . '/' . $g['photo']]]; }
    $price = wooPrice($g['price']);
    $sizes = $g['sizes']; $keys = array_keys($sizes);
    $single = count($keys) === 1 && $keys[0] === '';
    if ($single) {
        $data += ['type' => 'simple', 'regular_price' => $price, 'manage_stock' => true, 'stock_quantity' => max(0, (int)$sizes['']), 'stock_status' => 'instock'];
        $p = wooReq($c, 'POST', 'products', $data);
    } else {
        $lab = function ($k) { return $k === '' ? 'Unique' : $k . ' mm'; };
        $data += ['type' => 'variable', 'attributes' => [['name' => 'Taille', 'visible' => true, 'variation' => true, 'options' => array_map($lab, $keys)]]];
        $p = wooReq($c, 'POST', 'products', $data);
        $vars = [];
        foreach ($sizes as $k => $st) { $vars[] = ['regular_price' => $price, 'manage_stock' => true, 'stock_quantity' => max(0, (int)$st), 'attributes' => [['name' => 'Taille', 'option' => $lab($k)]]]; }
        wooReq($c, 'POST', 'products/' . (int)$p['id'] . '/variations/batch', ['create' => $vars]);
    }
    if (!empty($p['id'])) { setSetting($pdo, 'woomap:' . $g['g'], (string)(int)$p['id']); }
    return $name;
}

/* Met à jour un produit du site (stock, prix, catégories) à partir d'un produit de l'outil */
function wooUpdate(PDO $pdo, array $c, array $g, array $x, array $cats, array $trashed, array &$notes): int {
    $n = 0; $wid = (int)$x['id']; $price = wooPrice($g['price']);
    $ids = wooCatIds($pdo, $g, $cats, $trashed);
    $catPart = $ids ? ['categories' => array_map(function ($i) { return ['id' => $i]; }, $ids)] : [];
    $ph = wooPhoto($g); if ($ph) { $catPart['images'] = $ph; }
    if ($x['type'] === 'variable' && $x['vars']) {
        $lab = function ($k) { return $k === '' ? 'Unique' : $k . ' mm'; };
        $key = function (string $t) { $a = wooNums($t); sort($a); return implode('/', $a); };
        $used = []; $assign = [];
        foreach ($g['sizes'] as $sz => $st) {
            foreach ($x['vars'] as $v) { if (!isset($used[$v['id']]) && $key($v['label']) === $key((string)$sz) && $key($v['label']) !== '') { $used[$v['id']] = 1; $assign[(string)$sz] = $v['id']; break; } }
        }
        foreach ($g['sizes'] as $sz => $st) {
            if (isset($assign[(string)$sz])) { continue; }
            foreach ($x['vars'] as $v) { $vn = wooNums($v['label']); if (!isset($used[$v['id']]) && $vn && !array_diff($vn, wooNums((string)$sz))) { $used[$v['id']] = 1; $assign[(string)$sz] = $v['id']; break; } }
        }
        /* L'outil fait foi : les tailles absentes de l'outil sont retirées du site, les tailles manquantes sont ajoutées */
        $full = wooReq($c, 'GET', 'products/' . $wid . '?_fields=attributes');
        $attrs = (array)($full['attributes'] ?? []); $ai = -1;
        foreach ($attrs as $k => $a) { if (!empty($a['variation'])) { $ai = $k; break; } }
        $aName = $ai >= 0 ? (string)$attrs[$ai]['name'] : 'Taille'; $aId = $ai >= 0 ? (int)($attrs[$ai]['id'] ?? 0) : 0;
        $opts = array_map($lab, array_keys($g['sizes']));
        $newAttr = ['name' => $aName, 'visible' => true, 'variation' => true, 'options' => array_values($opts)] + ($aId ? ['id' => $aId] : []);
        if ($ai >= 0) { $attrs[$ai] = $newAttr; } else { $attrs[] = $newAttr; }
        $catPart['attributes'] = array_map(function ($a) { if (isset($a['options'])) { $a['options'] = array_values((array)$a['options']); } return $a; }, $attrs);
        wooReq($c, 'PUT', 'products/' . $wid, $catPart);
        $batch = ['update' => [], 'create' => [], 'delete' => []];
        foreach ($g['sizes'] as $sz => $st) {
            $data = ['manage_stock' => true, 'stock_quantity' => max(0, (int)$st), 'regular_price' => $price];
            if (isset($assign[(string)$sz])) { $batch['update'][] = ['id' => $assign[(string)$sz]] + $data; }
            else { $batch['create'][] = $data + ['attributes' => [($aId ? ['id' => $aId] : ['name' => $aName]) + ['option' => $lab((string)$sz)]]]; }
            $n++;
        }
        foreach ($x['vars'] as $v) { if (!isset($used[$v['id']])) { $batch['delete'][] = $v['id']; } }
        $batch = array_filter($batch);
        if ($batch) { wooReq($c, 'POST', 'products/' . $wid . '/variations/batch', $batch); }
    } else {
        wooReq($c, 'PUT', 'products/' . $wid, ['manage_stock' => true, 'stock_quantity' => max(0, (int)array_sum($g['sizes'])), 'regular_price' => $price] + $catPart);
        $n++;
    }
    return $n;
}
function wooSig(array $g): string { return md5(json_encode([$g['name'], $g['color'], $g['price'], $g['cat'], $g['mat'], $g['femme'], $g['photo'], $g['sizes']])); }

function wooPrice(float $p): string { $p = (int)round($p); if ($p === 3300) { $p = 5000; } elseif ($p === 7500) { $p = 10000; } return (string)$p; }
function wooPhoto(array $g): ?array {
    if (!preg_match('#^uploads/[a-f0-9]{24}\.jpg$#', (string)$g['photo'])) { return null; }
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api.php'))), '/');
    return [['src' => $proto . '://' . ($_SERVER['HTTP_HOST'] ?? '') . $dir . '/' . $g['photo']]];
}

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
if ($r === 'woo_hook') {
    $raw = (string)file_get_contents('php://input'); $sec = (string)(setting($pdo, 'woo_hook_secret') ?? '');
    $sig = (string)($_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? '');
    if ($sec === '' || $sig === '' || !hash_equals(base64_encode(hash_hmac('sha256', $raw, $sec, true)), $sig)) { fail('Accès refusé.', 403); }
    session_write_close(); ignore_user_abort(true); @set_time_limit(90);
    http_response_code(200); echo '{"ok":true}';
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); } else { header('Connection: close'); header('Content-Length: 11'); @ob_end_flush(); @flush(); }
    $c = wooCfg($pdo);
    if ($c['url'] !== '' && $c['key'] !== '' && $c['secret'] !== '') { wooImportOrders($pdo, $c); }
    exit;
}
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

/* ---------- Reçu PDF (sans bibliothèque) ---------- */
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
    $disc = (float)($o['disc'] ?? 0);
    $total = $sub - $disc + $ship;
    $logo = $biz['logo'] ?? null;
    $paid = !empty($o['paid']);
    $render = function (float $H) use ($o, $num, $biz, $items, $sub, $disc, $mine, $ship, $total, $logo, $paid): array {
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
        $T(547, 50, 'REÇU', 22, true, 'r', '1 1 1');
        $T(547, 70, 'N° ' . preg_replace('/^F-/', 'R-', $num), 11, false, 'r', '1 1 1');
        $T(547, 86, 'Date : ' . date('d/m/Y', strtotime((string)($o['date'] ?? 'now'))), 10, false, 'r', '1 1 1');
        $y0 = $hh + 36;
        $T(48, $y0, 'CLIENT', 9, true, 'l', '0.45 0.5 0.47');
        $T(48, $y0 + 19, pdfFit((string)($o['customer'] ?? 'Client'), true, 14, 360), 14, true);
        $y = $y0 + 33;
        if (!empty($o['phone'])) { $T(48, $y + 4, 'Tél. ' . $o['phone'], 10); $y += 17; }
        $R(447, $y0 - 10, 100, 28, $paid ? '0.84 0.94 0.88' : '0.99 0.9 0.86');
        $T(497, $y0 + 8, $paid ? 'PAYÉE' : 'À PAYER', 11, true, 'c', $paid ? '0.1 0.45 0.25' : '0.7 0.2 0.1');
        if (!empty($o['place'])) { $T(48, $y + 4, 'Livraison : ' . pdfFit((string)$o['place'], false, 10, 380), 10); $y += 17; }
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
        if ($disc > 0) { $T(455, $y, 'Remise lot', 10, false, 'r'); $T(537, $y, '- ' . money($disc), 10, false, 'r'); $y += 18; }
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
        $y += 16; $T(297, $y, 'Reçu de commande - document non fiscal, ne vaut pas facture', 8, false, 'c', '0.45 0.5 0.47');
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

function catPhotoJpeg(string $photo): ?array {
    if (!function_exists('imagecreatefromstring')) { return null; }
    $fs = photoFiles($photo); if (!$fs) { return null; }
    foreach ([$fs[1], $fs[0]] as $f) {
        if (!is_file($f)) { continue; }
        $im = @imagecreatefromstring((string)file_get_contents($f)); if (!$im) { continue; }
        $w = imagesx($im); $h = imagesy($im); $k = min(1, 420 / max($w, $h));
        $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
        $d = imagecreatetruecolor($nw, $nh); imagefill($d, 0, 0, imagecolorallocate($d, 255, 255, 255));
        imagecopyresampled($d, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start(); imagejpeg($d, null, 80); $bin = (string)ob_get_clean();
        imagedestroy($im); imagedestroy($d);
        return ['bin' => $bin, 'w' => $nw, 'h' => $nh];
    }
    return null;
}

/* Catalogue client : bracelets 5 000 F / 10 000 F + coques + antichocs en stock pour une taille de montre */
function catClientSizes(): array {
    return ['38' => ['38', 'A'], '40' => ['40', 'A'], '41' => ['41', 'A'], '42' => ['42', 'A'], '42s10' => ['42', 'B'], '44' => ['44', 'B'], '45' => ['45', 'B'], '46' => ['46', 'B'], '49' => ['49', 'B']];
}
function catClientSections(PDO $pdo, string $n, string $grp): array {
    $br5 = []; $br10 = []; $co = []; $ac = [];
    foreach (load($pdo, 'products') as $p) {
        if ((int)($p['stock'] ?? 0) <= 0) { continue; }
        $cat = (string)($p['category'] ?? ''); $cat = strpos($cat, '|') !== false ? explode('|', $cat)[0] : $cat;
        $szRaw = preg_replace('/\s+/', '', (string)($p['size'] ?? '')); $nums = wooNums($szRaw);
        $price = (int)wooPrice((float)($p['price'] ?? 0));
        $g = (string)($p['g'] ?? $p['id']);
        $m = ['name' => (string)($p['name'] ?? ''), 'color' => (string)($p['color'] ?? ''), 'photo' => (string)($p['photo'] ?? ''), 'price' => $price];
        if ($cat === 'Bracelet' || $cat === 'Bracelet femme') {
            if (count($nums) > 1) { $ok = $grp === 'A' ? in_array('38', $nums, true) : in_array('44', $nums, true); }
            else { $ok = in_array($n, $nums, true); }
            if (!$ok) { continue; }
            if ($price >= 10000) { $br10[$g] = $m; } else { $br5[$g] = $m; }
        } elseif ($cat === 'Coque' || $cat === 'Antichoc') {
            if (!($szRaw === '' || in_array($n, $nums, true))) { continue; }
            if ($cat === 'Coque') { $co[$g] = $m; } else { $ac[$g] = $m; }
        }
    }
    $sort = function (array $a) { $a = array_values($a); usort($a, function ($x, $y) { return strcasecmp($x['name'] . $x['color'], $y['name'] . $y['color']); }); return $a; };
    return [
        ['title' => 'Bracelets 5 000 F', 'foot' => '5 000 F l\'unité  |  3 bracelets pour 10 000 FCFA', 'models' => $sort($br5), 'price' => false],
        ['title' => 'Bracelets 10 000 F', 'foot' => '10 000 FCFA l\'unité  |  2 bracelets pour 15 000 FCFA', 'models' => $sort($br10), 'price' => false],
        ['title' => 'Coques', 'foot' => 'Coques de protection', 'models' => $sort($co), 'price' => true],
        ['title' => 'Antichocs', 'foot' => 'Protections antichoc', 'models' => $sort($ac), 'price' => true],
    ];
}
function logoAlpha(): ?array {
    if (!function_exists('imagecreatefrompng') || !is_file(__DIR__ . '/logo.png')) { return null; }
    $im = @imagecreatefrompng(__DIR__ . '/logo.png'); if (!$im) { return null; }
    $w0 = imagesx($im); $h0 = imagesy($im); $k = min(1, 520 / max($w0, $h0));
    $w = max(1, (int)round($w0 * $k)); $h = max(1, (int)round($h0 * $k));
    $d = imagecreatetruecolor($w, $h); imagealphablending($d, false); imagesavealpha($d, true);
    imagefill($d, 0, 0, imagecolorallocatealpha($d, 0, 0, 0, 127));
    imagecopyresampled($d, $im, 0, 0, 0, 0, $w, $h, $w0, $h0);
    $rgb = ''; $a = '';
    for ($y = 0; $y < $h; $y++) { for ($x = 0; $x < $w; $x++) { $c = imagecolorat($d, $x, $y); $rgb .= chr(($c >> 16) & 255) . chr(($c >> 8) & 255) . chr($c & 255); $a .= chr(255 - (int)round((($c >> 24) & 127) * 255 / 127)); } }
    imagedestroy($im); imagedestroy($d);
    return ['bin' => gzcompress($rgb, 6), 'mask' => gzcompress($a, 6), 'w' => $w, 'h' => $h, 'flate' => true];
}
function buildCatalogueClient(array $sections, string $watch, ?array $logo): string {
    $W = 595; $H = 842; $org = '0.87 0.39 0.06'; $dark = '0.09 0.11 0.1';
    $pages = [];
    $mk = function () use (&$pages) { $o = new stdClass(); $o->c = ''; $pages[] = $o; return $o; };
    $T = function (stdClass $pg, float $x, float $y, string $t, float $sz = 10, bool $b = false, string $al = 'l', string $col = '0.1 0.13 0.11') use ($H) {
        if ($al === 'r') { $x -= pdfW($t, $b, $sz); } elseif ($al === 'c') { $x -= pdfW($t, $b, $sz) / 2; }
        $pg->c .= "BT /" . ($b ? 'F2' : 'F1') . " $sz Tf $col rg 1 0 0 1 " . round($x, 2) . ' ' . round($H - $y, 2) . ' Tm (' . pdfStr($t) . ") Tj ET\n";
    };
    $R = function (stdClass $pg, float $x, float $y, float $w, float $h, string $col) use ($H) { $pg->c .= "$col rg " . round($x, 2) . ' ' . round($H - $y - $h, 2) . " $w $h re f\n"; };
    $I = function (stdClass $pg, string $name, array $im, float $x, float $y, float $bw, float $bh) use ($H) {
        $k = min($bw / $im['w'], $bh / $im['h']); $dw = round($im['w'] * $k, 2); $dh = round($im['h'] * $k, 2);
        $pg->c .= "q $dw 0 0 $dh " . round($x + ($bw - $dw) / 2, 2) . ' ' . round($H - $y - $bh + ($bh - $dh) / 2, 2) . " cm /$name Do Q\n";
    };
    $total = 0; foreach ($sections as $s) { $total += count($s['models']); }
    $p = $mk(); $R($p, 0, 0, $W, $H, $dark);
    if ($logo) { $I($p, 'Lg', $logo, 148, 44, 300, 300); }
    $T($p, 297.5, 388, 'Watch Côte d\'Ivoire', 14, true, 'c', $org);
    $T($p, 297.5, 446, 'Catalogue', 48, true, 'c', '1 1 1');
    $T($p, 297.5, 480, 'Pour Apple Watch ' . $watch, 20, false, 'c', '1 1 1');
    $nb = count(array_filter($sections, function ($s) { return $s['models']; }));
    $R($p, 0, 520, $W, 32 + 24 * $nb, $org);
    $yy = 552; foreach ($sections as $s) { if (!$s['models']) { continue; } $T($p, 297.5, $yy, $s['title'] . '  ·  ' . count($s['models']) . ' modèle' . (count($s['models']) > 1 ? 's' : ''), 14, true, 'c', '1 1 1'); $yy += 24; }
    $T($p, 297.5, 728, 'Seuls les articles en stock sont présentés · mis à jour le ' . date('d/m/Y'), 10, false, 'c', '0.75 0.78 0.76');
    $T($p, 297.5, 768, 'WhatsApp 01 02 99 09 66', 14, true, 'c', '1 1 1');
    $T($p, 297.5, 790, 'www.watchcotedivoire.com', 11, false, 'c', '0.75 0.78 0.76');
    $cw = 247; $ch = 339; $photoN = 0; $objImgs = [];
    foreach ($sections as $s) {
        $n = 0; $pg = null;
        foreach ($s['models'] as $m) {
            if ($n % 4 === 0) {
                $pg = $mk();
                $R($pg, 0, 0, $W, 64, $org);
                $T($pg, 40, 40, $s['title'], 20, true, 'l', '1 1 1'); $T($pg, 555, 40, 'Apple Watch ' . $watch, 11, false, 'r', '1 1 1');
                $R($pg, 0, 792, $W, 50, $dark);
                $T($pg, 40, 813, $s['foot'], 10, true, 'l', '1 1 1'); $T($pg, 555, 813, 'WhatsApp 01 02 99 09 66', 10, true, 'r', $org);
                $T($pg, 40, 830, 'www.watchcotedivoire.com', 8, false, 'l', '0.75 0.78 0.76');
            }
            $i = $n % 4; $x = 40 + ($i % 2) * ($cw + 1); $y = 80 + intdiv($i, 2) * ($ch + 14);
            $R($pg, $x, $y, $cw, $ch, '0.95 0.96 0.95'); $R($pg, $x, $y, $cw, 247, '1 1 1');
            $ph = $m['photo'] !== '' ? catPhotoJpeg($m['photo']) : null;
            if ($ph) { $nm = 'P' . (++$photoN); $objImgs[$nm] = $ph; $I($pg, $nm, $ph, $x + 4, $y + 4, $cw - 8, 239); $pg->c .= ''; $pg->im = ($pg->im ?? []) + [$nm => 1]; }
            else { $T($pg, $x + $cw / 2, $y + 126, 'Photo à venir', 10, false, 'c', '0.6 0.63 0.61'); }
            $R($pg, $x, $y, 30, 24, $org); $T($pg, $x + 15, $y + 17, (string)($n + 1), 11, true, 'c', '1 1 1');
            $T($pg, $x + 12, $y + 272, pdfFit($m['name'], true, 12, $cw - 24), 12, true);
            if ($m['color'] !== '') { $T($pg, $x + 12, $y + 291, pdfFit($m['color'], false, 10, $cw - 24), 10, false, 'l', '0.4 0.45 0.42'); }
            if ($s['price']) { $T($pg, $x + 12, $y + 318, number_format($m['price'], 0, ',', ' ') . ' F', 13, true, 'l', $org); }
            else { $T($pg, $x + 12, $y + 318, 'Disponible', 10, true, 'l', $org); }
            $n++;
        }
    }
    $objs = [1 => '', 2 => '', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>', 4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
    $num = 5; $xo = [];
    $addImg = function (array $im) use (&$objs, &$num): int {
        $sm = '';
        if (!empty($im['mask'])) { $mk2 = $num++; $objs[$mk2] = "<< /Type /XObject /Subtype /Image /Width {$im['w']} /Height {$im['h']} /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length " . strlen($im['mask']) . " >>\nstream\n" . $im['mask'] . "\nendstream"; $sm = " /SMask $mk2 0 R"; }
        $k = $num++; $flt = !empty($im['flate']) ? 'FlateDecode' : 'DCTDecode';
        $objs[$k] = "<< /Type /XObject /Subtype /Image /Width {$im['w']} /Height {$im['h']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /$flt$sm /Length " . strlen($im['bin']) . " >>\nstream\n" . $im['bin'] . "\nendstream";
        return $k;
    };
    if ($logo) { $xo['Lg'] = $addImg($logo); }
    foreach ($objImgs as $nm => $im) { $xo[$nm] = $addImg($im); }
    $xs = ''; foreach ($xo as $nm => $k) { $xs .= "/$nm $k 0 R "; }
    $kids = [];
    foreach ($pages as $pg) {
        $cn = $num++; $pn = $num++;
        $objs[$cn] = "<< /Length " . strlen($pg->c) . " >>\nstream\n" . $pg->c . "endstream";
        $objs[$pn] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $W $H] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << $xs>> >> /Contents $cn 0 R >>";
        $kids[] = "$pn 0 R";
    }
    $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>'; $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objs);
    $out = "%PDF-1.4\n"; $off = [];
    foreach ($objs as $k => $v) { $off[$k] = strlen($out); $out .= "$k 0 obj\n$v\nendobj\n"; }
    $xr = strlen($out); $mx = max(array_keys($objs)) + 1;
    $out .= "xref\n0 $mx\n0000000000 65535 f \n";
    for ($k = 1; $k < $mx; $k++) { $out .= sprintf("%010d 00000 n \n", $off[$k]); }
    return $out . "trailer\n<< /Size $mx /Root 1 0 R >>\nstartxref\n$xr\n%%EOF";
}

/* ---------- Notifications push (Web Push, sans bibliothèque) ---------- */
function b64u(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function b64ud(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
function pushKeys(PDO $pdo): array {
    $pem = setting($pdo, 'vapid_priv'); $pub = setting($pdo, 'vapid_pub');
    if ($pem && $pub) { return [$pem, $pub]; }
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$k) { fail('OpenSSL ne peut pas créer les clés de notification.', 500); }
    openssl_pkey_export($k, $pem); $d = openssl_pkey_get_details($k)['ec'];
    $pub = b64u("\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT));
    setSetting($pdo, 'vapid_priv', $pem); setSetting($pdo, 'vapid_pub', $pub);
    return [$pem, $pub];
}
function derToRaw(string $der): string {
    $o = 2; if (ord($der[1]) & 0x80) { $o += ord($der[1]) & 0x7f; }
    $rl = ord($der[$o + 1]); $r = substr($der, $o + 2, $rl); $o += 2 + $rl;
    $sl = ord($der[$o + 1]); $s = substr($der, $o + 2, $sl);
    return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
}
function pushEncrypt(string $payload, string $uaPub, string $auth): ?array {
    $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]); if (!$eph) { return null; }
    $d = openssl_pkey_get_details($eph)['ec'];
    $asPub = "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $uaPub;
    $peer = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $secret = openssl_pkey_derive($peer, $eph, 32); if ($secret === false) { return null; }
    $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPub . $asPub, $auth);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) { return null; }
    return ['body' => $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag];
}
function pushSendOne(PDO $pdo, array $sub, string $json): int {
    [$pem, $pub] = pushKeys($pdo);
    $enc = pushEncrypt($json, b64ud((string)$sub['p256dh']), b64ud((string)$sub['auth'])); if (!$enc) { return 0; }
    $u = parse_url((string)$sub['endpoint']); if (empty($u['scheme']) || empty($u['host'])) { return 0; }
    $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $in = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . b64u(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => 'https://www.watchcotedivoire.com']));
    if (!openssl_sign($in, $der, $pem, OPENSSL_ALGO_SHA256)) { return 0; }
    $jwt = $in . '.' . b64u(derToRaw($der));
    $ch = curl_init((string)$sub['endpoint']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $enc['body'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream', 'TTL: 86400', 'Urgency: high', 'Authorization: vapid t=' . $jwt . ', k=' . $pub]]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code;
}
function pushSend(PDO $pdo, string $title, string $body, string $tag = ''): int {
    if (!function_exists('curl_init') || !function_exists('openssl_pkey_derive')) { return 0; }
    $ok = 0; $json = json_encode(['title' => $title, 'body' => $body, 'url' => './', 'tag' => $tag], JSON_UNESCAPED_UNICODE);
    foreach ($pdo->query("SELECT k,v FROM settings WHERE k LIKE 'pushsub:%'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sub = json_decode((string)$row['v'], true); if (!is_array($sub)) { continue; }
        $code = pushSendOne($pdo, $sub, $json);
        if ($code >= 200 && $code < 300) { $ok++; } elseif ($code === 404 || $code === 410) { $pdo->prepare('DELETE FROM settings WHERE k=?')->execute([$row['k']]); }
    }
    return $ok;
}
function pushOrderText(array $o): string {
    $n = 0; $tot = 0.0; foreach ((array)($o['items'] ?? []) as $i) { $n += (int)($i['qty'] ?? 0); $tot += (int)($i['qty'] ?? 0) * (float)($i['price'] ?? 0); }
    $tot -= (float)($o['disc'] ?? 0);
    return trim((string)($o['customer'] ?? 'Client')) . ' · ' . $n . ' article' . ($n > 1 ? 's' : '') . ' · ' . number_format($tot, 0, ',', ' ') . ' F';
}
function wooEnsureHooks(PDO $pdo, array $c): array {
    if (setting($pdo, 'woo_hooks') === '1') { return ['ok' => true, 'already' => true]; }
    $sec = setting($pdo, 'woo_hook_secret'); if (!$sec) { $sec = bin2hex(random_bytes(16)); setSetting($pdo, 'woo_hook_secret', $sec); }
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api.php'))), '/');
    $url = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? '') . $dir . '/api.php?r=woo_hook';
    foreach (wooReq($c, 'GET', 'webhooks?per_page=100&status=all') as $h) { if (strpos((string)($h['delivery_url'] ?? ''), 'r=woo_hook') !== false) { wooReq($c, 'DELETE', 'webhooks/' . (int)$h['id'] . '?force=true'); } }
    foreach (['order.created' => 'Watch CI : nouvelle commande', 'order.updated' => 'Watch CI : commande modifiée'] as $topic => $name) {
        wooReq($c, 'POST', 'webhooks', ['name' => $name, 'topic' => $topic, 'delivery_url' => $url, 'secret' => $sec, 'status' => 'active']);
    }
    setSetting($pdo, 'woo_hooks', '1');
    return ['ok' => true];
}

/* ---------- SMS aux clients (BulkGate, API simple transactionnelle) ---------- */
function smsAscii(string $t): string {
    $x = strtr($t, ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','ä'=>'a','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','É'=>'E','È'=>'E','À'=>'A','Ç'=>'C','’'=>"'",'‘'=>"'",'«'=>'"','»'=>'"','…'=>'...','–'=>'-','—'=>'-',"\u{00A0}"=>' ',"\u{202F}"=>' ']);
    return preg_replace('/[^\x20-\x7E\n]/', '', $x) ?? $x;
}
function smsNumber(string $raw): string {
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (strncmp($d, '00', 2) === 0) { $d = substr($d, 2); }
    if (strlen($d) === 10) { $d = '225' . $d; }
    elseif (strlen($d) === 8) { $d = '225' . $d; }
    return (strlen($d) >= 11 && strlen($d) <= 15) ? $d : '';
}
function smsSend(PDO $pdo, string $number, string $text): array {
    $id = (string)(setting($pdo, 'sms_app') ?? ''); $tk = (string)(setting($pdo, 'sms_token') ?? '');
    if ($id === '' || $tk === '') { return [false, 'Identifiants BulkGate manquants.']; }
    $num = smsNumber($number); if ($num === '') { return [false, 'Numéro de téléphone invalide.']; }
    $sender = trim((string)(setting($pdo, 'sms_sender') ?? ''));
    $data = ['application_id' => $id, 'application_token' => $tk, 'number' => $num, 'text' => smsAscii($text), 'unicode' => false, 'tag' => 'watch-ci'];
    $mode = (string)(setting($pdo, 'sms_mode') ?? 'gSystem');
    if ($mode === 'gText' && $sender !== '') { $data['sender_id'] = 'gText'; $data['sender_id_value'] = mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $sender) ?? '', 0, 11); }
    elseif ($mode === 'gShort') { $data['sender_id'] = 'gShort'; }
    else { $data['sender_id'] = 'gSystem'; }
    $ch = curl_init('https://portal.bulkgate.com/api/1.0/simple/transactional');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Cache-Control: no-cache']]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($body === false) { return [false, 'BulkGate injoignable : ' . $err]; }
    $j = json_decode((string)$body, true);
    if (is_array($j) && isset($j['data']['status']) && $j['data']['status'] === 'accepted') { return [true, 'Accepté par BulkGate (' . $data['sender_id'] . ', n° ' . $num . ')']; }
    $m = is_array($j) ? (string)($j['error'] ?? ($j['data']['status'] ?? '')) : '';
    if ($m === '') { $m = 'code ' . $code . ' ' . mb_substr(trim(strip_tags((string)$body)), 0, 120); }
    return [false, 'BulkGate : ' . $m];
}
function smsOrderText(PDO $pdo, array $o): string {
    $tpl = (string)(setting($pdo, 'sms_tpl') ?? ''); if (trim($tpl) === '') { $tpl = "Bonjour {prenom}, votre commande Watch Cote d'Ivoire ({articles}, {total}) est bien recue. Nous vous contactons pour la livraison. Merci !"; }
    $n = 0; $tot = 0.0; foreach ((array)($o['items'] ?? []) as $i) { $n += (int)($i['qty'] ?? 0); $tot += (int)($i['qty'] ?? 0) * (float)($i['price'] ?? 0); }
    $tot -= (float)($o['disc'] ?? 0);
    $name = trim((string)($o['customer'] ?? '')); $first = $name !== '' ? preg_split('/\s+/', $name)[0] : '';
    return strtr($tpl, ['{prenom}' => $first !== '' ? $first : 'client', '{nom}' => $name, '{articles}' => $n . ' article' . ($n > 1 ? 's' : ''), '{total}' => number_format($tot, 0, ',', ' ') . ' F']);
}
function smsOrder(PDO $pdo, array $o, bool $fromSite): void {
    if (setting($pdo, 'sms_on') !== '1') { return; }
    if (!$fromSite && setting($pdo, 'sms_tool') === '0') { return; }
    $ph = (string)($o['phone'] ?? ''); if (smsNumber($ph) === '') { return; }
    [$ok, $msg] = smsSend($pdo, $ph, smsOrderText($pdo, $o));
    setSetting($pdo, 'sms_last', date('d/m H:i') . ' · ' . ($ok ? 'OK' : 'Échec') . ' · ' . $msg);
}

/* Commandes du site -> outil : création, stock, annulation */
function wooImportOrders(PDO $pdo, array $c): array {
    $res = ['new' => 0, 'cancelled' => 0, 'notes' => []];
    $busy = (int)(setting($pdo, 'woo_ord_busy') ?? 0);
    if ($busy > time() - 90) { return $res + ['busy' => true]; }
    setSetting($pdo, 'woo_ord_busy', (string)time());
    $since = setting($pdo, 'woo_ord_since');
    if ($since === null) { $since = gmdate('Y-m-d\TH:i:s', time() - 3600); setSetting($pdo, 'woo_ord_since', $since); }
    $list = [];
    for ($pg = 1; $pg <= 3; $pg++) {
        $page = wooReq($c, 'GET', 'orders?per_page=100&orderby=date&order=desc&dates_are_gmt=true&after=' . rawurlencode((string)$since) . '&page=' . $pg);
        foreach ($page as $o) { $list[] = $o; }
        if (count($page) < 100) { break; }
    }
    usort($list, function ($a, $b) { return strcmp((string)$a['date_created_gmt'], (string)$b['date_created_gmt']); });
    /* index outil : produit du site -> groupe -> taille -> id de produit */
    $rev = []; foreach ($pdo->query("SELECT k,v FROM settings WHERE k LIKE 'woomap:%'") as $r) { $rev[(int)$r['v']] = substr((string)$r['k'], 7); }
    $bySize = []; $costOf = [];
    foreach (load($pdo, 'products') as $p) { $g = (string)($p['g'] ?? $p['id']); $k = preg_replace('/\s+/', '', (string)($p['size'] ?? '')); $bySize[$g][$k] = $p['id']; $costOf[$p['id']] = (float)($p['cost'] ?? 0); }
    $key = function (string $t) { $a = wooNums($t); sort($a); return implode('/', $a); };
    $adjust = function (array $d) use ($pdo) {
        $sel = $pdo->prepare('SELECT data FROM products WHERE id=?'); $upd = $pdo->prepare('UPDATE products SET data=?, updated=? WHERE id=?');
        foreach ($d as $pid => $delta) {
            $sel->execute([$pid]); $raw = $sel->fetchColumn(); if ($raw === false || !$delta) { continue; }
            $x = json_decode((string)$raw, true); if (!is_array($x)) { continue; }
            $x['stock'] = (int)($x['stock'] ?? 0) + (int)$delta; $upd->execute([json_encode($x, JSON_UNESCAPED_UNICODE), time(), $pid]);
        }
    };
    foreach ($list as $o) {
        $wid = (int)$o['id']; $st = (string)($o['status'] ?? '');
        $mapKey = 'woord:' . $wid; $had = setting($pdo, $mapKey);
        $dead = in_array($st, ['cancelled', 'refunded', 'failed', 'trash'], true);
        if ($had !== null) {
            $oid = $had;
            $q = $pdo->prepare('SELECT data FROM orders WHERE id=?'); $q->execute([$oid]); $raw = $q->fetchColumn();
            if ($raw === false) { continue; }
            $od = json_decode((string)$raw, true); if (!is_array($od)) { continue; }
            if ($dead && ($od['status'] ?? '') !== 'annule') {
                $back = []; foreach (($od['items'] ?? []) as $it) { if (!empty($it['pid'])) { $back[$it['pid']] = ($back[$it['pid']] ?? 0) + (int)$it['qty']; } }
                $adjust($back); $od['status'] = 'annule';
                $pdo->prepare('UPDATE orders SET data=?, updated=? WHERE id=?')->execute([json_encode($od, JSON_UNESCAPED_UNICODE), time(), $oid]); $res['cancelled']++;
            } elseif (!$dead && $st === 'completed' && ($od['status'] ?? '') === 'a_livrer') {
                $od['status'] = 'livre'; $pdo->prepare('UPDATE orders SET data=?, updated=? WHERE id=?')->execute([json_encode($od, JSON_UNESCAPED_UNICODE), time(), $oid]);
            }
            continue;
        }
        if ($dead || !in_array($st, ['processing', 'completed', 'on-hold'], true)) { continue; }
        $items = []; $dec = [];
        foreach ((array)($o['line_items'] ?? []) as $li) {
            $qty = max(1, (int)($li['quantity'] ?? 1)); $price = (int)round((float)($li['subtotal'] ?? 0) / $qty);
            $g = $rev[(int)($li['product_id'] ?? 0)] ?? null; $pid = '';
            $opt = ''; foreach ((array)($li['meta_data'] ?? []) as $m) { if (is_string($m['display_value'] ?? null) && preg_match('/\d/', (string)$m['display_value'])) { $opt = (string)$m['display_value']; break; } }
            if ($g !== null && isset($bySize[$g])) {
                if ($opt === '' && count($bySize[$g]) === 1) { $pid = (string)reset($bySize[$g]); }
                foreach ($bySize[$g] as $sz => $id) { if ($opt !== '' && $key($opt) !== '' && $key($opt) === $key((string)$sz)) { $pid = (string)$id; break; } }
                if ($pid === '' && $opt !== '') { foreach ($bySize[$g] as $sz => $id) { $vn = wooNums($opt); if ($vn && !array_diff($vn, wooNums((string)$sz))) { $pid = (string)$id; break; } } }
            }
            if ($pid === '') { $res['notes'][] = 'Commande #' . ($o['number'] ?? $wid) . ' : « ' . ($li['name'] ?? '') . ' » non lié à un produit de l\'outil (stock non modifié)'; }
            $items[] = ['pid' => $pid, 'label' => html_entity_decode((string)($li['name'] ?? 'Article')), 'qty' => $qty, 'price' => $price, 'cost' => $pid !== '' ? ($costOf[$pid] ?? 0) : 0];
            if ($pid !== '') { $dec[$pid] = ($dec[$pid] ?? 0) - $qty; }
        }
        if (!$items) { continue; }
        $disc = 0.0; foreach ((array)($o['fee_lines'] ?? []) as $f) { $t = (float)($f['total'] ?? 0); if ($t < 0) { $disc += -$t; } }
        $b = (array)($o['billing'] ?? []); $sh = (array)($o['shipping'] ?? []);
        $name = trim(((string)($b['first_name'] ?? '')) . ' ' . ((string)($b['last_name'] ?? ''))); if ($name === '') { $name = trim(((string)($sh['first_name'] ?? '')) . ' ' . ((string)($sh['last_name'] ?? ''))); }
        $place = trim(implode(', ', array_filter([(string)($sh['address_1'] ?? $b['address_1'] ?? ''), (string)($sh['city'] ?? $b['city'] ?? '')])));
        $pm = (string)($o['payment_method'] ?? ''); $pt = (string)($o['payment_method_title'] ?? '');
        $paid = $st === 'completed' || ($st === 'processing' && $pm !== 'cod');
        $mm = $paid && (bool)preg_match('/mobile|money|wave|orange|mtn|moov|momo/i', $pm . ' ' . $pt);
        $ship = (float)($o['shipping_total'] ?? 0);
        $doc = ['date' => substr((string)($o['date_created'] ?? date('Y-m-d')), 0, 10), 'customer' => $name !== '' ? $name : 'Client du site', 'phone' => (string)($b['phone'] ?? ''), 'place' => $place,
            'channel' => 'Site web', 'items' => $items, 'disc' => $disc, 'dcost' => $ship, 'fee' => 0, 'paid' => $paid, 'mm' => $mm,
            'note' => 'Commande du site n°' . ($o['number'] ?? $wid) . ($pt !== '' ? ' · ' . $pt : ''), 'status' => $st === 'completed' ? 'livre' : 'a_livrer',
            'createdAt' => (string)($o['date_created_gmt'] ?? gmdate('Y-m-d\TH:i:s')) . 'Z', 'woo' => $wid];
        $oid = 'o' . base_convert((string)(time() * 1000 + $res['new']), 10, 36) . substr(bin2hex(random_bytes(2)), 0, 3);
        $pdo->prepare('INSERT INTO orders(id,data,updated) VALUES(?,?,?)')->execute([$oid, json_encode($doc, JSON_UNESCAPED_UNICODE), time()]);
        $adjust($dec);
        $res['lines'][] = pushOrderText($doc); $res['docs'][] = $doc;
        setSetting($pdo, $mapKey, $oid);
        $res['new']++;
    }
    setSetting($pdo, 'woo_ord_busy', '0');
    foreach (($res['lines'] ?? []) as $ln) { try { pushSend($pdo, 'Nouvelle commande du site', $ln); } catch (Throwable $e) { } }
    foreach (($res['docs'] ?? []) as $dd) { try { smsOrder($pdo, $dd, true); } catch (Throwable $e) { } }
    unset($res['lines'], $res['docs']);
    return $res;
}

switch ($r) {
    case 'state':
        $bkTo = (string)(setting($pdo, 'bk_email') ?? '');
        $doBk = $bkTo !== '' && substr((string)setting($pdo, 'bk_last'), 0, 10) !== date('Y-m-d') && (int)setting($pdo, 'bk_try') !== (int)date('Ymd') * 100 + (int)date('G');
        $payload = json_encode(['products' => load($pdo, 'products'), 'orders' => load($pdo, 'orders'), 'expenses' => load($pdo, 'expenses')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!$doBk) { http_response_code(200); echo $payload; exit; }
        /* La sauvegarde du jour part APRÈS l'envoi des données, pour ne jamais bloquer l'ouverture */
        setSetting($pdo, 'bk_try', (string)((int)date('Ymd') * 100 + (int)date('G')));
        session_write_close();
        ignore_user_abort(true); @set_time_limit(180);
        header('Connection: close');
        echo $payload;
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @flush();
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        try { bkSend($pdo, $bkTo); } catch (Throwable $e) { }
        exit;

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
        $isNew = false;
        if ($c === 'orders') { $q0 = $pdo->prepare('SELECT 1 FROM orders WHERE id=?'); $q0->execute([$id]); $isNew = $q0->fetchColumn() === false; }
        $json = json_encode($d, JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 200000) { fail('Document trop volumineux.'); }
        $pdo->prepare("INSERT INTO $c(id,data,updated) VALUES(?,?,?) ON CONFLICT(id) DO UPDATE SET data=excluded.data, updated=excluded.updated")->execute([$id, $json, time()]);
        if ($isNew) { try { pushSend($pdo, 'Nouvelle commande · ' . (string)($d['channel'] ?? 'Outil'), pushOrderText($d)); } catch (Throwable $e) { } try { smsOrder($pdo, $d, false); } catch (Throwable $e) { } }
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


    case 'cat_client': {
        if (!$isPost) { fail('POST requis.', 405); }
        $k = (string)(body()['k'] ?? ''); $defs = catClientSizes();
        if (!isset($defs[$k])) { fail('Taille inconnue.'); }
        @ini_set('memory_limit', '512M'); @set_time_limit(120);
        [$n, $grp] = $defs[$k];
        $secs = catClientSections($pdo, $n, $grp); $cnt = 0; foreach ($secs as $s) { $cnt += count($s['models']); }
        if ($cnt === 0) { fail('Aucun article en stock pour cette taille.'); }
        $lg = logoAlpha() ?: logoJpeg();
        $pdf = buildCatalogueClient($secs, $k === '42s10' ? '42 mm (série 10)' : $n . ' mm', $lg);
        $dir = $upDir . '/catalogues-client'; if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        if (file_put_contents($dir . '/' . $k . '.pdf', $pdf) === false) { fail('Écriture impossible dans le dossier uploads.', 500); }
        out(['ok' => true, 'file' => $k . '.pdf', 'n' => $cnt, 'v' => time()]);
    }

    case 'catalogues': {
        $dir = $upDir . '/catalogues';
        foreach (glob($dir . '/stock-*.pdf') ?: [] as $sf) { @unlink($sf); }
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
        header('Content-Disposition: inline; filename="Recu-' . $o['inv'] . '.pdf"'); header('Content-Length: ' . strlen($pdf));
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

    case 'woo_get': {
        $c = wooCfg($pdo);
        out(['url' => $c['url'], 'hasKeys' => $c['key'] !== '' && $c['secret'] !== '', 'hint' => $c['key'] !== '' ? 'ck_••••' . substr($c['key'], -4) : '', 'last' => (string)(setting($pdo, 'woo_last') ?? '')]);
    }

    case 'woo_set': {
        if (!$isPost) { fail('POST requis.', 405); }
        $b = body();
        $u = rtrim(trim((string)($b['url'] ?? '')), '/');
        if ($u !== '' && !preg_match('#^https://[^\s/]+#i', $u)) { fail('L\'adresse du site doit commencer par https://'); }
        setSetting($pdo, 'woo_url', $u);
        $k = trim((string)($b['key'] ?? '')); $sec = trim((string)($b['secret'] ?? ''));
        if ($k !== '') { setSetting($pdo, 'woo_key', $k); }
        if ($sec !== '') { setSetting($pdo, 'woo_secret', $sec); }
        out(['ok' => true]);
    }

    case 'woo_plan': {
        session_write_close();
        @set_time_limit(120);
        $c = wooCfg($pdo);
        $site = wooProducts($c);
        $groups = wooGroups($pdo);
        $map = [];
        foreach ($groups as $g) { $v = setting($pdo, 'woomap:' . $g['g']); if ($v !== null) { $map[$g['g']] = (int)$v; } }
        $rows = [];
        foreach ($groups as $g) {
            $gid = $g['g']; $want = wooNorm($g['name'] . ' ' . $g['color']); $match = $map[$gid] ?? 0;
            if (!$match) {
                foreach ($site as $x) { if (wooNorm($x['name']) === $want) { $match = $x['id']; break; } }
            }
            if (!$match) {
                foreach ($site as $x) { $n = wooNorm($x['name']); if ($n !== '' && $want !== '' && (strpos($n, $want) !== false || strpos($want, $n) !== false)) { $match = $x['id']; break; } }
            }
            $rows[] = ['g' => $gid, 'name' => trim($g['name'] . ($g['color'] !== '' ? ' · ' . $g['color'] : '')), 'sizes' => $g['sizes'], 'match' => $match];
        }
        $list = array_map(function ($x) { return ['id' => $x['id'], 'name' => $x['name'], 'type' => $x['type'], 'nv' => $x['nv']]; }, $site);
        out(['rows' => $rows, 'site' => $list]);
    }

    case 'woo_replace': {
        session_write_close();
        if (!$isPost) { fail('POST requis.', 405); }
        @set_time_limit(120);
        $c = wooCfg($pdo); $b = body(); $step = (int)($b['step'] ?? 0);
        $groups = wooGroups($pdo);
        if ($step === 0) {
            $ids = [];
            for ($i = 0; $i < 20; $i++) {
                $page = wooReq($c, 'GET', 'products?per_page=100&status=any&_fields=id&page=1');
                $batch = array_map(function ($x) { return (int)$x['id']; }, $page);
                if (!$batch) { break; }
                wooReq($c, 'POST', 'products/batch', ['delete' => $batch]);
                $ids = array_merge($ids, $batch);
            }
            foreach ($pdo->query("SELECT k FROM settings WHERE k LIKE 'woomap:%'")->fetchAll(PDO::FETCH_COLUMN) as $k) { $pdo->prepare('DELETE FROM settings WHERE k=?')->execute([$k]); }
            out(['trashed' => count($ids), 'groups' => array_map(function ($g) { return $g['g']; }, $groups)]);
        }
        $g = null; foreach ($groups as $x) { if ($x['g'] === (string)($b['g'] ?? '')) { $g = $x; break; } }
        if (!$g) { fail('Produit introuvable dans l\'outil.'); }
        $name = wooCreate($pdo, $c, $g, wooAllCats($c), $step === 1 ? wooTrashed($c) : []);
        setSetting($pdo, 'woo_last', date('Y-m-d H:i'));
        out(['ok' => true, 'name' => $name]);
    }

    case 'woo_fixcats': {
        if (!$isPost) { fail('POST requis.', 405); }
        session_write_close();
        @set_time_limit(180);
        $c = wooCfg($pdo); $cats = wooAllCats($c); $trashed = wooTrashed($c);
        $done = 0; $notes = [];
        foreach (wooGroups($pdo) as $g) {
            $wid = (int)(setting($pdo, 'woomap:' . $g['g']) ?? 0);
            if (!$wid) { continue; }
            $ids = wooCatIds($pdo, $g, $cats, $trashed);
            if (!$ids) { $notes[] = $g['name'] . ' : aucune catégorie trouvée'; continue; }
            setSetting($pdo, 'woocat:' . $g['g'], json_encode($ids));
            wooReq($c, 'PUT', 'products/' . $wid, ['categories' => array_map(function ($i) { return ['id' => $i]; }, $ids)]);
            $done++;
        }
        out(['ok' => true, 'updated' => $done, 'created' => $created, 'notes' => $notes]);
    }

    case 'woo_cancel': {
        if (!$isPost) { fail('POST requis.', 405); }
        session_write_close();
        $id = (string)(body()['id'] ?? ''); if (!okId($id)) { fail('Données invalides.'); }
        $q = $pdo->prepare('SELECT data FROM orders WHERE id=?'); $q->execute([$id]); $d = json_decode((string)$q->fetchColumn(), true);
        $c = wooCfg($pdo);
        if (!is_array($d) || empty($d['woo']) || $c['key'] === '') { out(['skipped' => true]); }
        wooReq($c, 'PUT', 'orders/' . (int)$d['woo'], ['status' => 'cancelled']);
        out(['ok' => true]);
    }

    case 'sms_get': {
        out(['on' => setting($pdo, 'sms_on') === '1', 'app' => (string)(setting($pdo, 'sms_app') ?? ''), 'hasToken' => (string)(setting($pdo, 'sms_token') ?? '') !== '', 'sender' => (string)(setting($pdo, 'sms_sender') ?? ''), 'mode' => (string)(setting($pdo, 'sms_mode') ?? 'gSystem'), 'tpl' => (string)(setting($pdo, 'sms_tpl') ?? ''), 'tool' => setting($pdo, 'sms_tool') !== '0', 'last' => (string)(setting($pdo, 'sms_last') ?? '')]);
    }

    case 'sms_save': {
        if (!$isPost) { fail('POST requis.', 405); }
        $b = body();
        setSetting($pdo, 'sms_on', !empty($b['on']) ? '1' : '0'); setSetting($pdo, 'sms_tool', !empty($b['tool']) ? '1' : '0');
        setSetting($pdo, 'sms_app', trim((string)($b['app'] ?? ''))); setSetting($pdo, 'sms_sender', mb_substr(trim((string)($b['sender'] ?? '')), 0, 11));
        setSetting($pdo, 'sms_tpl', mb_substr(trim((string)($b['tpl'] ?? '')), 0, 300));
        $md = (string)($b['mode'] ?? 'gSystem'); setSetting($pdo, 'sms_mode', in_array($md, ['gSystem', 'gText', 'gShort'], true) ? $md : 'gSystem');
        $tk = trim((string)($b['token'] ?? '')); if ($tk !== '') { setSetting($pdo, 'sms_token', $tk); }
        out(['ok' => true]);
    }

    case 'sms_test': {
        if (!$isPost) { fail('POST requis.', 405); }
        session_write_close();
        $num = (string)(body()['number'] ?? '');
        [$ok, $msg] = smsSend($pdo, $num, 'TEST Watch CI ' . date('H:i') . ' - ' . smsOrderText($pdo, ['customer' => 'Test', 'items' => [['qty' => 3, 'price' => 5000]], 'disc' => 5000]));
        setSetting($pdo, 'sms_last', date('d/m H:i') . ' · test · ' . $msg);
        out(['ok' => $ok, 'msg' => $msg]);
    }

    case 'push_key': {
        [, $pub] = pushKeys($pdo);
        $n = (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE k LIKE 'pushsub:%'")->fetchColumn();
        out(['key' => $pub, 'devices' => $n, 'hooks' => setting($pdo, 'woo_hooks') === '1', 'woo' => wooCfg($pdo)['key'] !== '']);
    }

    case 'push_sub': {
        if (!$isPost) { fail('POST requis.', 405); }
        $sub = (array)(body()['sub'] ?? []); $k = (array)($sub['keys'] ?? []);
        if (empty($sub['endpoint']) || empty($k['p256dh']) || empty($k['auth']) || strncmp((string)$sub['endpoint'], 'https://', 8) !== 0) { fail('Abonnement invalide.'); }
        setSetting($pdo, 'pushsub:' . md5((string)$sub['endpoint']), json_encode(['endpoint' => (string)$sub['endpoint'], 'p256dh' => (string)$k['p256dh'], 'auth' => (string)$k['auth']]));
        out(['ok' => true]);
    }

    case 'push_off': {
        if (!$isPost) { fail('POST requis.', 405); }
        $ep = (string)(body()['endpoint'] ?? '');
        $pdo->prepare('DELETE FROM settings WHERE k=?')->execute(['pushsub:' . md5($ep)]);
        out(['ok' => true]);
    }

    case 'push_test': {
        if (!$isPost) { fail('POST requis.', 405); }
        session_write_close();
        out(['sent' => pushSend($pdo, 'Watch CI', 'Les notifications fonctionnent ✅')]);
    }

    case 'push_hooks': {
        if (!$isPost) { fail('POST requis.', 405); }
        session_write_close();
        $c = wooCfg($pdo);
        if ($c['url'] === '' || $c['key'] === '' || $c['secret'] === '') { out(['skipped' => true]); }
        out(wooEnsureHooks($pdo, $c));
    }

    case 'woo_orders': {
        if (!$isPost) { fail('POST requis.', 405); }
        session_write_close();
        $c = wooCfg($pdo);
        if ($c['url'] === '' || $c['key'] === '' || $c['secret'] === '') { out(['skipped' => true]); }
        @set_time_limit(90);
        out(wooImportOrders($pdo, $c));
    }

    case 'woo_push': {
        if (!$isPost) { fail('POST requis.', 405); }
        session_write_close();
        $c = wooCfg($pdo);
        if ($c['url'] === '' || $c['key'] === '' || $c['secret'] === '') { out(['skipped' => true]); }
        if (!empty(body()['force'])) { $pdo->exec("DELETE FROM settings WHERE k LIKE 'woosig:%'"); setSetting($pdo, 'woo_busy', '0'); }
        wooImportOrders($pdo, $c);
        $changed = [];
        foreach (wooGroups($pdo) as $g) { if (setting($pdo, 'woosig:' . $g['g']) !== wooSig($g)) { $changed[] = $g; } }
        if (!$changed) { out(['changed' => 0]); }
        $busy = (int)(setting($pdo, 'woo_busy') ?? 0);
        if ($busy > time() - 150) { out(['busy' => true]); }
        setSetting($pdo, 'woo_busy', (string)time());
        @set_time_limit(170);
        $mapped = [];
        foreach ($changed as $g) { $m = setting($pdo, 'woomap:' . $g['g']); if ($m !== null) { $mapped[] = (int)$m; } }
        $site = wooProducts($c, $mapped);
        $cats = wooAllCats($c); $trashed = wooTrashed($c);
        $created = 0; $updated = 0; $notes = [];
        foreach ($changed as $g) {
            $wid = (int)(setting($pdo, 'woomap:' . $g['g']) ?? 0); $x = null;
            foreach ($site as $s2) { if ($s2['id'] === $wid) { $x = $s2; break; } }
            if (!$x) {
                $want = wooNorm($g['name'] . ' ' . $g['color']);
                foreach ($site as $s2) { if (wooNorm($s2['name']) === $want) { $x = $s2; break; } }
            }
            if ($x && $x['type'] === 'variable' && !$x['vars']) { $r = wooProducts($c, [$x['id']]); foreach ($r as $q) { if ($q['id'] === $x['id']) { $x = $q; } } }
            if ($x) {
                setSetting($pdo, 'woomap:' . $g['g'], (string)$x['id']);
                $updated += wooUpdate($pdo, $c, $g, $x, $cats, $trashed, $notes);
            } else {
                wooCreate($pdo, $c, $g, $cats, $trashed); $created++;
            }
            setSetting($pdo, 'woosig:' . $g['g'], wooSig($g));
        }
        setSetting($pdo, 'woo_busy', '0'); setSetting($pdo, 'woo_last', date('Y-m-d H:i'));
        out(['changed' => count($changed), 'created' => $created, 'updated' => $updated, 'notes' => $notes]);
    }

    case 'woo_sync': {
        session_write_close();
        if (!$isPost) { fail('POST requis.', 405); }
        @set_time_limit(180);
        $c = wooCfg($pdo);
        $maps = (array)(body()['map'] ?? []);
        $site = []; foreach (wooProducts($c, array_map('intval', array_values((array)(body()['map'] ?? [])))) as $x) { $site[$x['id']] = $x; }
        $groups = []; foreach (wooGroups($pdo) as $g) { $groups[$g['g']] = $g; }
        $done = 0; $notes = [];
        foreach ($maps as $gid => $wid) {
            $gid = (string)$gid; $wid = (int)$wid;
            if (!isset($groups[$gid])) { continue; }
            if ($wid <= 0) { $pdo->prepare('DELETE FROM settings WHERE k=?')->execute(['woomap:' . $gid]); continue; }
            if (!isset($site[$wid])) { $notes[] = $groups[$gid]['name'] . ' : produit du site introuvable'; continue; }
            setSetting($pdo, 'woomap:' . $gid, (string)$wid);
            $g = $groups[$gid]; $x = $site[$wid];
            if ($x['type'] === 'variable' && $x['vars']) {
                foreach ($x['vars'] as $v) {
                    $vn = wooNums($v['label']); $stock = null;
                    foreach ($g['sizes'] as $sz => $st) {
                        $tn = wooNums((string)$sz);
                        if ($vn && !array_diff($vn, $tn)) { $stock = $st; break; }
                    }
                    if ($stock === null) { $notes[] = $g['name'] . ' · « ' . $v['label'] . ' » : taille non trouvée dans l\'outil'; continue; }
                    wooReq($c, 'PUT', 'products/' . $wid . '/variations/' . $v['id'], ['manage_stock' => true, 'stock_quantity' => max(0, $stock), 'regular_price' => wooPrice($g['price'])]);
                    $done++;
                }
            } else {
                $tot = array_sum($g['sizes']);
                wooReq($c, 'PUT', 'products/' . $wid, ['manage_stock' => true, 'stock_quantity' => max(0, (int)$tot), 'regular_price' => wooPrice($g['price'])]);
                $done++;
            }
        }
        $created = 0; $cats = null; $trashed = [];
        foreach ($groups as $gid2 => $g2) {
            $w2 = isset($maps[$gid2]) ? (int)$maps[$gid2] : 0;
            if ($w2 > 0 || (setting($pdo, 'woomap:' . $gid2) !== null && !isset($maps[$gid2]))) { continue; }
            if (!empty(body()['create'])) {
                if ($cats === null) { $cats = wooAllCats($c); $trashed = wooTrashed($c); }
                try { wooCreate($pdo, $c, $g2, $cats, $trashed); $created++; } catch (Throwable $e) { $notes[] = $g2['name'] . ' : création impossible'; }
            }
        }
        setSetting($pdo, 'woo_last', date('Y-m-d H:i'));
        out(['ok' => true, 'updated' => $done, 'created' => $created, 'notes' => $notes]);
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
