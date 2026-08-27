<?php
declare(strict_types=1);

/**
 * MFC Clinic — шлюз интеграции с МИС IDENT (dent-it.ru).
 *
 * IDENT стоит внутри клиники, наружу ничего не слушает и не имеет веб-сервера:
 * все обращения инициирует «Клиент» — запущенная копия программы на рабочем
 * компьютере. Отсюда направление потоков: заявки и звонки IDENT ЗАБИРАЕТ у нас
 * методом GET, расписание врачей — ОТДАЁТ методом POST.
 *
 *   GET  …/GetTickets        заявки с сайта          опрос раз в 2 мин
 *   GET  …/GetFinishedCalls  завершённые звонки      опрос раз в 180 мин
 *   GET  …/GetOngoingCalls   звонки в моменте
 *   POST …/PostTimeTable     расписание на 30 дней   раз в 10 мин
 *
 * Спецификация протокола:  https://help.dent-it.ru/help/variant-s-http
 * Структура объектов:      https://help.dent-it.ru/help/struktura-peredavaemykh-obektov
 *
 * Авторизация — заголовок IDENT-Integration-Key. Требование IDENT: все ошибки,
 * кроме авторизационных 401/403, отдавать кодами 4XX/5XX и никогда 2XX.
 */

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['timezone']);

define('DATA_DIR', __DIR__ . '/data');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path   = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
$action = strtolower(basename(rtrim($path, '/')));

