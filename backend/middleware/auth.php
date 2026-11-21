<?php
declare(strict_types=1);

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function requireUser(): array
{
    $user = currentUser();
    if (!$user) respond(['error' => 'Authentication required'], 401);
    if (($user['account_status'] ?? 'active') !== 'active') respond(['error' => 'Account is restricted'], 403);
    return $user;
}

function requireRole(array $roles): array
{
    $user = requireUser();
    if (!in_array($user['role'], $roles, true)) respond(['error' => 'Insufficient permission'], 403);
    return $user;
}

function requireCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        respond(['error' => 'Invalid request token'], 419);
    }
}

function logActivity(?int $userId, string $activity, ?string $type = null, ?int $entityId = null): void
{
    $stmt = db()->prepare('INSERT INTO activity_logs(user_id, activity, entity_type, entity_id) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $activity, $type, $entityId]);
}

