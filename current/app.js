// app.js (Complete Version - Fixed)

const showToast = (text, type = 'info') => {
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

const formatBytes = (bytes, decimals = 2) => {
    if (!+bytes) return '0 Bytes';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
};

// Check if we are on the main dashboard page or the login page
if (document.getElementById('main-content')) {
    // --- SCRIPT FOR LOGGED-IN USERS (DASHBOARD) ---
    document.addEventListener('DOMContentLoaded', () => {
        // --- App State & Config ---
        let encryptionPassword = sessionStorage.getItem('vaultPassword');
        const SALT_LENGTH = 16, IV_LENGTH = 12, PBKDF2_ITERATIONS = 100000;
        let currentPath = '/';
        let currentView = document.body.dataset.viewMode || 'grid';
        let currentItems = [];
        let selectedItems = new Set();
        let sortKey = 'name';
        let sortDirection = 'asc';
        let filesToUpload = [];
        const thumbnailCache = {};
        let lastClickedIndex = -1;

        // --- DOM Elements ---
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        const breadcrumbContainer = document.getElementById('breadcrumb-container');
        const fileListContainer = document.getElementById('file-list-container');
        const fileGridContainer = document.getElementById('file-grid-container');
        const listView = document.getElementById('list-view');
        const gridView = document.getElementById('grid-view');
        const selectionActions = document.getElementById('selection-actions');
        const selectionCount = document.getElementById('selection-count');
        const uploadModal = document.getElementById('upload-modal');
        const newFolderModal = document.getElementById('new-folder-modal');
        const newFolderForm = document.getElementById('new-folder-form');
        const folderNameInput = document.getElementById('folder-name-input');
        const lightboxModal = document.getElementById('lightbox-modal');
        const lightboxContent = document.getElementById('lightbox-content');
        const lightboxSpinner = document.getElementById('lightbox-spinner');
        const dragDropArea = document.getElementById('drag-drop-area');
        const fileInput = document.getElementById('file-input');
        const deleteConfirmModal = document.getElementById('delete-confirm-modal');
        const confirmDeleteBtn = document.getElementById('confirm-delete-btn');
        const cancelDeleteBtn = document.getElementById('cancel-delete-btn');
        const deleteModalText = document.getElementById('delete-modal-text');

        // --- FUNCTION DEFINITIONS ---

        const toggleSidebar = () => {
            if (!sidebar || !sidebarOverlay) return;
            sidebar.classList.toggle('-translate-x-full');
            sidebarOverlay.classList.toggle('hidden');
        };

        // Note: The separate event listeners for the sidebar have been removed from here.
        // The logic is now correctly placed inside the main body event listener below.

        let itemsToDelete = [];
        function showDeleteConfirmationModal(items) {
            if (!deleteConfirmModal) { console.error('Delete confirmation modal not found.'); return; }
            itemsToDelete = items;
            if (itemsToDelete.length === 0) { showToast('No items selected for deletion.', 'error'); return; }
            const message = itemsToDelete.length > 1
                ? `Are you sure you want to delete these ${itemsToDelete.length} items? This cannot be undone.`
                : `Are you sure you want to delete "${itemsToDelete[0].original_name || itemsToDelete[0].name}"? This cannot be undone.`;
            deleteModalText.textContent = message;
            deleteConfirmModal.classList.remove('hidden');
        }

        async function performDeletion() {
            if (itemsToDelete.length === 0) return;
            try {
                const formData = new FormData();
                formData.append('action', 'delete_item');
                formData.append('path', currentPath);
                const itemsPayload = itemsToDelete.map(item => ({ name: item.encrypted_name || item.name }));
                formData.append('items', JSON.stringify(itemsPayload));
                const response = await fetch('api.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (!response.ok) throw new Error(result.message || 'Failed to delete.');
                showToast(`${itemsToDelete.length} item(s) deleted.`, 'success');
                selectedItems.clear();
                updateSelection();
            } catch (error) {
                showToast(`Error: ${error.message}`, 'error');
            } finally {
                if (deleteConfirmModal) deleteConfirmModal.classList.add('hidden');
                itemsToDelete = [];
                navigateTo(currentPath);
            }
        }

        const getEncryptionPassword = async () => {
            if (!encryptionPassword) {
                encryptionPassword = prompt("For security, please re-enter your vault password to enable cryptographic operations for this session.");
                if (encryptionPassword) sessionStorage.setItem('vaultPassword', encryptionPassword);
            }
            if (!encryptionPassword) {
                showToast('Password required for encryption/decryption.', 'error');
                return null;
            }
            return encryptionPassword;
        };

        function sortItems() {
            currentItems.sort((a, b) => {
                const valA = (sortKey === 'name') ? (a.original_name || a.name).toLowerCase() : a.size;
                const valB = (sortKey === 'name') ? (b.original_name || b.name).toLowerCase() : b.size;
                if (a.type === 'folder' && b.type === 'file') return -1;
                if (a.type === 'file' && b.type === 'folder') return 1;
                if (valA < valB) return sortDirection === 'asc' ? -1 : 1;
                if (valA > valB) return sortDirection === 'asc' ? 1 : -1;
                return 0;
            });
        }

        function render() {
            renderBreadcrumbs();
            sortItems();
            fileListContainer.innerHTML = '';
            fileGridContainer.innerHTML = '';
            const noFilesMessage = document.getElementById('no-files-message');
            if (currentItems.length === 0) {
                noFilesMessage.classList.remove('hidden');
                listView.classList.add('hidden');
                gridView.classList.add('hidden');
            } else {
                noFilesMessage.classList.add('hidden');
                if (currentView === 'list') {
                    listView.classList.remove('hidden');
                    gridView.classList.add('hidden');
                    currentItems.forEach(item => fileListContainer.innerHTML += createListItemHTML(item));
                } else {
                    listView.classList.add('hidden');
                    gridView.classList.remove('hidden');
                    currentItems.forEach(item => fileGridContainer.innerHTML += createGridItemHTML(item));
                }
            }
            updateViewControls();
            updateSelection();
        }

        function createListItemHTML(item) {
            const id = (item.type === 'file' ? item.encrypted_name : item.name).replace(/[^a-zA-Z0-9]/g, '');
            const isSelected = selectedItems.has(item);
            const selectedClass = isSelected ? 'selected' : '';
            if (item.type === 'folder') {
                return `<div id="item-container-${id}" class="item-container folder-item flex items-center px-6 py-4 border-b border-gray-200 dark:border-gray-700 last:border-b-0 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer ${selectedClass}" data-name="${item.name}" data-type="folder"><div class="w-full sm:w-4/5 flex items-center"><svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-yellow-500 mr-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg><span class="font-medium text-gray-700 dark:text-gray-300">${item.name}</span></div><div class="w-1/2 sm:w-1/5 text-right"><button data-name="${item.name}" data-type="folder" class="delete-btn action-btn text-gray-400 hover:text-red-500 p-1 rounded-full" title="Delete Folder"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button></div></div>`;
            }
            return `<div id="item-container-${id}" class="item-container file-item flex flex-wrap items-center px-6 py-4 border-b border-gray-200 dark:border-gray-700 last:border-b-0 hover:bg-gray-50 dark:hover:bg-gray-700/50 relative ${selectedClass}" data-name="${item.encrypted_name}" data-type="file"><div class="w-full sm:w-2/5 flex items-center mb-2 sm:mb-0"><svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-gray-400 mr-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg><span class="font-mono text-sm text-gray-700 dark:text-gray-300 truncate" title="${item.original_name}">${item.original_name}</span></div><div class="w-1/2 sm:w-1/5 text-sm text-gray-500 dark:text-gray-400"><span class="sm:hidden font-semibold mr-2">Size:</span>${formatBytes(item.size)}</div><div class="w-1/2 sm:w-2/5 text-right flex items-center justify-end gap-2"><button data-name="${item.encrypted_name}" data-type="file" class="delete-btn action-btn text-gray-400 hover:text-red-500 p-1 rounded-full" title="Delete File"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button><button data-filename="${item.encrypted_name}" class="download-btn inline-flex items-center gap-2 bg-green-600 text-white font-semibold py-1.5 px-4 rounded-md hover:bg-green-700 transition text-sm"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd" /></svg><span>Download</span></button></div><div class="w-full mt-2 hidden download-progress-container"><div class="w-full bg-gray-200 rounded-full h-1.5"><div class="progress-bar bg-green-600 h-1.5 rounded-full" style="width: 0%"></div></div></div></div>`;
        }

        function createGridItemHTML(item) {
            const id = (item.type === 'file' ? item.encrypted_name : item.name).replace(/[^a-zA-Z0-9]/g, '');
            const isSelected = selectedItems.has(item);
            const selectedClass = isSelected ? 'selected' : '';
            if (item.type === 'folder') {
                return `<div id="item-container-${id}" class="item-container folder-item bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 overflow-hidden aspect-square flex flex-col relative hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer ${selectedClass}" data-name="${item.name}" data-type="folder"><div class="absolute top-1 right-1 flex items-center gap-1 z-10"><button data-name="${item.name}" data-type="folder" class="delete-btn action-btn text-gray-600 bg-white/50 dark:text-gray-300 dark:bg-gray-900/50 hover:bg-white dark:hover:bg-gray-600 p-1 rounded-full" title="Delete Folder"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button></div><div class="flex-grow flex items-center justify-center text-yellow-500"><svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg></div><div class="p-2 text-center border-t dark:border-gray-700"><p class="text-sm font-medium truncate text-gray-700 dark:text-gray-300" title="${item.name}">${item.name}</p></div></div>`;
            }
            const canPreview = item.media_type === 'image' || item.media_type === 'video';
            let previewContent;
            if (thumbnailCache[item.encrypted_name] && thumbnailCache[item.encrypted_name].dataUrl) {
                previewContent = `<div class="relative w-full h-full group"><img src="${thumbnailCache[item.encrypted_name].dataUrl}" alt="Preview" class="w-full h-full object-cover"><div class="absolute inset-0 bg-black/20 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"><button class="lightbox-trigger bg-white/80 text-gray-900 rounded-full p-2 shadow-lg" data-filename="${item.encrypted_name}" data-type="${item.media_type}" title="Open in Lightbox"><svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5v4m0 0h4" /></svg></button></div></div>`;
            } else {
                previewContent = `<div class="flex flex-col items-center justify-center h-full bg-gray-100 dark:bg-gray-700/50 text-gray-400">${item.media_type === 'image' ? '<svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14" /></svg>' : ''}${item.media_type === 'video' ? '<svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>' : ''}${item.media_type === 'file' ? '<svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>' : ''}<div class="preview-action-container mt-2">${canPreview ? `<button class="generate-preview-btn text-xs bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 px-2 py-1 rounded-md shadow flex items-center gap-1" data-filename="${item.encrypted_name}" data-type="${item.media_type}">Preview</button>`: ''}</div></div>`;
            }
            return `<div id="item-container-${id}" class="item-container bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 overflow-hidden aspect-square flex flex-col relative ${selectedClass}" data-name="${item.encrypted_name}" data-type="file"><div class="absolute top-1 right-1 flex items-center gap-1 z-10"><button data-filename="${item.encrypted_name}" class="download-btn action-btn text-gray-600 bg-white/50 dark:text-gray-300 dark:bg-gray-900/50 hover:bg-white dark:hover:bg-gray-600 p-1 rounded-full" title="Download File"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg></button><button data-name="${item.encrypted_name}" data-type="file" class="delete-btn action-btn text-gray-600 bg-white/50 dark:text-gray-300 dark:bg-gray-900/50 hover:bg-white dark:hover:bg-gray-600 p-1 rounded-full" title="Delete File"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button></div><div class="flex-grow relative preview-container min-h-0" data-filename="${item.encrypted_name}" data-type="${item.media_type}">${previewContent}</div><div class="p-2 text-center border-t dark:border-gray-700"><p class="text-xs font-mono truncate text-gray-700 dark:text-gray-300" title="${item.original_name}">${item.original_name}</p><p class="text-xs text-gray-500 dark:text-gray-400">${formatBytes(item.size)}</p></div><div class="w-full mt-1 px-2 pb-2 hidden download-progress-container"><div class="w-full bg-gray-200 rounded-full h-1"><div class="progress-bar bg-green-600 h-1 rounded-full" style="width: 0%"></div></div></div></div>`;
        }

        function renderBreadcrumbs() {
            breadcrumbContainer.innerHTML = '';
            const pathParts = currentPath.split('/').filter(p => p);
            const homeCrumb = document.createElement('li');
            homeCrumb.innerHTML = `<a href="#" data-path="/" class="text-blue-600 dark:text-blue-400 hover:underline">Home</a>`;
            breadcrumbContainer.appendChild(homeCrumb);
            let pathSoFar = '';
            pathParts.forEach(part => {
                pathSoFar += `/${part}`;
                const crumb = document.createElement('li');
                crumb.innerHTML = `<span class="mx-2">/</span><a href="#" data-path="${pathSoFar}" class="text-blue-600 dark:text-blue-400 hover:underline">${part}</a>`;
                breadcrumbContainer.appendChild(crumb);
            });
        }

        async function navigateTo(path) {
            currentPath = path;
            try {
                const response = await fetch(`api.php?action=list&path=${encodeURIComponent(path)}`);
                const data = await response.json();
                if (data.status === 'success') {
                    currentItems = data.items;
                    selectedItems.clear();
                    render();
                } else { throw new Error(data.message || 'Failed to list items.'); }
            } catch (error) { showToast(`Error: ${error.message}`, 'error'); }
        }

        function updateViewControls() {
            const viewListBtn = document.getElementById('view-list-btn');
            const viewGridBtn = document.getElementById('view-grid-btn');
            const generateAllBtn = document.getElementById('generate-all-btn');
            if (generateAllBtn) {
                if (currentView === 'list') {
                    viewListBtn.classList.add('active');
                    viewGridBtn.classList.remove('active');
                    generateAllBtn.classList.add('hidden');
                } else {
                    viewGridBtn.classList.add('active');
                    viewListBtn.classList.remove('active');
                    generateAllBtn.classList.remove('hidden');
                }
            } else {
                viewListBtn.classList.toggle('active', currentView === 'list');
                viewGridBtn.classList.toggle('active', currentView !== 'list');
            }
        }

        function updateSelection() {
            document.querySelectorAll('.item-container.selected').forEach(el => el.classList.remove('selected'));
            selectedItems.forEach(item => {
                const id = (item.type === 'file' ? item.encrypted_name : item.name).replace(/[^a-zA-Z0-9]/g, '');
                const el = document.getElementById(`item-container-${id}`);
                if (el) el.classList.add('selected');
            });
            if (selectedItems.size > 0) {
                selectionActions.classList.remove('hidden');
                selectionActions.classList.add('inline-flex');
                selectionCount.textContent = `${selectedItems.size} selected`;
            } else {
                selectionActions.classList.add('hidden');
                selectionActions.classList.remove('inline-flex');
            }
        }

        function handleFiles(files) {
            const uploadProgressList = document.getElementById('upload-progress-list');
            filesToUpload = [...files];
            uploadProgressList.innerHTML = '';
            filesToUpload.forEach(file => {
                uploadProgressList.innerHTML += `<div id="progress-for-${file.name.replace(/[^a-zA-Z0-9]/g, '')}" class="text-sm"><div class="flex justify-between items-center"><p class="truncate text-gray-700 dark:text-gray-300 w-3/5" title="${file.name}">${file.name}</p><p class="text-gray-500 dark:text-gray-400 status-text">Waiting...</p></div><div class="w-full bg-gray-200 dark:bg-gray-600 rounded-full h-1.5 mt-1"><div class="progress-bar bg-blue-600 h-1.5 rounded-full" style="width: 0%"></div></div></div>`;
            });
        }

        async function handleUpload() {
            if (filesToUpload.length === 0) { showToast('Please select files to upload.', 'info'); return; }
            const password = await getEncryptionPassword();
            if (!password) return;
            document.getElementById('encrypt-btn').disabled = true;
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
                    encryptedPackage.set(salt, 0);
                    encryptedPackage.set(iv, SALT_LENGTH);
                    encryptedPackage.set(new Uint8Array(encryptedContent), SALT_LENGTH + IV_LENGTH);
                    statusText.textContent = 'Uploading...';
                    await uploadFile(file.name, currentPath, encryptedPackage, (p) => { statusBar.style.width = `${p}%`; });
                    statusText.textContent = 'Complete!';
                    statusBar.classList.replace('bg-blue-600', 'bg-green-600');
                } catch (error) {
                    statusText.textContent = `Error`;
                    statusBar.classList.replace('bg-blue-600', 'bg-red-600');
                    showToast(`Upload failed for ${file.name}.`, 'error');
                }
            }
            showToast('Upload process finished.', 'success');
            setTimeout(() => {
                uploadModal.classList.add('hidden');
                navigateTo(currentPath);
            }, 1500);
        }

        function uploadFile(filename, path, data, onProgress) {
            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                const url = `api.php?action=upload&path=${encodeURIComponent(path)}&filename=${encodeURIComponent(filename)}`;
                xhr.open('POST', url, true);
                xhr.setRequestHeader('Content-Type', 'application/octet-stream');
                xhr.upload.onprogress = (event) => { if (event.lengthComputable) onProgress((event.loaded / event.total) * 100); };
                xhr.onload = () => { if (xhr.status >= 200 && xhr.status < 300) resolve(xhr.response); else reject(new Error(`Server error: ${xhr.status}`)); };
                xhr.onerror = () => reject(new Error('Network error.'));
                xhr.send(data);
            });
        }

        async function deriveKey(password, salt) {
            const passwordBuffer = new TextEncoder().encode(password);
            const baseKey = await crypto.subtle.importKey('raw', passwordBuffer, { name: 'PBKDF2' }, false, ['deriveKey']);
            return crypto.subtle.deriveKey({ name: 'PBKDF2', salt, iterations: PBKDF2_ITERATIONS, hash: 'SHA-256' }, baseKey, { name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);
        }

        async function fetchAndDecrypt(filename, onProgress) {
            const password = await getEncryptionPassword();
            if (!password) throw new Error("Password not provided.");
            const userId = document.body.dataset.userId;
            const fullFilePath = `uploads/${userId}${currentPath}/${filename}`.replace(/\/+/g, '/');
            const response = await fetch(fullFilePath);
            if (!response.ok) throw new Error(`File not found on server (HTTP ${response.status}).`);
            const contentLength = +response.headers.get('Content-Length');
            let receivedLength = 0;
            const chunks = [];
            const reader = response.body.getReader();
            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                chunks.push(value);
                receivedLength += value.length;
                if (contentLength && onProgress) onProgress((receivedLength / contentLength) * 100);
            }
            let encryptedPackage = new Uint8Array(receivedLength);
            let position = 0;
            for (const chunk of chunks) {
                encryptedPackage.set(chunk, position);
                position += chunk.length;
            }
            const salt = encryptedPackage.slice(0, SALT_LENGTH);
            const iv = encryptedPackage.slice(SALT_LENGTH, SALT_LENGTH + IV_LENGTH);
            const ciphertext = encryptedPackage.slice(SALT_LENGTH + IV_LENGTH);
            const key = await deriveKey(password, salt);
            try {
                return await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ciphertext);
            } catch (e) { throw new Error('Decryption failed. Check password or file integrity.'); }
        }

        function triggerDownload(decryptedBufferOrBlob, filename) {
            const blob = decryptedBufferOrBlob instanceof Blob ? decryptedBufferOrBlob : new Blob([decryptedBufferOrBlob]);
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename.replace(/\.enc$/, '');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(link.href);
        }

        async function generateThumbnail(blob, type) {
            return new Promise((resolve, reject) => {
                const objectURL = URL.createObjectURL(blob);
                if (type === 'image') {
                    const img = new Image();
                    img.onload = () => { resolve(objectURL); }; // Don't revoke here, used by the <img> src
                    img.onerror = reject;
                    img.src = objectURL;
                } else if (type === 'video') {
                    const video = document.createElement('video');
                    const canvas = document.createElement('canvas');
                    video.muted = true;
                    video.playsInline = true;
                    video.onloadedmetadata = () => {
                        video.currentTime = Math.min(1, video.duration / 2); // Seek to 1s or midpoint
                    };
                    video.onseeked = () => {
                        requestAnimationFrame(() => {
                            canvas.width = video.videoWidth;
                            canvas.height = video.videoHeight;
                            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                            URL.revokeObjectURL(objectURL);
                            resolve(canvas.toDataURL('image/jpeg'));
                        });
                    };
                    video.onerror = reject;
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
                mediaElement.src = objectURL;
                mediaElement.className = 'max-w-full max-h-[90vh] object-contain';
            } else if (type === 'video') {
                mediaElement = document.createElement('video');
                mediaElement.src = objectURL;
                mediaElement.controls = true;
                mediaElement.autoplay = true;
                mediaElement.className = 'max-w-full max-h-[90vh]';
            }
            if (mediaElement) {
                lightboxSpinner.classList.add('hidden');
                lightboxContent.appendChild(mediaElement);
            }
        }

        function hideLightbox() {
            lightboxModal.classList.add('hidden');
            const objectUrl = lightboxContent.dataset.objectUrl;
            const video = lightboxContent.querySelector('video');
            if(video) video.pause();
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
                delete lightboxContent.dataset.objectUrl;
            }
            lightboxContent.innerHTML = '';
        }

        async function handleAction(actionBtn) {
            const isDownload = actionBtn.matches('.download-btn') || actionBtn.closest('.download-btn');
            const isPreview = actionBtn.matches('.generate-preview-btn') || actionBtn.closest('.generate-preview-btn');
            if (!isDownload && !isPreview) return;
            const { filename, type } = actionBtn.dataset;
            const fileItem = actionBtn.closest('.item-container');
            if (!fileItem) return;
            const progressContainer = fileItem.querySelector('.download-progress-container');
            const progressBar = progressContainer ? progressContainer.querySelector('.progress-bar') : null;
            const previewContainer = fileItem.querySelector('.preview-container');
            const actionContainer = fileItem.querySelector('.preview-action-container');
            actionBtn.disabled = true;
            if (isPreview && actionContainer) actionContainer.innerHTML = `<div class="spinner"></div>`;
            if (progressContainer) {
                progressContainer.classList.remove('hidden');
                if (progressBar) progressBar.style.width = '0%';
            }
            try {
                const decryptedBuffer = await fetchAndDecrypt(filename, (p) => { if (progressBar) progressBar.style.width = `${p}%`; });
                const blob = new Blob([decryptedBuffer]);
                if (isDownload) {
                    triggerDownload(blob, filename);
                } else if (isPreview) {
                    const thumbDataUrl = await generateThumbnail(blob, type);
                    thumbnailCache[filename] = { dataUrl: thumbDataUrl, blob: blob };
                    previewContainer.innerHTML = `<div class="relative w-full h-full group"><img src="${thumbDataUrl}" alt="Preview" class="w-full h-full object-cover"><div class="absolute inset-0 bg-black/20 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"><button class="lightbox-trigger bg-white/80 text-gray-900 rounded-full p-2 shadow-lg" data-filename="${filename}" data-type="${type}" title="Open in Lightbox"><svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5v4m0 0h4" /></svg></button></div></div>`;
                }
            } catch (error) {
                showToast(`Action failed: ${error.message}`, 'error');
                if (progressBar) progressBar.classList.add('bg-red-500');
                if (isPreview && actionContainer) actionContainer.innerHTML = `<button class="generate-preview-btn text-xs bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 px-2 py-1 rounded-md shadow flex items-center gap-1" data-filename="${filename}" data-type="${type}">Preview</button>`;
            } finally {
                actionBtn.disabled = false;
                if (progressContainer) setTimeout(() => { progressContainer.classList.add('hidden'); if (progressBar) progressBar.style.width = '0%'; }, 3000);
            }
        }

        function handleItemSelection(event, targetElement) {
            const name = targetElement.dataset.name;
            const clickedItem = currentItems.find(item => (item.encrypted_name || item.name) === name);
            if (!clickedItem) return;
            const currentIndex = currentItems.indexOf(clickedItem);
            if (event.shiftKey && lastClickedIndex > -1) {
                const start = Math.min(lastClickedIndex, currentIndex);
                const end = Math.max(lastClickedIndex, currentIndex);
                for (let i = start; i <= end; i++) {
                    selectedItems.add(currentItems[i]);
                }
            } else if (event.ctrlKey || event.metaKey) {
                if (selectedItems.has(clickedItem)) {
                    selectedItems.delete(clickedItem);
                } else {
                    selectedItems.add(clickedItem);
                }
            } else {
                selectedItems.clear();
                selectedItems.add(clickedItem);
            }
            lastClickedIndex = currentIndex;
            updateSelection();
        }

        function updateThemeIcons(isDark) {
            const themeIconLight = document.getElementById('theme-icon-light');
            const themeIconDark = document.getElementById('theme-icon-dark');
            if(themeIconLight && themeIconDark) {
                themeIconLight.classList.toggle('hidden', isDark);
                themeIconDark.classList.toggle('hidden', !isDark);
            }
        }

        // --- GLOBAL EVENT LISTENERS ---
        document.body.addEventListener('click', async (e) => {
            const target = e.target;
            const isAction = (selector) => target.matches(selector) || target.closest(selector);

            // --- FIX STARTS HERE ---
            // Moved sidebar logic into the main handler and made it the first check.
            if (isAction('#sidebar-toggle-btn') || isAction('#sidebar-overlay')) {
                toggleSidebar();
                return; // Stop processing further clicks
            }
            // --- FIX ENDS HERE ---

            const actionTarget = isAction('button, a, .item-container, [data-path]') ? (target.closest('button, a, .item-container, [data-path]')) : null;
            if (!actionTarget) return;

            // --- FIX: This line was removed as it was too aggressive and broke button clicks ---
            // if (actionTarget.tagName === 'A' || (actionTarget.tagName === 'BUTTON' && !actionTarget.closest('form'))) e.preventDefault();
            // We now handle e.preventDefault() inside specific handlers where needed.

            // Handle actions based on the clicked element
            if (isAction('.download-btn, .generate-preview-btn')) { e.preventDefault(); handleAction(actionTarget); }
            else if (isAction('.delete-btn')) { e.preventDefault(); const itemElement = actionTarget.closest('.item-container'); const itemName = itemElement.dataset.name; const item = currentItems.find(i => (i.encrypted_name || i.name) === itemName); if (item) showDeleteConfirmationModal([item]); }
            else if (isAction('#delete-selected-btn')) { e.preventDefault(); showDeleteConfirmationModal(Array.from(selectedItems)); }
            else if (isAction('.lightbox-trigger')) {
                e.preventDefault();
                const { filename, type } = actionTarget.dataset;
                showLightbox();
                if (thumbnailCache[filename] && thumbnailCache[filename].blob) {
                    displayInLightbox(thumbnailCache[filename].blob, type);
                } else { try { const decryptedBuffer = await fetchAndDecrypt(filename); const blob = new Blob([decryptedBuffer]); displayInLightbox(blob, type); } catch (err) { showToast(`Error loading file: ${err.message}`, 'error'); hideLightbox(); } }
            }
            else if (isAction('.folder-item')) { e.preventDefault(); navigateTo(`${currentPath}/${actionTarget.dataset.name}`.replace(/\/+/g, '/')); }
            else if (actionTarget.dataset.path) { e.preventDefault(); navigateTo(actionTarget.dataset.path); }
            else if (isAction('#logout-btn')) { e.preventDefault(); window.location.href = 'api.php?action=logout'; }
            else if (isAction('#theme-toggle-btn')) { e.preventDefault(); const isDark = document.documentElement.classList.toggle('dark'); updateThemeIcons(isDark); fetch(`api.php?action=set_theme&theme=${isDark ? 'dark' : 'light'}`); }
            else if (isAction('#view-list-btn')) { e.preventDefault(); currentView = 'list'; render(); fetch('api.php?action=set_view&view=list'); }
            else if (isAction('#view-grid-btn')) { e.preventDefault(); currentView = 'grid'; render(); fetch('api.php?action=set_view&view=grid'); }
            else if (isAction('#new-folder-btn')) { e.preventDefault(); newFolderModal.classList.remove('hidden'); folderNameInput.focus(); }
            else if (isAction('#show-upload-modal-btn')) { e.preventDefault(); uploadModal.classList.remove('hidden'); }
            else if (isAction('#encrypt-btn')) { e.preventDefault(); handleUpload(); }
            else if (isAction('#close-upload-modal-btn, #close-folder-modal-btn, #lightbox-close') || actionTarget.matches('.modal-backdrop') || actionTarget.matches('#lightbox-backdrop')) {
                e.preventDefault();
                const modal = actionTarget.closest('.fixed.inset-0');
                if (modal) modal.classList.add('hidden');
                if (isAction('#lightbox-close') || actionTarget.matches('#lightbox-backdrop')) hideLightbox();
            }
            else if (isAction('#generate-all-btn')) { e.preventDefault(); actionTarget.disabled = true; actionTarget.querySelector('span').textContent = 'Generating...'; const previewButtons = document.querySelectorAll('.generate-preview-btn'); (async () => { for (const btn of previewButtons) { if (!btn.disabled) { await handleAction(btn); } } actionTarget.disabled = false; actionTarget.querySelector('span').textContent = 'Generate Previews'; })(); }
            else if (isAction('.sortable-header')) { e.preventDefault(); /* Sorting logic here */ }
            else if (isAction('.item-container') && !isAction('button, a')) { handleItemSelection(e, actionTarget); }
        });

        newFolderForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const folderName = folderNameInput.value.trim();
            if (!folderName) return;
            const formData = new FormData();
            formData.append('action', 'create_folder');
            formData.append('name', folderName);
            formData.append('path', currentPath);
            try {
                const response = await fetch('api.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (!response.ok) throw new Error(result.message || 'Failed to create folder.');
                showToast(`Folder "${folderName}" created.`, 'success');
                newFolderModal.classList.add('hidden');
                folderNameInput.value = '';
                navigateTo(currentPath);
            } catch (error) { showToast(`Error: ${error.message}`, 'error'); }
        });

        dragDropArea.addEventListener('dragover', (e) => { e.preventDefault(); e.stopPropagation(); dragDropArea.classList.add('dragover'); });
        dragDropArea.addEventListener('dragleave', (e) => { e.preventDefault(); e.stopPropagation(); dragDropArea.classList.remove('dragover'); });
        dragDropArea.addEventListener('drop', (e) => { e.preventDefault(); e.stopPropagation(); dragDropArea.classList.remove('dragover'); handleFiles(e.dataTransfer.files); });
        dragDropArea.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', (e) => handleFiles(e.target.files));

        // --- INITIALIZATION ---
        updateThemeIcons(document.documentElement.classList.contains('dark'));
        navigateTo('/');
        fetch('api.php?action=get_usage').then(res => res.json()).then(data => {
            if (data.status === 'success') {
                const usageText = document.getElementById('usage-text');
                const usageBar = document.getElementById('usage-bar');
                const percentage = (data.used / data.quota) * 100;
                usageBar.style.width = `${Math.min(percentage, 100)}%`;
                usageText.textContent = `${formatBytes(data.used)} / ${formatBytes(data.quota)}`;
            }
        });
    });
} else {
    // --- AUTHENTICATION SCRIPT (LOGIN PAGE) ---
    document.addEventListener('DOMContentLoaded', () => {
        const loginView = document.getElementById('login-view');
        const registerView = document.getElementById('register-view');
        const showRegisterBtn = document.getElementById('show-register');
        const showLoginBtn = document.getElementById('show-login');
        const loginForm = document.getElementById('login-form');
        const registerForm = document.getElementById('register-form');
        const loginError = document.getElementById('login-error');
        const registerError = document.getElementById('register-error');

        showRegisterBtn.addEventListener('click', (e) => { e.preventDefault(); loginView.classList.add('hidden'); registerView.classList.remove('hidden'); });
        showLoginBtn.addEventListener('click', (e) => { e.preventDefault(); registerView.classList.add('hidden'); loginView.classList.remove('hidden'); });

        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            loginError.classList.add('hidden');
            const formData = new FormData(loginForm);
            try {
                const response = await fetch('api.php?action=login', { method: 'POST', body: formData });
                const result = await response.json();
                if (!response.ok) throw new Error(result.message || 'Login failed.');
                if (result.status === 'success') {
                    sessionStorage.setItem('vaultPassword', formData.get('password'));
                    window.location.reload();
                } else { throw new Error(result.message || 'Login failed.'); }
            } catch (error) { loginError.textContent = error.message; loginError.classList.remove('hidden'); }
        });

        registerForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            registerError.classList.add('hidden');
            const formData = new FormData(registerForm);
            try {
                const response = await fetch('api.php?action=register', { method: 'POST', body: formData });
                const result = await response.json();
                if (!response.ok) throw new Error(result.message || 'Registration failed.');
                showToast('Registration successful! Please log in.', 'success');
                showLoginBtn.click();
                registerForm.reset();
            } catch (error) { registerError.textContent = error.message; registerError.classList.remove('hidden'); }
        });
    });
}
