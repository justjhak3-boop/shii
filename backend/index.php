<?php
declare(strict_types=1);

require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/config/database.php';

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) respond(['error' => 'Expected a JSON object'], 400);
    return $data;
}

function requiredText(array $data, string $key, int $max): string
{
    $value = trim((string)($data[$key] ?? ''));
    if ($value === '' || mb_strlen($value) > $max) respond(['error' => "Invalid {$key}"], 422);
    return $value;
}

function safeUser(array $row): array
{
    unset($row['password_hash']);
    return $row;
}

require_once __DIR__ . '/middleware/auth.php';
require_once __DIR__ . '/services/MatchingService.php';
require_once __DIR__ . '/services/EmailService.php';

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params(['httponly'=>true, 'secure'=>$https, 'samesite'=>'Lax', 'path'=>'/']);
session_start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = '/' . trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$path = preg_replace('#^/backend(?=/|$)#', '', $path) ?: '/';
$path = preg_replace('#^/index\.php#', '', $path) ?: '/';

if ($method === 'GET' && $path === '/api/health') respond(['ok'=>true,'service'=>'CampusFind API']);
if ($method === 'GET' && $path === '/api/csrf') {
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    respond(['token'=>$_SESSION['csrf']]);
}

$pdo = db();

if ($method === 'POST' && $path === '/api/register') {
    $data = body();
    $name = requiredText($data, 'full_name', 120);
    $email = filter_var(trim((string)($data['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $password = (string)($data['password'] ?? '');
    $role = (string)($data['role'] ?? 'student');
    if (!$email || strlen($password) < 10 || !in_array($role, ['student','staff'], true)) {
        respond(['error'=>'Provide a valid email, a password of at least 10 characters, and a student or staff role'], 422);
    }
    $matric = trim((string)($data['matric_number'] ?? '')) ?: null;
    try {
        $q=$pdo->prepare('INSERT INTO users(full_name,email,password_hash,role,matric_number) VALUES(?,?,?,?,?)');
        $q->execute([$name, strtolower($email), password_hash($password, PASSWORD_DEFAULT), $role, $matric]);
    } catch (PDOException $e) {
        if ($e->getCode()==='23000') respond(['error'=>'That email or matric number is already registered'],409);
        throw $e;
    }
    $id=(int)$pdo->lastInsertId();
    session_regenerate_id(true);
    $_SESSION['csrf']=bin2hex(random_bytes(32));
    $_SESSION['user']=['id'=>$id,'full_name'=>$name,'email'=>strtolower($email),'role'=>$role,'account_status'=>'active'];
    logActivity($id,'Account registered','user',$id);
    respond(['user'=>$_SESSION['user'],'csrf'=>$_SESSION['csrf']],201);
}

if ($method === 'POST' && $path === '/api/login') {
    $data=body(); $email=filter_var(trim((string)($data['email']??'')),FILTER_VALIDATE_EMAIL);
    $q=$pdo->prepare('SELECT id,full_name,email,password_hash,role,account_status FROM users WHERE email=?');
    $q->execute([strtolower((string)$email)]); $user=$q->fetch();
    if (!$email || !$user || !password_verify((string)($data['password']??''),$user['password_hash'])) respond(['error'=>'Email or password is incorrect'],401);
    if ($user['account_status']!=='active') respond(['error'=>'This account is restricted'],403);
    session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32));
    unset($user['password_hash']); $_SESSION['user']=$user;
    logActivity((int)$user['id'],'User signed in','user',(int)$user['id']);
    respond(['user'=>$user,'csrf'=>$_SESSION['csrf']]);
}

if ($method === 'GET' && $path === '/api/me') {
    $user=currentUser(); if (!$user) respond(['user'=>null]); respond(['user'=>$user]);
}
if ($method === 'POST' && $path === '/api/logout') {
    requireUser(); requireCsrf(); $_SESSION=[]; session_destroy(); respond(['ok'=>true]);
}

if ($method === 'GET' && in_array($path,['/api/lost-items','/api/found-items'],true)) {
    $lost=$path==='/api/lost-items';
    $table=$lost?'lost_items':'found_items'; $status=$lost?'lost':'available'; $date=$lost?'date_lost':'date_found';
    $sql="SELECT i.id,i.item_name,i.description,i.location,i.{$date} AS item_date,i.status,c.category_name,u.full_name AS reporter FROM {$table} i JOIN categories c ON c.id=i.category_id JOIN users u ON u.id=i.user_id WHERE i.status IN (?,?) ORDER BY i.created_at DESC LIMIT 100";
    $q=$pdo->prepare($sql); $q->execute($lost?['lost','matched']:['available','claimed']); respond(['items'=>$q->fetchAll()]);
}

if ($method === 'POST' && in_array($path,['/api/lost-items','/api/found-items'],true)) {
    $user=requireUser(); requireCsrf(); $data=body();
    $lost=$path==='/api/lost-items'; $name=requiredText($data,'item_name',120);
    $description=requiredText($data,'description',3000); $location=requiredText($data,'location',160);
    $dateKey=$lost?'date_lost':'date_found'; $date=(string)($data[$dateKey]??'');
    $parsed=DateTime::createFromFormat('Y-m-d',$date);
    if (!$parsed || $parsed->format('Y-m-d')!==$date) respond(['error'=>"Invalid {$dateKey}"],422);
    $cat=$pdo->prepare('SELECT id FROM categories WHERE category_name=?'); $cat->execute([requiredText($data,'category',60)]); $category=$cat->fetchColumn();
    if (!$category) respond(['error'=>'Unknown category'],422);
    $table=$lost?'lost_items':'found_items';
    $q=$pdo->prepare("INSERT INTO {$table}(user_id,category_id,item_name,description,location,{$dateKey}) VALUES(?,?,?,?,?,?)");
    $q->execute([(int)$user['id'],(int)$category,$name,$description,$location,$date]); $id=(int)$pdo->lastInsertId();
    if (!$lost) createMatchesForFoundItem($pdo,$id);
    logActivity((int)$user['id'],$lost?'Lost item reported':'Found item reported',$lost?'lost_item':'found_item',$id);
    respond(['id'=>$id,'status'=>$lost?'lost':'available'],201);
}

if ($method === 'GET' && $path === '/api/matches') {
    $user=requireUser();
    $q=$pdo->prepare("SELECT m.id,m.match_score,m.status,m.created_at,l.item_name AS lost_name,l.location AS lost_location,f.item_name AS found_name,f.description AS found_description,f.location AS found_location,f.date_found,f.id AS found_item_id,u.full_name AS finder FROM matches m JOIN lost_items l ON l.id=m.lost_item_id JOIN found_items f ON f.id=m.found_item_id JOIN users u ON u.id=f.user_id WHERE l.user_id=? ORDER BY m.match_score DESC,m.created_at DESC");
    $q->execute([(int)$user['id']]); respond(['matches'=>$q->fetchAll()]);
}

if ($method === 'POST' && $path === '/api/claims') {
    $user=requireUser(); requireCsrf(); $data=body(); $matchId=filter_var($data['match_id']??null,FILTER_VALIDATE_INT);
    $details=requiredText($data,'verification_details',2000);
    if (!$matchId) respond(['error'=>'Invalid match id'],422);
    $q=$pdo->prepare('SELECT l.user_id,l.item_name FROM matches m JOIN lost_items l ON l.id=m.lost_item_id WHERE m.id=?');
    $q->execute([$matchId]); $match=$q->fetch();
    if (!$match || (int)$match['user_id']!==(int)$user['id']) respond(['error'=>'Match not found'],404);
    $q=$pdo->prepare('INSERT INTO claims(match_id,claimant_id,verification_details) VALUES(?,?,?)');
    $q->execute([$matchId,(int)$user['id'],$details]); $id=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO notifications(user_id,title,message,type) SELECT id,?,?,? FROM users WHERE role=\'admin\'')
        ->execute(['Ownership claim submitted','A claim for '.$match['item_name'].' needs review.','claim']);
    logActivity((int)$user['id'],'Ownership claim submitted','claim',$id);
    respond(['id'=>$id,'status'=>'pending'],201);
}

if ($method === 'GET' && $path === '/api/notifications') {
    $user=requireUser(); $q=$pdo->prepare('SELECT id,title,message,type,is_read,created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 100');
    $q->execute([(int)$user['id']]); respond(['notifications'=>$q->fetchAll()]);
}
if ($method === 'PATCH' && $path === '/api/notifications/read') {
    $user=requireUser(); requireCsrf(); $data=body();
    if (!empty($data['id'])) $q=$pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([(int)$data['id'],(int)$user['id']]);
    else $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([(int)$user['id']]);
    respond(['ok'=>true]);
}

if ($method === 'GET' && $path === '/api/messages') {
    $user=requireUser(); $q=$pdo->prepare('SELECT m.id,m.match_id,m.sender_id,m.receiver_id,m.message,m.created_at,s.full_name AS sender_name,r.full_name AS receiver_name FROM messages m JOIN users s ON s.id=m.sender_id JOIN users r ON r.id=m.receiver_id WHERE m.sender_id=? OR m.receiver_id=? ORDER BY m.created_at DESC LIMIT 200');
    $q->execute([(int)$user['id'],(int)$user['id']]); respond(['messages'=>$q->fetchAll()]);
}
if ($method === 'POST' && $path === '/api/messages') {
    $user=requireUser(); requireCsrf(); $data=body();
    $receiver=filter_var($data['receiver_id']??null,FILTER_VALIDATE_INT); $message=requiredText($data,'message',2000);
    if (!$receiver || $receiver===(int)$user['id']) respond(['error'=>'Invalid recipient'],422);
    $matchId=isset($data['match_id'])?(int)$data['match_id']:null;
    if ($matchId) {
        $q=$pdo->prepare('SELECT COUNT(*) FROM matches m JOIN lost_items l ON l.id=m.lost_item_id JOIN found_items f ON f.id=m.found_item_id WHERE m.id=? AND (l.user_id=? OR f.user_id=?)');
        $q->execute([$matchId,(int)$user['id'],(int)$user['id']]);
        if (!(int)$q->fetchColumn()) respond(['error'=>'Match not found'],404);
    }
    $pdo->prepare('INSERT INTO messages(sender_id,receiver_id,match_id,message) VALUES(?,?,?,?)')->execute([(int)$user['id'],$receiver,$matchId,$message]);
    respond(['ok'=>true],201);
}

if ($method === 'GET' && $path === '/api/admin/reports') {
    requireRole(['admin']);
    $q=$pdo->query("SELECT 'lost' AS kind,id,item_name,location,status,created_at FROM lost_items UNION ALL SELECT 'found' AS kind,id,item_name,location,status,created_at FROM found_items ORDER BY created_at DESC LIMIT 200");
    respond(['reports'=>$q->fetchAll()]);
}
if ($method === 'GET' && $path === '/api/admin/users') {
    requireRole(['admin']); $q=$pdo->query('SELECT id,full_name,email,role,matric_number,account_status,created_at FROM users ORDER BY created_at DESC LIMIT 200');
    respond(['users'=>$q->fetchAll()]);
}
if ($method === 'GET' && $path === '/api/admin/claims') {
    requireRole(['admin']);
    $q=$pdo->query('SELECT c.id,c.verification_details,c.status,c.created_at,u.full_name AS claimant,l.item_name,f.item_name AS found_item,m.match_score FROM claims c JOIN users u ON u.id=c.claimant_id JOIN matches m ON m.id=c.match_id JOIN lost_items l ON l.id=m.lost_item_id JOIN found_items f ON f.id=m.found_item_id ORDER BY c.created_at DESC');
    respond(['claims'=>$q->fetchAll()]);
}
if ($method === 'PATCH' && preg_match('#^/api/admin/claims/(\d+)$#',$path,$m)) {
    $admin=requireRole(['admin']); requireCsrf(); $status=(string)(body()['status']??'');
    if (!in_array($status,['approved','rejected'],true)) respond(['error'=>'Invalid claim status'],422);
    $q=$pdo->prepare('SELECT c.id,c.claimant_id,m.lost_item_id,m.found_item_id,l.item_name FROM claims c JOIN matches m ON m.id=c.match_id JOIN lost_items l ON l.id=m.lost_item_id WHERE c.id=?');
    $q->execute([(int)$m[1]]); $claim=$q->fetch(); if(!$claim) respond(['error'=>'Claim not found'],404);
    $pdo->prepare('UPDATE claims SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?')->execute([$status,(int)$admin['id'],(int)$m[1]]);
    if ($status==='approved') {
        $pdo->prepare("UPDATE lost_items SET status='resolved' WHERE id=?")->execute([(int)$claim['lost_item_id']]);
        $pdo->prepare("UPDATE found_items SET status='returned' WHERE id=?")->execute([(int)$claim['found_item_id']]);
    }
    $pdo->prepare('INSERT INTO notifications(user_id,title,message,type) VALUES(?,?,?,?)')->execute([(int)$claim['claimant_id'],'Claim '.ucfirst($status),'Your claim for '.$claim['item_name'].' was '.$status.'.','claim']);
    logActivity((int)$admin['id'],'Claim '.$status,'claim',(int)$m[1]); respond(['ok'=>true]);
}

respond(['error'=>'Endpoint not found'],404);

