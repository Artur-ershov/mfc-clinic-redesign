<?php
require __DIR__ . '/notify-lib.php';
// MFC Clinic — lead form -> email + Telegram relay.
// Temporary routing: sends to Artur's email and a Telegram bot for review
// while the clinic's final lead-handling setup (CRM / dedicated inbox) is
// decided. Both channels are tried; the request only fails if BOTH do.
//
// Also serves the production site (mfc-clinic.ru), which is static-hosted on
// Timeweb with no PHP runtime — its form posts here cross-origin until a
// proper backend exists there.

$allowedOrigins = ['https://mfc-clinic.ru', 'https://www.mfc-clinic.ru', 'https://lume.moscow', 'https://mfc.lume.moscow'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$name    = trim((string)($data['name'] ?? ''));
$contact = trim((string)($data['contact'] ?? ''));
$prefDate = trim((string)($data['preferred_date'] ?? ''));
$prefTime = trim((string)($data['preferred_time'] ?? ''));
$consent = !empty($data['consent']);
$source  = trim((string)($data['source'] ?? ''));

// Разметка канала — нужна не письму, а МИС: IDENT принимает UTM в объекте
// «Заявка» и дальше сам связывает обращение с записью, приёмом и деньгами.
$email    = trim((string)($data['email'] ?? ''));
$page     = trim((string)($data['page'] ?? ''));
$referer  = trim((string)($data['referer'] ?? ''));
$clientId = trim((string)($data['client_id'] ?? ''));
$utmIn    = is_array($data['utm'] ?? null) ? $data['utm'] : [];
$utm      = [];
foreach (['source', 'medium', 'campaign', 'term', 'content'] as $k) {
    $utm[$k] = mb_substr(str_replace(["\r", "\n"], ' ', trim((string)($utmIn[$k] ?? ''))), 0, 200);
}

if ($name === '' || $contact === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing fields']);
    exit;
}

// 152-ФЗ: согласие на обработку персональных данных обязательно.
if (!$consent) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'consent required']);
    exit;
}

// Базовая защита от переполнения и header-инъекций.
$name     = mb_substr(str_replace(["\r", "\n"], ' ', $name), 0, 200);
$contact  = mb_substr(str_replace(["\r", "\n"], ' ', $contact), 0, 200);
$prefDate = mb_substr(str_replace(["\r", "\n"], ' ', $prefDate), 0, 20);
$prefTime = mb_substr(str_replace(["\r", "\n"], ' ', $prefTime), 0, 20);
$source   = mb_substr(str_replace(["\r", "\n"], ' ', $source), 0, 50);
$email    = mb_substr(str_replace(["\r", "\n"], ' ', $email), 0, 200);
$page     = mb_substr(str_replace(["\r", "\n"], ' ', $page), 0, 300);
$referer  = mb_substr(str_replace(["\r", "\n"], ' ', $referer), 0, 300);
$clientId = mb_substr(str_replace(["\r", "\n"], ' ', $clientId), 0, 50);

$sourceLabels = [
    'nav' => 'Шапка сайта', 'mobile_menu' => 'Мобильное меню', 'mobile_bar' => 'Нижняя панель (моб.)',
    'hero' => 'Главный экран', 'flagship' => '«Зубы за 3 дня»', 'calculator' => 'Калькулятор',
    'cta_block' => 'Блок записи внизу', 'footer' => 'Подвал сайта',
];
$sourceLabel = $sourceLabels[$source] ?? ($source !== '' ? $source : 'не определён');

// Технический прогон интеграции: заявка проходит весь путь и ложится в очередь
// для IDENT, но письмо и телеграм не отправляются — чтобы проверять связку, не
// дёргая почту и рабочую группу. Обычная заявка с сайта этот токен не несёт.
$isProbe = hash_equals('80d6c9b920abfe171e0bbb20', (string)($data['probe'] ?? ''));

$to      = 'yershov.artur@gmail.com';
$subject = '=?UTF-8?B?' . base64_encode('Заявка с сайта MFC Clinic') . '?=';
$when    = date('d.m.Y H:i');
$ip      = $_SERVER['REMOTE_ADDR'] ?? '—';
$ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';

$prefLine = '';
if ($prefDate !== '' || $prefTime !== '') {
    $prefLine = "Удобное время: " . trim($prefDate . ' ' . $prefTime) . "\n";
}

