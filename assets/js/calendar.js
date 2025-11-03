// Calendar Event Management
let currentEventId = null;
let currentEventData = null;

function openCreateEventModal(date = null) {
    document.getElementById('modalTitle').textContent = 'Crear Evento';
    document.getElementById('eventForm').reset();
    document.getElementById('eventId').value = '';
    
    if (date) {
        document.getElementById('eventDate').value = date;
    } else {
        document.getElementById('eventDate').value = new Date().toISOString().split('T')[0];
    }
    
    // Reset color selection
    document.querySelectorAll('.color-option').forEach(opt => opt.classList.remove('selected'));
    document.querySelector('.color-option[data-color="#6366f1"]').classList.add('selected');
    document.getElementById('eventColor').value = '#6366f1';
    
    // Show time fields by default
    document.getElementById('isAllDay').checked = false;
    document.getElementById('timeFields').style.display = 'grid';
    
    document.getElementById('eventModal').classList.add('show');
}

function closeEventModal() {
    document.getElementById('eventModal').classList.remove('show');
}

function toggleTimeFields() {
    const isAllDay = document.getElementById('isAllDay').checked;
    const timeFields = document.getElementById('timeFields');
    timeFields.style.display = isAllDay ? 'none' : 'grid';
    
    if (isAllDay) {
        document.getElementById('startTime').value = '';
        document.getElementById('endTime').value = '';
    }
}

function selectColor(element) {
    document.querySelectorAll('.color-option').forEach(opt => opt.classList.remove('selected'));
    element.classList.add('selected');
    document.getElementById('eventColor').value = element.dataset.color;
}

