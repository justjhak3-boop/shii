<?php
declare(strict_types=1);

function createMatchesForFoundItem(PDO $pdo, int $foundId): void
{
    $foundQuery = $pdo->prepare(
        'SELECT f.*, c.category_name FROM found_items f JOIN categories c ON c.id=f.category_id WHERE f.id=?'
    );
    $foundQuery->execute([$foundId]);
    $found = $foundQuery->fetch();
    if (!$found) return;

    $lostQuery = $pdo->prepare(
        "SELECT l.*, c.category_name FROM lost_items l JOIN categories c ON c.id=l.category_id WHERE l.status IN ('lost','matched')"
    );
    $lostQuery->execute();
    $insert = $pdo->prepare('INSERT IGNORE INTO matches(lost_item_id, found_item_id, match_score) VALUES (?, ?, ?)');
    $notify = $pdo->prepare('INSERT INTO notifications(user_id,title,message,type) VALUES (?,?,?,?)');

    foreach ($lostQuery as $lost) {
        $category = (int)$lost['category_id'] === (int)$found['category_id'];
        $nameA = strtolower($lost['item_name']);
        $nameB = strtolower($found['item_name']);
        $name = $nameA === $nameB || str_contains($nameA, $nameB) || str_contains($nameB, $nameA);
        $description = keywordOverlap($lost['description'], $found['description']);
        $location = similarTextScore($lost['location'], $found['location']);
        $days = abs((strtotime($lost['date_lost']) ?: 0) - (strtotime($found['date_found']) ?: 0)) / 86400;
        $dateScore = $days <= 2 ? 1 : ($days <= 7 ? 0.6 : 0.2);
        $score = (int)round(($category ? 30 : 0) + ($name ? 35 : 0) + ($description * 20) + ($location * 10) + ($dateScore * 5));
        if ($score < 30) continue;

        $insert->execute([(int)$lost['id'], $foundId, min(100, $score)]);
        if ($insert->rowCount() > 0) {
            $notify->execute([
                (int)$lost['user_id'],
                'Possible item match',
                'A found-item report may match your lost ' . $lost['item_name'] . '. Review the lead and verify ownership.',
                'match'
            ]);
            if (function_exists('sendMatchEmail')) {
                sendMatchEmail((int)$lost['user_id'], $lost['item_name'], $found['item_name']);
            }
        }
    }
}

function keywordOverlap(string $a, string $b): float
{
    $words = static function (string $s): array {
        $parts = preg_split('/[^\pL\pN]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter($parts, fn($w) => mb_strlen($w) > 2)));
    };
    $left = $words($a);
    $right = $words($b);
    if (!$left || !$right) return 0;
    return count(array_intersect($left, $right)) / max(count($left), count($right));
}

function similarTextScore(string $a, string $b): float
{
    similar_text(mb_strtolower($a), mb_strtolower($b), $percent);
    return min(1, $percent / 100);
}

