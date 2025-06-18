<?php
// --- PART 0: SESSION & DATABASE SETUP ---
session_start();

$db_file = 'vault.db';
$db_is_new = !file_exists($db_file);
$pdo = new PDO('sqlite:' . $db_file);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($db_is_new) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

$is_logged_in = isset($_SESSION['user_id']);
$upload_dir = 'uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}
$base_path = realpath($upload_dir);

// --- HELPER FUNCTIONS ---
function format_bytes($bytes, $precision = 2) { if ($bytes === false) return 'N/A'; $units = ['B', 'KB', 'MB', 'GB', 'TB']; $bytes = max($bytes, 0); $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); $pow = min($pow, count($units) - 1); $bytes /= (1 << (10 * $pow)); return round($bytes, $precision) . ' ' . $units[$pow]; }
function get_media_type($filename) { $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']; $video_exts = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'wmv', 'mkv']; $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION)); if (in_array($extension, $image_exts)) return 'image'; if (in_array($extension, $video_exts)) return 'video'; return 'file'; }
function sanitize_path($path, $base_path) { $path = urldecode($path); $path = preg_replace('~/{2,}~', '/', $path); $path = trim($path, '/'); $path_parts = explode('/', $path); $safe_parts = []; foreach ($path_parts as $part) { if ($part !== '.' && $part !== '..') { $safe_parts[] = $part; } } $safe_path_suffix = implode('/', $safe_parts); $full_path = $base_path . '/' . $safe_path_suffix; $real_base = realpath($base_path); $real_full_path = realpath($full_path); if ($real_full_path === false) { $real_full_path = $full_path; } if (strpos($real_full_path, $real_base) !== 0) { return false; } return '/' . $safe_path_suffix; }

// --- API & AUTH ACTIONS ---
$action = $_REQUEST['action'] ?? null;
if ($action) {

    // --- Public Actions (Login/Register) ---
    if ($action === 'register') {
        header('Content-Type: application/json');
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        if (empty($email) || empty($password) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Invalid input.']); exit();
        }
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            http_response_code(409); echo json_encode(['status' => 'error', 'message' => 'Email already registered.']); exit();
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (email, password_hash) VALUES (?, ?)");
        if ($stmt->execute([$email, $hash])) {
            $user_id = $pdo->lastInsertId();
            if (!is_dir($upload_dir . $user_id)) {
                mkdir($upload_dir . $user_id, 0755, true);
            }
            echo json_encode(['status' => 'success', 'message' => 'Registration successful. Please log in.']);
        } else { http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Registration failed.']); }
        exit();
    }

    if ($action === 'login') {
        header('Content-Type: application/json');
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $email;
            echo json_encode(['status' => 'success']);
        } else { http_response_code(401); echo json_encode(['status' => 'error', 'message' => 'Invalid email or password.']); }
        exit();
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . basename(__FILE__));
        exit();
    }

    // --- Logged-in Actions ---
    if (!$is_logged_in) {
        http_response_code(401); echo json_encode(['status' => 'error', 'message' => 'Authentication required.']); exit();
    }

    header('Content-Type: application/json');
    $user_upload_dir = $upload_dir . $_SESSION['user_id'] . '/';
    if (!is_dir($user_upload_dir)) { mkdir($user_upload_dir, 0755, true); }
    $user_base_path = realpath($user_upload_dir);

    $current_path_unsafe = isset($_REQUEST['path']) ? $_REQUEST['path'] : '/';
    $current_path = sanitize_path($current_path_unsafe, $user_base_path);
    if ($current_path === false) { http_response_code(403); echo json_encode(['status' => 'error', 'message' => 'Forbidden path.']); exit(); }
    $full_current_path = $user_base_path . $current_path;

    switch ($action) {
        case 'set_theme': if (isset($_GET['theme']) && in_array($_GET['theme'], ['light', 'dark'])) { $_SESSION['theme'] = $_GET['theme']; echo json_encode(['status' => 'success']); } else { http_response_code(400); echo json_encode(['status' => 'error']); } break;
        case 'set_view': if (isset($_GET['view']) && in_array($_GET['view'], ['list', 'grid'])) { $_SESSION['view_mode'] = $_GET['view']; echo json_encode(['status' => 'success']); } else { http_response_code(400); echo json_encode(['status' => 'error']); } break;

        case 'list':
            $items = [];
            if (!is_dir($full_current_path)) { echo json_encode(['status' => 'success', 'items' => []]); exit(); }
            $dir_contents = scandir($full_current_path);
            foreach ($dir_contents as $item) {
                if ($item === '.' || $item === '..') continue;
                $item_path = $full_current_path . '/' . $item;
                if (is_dir($item_path)) {
                    $items[] = ['name' => htmlspecialchars($item), 'type' => 'folder'];
                } else if (pathinfo($item, PATHINFO_EXTENSION) === 'enc') {
                    $original_filename = preg_replace('/\.enc$/', '', $item);
                    $items[] = [ 'encrypted_name' => htmlspecialchars($item), 'original_name' => htmlspecialchars($original_filename), 'size' => format_bytes(filesize($item_path)), 'media_type' => get_media_type($original_filename), 'type' => 'file' ];
                }
            }
            echo json_encode(['status' => 'success', 'items' => $items]);
            break;

        case 'upload':
             if (!is_dir($full_current_path)) { http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Upload path is not a valid directory.']); exit(); }
            $filename = isset($_GET['filename']) ? basename($_GET['filename']) : 'encrypted-file';
            $original_sanitized_name = preg_replace("/[^a-zA-Z0-9._-]/", '', $filename);
            $destination = $full_current_path . '/' . $original_sanitized_name . '.enc';
            if (file_exists($destination)) {
                $name_part = pathinfo($original_sanitized_name, PATHINFO_FILENAME);
                $ext_part = pathinfo($original_sanitized_name, PATHINFO_EXTENSION);
                $new_filename = $name_part . '-' . time() . ($ext_part ? '.' . $ext_part : '');
                $destination = $full_current_path . '/' . $new_filename . '.enc';
            }
            $encrypted_data = file_get_contents('php://input');
            if ($encrypted_data && file_put_contents($destination, $encrypted_data)) {
                echo json_encode(['status' => 'success', 'file' => basename($destination)]);
            } else { http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Failed to save file.']); }
            break;

        case 'create_folder':
            $folder_name = isset($_POST['name']) ? basename($_POST['name']) : '';
            if (empty($folder_name) || strpbrk($folder_name, "\\/?%*:|\"<>")) { http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Invalid folder name.']); exit(); }
            $new_folder_path = $full_current_path . '/' . $folder_name;
            if (!is_dir($new_folder_path) && mkdir($new_folder_path, 0755, true)) {
                echo json_encode(['status' => 'success', 'message' => 'Folder created.']);
            } else { http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Could not create folder.']);}
            break;

        case 'delete_item':
            $item_name = isset($_POST['name']) ? basename($_POST['name']) : '';
            if (empty($item_name)) { http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Item name not provided.']); exit(); }
            $item_path = $full_current_path . '/' . $item_name;
            if (!file_exists($item_path)) { http_response_code(404); echo json_encode(['status' => 'error', 'message' => 'Item not found.']); exit(); }

            if (is_dir($item_path)) {
                if (count(scandir($item_path)) > 2) { http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Folder is not empty.']);
                } else if (rmdir($item_path)) { echo json_encode(['status' => 'success']);
                } else { http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Failed to delete folder.']); }
            } else {
                if (unlink($item_path)) { echo json_encode(['status' => 'success']);
                } else { http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Failed to delete file.']); }
            }
            break;
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo $_SESSION['theme'] ?? 'dark'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Vault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = { darkMode: 'class' }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .progress-bar, .file-container.deleting { transition: all 0.3s ease; }
        .modal-backdrop { background-color: rgba(0,0,0,0.5); }
        .lightbox-backdrop { background-color: rgba(0,0,0,0.8); }
        .dragover { border-color: #3b82f6; }
        .view-btn.active { background-color: #e0e7ff; }
        .dark .view-btn.active { background-color: #312e81; }
        .file-container:hover .action-btn, .folder-item:hover .action-btn { opacity: 1; }
        .action-btn { opacity: 0; transition: opacity 0.2s; }
        .spinner { border: 2px solid #f3f3f3; border-top: 2px solid #3b82f6; border-radius: 50%; width: 14px; height: 14px; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .toast { position: fixed; bottom: 1.5rem; right: 1.5rem; transform: translateY(200%); opacity: 0; transition: all 0.3s ease-in-out; z-index: 100; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .file-container.deleting { opacity: 0; transform: scale(0.9); }
        .delete-btn.confirming {
            opacity: 1 !important;
            background-color: white;
            color: #ef4444;
            border-radius: 9999px;
            font-size: 0.75rem;
            line-height: 1rem;
            padding: 0.25rem 0.5rem;
            box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        }
        .dark .delete-btn.confirming {
            background-color: #374151;
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 flex flex-col min-h-screen">

    <?php if ($is_logged_in): ?>
    <!-- /////////// DASHBOARD VIEW /////////// -->
    <div class="flex-grow">
        <header class="bg-white/70 dark:bg-gray-800/70 backdrop-blur-lg sticky top-0 border-b border-gray-200 dark:border-gray-700 z-20">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">Secure Vault</h1>
                    <div class="flex items-center gap-4">
                         <span class="text-sm text-gray-500 dark:text-gray-400 hidden sm:block"><?php echo htmlspecialchars($_SESSION['user_email']); ?></span>
                        <button id="theme-toggle-btn" class="p-1.5 rounded-md text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700">
                           <svg id="theme-icon-light" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                           <svg id="theme-icon-dark" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" /></svg>
                        </button>
                        <div class="flex items-center p-1 bg-gray-100 dark:bg-gray-900/50 rounded-lg">
                            <button id="view-list-btn" title="List View" class="view-btn p-1.5 rounded-md text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd" /></svg></button>
                            <button id="view-grid-btn" title="Grid View" class="view-btn p-1.5 rounded-md text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM11 5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zM11 13a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" /></svg></button>
                        </div>
                        <button id="logout-btn" class="text-sm font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">Logout</button>
                    </div>
                </div>
            </div>
        </header>

        <main class="container mx-auto p-4 sm:p-6 lg:p-8">
            <div class="flex flex-wrap gap-2 items-center justify-between mb-4">
                <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">My Files</h2>
                <div class="flex items-center gap-2">
                     <button id="generate-all-btn" class="hidden inline-flex items-center gap-2 bg-gray-600 text-white font-semibold py-2 px-3 sm:px-4 text-sm rounded-md hover:bg-gray-700 transition"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14" /></svg><span class="hidden sm:inline">Generate All Previews</span></button>
                     <button id="new-folder-btn" class="inline-flex items-center gap-2 bg-yellow-500 text-white font-semibold py-2 px-3 sm:px-4 text-sm rounded-md hover:bg-yellow-600 transition"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h5l2 2h5a2 2 0 012 2v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" /></svg><span class="hidden sm:inline">New Folder</span></button>
                    <button id="show-upload-modal-btn" class="inline-flex items-center gap-2 bg-blue-600 text-white font-semibold py-2 px-3 sm:px-4 text-sm rounded-md hover:bg-blue-700 transition"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L6.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd" /></svg><span class="hidden sm:inline">Upload File</span></button>
                </div>
            </div>

            <nav class="bg-gray-100 dark:bg-gray-800 p-2 rounded-md mb-6 text-sm text-gray-600 dark:text-gray-400">
                <ol id="breadcrumb-container" class="list-none p-0 inline-flex items-center space-x-2"></ol>
            </nav>

            <!-- List View Container -->
            <div id="list-view" class="bg-white dark:bg-gray-800 rounded-lg shadow-md border border-gray-200 dark:border-gray-700">
                <div class="hidden sm:flex items-center px-6 py-3 border-b border-gray-200 dark:border-gray-700 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider"><div class="w-2/5">Name</div><div class="w-1/5">Size</div><div class="w-2/5 text-right">Actions</div></div>
                <div id="file-list-container"></div>
            </div>
            <!-- Grid View Container -->
            <div id="grid-view" class="hidden"><div id="file-grid-container" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4"></div></div>
            <div id="no-files-message" class="text-center py-12 text-gray-500 hidden"></div>
        </main>
    </div>
    <footer class="bg-gray-100 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 p-4 text-center text-sm text-gray-500 dark:text-gray-400">
        <p>Client-Side Encryption Vault - Your files are secured in the browser.</p>
    </footer>

    <!-- MODALS -->
    <div id="upload-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4"><div class="fixed inset-0 modal-backdrop"></div><div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-lg z-10"><div class="p-6 border-b dark:border-gray-700 flex justify-between items-center"><h3 class="text-xl font-semibold">Upload Files</h3><button id="close-upload-modal-btn" class="text-gray-400 hover:text-gray-600">&times;</button></div><div class="p-6"><div id="drag-drop-area" class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-8 text-center cursor-pointer hover:border-blue-500 dark:hover:border-blue-400 hover:bg-blue-50 dark:hover:bg-gray-700 transition"><input type="file" id="file-input" multiple class="hidden"><svg xmlns="http://www.w3.org/2000/svg" class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" /></svg><p class="mt-4 text-gray-600 dark:text-gray-400">Drag & drop files here, or <span class="font-semibold text-blue-600 dark:text-blue-400">click to browse</span></p></div><div id="upload-progress-list" class="mt-4 space-y-3"></div></div><div class="p-6 bg-gray-50 dark:bg-gray-900/50 border-t dark:border-gray-700 rounded-b-lg text-right"><button id="encrypt-btn" class="bg-blue-600 text-white font-semibold py-2 px-5 rounded-md hover:bg-blue-700 disabled:bg-gray-400 disabled:cursor-not-allowed">Upload</button></div></div></div>
    <div id="new-folder-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4"><div class="fixed inset-0 modal-backdrop"></div><div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-md z-10"><form id="new-folder-form"><div class="p-6 border-b dark:border-gray-700 flex justify-between items-center"><h3 class="text-xl font-semibold">Create New Folder</h3><button type="button" id="close-folder-modal-btn" class="text-gray-400 hover:text-gray-600">&times;</button></div><div class="p-6"><label for="folder-name-input" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Folder Name</label><input type="text" id="folder-name-input" class="block w-full px-3 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500" required></div><div class="p-6 bg-gray-50 dark:bg-gray-900/50 border-t dark:border-gray-700 rounded-b-lg text-right"><button type="submit" class="bg-blue-600 text-white font-semibold py-2 px-5 rounded-md hover:bg-blue-700">Create Folder</button></div></form></div></div>
    <div id="lightbox-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4"><div id="lightbox-backdrop" class="fixed inset-0 lightbox-backdrop"></div><button id="lightbox-close" class="absolute top-2 right-4 text-white text-4xl z-50 opacity-80 hover:opacity-100">&times;</button><div id="lightbox-content" class="relative z-50 max-w-4xl w-full max-h-[90vh] flex items-center justify-center"><div id="lightbox-spinner" class="spinner" style="width: 50px; height: 50px; border-width: 4px;"></div></div></div>
    <div id="toast-container"></div>

    <?php else: ?>
    <!-- /////////// AUTH VIEW /////////// -->
     <div class="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-gray-900 px-4">
        <div class="w-full max-w-md">
            <h2 class="text-3xl font-bold text-center text-gray-900 dark:text-gray-100 mb-6">Secure Vault</h2>

            <!-- Login Form -->
            <div id="login-view">
                <div class="bg-white dark:bg-gray-800 p-8 rounded-lg shadow-md border border-gray-200 dark:border-gray-700">
                    <h3 class="text-xl font-semibold mb-4 text-center">Login</h3>
                    <form id="login-form" class="space-y-4">
                        <div>
                            <label for="login-email" class="sr-only">Email:</label>
                            <input type="email" name="email" id="login-email" placeholder="Email Address" class="mt-1 block w-full px-3 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                        </div>
                        <div>
                            <label for="login-password" class="sr-only">Password:</label>
                            <input type="password" name="password" id="login-password" placeholder="Password" class="mt-1 block w-full px-3 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                        </div>
                        <p id="login-error" class="text-sm text-red-600 hidden"></p>
                        <button type="submit" class="w-full flex items-center justify-center bg-blue-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">Login</button>
                    </form>
                </div>
                <p class="mt-4 text-center text-sm text-gray-600 dark:text-gray-400">Don't have an account? <a href="#" id="show-register" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Sign up</a></p>
            </div>

            <!-- Registration Form -->
            <div id="register-view" class="hidden">
                 <div class="bg-white dark:bg-gray-800 p-8 rounded-lg shadow-md border border-gray-200 dark:border-gray-700">
                    <h3 class="text-xl font-semibold mb-4 text-center">Create Account</h3>
                    <form id="register-form" class="space-y-4">
                         <div>
                            <label for="register-email" class="sr-only">Email:</label>
                            <input type="email" name="email" id="register-email" placeholder="Email Address" class="mt-1 block w-full px-3 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                        </div>
                        <div>
                            <label for="register-password" class="sr-only">Password:</label>
                            <input type="password" name="password" id="register-password" placeholder="Password" class="mt-1 block w-full px-3 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                        </div>
                        <p id="register-error" class="text-sm text-red-600 hidden"></p>
                        <button type="submit" class="w-full flex items-center justify-center bg-blue-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">Create Account</button>
                    </form>
                </div>
                <p class="mt-4 text-center text-sm text-gray-600 dark:text-gray-400">Already have an account? <a href="#" id="show-login" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Log in</a></p>
            </div>
        </div>
    </div>
    <div id="toast-container"></div>
    <?php endif; ?>

<script>
const showToast = (text, type = 'info') => {
    const container = document.getElementById('toast-container');
    if (!container) return;
    const toast = document.createElement('div');
    const typeClasses = {
        success: 'bg-green-500', error: 'bg-red-500', info: 'bg-blue-500'
    };
    toast.className = `toast p-4 rounded-lg text-white shadow-lg ${typeClasses[type] || typeClasses.info}`;
    toast.textContent = text;
    container.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => {
        toast.classList.remove('show');
        toast.addEventListener('transitionend', () => toast.remove());
    }, 4000);
};

<?php if ($is_logged_in): ?>

document.addEventListener('DOMContentLoaded', () => {
    let encryptionPassword = sessionStorage.getItem('vaultPassword');
    const getEncryptionPassword = async () => {
        if (!encryptionPassword) {
            encryptionPassword = prompt("For security, please re-enter your vault password to enable cryptographic operations for this session.");
            if (encryptionPassword) {
                sessionStorage.setItem('vaultPassword', encryptionPassword);
            }
        }
        if (!encryptionPassword) {
            showToast('Password required for encryption/decryption.', 'error');
            return null;
        }
        return encryptionPassword;
    };

    const SALT_LENGTH = 16, IV_LENGTH = 12, PBKDF2_ITERATIONS = 100000;

    let currentPath = '/';
    let currentView = '<?php echo $_SESSION['view_mode'] ?? 'grid'; ?>';
    let currentItems = [];

    // --- DOM Elements ---
    const breadcrumbContainer = document.getElementById('breadcrumb-container');
    const fileListContainer = document.getElementById('file-list-container');
    const fileGridContainer = document.getElementById('file-grid-container');
    const listView = document.getElementById('list-view');
    const gridView = document.getElementById('grid-view');
    const viewListBtn = document.getElementById('view-list-btn');
    const viewGridBtn = document.getElementById('view-grid-btn');
    const generateAllBtn = document.getElementById('generate-all-btn');
    const noFilesMessage = document.getElementById('no-files-message');
    const newFolderBtn = document.getElementById('new-folder-btn');
    const themeToggleBtn = document.getElementById('theme-toggle-btn');
    const themeIconLight = document.getElementById('theme-icon-light');
    const themeIconDark = document.getElementById('theme-icon-dark');
    const logoutBtn = document.getElementById('logout-btn');

    // Modal elements
    const uploadModal = document.getElementById('upload-modal');
    const showUploadModalBtn = document.getElementById('show-upload-modal-btn');
    const closeUploadModalBtn = document.getElementById('close-upload-modal-btn');
    const dragDropArea = document.getElementById('drag-drop-area');
    const fileInput = document.getElementById('file-input');
    const encryptBtn = document.getElementById('encrypt-btn');
    const uploadProgressList = document.getElementById('upload-progress-list');
    const newFolderModal = document.getElementById('new-folder-modal');
    const newFolderForm = document.getElementById('new-folder-form');
    const folderNameInput = document.getElementById('folder-name-input');
    const closeFolderModalBtn = document.getElementById('close-folder-modal-btn');

    // Lightbox elements
    const lightboxModal = document.getElementById('lightbox-modal');
    const lightboxBackdrop = document.getElementById('lightbox-backdrop');
    const lightboxCloseBtn = document.getElementById('lightbox-close');
    const lightboxContent = document.getElementById('lightbox-content');
    const lightboxSpinner = document.getElementById('lightbox-spinner');

    let filesToUpload = [];
    const thumbnailCache = {}; // In-memory cache for generated thumbnails

    // --- Core App Logic ---
    function render() {
        renderBreadcrumbs();
        fileListContainer.innerHTML = '';
        fileGridContainer.innerHTML = '';

        const folders = currentItems.filter(item => item.type === 'folder');
        const files = currentItems.filter(item => item.type === 'file');
        const sortedItems = [...folders.sort((a,b) => a.name.localeCompare(b.name)), ...files.sort((a,b) => a.original_name.localeCompare(b.original_name))];

        if (sortedItems.length === 0) {
            noFilesMessage.classList.remove('hidden');
            listView.classList.add('hidden');
            gridView.classList.add('hidden');
        } else {
            noFilesMessage.classList.add('hidden');
            if (currentView === 'list') {
                listView.classList.remove('hidden');
                gridView.classList.add('hidden');
                sortedItems.forEach(item => fileListContainer.innerHTML += createListItemHTML(item));
            } else { // Grid view
                listView.classList.add('hidden');
                gridView.classList.remove('hidden');
                sortedItems.forEach(item => fileGridContainer.innerHTML += createGridItemHTML(item));
            }
        }
        updateViewControls();
    }

    function createListItemHTML(item) {
        const id = (item.type === 'file' ? item.encrypted_name : item.name).replace(/[^a-zA-Z0-9]/g, '');
        if (item.type === 'folder') {
            return `<div id="item-container-${id}" class="file-container folder-item flex items-center px-6 py-4 border-b border-gray-200 dark:border-gray-700 last:border-b-0 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer" data-name="${item.name}">
                        <div class="w-full sm:w-4/5 flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-yellow-500 mr-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg>
                            <span class="font-medium text-gray-700 dark:text-gray-300">${item.name}</span>
                        </div>
                        <div class="w-1/2 sm:w-1/5 text-right">
                             <button data-name="${item.name}" data-type="folder" class="delete-btn action-btn text-gray-400 hover:text-red-500 p-1 rounded-full" title="Delete Folder"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button>
                        </div>
                   </div>`;
        }
        return `<div id="item-container-${id}" class="file-container file-item flex flex-wrap items-center px-6 py-4 border-b border-gray-200 dark:border-gray-700 last:border-b-0 hover:bg-gray-50 dark:hover:bg-gray-700/50 relative">
                    <div class="w-full sm:w-2/5 flex items-center mb-2 sm:mb-0"><svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-gray-400 mr-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg><span class="font-mono text-sm text-gray-700 dark:text-gray-300 truncate" title="${item.original_name}">${item.original_name}</span></div>
                    <div class="w-1/2 sm:w-1/5 text-sm text-gray-500 dark:text-gray-400"><span class="sm:hidden font-semibold mr-2">Size:</span>${item.size}</div>
                    <div class="w-1/2 sm:w-2/5 text-right flex items-center justify-end gap-2">
                        <button data-name="${item.encrypted_name}" data-type="file" class="delete-btn action-btn text-gray-400 hover:text-red-500 p-1 rounded-full" title="Delete File"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button>
                        <button data-filename="${item.encrypted_name}" class="download-btn inline-flex items-center gap-2 bg-green-600 text-white font-semibold py-1.5 px-4 rounded-md hover:bg-green-700 transition text-sm"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd" /></svg><span>Download</span></button>
                    </div>
                    <div class="w-full mt-2 hidden download-progress-container"><div class="w-full bg-gray-200 rounded-full h-1.5"><div class="progress-bar bg-green-600 h-1.5 rounded-full" style="width: 0%"></div></div></div>
                </div>`;
    }

    function createGridItemHTML(item) {
        const id = (item.type === 'file' ? item.encrypted_name : item.name).replace(/[^a-zA-Z0-9]/g, '');
        if (item.type === 'folder') {
            return `<div id="item-container-${id}" class="file-container folder-item bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 overflow-hidden aspect-square flex flex-col relative hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer" data-name="${item.name}">
                        <div class="absolute top-1 right-1 flex items-center gap-1 z-10">
                            <button data-name="${item.name}" data-type="folder" class="delete-btn action-btn text-gray-600 bg-white/50 dark:text-gray-300 dark:bg-gray-900/50 hover:bg-white dark:hover:bg-gray-600 p-1 rounded-full" title="Delete Folder"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button>
                        </div>
                        <div class="flex-grow flex items-center justify-center text-yellow-500"><svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg></div>
                        <div class="p-2 text-center border-t dark:border-gray-700"><p class="text-sm font-medium truncate text-gray-700 dark:text-gray-300" title="${item.name}">${item.name}</p></div>
                   </div>`;
        }

        const canPreview = item.media_type === 'image' || item.media_type === 'video';
        let previewContent;
        if (thumbnailCache[item.encrypted_name]) {
             previewContent = `<div class="lightbox-trigger flex items-center justify-center h-full p-1 cursor-pointer" data-filename="${item.encrypted_name}" data-type="${item.media_type}"><img src="${thumbnailCache[item.encrypted_name]}" alt="Preview for ${item.original_name}" class="w-full h-full object-cover pointer-events-none"></div>`;
        } else {
             previewContent = `<div class="flex flex-col items-center justify-center h-full bg-gray-100 dark:bg-gray-700/50 text-gray-400">
                    ${item.media_type === 'image' ? '<svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14" /></svg>' : ''}
                    ${item.media_type === 'video' ? '<svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>' : ''}
                    ${item.media_type === 'file' ? '<svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>' : ''}
                    <div class="preview-action-container mt-2">
                        ${canPreview ? `<button class="generate-preview-btn text-xs bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 px-2 py-1 rounded-md shadow flex items-center gap-1" data-filename="${item.encrypted_name}" data-type="${item.media_type}">Preview</button>`: ''}
                    </div>
                </div>`;
        }

        return `<div id="item-container-${id}" class="file-container bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 overflow-hidden aspect-square flex flex-col relative">
                 <div class="absolute top-1 right-1 flex items-center gap-1 z-10">
                    <button data-filename="${item.encrypted_name}" class="download-btn action-btn text-gray-600 bg-white/50 dark:text-gray-300 dark:bg-gray-900/50 hover:bg-white dark:hover:bg-gray-600 p-1 rounded-full" title="Download File"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg></button>
                    <button data-name="${item.encrypted_name}" data-type="file" class="delete-btn action-btn text-gray-600 bg-white/50 dark:text-gray-300 dark:bg-gray-900/50 hover:bg-white dark:hover:bg-gray-600 p-1 rounded-full" title="Delete File"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button>
                 </div>
                <div class="flex-grow relative preview-container min-h-0" data-filename="${item.encrypted_name}" data-type="${item.media_type}">${previewContent}</div>
                <div class="p-2 text-center border-t dark:border-gray-700"><p class="text-xs font-mono truncate text-gray-700 dark:text-gray-300" title="${item.original_name}">${item.original_name}</p><p class="text-xs text-gray-500 dark:text-gray-400">${item.size}</p></div>
                <div class="w-full mt-1 px-2 pb-2 hidden download-progress-container"><div class="w-full bg-gray-200 rounded-full h-1"><div class="progress-bar bg-green-600 h-1 rounded-full" style="width: 0%"></div></div></div>
            </div>`;
    }

    function renderBreadcrumbs() {
        breadcrumbContainer.innerHTML = '';
        const pathParts = currentPath.split('/').filter(p => p);
        let pathSoFar = '';

        const homeCrumb = document.createElement('li');
        homeCrumb.innerHTML = `<a href="#" data-path="/" class="text-blue-600 dark:text-blue-400 hover:underline">Home</a>`;
        breadcrumbContainer.appendChild(homeCrumb);

        pathParts.forEach(part => {
            pathSoFar += `/${part}`;
            const crumb = document.createElement('li');
            crumb.innerHTML = `<span class="mx-2">/</span><a href="#" data-path="${pathSoFar}" class="text-blue-600 dark:text-blue-400 hover:underline">${part}</a>`;
            breadcrumbContainer.appendChild(crumb);
        });
    }

    // --- Navigation ---
    async function navigateTo(path) {
        currentPath = path;
        try {
            const response = await fetch(`?action=list&path=${encodeURIComponent(path)}`);
            if (!response.ok) throw new Error('Network response was not ok.');
            const data = await response.json();
            if (data.status === 'success') {
                currentItems = data.items;
                render();
            } else { throw new Error(data.message || 'Failed to list items.'); }
        } catch (error) {
            showToast(`Error: ${error.message}`, 'error');
        }
    }

    function updateViewControls() {
        if (currentView === 'list') {
            viewListBtn.classList.add('active');
            viewGridBtn.classList.remove('active');
            generateAllBtn.classList.add('hidden');
        } else {
            viewGridBtn.classList.add('active');
            viewListBtn.classList.remove('active');
            generateAllBtn.classList.remove('hidden');
        }
    }

    // View toggling logic
    viewListBtn.addEventListener('click', () => {
        currentView = 'list';
        render();
        fetch('?action=set_view&view=list').catch(err => console.error("Could not save view preference.", err));
    });
    viewGridBtn.addEventListener('click', () => {
        currentView = 'grid';
        render();
        fetch('?action=set_view&view=grid').catch(err => console.error("Could not save view preference.", err));
    });

    // --- Modal & Upload Logic ---
    showUploadModalBtn.addEventListener('click', () => uploadModal.classList.remove('hidden'));
    closeUploadModalBtn.addEventListener('click', () => { uploadModal.classList.add('hidden'); uploadProgressList.innerHTML = ''; filesToUpload = []; });
    dragDropArea.addEventListener('click', () => fileInput.click());
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eName => dragDropArea.addEventListener(eName, e => {e.preventDefault(); e.stopPropagation();}, false));
    ['dragenter', 'dragover'].forEach(eName => dragDropArea.addEventListener(eName, () => dragDropArea.classList.add('dragover')));
    ['dragleave', 'drop'].forEach(eName => dragDropArea.addEventListener(eName, () => dragDropArea.classList.remove('dragover')));
    dragDropArea.addEventListener('drop', e => handleFiles(e.dataTransfer.files));
    fileInput.addEventListener('change', e => handleFiles(e.target.files));

    function handleFiles(files) {
        filesToUpload = [...files];
        uploadProgressList.innerHTML = '';
        if (filesToUpload.length > 0) {
            filesToUpload.forEach(file => {
                uploadProgressList.innerHTML += `<div id="progress-for-${file.name.replace(/[^a-zA-Z0-9]/g, '')}" class="text-sm"><div class="flex justify-between items-center"><p class="truncate text-gray-700 dark:text-gray-300 w-3/5" title="${file.name}">${file.name}</p><p class="text-gray-500 dark:text-gray-400 status-text">Waiting...</p></div><div class="w-full bg-gray-200 dark:bg-gray-600 rounded-full h-1.5 mt-1"><div class="progress-bar bg-blue-600 h-1.5 rounded-full" style="width: 0%"></div></div></div>`;
            });
        }
    }

    encryptBtn.addEventListener('click', async () => {
        if (filesToUpload.length === 0) { alert('Please select files to upload.'); return; }
        const password = await getEncryptionPassword();
        if (!password) return;

        encryptBtn.disabled = true;
        let allOk = true;

        for (const file of filesToUpload) {
            const saneName = file.name.replace(/[^a-zA-Z0-9]/g, '');
            const progressCont = document.getElementById(`progress-for-${saneName}`);
            const statusBar = progressCont.querySelector('.progress-bar');
            const statusText = progressCont.querySelector('.status-text');
            try {
                statusText.textContent = 'Encrypting...';
                const fileBuffer = await file.arrayBuffer();
                const salt = crypto.getRandomValues(new Uint8Array(SALT_LENGTH));
                const iv = crypto.getRandomValues(new Uint8Array(IV_LENGTH));
                const key = await deriveKey(password, salt);
                const encryptedContent = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, fileBuffer);
                const encryptedPackage = new Uint8Array(SALT_LENGTH + IV_LENGTH + encryptedContent.byteLength);
                encryptedPackage.set(salt, 0); encryptedPackage.set(iv, SALT_LENGTH); encryptedPackage.set(new Uint8Array(encryptedContent), SALT_LENGTH + IV_LENGTH);
                statusText.textContent = 'Uploading...';
                await uploadFile(file.name, currentPath, encryptedPackage, (p) => { statusBar.style.width = `${p}%`; });
                statusText.textContent = 'Complete!'; statusBar.classList.replace('bg-blue-600', 'bg-green-600');
            } catch (error) {
                console.error(`Failed on ${file.name}:`, error); statusText.textContent = `Error`; statusBar.classList.replace('bg-blue-600', 'bg-red-600'); allOk = false; break;
            }
        }

        showToast('Upload process finished.', allOk ? 'success' : 'error');

        setTimeout(() => {
            closeUploadModalBtn.click();
            navigateTo(currentPath);
        }, 1500);
    });

    function uploadFile(filename, path, data, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            const url = `?action=upload&path=${encodeURIComponent(path)}&filename=${encodeURIComponent(filename)}`;
            xhr.open('POST', url, true);
            xhr.setRequestHeader('Content-Type', 'application/octet-stream');
            xhr.upload.onprogress = (event) => { if (event.lengthComputable) onProgress((event.loaded / event.total) * 100); };
            xhr.onload = () => { if (xhr.status >= 200 && xhr.status < 300) resolve(xhr.response); else reject(new Error(`Server error: ${xhr.status}`)); };
            xhr.onerror = () => reject(new Error('Network error.'));
            xhr.send(data);
        });
    }

    // --- Core Crypto & Decryption Logic ---
    async function deriveKey(password, salt) {
        const passwordBuffer = new TextEncoder().encode(password);
        const baseKey = await crypto.subtle.importKey('raw', passwordBuffer, { name: 'PBKDF2' }, false, ['deriveKey']);
        return crypto.subtle.deriveKey({ name: 'PBKDF2', salt, iterations: PBKDF2_ITERATIONS, hash: 'SHA-256' }, baseKey, { name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);
    }

    async function fetchAndDecrypt(filename, onProgress) {
        const password = await getEncryptionPassword();
        if (!password) throw new Error("Password not provided.");

        const fullFilePath = `uploads/<?php echo $_SESSION['user_id']; ?>${currentPath}/${filename}`.replace(/\/+/g, '/');
        const response = await fetch(fullFilePath);
        if (!response.ok) throw new Error(`File not found on server (HTTP ${response.status}).`);
        const contentLength = +response.headers.get('Content-Length'); let receivedLength = 0; const chunks = [];
        const reader = response.body.getReader();
        while (true) {
            const { done, value } = await reader.read(); if (done) break;
            chunks.push(value); receivedLength += value.length;
            if (contentLength && onProgress) onProgress((receivedLength / contentLength) * 100);
        }
        let encryptedPackage = new Uint8Array(receivedLength); let position = 0;
        for (const chunk of chunks) { encryptedPackage.set(chunk, position); position += chunk.length; }
        const salt = encryptedPackage.slice(0, SALT_LENGTH); const iv = encryptedPackage.slice(SALT_LENGTH, SALT_LENGTH + IV_LENGTH); const ciphertext = encryptedPackage.slice(SALT_LENGTH + IV_LENGTH);
        const key = await deriveKey(password, salt);
        try {
            const decryptedBuffer = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ciphertext);
            return decryptedBuffer;
        } catch (e) { throw new Error('Decryption failed. Check password or file integrity.'); }
    }

    function triggerDownload(decryptedBuffer, filename) {
        const blob = new Blob([decryptedBuffer]);
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename.replace(/\.enc$/, '');
        document.body.appendChild(link); link.click(); document.body.removeChild(link);
        URL.revokeObjectURL(link.href);
    }

    async function generateThumbnail(blob, type) {
        return new Promise((resolve, reject) => {
            const objectURL = URL.createObjectURL(blob);
            if (type === 'image') {
                const img = new Image();
                img.onload = () => { URL.revokeObjectURL(objectURL); resolve(img.src); };
                img.onerror = reject;
                img.src = objectURL;
            } else if (type === 'video') {
                const video = document.createElement('video');
                const canvas = document.createElement('canvas');
                video.muted = true;
                video.playsinline = true;

                const onSeeked = () => {
                    video.removeEventListener('seeked', onSeeked);
                    // Use requestAnimationFrame to ensure the frame is painted before drawing
                    requestAnimationFrame(() => {
                        canvas.width = video.videoWidth; canvas.height = video.videoHeight;
                        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                        URL.revokeObjectURL(objectURL);
                        resolve(canvas.toDataURL('image/jpeg'));
                    });
                };

                video.addEventListener('seeked', onSeeked);
                video.addEventListener('error', (e) => reject(new Error('Video thumbnail generation error')));

                // For mobile, wait for canplay event before seeking
                video.addEventListener('canplay', () => {
                    video.currentTime = 1; // Seek to 1 second to grab a frame
                }, { once: true });

                video.src = objectURL;
            }
        });
    }

    function showLightbox() {
        lightboxContent.innerHTML = '';
        lightboxSpinner.classList.remove('hidden');
        lightboxModal.classList.remove('hidden');
    }

    function displayInLightbox(blob, type) {
        const objectURL = URL.createObjectURL(blob);
        lightboxContent.dataset.objectUrl = objectURL;
        let mediaElement;
        if (type === 'image') {
            mediaElement = document.createElement('img');
            mediaElement.src = objectURL; mediaElement.className = 'max-w-full max-h-[90vh] object-contain';
        } else if (type === 'video') {
            mediaElement = document.createElement('video');
            mediaElement.src = objectURL; mediaElement.controls = true; mediaElement.autoplay = true; mediaElement.className = 'max-w-full max-h-[90vh]';
        }
        if (mediaElement) { lightboxSpinner.classList.add('hidden'); lightboxContent.appendChild(mediaElement); }
    }

    function hideLightbox() {
        lightboxModal.classList.add('hidden');
        const objectUrl = lightboxContent.dataset.objectUrl;
        if (objectUrl) { URL.revokeObjectURL(objectUrl); delete lightboxContent.dataset.objectUrl; }
        lightboxContent.innerHTML = '';
    }

    // --- Refactored Event Handlers ---
    async function handleAction(actionBtn) {
        const isDownload = actionBtn.matches('.download-btn');
        const isPreview = actionBtn.matches('.generate-preview-btn');
        if (!isDownload && !isPreview) return;

        const { filename, type } = actionBtn.dataset;
        const fileItem = actionBtn.closest('.file-container');
        if (!fileItem) return;

        const progressContainer = fileItem.querySelector('.download-progress-container');
        const progressBar = progressContainer.querySelector('.progress-bar');

        actionBtn.disabled = true;
        if(isPreview) {
            actionBtn.parentElement.innerHTML = `<div class="spinner"></div>`;
        }
        if (progressContainer) {
            progressContainer.classList.remove('hidden');
            progressBar.style.width = '0%';
        }

        try {
            const decryptedBuffer = await fetchAndDecrypt(filename, (p) => { if (progressBar) progressBar.style.width = `${p}%`; });
            if (isDownload) {
                triggerDownload(decryptedBuffer, filename);
            } else if (isPreview) {
                const thumbDataUrl = await generateThumbnail(new Blob([decryptedBuffer]), type);
                thumbnailCache[filename] = thumbDataUrl;
                const previewContainer = fileItem.querySelector('.preview-container');
                previewContainer.innerHTML = `<div class="lightbox-trigger flex items-center justify-center h-full p-1 cursor-pointer" data-filename="${filename}" data-type="${type}"><img src="${thumbDataUrl}" alt="Preview" class="w-full h-full object-cover pointer-events-none"></div>`;
            }
        } catch (error) {
            showToast(`Action failed: ${error.message}`, 'error');
            if (progressBar) progressBar.classList.add('bg-red-500');
        } finally {
            if (actionBtn && !isDownload) { actionBtn.disabled = false; }
            if(isPreview) {
                const actionContainer = fileItem.querySelector('.preview-action-container');
                if (actionContainer) actionContainer.innerHTML = `<button class="generate-preview-btn text-xs bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 px-2 py-1 rounded-md shadow flex items-center gap-1" data-filename="${filename}" data-type="${type}">Preview</button>`;
            }
            if (progressContainer) {
                setTimeout(() => { progressContainer.classList.add('hidden'); progressBar.style.width = '0%';}, 3000);
            }
        }
    }

    async function handleDelete(deleteBtn) {
        const { name: itemName, type: itemType } = deleteBtn.dataset;
        if (deleteBtn.dataset.confirm === 'true') {
             try {
                const formData = new FormData();
                formData.append('action', 'delete_item');
                formData.append('name', itemName);
                formData.append('path', currentPath);
                const response = await fetch('?', { method: 'POST', body: formData });
                const result = await response.json();
                if (!response.ok) throw new Error(result.message || 'Failed to delete.');

                showToast(`${itemType === 'folder' ? 'Folder' : 'File'} deleted.`, 'success');
                const itemId = itemName.replace(/[^a-zA-Z0-9]/g, '');
                const elementToRemove = document.getElementById(`item-container-${itemId}`);
                if(elementToRemove) {
                    elementToRemove.classList.add('deleting');
                    elementToRemove.addEventListener('transitionend', () => navigateTo(currentPath));
                } else {
                    navigateTo(currentPath); // Fallback if transitionend doesn't fire
                }
            } catch (error) { showToast(`Error: ${error.message}`, 'error'); }
        } else {
            deleteBtn.dataset.confirm = 'true';
            deleteBtn.innerHTML = 'Confirm?';
            deleteBtn.className = 'delete-btn action-btn confirming';
            deleteBtn.onmouseleave = () => {
                deleteBtn.dataset.confirm = 'false';
                deleteBtn.className = 'delete-btn action-btn text-gray-600 bg-white/50 dark:text-gray-300 dark:bg-gray-900/50 hover:bg-white dark:hover:bg-gray-600 p-1 rounded-full';
                const icon = itemType === 'folder' ?
                    `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>` :
                    `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>`;
                deleteBtn.innerHTML = icon;
            };
        }
    }

    document.body.addEventListener('click', async (e) => {
        const target = e.target.closest('.download-btn, .generate-preview-btn, .delete-btn, .lightbox-trigger, .folder-item, #breadcrumb-container a');
        if (!target) return;

        e.preventDefault();

        if (target.matches('.download-btn, .generate-preview-btn')) {
            handleAction(target);
        } else if (target.matches('.delete-btn')) {
            handleDelete(target);
        } else if (target.matches('.lightbox-trigger')) {
            const { filename, type } = target.dataset;
            showLightbox();
            try {
                const decryptedBuffer = await fetchAndDecrypt(filename);
                const blob = new Blob([decryptedBuffer]);
                displayInLightbox(blob, type);
            } catch(err) { showToast(`Error loading file: ${err.message}`, 'error'); hideLightbox(); }
        } else if (target.matches('.folder-item')) {
            const folderName = target.dataset.name;
            const newPath = `${currentPath}/${folderName}`.replace(/\/+/g, '/');
            navigateTo(newPath);
        } else if (target.matches('#breadcrumb-container a')) {
            const path = target.dataset.path;
            navigateTo(path);
        }
    });

    generateAllBtn.addEventListener('click', async () => {
        generateAllBtn.disabled = true;
        generateAllBtn.querySelector('span').textContent = 'Generating...';
        const previewButtons = document.querySelectorAll('.generate-preview-btn');
        for (const btn of previewButtons) { if(!btn.disabled) { await btn.click(); } }
        generateAllBtn.disabled = false;
        generateAllBtn.querySelector('span').textContent = 'Generate All Previews';
    });

    newFolderBtn.addEventListener('click', () => {
        newFolderModal.classList.remove('hidden');
        folderNameInput.focus();
    });
    closeFolderModalBtn.addEventListener('click', () => newFolderModal.classList.add('hidden'));
    newFolderModal.querySelector('.modal-backdrop').addEventListener('click', () => newFolderModal.classList.add('hidden'));

    newFolderForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const folderName = folderNameInput.value.trim();
        if (folderName) {
            const formData = new FormData();
            formData.append('action', 'create_folder');
            formData.append('name', folderName);
            formData.append('path', currentPath);
            try {
                const response = await fetch('?', { method: 'POST', body: formData });
                const result = await response.json();
                if (!response.ok) throw new Error(result.message || 'Failed to create folder.');
                showToast(`Folder "${folderName}" created.`, 'success');
                newFolderModal.classList.add('hidden');
                folderNameInput.value = '';
                navigateTo(currentPath);
            } catch (error) { showToast(`Error: ${error.message}`, 'error'); }
        }
    });

    // --- Theme Toggle ---
    function updateThemeIcons(isDark) {
        if(isDark) {
            themeIconLight.classList.add('hidden');
            themeIconDark.classList.remove('hidden');
        } else {
            themeIconLight.classList.remove('hidden');
            themeIconDark.classList.add('hidden');
        }
    }

    themeToggleBtn.addEventListener('click', () => {
        const isDark = document.documentElement.classList.toggle('dark');
        updateThemeIcons(isDark);
        fetch(`?action=set_theme&theme=${isDark ? 'dark' : 'light'}`);
    });

    logoutBtn.addEventListener('click', () => {
        sessionStorage.removeItem('vaultPassword');
        window.location.href = '?action=logout';
    });

    // Lightbox close events
    lightboxCloseBtn.addEventListener('click', () => hideLightbox());
    lightboxBackdrop.addEventListener('click', () => hideLightbox());

    // --- Initial Render ---
    updateThemeIcons(document.documentElement.classList.contains('dark'));
    navigateTo(currentPath);
});

<?php else: ?>
// --- AUTHENTICATION SCRIPT ---
const showAuthToast = (text, type = 'info') => {
    const container = document.getElementById('toast-container');
    if (!container) return;
    const toast = document.createElement('div');
    const typeClasses = { success: 'bg-green-500', error: 'bg-red-500', info: 'bg-blue-500' };
    toast.className = `toast p-4 rounded-lg text-white shadow-lg ${typeClasses[type] || typeClasses.info}`;
    toast.textContent = text;
    container.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => {
        toast.classList.remove('show');
        toast.addEventListener('transitionend', () => toast.remove());
    }, 4000);
};

document.addEventListener('DOMContentLoaded', () => {
    const loginView = document.getElementById('login-view');
    const registerView = document.getElementById('register-view');
    const showRegisterBtn = document.getElementById('show-register');
    const showLoginBtn = document.getElementById('show-login');
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const loginError = document.getElementById('login-error');
    const registerError = document.getElementById('register-error');

    showRegisterBtn.addEventListener('click', (e) => {
        e.preventDefault();
        loginView.classList.add('hidden');
        registerView.classList.remove('hidden');
    });

    showLoginBtn.addEventListener('click', (e) => {
        e.preventDefault();
        registerView.classList.add('hidden');
        loginView.classList.remove('hidden');
    });

    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        loginError.classList.add('hidden');
        const formData = new FormData(loginForm);
        formData.append('action', 'login');

        try {
            const response = await fetch('?', { method: 'POST', body: formData });
            if (!response.ok) {
                 const result = await response.json().catch(() => ({ message: 'An unknown error occurred.' }));
                 throw new Error(result.message || 'Login failed.');
            }
            const result = await response.json();
            if (result.status === 'success') {
                sessionStorage.setItem('vaultPassword', formData.get('password'));
                window.location.reload();
            } else {
                 throw new Error(result.message || 'Login failed.');
            }
        } catch (error) {
            loginError.textContent = error.message;
            loginError.classList.remove('hidden');
        }
    });

    registerForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        registerError.classList.add('hidden');
        const formData = new FormData(registerForm);
        formData.append('action', 'register');

        try {
            const response = await fetch('?', { method: 'POST', body: formData });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Registration failed.');

            showAuthToast('Registration successful! Please log in.', 'success');
            showLoginBtn.click();
            registerForm.reset();

        } catch (error) {
            registerError.textContent = error.message;
            registerError.classList.remove('hidden');
        }
    });
});
<?php endif; ?>
</script>
</body>
</html>