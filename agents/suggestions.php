<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// Check permission
if (!userHasPermission('helpdesk_suggestions')) {
    header("Location: ../unauthorized.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';

require_once __DIR__ . '/../header_agent.php';
?>

<link rel="stylesheet" href="../assets/css/theme.css">

<style>
.suggestions-container {
    padding: 20px;
    max-width: 1400px;
    margin: 0 auto;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
}

.page-header h1 {
    margin: 0;
    color: #333;
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.3s;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}

.btn-success {
    background: #28a745;
    color: white;
}

.btn-vote {
    padding: 8px 16px;
    font-size: 13px;
    background: white;
    border: 2px solid #e0e0e0;
    color: #666;
}

.btn-vote:hover {
    border-color: #667eea;
    color: #667eea;
}

.btn-vote.active {
    background: #667eea;
    border-color: #667eea;
    color: white;
}

.filters-bar {
    background: white;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.filters-bar select {
    padding: 10px 15px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    min-width: 150px;
}

.suggestions-grid {
    display: grid;
    gap: 20px;
}

.suggestion-card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transition: all 0.3s;
    cursor: pointer;
    border-left: 4px solid #667eea;
}

.suggestion-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
    transform: translateY(-2px);
}

.suggestion-card.type-improvement { border-left-color: #007bff; }
.suggestion-card.type-new_feature { border-left-color: #28a745; }
.suggestion-card.type-complaint { border-left-color: #dc3545; }
.suggestion-card.type-compliment { border-left-color: #ffc107; }
.suggestion-card.type-other { border-left-color: #6c757d; }

.suggestion-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
}

.suggestion-title {
    font-size: 18px;
    font-weight: 600;
    color: #333;
    margin: 0 0 10px 0;
}

.suggestion-badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-status-pending { background: #fff3cd; color: #856404; }
.badge-status-under_review { background: #cce5ff; color: #004085; }
.badge-status-approved { background: #d4edda; color: #155724; }
.badge-status-implemented { background: #28a745; color: white; }
.badge-status-rejected { background: #f8d7da; color: #721c24; }

.badge-type-improvement { background: #e7f3ff; color: #0066cc; }
.badge-type-new_feature { background: #d4edda; color: #155724; }
.badge-type-complaint { background: #f8d7da; color: #721c24; }
.badge-type-compliment { background: #fff3cd; color: #856404; }
.badge-type-other { background: #e2e3e5; color: #383d41; }

.suggestion-description {
    color: #666;
    font-size: 14px;
    margin-bottom: 15px;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.suggestion-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
    color: #999;
    padding-top: 15px;
    border-top: 1px solid #e0e0e0;
}

.suggestion-votes {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    color: #667eea;
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
}

.modal-content {
    background: white;
    margin: 50px auto;
    padding: 40px;
    border-radius: 16px;
    max-width: 700px;
    max-height: 85vh;
    overflow-y: auto;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
}

.close {
    float: right;
    font-size: 32px;
    font-weight: bold;
    cursor: pointer;
    color: #999;
    line-height: 1;
}

.close:hover {
    color: #333;
}

.form-group {
    margin-bottom: 24px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #333;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 12px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    transition: border-color 0.3s;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #667eea;
}

.form-group textarea {
    min-height: 150px;
    resize: vertical;
    font-family: inherit;
}

.loading {
    text-align: center;
    padding: 60px 20px;
    color: #999;
}

.empty-state {
    text-align: center;
    padding: 80px 20px;
    color: #999;
}

.empty-state i {
    font-size: 80px;
    margin-bottom: 20px;
    opacity: 0.3;
}

.info-box {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 25px;
    border-radius: 12px;
    margin-bottom: 30px;
}

.info-box h3 {
    margin: 0 0 10px 0;
}

.info-box p {
    margin: 0;
    opacity: 0.9;
}
</style>

<div class="suggestions-container">
    <div class="page-header">
        <h1><i class="fas fa-lightbulb"></i> Suggestion Box</h1>
        <button class="btn btn-primary" onclick="openCreateSuggestionModal()">
            <i class="fas fa-plus"></i> Submit Suggestion
        </button>
    </div>

    <div class="info-box">
        <h3><i class="fas fa-info-circle"></i> Share Your Ideas</h3>
        <p>We value your feedback! Submit suggestions for improvements, new features, or share your thoughts about any department. Your input helps us create a better workplace.</p>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
        <select id="filterDepartment" onchange="loadSuggestions()">
            <option value="">All Departments</option>
            <option value="IT">IT</option>
            <option value="HR">HR</option>
            <option value="Payroll">Payroll</option>
            <option value="Operations">Operations</option>
            <option value="Facilities">Facilities</option>
            <option value="Training">Training</option>
            <option value="General">General</option>
        </select>
        <select id="filterStatus" onchange="loadSuggestions()">
            <option value="">All Statuses</option>
            <option value="pending">Pending</option>
            <option value="under_review">Under Review</option>
            <option value="approved">Approved</option>
            <option value="implemented">Implemented</option>
            <option value="rejected">Rejected</option>
        </select>
        <select id="filterType" onchange="loadSuggestions()">
            <option value="">All Types</option>
            <option value="improvement">Improvement</option>
            <option value="new_feature">New Feature</option>
            <option value="complaint">Complaint</option>
            <option value="compliment">Compliment</option>
            <option value="other">Other</option>
        </select>
    </div>

    <!-- Suggestions List -->
    <div class="suggestions-grid" id="suggestionsList">
        <div class="loading">
            <i class="fas fa-spinner fa-spin fa-3x"></i>
            <p>Loading suggestions...</p>
        </div>
    </div>
</div>

<!-- Create Suggestion Modal -->
<div id="createSuggestionModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeCreateSuggestionModal()">&times;</span>
        <h2 style="margin-top: 0;"><i class="fas fa-lightbulb"></i> Submit a Suggestion</h2>
        <p style="color: #666; margin-bottom: 30px;">Share your ideas, feedback, or suggestions to help us improve.</p>
        
        <form id="createSuggestionForm" onsubmit="createSuggestion(event)">
            <div class="form-group">
                <label>Department *</label>
                <select id="suggestionDepartment" required>
                    <option value="">Select department</option>
                    <option value="IT">IT</option>
                    <option value="HR">HR</option>
                    <option value="Payroll">Payroll</option>
                    <option value="Operations">Operations</option>
                    <option value="Facilities">Facilities</option>
                    <option value="Training">Training</option>
                    <option value="General">General</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Type *</label>
                <select id="suggestionType" required>
                    <option value="improvement">Improvement</option>
                    <option value="new_feature">New Feature</option>
                    <option value="complaint">Complaint</option>
                    <option value="compliment">Compliment</option>
                    <option value="other">Other</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Title *</label>
                <input type="text" id="suggestionTitle" required placeholder="Brief summary of your suggestion">
            </div>
            
            <div class="form-group">
                <label>Description *</label>
                <textarea id="suggestionDescription" required placeholder="Provide detailed information about your suggestion..."></textarea>
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" id="isAnonymous"> Submit anonymously
                </label>
                <small style="color: #666; display: block; margin-top: 5px;">Your identity will be hidden from other users (admins can still see it)</small>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%;">
                <i class="fas fa-paper-plane"></i> Submit Suggestion
            </button>
        </form>
    </div>
</div>

<!-- View Suggestion Modal -->
<div id="viewSuggestionModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeViewSuggestionModal()">&times;</span>
        <div id="suggestionDetails"></div>
    </div>
</div>

<script>
let currentSuggestion = null;

document.addEventListener('DOMContentLoaded', function() {
    loadSuggestions();
    
    // Refresh every 60 seconds
    setInterval(loadSuggestions, 60000);
});

function loadSuggestions() {
    const department = document.getElementById('filterDepartment').value;
    const status = document.getElementById('filterStatus').value;
    const type = document.getElementById('filterType').value;
    
    let url = '../hr/suggestions_api.php?action=get_suggestions';
    if (department) url += '&department=' + encodeURIComponent(department);
    if (status) url += '&status=' + status;
    if (type) url += '&suggestion_type=' + type;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displaySuggestions(data.suggestions);
            }
        });
}

function displaySuggestions(suggestions) {
    const container = document.getElementById('suggestionsList');
    
    if (suggestions.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-lightbulb"></i>
                <h3>No suggestions yet</h3>
                <p>Be the first to share your ideas!</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = suggestions.map(suggestion => `
        <div class="suggestion-card type-${suggestion.suggestion_type}" onclick="viewSuggestion(${suggestion.id})">
            <div class="suggestion-header">
                <div style="flex: 1;">
                    <h3 class="suggestion-title">${escapeHtml(suggestion.title)}</h3>
                    <div class="suggestion-badges">
                        <span class="badge badge-status-${suggestion.status}">${suggestion.status.replace('_', ' ')}</span>
                        <span class="badge badge-type-${suggestion.suggestion_type}">${suggestion.suggestion_type.replace('_', ' ')}</span>
                        <span class="badge" style="background: #e0e0e0; color: #666;">${suggestion.department}</span>
                    </div>
                </div>
            </div>
            <div class="suggestion-description">${escapeHtml(suggestion.description)}</div>
            <div class="suggestion-meta">
                <span><i class="fas fa-user"></i> ${suggestion.user_name}</span>
                <span><i class="fas fa-clock"></i> ${formatDate(suggestion.created_at)}</span>
                <div class="suggestion-votes">
                    <i class="fas fa-thumbs-up"></i> ${suggestion.votes_count || 0} votes
                </div>
            </div>
        </div>
    `).join('');
}

function openCreateSuggestionModal() {
    document.getElementById('createSuggestionModal').style.display = 'block';
}

function closeCreateSuggestionModal() {
    document.getElementById('createSuggestionModal').style.display = 'none';
    document.getElementById('createSuggestionForm').reset();
}

function createSuggestion(event) {
    event.preventDefault();
    
    const submitBtn = event.target.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    
    const formData = new FormData();
    formData.append('action', 'create_suggestion');
    formData.append('department', document.getElementById('suggestionDepartment').value);
    formData.append('title', document.getElementById('suggestionTitle').value);
    formData.append('description', document.getElementById('suggestionDescription').value);
    formData.append('suggestion_type', document.getElementById('suggestionType').value);
    formData.append('is_anonymous', document.getElementById('isAnonymous').checked ? 1 : 0);
    
    fetch('../hr/suggestions_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Suggestion';
        
        if (data.success) {
            alert('✓ Suggestion submitted successfully!\n\nThank you for your feedback. We will review it and get back to you.');
            closeCreateSuggestionModal();
            loadSuggestions();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Suggestion';
        alert('Error submitting suggestion. Please try again.');
    });
}

function viewSuggestion(suggestionId) {
    fetch(`../hr/suggestions_api.php?action=get_suggestion&suggestion_id=${suggestionId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displaySuggestionDetails(data.suggestion);
                document.getElementById('viewSuggestionModal').style.display = 'block';
            }
        });
}

function closeViewSuggestionModal() {
    document.getElementById('viewSuggestionModal').style.display = 'none';
}

function displaySuggestionDetails(suggestion) {
    currentSuggestion = suggestion;
    
    let html = `
        <h2 style="margin-top: 0;">${escapeHtml(suggestion.title)}</h2>
        
        <div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
            <span class="badge badge-status-${suggestion.status}">${suggestion.status.replace('_', ' ')}</span>
            <span class="badge badge-type-${suggestion.suggestion_type}">${suggestion.suggestion_type.replace('_', ' ')}</span>
            <span class="badge" style="background: #667eea; color: white;">${suggestion.department}</span>
        </div>
        
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
            <p style="margin: 0 0 10px 0;"><strong>Submitted by:</strong> ${suggestion.user_name}</p>
            <p style="margin: 0 0 10px 0;"><strong>Date:</strong> ${formatDate(suggestion.created_at)}</p>
            <p style="margin: 0;"><strong>Votes:</strong> ${suggestion.votes_count || 0}</p>
        </div>
        
        <div style="margin-bottom: 30px;">
            <h4>Description</h4>
            <p style="white-space: pre-wrap; color: #555;">${escapeHtml(suggestion.description)}</p>
        </div>
    `;
    
    if (suggestion.reviewed_by_name) {
        html += `
            <div style="background: #e7f3ff; padding: 20px; border-radius: 8px; border-left: 4px solid #007bff; margin-bottom: 20px;">
                <h4 style="margin-top: 0;">Review</h4>
                <p><strong>Reviewed by:</strong> ${suggestion.reviewed_by_name}</p>
                <p><strong>Date:</strong> ${formatDate(suggestion.reviewed_at)}</p>
                ${suggestion.review_notes ? `<p><strong>Notes:</strong><br>${escapeHtml(suggestion.review_notes)}</p>` : ''}
            </div>
        `;
    }
    
    html += `
        <div style="display: flex; gap: 10px; margin-top: 20px;">
            <button class="btn btn-vote" onclick="voteSuggestion('up')">
                <i class="fas fa-thumbs-up"></i> Upvote
            </button>
            <button class="btn btn-vote" onclick="voteSuggestion('down')">
                <i class="fas fa-thumbs-down"></i> Downvote
            </button>
        </div>
    `;
    
    document.getElementById('suggestionDetails').innerHTML = html;
}

function voteSuggestion(voteType) {
    const formData = new FormData();
    formData.append('action', 'vote_suggestion');
    formData.append('suggestion_id', currentSuggestion.id);
    formData.append('vote_type', voteType);
    
    fetch('../hr/suggestions_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✓ Vote recorded!');
            viewSuggestion(currentSuggestion.id);
            loadSuggestions();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now - date;
    const diffDays = Math.floor(diffMs / 86400000);
    
    if (diffDays === 0) return 'Today';
    if (diffDays === 1) return 'Yesterday';
    if (diffDays < 7) return `${diffDays} days ago`;
    
    return date.toLocaleDateString();
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
