<?php
	// --- index.php ---
	session_start();
	$is_logged_in = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo htmlspecialchars($_SESSION['theme'] ?? 'dark'); ?>">
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>Secure Vault</title>
		
		<link rel="stylesheet" href="style.css">
		
		<script src="https://cdn.tailwindcss.com"></script>
		<script>
            tailwind.config = { darkMode: 'class' }
		</script>
		
		<link rel="preconnect" href="https://fonts.googleapis.com">
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
		<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
	</head>
	
	<body
		class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 flex flex-col min-h-screen"
		data-view-mode="<?php echo htmlspecialchars($_SESSION['view_mode'] ?? 'grid'); ?>"
		data-user-id="<?php echo htmlspecialchars($_SESSION['user_id'] ?? ''); ?>"
	>
		
		<?php if ($is_logged_in): ?>
			<div class="flex flex-1 relative overflow-hidden">
				
				<div id="sidebar-overlay" class="hidden fixed inset-0 bg-black/30 z-30 md:hidden"></div>
				
				<aside id="sidebar" class="absolute top-0 left-0 h-full w-64 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 flex flex-col z-40
                                      transform -translate-x-full transition-transform md:relative md:translate-x-0">
					<div class="p-4 flex flex-col flex-1 overflow-y-auto">
						<h1 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-6">Secure Vault</h1>
						<button id="show-upload-modal-btn" class="w-full inline-flex justify-center items-center gap-2 bg-blue-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-blue-700 transition mb-4"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L6.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd" /></svg><span>Upload File</span></button>
						<button id="new-folder-btn" class="w-full inline-flex justify-center items-center gap-2 bg-yellow-500 text-white font-semibold py-2 px-4 rounded-md hover:bg-yellow-600 transition"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h5l2 2h5a2 2 0 012 2v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" /></svg><span>New Folder</span></button>
						
						<div class="mt-auto">
							<div class="text-xs text-gray-600 dark:text-gray-400 mb-1">Storage</div>
							<div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
								<div id="usage-bar" class="bg-blue-600 h-2.5 rounded-full" style="width: 0%"></div>
							</div>
							<div id="usage-text" class="text-xs text-gray-500 dark:text-gray-400 mt-1 text-center">Loading...</div>
						</div>
					</div>
				</aside>
				
				<div class="flex-1 flex flex-col overflow-hidden">
					<header class="bg-white/70 dark:bg-gray-800/70 backdrop-blur-lg border-b border-gray-200 dark:border-gray-700 z-10">
						<div class="px-4 sm:px-6 lg:px-8">
							<div class="flex items-center justify-between h-16">
								<button id="sidebar-toggle-btn" class="p-2 rounded-md text-gray-500 hover:bg-gray-200 dark:hover:bg-gray-700 md:hidden">
									<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
								</button>
								<nav class="text-sm text-gray-600 dark:text-gray-400 flex-1 min-w-0">
									<ol id="breadcrumb-container" class="list-none p-0 inline-flex items-center space-x-2 whitespace-nowrap overflow-x-auto"></ol>
								</nav>
								<div class="flex items-center gap-4 pl-4">
                        <span id="selection-actions" class="hidden items-center gap-2">
                            <span id="selection-count" class="text-sm font-medium"></span>
                            <button id="delete-selected-btn" title="Delete Selected" class="inline-flex items-center gap-2 bg-red-600 text-white font-semibold py-2 px-3 text-sm rounded-md hover:bg-red-700 transition"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg><span class="hidden sm:inline">Delete</span></button>
                        </span>
									<span class="text-sm text-gray-500 dark:text-gray-400 hidden sm:block"><?php echo htmlspecialchars($_SESSION['user_email']); ?></span>
									<button id="theme-toggle-btn" class="p-1.5 rounded-md text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700"><svg id="theme-icon-light" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" /></svg><svg id="theme-icon-dark" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" /></svg></button>
									<div class="flex items-center p-1 bg-gray-100 dark:bg-gray-900/50 rounded-lg">
										<button id="view-list-btn" title="List View" class="view-btn p-1.5 rounded-md text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd" /></svg></button>
										<button id="view-grid-btn" title="Grid View" class="view-btn p-1.5 rounded-md text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM11 5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zM11 13a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" /></svg></button>
									</div>
									<button id="generate-all-btn" title="Generate all missing previews" class="hidden sm:inline-flex items-center gap-2 bg-indigo-600 text-white font-semibold py-2 px-3 text-sm rounded-md hover:bg-indigo-700 transition"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg><span class="hidden sm:inline">Generate Previews</span></button>
									<button id="logout-btn" class="text-sm font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">Logout</button>
								</div>
							</div>
						</div>
					</header>
					
					<main id="main-content" class="flex-1 p-4 sm:p-6 lg:p-8 overflow-y-auto">
						<div id="list-view" class="bg-white dark:bg-gray-800 rounded-lg shadow-md border border-gray-200 dark:border-gray-700">
							<div id="file-list-container"></div>
						</div>
						<div id="grid-view" class="hidden"><div id="file-grid-container" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4"></div></div>
						<div id="no-files-message" class="text-center py-12 text-gray-500 hidden"><p>This folder is empty.</p></div>
					</main>
				</div>
			</div>
			
			<footer class="bg-gray-100 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 p-4 text-center text-sm text-gray-500 dark:text-gray-400">
				<p>Client-Side Encryption Vault - Your files are secured in the browser.</p>
			</footer>
			
			<div id="upload-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4"><div class="fixed inset-0 modal-backdrop"></div><div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-lg z-10"><div class="p-6 border-b dark:border-gray-700 flex justify-between items-center"><h3 class="text-xl font-semibold">Upload Files</h3><button id="close-upload-modal-btn" class="text-gray-400 hover:text-gray-600">&times;</button></div><div class="p-6"><div id="drag-drop-area" class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-8 text-center cursor-pointer hover:border-blue-500 dark:hover:border-blue-400 hover:bg-blue-50 dark:hover:bg-gray-700 transition"><input type="file" id="file-input" multiple class="hidden"><svg xmlns="http://www.w3.org/2000/svg" class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" /></svg><p class="mt-4 text-gray-600 dark:text-gray-400">Drag & drop files here, or <span class="font-semibold text-blue-600 dark:text-blue-400">click to browse</span></p></div><div id="upload-progress-list" class="mt-4 space-y-3"></div></div><div class="p-6 bg-gray-50 dark:bg-gray-900/50 border-t dark:border-gray-700 rounded-b-lg text-right"><button id="encrypt-btn" class="bg-blue-600 text-white font-semibold py-2 px-5 rounded-md hover:bg-blue-700 disabled:bg-gray-400 disabled:cursor-not-allowed">Upload</button></div></div></div>
			<div id="new-folder-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4"><div class="fixed inset-0 modal-backdrop"></div><div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-md z-10"><form id="new-folder-form"><div class="p-6 border-b dark:border-gray-700 flex justify-between items-center"><h3 class="text-xl font-semibold">Create New Folder</h3><button type="button" id="close-folder-modal-btn" class="text-gray-400 hover:text-gray-600">&times;</button></div><div class="p-6"><label for="folder-name-input" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Folder Name</label><input type="text" id="folder-name-input" class="block w-full px-3 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500" required></div><div class="p-6 bg-gray-50 dark:bg-gray-900/50 border-t dark:border-gray-700 rounded-b-lg text-right"><button type="submit" class="bg-blue-600 text-white font-semibold py-2 px-5 rounded-md hover:bg-blue-700">Create Folder</button></div></form></div></div>
			<div id="lightbox-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4"><div id="lightbox-backdrop" class="fixed inset-0 lightbox-backdrop"></div><button id="lightbox-close" class="absolute top-2 right-4 text-white text-4xl z-50 opacity-80 hover:opacity-100">&times;</button><div id="lightbox-content" class="relative z-50 max-w-4xl w-full max-h-[90vh] flex items-center justify-center"><div id="lightbox-spinner" class="spinner" style="width: 50px; height: 50px; border-width: 4px;"></div></div></div>
			<div id="delete-confirm-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4"><div class="fixed inset-0 modal-backdrop"></div><div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-md z-10"><div class="p-6 text-center"><svg xmlns="http://www.w3.org/2000/svg" class="mx-auto h-12 w-12 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg><h3 class="mt-2 text-xl font-semibold">Delete Items?</h3><p id="delete-modal-text" class="mt-2 text-sm text-gray-500 dark:text-gray-400">Are you sure? This action cannot be undone.</p></div><div class="p-4 bg-gray-50 dark:bg-gray-900/50 border-t dark:border-gray-700 rounded-b-lg flex justify-end gap-3"><button type="button" id="cancel-delete-btn" class="bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-gray-200 font-semibold py-2 px-4 rounded-md hover:bg-gray-300 dark:hover:bg-gray-500">Cancel</button><button type="button" id="confirm-delete-btn" class="bg-red-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-red-700">Delete</button></div></div></div>
			<div id="toast-container"></div>
			<div id="context-menu" class="hidden absolute z-30 bg-white dark:bg-gray-800 rounded-md shadow-lg border dark:border-gray-700 text-sm py-1"></div>
		
		<?php else: ?>
			<div class="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-gray-900 px-4">
				<div class="w-full max-w-md">
					<h2 class="text-3xl font-bold text-center text-gray-900 dark:text-gray-100 mb-6">Secure Vault</h2>
					<div id="login-view">
						<div class="bg-white dark:bg-gray-800 p-8 rounded-lg shadow-md border border-gray-200 dark:border-gray-700">
							<h3 class="text-xl font-semibold mb-4 text-center">Login</h3>
							<form id="login-form" class="space-y-4">
								<div><label for="login-email" class="sr-only">Email:</label><input type="email" name="email" id="login-email" placeholder="Email Address" class="mt-1 block w-full px-3 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500" required></div>
								<div><label for="login-password" class="sr-only">Password:</label><input type="password" name="password" id="login-password" placeholder="Password" class="mt-1 block w-full px-3 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500" required></div>
								<p id="login-error" class="text-sm text-red-600 hidden"></p>
								<button type="submit" class="w-full flex items-center justify-center bg-blue-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">Login</button>
							</form>
						</div>
						<p class="mt-4 text-center text-sm text-gray-600 dark:text-gray-400">Don't have an account? <a href="#" id="show-register" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Sign up</a></p>
					</div>
					<div id="register-view" class="hidden">
						<div class="bg-white dark:bg-gray-800 p-8 rounded-lg shadow-md border border-gray-200 dark:border-gray-700">
							<h3 class="text-xl font-semibold mb-4 text-center">Create Account</h3>
							<form id="register-form" class="space-y-4">
								<div><label for="register-email" class="sr-only">Email:</label><input type="email" name="email" id="register-email" placeholder="Email Address" class="mt-1 block w-full px-3 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500" required></div>
								<div><label for="register-password" class="sr-only">Password:</label><input type="password" name="password" id="register-password" placeholder="Password" class="mt-1 block w-full px-3 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500" required></div>
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
		
		<script src="app.js"></script>
	</body>
</html>