// Пишем каждое обращение: когда программа в клинике говорит «невозможно
// соединиться», первый вопрос — долетел ли запрос вообще.
logLine('→ ' . $method . ' ' . $path . ' от ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));

switch ($action) {
    case 'gettickets':       requireKey($cfg); requireMethod('GET', $method);  actionTickets($cfg);       break;
    case 'getfinishedcalls': requireKey($cfg); requireMethod('GET', $method);  actionCalls($cfg, 'calls-finished.ndjson', true);  break;
    case 'getongoingcalls':  requireKey($cfg); requireMethod('GET', $method);  actionCalls($cfg, 'calls-ongoing.ndjson', false); break;
    case 'posttimetable':    requireKey($cfg); requireMethod('POST', $method); actionTimeTable();         break;

    // Наша служебная сводка, IDENT её не вызывает.
    case 'status':           requireKey($cfg); actionStatus($cfg);             break;
    case 'log':              requireKey($cfg); actionLog();                    break;

    // Корень каталога — health-check, чтобы можно было проверить шлюз из
    // браузера, ничего не раскрывая. Ключ здесь не нужен.
    case '':
    case 'ident':
    case 'index.php':
        respond(['service' => 'mfc-ident-gateway', 'ok' => true, 'time' => date('c')]);
        break;

    default:
        // Пишем в лог, чтобы увидеть, что именно дёргает кнопка «Проверить
        // работоспособность» в настройках интеграции — в документации это
        // не описано.
        logLine('НЕИЗВЕСТНЫЙ ' . $method . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . ' от ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        fail(404, 'unknown method: ' . $action);
}

/* ------------------------------------------------------------------ методы -- */

/**
 * Заявки с сайта. Пишет их send-lead.php при каждой отправке формы —
 * см. ../site/send-lead.php. IDENT сам отбрасывает дубли по Id, поэтому
 * состояние «уже отдано» мы не храним: отдаём всё, что попало в окно.
 */
function actionTickets(array $cfg): void
{
    if (empty($cfg['tickets_enabled'])) {
        logLine('GetTickets отключён в config.php — отдан пустой список');
        respond([]);
    }

    [$from, $to]      = periodFromQuery();
    [$limit, $offset] = pagingFromQuery($cfg);

    // Отсев делаем до нарезки на страницы. Иначе страница из 500 записей, где
    // часть отброшена, вернула бы меньше limit — а для IDENT это сигнал
    // «данные кончились», и остаток он бы уже не запросил.
    $rows    = readStore('tickets.ndjson', $from, $to);
    $all     = [];
    $skipped = 0;

    foreach ($rows as $r) {
        $t = ticketToIdent($r);
        if ($t === null) { $skipped++; continue; }
        $all[] = $t;
    }
    $tickets = array_slice($all, $offset, $limit);

    // Заявки без распознанного телефона (человек оставил только телеграм)
    // в IDENT не уходят: ClientPhone — обязательное поле. Они по-прежнему
    // приходят в почту и телеграм-бот, здесь только считаем их для отладки.
    logLine("GetTickets from={$from->format('c')} to={$to->format('c')} limit={$limit} offset={$offset} -> " . count($tickets) . " (skipped {$skipped})");

    respond($tickets);
}

/**
 * Звонки. Файлы наполняет выгрузка из UIS — пока не подключена, тогда
 * отдаётся пустой массив: для IDENT это валидный ответ «новых звонков нет».
 */
function actionCalls(array $cfg, string $store, bool $filterByPeriod): void
{
    [$limit, $offset] = pagingFromQuery($cfg);

    if ($filterByPeriod) {
        [$from, $to] = periodFromQuery();
        $rows = readStore($store, $from, $to);
    } else {
        $rows = readStore($store, null, null);
    }

    $all = [];
    foreach ($rows as $r) {
        $c = callToIdent($r);
        if ($c !== null) { $all[] = $c; }
    }
    $calls = array_slice($all, $offset, $limit);

    logLine(basename($store) . ' -> ' . count($calls));
    respond($calls);
}

/**
 * Расписание врачей на 30 дней вперёд. Единственный поток, где IDENT отдаёт
 * данные наружу. Пишем последний срез плюс по снимку на день — из этой серии
 * получается история загрузки кресел.
 */
function actionTimeTable(): void
{
    $raw = (string)file_get_contents('php://input');
    if ($raw === '') {
        fail(400, 'empty body');
    }

    $body = json_decode($raw, true);
    if (!is_array($body) || !isset($body['Intervals'])) {
        fail(400, 'expected JSON with Doctors, Branches, Intervals');
    }

    $counts = [
        'doctors'   => is_array($body['Doctors']   ?? null) ? count($body['Doctors'])   : 0,
        'branches'  => is_array($body['Branches']  ?? null) ? count($body['Branches'])  : 0,
        'intervals' => is_array($body['Intervals'] ?? null) ? count($body['Intervals']) : 0,
    ];

    $body['_received'] = date('c');
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    ensureDataDir();
    $snapDir = DATA_DIR . '/timetable';
    if (!is_dir($snapDir)) { @mkdir($snapDir, 0755, true); }

    @file_put_contents(DATA_DIR . '/timetable-latest.json', $json, LOCK_EX);
    @file_put_contents($snapDir . '/' . date('Y-m-d') . '.json', $json, LOCK_EX);

    logLine("PostTimeTable <- doctors={$counts['doctors']} branches={$counts['branches']} intervals={$counts['intervals']}");
    respond(['ok' => true] + $counts);
}

/**
 * Сводка по тому, что доехало. Нужна нам, а не программе: каталог с данными
 * закрыт от HTTP, а лазить за каждым срезом по FTP неудобно.
 */
function actionStatus(array $cfg): void
{
    $tt = ['received' => null, 'doctors' => 0, 'branches' => 0, 'intervals' => 0, 'busy' => 0];

    $file = DATA_DIR . '/timetable-latest.json';
    if (is_file($file)) {
        $body = json_decode((string)file_get_contents($file), true);
        if (is_array($body)) {
            $intervals = is_array($body['Intervals'] ?? null) ? $body['Intervals'] : [];
            $tt['received']  = $body['_received'] ?? null;
            $tt['doctors']   = is_array($body['Doctors'] ?? null)  ? count($body['Doctors'])  : 0;
            $tt['branches']  = is_array($body['Branches'] ?? null) ? count($body['Branches']) : 0;
            $tt['intervals'] = count($intervals);
            foreach ($intervals as $i) {
                if (!empty($i['IsBusy'])) { $tt['busy']++; }
            }
        }
    }

    $snapshots = glob(DATA_DIR . '/timetable/*.json') ?: [];

    respond([
        'ok'        => true,
        'now'       => date('c'),
        'timetable' => $tt + ['snapshots' => count($snapshots)],
        'tickets'   => ['queued' => countLines('tickets.ndjson'), 'enabled' => (bool)$cfg['tickets_enabled']],
        'calls'     => ['finished' => countLines('calls-finished.ndjson'), 'ongoing' => countLines('calls-ongoing.ndjson')],
    ]);
}

/** Хвост журнала обращений — тоже только для нас. */
function actionLog(): void
{
    $file = DATA_DIR . '/access.log';
    if (!is_file($file)) {
        respond([]);
    }
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    respond(array_slice($lines, -60));
}

function countLines(string $name): int
{
    $file = DATA_DIR . '/' . $name;
    if (!is_file($file)) {
        return 0;
    }
    $n = 0;
    $fh = @fopen($file, 'r');
    if ($fh === false) {
        return 0;
    }
    while (($line = fgets($fh)) !== false) {
        if (trim($line) !== '') { $n++; }
    }
    fclose($fh);
    return $n;
}

/* ---------------------------------------------------------------- маппинг -- */

/** Запись из tickets.ndjson → объект «Заявка» в формате IDENT. */
function ticketToIdent(array $r): ?array
{
    $phone = normalizePhone((string)($r['contact'] ?? ''));
    if ($phone === null) {
        return null;
    }

    $comment = [];
    $pref = trim((string)($r['preferred_date'] ?? '') . ' ' . (string)($r['preferred_time'] ?? ''));
    if ($pref !== '')                      { $comment[] = 'Удобное время: ' . $pref; }
    if (!empty($r['form']))                { $comment[] = 'Кнопка: ' . $r['form']; }
    if (!empty($r['page']))                { $comment[] = 'Страница: ' . $r['page']; }
    if (!empty($r['client_id']))           { $comment[] = 'Метрика ClientID: ' . $r['client_id']; }

    $utm = is_array($r['utm'] ?? null) ? $r['utm'] : [];

    return [
        'Id'             => (string)($r['id'] ?? md5(json_encode($r))),
        'DateAndTime'    => isoDate((string)($r['time'] ?? 'now')),
        'ClientPhone'    => $phone,
        'ClientEmail'    => nullIfEmpty((string)($r['email'] ?? '')),
        'ClientFullName' => nullIfEmpty(trim((string)($r['name'] ?? ''))) ?? 'Заявка с сайта',
        'FormName'       => nullIfEmpty((string)($r['form'] ?? '')) ?? 'Форма на сайте',
        'Comment'        => nullIfEmpty(implode('; ', $comment)),
        'UtmSource'      => nullIfEmpty((string)($utm['source']   ?? '')),
        'UtmMedium'      => nullIfEmpty((string)($utm['medium']   ?? '')),
        'UtmCampaign'    => nullIfEmpty((string)($utm['campaign'] ?? '')),
        'UtmTerm'        => nullIfEmpty((string)($utm['term']     ?? '')),
        'UtmContent'     => nullIfEmpty((string)($utm['content']  ?? '')),
        'HttpReferer'    => nullIfEmpty((string)($r['referer'] ?? '')),
    ];
}

/** Запись из calls-*.ndjson → объект «Вызов» в формате IDENT. */
function callToIdent(array $r): ?array
{
    $from = normalizePhone((string)($r['phone_from'] ?? ''));
    $to   = normalizePhone((string)($r['phone_to'] ?? ''));
    if ($from === null || $to === null) {
        return null;
    }

    $utm = is_array($r['utm'] ?? null) ? $r['utm'] : [];

    return [
        'DateAndTime'     => isoDate((string)($r['time'] ?? 'now')),
        'Direction'       => ((string)($r['direction'] ?? 'in')) === 'out' ? 'out' : 'in',
        'PhoneFrom'       => $from,
        'PhoneTo'         => $to,
        'WaitInSeconds'   => (int)($r['wait'] ?? 0),
        'TalkInSeconds'   => isset($r['talk']) ? (int)$r['talk'] : null,
        'LineDescription' => nullIfEmpty((string)($r['line'] ?? '')),
        'RecordUrl'       => nullIfEmpty((string)($r['record_url'] ?? '')),
        'UtmSource'       => nullIfEmpty((string)($utm['source']   ?? '')),
        'UtmMedium'       => nullIfEmpty((string)($utm['medium']   ?? '')),
        'UtmCampaign'     => nullIfEmpty((string)($utm['campaign'] ?? '')),
        'UtmTerm'         => nullIfEmpty((string)($utm['term']     ?? '')),
        'UtmContent'      => nullIfEmpty((string)($utm['content']  ?? '')),
        'HttpReferer'     => nullIfEmpty((string)($r['referer'] ?? '')),
    ];
}

/* ------------------------------------------------------------ инструменты -- */

function requireKey(array $cfg): void
{
    $given = '';
    if (!empty($_SERVER['HTTP_IDENT_INTEGRATION_KEY'])) {
        $given = (string)$_SERVER['HTTP_IDENT_INTEGRATION_KEY'];
    } elseif (function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $name => $value) {
            if (strcasecmp((string)$name, 'IDENT-Integration-Key') === 0) {
                $given = (string)$value;
                break;
            }
        }
    }

    if ($given === '' || !hash_equals((string)$cfg['key'], trim($given))) {
        logLine('DENIED ' . ($_SERVER['REQUEST_URI'] ?? '') . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        fail(401, 'invalid or missing IDENT-Integration-Key');
    }
}

function requireMethod(string $expected, string $actual): void
{
    if ($actual !== $expected) {
        fail(405, "method not allowed, expected {$expected}");
    }
}

/** dateTimeFrom / dateTimeTo из query. IDENT шлёт ISO 8601 со смещением. */
function periodFromQuery(): array
{
    $from = parseDate((string)($_GET['dateTimeFrom'] ?? ''), '2000-01-01T00:00:00');
    $to   = parseDate((string)($_GET['dateTimeTo'] ?? ''),   '2100-01-01T00:00:00');
    if ($from > $to) {
        fail(400, 'dateTimeFrom is after dateTimeTo');
    }
    return [$from, $to];
}

function parseDate(string $value, string $fallback): DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        $value = $fallback;
    }
    try {
        return new DateTimeImmutable($value);
    } catch (Exception $e) {
        fail(400, 'bad datetime: ' . $value);
    }
}

