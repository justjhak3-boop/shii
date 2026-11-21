<?php
declare(strict_types=1);

/**
 * Configure CAMPUSFIND_MAIL_FROM and a working server mail transport before
 * enabling email delivery. In-app notifications remain the reliable default.
 */
function sendMatchEmail(int $userId, string $lostName, string $foundName): void
{
    $from = getenv('CAMPUSFIND_MAIL_FROM');
    if (!$from || !function_exists('mail')) return;

    $stmt = db()->prepare('SELECT full_name,email FROM users WHERE id=?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) return;

    $subject = 'A possible CampusFind match was found';
    $body = "Hello {$user['full_name']},\n\nA found-item report for {$foundName} may match your lost {$lostName}. Sign in to CampusFind to review it.\n\nA possible match does not confirm ownership.";
    $headers = "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8";
    @mail($user['email'], $subject, $body, $headers);
}

