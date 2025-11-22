// API Configuration
const API_URL = 'http://localhost:8000/api/index.php';
let currentUser = null;
let currentToken = null;
let currentFolderId = null;
let selectedItem = null;
let files = [];

// Helper function for authenticated requests
async function fetchWithAuth(url, options = {}) {
    const token = localStorage.getItem('token') || currentToken;
    if (!options.headers) {
        options.headers = {};
    }
    if (token) {
        options.headers['Authorization'] = `Bearer ${token}`;
    }
    return fetch(url, options);
}

// Initialize app
document.addEventListener('DOMContentLoaded', () => {
    // Check if user is logged in
    const token = localStorage.getItem('token');
    if (token) {
        currentToken = token;
        loadCurrentUser();
    }
    
    initializeEventListeners();
});

// Event Listeners
function initializeEventListeners() {
    // Auth tabs
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', () => switchTab(tab.dataset.tab));
    });
    
    // Auth forms
    document.getElementById('login-form').addEventListener('submit', handleLogin);
    document.getElementById('register-form').addEventListener('submit', handleRegister);
    document.getElementById('logout-btn').addEventListener('click', handleLogout);
    
    // File operations
    document.getElementById('upload-btn').addEventListener('click', () => {
        document.getElementById('file-input').click();
    });
    document.getElementById('file-input').addEventListener('change', handleFileUpload);
    document.getElementById('new-folder-btn').addEventListener('click', showNewFolderModal);
    document.getElementById('create-folder-btn').addEventListener('click', handleCreateFolder);
    
    // Share functionality
    document.getElementById('share-folder-btn').addEventListener('click', handleShareFolder);
    
    // Modal close buttons
    document.querySelectorAll('.close-btn, .close-modal').forEach(btn => {
        btn.addEventListener('click', closeModals);
    });
    
    // View toggle
    document.querySelectorAll('.view-btn').forEach(btn => {
        btn.addEventListener('click', () => toggleView(btn.dataset.view));
    });
    
    // Navigation
    document.querySelectorAll('.nav-item').forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            switchNavView(item.dataset.view);
        });
    });
    
    // Admin tabs
    document.querySelectorAll('.admin-tab').forEach(tab => {
        tab.addEventListener('click', () => switchAdminTab(tab.dataset.tab));
    });
    
    // Save settings
    document.getElementById('save-settings-btn').addEventListener('click', saveSettings);
    
    // Drag and drop
    const dropZone = document.getElementById('drop-zone');
    const fileArea = document.querySelector('.file-area');
    
    ['dragenter', 'dragover'].forEach(event => {
        fileArea.addEventListener(event, (e) => {
            e.preventDefault();
            dropZone.classList.add('active', 'drag-over');
        });
    });
    
    ['dragleave', 'drop'].forEach(event => {
        fileArea.addEventListener(event, (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
        });
    });
    
    dropZone.addEventListener('drop', (e) => {
        const files = e.dataTransfer.files;
        handleFileDrop(files);
    });
    
    // Context menu
    document.addEventListener('click', () => {
        document.getElementById('context-menu').classList.remove('active');
    });
    
    document.querySelectorAll('.context-item').forEach(item => {
        item.addEventListener('click', handleContextAction);
    });
    
    // Search
    document.getElementById('search-input').addEventListener('input', handleSearch);
}

// Auth Functions
function switchTab(tab) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
    
    document.querySelector(`[data-tab="${tab}"]`).classList.add('active');
    document.getElementById(`${tab}-form`).classList.add('active');
}

async function handleLogin(e) {
    e.preventDefault();
    const username = document.getElementById('login-username').value;
    const password = document.getElementById('login-password').value;
    
    showLoading();
    
    try {
        const response = await fetch(`${API_URL}/auth/login`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password })
        });
        
        const data = await response.json();
        
        if (response.ok) {
            currentToken = data.access_token;
            currentUser = data.user;
            localStorage.setItem('token', currentToken);
            showApp();
            loadFiles();
        } else {
            showError('login-error', data.error || 'Error al iniciar sesión');
        }
    } catch (error) {
        showError('login-error', 'Error de conexión con el servidor');
    }
    
    hideLoading();
}

