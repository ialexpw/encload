<?php
// --- api.php ---
// Handles all backend actions and returns JSON.
	
	session_start();

// --- CONFIGURATION ---
	define('USER_QUOTA', 5 * 1024 * 1024 * 1024); // 5 GB in bytes

// --- DATABASE CONNECTION ---
	try {
		$pdo = new PDO('sqlite:vault.db');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	} catch (PDOException $e) {
		http_response_code(500);
		echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
		exit();
	}

// Ensure the users table exists
	$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
	
	$is_logged_in = isset($_SESSION['user_id']);
	$upload_dir = 'uploads/';
	if (!is_dir($upload_dir)) {
		mkdir($upload_dir, 0755, true);
	}

// --- HELPER FUNCTIONS ---
	function get_media_type($filename) {
		$image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
		$video_exts = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'wmv', 'mkv'];
		$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		if (in_array($extension, $image_exts)) return 'image';
		if (in_array($extension, $video_exts)) return 'video';
		return 'file';
	}
	
	function sanitize_path($path, $base_path) {
		$path = urldecode($path);
		$path = preg_replace('~/{2,}~', '/', $path);
		$path = trim($path, '/');
		$path_parts = explode('/', $path);
		$safe_parts = [];
		foreach ($path_parts as $part) {
			if ($part !== '.' && $part !== '..') {
				$safe_parts[] = $part;
			}
		}
		$safe_path_suffix = implode('/', $safe_parts);
		$full_path = $base_path . '/' . $safe_path_suffix;
		$real_base = realpath($base_path);
		$real_full_path = realpath($full_path);
		if ($real_full_path === false) {
			$real_full_path = $full_path;
		}
		if (strpos($real_full_path, $real_base) !== 0) {
			return false;
		}
		return '/' . $safe_path_suffix;
	}
	
	function get_directory_size($path) {
		if (!is_dir($path)) return 0;
		$total_size = 0;
		try {
			$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
			foreach ($files as $file) {
				if ($file->isFile()) {
					$total_size += $file->getSize();
				}
			}
		} catch (Exception $e) {
			// Ignore errors from unreadable directories
		}
		return $total_size;
	}

// --- ROUTER ---
	$action = $_REQUEST['action'] ?? '';
	header('Content-Type: application/json');
	
	switch ($action) {
		case 'register':
			// Registration logic
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
			} else {
				http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Registration failed.']);
			}
			break;
		
		case 'login':
			// Login logic
			$email = $_POST['email'] ?? '';
			$password = $_POST['password'] ?? '';
			$stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE email = ?");
			$stmt->execute([$email]);
			$user = $stmt->fetch(PDO::FETCH_ASSOC);
			if ($user && password_verify($password, $user['password_hash'])) {
				$_SESSION['user_id'] = $user['id'];
				$_SESSION['user_email'] = $email;
				echo json_encode(['status' => 'success']);
			} else {
				http_response_code(401); echo json_encode(['status' => 'error', 'message' => 'Invalid email or password.']);
			}
			break;
		
		case 'logout':
			// Logout logic - this one redirects, so it's a special case.
			header('Content-Type: text/html'); // Override JSON header
			$_SESSION = [];
			session_destroy();
			header('Location: index.php'); // Redirect to the main page
			break;
		
		// --- All other cases require login ---
		default:
			if (!$is_logged_in) {
				http_response_code(401);
				echo json_encode(['status' => 'error', 'message' => 'Authentication required.']);
				exit();
			}
			
			$user_upload_dir = $upload_dir . $_SESSION['user_id'] . '/';
			if (!is_dir($user_upload_dir)) { mkdir($user_upload_dir, 0755, true); }
			$user_base_path = realpath($user_upload_dir);
			
			$current_path = sanitize_path($_REQUEST['path'] ?? '/', $user_base_path);
			if ($current_path === false) {
				http_response_code(403);
				echo json_encode(['status' => 'error', 'message' => 'Forbidden path.']);
				exit();
			}
			$full_current_path = $user_base_path . $current_path;
			
			switch ($action) {
				case 'set_theme':
					if (isset($_GET['theme']) && in_array($_GET['theme'], ['light', 'dark'])) {
						$_SESSION['theme'] = $_GET['theme'];
						echo json_encode(['status' => 'success']);
					} else {
						http_response_code(400); echo json_encode(['status' => 'error']);
					}
					break;
				case 'set_view':
					if (isset($_GET['view']) && in_array($_GET['view'], ['list', 'grid'])) {
						$_SESSION['view_mode'] = $_GET['view'];
						echo json_encode(['status' => 'success']);
					} else {
						http_response_code(400); echo json_encode(['status' => 'error']);
					}
					break;
				case 'get_usage':
					echo json_encode(['status' => 'success', 'used' => get_directory_size($user_base_path), 'quota' => USER_QUOTA]);
					break;
				case 'list':
					$items = [];
					if (is_dir($full_current_path)) {
						$dir_contents = scandir($full_current_path);
						foreach ($dir_contents as $item) {
							if ($item === '.' || $item === '..') continue;
							$item_path = $full_current_path . '/' . $item;
							if (is_dir($item_path)) {
								$items[] = ['name' => htmlspecialchars($item), 'type' => 'folder'];
							} else if (pathinfo($item, PATHINFO_EXTENSION) === 'enc') {
								$original_filename = preg_replace('/\.enc$/', '', $item);
								$items[] = [
									'encrypted_name' => htmlspecialchars($item),
									'original_name' => htmlspecialchars($original_filename),
									'size' => filesize($item_path),
									'media_type' => get_media_type($original_filename),
									'type' => 'file'
								];
							}
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
					} else {
						http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Failed to save file.']);
					}
					break;
				case 'create_folder':
					$folder_name = isset($_POST['name']) ? basename($_POST['name']) : '';
					if (empty($folder_name) || strpbrk($folder_name, "\\/?%*:|\"<>")) { http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Invalid folder name.']); exit(); }
					$new_folder_path = $full_current_path . '/' . $folder_name;
					if (!is_dir($new_folder_path) && mkdir($new_folder_path, 0755, true)) {
						echo json_encode(['status' => 'success', 'message' => 'Folder created.']);
					} else {
						http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Could not create folder.']);
					}
					break;
				case 'delete_item':
					$items_to_delete = json_decode($_POST['items'] ?? '[]');
					if (empty($items_to_delete)) { http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'No items specified for deletion.']); exit(); }
					$errors = [];
					foreach($items_to_delete as $item) {
						$item_name = basename($item->name);
						$item_path = $full_current_path . '/' . $item_name;
						if (file_exists($item_path)) {
							if (is_dir($item_path)) {
								if (count(scandir($item_path)) > 2) {
									$errors[] = "Folder '{$item_name}' is not empty.";
								} else if (!rmdir($item_path)) {
									$errors[] = "Failed to delete folder '{$item_name}'.";
								}
							} else {
								if (!unlink($item_path)) {
									$errors[] = "Failed to delete file '{$item_name}'.";
								}
							}
						} else {
							$errors[] = "Item '{$item_name}' not found.";
						}
					}
					if (empty($errors)) {
						echo json_encode(['status' => 'success']);
					} else {
						http_response_code(500); echo json_encode(['status' => 'error', 'message' => implode(' ', $errors)]);
					}
					break;
				default:
					http_response_code(404);
					echo json_encode(['status' => 'error', 'message' => 'Action not found.']);
					break;
			}
			break;
	}
?>