class TicketApp {
    constructor() {
        this.user = null;
        this.employees = [];
        this.tickets = [];
        this.currentTab = 'add';
        this.editingTicketId = null;
        this.changes = new Map();
        this.debounceTimer = null;
        this.surnamePromptShown = false;
        this.initEvents();
    }

    // ========== ИНИЦИАЛИЗАЦИЯ ПО РОЛИ ==========
    initByRole(user) {
        this.user = user;
        
        if (this.user.role === 'it') {
            this.initIT();
        } else if (this.user.role === 'requester') {
            this.initRequester();
        }
        
        const dateInput = document.getElementById('date');
        if (dateInput) {
            dateInput.value = new Date().toISOString().split('T')[0];
        }
    }

    initIT() {
        console.log('Initializing IT interface');
        this.surnamePromptShown = false;
        this.renderITAddForm();
        this.loadEmployees();
        this.loadTickets();
        this.initTabNavigation();
        
        if (this.user.is_admin) {
            document.querySelectorAll('.admin-only').forEach(el => el.style.display = 'block');
            this.loadAllUsers();
            this.loadRequesterTicketsForAdminTab();
            this.loadTicketStats();
            
            const employeesBlock = document.querySelector('.employees-list');
            if (employeesBlock) {
                const isCollapsed = localStorage.getItem('employees_block_collapsed') === 'true';
                if (isCollapsed) employeesBlock.classList.add('collapsed');
                else employeesBlock.classList.remove('collapsed');
            }
        } else {
            document.querySelectorAll('.admin-only').forEach(el => el.style.display = 'none');
        }
        
        document.querySelectorAll('.requester-only').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.it-only').forEach(el => el.style.display = 'block');
        
        this.switchTab('add');
    }

    initRequester() {
        console.log('Initializing Requester interface');
        
        document.querySelectorAll('.it-only, .admin-only').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.requester-only').forEach(el => el.style.display = 'block');
        
        if (!this.user.surname) {
            this.requestSurname();
        }
        
        this.loadRequesterTickets();
        this.setupRequesterTabs();
    }

    renderITAddForm() {
        const form = document.getElementById('ticket-form');
        if (!form) return;
        
        form.innerHTML = `
            <div class="form-group">
                <label for="date"><i class="far fa-calendar"></i> Дата выполнения:</label>
                <input type="date" id="date" required>
            </div>

            <div class="form-group">
                <label for="employee"><i class="fas fa-user"></i> От кого тикет:</label>
                <div class="search-container">
                    <input type="text" 
                        id="employee-search" 
                        placeholder="Введите первую букву фамилии..."
                        autocomplete="off">
                    <div id="employee-dropdown" class="dropdown">
                    </div>
                </div>
                <input type="hidden" id="employee-id" required>
                <div id="selected-employee" class="selected-item hidden">
                </div>
            </div>

            <div class="form-group">
                <label for="task"><i class="fas fa-tasks"></i> Содержание задачи:</label>
                <textarea id="task" rows="4" placeholder="Опишите задачу..." required></textarea>
            </div>

            <div class="checkboxes">
                <label class="checkbox-label">
                    <input type="checkbox" id="is-done">
                    <span class="checkmark"></span>
                    <i class="fas fa-check-circle"></i> Выполнено
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" id="is-in-db">
                    <span class="checkmark"></span>
                    <i class="fas fa-database"></i> Внесено в SN
                </label>
            </div>

            <button type="submit" class="btn btn-success">
                <i class="fas fa-save"></i> Сохранить тикет
            </button>
        `;
        
        form.onsubmit = (e) => {
            e.preventDefault();
            this.addTicket();
        };
        
        const dateInput = document.getElementById('date');
        if (dateInput) {
            dateInput.value = new Date().toISOString().split('T')[0];
        }
        
        this.clearEmployee();
        this.initEmployeeSearchEvents();
    }

    requestSurname() {
        if (this.surnamePromptShown) return;
        this.surnamePromptShown = true;
        
        const surname = prompt('Введите вашу фамилию и инициалы (например: Иванов И.И.):');
        if (surname && surname.trim()) {
            this.updateSurname(surname.trim());
        } else {
            this.surnamePromptShown = false;
            alert('Фамилия обязательна для создания заявок.');
            setTimeout(() => this.requestSurname(), 500);
        }
    }

    async updateSurname(surname) {
        try {
            const response = await this.fetchWithAuth('../api/update_surname.php', {
                method: 'POST',
                body: JSON.stringify({ surname })
            });
            const data = await response.json();
            if (data.success) {
                this.user.surname = surname;
                this.showNotification('✅ Фамилия сохранена', 'success');
                const usernameEl = document.getElementById('username');
                if (usernameEl) usernameEl.textContent = surname;
            } else {
                throw new Error(data.error || 'Ошибка сохранения');
            }
        } catch (error) {
            console.error('Update surname error:', error);
            this.showNotification('❌ Ошибка сохранения фамилии', 'error');
            this.surnamePromptShown = false;
            setTimeout(() => this.requestSurname(), 1000);
        }
    }

    setupRequesterTabs() {
        document.querySelectorAll('.tab').forEach(tab => {
            if (tab.dataset.tab === 'settings') {
                tab.style.display = 'none';
            }
        });
        
        this.switchTab('add');
        this.renderRequesterAddForm();
    }

    renderRequesterAddForm() {
        const form = document.getElementById('ticket-form');
        if (!form) return;
        
        form.innerHTML = `
            <div class="form-group">
                <label><i class="far fa-calendar"></i> Дата выполнения:</label>
                <input type="date" id="date" required value="${new Date().toISOString().split('T')[0]}">
            </div>
            <div class="form-group">
                <label><i class="fas fa-user"></i> Ваша фамилия:</label>
                <input type="text" id="requester-surname" value="${this.user.surname || ''}" readonly class="readonly-field">
                <p class="hint">Если фамилия неверна, обратитесь к администратору</p>
            </div>
            <div class="form-group">
                <label><i class="fas fa-tasks"></i> Описание задачи:</label>
                <textarea id="task" rows="4" placeholder="Опишите проблему..." required></textarea>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> 📤 Отправить в IT
            </button>
        `;
        
        form.onsubmit = (e) => {
            e.preventDefault();
            this.addRequesterTicket();
        };
    }