async function handleRegister(e) {
    e.preventDefault();
    const username = document.getElementById('register-username').value;
    const email = document.getElementById('register-email').value;
    const password = document.getElementById('register-password').value;
    const confirmPassword = document.getElementById('register-password-confirm').value;
    
    if (password !== confirmPassword) {
        showError('register-error', 'Las contraseñas no coinciden');
        return;
    }
    
    showLoading();
    
    try {
        const response = await fetch(`${API_URL}/auth/register`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, email, password })
        });
        
        const data = await response.json();
        
        if (response.ok) {
            alert('¡Registro exitoso! Ahora puedes iniciar sesión');
            switchTab('login');
            document.getElementById('register-form').reset();
        } else {
            showError('register-error', data.error || 'Error al registrar usuario');
        }
    } catch (error) {
        showError('register-error', 'Error de conexión con el servidor');
    }
    
    hideLoading();
}

async function loadCurrentUser() {
    try {
        const response = await fetchWithAuth(`${API_URL}/auth/me`);
        
        if (response.ok) {
            currentUser = await response.json();
            showApp();
            loadFiles();
        } else {
            handleLogout();
        }
    } catch (error) {
        handleLogout();
    }
}

function handleLogout() {
    currentToken = null;
    currentUser = null;
    localStorage.removeItem('token');
    showLogin();
}

function showApp() {
    document.getElementById('login-screen').classList.remove('active');
    document.getElementById('app-screen').classList.add('active');
    document.getElementById('user-name').textContent = currentUser.username;
    window.scrollTo(0, 0);
    document.body.style.overflow = 'hidden';
    
    // Show admin menu if user is admin
    if (currentUser.role === 'admin') {
        document.querySelectorAll('.admin-only').forEach(el => el.style.display = 'flex');
    }
}

function showLogin() {
    document.getElementById('app-screen').classList.remove('active');
    document.getElementById('login-screen').classList.add('active');
    window.scrollTo(0, 0);
    document.body.style.overflow = 'auto';
}

// File Operations
async function loadFiles(folderId = null) {
    showLoading();
    
    try {
        const url = folderId 
            ? `${API_URL}/files?folder_id=${folderId}`
            : `${API_URL}/files`;
            
        const response = await fetchWithAuth(url);
        
        if (response.ok) {
            files = await response.json();
            displayFiles(files);
            updateStorageInfo();
        }
    } catch (error) {
        console.error('Error loading files:', error);
    }
    
    hideLoading();
}

function displayFiles(items) {
    const container = document.getElementById('files-container');
    const emptyState = document.getElementById('empty-state');
    
    if (items.length === 0) {
        container.innerHTML = '';
        emptyState.classList.add('show');
        return;
    }
    
    emptyState.classList.remove('show');
    
    // Sort: folders first, then files
    items.sort((a, b) => {
        if (a.type === 'folder' && b.type === 'file') return -1;
        if (a.type === 'file' && b.type === 'folder') return 1;
        return a.name.localeCompare(b.name);
    });
    
    container.innerHTML = items.map(item => createFileElement(item)).join('');
    
    // Add event listeners
    document.querySelectorAll('.file-item').forEach(item => {
        item.addEventListener('click', () => handleFileClick(item));
        item.addEventListener('contextmenu', (e) => handleContextMenu(e, item));
    });
}

function createFileElement(item) {
    const icon = item.type === 'folder' ? '📁' : getFileIcon(item.mime_type);
    const size = item.type === 'file' ? formatFileSize(item.size) : '';
    const sharedClass = item.shared ? 'shared' : '';
    
    return `
        <div class="file-item ${sharedClass}" data-id="${item.id}" data-type="${item.type}">
            <div class="file-icon">${icon}</div>
            <div class="file-name">${item.name}</div>
            ${size ? `<div class="file-info">${size}</div>` : ''}
        </div>
    `;
}

