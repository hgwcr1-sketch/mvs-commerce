const shell = document.getElementById('mvs-offline-shell');

async function loadApplicationBundle() {
    const response = await fetch('/build/manifest.json', { credentials: 'same-origin' });
    if (!response.ok) throw new Error('El bundle Offline no está disponible localmente.');
    const manifest = await response.json();
    const entry = manifest['resources/js/app.js']?.file;
    if (!entry) throw new Error('El bundle Offline no está registrado en el manifest.');
    await import(`/build/${entry}`);
}

function showShellError(error) {
    const title = document.getElementById('mvs-offline-status-title');
    const detail = document.getElementById('mvs-offline-status-detail');
    const status = document.getElementById('mvs-offline-status');
    status.dataset.state = 'blocked';
    title.textContent = 'Offline no disponible';
    detail.textContent = error?.message || 'Conecte la terminal al servidor para reaprovisionarla.';
}

loadApplicationBundle().catch(showShellError);
