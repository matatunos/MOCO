<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'database.php';

// Initialize database
$db = initDatabase();

// JWT Functions
function createToken($userId) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode(['user_id' => $userId, 'exp' => time() + (24 * 60 * 60)]);
    
    $base64UrlHeader = base64UrlEncode($header);
    $base64UrlPayload = base64UrlEncode($payload);
    
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, 'your-secret-key', true);
    $base64UrlSignature = base64UrlEncode($signature);
    
    return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
}

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

function verifyToken($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    
    $header = base64UrlDecode($parts[0]);
    $payload = base64UrlDecode($parts[1]);
    $signatureProvided = $parts[2];
    
    $signature = hash_hmac('sha256', $parts[0] . "." . $parts[1], 'your-secret-key', true);
    $base64UrlSignature = base64UrlEncode($signature);
    
    if ($base64UrlSignature !== $signatureProvided) return false;
    
    $payloadData = json_decode($payload, true);
    if ($payloadData['exp'] < time()) return false;
    
    return $payloadData['user_id'];
}

function getUserIdFromAuth() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return verifyToken($matches[1]);
    }
    
    return false;
}

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['PATH_INFO'] ?? '/';

// Register
if ($method === 'POST' && $path === '/auth/register') {
    // Check if registration is allowed
    if (getSetting('allow_registration') !== '1') {
        http_response_code(403);
        echo json_encode(['error' => 'Registration is currently disabled']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data['username'] || !$data['email'] || !$data['password']) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }
    
    try {
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        
        // Check if this is the first user (will be admin)
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM users');
        $stmt->execute();
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $role = $count == 0 ? 'admin' : 'user';
        
        $stmt = $db->prepare('INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([$data['username'], $data['email'], $hashedPassword, $role]);
        
        $userId = $db->lastInsertId();
        
        // Create root folder
        $stmt = $db->prepare('INSERT INTO folders (name, path, user_id) VALUES (?, ?, ?)');
        $stmt->execute(['root', '/', $userId]);
        
        echo json_encode([
            'message' => 'User registered successfully',
            'user' => [
                'id' => $userId,
                'username' => $data['username'],
                'email' => $data['email'],
                'role' => $role
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Username or email already exists']);
    }
    exit;
}

// Login
if ($method === 'POST' && $path === '/auth/login') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data['username'] || !$data['password']) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing username or password']);
        exit;
    }
    
    $stmt = $db->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$data['username']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || !password_verify($data['password'], $user['password'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid username or password']);
        exit;
    }
    
    if (!$user['is_active']) {
        http_response_code(403);
        echo json_encode(['error' => 'Account is disabled']);
        exit;
    }
    
    $token = createToken($user['id']);
    
    echo json_encode([
        'access_token' => $token,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role']
        ]
    ]);
    exit;
}

// Get current user
if ($method === 'GET' && $path === '/auth/me') {
    $userId = getUserIdFromAuth();
    
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $stmt = $db->prepare('SELECT id, username, email, role, created_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode($user);
    exit;
}

// List files
if ($method === 'GET' && $path === '/files') {
    $userId = getUserIdFromAuth();
    
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $folderId = $_GET['folder_id'] ?? null;
    
    // Get files
    if ($folderId) {
        $stmt = $db->prepare('SELECT * FROM files WHERE user_id = ? AND folder_id = ?');
        $stmt->execute([$userId, $folderId]);
    } else {
        $stmt = $db->prepare('SELECT * FROM files WHERE user_id = ? AND folder_id IS NULL');
        $stmt->execute([$userId]);
    }
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get folders
    if ($folderId) {
        $stmt = $db->prepare('SELECT * FROM folders WHERE user_id = ? AND parent_id = ?');
        $stmt->execute([$userId, $folderId]);
    } else {
        $stmt = $db->prepare('SELECT * FROM folders WHERE user_id = ? AND parent_id IS NULL AND name != "root"');
        $stmt->execute([$userId]);
    }
    $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format response
    $items = [];
    foreach ($folders as $folder) {
        $items[] = [
            'id' => $folder['id'],
            'name' => $folder['name'],
            'type' => 'folder',
            'created_at' => $folder['created_at']
        ];
    }
    
    foreach ($files as $file) {
        $items[] = [
            'id' => $file['id'],
            'name' => $file['name'],
            'original_name' => $file['original_name'],
            'size' => $file['size'],
            'mime_type' => $file['mime_type'],
            'type' => 'file',
            'created_at' => $file['created_at']
        ];
    }
    
    echo json_encode($items);
    exit;
}

// Upload file
if ($method === 'POST' && $path === '/files/upload') {
    $userId = getUserIdFromAuth();
    
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    if (!isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No file provided']);
        exit;
    }
    
    $file = $_FILES['file'];
    $folderId = $_POST['folder_id'] ?? null;
    
    $uploadDir = __DIR__ . '/../uploads/' . $userId . '/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $filename = basename($file['name']);
    $counter = 1;
    $finalFilename = $filename;
    
    while (file_exists($uploadDir . $finalFilename)) {
        $info = pathinfo($filename);
        $finalFilename = $info['filename'] . '_' . $counter . '.' . $info['extension'];
        $counter++;
    }
    
    $targetPath = $uploadDir . $finalFilename;
    
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $stmt = $db->prepare('INSERT INTO files (name, original_name, size, mime_type, storage_path, folder_id, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $finalFilename,
            $filename,
            $file['size'],
            $file['type'],
            $targetPath,
            $folderId,
            $userId
        ]);
        
        echo json_encode([
            'message' => 'File uploaded successfully',
            'file' => [
                'id' => $db->lastInsertId(),
                'name' => $finalFilename,
                'original_name' => $filename,
                'size' => $file['size'],
                'type' => 'file'
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to upload file']);
    }
    exit;
}

// Download file
if ($method === 'GET' && preg_match('/^\/files\/(\d+)\/download$/', $path, $matches)) {
    $userId = getUserIdFromAuth();
    
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $fileId = $matches[1];
    
    $stmt = $db->prepare('SELECT * FROM files WHERE id = ? AND user_id = ?');
    $stmt->execute([$fileId, $userId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$file) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        exit;
    }
    
    if (file_exists($file['storage_path'])) {
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Disposition: attachment; filename="' . $file['original_name'] . '"');
        header('Content-Length: ' . filesize($file['storage_path']));
        readfile($file['storage_path']);
        exit;
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'File not found on disk']);
        exit;
    }
}

// Delete file
if ($method === 'DELETE' && preg_match('/^\/files\/(\d+)$/', $path, $matches)) {
    $userId = getUserIdFromAuth();
    
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $fileId = $matches[1];
    
    $stmt = $db->prepare('SELECT * FROM files WHERE id = ? AND user_id = ?');
    $stmt->execute([$fileId, $userId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$file) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        exit;
    }
    
    if (file_exists($file['storage_path'])) {
        unlink($file['storage_path']);
    }
    
    $stmt = $db->prepare('DELETE FROM files WHERE id = ?');
    $stmt->execute([$fileId]);
    
    echo json_encode(['message' => 'File deleted successfully']);
    exit;
}

// Create folder
if ($method === 'POST' && $path === '/folders') {
    $userId = getUserIdFromAuth();
    
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data['name']) {
        http_response_code(400);
        echo json_encode(['error' => 'Folder name is required']);
        exit;
    }
    
    $parentId = $data['parent_id'] ?? null;
    $name = $data['name'];
    
    if ($parentId) {
        $stmt = $db->prepare('SELECT path FROM folders WHERE id = ? AND user_id = ?');
        $stmt->execute([$parentId, $userId]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);
        $path = $parent['path'] . $name . '/';
    } else {
        $path = '/' . $name . '/';
    }
    
    $stmt = $db->prepare('INSERT INTO folders (name, path, parent_id, user_id) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $path, $parentId, $userId]);
    
    echo json_encode([
        'message' => 'Folder created successfully',
        'folder' => [
            'id' => $db->lastInsertId(),
            'name' => $name,
            'path' => $path,
            'type' => 'folder'
        ]
    ]);
    exit;
}

// Delete folder
if ($method === 'DELETE' && preg_match('/^\/folders\/(\d+)$/', $path, $matches)) {
    $userId = getUserIdFromAuth();
    
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $folderId = $matches[1];
    
    $stmt = $db->prepare('SELECT * FROM folders WHERE id = ? AND user_id = ?');
    $stmt->execute([$folderId, $userId]);
    $folder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$folder) {
        http_response_code(404);
        echo json_encode(['error' => 'Folder not found']);
        exit;
    }
    
    if ($folder['name'] === 'root') {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot delete root folder']);
        exit;
    }
    
    // Delete all files in folder
    $stmt = $db->prepare('SELECT * FROM files WHERE folder_id = ?');
    $stmt->execute([$folderId]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($files as $file) {
        if (file_exists($file['storage_path'])) {
            unlink($file['storage_path']);
        }
    }
    
    $stmt = $db->prepare('DELETE FROM files WHERE folder_id = ?');
    $stmt->execute([$folderId]);
    
    $stmt = $db->prepare('DELETE FROM folders WHERE id = ?');
    $stmt->execute([$folderId]);
    
    echo json_encode(['message' => 'Folder deleted successfully']);
    exit;
}

// ADMIN ENDPOINTS

// Get all users (admin only)
if ($method === 'GET' && $path === '/admin/users') {
    $userId = getUserIdFromAuth();
    
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $stmt = $db->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }
    
    $stmt = $db->query('SELECT id, username, email, role, is_active, created_at FROM users ORDER BY created_at DESC');
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($users);
    exit;
}