async function saveEvent() {
    const form = document.getElementById('eventForm');
    const formData = new FormData(form);
    
    // Validate required fields
    if (!formData.get('title') || !formData.get('event_date')) {
        alert('Por favor complete los campos requeridos');
        return;
    }
    
    const eventData = {
        title: formData.get('title'),
        description: formData.get('description'),
        event_date: formData.get('event_date'),
        start_time: formData.get('start_time') || null,
        end_time: formData.get('end_time') || null,
        event_type: formData.get('event_type'),
        color: formData.get('color'),
        location: formData.get('location'),
        is_all_day: document.getElementById('isAllDay').checked ? 1 : 0
    };
    
    const eventId = document.getElementById('eventId').value;
    const action = eventId ? 'update' : 'create';
    
    if (eventId) {
        eventData.id = eventId;
    }
    
    try {
        const response = await fetch(`calendar_events_api.php?action=${action}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(eventData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert(result.message);
            closeEventModal();
            location.reload();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error al guardar el evento');
    }
}

async function viewEvent(eventId) {
    try {
        const response = await fetch(`calendar_events_api.php?action=get&id=${eventId}`);
        const result = await response.json();
        
        if (result.success) {
            currentEventData = result.event;
            currentEventId = eventId;
            displayEventDetails(result.event);
            document.getElementById('viewEventModal').classList.add('show');
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error al cargar el evento');
    }
}

function displayEventDetails(event) {
    document.getElementById('viewEventTitle').textContent = event.title;
    
    const eventTypeLabels = {
        'MEETING': 'Reunión',
        'REMINDER': 'Recordatorio',
        'DEADLINE': 'Fecha límite',
        'HOLIDAY': 'Feriado',
        'TRAINING': 'Capacitación',
        'OTHER': 'Otro'
    };
    
    let html = '<div class="space-y-2">';
    
    // Event Type
    html += `
        <div class="event-detail-row">
            <i class="fas fa-tag event-detail-icon"></i>
            <div class="event-detail-content">
                <div class="event-detail-label">Tipo</div>
                <div class="event-detail-value">${eventTypeLabels[event.event_type] || event.event_type}</div>
            </div>
        </div>
    `;
    
    // Date
    const dateObj = new Date(event.event_date + 'T00:00:00');
    const formattedDate = dateObj.toLocaleDateString('es-ES', { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
    
    html += `
        <div class="event-detail-row">
            <i class="fas fa-calendar event-detail-icon"></i>
            <div class="event-detail-content">
                <div class="event-detail-label">Fecha</div>
                <div class="event-detail-value">${formattedDate}</div>
            </div>
        </div>
    `;
    
    // Time
    if (event.is_all_day == 1) {
        html += `
            <div class="event-detail-row">
                <i class="fas fa-clock event-detail-icon"></i>
                <div class="event-detail-content">
                    <div class="event-detail-label">Horario</div>
                    <div class="event-detail-value">Todo el día</div>
                </div>
            </div>
        `;
    } else if (event.start_time) {
        const timeText = event.end_time 
            ? `${event.start_time.substring(0, 5)} - ${event.end_time.substring(0, 5)}`
            : event.start_time.substring(0, 5);
        
        html += `
            <div class="event-detail-row">
                <i class="fas fa-clock event-detail-icon"></i>
                <div class="event-detail-content">
                    <div class="event-detail-label">Horario</div>
                    <div class="event-detail-value">${timeText}</div>
                </div>
            </div>
        `;
    }
    
    // Location
    if (event.location) {
        html += `
            <div class="event-detail-row">
                <i class="fas fa-map-marker-alt event-detail-icon"></i>
                <div class="event-detail-content">
                    <div class="event-detail-label">Ubicación</div>
                    <div class="event-detail-value">${event.location}</div>
                </div>
            </div>
        `;
    }
    
    // Description
    if (event.description) {
        html += `
            <div class="event-detail-row">
                <i class="fas fa-align-left event-detail-icon"></i>
                <div class="event-detail-content">
                    <div class="event-detail-label">Descripción</div>
                    <div class="event-detail-value">${event.description}</div>
                </div>
            </div>
        `;
    }
    
    // Creator
    html += `
        <div class="event-detail-row">
            <i class="fas fa-user event-detail-icon"></i>
            <div class="event-detail-content">
                <div class="event-detail-label">Creado por</div>
                <div class="event-detail-value">${event.creator_name}</div>
            </div>
        </div>
    `;
    
    // Color indicator
    html += `
        <div class="event-detail-row">
            <i class="fas fa-palette event-detail-icon"></i>
            <div class="event-detail-content">
                <div class="event-detail-label">Color</div>
                <div class="event-detail-value">
                    <span style="display: inline-block; width: 20px; height: 20px; background: ${event.color}; border-radius: 4px; vertical-align: middle;"></span>
                </div>
            </div>
        </div>
    `;
    
    html += '</div>';
    
    document.getElementById('viewEventBody').innerHTML = html;
}

function closeViewEventModal() {
    document.getElementById('viewEventModal').classList.remove('show');
    currentEventId = null;
    currentEventData = null;
}

function editCurrentEvent() {
    if (!currentEventData) return;
    
    closeViewEventModal();
    
    // Populate form with current event data
    document.getElementById('modalTitle').textContent = 'Editar Evento';
    document.getElementById('eventId').value = currentEventData.id;
    document.getElementById('eventTitle').value = currentEventData.title;
    document.getElementById('eventDescription').value = currentEventData.description || '';
    document.getElementById('eventDate').value = currentEventData.event_date;
    document.getElementById('eventType').value = currentEventData.event_type;
    document.getElementById('startTime').value = currentEventData.start_time || '';
    document.getElementById('endTime').value = currentEventData.end_time || '';
    document.getElementById('eventLocation').value = currentEventData.location || '';
    document.getElementById('isAllDay').checked = currentEventData.is_all_day == 1;
    
    // Set color
    document.querySelectorAll('.color-option').forEach(opt => {
        if (opt.dataset.color === currentEventData.color) {
            opt.classList.add('selected');
        } else {
            opt.classList.remove('selected');
        }
    });
    document.getElementById('eventColor').value = currentEventData.color;
    
    // Toggle time fields
    toggleTimeFields();
    
    document.getElementById('eventModal').classList.add('show');
}

async function deleteCurrentEvent() {
    if (!currentEventId) return;
    
    if (!confirm('¿Está seguro de que desea eliminar este evento?')) {
        return;
    }
    
    try {
        const response = await fetch('calendar_events_api.php?action=delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ id: currentEventId })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert(result.message);
            closeViewEventModal();
            location.reload();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error al eliminar el evento');
    }
}

// Close modals when clicking outside
window.onclick = function(event) {
    const eventModal = document.getElementById('eventModal');
    const viewEventModal = document.getElementById('viewEventModal');
    
    if (event.target === eventModal) {
        closeEventModal();
    }
    if (event.target === viewEventModal) {
        closeViewEventModal();
    }
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // ESC to close modals
    if (e.key === 'Escape') {
        closeEventModal();
        closeViewEventModal();
    }
    
    // Ctrl+N or Cmd+N to create new event
    if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
        e.preventDefault();
        openCreateEventModal();
    }
});