function getFileIcon(mimeType) {
    if (!mimeType) return '📄';
    
    if (mimeType.startsWith('image/')) return '🖼️';
    if (mimeType.startsWith('video/')) return '🎥';
    if (mimeType.startsWith('audio/')) return '🎵';
    if (mimeType.includes('pdf')) return '📕';
    if (mimeType.includes('zip') || mimeType.includes('rar')) return '📦';
    if (mimeType.includes('word')) return '📝';
    if (mimeType.includes('excel') || mimeType.includes('spreadsheet')) return '📊';
    if (mimeType.includes('powerpoint') || mimeType.includes('presentation')) return '📽️';
    
    return '📄';
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
}

function handleFileClick(element) {
    const id = parseInt(element.dataset.id);
    const type = element.dataset.type;
    
    if (type === 'folder') {
        currentFolderId = id;
        loadFiles(id);
        updateBreadcrumb();
    } else {
        downloadFile(id);
    }
}

async function handleFileUpload(e) {
    const files = e.target.files;
    await uploadFiles(files);
}

async function handleFileDrop(files) {
    await uploadFiles(files);
}

async function uploadFiles(files) {
    showLoading();
    
    for (const file of files) {
        const formData = new FormData();
        formData.append('file', file);
        if (currentFolderId) {
            formData.append('folder_id', currentFolderId);
        }
        
        try {
            const response = await fetchWithAuth(`${API_URL}/files/upload`, {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) {
                const data = await response.json();
                alert(`Error subiendo ${file.name}: ${data.error}`);
            }
        } catch (error) {
            alert(`Error subiendo ${file.name}`);
        }
    }
    
    document.getElementById('file-input').value = '';
    await loadFiles(currentFolderId);
    hideLoading();
}

async function downloadFile(fileId) {
    try {
        const response = await fetchWithAuth(`${API_URL}/files/${fileId}/download`);
        
        if (response.ok) {
            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = '';
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
        }
    } catch (error) {
        alert('Error al descargar el archivo');
    }
}

async function deleteItem(id, type) {
    if (!confirm(`¿Estás seguro de eliminar este ${type === 'folder' ? 'carpeta' : 'archivo'}?`)) {
        return;
    }
    
    showLoading();
    
    try {
        const endpoint = type === 'folder' ? 'folders' : 'files';
        const response = await fetchWithAuth(`${API_URL}/${endpoint}/${id}`, {
            method: 'DELETE'
        });
        
        if (response.ok) {
            await loadFiles(currentFolderId);
        } else {
            alert('Error al eliminar');
        }
    } catch (error) {
        alert('Error al eliminar');
    }
    
    hideLoading();
}

// Folder Operations
function showNewFolderModal() {
    document.getElementById('new-folder-modal').classList.add('active');
    document.getElementById('folder-name').value = '';
    document.getElementById('folder-name').focus();
}

async function handleCreateFolder() {
    const name = document.getElementById('folder-name').value.trim();
    
    if (!name) {
        alert('Por favor ingresa un nombre para la carpeta');
        return;
    }
    
    showLoading();
    
    try {
        const response = await fetchWithAuth(`${API_URL}/folders`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                name,
                parent_id: currentFolderId
            })
        });
        
        if (response.ok) {
            closeModals();
            await loadFiles(currentFolderId);
        } else {
            const data = await response.json();
            alert(data.error || 'Error al crear carpeta');
        }
    } catch (error) {
        alert('Error al crear carpeta');
    }
    
    hideLoading();
}

// Share Functionality
async function showShareModal(folderId) {
    selectedItem = { id: folderId, type: 'folder' };
    document.getElementById('share-modal').classList.add('active');
    document.getElementById('share-username').value = '';
    
    await loadFolderShares(folderId);
}

async function loadFolderShares(folderId) {
    try {
        const response = await fetchWithAuth(`${API_URL}/folders/${folderId}/shares`);
        
        if (response.ok) {
            const shares = await response.json();
            displayShares(shares);
        }
    } catch (error) {
        console.error('Error loading shares:', error);
    }
}

function displayShares(shares) {
    const container = document.getElementById('shares-list');
    
    if (shares.length === 0) {
        container.innerHTML = '<p style="color: var(--text-secondary); font-size: 14px;">No compartido con nadie aún</p>';
        return;
    }
    
    container.innerHTML = shares.map(share => `
        <div class="share-item">
            <div class="share-info">
                <div class="share-username">${share.shared_with_username}</div>
                <div class="share-permission">${share.permission === 'read' ? 'Solo lectura' : 'Lectura y escritura'}</div>
            </div>
            <button class="remove-share-btn" onclick="removeShare(${share.id})">Eliminar</button>
        </div>
    `).join('');
}

