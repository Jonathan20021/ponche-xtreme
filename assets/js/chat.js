/**
 * Sistema de Chat en Tiempo Real
 * JavaScript del lado del cliente
 */

class ChatApp {
    constructor() {
        this.currentConversationId = null;
        this.currentConversation = null;
        this.lastMessageId = 0;
        this.pollInterval = null;
        this.typingTimeout = null;
        this.searchTimeout = null;
        this.selectedUsers = [];
        this.unreadCount = 0;
        this.audioContext = null; // Para el sonido de notificación
        
        // Detección de dispositivo móvil y características
        this.isMobile = this.detectMobile();
        this.isTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
        this.screenWidth = window.innerWidth;
        
        this.init();
        this.setupResponsiveHandlers();
    }
    
    detectMobile() {
        return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) 
            || window.innerWidth <= 640;
    }
    
    setupResponsiveHandlers() {
        // Manejar cambios de orientación y tamaño de pantalla
        let resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                this.handleResize();
            }, 250);
        });
        
        // Manejar cambios de orientación
        window.addEventListener('orientationchange', () => {
            setTimeout(() => {
                this.handleResize();
            }, 100);
        });
        
        // Prevenir zoom en inputs en iOS
        if (this.isMobile && /iPhone|iPad|iPod/i.test(navigator.userAgent)) {
            const viewport = document.querySelector('meta[name=viewport]');
            if (viewport) {
                viewport.content = 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no';
            }
        }
    }
    
    handleResize() {
        const newWidth = window.innerWidth;
        const wasMobile = this.isMobile;
        this.isMobile = this.detectMobile();
        this.screenWidth = newWidth;
        
        // Si cambió de móvil a desktop o viceversa
        if (wasMobile !== this.isMobile) {
            console.log(`📱 Cambio de dispositivo detectado: ${this.isMobile ? 'Móvil' : 'Desktop'}`);
            this.adjustForDevice();
        }
        
        // Ajustar altura del contenedor de mensajes en móviles
        if (this.isMobile) {
            this.adjustMobileLayout();
        }
    }
    
    adjustForDevice() {
        const chatWindow = document.getElementById('chatWindow');
        if (chatWindow && chatWindow.classList.contains('open')) {
            // Recalcular layout del chat
            this.scrollToBottom();
        }
    }
    
    adjustMobileLayout() {
        // Ajustar altura considerando teclado virtual
        const vh = window.innerHeight * 0.01;
        document.documentElement.style.setProperty('--vh', `${vh}px`);
    }
    
    init() {
        console.log('🔧 Iniciando ChatApp.init()...');
        this.createChatWidget();
        console.log('📦 Widget creado');
        this.attachEventListeners();
        console.log('🔗 Event listeners adjuntados');
        
        // Agregar soporte para gestos táctiles en móviles
        if (this.isTouch) {
            this.setupTouchGestures();
        }
        
        this.startPolling();
        console.log('🔄 Polling iniciado');
        this.updateUserStatus('online');
        console.log('🟢 Estado actualizado a online');
        
        // Actualizar estado al cerrar/salir
        window.addEventListener('beforeunload', () => {
            this.updateUserStatus('offline');
        });
    }
    
    setupTouchGestures() {
        const chatWindow = document.getElementById('chatWindow');
        if (!chatWindow) return;
        
        let touchStartY = 0;
        let touchEndY = 0;
        
        // Gesto de deslizar hacia abajo para cerrar (solo en móviles)
        chatWindow.addEventListener('touchstart', (e) => {
            if (!this.isMobile) return;
            touchStartY = e.touches[0].clientY;
        }, { passive: true });
        
        chatWindow.addEventListener('touchend', (e) => {
            if (!this.isMobile) return;
            touchEndY = e.changedTouches[0].clientY;
            
            // Si deslizó hacia abajo más de 100px desde el header
            const header = chatWindow.querySelector('.chat-header');
            const headerRect = header ? header.getBoundingClientRect() : null;
            
            if (headerRect && touchStartY < headerRect.bottom && touchEndY - touchStartY > 100) {
                this.toggleChat();
            }
        }, { passive: true });
    }
    
    createChatWidget() {
        const widget = document.createElement('div');
        widget.className = 'chat-widget';
        widget.setAttribute('style', 'position: fixed !important; bottom: 20px !important; right: 20px !important; z-index: 2147483647 !important; pointer-events: auto !important; visibility: visible !important; display: block !important;');
        widget.innerHTML = `
            <button class="chat-toggle-btn" id="chatToggle">
                <i class="fas fa-comments"></i>
                <span class="unread-badge" id="unreadBadge" style="display: none;">0</span>
            </button>
            
            <div class="chat-window" id="chatWindow">
                <div class="chat-header">
                    <h3 class="chat-header-title">Chat</h3>
                    <div class="chat-header-actions">
                        <button class="chat-header-btn" id="newChatBtn" title="Nueva conversación">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="chat-header-btn" id="chatMinimize" title="Minimizar">
                            <i class="fas fa-minus"></i>
                        </button>
                    </div>
                </div>
                
                <div class="chat-tabs">
                    <button class="chat-tab active" data-tab="conversations">Conversaciones</button>
                    <button class="chat-tab" data-tab="online">En línea</button>
                </div>
                
                <div id="conversationsTab" class="chat-conversations"></div>
                
                <div id="messagesView" class="chat-messages-view">
                    <div class="chat-messages-header">
                        <button class="chat-back-btn" id="backToConversations">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <div class="chat-avatar" id="currentChatAvatar"></div>
                        <div class="chat-conversation-info" style="flex: 1;">
                            <div class="chat-conversation-name" id="currentChatName"></div>
                            <div class="chat-conversation-type" id="currentChatType" style="font-size: 11px; color: #94a3b8;"></div>
                        </div>
                        <button class="chat-input-btn" id="groupOptionsBtn" style="display: none;" title="Opciones del grupo">
                            <i class="fas fa-cog"></i>
                        </button>
                    </div>
                    
                    <div class="chat-messages-container" id="messagesContainer"></div>
                    
                    <div class="chat-typing-indicator" id="typingIndicator"></div>
                    
                    <div class="chat-input-container">
                        <div class="chat-input-wrapper">
                            <button class="chat-input-btn chat-attach-btn" id="attachBtn">
                                <i class="fas fa-paperclip"></i>
                            </button>
                            <textarea class="chat-input" id="messageInput" placeholder="Escribe un mensaje..." rows="1"></textarea>
                            <button class="chat-input-btn chat-send-btn" id="sendBtn">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Modal de nueva conversación -->
            <div class="chat-new-conversation-modal" id="newConversationModal">
                <div class="chat-modal-content">
                    <div class="chat-modal-header">
                        <h3 class="chat-modal-title">Nueva Conversación</h3>
                        <button class="chat-modal-close" id="closeModalBtn">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="chat-modal-body">
                        <!-- Tipo de conversación -->
                        <div class="chat-conversation-type" style="margin-bottom: 15px;">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 10px; background: rgba(99, 102, 241, 0.1); border-radius: 8px;">
                                <input type="checkbox" id="isGroupChat" style="width: 18px; height: 18px; cursor: pointer;">
                                <span style="font-weight: 500; color: #4f46e5;">
                                    <i class="fas fa-users"></i> Crear grupo de chat
                                </span>
                            </label>
                        </div>
                        
                        <!-- Nombre del grupo (solo visible si es grupo) -->
                        <div id="groupNameContainer" style="display: none; margin-bottom: 15px;">
                            <input type="text" class="chat-search-input" id="groupNameInput" placeholder="Nombre del grupo..." maxlength="100">
                        </div>
                        
                        <!-- Búsqueda de usuarios -->
                        <input type="text" class="chat-search-input" id="userSearchInput" placeholder="Buscar usuarios...">
                        <div class="chat-user-list" id="userList"></div>
                        
                        <!-- Contador de usuarios seleccionados -->
                        <div id="selectedUsersCount" style="padding: 8px; text-align: center; color: #64748b; font-size: 13px; display: none;"></div>
                    </div>
                    <div class="chat-modal-footer">
                        <button class="chat-modal-btn chat-modal-btn-secondary" id="cancelModalBtn">Cancelar</button>
                        <button class="chat-modal-btn chat-modal-btn-primary" id="startChatBtn">Iniciar Chat</button>
                    </div>
                </div>
            </div>
            
            <input type="file" id="fileInput" style="display: none;" accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar">
        `;
        
        // Esperar a que el body esté completamente cargado
        const appendWidget = () => {
            // Remover cualquier instancia anterior
            const oldWidget = document.querySelector('.chat-widget');
            if (oldWidget) oldWidget.remove();
            
            // Crear un contenedor portal independiente con atributos inline
            const portal = document.createElement('div');
            portal.id = 'chat-portal';
            portal.setAttribute('style', 'position: fixed !important; top: 0 !important; left: 0 !important; width: 100vw !important; height: 100vh !important; pointer-events: none !important; z-index: 2147483647 !important; transform: none !important; overflow: visible !important;');
            
            // Agregar estilos al widget con setAttribute para máxima prioridad
            widget.setAttribute('style', widget.getAttribute('style') + '; position: fixed !important; bottom: 20px !important; right: 20px !important; z-index: 2147483647 !important; visibility: visible !important; display: block !important; pointer-events: auto !important;');
            
            // Agregar widget al portal
            portal.appendChild(widget);
            
            // SOLUCIÓN DEFINITIVA: Agregar portal al <html> en lugar del <body>
            // Esto escapa completamente de cualquier limitación del body
            document.documentElement.appendChild(portal);
            
            console.log('✅ Portal agregado directamente al <html>');
            
            // Forzar que el portal esté siempre al final del HTML
            setInterval(() => {
                if (portal.parentElement === document.documentElement && portal !== document.documentElement.lastElementChild) {
                    document.documentElement.appendChild(portal);
                }
            }, 1000);
            
            console.log('✅ Widget agregado al portal independiente');
        };
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', appendWidget);
        } else {
            appendWidget();
        }
    }
    
    attachEventListeners() {
        // Toggle chat
        document.getElementById('chatToggle').addEventListener('click', () => {
            this.toggleChat();
        });
        
        document.getElementById('chatMinimize').addEventListener('click', () => {
            this.toggleChat();
        });
        
        // Nueva conversación
        document.getElementById('newChatBtn').addEventListener('click', () => {
            this.openNewConversationModal();
        });
        
        document.getElementById('closeModalBtn').addEventListener('click', () => {
            this.closeNewConversationModal();
        });
        
        document.getElementById('cancelModalBtn').addEventListener('click', () => {
            this.closeNewConversationModal();
        });
        
        document.getElementById('startChatBtn').addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.startNewConversation();
        });
        
        // Toggle tipo de conversación (directo o grupo)
        document.getElementById('isGroupChat').addEventListener('change', (e) => {
            const isGroup = e.target.checked;
            const groupNameContainer = document.getElementById('groupNameContainer');
            const selectedCountDiv = document.getElementById('selectedUsersCount');
            
            if (isGroup) {
                groupNameContainer.style.display = 'block';
                selectedCountDiv.style.display = 'block';
                document.getElementById('groupNameInput').focus();
            } else {
                groupNameContainer.style.display = 'none';
                selectedCountDiv.style.display = 'none';
            }
        });
        
        // Buscar usuarios en tiempo real con debounce
        document.getElementById('userSearchInput').addEventListener('input', (e) => {
            // Cancelar búsqueda anterior si existe
            if (this.searchTimeout) {
                clearTimeout(this.searchTimeout);
            }
            
            const value = e.target.value.trim();
            
            // Si está vacío, mostrar lista vacía
            if (value.length === 0) {
                this.renderUserList([]);
                return;
            }
            
            // Esperar 300ms antes de buscar (debounce)
            this.searchTimeout = setTimeout(() => {
                this.searchUsers(value);
            }, 300);
        });
        
        // Enviar mensaje
        document.getElementById('sendBtn').addEventListener('click', () => {
            this.sendMessage();
        });
        
        document.getElementById('messageInput').addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.sendMessage();
            }
        });
        
        // Indicador de escritura
        document.getElementById('messageInput').addEventListener('input', () => {
            this.handleTyping();
        });
        
        // Volver a conversaciones
        document.getElementById('backToConversations').addEventListener('click', () => {
            this.showConversations();
        });
        
        // Adjuntar archivo
        document.getElementById('attachBtn').addEventListener('click', () => {
            document.getElementById('fileInput').click();
        });
        
        document.getElementById('fileInput').addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                this.uploadFile(e.target.files[0]);
            }
        });
        
        // Tabs
        document.querySelectorAll('.chat-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                this.switchTab(tab.dataset.tab);
            });
        });
        
        // Auto-resize textarea
        const textarea = document.getElementById('messageInput');
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 100) + 'px';
        });
    }
    
    toggleChat() {
        const chatWindow = document.getElementById('chatWindow');
        const isOpening = !chatWindow.classList.contains('open');
        
        chatWindow.classList.toggle('open');
        
        if (isOpening) {
            this.loadConversations();
            this.updateUnreadCount();
            
            // En móviles, prevenir scroll del body cuando el chat está abierto
            if (this.isMobile) {
                document.body.style.overflow = 'hidden';
                this.adjustMobileLayout();
            }
            
            // Focus en el input si hay una conversación abierta
            if (this.currentConversationId && !this.isMobile) {
                setTimeout(() => {
                    const input = document.getElementById('messageInput');
                    if (input) input.focus();
                }, 300);
            }
        } else {
            // Restaurar scroll del body
            if (this.isMobile) {
                document.body.style.overflow = '';
            }
        }
    }
    
    switchTab(tab) {
        document.querySelectorAll('.chat-tab').forEach(t => t.classList.remove('active'));
        document.querySelector(`[data-tab="${tab}"]`).classList.add('active');
        
        if (tab === 'conversations') {
            this.loadConversations();
        } else if (tab === 'online') {
            this.loadOnlineUsers();
        }
    }
    
    async loadConversations() {
        try {
            const basePath = this.getBasePath();
            const response = await fetch(`${basePath}api.php?action=get_conversations`);
            const data = await response.json();
            
            if (data.success) {
                this.renderConversations(data.conversations);
            }
        } catch (error) {
            console.error('Error loading conversations:', error);
        }
    }
    
    renderConversations(conversations) {
        const container = document.getElementById('conversationsTab');
        
        if (conversations.length === 0) {
            container.innerHTML = `
                <div class="chat-empty-state">
                    <i class="fas fa-comments"></i>
                    <p>No tienes conversaciones aún.<br>Inicia una nueva conversación.</p>
                </div>
            `;
            return;
        }
        
        container.innerHTML = conversations.map(conv => `
            <div class="chat-conversation-item ${conv.unread_count > 0 ? 'unread' : ''}" data-id="${conv.id}">
                <div class="chat-avatar">
                    ${this.getInitials(conv.display_name || 'Chat')}
                </div>
                <div class="chat-conversation-info">
                    <div class="chat-conversation-name">
                        <span>${this.escapeHtml(conv.display_name || 'Sin nombre')}</span>
                        ${conv.unread_count > 0 ? `<span class="chat-conversation-unread">${conv.unread_count}</span>` : ''}
                    </div>
                    <div class="chat-conversation-last-message">
                        ${conv.last_sender ? this.escapeHtml(conv.last_sender) + ': ' : ''}
                        ${this.escapeHtml(conv.last_message || 'Sin mensajes')}
                    </div>
                </div>
                <div class="chat-conversation-time">
                    ${this.formatTime(conv.last_message_at)}
                </div>
            </div>
        `).join('');
        
        // Agregar listeners
        container.querySelectorAll('.chat-conversation-item').forEach(item => {
            item.addEventListener('click', () => {
                this.openConversation(parseInt(item.dataset.id));
            });
        });
    }
    
    async openConversation(conversationId) {
        console.log('📖 Abriendo conversación:', conversationId);
        
        // IMPORTANTE: Limpiar estado anterior
        this.currentConversationId = conversationId;
        this.lastMessageId = 0;
        
        // Limpiar contenedor de mensajes INMEDIATAMENTE
        const messagesContainer = document.getElementById('messagesContainer');
        messagesContainer.innerHTML = '<div style="text-align:center;padding:20px;color:#94a3b8;">Cargando mensajes...</div>';
        
        // Obtener info de la conversación
        const conversations = await this.fetchConversations();
        const conversation = conversations.find(c => c.id === conversationId);
        
        console.log('Conversación encontrada:', conversation);
        
        if (conversation) {
            const displayName = conversation.display_name || 'Chat';
            const isGroup = conversation.type === 'group';
            console.log('Display name:', displayName);
            document.getElementById('currentChatName').textContent = displayName;
            document.getElementById('currentChatAvatar').textContent = this.getInitials(displayName);
            
            // Mostrar tipo de conversación
            const typeDiv = document.getElementById('currentChatType');
            if (isGroup) {
                typeDiv.textContent = 'Grupo';
                typeDiv.style.display = 'block';
                document.getElementById('groupOptionsBtn').style.display = 'block';
            } else {
                typeDiv.textContent = '';
                typeDiv.style.display = 'none';
                document.getElementById('groupOptionsBtn').style.display = 'none';
            }
            
            // Guardar datos de la conversación actual
            this.currentConversation = conversation;
        } else {
            console.warn('⚠️ Conversación no encontrada en la lista');
            document.getElementById('currentChatName').textContent = 'Chat';
            document.getElementById('currentChatAvatar').textContent = '?';
            document.getElementById('currentChatType').textContent = '';
            document.getElementById('groupOptionsBtn').style.display = 'none';
            this.currentConversation = null;
        }
        
        // Mostrar vista de mensajes
        document.getElementById('conversationsTab').style.display = 'none';
        document.getElementById('messagesView').classList.add('active');
        
        // Limpiar mensajes antiguos antes de cargar nuevos
        messagesContainer.innerHTML = '';
        
        // Cargar mensajes de esta conversación
        await this.loadMessages();
        
        // Marcar como leído
        this.markAsRead(conversationId);
    }
    
    async loadMessages() {
        if (!this.currentConversationId) {
            console.warn('⚠️ No hay conversación actual seleccionada');
            return;
        }
        
        try {
            const basePath = this.getBasePath();
            
            // Si lastMessageId es 0, cargamos TODOS los mensajes (primera carga)
            // Si lastMessageId > 0, solo cargamos mensajes nuevos (polling)
            const isInitialLoad = this.lastMessageId === 0;
            const url = `${basePath}api.php?action=get_messages&conversation_id=${this.currentConversationId}&last_message_id=${this.lastMessageId}`;
            
            console.log('📥 Cargando mensajes:', {
                conversationId: this.currentConversationId,
                lastMessageId: this.lastMessageId,
                isInitialLoad: isInitialLoad
            });
            
            const response = await fetch(url);
            const data = await response.json();
            
            if (data.success) {
                if (data.messages && data.messages.length > 0) {
                    // Verificar si hay mensajes nuevos de otros usuarios (solo para sonido)
                    const currentUserId = this.getCurrentUserId();
                    const hasNewMessagesFromOthers = data.messages.some(msg => msg.user_id != currentUserId);
                    
                    // Renderizar mensajes (append solo si no es carga inicial)
                    this.renderMessages(data.messages, !isInitialLoad);
                    
                    // Actualizar último ID
                    this.lastMessageId = Math.max(...data.messages.map(m => m.id));
                    
                    // Scroll al final
                    this.scrollToBottom();
                    
                    // Reproducir sonido solo si hay mensajes de otros usuarios Y no es carga inicial
                    if (hasNewMessagesFromOthers && !isInitialLoad) {
                        this.playNotificationSound();
                    }
                    
                    console.log('✅ Mensajes cargados:', data.messages.length, 'último ID:', this.lastMessageId);
                } else if (isInitialLoad) {
                    // No hay mensajes en esta conversación
                    const container = document.getElementById('messagesContainer');
                    container.innerHTML = '<div style="text-align:center;padding:20px;color:#64748b;">No hay mensajes aún. ¡Inicia la conversación!</div>';
                }
            } else {
                console.error('❌ Error al cargar mensajes:', data.error);
            }
        } catch (error) {
            console.error('❌ Error de red al cargar mensajes:', error);
        }
    }
    
    renderMessages(messages, append = true) {
        const container = document.getElementById('messagesContainer');
        
        if (!append) {
            container.innerHTML = '';
        }
        
        messages.forEach(msg => {
            // No agregar si ya existe
            if (container.querySelector(`[data-id="${msg.id}"]`)) {
                return;
            }
            
            const isOwn = msg.user_id == this.getCurrentUserId();
            const messageEl = document.createElement('div');
            messageEl.className = `chat-message ${isOwn ? 'own' : ''}`;
            messageEl.dataset.id = msg.id;
            
            let attachmentsHtml = '';
            if (msg.attachments && msg.attachments.length > 0) {
                attachmentsHtml = msg.attachments.map(att => {
                    const basePath = this.getBasePath();
                    if (att.file_type === 'image') {
                        return `<div class="chat-attachment">
                            <img src="${basePath}serve.php?file=${att.file_name}" 
                                 class="chat-attachment-image" 
                                 alt="${this.escapeHtml(att.file_original_name)}"
                                 onerror="this.style.display='none'; this.parentElement.innerHTML='<div style=\\'padding:20px;text-align:center;color:#94a3b8;\\'>📷 Imagen no disponible</div>'">
                        </div>`;
                    } else {
                        return `<div class="chat-attachment">
                            <div class="chat-attachment-file" onclick="window.open('${basePath}serve.php?file=${att.file_name}')">
                                <div class="chat-attachment-icon">
                                    <i class="fas fa-file"></i>
                                </div>
                                <div class="chat-attachment-info">
                                    <div class="chat-attachment-name">${this.escapeHtml(att.file_original_name)}</div>
                                    <div class="chat-attachment-size">${this.formatFileSize(att.file_size)}</div>
                                </div>
                            </div>
                        </div>`;
                    }
                }).join('');
            }
            
            let reactionsHtml = '';
            if (msg.reactions && msg.reactions.length > 0) {
                const groupedReactions = {};
                msg.reactions.forEach(r => {
                    if (!groupedReactions[r.reaction]) {
                        groupedReactions[r.reaction] = [];
                    }
                    groupedReactions[r.reaction].push(r);
                });
                
                reactionsHtml = '<div class="chat-reactions">' + 
                    Object.entries(groupedReactions).map(([emoji, users]) => {
                        const isOwn = users.some(u => u.user_id == this.getCurrentUserId());
                        return `<div class="chat-reaction ${isOwn ? 'own' : ''}">${emoji} ${users.length}</div>`;
                    }).join('') + 
                '</div>';
            }
            
            messageEl.innerHTML = `
                ${!isOwn ? `<div class="chat-message-avatar">${this.getInitials(msg.full_name)}</div>` : ''}
                <div class="chat-message-content">
                    ${!isOwn ? `<div class="chat-message-meta"><strong>${this.escapeHtml(msg.full_name)}</strong></div>` : ''}
                    <div class="chat-message-bubble">
                        ${this.escapeHtml(msg.message_text)}
                        ${attachmentsHtml}
                    </div>
                    ${reactionsHtml}
                    <div class="chat-message-meta">
                        <span>${this.formatTime(msg.created_at)}</span>
                        ${msg.is_edited ? '<span class="chat-message-edited">(editado)</span>' : ''}
                    </div>
                </div>
                ${isOwn ? `<div class="chat-message-avatar">${this.getInitials(msg.full_name)}</div>` : ''}
            `;
            
            container.appendChild(messageEl);
        });
    }
    
    async sendMessage() {
        const input = document.getElementById('messageInput');
        const text = input.value.trim();
        
        if (!text || !this.currentConversationId) return;
        
        try {
            const basePath = this.getBasePath();
            const response = await fetch(`${basePath}api.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'send_message',
                    conversation_id: this.currentConversationId,
                    message_text: text
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                input.value = '';
                input.style.height = 'auto';
                await this.loadMessages();
                this.scrollToBottom();
            }
        } catch (error) {
            console.error('Error sending message:', error);
        }
    }
    
    async uploadFile(file) {
        if (!this.currentConversationId) return;
        
        const formData = new FormData();
        formData.append('file', file);
        formData.append('conversation_id', this.currentConversationId);
        formData.append('message_text', '');
        
        try {
            const basePath = this.getBasePath();
            const response = await fetch(`${basePath}upload.php`, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                await this.loadMessages();
                this.scrollToBottom();
                document.getElementById('fileInput').value = '';
            } else {
                alert('Error al subir archivo: ' + (data.error || 'Error desconocido'));
            }
        } catch (error) {
            console.error('Error uploading file:', error);
            alert('Error al subir archivo');
        }
    }
    
    showConversations() {
        document.getElementById('messagesView').classList.remove('active');
        document.getElementById('conversationsTab').style.display = 'block';
        this.currentConversationId = null;
        this.lastMessageId = 0;
        
        // Recargar la lista de conversaciones para actualizar los badges
        this.loadConversations();
        
        // Restaurar scroll suave en móviles
        if (this.isMobile) {
            const conversationsTab = document.getElementById('conversationsTab');
            if (conversationsTab) {
                conversationsTab.scrollTop = 0;
            }
        }
    }
    
    async openNewConversationModal() {
        document.getElementById('newConversationModal').classList.add('open');
        this.selectedUsers = [];
        document.getElementById('userSearchInput').value = '';
        document.getElementById('isGroupChat').checked = false;
        document.getElementById('groupNameInput').value = '';
        document.getElementById('groupNameContainer').style.display = 'none';
        document.getElementById('selectedUsersCount').style.display = 'none';
        
        // Mostrar mensaje inicial
        const container = document.getElementById('userList');
        container.innerHTML = `
            <div class="chat-empty-state" style="padding: 40px;">
                <i class="fas fa-search"></i>
                <p style="margin-top: 10px;">Escribe para buscar usuarios</p>
            </div>
        `;
    }
    
    closeNewConversationModal() {
        document.getElementById('newConversationModal').classList.remove('open');
        document.getElementById('userSearchInput').value = '';
    }
    
    async searchUsers(query) {
        const container = document.getElementById('userList');
        
        try {
            // Mostrar indicador de carga
            container.innerHTML = `
                <div class="chat-empty-state" style="padding: 20px;">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p style="margin-top: 10px;">Buscando usuarios...</p>
                </div>
            `;
            
            const basePath = this.getBasePath();
            const url = `${basePath}api.php?action=search_users&q=${encodeURIComponent(query)}`;
            console.log('Buscando usuarios en:', url);
            
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            console.log('Respuesta de búsqueda:', data);
            
            if (data.success) {
                if (data.users.length === 0) {
                    container.innerHTML = `
                        <div class="chat-empty-state" style="padding: 20px;">
                            <i class="fas fa-search"></i>
                            <p style="margin-top: 10px;">No se encontraron usuarios</p>
                        </div>
                    `;
                } else {
                    this.renderUserList(data.users);
                }
            } else {
                throw new Error(data.error || 'Error desconocido');
            }
        } catch (error) {
            console.error('Error searching users:', error);
            container.innerHTML = `
                <div class="chat-empty-state" style="padding: 20px; color: var(--text-muted, #ef4444);">
                    <i class="fas fa-exclamation-circle"></i>
                    <p style="margin-top: 10px;">Error al buscar usuarios</p>
                    <p style="font-size: 12px; margin-top: 5px;">${error.message}</p>
                </div>
            `;
        }
    }
    
    renderUserList(users) {
        const container = document.getElementById('userList');
        
        container.innerHTML = users.map(user => `
            <div class="chat-user-item" data-id="${user.id}">
                <div class="chat-avatar">
                    ${this.getInitials(user.full_name)}
                </div>
                <div class="chat-user-info">
                    <div class="chat-user-name">${this.escapeHtml(user.full_name)}</div>
                    <div class="chat-user-role">@${this.escapeHtml(user.username)} • ${this.escapeHtml(user.role)}</div>
                </div>
            </div>
        `).join('');
        
        // Agregar listeners
        container.querySelectorAll('.chat-user-item').forEach(item => {
            item.addEventListener('click', () => {
                const userId = parseInt(item.dataset.id);
                this.toggleUserSelection(userId, item);
            });
        });
    }
    
    toggleUserSelection(userId, element) {
        const index = this.selectedUsers.indexOf(userId);
        const isGroup = document.getElementById('isGroupChat').checked;
        
        if (index > -1) {
            this.selectedUsers.splice(index, 1);
            element.classList.remove('selected');
        } else {
            // Si no es grupo, limpiar selección previa (solo permite 1 usuario)
            if (!isGroup && this.selectedUsers.length > 0) {
                document.querySelectorAll('.chat-user-item.selected').forEach(item => {
                    item.classList.remove('selected');
                });
                this.selectedUsers = [];
            }
            this.selectedUsers.push(userId);
            element.classList.add('selected');
        }
        
        // Actualizar contador
        this.updateSelectedUsersCount();
    }
    
    updateSelectedUsersCount() {
        const countDiv = document.getElementById('selectedUsersCount');
        const isGroup = document.getElementById('isGroupChat').checked;
        
        if (isGroup && this.selectedUsers.length > 0) {
            countDiv.textContent = `${this.selectedUsers.length} usuario(s) seleccionado(s)`;
            countDiv.style.display = 'block';
        } else if (!isGroup) {
            countDiv.style.display = 'none';
        }
    }
    
    async startNewConversation() {
        if (this.selectedUsers.length === 0) {
            alert('Selecciona al menos un usuario');
            return;
        }
        
        const isGroup = document.getElementById('isGroupChat').checked;
        const groupName = document.getElementById('groupNameInput').value.trim();
        
        // Validar nombre del grupo si es necesario
        if (isGroup) {
            if (!groupName) {
                alert('El nombre del grupo es requerido');
                document.getElementById('groupNameInput').focus();
                return;
            }
            if (this.selectedUsers.length < 2) {
                alert('Selecciona al menos 2 usuarios para crear un grupo');
                return;
            }
        }
        
        try {
            const basePath = this.getBasePath();
            const url = `${basePath}api.php`;
            const payload = {
                action: 'create_conversation',
                type: isGroup ? 'group' : 'direct',
                participants: this.selectedUsers,
                name: isGroup ? groupName : null
            };
            
            console.log('🚀 Creando conversación...');
            console.log('URL:', url);
            console.log('Method: POST');
            console.log('Payload:', payload);
            
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            
            // Mostrar respuesta completa si hay error
            const text = await response.text();
            console.log('📩 Respuesta del servidor:', text);
            
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('❌ Error parseando JSON. Respuesta del servidor:', text);
                alert('Error del servidor. Revisa la consola para más detalles.');
                return;
            }
            
            if (data.success) {
                this.closeNewConversationModal();
                await this.loadConversations();
                this.openConversation(data.conversation_id);
            } else {
                alert('Error: ' + (data.error || 'Error desconocido'));
            }
        } catch (error) {
            console.error('Error creating conversation:', error);
            alert('Error de conexión: ' + error.message);
        }
    }
    
    async markAsRead(conversationId) {
        try {
            const basePath = this.getBasePath();
            await fetch(`${basePath}api.php?action=mark_as_read`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `conversation_id=${conversationId}`
            });
            
            // Actualizar el badge global y recargar conversaciones
            this.updateUnreadCount();
            
            // Si estamos en la vista de conversaciones, actualizarlas también
            const conversationsTab = document.getElementById('conversationsTab');
            if (conversationsTab && conversationsTab.children.length > 0) {
                this.loadConversations();
            }
        } catch (error) {
            console.error('Error marking as read:', error);
        }
    }
    
    async updateUnreadCount() {
        try {
            const basePath = this.getBasePath();
            const response = await fetch(`${basePath}api.php?action=get_unread_count`);
            const data = await response.json();
            
            if (data.success) {
                this.unreadCount = data.unread_count;
                const badge = document.getElementById('unreadBadge');
                
                if (this.unreadCount > 0) {
                    badge.textContent = this.unreadCount > 99 ? '99+' : this.unreadCount;
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
            }
        } catch (error) {
            console.error('Error updating unread count:', error);
        }
    }
    
    async updateUserStatus(status) {
        try {
            const basePath = this.getBasePath();
            await fetch(`${basePath}api.php?action=update_status`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `status=${status}`
            });
        } catch (error) {
            console.error('Error updating status:', error);
        }
    }
    
    async loadOnlineUsers() {
        try {
            const basePath = this.getBasePath();
            const response = await fetch(`${basePath}api.php?action=get_online_users`);
            const data = await response.json();
            
            if (data.success) {
                const container = document.getElementById('conversationsTab');
                
                container.innerHTML = data.users.map(user => `
                    <div class="chat-conversation-item" data-user-id="${user.id}">
                        <div class="chat-avatar online">
                            ${this.getInitials(user.full_name)}
                        </div>
                        <div class="chat-conversation-info">
                            <div class="chat-conversation-name">${this.escapeHtml(user.full_name)}</div>
                            <div class="chat-conversation-last-message">@${this.escapeHtml(user.username)}</div>
                        </div>
                    </div>
                `).join('');
                
                // Agregar listeners para iniciar chat
                container.querySelectorAll('.chat-conversation-item').forEach(item => {
                    item.addEventListener('click', async () => {
                        const userId = parseInt(item.dataset.userId);
                        this.selectedUsers = [userId];
                        await this.startNewConversation();
                    });
                });
            }
        } catch (error) {
            console.error('Error loading online users:', error);
        }
    }
    
    handleTyping() {
        if (!this.currentConversationId) return;
        
        // Enviar indicador de escritura
        const basePath = this.getBasePath();
        fetch(`${basePath}api.php?action=typing`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `conversation_id=${this.currentConversationId}`
        });
    }
    
    async checkTyping() {
        if (!this.currentConversationId) return;
        
        try {
            const basePath = this.getBasePath();
            const response = await fetch(`${basePath}api.php?action=get_typing&conversation_id=${this.currentConversationId}`);
            const data = await response.json();
            
            if (data.success && data.typing_users.length > 0) {
                const indicator = document.getElementById('typingIndicator');
                indicator.textContent = `${data.typing_users.join(', ')} está escribiendo...`;
                indicator.classList.add('active');
            } else {
                document.getElementById('typingIndicator').classList.remove('active');
            }
        } catch (error) {
            console.error('Error checking typing:', error);
        }
    }
    
    startPolling() {
        // Polling cada 2 segundos para nuevos mensajes
        this.pollInterval = setInterval(() => {
            if (this.currentConversationId) {
                this.loadMessages();
                this.checkTyping();
            } else {
                // Si no hay conversación abierta, actualizar la lista de conversaciones
                const conversationsTab = document.getElementById('conversationsTab');
                const messagesView = document.getElementById('messagesView');
                if (conversationsTab && !messagesView.classList.contains('active')) {
                    this.loadConversations();
                }
            }
            this.updateUnreadCount();
        }, 2000);
    }
    
    async fetchConversations() {
        try {
            const basePath = this.getBasePath();
            const response = await fetch(`${basePath}api.php?action=get_conversations`);
            const data = await response.json();
            
            if (data.success) {
                console.log('📋 Conversaciones obtenidas:', data.conversations.length);
                return data.conversations;
            } else {
                console.error('❌ Error al obtener conversaciones:', data.error);
                return [];
            }
        } catch (error) {
            console.error('❌ Error de red al obtener conversaciones:', error);
            return [];
        }
    }
    
    scrollToBottom(smooth = false) {
        const container = document.getElementById('messagesContainer');
        if (!container) return;
        
        if (smooth && 'scrollBehavior' in document.documentElement.style) {
            container.scrollTo({
                top: container.scrollHeight,
                behavior: 'smooth'
            });
        } else {
            container.scrollTop = container.scrollHeight;
        }
        
        // En móviles, asegurar que el input sea visible
        if (this.isMobile && this.currentConversationId) {
            setTimeout(() => {
                const inputContainer = document.querySelector('.chat-input-container');
                if (inputContainer) {
                    inputContainer.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            }, 100);
        }
    }
    
    // Reproducir sonido de notificación
    playNotificationSound() {
        try {
            // Inicializar AudioContext si no existe
            if (!this.audioContext) {
                this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
            }
            
            const ctx = this.audioContext;
            const currentTime = ctx.currentTime;
            
            // Crear oscilador para el sonido
            const oscillator = ctx.createOscillator();
            const gainNode = ctx.createGain();
            
            // Conectar nodos
            oscillator.connect(gainNode);
            gainNode.connect(ctx.destination);
            
            // Configurar sonido tipo "ding" (dos tonos)
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(800, currentTime); // Primera nota
            oscillator.frequency.setValueAtTime(1000, currentTime + 0.1); // Segunda nota más alta
            
            // Envolvente de volumen
            gainNode.gain.setValueAtTime(0, currentTime);
            gainNode.gain.linearRampToValueAtTime(0.3, currentTime + 0.01);
            gainNode.gain.exponentialRampToValueAtTime(0.01, currentTime + 0.3);
            
            // Reproducir
            oscillator.start(currentTime);
            oscillator.stop(currentTime + 0.3);
        } catch (error) {
            console.warn('No se pudo reproducir el sonido de notificación:', error);
        }
    }
    
    // Utilidades
    getInitials(name) {
        if (!name) return '?';
        return name.split(' ')
            .map(word => word[0])
            .slice(0, 2)
            .join('')
            .toUpperCase();
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    formatTime(timestamp) {
        if (!timestamp) return '';
        
        // Si el timestamp viene de MySQL (formato 'YYYY-MM-DD HH:mm:ss'), 
        // lo tratamos como hora local, no UTC
        let date;
        if (typeof timestamp === 'string' && timestamp.match(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/)) {
            // Reemplazar espacio con 'T' para que JavaScript lo interprete como hora local
            date = new Date(timestamp.replace(' ', 'T'));
        } else {
            date = new Date(timestamp);
        }
        
        const now = new Date();
        const diff = now - date;
        
        if (diff < 60000) return 'Ahora';
        if (diff < 3600000) return Math.floor(diff / 60000) + 'm';
        if (diff < 86400000) return Math.floor(diff / 3600000) + 'h';
        if (diff < 604800000) return Math.floor(diff / 86400000) + 'd';
        
        return date.toLocaleDateString('es-ES', { day: 'numeric', month: 'short' });
    }
    
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }
    
    getCurrentUserId() {
        // Esta función debe obtener el ID del usuario actual de la sesión
        // Puede ser inyectado desde PHP o almacenado en una variable global
        return window.currentUserId || null;
    }
    
    getBasePath() {
        // Detectar si estamos en un subdirectorio y devolver la ruta correcta al API del chat
        const path = window.location.pathname;
        console.log('🔍 Ruta actual:', path);
        
        // Si estamos DENTRO de /chat/, usar ruta relativa simple
        if (path.includes('/chat/')) {
            console.log('✅ Dentro de /chat/, usando ruta: ""');
            return '';  // api.php está en el mismo directorio
        }
        
        // Si estamos en otros subdirectorios (agents, hr, helpdesk), volver al root y entrar a chat
        if (path.includes('/agents/') || path.includes('/hr/') || path.includes('/helpdesk/')) {
            console.log('✅ En subdirectorio, usando ruta: ../chat/');
            return '../chat/';
        }
        
        // Si estamos en el root
        console.log('✅ En root, usando ruta: chat/');
        return 'chat/';
    }
}

// Inicializar chat cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    console.log('🚀 Chat JS cargado');
    console.log('👤 currentUserId:', typeof currentUserId !== 'undefined' ? currentUserId : 'NO DEFINIDO');
    
    // Verificar que el usuario esté autenticado
    if (typeof currentUserId !== 'undefined' && currentUserId) {
        console.log('✅ Inicializando ChatApp...');
        window.chatApp = new ChatApp();
        console.log('✅ ChatApp inicializado correctamente');
        
        // Forzar que el widget esté visible inmediatamente y verificar múltiples veces
        const ensureWidgetPosition = () => {
            const portal = document.getElementById('chat-portal');
            const widget = document.querySelector('.chat-widget');
            
            if (!portal || !widget) {
                console.log('❌ Portal o widget no encontrado');
                return;
            }
            
            console.log('📌 Verificando posición del portal y widget...');
            
            // Verificar que el portal esté en el <html>, no en el <body>
            if (portal.parentElement !== document.documentElement) {
                console.log('⚠️ Portal no está en <html>, moviendo...');
                document.documentElement.appendChild(portal);
            }
            
            // Verificar que el widget esté en el portal
            if (widget.parentElement !== portal) {
                console.log('⚠️ Widget no está en portal, moviendo...');
                portal.appendChild(widget);
            }
            
            // Forzar estilos del portal con setAttribute (máxima prioridad)
            portal.setAttribute('style', 'position: fixed !important; top: 0 !important; left: 0 !important; width: 100vw !important; height: 100vh !important; pointer-events: none !important; z-index: 2147483647 !important; transform: none !important;');
            
            // Forzar estilos del widget con setAttribute (máxima prioridad)
            widget.setAttribute('style', 'position: fixed !important; bottom: 20px !important; right: 20px !important; z-index: 2147483647 !important; visibility: visible !important; display: block !important; pointer-events: auto !important; transform: none !important;');
            
            console.log('✅ Portal en <html> y widget verificados correctamente');
        };
        
        // Verificar múltiples veces para asegurar
        setTimeout(ensureWidgetPosition, 100);
        setTimeout(ensureWidgetPosition, 500);
        setTimeout(ensureWidgetPosition, 1000);
    } else {
        console.log('❌ No se puede inicializar el chat: usuario no autenticado o currentUserId no definido');
    }
});