    async addRequesterTicket() {
        if (!this.user.surname) {
            this.showNotification('Сначала укажите фамилию', 'error');
            this.requestSurname();
            return;
        }
        
        const task = document.getElementById('task').value.trim();
        const date = document.getElementById('date').value;
        
        if (!task) {
            this.showNotification('Введите описание задачи', 'error');
            return;
        }
        
        const submitBtn = document.querySelector('#ticket-form button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';
        submitBtn.disabled = true;
        
        try {
            const response = await this.fetchWithAuth('../api/tickets.php', {
                method: 'POST',
                body: JSON.stringify({
                    date: date,
                    task: task
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showNotification('✅ Заявка отправлена в IT-отдел', 'success');
                document.getElementById('task').value = '';
                this.loadRequesterTickets();
                this.switchTab('list');
            } else {
                if (data.code === 'SURNAME_REQUIRED') {
                    this.requestSurname();
                }
                this.showNotification('❌ Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
            }
        } catch (error) {
            console.error('Add requester ticket error:', error);
            this.showNotification('❌ Ошибка соединения', 'error');
        } finally {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    }

    async loadRequesterTickets() {
        try {
            const response = await this.fetchWithAuth('../api/tickets.php');
            const data = await response.json();
            if (data.success) {
                this.tickets = data.tickets;
                this.renderRequesterTickets(data.tickets);
            }
        } catch (error) {
            console.error('Load requester tickets error:', error);
            this.showNotification('Ошибка загрузки заявок', 'error');
        }
    }

    renderRequesterTickets(tickets) {
        const container = document.getElementById('tickets-list');
        if (!tickets || tickets.length === 0) {
            container.innerHTML = '<div class="empty-state">У вас нет отправленных заявок</div>';
            return;
        }
        
        container.innerHTML = tickets.map(ticket => {
            let status = '';
            let statusClass = '';
            
            if (ticket.is_done == 1) {
                status = '✅ Выполнено';
                statusClass = 'status-done';
            } else if (ticket.requester_status === 'in_progress') {
                status = '▶️ В работе';
                statusClass = 'status-progress';
            } else {
                status = '⏳ На рассмотрении';
                statusClass = 'status-pending';
            }
            
            return `
                <div class="ticket-item">
                    <div class="ticket-header">
                        <span class="ticket-date"><i class="far fa-calendar"></i> ${ticket.ticket_date}</span>
                        <span class="ticket-id">#${ticket.id}</span>
                        <button onclick="app.deleteRequesterTicket(${ticket.id})" class="btn btn-sm btn-danger">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    <div class="ticket-task">${ticket.task}</div>
                    <div class="ticket-status">
                        <span class="status-badge ${statusClass}">${status}</span>
                    </div>
                </div>
            `;
        }).join('');
    }

    async deleteRequesterTicket(ticketId) {
        if (!confirm('Удалить эту заявку? Это действие нельзя отменить.')) return;
        
        try {
            const response = await this.fetchWithAuth(`../api/tickets.php?id=${ticketId}`, {
                method: 'DELETE'
            });
            const data = await response.json();
            if (data.success) {
                this.showNotification('✅ Заявка удалена', 'success');
                this.loadRequesterTickets();
            } else {
                this.showNotification('❌ Ошибка удаления: ' + (data.error || ''), 'error');
            }
        } catch (error) {
            console.error('Delete requester ticket error:', error);
            this.showNotification('❌ Ошибка соединения', 'error');
        }
    }

    // ========== МЕТОДЫ ДЛЯ АДМИНА (ЗАЯВИТЕЛИ) ==========
    async loadRequesters(searchTerm = '') {
        try {
            const response = await this.fetchWithAuth('../api/requesters.php');
            const data = await response.json();
            if (data.success) {
                let requesters = data.requesters;
                if (searchTerm) {
                    const searchLower = searchTerm.toLowerCase();
                    requesters = requesters.filter(r => 
                        (r.surname && r.surname.toLowerCase().includes(searchLower)) ||
                        (r.first_name && r.first_name.toLowerCase().includes(searchLower)) ||
                        (r.last_name && r.last_name.toLowerCase().includes(searchLower)) ||
                        (r.username && r.username.toLowerCase().includes(searchLower)) ||
                        r.user_id.toString().includes(searchLower)
                    );
                }
                const requestersWithType = requesters.map(r => ({...r, type: 'requester'}));
                this.renderUserList(requestersWithType, 'requester');
            }
        } catch (error) {
            console.error('Load requesters error:', error);
            this.showNotification('Ошибка загрузки заявителей', 'error');
        }
    }

    // ========== НОВЫЙ РАЗДЕЛ "ЗАЯВКИ ОТ ЗАЯВИТЕЛЕЙ" ==========
    async loadRequesterTicketsForAdminTab() {
        try {
            const response = await this.fetchWithAuth('../api/tickets.php?source=requester');
            const data = await response.json();
            if (data.success) {
                this.requesterTickets = data.tickets;
                this.filterRequesterTickets();
            }
        } catch (error) {
            console.error('Load requester tickets error:', error);
            this.showNotification('Ошибка загрузки заявок', 'error');
        }
    }

    filterRequesterTickets() {
        const searchText = document.getElementById('search-requester-tickets')?.value.toLowerCase() || '';
        const filterStatus = document.getElementById('filter-requester-status')?.value || 'all';
        
        const filtered = this.requesterTickets.filter(ticket => {
            const matchesSearch = ticket.task.toLowerCase().includes(searchText) ||
                                 (ticket.requester_name && ticket.requester_name.toLowerCase().includes(searchText));
            
            let matchesStatus = true;
            if (filterStatus === 'pending') {
                matchesStatus = ticket.requester_status === 'pending' && ticket.is_done == 0;
            } else if (filterStatus === 'in_progress') {
                matchesStatus = ticket.requester_status === 'in_progress' && ticket.is_done == 0;
            } else if (filterStatus === 'done') {
                matchesStatus = ticket.is_done == 1;
            }
            
            return matchesSearch && matchesStatus;
        });
        
        this.renderRequesterTicketsForAdminTab(filtered);
    }

    renderRequesterTicketsForAdminTab(tickets) {
        const container = document.getElementById('requester-tickets-list');
        if (!container) return;
        
        if (!tickets || tickets.length === 0) {
            container.innerHTML = '<div class="empty-state">Нет заявок от заявителей</div>';
            return;
        }
        
        container.innerHTML = tickets.map(ticket => {
            let status = '';
            let statusClass = '';
            if (ticket.is_done == 1) {
                status = '✅ Выполнено';
                statusClass = 'status-done';
            } else if (ticket.requester_status === 'in_progress') {
                status = '▶️ В работе';
                statusClass = 'status-progress';
            } else {
                status = '⏳ На рассмотрении';
                statusClass = 'status-pending';
            }
            
            return `
                <div class="ticket-item" data-id="${ticket.id}">
                    <div class="ticket-header">
                        <span class="ticket-date"><i class="far fa-calendar"></i> ${ticket.ticket_date}</span>
                        <span class="ticket-id">#${ticket.id}</span>
                        <span class="ticket-requester">👤 ${ticket.requester_name || 'Неизвестно'}</span>
                    </div>
                    <div class="ticket-task">${ticket.task}</div>
                    <div class="ticket-status">
                        <span class="status-badge ${statusClass}">${status}</span>
                    </div>
                    <div class="ticket-actions">
                        <select onchange="app.updateRequesterTicketStatus(${ticket.id}, this.value)">
                            <option value="pending" ${ticket.requester_status === 'pending' && ticket.is_done == 0 ? 'selected' : ''}>На рассмотрении</option>
                            <option value="in_progress" ${ticket.requester_status === 'in_progress' && ticket.is_done == 0 ? 'selected' : ''}>В работе</option>
                            <option value="done" ${ticket.is_done == 1 ? 'selected' : ''}>Выполнено</option>
                        </select>
                    </div>
                </div>
            `;
        }).join('');
    }

    async updateRequesterTicketStatus(ticketId, newStatus) {
        try {
            if (newStatus === 'done') {
                await this.updateTicketField(ticketId, 'is_done', 1);
            } else {
                await this.updateTicketField(ticketId, 'requester_status', newStatus);
                if (newStatus !== 'done') {
                    await this.updateTicketField(ticketId, 'is_done', 0);
                }
            }
            this.showNotification('✅ Статус обновлён', 'success');
            this.loadRequesterTicketsForAdminTab();
        } catch (error) {
            console.error('Update status error:', error);
            this.showNotification('❌ Ошибка обновления статуса', 'error');
        }
    }

    // ========== ОБЪЕДИНЁННЫЙ СПИСОК ПОЛЬЗОВАТЕЛЕЙ (ДЛЯ АДМИНА) ==========
    async loadUsers(filterType = 'it', searchTerm = '') {
        try {
            const response = await this.fetchWithAuth('../api/users.php');
            const data = await response.json();
            if (data.success) {
                let users = data.users;
                if (filterType === 'admin') {
                    users = users.filter(u => u.is_admin == 1);
                }
                if (searchTerm) {
                    const searchLower = searchTerm.toLowerCase();
                    users = users.filter(u => 
                        (u.first_name && u.first_name.toLowerCase().includes(searchLower)) ||
                        (u.last_name && u.last_name.toLowerCase().includes(searchLower)) ||
                        (u.username && u.username.toLowerCase().includes(searchLower)) ||
                        u.telegram_id.toString().includes(searchLower)
                    );
                }
                // Сохраняем для последующего удаления
                this.users = users;
                this.renderUserList(users, 'it');
            }
        } catch (error) {
            console.error('Load users error:', error);
            this.showNotification('Ошибка загрузки пользователей', 'error');
        }
    }

    async loadAllUsers(searchTerm = '') {
        try {
            const [usersRes, requestersRes] = await Promise.all([
                this.fetchWithAuth('../api/users.php'),
                this.fetchWithAuth('../api/requesters.php')
            ]);
            const usersData = await usersRes.json();
            const requestersData = await requestersRes.json();
            
            let combined = [];
            if (usersData.success) {
                combined = combined.concat(usersData.users.map(u => ({...u, type: 'it'})));
                // Обновляем статистику: общее число = users + requesters
                const totalUsers = usersData.users.length + (requestersData.success ? requestersData.requesters.length : 0);
                const totalAdmins = usersData.users.filter(u => u.is_admin == 1).length;
                document.querySelector('.total-users').textContent = totalUsers;
                document.querySelector('.total-admins').textContent = totalAdmins;
            }
            if (requestersData.success) {
                combined = combined.concat(requestersData.requesters.map(r => ({...r, type: 'requester'})));
            }
            
            if (searchTerm) {
                const searchLower = searchTerm.toLowerCase();
                combined = combined.filter(item => {
                    if (item.type === 'it') {
                        return (item.first_name && item.first_name.toLowerCase().includes(searchLower)) ||
                               (item.last_name && item.last_name.toLowerCase().includes(searchLower)) ||
                               (item.username && item.username.toLowerCase().includes(searchLower)) ||
                               item.telegram_id.toString().includes(searchLower);
                    } else {
                        return (item.surname && item.surname.toLowerCase().includes(searchLower)) ||
                               (item.first_name && item.first_name.toLowerCase().includes(searchLower)) ||
                               (item.last_name && item.last_name.toLowerCase().includes(searchLower)) ||
                               (item.username && item.username.toLowerCase().includes(searchLower)) ||
                               item.user_id.toString().includes(searchLower);
                    }
                });
            }
            
            combined.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
            this.renderUserList(combined, 'mixed');
        } catch (error) {
            console.error('Load all users error:', error);
            this.showNotification('Ошибка загрузки пользователей', 'error');
        }
    }

    renderUserList(items, type) {
        const container = document.getElementById('users-container');
        if (!container) return;
        
        if (!items || items.length === 0) {
            container.innerHTML = '<div class="empty-state"><i class="fas fa-users"></i> Нет данных</div>';
            return;
        }
        
        let html = '';
        items.forEach(item => {
            if (item.type === 'it' || type === 'it') {
                // IT-пользователь
                const initials = (item.first_name?.[0] || '') + (item.last_name?.[0] || '');
                const isCurrentUser = item.telegram_id == this.user.telegram_id;
                const userName = `${item.first_name || ''} ${item.last_name || ''}`.trim() || 'Без имени';
                const username = item.username ? `@${item.username}` : 'нет username';
                const regDate = new Date(item.created_at).toLocaleDateString('ru-RU');
                
                html += `
                    <div class="user-item">
                        <div class="user-info">
                            <div class="user-avatar">${initials || '?'}</div>
                            <div class="user-details">
                                <div class="user-name">${userName}</div>
                                <div class="user-username">${username}</div>
                                <div class="user-details-row">
                                    <span class="user-status ${item.is_admin ? 'admin' : 'user'}">
                                        ${item.is_admin ? '👑 Администратор' : '👤 IT-специалист'}
                                    </span>
                                    <span class="user-telegram-id">ID: ${item.telegram_id}</span>
                                </div>
                                <div class="user-reg-date">📅 ${regDate}</div>
                            </div>
                        </div>
                        <div class="user-actions">
                            ${!isCurrentUser ? `
                                <button onclick="app.toggleUserAdmin(${item.id}, ${item.is_admin})" 
                                        class="btn btn-sm ${item.is_admin ? 'btn-warning' : 'btn-primary'}">
                                    ${item.is_admin ? '👑 Снять админа' : '👑 Назначить админом'}
                                </button>
                                <button onclick="app.deleteUser(${item.id})" class="btn btn-sm btn-danger">
                                    <i class="fas fa-trash"></i> Удалить
                                </button>
                            ` : `
                                <span style="color: var(--dynamo-blue); font-weight: 600;">👑 Вы (администратор)</span>
                            `}
                        </div>
                    </div>
                `;
            } else {
                // Заявитель
                const surname = item.surname || item.first_name || 'Без имени';
                const username = item.username ? `@${item.username}` : 'нет username';
                const regDate = new Date(item.created_at).toLocaleDateString('ru-RU');
                
                html += `
                    <div class="user-item">
                        <div class="user-info">
                            <div class="user-avatar">${surname.charAt(0)}</div>
                            <div class="user-details">
                                <div class="user-name">${surname}</div>
                                <div class="user-username">${username}</div>
                                <div class="user-details-row">
                                    <span class="user-status requester">🙋 Заявитель</span>
                                    <span class="user-telegram-id">ID: ${item.user_id}</span>
                                </div>
                                <div class="user-reg-date">📅 ${regDate}</div>
                                <div class="user-tickets-count">🎫 ${item.tickets_count || 0} заявок</div>
                            </div>
                        </div>
                        <div class="user-actions">
                            <button onclick="app.makeRequesterIT(${item.user_id})" class="btn btn-sm btn-primary">
                                👑 Сделать IT
                            </button>
                            <button onclick="app.deleteRequester(${item.user_id})" class="btn btn-sm btn-danger">
                                <i class="fas fa-trash"></i> Удалить
                            </button>
                        </div>
                    </div>
                `;
            }
        });
        
        container.innerHTML = html;
    }

    filterUsers() {
        const filter = document.getElementById('user-status-filter').value;
        const searchTerm = document.getElementById('search-users').value;
        
        if (filter === 'requester') {
            this.loadRequesters(searchTerm);
        } else if (filter === 'it') {
            this.loadUsers('it', searchTerm);
        } else if (filter === 'admin') {
            this.loadUsers('admin', searchTerm);
        } else if (filter === 'all') {
            this.loadAllUsers(searchTerm);
        }
    }

    // ========== СТАТИСТИКА ПО ТИКЕТАМ ==========
    async loadTicketStats() {
        try {
            const [itRes, requesterRes] = await Promise.all([
                this.fetchWithAuth('../api/tickets.php?source=it'),
                this.fetchWithAuth('../api/tickets.php?source=requester')
            ]);
            const itData = await itRes.json();
            const requesterData = await requesterRes.json();
            
            const itTickets = itData.success ? itData.tickets : [];
            const requesterTickets = requesterData.success ? requesterData.tickets : [];
            
            const totalIt = itTickets.length;
            const totalRequester = requesterTickets.length;
            const done = itTickets.filter(t => t.is_done == 1).length + requesterTickets.filter(t => t.is_done == 1).length;
            const inSn = itTickets.filter(t => t.is_in_db == 1).length;
            const pendingRequester = requesterTickets.filter(t => t.requester_status === 'pending' && t.is_done == 0).length;
            const inProgressRequester = requesterTickets.filter(t => t.requester_status === 'in_progress' && t.is_done == 0).length;
            
            document.getElementById('total-it-tickets').textContent = totalIt;
            document.getElementById('total-requester-tickets').textContent = totalRequester;
            document.getElementById('done-tickets').textContent = done;
            document.getElementById('in-sn-tickets').textContent = inSn;
            document.getElementById('pending-requester-tickets').textContent = pendingRequester;
            document.getElementById('in-progress-requester-tickets').textContent = inProgressRequester;
        } catch (error) {
            console.error('Load stats error:', error);
        }
    }

    // ========== ОРИГИНАЛЬНЫЕ МЕТОДЫ ДЛЯ IT ==========
    
    async fetchWithAuth(url, options = {}) {
        if (!authManager.user) throw new Error('Not authenticated');
        const defaultOptions = {
            headers: {
                'X-Telegram-User-ID': authManager.user.telegram_id.toString(),
                'Content-Type': 'application/json'
            }
        };
        return fetch(url, { ...defaultOptions, ...options });
    }

    initEvents() {
        const addEmployeeBtn = document.getElementById('add-employee-btn');
        if (addEmployeeBtn) {
            addEmployeeBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.addEmployee();
            });
        }

        const addUserBtn = document.getElementById('add-user-btn');
        if (addUserBtn) {
            addUserBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.addUser();
            });
        }

        const searchTickets = document.getElementById('search-tickets');
        if (searchTickets) {
            searchTickets.addEventListener('input', (e) => {
                this.filterTickets();
            });
        }

        const filterStatus = document.getElementById('filter-status');
        if (filterStatus) {
            filterStatus.addEventListener('change', () => {
                this.filterTickets();
            });
        }

        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const tabId = tab.dataset.tab;
                this.switchTab(tabId);
            });
        });

        const searchUsers = document.getElementById('search-users');
        if (searchUsers) {
            searchUsers.addEventListener('input', (e) => {
                clearTimeout(this.debounceTimer);
                this.debounceTimer = setTimeout(() => {
                    this.filterUsers();
                }, 300);
            });
        }

        const userStatusFilter = document.getElementById('user-status-filter');
        if (userStatusFilter) {
            userStatusFilter.addEventListener('change', () => {
                this.filterUsers();
            });
        }

        const searchRequesterTickets = document.getElementById('search-requester-tickets');
        if (searchRequesterTickets) {
            searchRequesterTickets.addEventListener('input', () => this.filterRequesterTickets());
        }

        const filterRequesterStatus = document.getElementById('filter-requester-status');
        if (filterRequesterStatus) {
            filterRequesterStatus.addEventListener('change', () => this.filterRequesterTickets());
        }
        
        const employeesHeader = document.querySelector('.employees-list h4');
        if (employeesHeader) {
            const newHeader = employeesHeader.cloneNode(true);
            employeesHeader.parentNode.replaceChild(newHeader, employeesHeader);
            newHeader.addEventListener('click', (e) => {
                if (e.target.tagName === 'I') return;
                this.toggleEmployeesBlock();
            });
        }
    }

    initEmployeeSearchEvents() {
        const employeeSearch = document.getElementById('employee-search');
        if (employeeSearch) {
            employeeSearch.addEventListener('input', (e) => {
                clearTimeout(this.debounceTimer);
                this.debounceTimer = setTimeout(() => {
                    this.searchEmployeesByFirstLetter(e.target.value);
                }, 300);
            });
        }

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.search-container')) {
                const dropdown = document.getElementById('employee-dropdown');
                if (dropdown) dropdown.style.display = 'none';
                const searchInput = document.getElementById('employee-search');
                if (searchInput) searchInput.style.borderRadius = '8px';
            }
        });
    }

    initTabNavigation() {
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const tabId = tab.dataset.tab;
                this.switchTab(tabId);
            });
        });
    }

    switchTab(tabId) {
        document.querySelectorAll('.tab').forEach(t => {
            t.classList.toggle('active', t.dataset.tab === tabId);
        });
        
        document.querySelectorAll('.tab-content').forEach(c => {
            c.classList.toggle('active', c.id === `tab-${tabId}`);
        });
        
        this.currentTab = tabId;
        
        if (tabId === 'settings' && this.user && this.user.is_admin) {
            this.loadAllEmployees();
            this.filterUsers();
            this.loadTicketStats();
        }
        
        if (tabId === 'requester-tickets' && this.user && this.user.is_admin) {
            this.loadRequesterTicketsForAdminTab();
        }
    }

    async loadEmployees() {
        try {
            const response = await this.fetchWithAuth('../api/employees.php');
            const data = await response.json();
            if (data.success) {
                this.employees = data.employees;
            } else {
                this.showNotification('Ошибка загрузки сотрудников: ' + (data.error || ''), 'error');
            }
        } catch (error) {
            console.error('Load employees error:', error);
            this.showNotification('Ошибка загрузки сотрудников', 'error');
        }
    }

    async loadAllEmployees() {
        try {
            const response = await this.fetchWithAuth('../api/employees.php');
            const data = await response.json();
            if (data.success) {
                this.renderEmployeesList(data.employees);
            }
        } catch (error) {
            console.error('Load employees list error:', error);
            this.showNotification('Ошибка загрузки списка сотрудников', 'error');
        }
    }

    searchEmployeesByFirstLetter(query) {
        const dropdown = document.getElementById('employee-dropdown');
        const searchInput = document.getElementById('employee-search');
        
        if (!query || query.trim().length === 0) {
            dropdown.style.display = 'none';
            searchInput.style.borderRadius = '8px';
            return;
        }
        
        const searchLetter = query.trim().toLowerCase();
        const filtered = this.employees.filter(emp => {
            const lastName = emp.full_name.split(' ')[0].toLowerCase();
            return lastName.startsWith(searchLetter);
        });
        
        this.updateEmployeesDropdown(filtered);
    }

    updateEmployeesDropdown(employees) {
        const dropdown = document.getElementById('employee-dropdown');
        const searchInput = document.getElementById('employee-search');
        
        dropdown.innerHTML = '';
        
        if (!employees || employees.length === 0) {
            dropdown.style.display = 'none';
            searchInput.style.borderRadius = '8px';
            return;
        }
        
        const displayEmployees = employees.slice(0, 10);
        
        displayEmployees.forEach(emp => {
            const div = document.createElement('div');
            div.className = 'dropdown-item';
            div.textContent = emp.full_name;
            div.dataset.id = emp.id;
            
            div.addEventListener('click', (e) => {
                e.stopPropagation();
                this.selectEmployee(emp);
            });
            
            dropdown.appendChild(div);
        });
        
        dropdown.style.display = 'block';
        searchInput.style.borderRadius = '8px 8px 0 0';
    }

    selectEmployee(employee) {
        const searchInput = document.getElementById('employee-search');
        const dropdown = document.getElementById('employee-dropdown');
        
        searchInput.value = employee.full_name;
        document.getElementById('employee-id').value = employee.id;
        dropdown.style.display = 'none';
        searchInput.style.borderRadius = '8px';
        
        const selectedDiv = document.getElementById('selected-employee');
        selectedDiv.innerHTML = `
            <span><strong>Выбран:</strong> ${employee.full_name}</span>
            <button type="button" onclick="app.clearEmployee()" class="btn btn-sm btn-danger">
                <i class="fas fa-times"></i> Сбросить
            </button>
        `;
        selectedDiv.style.display = 'flex';
    }

    clearEmployee() {
        const employeeId = document.getElementById('employee-id');
        const employeeSearch = document.getElementById('employee-search');
        const selectedDiv = document.getElementById('selected-employee');
        const dropdown = document.getElementById('employee-dropdown');
        
        if (employeeId) employeeId.value = '';
        if (employeeSearch) employeeSearch.value = '';
        if (selectedDiv) selectedDiv.style.display = 'none';
        if (dropdown) dropdown.style.display = 'none';
    }

    async addTicket() {
        const formData = {
            employee_id: document.getElementById('employee-id').value,
            date: document.getElementById('date').value,
            task: document.getElementById('task').value.trim(),
            is_done: document.getElementById('is-done').checked ? 1 : 0,
            is_in_db: document.getElementById('is-in-db').checked ? 1 : 0
        };
        
        if (!formData.employee_id) {
            this.showNotification('Выберите сотрудника', 'error');
            return;
        }
        if (!formData.task) {
            this.showNotification('Введите описание задачи', 'error');
            return;
        }
        if (!formData.date) {
            this.showNotification('Выберите дату', 'error');
            return;
        }
        
        const submitBtn = document.querySelector('#ticket-form button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Сохранение...';
        submitBtn.disabled = true;
        
        try {
            const response = await this.fetchWithAuth('../api/tickets.php', {
                method: 'POST',
                body: JSON.stringify(formData)
            });
            const data = await response.json();
            if (data.success) {
                this.showNotification('✅ Тикет успешно сохранен!', 'success');
                document.getElementById('ticket-form').reset();
                document.getElementById('date').value = new Date().toISOString().split('T')[0];
                this.clearEmployee();
                this.loadTickets();
                this.switchTab('list');
            } else {
                this.showNotification('❌ Ошибка при сохранении тикета: ' + (data.error || 'Неизвестная ошибка'), 'error');
            }
        } catch (error) {
            console.error('Add ticket error:', error);
            this.showNotification('❌ Ошибка соединения с сервером', 'error');
        } finally {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    }

    async loadTickets() {
        try {
            const response = await this.fetchWithAuth('../api/tickets.php');
            const data = await response.json();
            if (data.success) {
                this.tickets = data.tickets;
                this.renderTickets(data.tickets);
            } else {
                this.showNotification('Ошибка загрузки тикетов: ' + (data.error || ''), 'error');
            }
        } catch (error) {
            console.error('Load tickets error:', error);
            this.showNotification('Ошибка соединения с сервером', 'error');
        }
    }

    renderTickets(tickets) {
        const container = document.getElementById('tickets-list');
        
        if (!tickets || tickets.length === 0) {
            container.innerHTML = '<div class="empty-state">Нет сохраненных тикетов</div>';
            return;
        }
        
        container.innerHTML = tickets.map(ticket => {
            const ticketClass = this.getTicketClass(ticket);
            const ticketId = ticket.id;
            const cleanTask = ticket.task.trim();
            
            return `
                <div class="ticket-item ${ticketClass}" data-id="${ticketId}">
                    <div class="ticket-header">
                        <div class="ticket-header-left">
                            <span class="ticket-date">
                                <i class="far fa-calendar"></i> ${ticket.ticket_date}
                            </span>
                            <span class="ticket-employee">
                                <i class="fas fa-user"></i> ${ticket.employee_name}
                            </span>
                        </div>
                        <div class="ticket-actions">
                            <button onclick="app.deleteTicket(${ticketId})" class="btn btn-sm btn-danger">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="ticket-task-container">
                        <label>Описание задачи:</label>
                        <textarea class="ticket-task" 
                                  id="task-${ticketId}"
                                  placeholder="Опишите задачу..."
                                  oninput="app.onTicketChange(${ticketId}, 'task', this.value)">${cleanTask}</textarea>
                    </div>
                    
                    <div class="ticket-status">
                        <div class="status-control">
                            <label for="work-status-${ticketId}">
                                <i class="fas fa-tasks"></i> Статус работы:
                            </label>
                            <select id="work-status-${ticketId}" 
                                    class="work-status" 
                                    onchange="app.onTicketChange(${ticketId}, 'is_done', this.value)">
                                <option value="0" ${ticket.is_done == 0 ? 'selected' : ''}>В работе</option>
                                <option value="1" ${ticket.is_done == 1 ? 'selected' : ''}>Выполнено</option>
                            </select>
                        </div>
                        
                        <div class="status-control">
                            <label for="sn-status-${ticketId}">
                                <i class="fas fa-database"></i> Статус в SN:
                            </label>
                            <select id="sn-status-${ticketId}" 
                                    class="sn-status" 
                                    onchange="app.onTicketChange(${ticketId}, 'is_in_db', this.value)">
                                <option value="0" ${ticket.is_in_db == 0 ? 'selected' : ''}>Не в SN</option>
                                <option value="1" ${ticket.is_in_db == 1 ? 'selected' : ''}>Внесено в SN</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="save-changes" id="save-changes-${ticketId}">
                        <button onclick="app.saveTicketChanges(${ticketId})" class="btn btn-success btn-sm">
                            <i class="fas fa-save"></i> Сохранить изменения
                        </button>
                        <button onclick="app.cancelTicketChanges(${ticketId})" class="btn btn-danger btn-sm">
                            <i class="fas fa-times"></i> Отмена
                        </button>
                    </div>
                </div>
            `;
        }).join('');
        
        this.changes.clear();
    }

    getTicketClass(ticket) {
        let classes = [];
        if (ticket.is_done == 1) classes.push('done');
        if (ticket.is_in_db == 1) classes.push('in-sn');
        return classes.join(' ');
    }

    onTicketChange(ticketId, field, value) {
        if (!this.changes.has(ticketId)) {
            this.changes.set(ticketId, {});
        }
        const ticketChanges = this.changes.get(ticketId);
        ticketChanges[field] = field === 'task' ? value : parseInt(value);
        
        const saveButtons = document.getElementById(`save-changes-${ticketId}`);
        if (saveButtons) {
            saveButtons.classList.add('visible');
        }
        
        if (field === 'is_done' || field === 'is_in_db') {
            const ticketItem = document.querySelector(`.ticket-item[data-id="${ticketId}"]`);
            if (ticketItem) {
                const ticket = this.tickets.find(t => t.id == ticketId);
                const newIsDone = field === 'is_done' ? parseInt(value) : 
                    (ticketChanges.is_done !== undefined ? ticketChanges.is_done : ticket.is_done);
                const newIsInDb = field === 'is_in_db' ? parseInt(value) : 
                    (ticketChanges.is_in_db !== undefined ? ticketChanges.is_in_db : ticket.is_in_db);
                
                ticketItem.className = 'ticket-item';
                if (newIsDone == 1) ticketItem.classList.add('done');
                if (newIsInDb == 1) ticketItem.classList.add('in-sn');
            }
        }
    }

    async saveTicketChanges(ticketId) {
        if (!this.changes.has(ticketId)) {
            this.showNotification('Нет изменений для сохранения', 'info');
            return;
        }
        
        const changes = this.changes.get(ticketId);
        const savePromises = [];
        
        for (const [field, value] of Object.entries(changes)) {
            savePromises.push(this.updateTicketField(ticketId, field, value));
        }
        
        try {
            await Promise.all(savePromises);
            this.changes.delete(ticketId);
            
            const saveButtons = document.getElementById(`save-changes-${ticketId}`);
            if (saveButtons) {
                saveButtons.classList.remove('visible');
            }
            
            this.showNotification('✅ Изменения сохранены', 'success');
            this.loadTickets();
        } catch (error) {
            this.showNotification('❌ Ошибка сохранения изменений', 'error');
        }
    }

    cancelTicketChanges(ticketId) {
        this.changes.delete(ticketId);
        
        const saveButtons = document.getElementById(`save-changes-${ticketId}`);
        if (saveButtons) {
            saveButtons.classList.remove('visible');
        }
        
        const ticket = this.tickets.find(t => t.id == ticketId);
        if (ticket) {
            document.getElementById(`task-${ticketId}`).value = ticket.task.trim();
            document.getElementById(`work-status-${ticketId}`).value = ticket.is_done;
            document.getElementById(`sn-status-${ticketId}`).value = ticket.is_in_db;
            
            const ticketItem = document.querySelector(`.ticket-item[data-id="${ticketId}"]`);
            if (ticketItem) {
                ticketItem.className = 'ticket-item ' + this.getTicketClass(ticket);
            }
        }
    }

    async updateTicketField(ticketId, field, value) {
        try {
            const response = await this.fetchWithAuth('../api/tickets.php', {
                method: 'PUT',
                body: JSON.stringify({
                    id: ticketId,
                    field: field,
                    value: value
                })
            });
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.error || 'Ошибка обновления');
            }
            return data;
        } catch (error) {
            console.error('Update ticket field error:', error);
            throw error;
        }
    }

    async deleteTicket(ticketId) {
        if (!confirm('Удалить этот тикет? Это действие нельзя отменить.')) return;
        
        try {
            const response = await this.fetchWithAuth(`../api/tickets.php?id=${ticketId}`, {
                method: 'DELETE'
            });
            const data = await response.json();
            if (data.success) {
                this.showNotification('✅ Тикет удален', 'success');
                this.tickets = this.tickets.filter(ticket => ticket.id !== ticketId);
                this.renderTickets(this.tickets);
            } else {
                this.showNotification('❌ Ошибка удаления: ' + (data.error || ''), 'error');
            }
        } catch (error) {
            console.error('Delete ticket error:', error);
            this.showNotification('❌ Ошибка соединения', 'error');
        }
    }

    filterTickets() {
        const searchText = document.getElementById('search-tickets').value.toLowerCase();
        const filterStatus = document.getElementById('filter-status').value;
        
        const filtered = this.tickets.filter(ticket => {
            const matchesSearch = 
                ticket.task.toLowerCase().includes(searchText) ||
                ticket.employee_name.toLowerCase().includes(searchText) ||
                ticket.ticket_date.includes(searchText);
            
            let matchesStatus = true;
            if (filterStatus === 'pending') {
                matchesStatus = ticket.is_done == 0;
            } else if (filterStatus === 'done') {
                matchesStatus = ticket.is_done == 1;
            } else if (filterStatus === 'in_db') {
                matchesStatus = ticket.is_in_db == 1;
            }
            
            return matchesSearch && matchesStatus;
        });
        
        this.renderTickets(filtered);
    }

    async addEmployee() {
        const nameInput = document.getElementById('new-employee');
        let fullName = nameInput.value.trim();
        
        if (!fullName) {
            this.showNotification('Введите ФИО сотрудника', 'error');
            return;
        }
        
        const parts = fullName.split(' ');
        if (parts.length >= 2) {
            const lastName = parts[0];
            const firstLetter = parts[1].charAt(0).toUpperCase();
            fullName = `${lastName} ${firstLetter}.`;
        }
        
        try {
            const response = await this.fetchWithAuth('../api/employees.php', {
                method: 'POST',
                body: JSON.stringify({ full_name: fullName })
            });
            const data = await response.json();
            if (data.success) {
                this.showNotification('✅ Сотрудник добавлен', 'success');
                nameInput.value = '';
                this.loadEmployees();
                this.loadAllEmployees();
            } else {
                this.showNotification('❌ Ошибка добавления: ' + (data.error || ''), 'error');
            }
        } catch (error) {
            console.error('Add employee error:', error);
            this.showNotification('❌ Ошибка соединения с сервером', 'error');
        }
    }

    renderEmployeesList(employees) {
        const container = document.getElementById('employees-container');
        
        if (!employees || employees.length === 0) {
            container.innerHTML = '<div class="empty-state">Нет добавленных сотрудников</div>';
            return;
        }

        const groups = {};
        employees.forEach(emp => {
            const firstLetter = emp.full_name.charAt(0).toUpperCase();
            if (!groups[firstLetter]) groups[firstLetter] = [];
            groups[firstLetter].push(emp);
        });

        const sortedLetters = Object.keys(groups).sort((a, b) => a.localeCompare(b));

        let html = '';
        sortedLetters.forEach(letter => {
            const sortedEmployees = groups[letter].sort((a, b) => 
                a.full_name.localeCompare(b.full_name)
            );
            
            html += `<div class="employee-group" data-letter="${letter}">`;
            html += `
                <div class="employee-group-header" onclick="app.toggleGroup('${letter}')">
                    <span class="group-toggle-icon">▼</span>
                    <span class="employee-group-title">${letter}</span>
                    <span class="group-count">(${sortedEmployees.length})</span>
                </div>
            `;
            html += `<div class="employee-group-content">`;
            
            sortedEmployees.forEach(emp => {
                html += `
                    <div class="employee-item" data-id="${emp.id}">
                        <span>${emp.full_name}</span>
                        <div class="employee-actions">
                            <button onclick="event.stopPropagation(); app.deleteEmployee(${emp.id})" 
                                    class="btn btn-danger btn-sm">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
            });
            
            html += `</div></div>`;
        });

        container.innerHTML = html;
        
        sortedLetters.forEach(letter => {
            const collapsed = localStorage.getItem(`employee_group_collapsed_${letter}`) === 'true';
            const group = container.querySelector(`.employee-group[data-letter="${letter}"]`);
            if (group) {
                const content = group.querySelector('.employee-group-content');
                const icon = group.querySelector('.group-toggle-icon');
                if (collapsed) {
                    content.style.display = 'none';
                    if (icon) icon.textContent = '▶';
                }
            }
        });
    }

    toggleGroup(letter) {
        const group = document.querySelector(`.employee-group[data-letter="${letter}"]`);
        if (!group) return;
        
        const content = group.querySelector('.employee-group-content');
        const icon = group.querySelector('.group-toggle-icon');
        
        if (content.style.display === 'none') {
            content.style.display = 'block';
            if (icon) icon.textContent = '▼';
            localStorage.setItem(`employee_group_collapsed_${letter}`, 'false');
        } else {
            content.style.display = 'none';
            if (icon) icon.textContent = '▶';
            localStorage.setItem(`employee_group_collapsed_${letter}`, 'true');
        }
    }

    async deleteEmployee(id) {
        if (!confirm('Удалить сотрудника? Это действие нельзя отменить.')) return;
        
        try {
            const response = await this.fetchWithAuth(`../api/employees.php?id=${id}`, {
                method: 'DELETE'
            });
            const data = await response.json();
            if (data.success) {
                this.showNotification('✅ Сотрудник удален', 'success');
                this.loadEmployees();
                this.loadAllEmployees();
            } else {
                this.showNotification('❌ Ошибка удаления: ' + (data.error || ''), 'error');
            }
        } catch (error) {
            console.error('Delete employee error:', error);
            this.showNotification('❌ Ошибка соединения с сервером', 'error');
        }
    }

    // ========== МЕТОДЫ УДАЛЕНИЯ ПОЛЬЗОВАТЕЛЕЙ (КАСКАДНОЕ) ==========
    async deleteUser(userId) {
        if (!confirm('Удалить IT-специалиста? Все его тикеты также будут удалены!')) return;
        
        try {
            const response = await this.fetchWithAuth(`../api/users.php?id=${userId}`, {
                method: 'DELETE'
            });
            const data = await response.json();
            if (data.success) {
                this.showNotification('✅ Пользователь удален', 'success');
                this.filterUsers(); // обновляем список в соответствии с текущим фильтром
            } else {
                this.showNotification('❌ Ошибка удаления: ' + (data.error || ''), 'error');
            }
        } catch (error) {
            console.error('Delete user error:', error);
            this.showNotification('❌ Ошибка соединения', 'error');
        }
    }

    async deleteRequester(userId) {
        if (!confirm('Удалить заявителя? Все его заявки также будут удалены.')) return;
        try {
            const response = await this.fetchWithAuth(`../api/requesters.php?user_id=${userId}`, {
                method: 'DELETE'
            });
            const data = await response.json();
            if (data.success) {
                this.showNotification('✅ Заявитель удалён', 'success');
                this.filterUsers(); // обновляем список
            } else {
                this.showNotification('❌ Ошибка: ' + (data.error || ''), 'error');
            }
        } catch (error) {
            console.error('Delete requester error:', error);
            this.showNotification('❌ Ошибка соединения', 'error');
        }
    }

    async makeRequesterIT(userId) {
        if (!confirm('Перевести заявителя в IT-специалисты? Он будет удалён из списка заявителей и получит права IT.')) return;
        try {
            const response = await this.fetchWithAuth('../api/requesters.php', {
                method: 'PUT',
                body: JSON.stringify({ user_id: userId })
            });
            const data = await response.json();
            if (data.success) {
                this.showNotification('✅ Пользователь теперь IT-специалист', 'success');
                this.filterUsers();
            } else {
                this.showNotification('❌ Ошибка: ' + (data.error || ''), 'error');
            }
        } catch (error) {
            console.error('Make requester IT error:', error);
            this.showNotification('❌ Ошибка соединения', 'error');
        }
    }

    async toggleUserAdmin(userId, isCurrentlyAdmin) {
        if (!confirm(`Вы уверены, что хотите ${isCurrentlyAdmin ? 'снять' : 'назначить'} администратора?`)) return;
        
        try {
            const response = await this.fetchWithAuth('../api/users.php', {
                method: 'PUT',
                body: JSON.stringify({
                    id: userId,
                    is_admin: isCurrentlyAdmin ? 0 : 1
                })
            });
            const data = await response.json();
            if (data.success) {
                this.showNotification(
                    `✅ Пользователь ${isCurrentlyAdmin ? 'лишен прав' : 'назначен'} администратором`, 
                    'success'
                );
                this.filterUsers();
            } else {
                this.showNotification('❌ Ошибка обновления: ' + (data.error || ''), 'error');
            }
        } catch (error) {
            console.error('Toggle admin error:', error);
            this.showNotification('❌ Ошибка соединения', 'error');
        }
    }

    async addUser() {
        const telegramIdInput = document.getElementById('new-user-telegram-id');
        const usernameInput = document.getElementById('new-user-username');
        const firstNameInput = document.getElementById('new-user-first-name');
        const lastNameInput = document.getElementById('new-user-last-name');
        const isAdminCheckbox = document.getElementById('new-user-is-admin');
        
        const telegramId = telegramIdInput.value.trim();
        const username = usernameInput.value.trim();
        const firstName = firstNameInput.value.trim();
        const lastName = lastNameInput.value.trim();
        const isAdmin = isAdminCheckbox.checked ? 1 : 0;
        
        if (!telegramId) {
            this.showNotification('Введите Telegram ID', 'error');
            return;
        }
        if (!/^\d+$/.test(telegramId)) {
            this.showNotification('Telegram ID должен содержать только цифры', 'error');
            return;
        }
        if (!firstName) {
            this.showNotification('Введите имя пользователя', 'error');
            return;
        }
        
        const userData = {
            telegram_id: parseInt(telegramId),
            username: username,
            first_name: firstName,
            last_name: lastName,
            is_admin: isAdmin
        };
        
        try {
            const response = await this.fetchWithAuth('../api/users.php', {
                method: 'POST',
                body: JSON.stringify(userData)
            });
            const data = await response.json();
            if (data.success) {
                this.showNotification('✅ Пользователь добавлен', 'success');
                telegramIdInput.value = '';
                usernameInput.value = '';
                firstNameInput.value = '';
                lastNameInput.value = '';
                isAdminCheckbox.checked = false;
                this.filterUsers();
            } else {
                this.showNotification('❌ Ошибка добавления: ' + (data.error || ''), 'error');
            }
        } catch (error) {
            console.error('Add user error:', error);
            this.showNotification('❌ Ошибка соединения', 'error');
        }
    }

    showNotification(message, type = 'info') {
        const notification = document.getElementById('notification');
        const text = document.getElementById('notification-text');
        
        text.textContent = message;
        notification.className = `notification ${type}`;
        notification.classList.add('show');
        
        if (this.notificationTimer) {
            clearTimeout(this.notificationTimer);
        }
        
        this.notificationTimer = setTimeout(() => {
            notification.classList.remove('show');
        }, 3000);
    }

    toggleEmployeesBlock() {
        const employeesBlock = document.querySelector('.employees-list');
        if (!employeesBlock) return;
        
        const isCollapsed = employeesBlock.classList.contains('collapsed');
        
        if (isCollapsed) {
            employeesBlock.classList.remove('collapsed');
            localStorage.setItem('employees_block_collapsed', 'false');
            this.showNotification('Блок сотрудников развернут', 'info');
        } else {
            employeesBlock.classList.add('collapsed');
            localStorage.setItem('employees_block_collapsed', 'true');
            this.showNotification('Блок сотрудников свернут', 'info');
        }
    }
}

const App = new TicketApp();
window.app = App;