// Update user (admin only)
if ($method === 'PUT' && preg_match('/^\/admin\/users\/(\d+)$/', $path, $matches)) {
    $userId = getUserIdFromAuth();
    
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $stmt = $db->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }
    
    $targetUserId = $matches[1];
    $data = json_decode(file_get_contents('php://input'), true);
    
    $updates = [];
    $params = [];
    
    if (isset($data['role'])) {
        $updates[] = 'role = ?';
        $params[] = $data['role'];
    }
    
    if (isset($data['is_active'])) {
        $updates[] = 'is_active = ?';
        $params[] = $data['is_active'];
    }
    
    if (count($updates) > 0) {
        $params[] = $targetUserId;
        $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    }
    
    echo json_encode(['message' => 'User updated successfully']);
    exit;
}

// Delete user (admin only)
if ($method === 'DELETE' && preg_match('/^\/admin\/users\/(\d+)$/', $path, $matches)) {
    $userId = getUserIdFromAuth();
    
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $stmt = $db->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }
    
    $targetUserId = $matches[1];
    
    // Don't allow deleting yourself
    if ($targetUserId == $userId) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot delete your own account']);
        exit;
    }
    
    // Delete user's files
    $stmt = $db->prepare('SELECT * FROM files WHERE user_id = ?');
    $stmt->execute([$targetUserId]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($files as $file) {
        if (file_exists($file['storage_path'])) {
            unlink($file['storage_path']);
        }
    }
    
    // Delete user's data
    $db->prepare('DELETE FROM files WHERE user_id = ?')->execute([$targetUserId]);
    $db->prepare('DELETE FROM folders WHERE user_id = ?')->execute([$targetUserId]);
    $db->prepare('DELETE FROM shared_folders WHERE shared_with_user_id = ?')->execute([$targetUserId]);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$targetUserId]);
    
    echo json_encode(['message' => 'User deleted successfully']);
    exit;
}

// Get settings (admin only)
if ($method === 'GET' && $path === '/admin/settings') {
    $userId = getUserIdFromAuth();
    
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $stmt = $db->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }
    
    $stmt = $db->query('SELECT * FROM settings');
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result = [];
    foreach ($settings as $setting) {
        $result[$setting['key']] = $setting['value'];
    }
    
    echo json_encode($result);
    exit;
}

// Update settings (admin only)
if ($method === 'POST' && $path === '/admin/settings') {
    $userId = getUserIdFromAuth();
    
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $stmt = $db->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    foreach ($data as $key => $value) {
        setSetting($key, $value);
    }
    
    echo json_encode(['message' => 'Settings updated successfully']);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Endpoint not found']);
