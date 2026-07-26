<?php
/**
 * Приём заявок с сайта посёлка «Гармония» и отправка на почту.
 *
 * Куда положить: рядом с index.html на хостинге С ПОДДЕРЖКОЙ PHP.
 *
 * Что настроить (ОБЯЗАТЕЛЬНО):
 *   $TO_EMAIL   — куда присылать заявки (ваш ящик);
 *   $FROM_EMAIL — от кого уходит письмо. На shared-хостингах (sweb.ru и т.п.)
 *                 это должен быть РЕАЛЬНЫЙ почтовый ящик, СОЗДАННЫЙ на вашем
 *                 домене в панели хостинга (например info@ваш-домен.ru).
 *                 Письма с чужого From хостинг отклоняет — и mail() вернёт false.
 *
 * Проверка: откройте send.php в браузере (обычный переход по адресу) —
 * покажет JSON со статусом (PHP, mail(), настроены ли адреса). Если вместо
 * JSON виден код скрипта — PHP на хостинге не выполняется.
 */

// ── Настройки ─────────────────────────────────────────────────────
$TO_EMAIL   = 'ВАША-ПОЧТА@example.com';   // ← куда присылать заявки
$FROM_EMAIL = 'noreply@garmoniya.ru';     // ← реальный ящик на ВАШЕМ домене
$SUBJECT    = 'Заявка с сайта посёлка «Гармония»';
// ──────────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');

$to_configured   = ($TO_EMAIL   !== '' && stripos($TO_EMAIL, 'example.com') === false);
$from_configured = ($FROM_EMAIL !== '' && $FROM_EMAIL !== 'noreply@garmoniya.ru');

// GET → диагностика (без отправки письма)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'ok'              => true,
        'php'             => PHP_VERSION,
        'mail_available'  => function_exists('mail'),
        'to_configured'   => $to_configured,   // задан ли ваш адрес получателя
        'from_configured' => $from_configured, // сменён ли From на ваш домен
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Дальше — только POST (реальная отправка)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод не поддерживается']);
    exit;
}

// Антиспам-ловушка (honeypot)
if (!empty($_POST['website'])) {
    echo json_encode(['success' => true]);
    exit;
}

function field($key, $max) {
    $v = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}
function no_crlf($v) {
    return trim(str_replace(["\r", "\n", '%0a', '%0d', '%0A', '%0D'], ' ', $v));
}

$name    = field('name', 100);
$phone   = field('phone', 40);
$project = field('project', 120);
$message = field('message', 2000);

// Адрес получателя должен быть настроен
if (!$to_configured) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Не задан адрес получателя ($TO_EMAIL в send.php)']);
    exit;
}

// Обязательные поля + телефон (не меньше 10 цифр)
$phoneDigits = preg_replace('/\D/', '', $phone);
if ($name === '' || strlen($phoneDigits) < 10) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Укажите имя и корректный телефон']);
    exit;
}

// Тело письма (данные пользователя идут только в тело)
$lines = [
    'Новая заявка с сайта посёлка «Гармония»',
    '',
    'Имя: ' . $name,
    'Телефон: ' . $phone,
    'Интересует: ' . ($project !== '' ? $project : '—'),
];
if ($message !== '') {
    $lines[] = 'Сообщение: ' . $message;
}
$lines[] = '';
$lines[] = 'Отправлено: ' . date('d.m.Y H:i');
$body = implode("\n", $lines);

// Заголовки письма (UTF-8)
$subjectEnc = '=?UTF-8?B?' . base64_encode($SUBJECT) . '?=';
$fromName   = '=?UTF-8?B?' . base64_encode('Сайт «Гармония»') . '?=';
$from       = no_crlf($FROM_EMAIL);
$headers  = [];
$headers[] = 'From: ' . $fromName . ' <' . $from . '>';
$headers[] = 'Reply-To: ' . $from;
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'Content-Transfer-Encoding: 8bit';
$headers[] = 'X-Mailer: PHP/' . phpversion();

// 5-й параметр (-f) задаёт конверт-отправителя — многие хостинги без него
// не отправляют письмо через mail().
$ok = @mail(no_crlf($TO_EMAIL), $subjectEnc, $body, implode("\r\n", $headers), '-f' . $from);

if ($ok) {
    echo json_encode(['success' => true]);
} else {
    // подсказка в лог хостинга (панель → логи), чтобы видеть причину
    error_log('garmoniya: mail() вернул false. From=' . $from . ' To=' . $TO_EMAIL);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Хостинг не принял письмо. Проверьте, что From ($FROM_EMAIL) — реальный ящик на вашем домене.'
    ]);
}
