<?php
/**
 * Приём заявок с сайта посёлка «Гармония» и отправка на почту.
 *
 * Куда положить: рядом с index.html на хостинге С ПОДДЕРЖКОЙ PHP
 * (обычный shared-хостинг или VPS). На GitHub Pages PHP не выполняется —
 * там форма работать не будет.
 *
 * Что настроить: заполните $TO_EMAIL (куда слать заявки) и по возможности
 * $FROM_EMAIL (адрес на вашем домене, от чьего имени уходит письмо).
 */

// ── Настройки ─────────────────────────────────────────────────────
$TO_EMAIL   = 'ВАША-ПОЧТА@example.com';        // ← куда присылать заявки
$FROM_EMAIL = 'noreply@garmoniya.ru';          // ← адрес-отправитель (домен сайта)
$SUBJECT    = 'Заявка с сайта посёлка «Гармония»';
// ──────────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');

// Отвечаем только на POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод не поддерживается']);
    exit;
}

// Антиспам-ловушка (honeypot): у поля "website" в форме нет видимого input,
// его заполняют только боты. Если заполнено — тихо выходим «успехом».
if (!empty($_POST['website'])) {
    echo json_encode(['success' => true]);
    exit;
}

// Достаём и обрезаем поля
function field($key, $max) {
    $v = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}
// Убираем переносы строк — защита от инъекции заголовков письма
function no_crlf($v) {
    return trim(str_replace(["\r", "\n", '%0a', '%0d', '%0A', '%0D'], ' ', $v));
}

$name    = field('name', 100);
$phone   = field('phone', 40);
$project = field('project', 120);
$message = field('message', 2000);

// Обязательные поля + проверка телефона (не меньше 10 цифр)
$phoneDigits = preg_replace('/\D/', '', $phone);
if ($name === '' || strlen($phoneDigits) < 10) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Укажите имя и корректный телефон']);
    exit;
}

// Тело письма (пользовательские данные идут только в тело — заголовки не задевают)
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
$headers  = [];
$headers[] = 'From: ' . $fromName . ' <' . no_crlf($FROM_EMAIL) . '>';
$headers[] = 'Reply-To: ' . no_crlf($FROM_EMAIL);
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'Content-Transfer-Encoding: 8bit';
$headers[] = 'X-Mailer: PHP/' . phpversion();

$ok = @mail(no_crlf($TO_EMAIL), $subjectEnc, $body, implode("\r\n", $headers));

if ($ok) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Не удалось отправить письмо']);
}