function pagingFromQuery(array $cfg): array
{
    $limit  = isset($_GET['limit'])  ? (int)$_GET['limit']  : (int)$cfg['default_limit'];
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    if ($limit <= 0)                    { $limit = (int)$cfg['default_limit']; }
    if ($limit > (int)$cfg['max_limit']) { $limit = (int)$cfg['max_limit']; }
    if ($offset < 0)                    { $offset = 0; }

    return [$limit, $offset];
}

/**
 * Читает ndjson-хранилище, отбрасывает битые строки и то, что вне окна.
 * Порядок обязан быть стабильным между страницами, иначе постраничная
 * выгрузка IDENT потеряет или задвоит записи — сортируем по времени и id.
 */
function readStore(string $name, ?DateTimeImmutable $from, ?DateTimeImmutable $to): array
{
    $file = DATA_DIR . '/' . $name;
    if (!is_file($file)) {
        return [];
    }

    $rows = [];
    $fh = @fopen($file, 'r');
    if ($fh === false) {
        fail(500, 'store unavailable');
    }

    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') { continue; }

        $r = json_decode($line, true);
        if (!is_array($r) || empty($r['time'])) { continue; }

        try {
            $ts = new DateTimeImmutable((string)$r['time']);
        } catch (Exception $e) {
            continue;
        }

        if ($from !== null && $ts < $from) { continue; }
        if ($to !== null && $ts > $to)     { continue; }

        $r['_ts'] = $ts->getTimestamp();
        $rows[] = $r;
    }
    fclose($fh);

    usort($rows, static function (array $a, array $b): int {
        return [$a['_ts'], (string)($a['id'] ?? '')] <=> [$b['_ts'], (string)($b['id'] ?? '')];
    });

    return $rows;
}