$utmLine = '';
if ($utm['source'] !== '' || $utm['campaign'] !== '') {
    $utmLine = 'Канал: ' . trim($utm['source'] . ' / ' . $utm['medium'] . ' / ' . $utm['campaign'], ' /') . "\n";
}

$siteLabel = $origin !== '' ? $origin : 'lume.moscow/mfc/site';
$body = "Новая заявка с {$siteLabel}\n\n"
      . "Имя: {$name}\n"
      . "Контакт: {$contact}\n"
      . $prefLine
      . "Кнопка: {$sourceLabel}\n"
      . $utmLine . "\n"
      . "— {$when} · согласие на обработку ПДн получено · IP {$ip}";

$headers = "From: MFC Clinic Site <noreply@lume.moscow>\r\n"
         . "Reply-To: noreply@lume.moscow\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n";

$emailOk = $isProbe ? true : @mail($to, $subject, $body, $headers);

// Fallback #3 — local append-only log, no network involved, so it can't be
// taken down by an email or Telegram outage. Folder is blocked from public
// HTTP access by leads/.htaccess.
$logDir = __DIR__ . '/leads';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
$logLine = json_encode([
    'time' => date('c'), 'name' => $name, 'contact' => $contact,
    'preferred_date' => $prefDate, 'preferred_time' => $prefTime,
    'source' => $sourceLabel, 'site' => $siteLabel, 'ip' => $ip, 'ua' => $ua,
], JSON_UNESCAPED_UNICODE) . "\n";
@file_put_contents($logDir . '/leads.ndjson', $logLine, FILE_APPEND | LOCK_EX);

// Очередь для МИС клиники. IDENT забирает её сам раз в 2 минуты через
// /mfc/ident/GetTickets — см. ../ident/index.php. Запись сюда никогда не должна
// ронять отправку: если каталог недоступен, заявка всё равно уйдёт в почту и
// телеграм, просто не появится у администратора в программе.
$identDir = dirname(__DIR__) . '/ident/data';
if (!is_dir($identDir)) { @mkdir($identDir, 0755, true); }
if (!is_file($identDir . '/.htaccess')) {
    @file_put_contents($identDir . '/.htaccess', "Require all denied\n");
}
$ticket = [
    'id'   => 'web-' . date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 8),
    'time' => date('c'),
    'name' => $name, 'contact' => $contact, 'email' => $email,
    'preferred_date' => $prefDate, 'preferred_time' => $prefTime,
    'form' => 'Сайт · ' . $sourceLabel, 'page' => $page,
    'referer' => $referer, 'client_id' => $clientId, 'utm' => $utm,
    'ip' => $ip, 'ua' => $ua,
];
@file_put_contents(
    $identDir . '/tickets.ndjson',
    json_encode($ticket, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
    FILE_APPEND | LOCK_EX
);

// Текст уведомления. Сначала кладём в очередь, потом пробуем отправить сразу:
// если Telegram недоступен, задание останется и его добьёт notify-worker.php.
$tgText = "🦷 Новая заявка с {$siteLabel}

"
        . "Имя: {$name}
"
        . "Контакт: {$contact}
"
        . $prefLine
        . "Кнопка: {$sourceLabel}
"
        . $utmLine . "
"
        . "{$when} · согласие получено · IP {$ip}";

$tgOk = false;
$tgFailed = [];
if ($isProbe) {
    $tgOk = true;
} else {
    $jobPath = tgEnqueue($tgText);
    // две секунды на попытку «прямо сейчас»: обычно Telegram отвечает за 0.7с и человек
    // получает уведомление мгновенно, а если связь тормозит — не ждём, добьёт крон
    $tgOk = tgRunJob($jobPath, 2);
    if (!$tgOk) {
        $job = json_decode(@file_get_contents($jobPath), true);
        $tgFailed = ['в очереди' => ($job['last_error'] ?? 'не доставлено, будет повтор')];
    }
}

// Успех одного адресата не должен маскировать поломку другого: заявка уходила
// Артуру в личку, а в группу молча не доходила — и снаружи всё выглядело рабочим.
// Пишем, до кого именно не дошло, чтобы это было видно.
if ($tgFailed) {
    @file_put_contents(
        $logDir . '/delivery-errors.ndjson',
        json_encode(['time' => date('c'), 'name' => $name, 'failed' => $tgFailed], JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

if (!$emailOk && !$tgOk) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'send failed']);
    exit;
}

echo json_encode(['ok' => true, 'email' => $emailOk, 'telegram' => $tgOk]);
