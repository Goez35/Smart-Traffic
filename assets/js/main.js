// SmartTraffic - Client-Side Interactive Controller

let refreshSeconds = 3;
let countdown = refreshSeconds;
let isAutoRefreshActive = true;
let isUserInteracting = false;

const refreshText = document.getElementById('refreshText');
const refreshDot = document.getElementById('refreshDot');

function updateTimer() {
    if (!isAutoRefreshActive || isUserInteracting) return;

    countdown--;
    if (countdown <= 0) {
        window.location.reload();
    } else {
        if (refreshText) {
            refreshText.innerText = 'Live: ' + countdown + 's';
        }
    }
}

const timerInterval = setInterval(updateTimer, 1000);

function toggleAutoRefresh() {
    isAutoRefreshActive = !isAutoRefreshActive;
    if (isAutoRefreshActive) {
        countdown = refreshSeconds;
        if (refreshText) refreshText.innerText = 'Live: ' + countdown + 's';
        if (refreshDot) refreshDot.style.background = '#10b981';
    } else {
        if (refreshText) refreshText.innerText = 'Dijeda';
        if (refreshDot) refreshDot.style.background = '#f59e0b';
    }
}

// Pause refresh when user interacts with inputs or selects
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form, select, input').forEach(el => {
        el.addEventListener('focus', () => { isUserInteracting = true; });
        el.addEventListener('blur', () => { isUserInteracting = false; });
    });
});

function pauseRefreshDuringSubmit() {
    isAutoRefreshActive = false;
}

// Handle Mode Switch
function handleModeChange(selectElement, id) {
    const isManual = selectElement.value === 'manual';
    const lampSelect = document.getElementById('select-lampu-' + id);
    const submitBtn = document.getElementById('btn-submit-' + id);

    if (lampSelect) lampSelect.disabled = !isManual;
    if (submitBtn) submitBtn.disabled = !isManual;

    if (!isManual) {
        selectElement.form.submit();
    }
}

// Live Filter Intersections
function filterIntersections(keyword) {
    const query = (keyword || '').toLowerCase().trim();
    const cards = document.querySelectorAll('#gridCards .simpang-card');
    cards.forEach(card => {
        const name = card.getAttribute('data-name') || '';
        if (name.includes(query)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

