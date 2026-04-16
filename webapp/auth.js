class AuthManager {
    constructor() {
        this.user = null;
        this.tg = window.Telegram?.WebApp;
        this.init();
    }

    init() {
        if (this.tg) {
            this.tg.expand();
            this.tg.enableClosingConfirmation();
            this.tg.setHeaderColor('#0055A4');
            this.tg.setBackgroundColor('#FFFFFF');
            this.initUserFromTelegram();
        } else {
            console.log('Telegram Web App не обнаружен, режим разработки');
            this.showAuthScreen();
        }
    }

    initUserFromTelegram() {
        if (this.tg?.initDataUnsafe?.user) {
            const tgUser = this.tg.initDataUnsafe.user;
            console.log('Telegram user detected:', tgUser);
            
            fetch('../api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    telegram_id: tgUser.id,
                    username: tgUser.username || '',
                    first_name: tgUser.first_name || '',
                    last_name: tgUser.last_name || ''
                })
            })
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    this.user = data.user;
                    console.log('User authenticated:', this.user);
                    this.onAuthSuccess();
                } else {
                    console.error('Auth failed:', data.error);
                    this.showAuthScreen();
                }
            })
            .catch(error => {
                console.error('Auth error:', error);
                this.showAuthScreen();
            });
        } else {
            console.log('No Telegram user data');
            this.showAuthScreen();
        }
    }

    showAuthScreen() {
        const authScreen = document.getElementById('auth-screen');
        const mainScreen = document.getElementById('main-screen');
        if (authScreen && mainScreen) {
            authScreen.classList.remove('hidden');
            mainScreen.classList.add('hidden');
            
            const logoutBtn = document.getElementById('logout-btn');
            if (logoutBtn) logoutBtn.style.display = 'none';
            const usernameEl = document.getElementById('username');
            if (usernameEl) usernameEl.textContent = 'Вход не выполнен';
            
            const authBtn = document.getElementById('auth-btn');
            if (authBtn) {
                const newAuthBtn = authBtn.cloneNode(true);
                authBtn.parentNode.replaceChild(newAuthBtn, authBtn);
                newAuthBtn.addEventListener('click', () => {
                    if (this.tg) {
                        this.tg.openTelegramLink('https://t.me/thetickets_bot?start=webapp');
                    } else {
                        alert('Откройте приложение через Telegram бота The Tickets');
                    }
                });
            }
        }
    }

    onAuthSuccess() {
        const authScreen = document.getElementById('auth-screen');
        const mainScreen = document.getElementById('main-screen');
        if (authScreen && mainScreen) {
            authScreen.classList.add('hidden');
            mainScreen.classList.remove('hidden');
            
            const usernameEl = document.getElementById('username');
            if (usernameEl) {
                let displayName = '';
                if (this.user.role === 'it') {
                    displayName = (this.user.first_name || '') + ' ' + (this.user.last_name || '');
                } else {
                    displayName = this.user.surname || (this.user.first_name || '') + ' ' + (this.user.last_name || '');
                }
                usernameEl.textContent = displayName.trim() || 'Пользователь';
            }
            
            const logoutBtn = document.getElementById('logout-btn');
            if (logoutBtn) {
                logoutBtn.style.display = 'inline-flex';
                logoutBtn.replaceWith(logoutBtn.cloneNode(true));
                document.getElementById('logout-btn').addEventListener('click', () => this.logout());
            }
            
            // Инициализация приложения в зависимости от роли
            if (typeof App !== 'undefined') {
                App.initByRole(this.user);
            }
            
            // Сегодняшняя дата в форме
            const dateInput = document.getElementById('date');
            if (dateInput && !dateInput.value) {
                dateInput.value = new Date().toISOString().split('T')[0];
            }
        }
    }

    logout() {
        if (this.tg) {
            this.tg.close();
        } else {
            this.user = null;
            this.showAuthScreen();
            if (typeof App !== 'undefined' && typeof App.reset === 'function') {
                App.reset();
            }
        }
    }

    getUser() {
        return this.user;
    }

    isAdmin() {
        return this.user?.is_admin || false;
    }

    isIT() {
        return this.user?.role === 'it';
    }

    isRequester() {
        return this.user?.role === 'requester';
    }
}

const authManager = new AuthManager();
window.authManager = authManager;