async function handleShareFolder() {
    const username = document.getElementById('share-username').value.trim();
    const permission = document.getElementById('share-permission').value;
    
    if (!username) {
        alert('Por favor ingresa un nombre de usuario');
        return;
    }
    
    showLoading();
    
    try {
        const response = await fetchWithAuth(`${API_URL}/folders/${selectedItem.id}/share`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ username, permission })
        });
        
        const data = await response.json();
        
        if (response.ok) {
            document.getElementById('share-username').value = '';
            await loadFolderShares(selectedItem.id);
            alert('Carpeta compartida exitosamente');
        } else {
            alert(data.error || 'Error al compartir carpeta');
        }
    } catch (error) {
        alert('Error al compartir carpeta');
    }
    
    hideLoading();
}

async function removeShare(shareId) {
    if (!confirm('¿Eliminar este acceso compartido?')) return;
    
    showLoading();
    
    try {
        const response = await fetchWithAuth(`${API_URL}/shares/${shareId}`, {
            method: 'DELETE'
        });
        
        if (response.ok) {
            await loadFolderShares(selectedItem.id);
        }
    } catch (error) {
        alert('Error al eliminar acceso');
    }
    
    hideLoading();
}

// Context Menu
function handleContextMenu(e, element) {
    e.preventDefault();
    
    selectedItem = {
        id: parseInt(element.dataset.id),
        type: element.dataset.type
    };
    
    const menu = document.getElementById('context-menu');
    menu.style.left = e.pageX + 'px';
    menu.style.top = e.pageY + 'px';
    menu.classList.add('active');
    
    // Hide share option for files
    const shareBtn = menu.querySelector('[data-action="share"]');
    shareBtn.style.display = selectedItem.type === 'folder' ? 'flex' : 'none';
}

function handleContextAction(e) {
    const action = e.currentTarget.dataset.action;
    
    switch (action) {
        case 'download':
            if (selectedItem.type === 'file') {
                downloadFile(selectedItem.id);
            }
            break;
        case 'share':
            if (selectedItem.type === 'folder') {
                showShareModal(selectedItem.id);
            }
            break;
        case 'delete':
            deleteItem(selectedItem.id, selectedItem.type);
            break;
    }
    
    document.getElementById('context-menu').classList.remove('active');
}

// UI Functions
function toggleView(view) {
    document.querySelectorAll('.view-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.view === view);
    });
    
    const container = document.getElementById('files-container');
    container.className = `files-container ${view}-view`;
}

function switchNavView(view) {
    document.querySelectorAll('.nav-item').forEach(item => {
        item.classList.toggle('active', item.dataset.view === view);
    });
    
    if (view === 'admin') {
        document.querySelector('.file-area').style.display = 'none';
        document.getElementById('admin-panel').style.display = 'block';
        loadAdminData();
    } else {
        document.querySelector('.file-area').style.display = 'block';
        document.getElementById('admin-panel').style.display = 'none';
        currentFolderId = null;
        loadFiles();
    }
}

function updateBreadcrumb() {
    // Simplified breadcrumb - could be enhanced to show full path
    const breadcrumb = document.querySelector('.breadcrumb');
    breadcrumb.innerHTML = '<span class="breadcrumb-item active">Carpeta</span>';
}

async function updateStorageInfo() {
    let totalSize = 0;
    files.forEach(file => {
        if (file.type === 'file') {
            totalSize += file.size;
        }
    });
    
    document.getElementById('storage-used').textContent = formatFileSize(totalSize);
    
    const maxStorage = 1073741824; // 1GB
    const percentage = (totalSize / maxStorage) * 100;
    document.querySelector('.storage-used').style.width = percentage + '%';
}

function handleSearch(e) {
    const query = e.target.value.toLowerCase();
    
    if (!query) {
        displayFiles(files);
        return;
    }
    
    const filtered = files.filter(item => 
        item.name.toLowerCase().includes(query)
    );
    
    displayFiles(filtered);
}

