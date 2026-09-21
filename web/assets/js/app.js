/**
 * WhatsApp Bot Control Panel - Client Application Script
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Setup CSRF Token in global fetch
    const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
    window.csrfToken = csrfTokenMeta ? csrfTokenMeta.getAttribute('content') : '';

    // 2. Poll worker / connection status every 10 seconds
    const pill = document.getElementById('connectionPill');
    const pillText = document.getElementById('connectionText');

    function updateWorkerStatus() {
        if (!pill || !pillText) return;
        fetch('../api/worker/status.php')
            .then(res => res.json())
            .then(data => {
                if (data.success && data.data) {
                    const status = data.data;
                    pill.className = 'status-pill';
                    if (status.whatsapp_connected) {
                        pill.classList.add('status-pill-success');
                        pillText.textContent = `WhatsApp Connected (${status.online_workers} worker${status.online_workers === 1 ? '' : 's'})`;
                    } else if (status.qr_ready) {
                        pill.classList.add('status-pill-warning');
                        pillText.textContent = 'WhatsApp QR Ready to Link';
                    } else if (status.online_workers > 0) {
                        pill.classList.add('status-pill-warning');
                        pillText.textContent = `Worker Online (WhatsApp Disconnected)`;
                    } else {
                        pill.classList.add('status-pill-danger');
                        pillText.textContent = 'No Workers Online';
                    }
                }
            })
            .catch(() => {
                if (pill && pillText) {
                    pill.className = 'status-pill status-pill-neutral';
                    pillText.textContent = 'Status Check Offline';
                }
            });
    }

    if (pill) {
        updateWorkerStatus();
        setInterval(updateWorkerStatus, 10000);
    }
});

// Modal Helpers
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('show');
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('show');
    }
}

// Global API Fetch helper with CSRF
async function apiRequest(url, method = 'GET', body = null) {
    const options = {
        method: method,
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': window.csrfToken || ''
        }
    };

    if (body instanceof FormData) {
        body.append('csrf_token', window.csrfToken || '');
        options.body = body;
    } else if (body && typeof body === 'object') {
        options.headers['Content-Type'] = 'application/json';
        body.csrf_token = window.csrfToken || '';
        options.body = JSON.stringify(body);
    }

    const res = await fetch(url, options);
    const data = await res.json();
    return data;
}