/**
 * Телефон к виду +7XXXXXXXXXX. Поле «контакт» на сайте свободное — туда пишут
 * и телеграм, и почту, поэтому вытаскиваем цифры и проверяем на правдоподобие.
 */
function normalizePhone(string $raw): ?string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';

    if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
        $digits = '7' . substr($digits, 1);
    } elseif (strlen($digits) === 10 && $digits[0] === '9') {
        $digits = '7' . $digits;
    } else {
        return null;
    }

    return '+' . $digits;
}

function isoDate(string $value): string
{
    try {
        return (new DateTimeImmutable($value))->format('Y-m-d\TH:i:sP');
    } catch (Exception $e) {
        return (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP');
    }
}

function nullIfEmpty(?string $v): ?string
{
    $v = $v === null ? '' : trim($v);
    return $v === '' ? null : $v;
}

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0755, true);
    }
    // Хранилище содержит телефоны — наружу его отдавать нельзя.
    $guard = DATA_DIR . '/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "Require all denied\n");
    }
}

function logLine(string $message): void
{
    ensureDataDir();
    $file = DATA_DIR . '/access.log';
    if (is_file($file) && filesize($file) > 1048576) {
        @rename($file, $file . '.1');
    }
    @file_put_contents($file, date('c') . ' ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

function respond($payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
