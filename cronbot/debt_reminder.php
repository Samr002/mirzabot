<?php
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../botapi.php';

// Add tracking column once — silently ignored on subsequent runs
addFieldToTable('user', 'debt_notified', '0', 'VARCHAR(50)');

// Reset marker for users whose balance recovered above the threshold
// so they can be re-notified if they fall into debt again later
$stmt = $pdo->prepare(
    "UPDATE user SET debt_notified = '0'
     WHERE Balance >= -500000 AND debt_notified != '0'"
);
$stmt->execute();

// Find active users over the debt threshold who haven't been notified yet
// Process up to 50 per run to avoid PHP timeout
$stmt = $pdo->prepare(
    "SELECT id, username, Balance, bottype FROM user
     WHERE Balance < -500000
       AND User_Status = 'Active'
       AND (debt_notified = '0' OR debt_notified IS NULL)
     LIMIT 50"
);
$stmt->execute();
$debtors = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($debtors)) {
    exit;
}

$keyboard = json_encode([
    'inline_keyboard' => [
        [
            ['text' => '💳 افزایش موجودی', 'callback_data' => 'Add_Balance'],
        ],
    ],
]);

foreach ($debtors as $debtor) {
    $userId       = $debtor['id'];
    $debtFormatted = number_format(abs((int) $debtor['Balance']), 0);
    $botToken     = (!empty($debtor['bottype']) && $debtor['bottype'] !== '0')
                    ? $debtor['bottype']
                    : null;

    $message = "🔔 <b>اطلاعیه مهم</b>\n\n"
        . "کاربر گرامی،\n\n"
        . "با احترام به اطلاع می‌رسانیم که موجودی حساب شما در حال حاضر منفی بوده "
        . "و میزان بدهی شما به مبلغ <b>" . $debtFormatted . " تومان</b> رسیده است.\n\n"
        . "خواهشمند است در اسرع وقت نسبت به شارژ کیف پول و تسویه بدهی خود اقدام فرمایید.\n\n"
        . "با تشکر از همراهی شما 🙏";

    telegram('sendMessage', [
        'chat_id'      => $userId,
        'text'         => $message,
        'parse_mode'   => 'HTML',
        'reply_markup' => $keyboard,
    ], $botToken);

    // Mark as notified regardless of send result (avoids spamming blocked users too)
    $now  = (string) time();
    $stmt2 = $pdo->prepare("UPDATE user SET debt_notified = :ts WHERE id = :id");
    $stmt2->bindParam(':ts', $now);
    $stmt2->bindParam(':id', $userId);
    $stmt2->execute();
}