function closeModals() {
    document.querySelectorAll('.modal').forEach(modal => {
        modal.classList.remove('active');
    });
}

function showLoading() {
    document.getElementById('loading-overlay').classList.add('active');
}

function hideLoading() {
    document.getElementById('loading-overlay').classList.remove('active');
}

function showError(elementId, message) {
    const element = document.getElementById(elementId);
    element.textContent = message;
    element.classList.add('show');
    
    setTimeout(() => {
        element.classList.remove('show');
    }, 5000);
}

// ADMIN FUNCTIONS

async function loadAdminData() {
    await loadUsers();
    await loadSettings();
}

function switchAdminTab(tab) {
    document.querySelectorAll('.admin-tab').forEach(t => {
        t.classList.toggle('active', t.dataset.tab === tab);
    });
    
    document.querySelectorAll('.admin-content').forEach(content => {
        content.style.display = 'none';
    });
    
    document.getElementById(`admin-${tab}`).style.display = 'block';
}

async function loadUsers() {
    try {
        const response = await fetchWithAuth(`${API_URL}/admin/users`);
        
        if (response.ok) {
            const users = await response.json();
            displayUsers(users);
        }
    } catch (error) {
        console.error('Error loading users:', error);
    }
}

function displayUsers(users) {
    const container = document.getElementById('users-list');
    
    container.innerHTML = users.map(user => `
        <tr>
            <td><strong>${user.username}</strong></td>
            <td>${user.email}</td>
            <td><span class="user-role ${user.role}">${user.role}</span></td>
            <td><span class="user-status ${user.is_active ? 'active' : 'inactive'}">${user.is_active ? 'Activo' : 'Inactivo'}</span></td>
            <td>${new Date(user.created_at).toLocaleDateString()}</td>
            <td>
                <div class="user-actions">
                    <button class="user-action-btn toggle" onclick="toggleUserStatus(${user.id}, ${user.is_active})">
                        ${user.is_active ? 'Desactivar' : 'Activar'}
                    </button>
                    ${currentUser.id !== user.id ? `<button class="user-action-btn delete" onclick="deleteUser(${user.id})">Eliminar</button>` : ''}
                </div>
            </td>
        </tr>
    `).join('');
}

async function toggleUserStatus(userId, currentStatus) {
    if (!confirm('¿Cambiar el estado de este usuario?')) return;
    
    showLoading();
    
    try {
        const response = await fetchWithAuth(`${API_URL}/admin/users/${userId}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ is_active: currentStatus ? 0 : 1 })
        });
        
        if (response.ok) {
            await loadUsers();
        }
    } catch (error) {
        alert('Error al actualizar usuario');
    }
    
    hideLoading();
}

async function deleteUser(userId) {
    if (!confirm('¿Eliminar este usuario? Esta acción no se puede deshacer y eliminará todos sus archivos.')) return;
    
    showLoading();
    
    try {
        const response = await fetchWithAuth(`${API_URL}/admin/users/${userId}`, {
            method: 'DELETE'
        });
        
        if (response.ok) {
            await loadUsers();
            alert('Usuario eliminado correctamente');
        }
    } catch (error) {
        alert('Error al eliminar usuario');
    }
    
    hideLoading();
}

async function loadSettings() {
    try {
        const response = await fetchWithAuth(`${API_URL}/admin/settings`);
        
        if (response.ok) {
            const settings = await response.json();
            document.getElementById('setting-registration').checked = settings.allow_registration === '1';
            document.getElementById('setting-max-size').value = Math.round(settings.max_file_size / 1048576);
        }
    } catch (error) {
        console.error('Error loading settings:', error);
    }
}

async function saveSettings() {
    showLoading();
    
    const settings = {
        allow_registration: document.getElementById('setting-registration').checked ? '1' : '0',
        max_file_size: (parseInt(document.getElementById('setting-max-size').value) * 1048576).toString()
    };
    
    try {
        const response = await fetchWithAuth(`${API_URL}/admin/settings`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(settings)
        });
        
        if (response.ok) {
            alert('Configuración guardada correctamente');
        }
    } catch (error) {
        alert('Error al guardar configuración');
    }
    
    hideLoading();
}
