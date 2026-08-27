<?php
// Разгребает очередь уведомлений. Запускается по расписанию раз в минуту.
// Смысл: доставка перестаёт зависеть от того, был ли доступен Telegram
// ровно в секунду отправки формы. Не ушло сразу — уйдёт с повтором.
//
// Крон: * * * * * /usr/bin/php /www/lume.moscow/mfc/site/notify-worker.php
// Из браузера тоже открывается, но только со сверкой ключа.

require __DIR__ . '/notify-lib.php';

$fromCli = (PHP_SAPI === 'cli');
if (!$fromCli) {
    // из веба пускаем только по ключу — иначе очередь сможет дёргать кто угодно
    $key = $_GET['key'] ?? '';
    if (!hash_equals('7c1f4a9e2b6d0853', (string)$key)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

// Крон может запуститься, пока предыдущий проход ещё идёт, — второй просто уходит.
$lock = @fopen(__DIR__ . '/leads/worker.lock', 'c');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$fromCli) { echo json_encode(['ok' => true, 'skipped' => 'уже выполняется']); }
    exit;
}

tgQueueInit();
$files = glob(TG_QUEUE_DIR . '/*.json') ?: [];
$now = time();
$done = 0; $retried = 0; $skipped = 0;

foreach ($files as $f) {
    $job = json_decode(@file_get_contents($f), true);
    if (!is_array($job)) { continue; }
    if (($job['next_try'] ?? 0) > $now) { $skipped++; continue; }
    if (tgRunJob($f)) { $done++; } else { $retried++; }
}

$report = ['ok' => true, 'в_очереди_было' => count($files),
           'доставлено' => $done, 'осталось_на_потом' => $retried, 'рано_повторять' => $skipped];

if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
if (!$fromCli) { echo json_encode($report, JSON_UNESCAPED_UNICODE); }
else { echo date('c') . ' ' . json_encode($report, JSON_UNESCAPED_UNICODE) . PHP_EOL; }
