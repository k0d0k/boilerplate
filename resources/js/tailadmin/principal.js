const menu = document.querySelector('[data-principal-menu]');
const trigger = document.querySelector('[data-principal-menu-trigger]');

if (menu && trigger) {
    trigger.addEventListener('click', () => menu.showModal());
    menu.addEventListener('close', () => trigger.focus());
    menu.addEventListener('click', (event) => {
        if (event.target === menu) {
            menu.close();
        }
    });
}

// Desktop rail toggle: collapses the Principal sidebar to the ~72px icon rail
// documented in docs/DESIGN_SYSTEM.md and remembers the choice between pages.
// The labels stay in the DOM (visually hidden by CSS) and every link keeps its
// `title`, so the rail stays understandable and keyboard-focusable.
const shell = document.querySelector('[data-principal-shell]');
const sidebarToggle = document.querySelector('[data-principal-sidebar-toggle]');

if (shell && sidebarToggle) {
    const storageKey = 'lba.principal.sidebar.collapsed';

    const applyCollapsed = (collapsed) => {
        shell.toggleAttribute('data-principal-collapsed', collapsed);
        sidebarToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

        const label = collapsed ? sidebarToggle.dataset.labelExpand : sidebarToggle.dataset.labelCollapse;

        if (label) {
            sidebarToggle.setAttribute('aria-label', label);
            sidebarToggle.setAttribute('title', label);
        }
    };

    let collapsed = false;

    try {
        collapsed = window.localStorage.getItem(storageKey) === 'true';
    } catch (error) {
        // Storage is unavailable (private mode): fall back to the expanded rail.
    }

    applyCollapsed(collapsed);

    sidebarToggle.addEventListener('click', () => {
        collapsed = !shell.hasAttribute('data-principal-collapsed');
        applyCollapsed(collapsed);

        try {
            window.localStorage.setItem(storageKey, String(collapsed));
        } catch (error) {
            // Persisting is best-effort only.
        }
    });
}

// Top-right account menu: "Profil Akun", "Ubah Kata Sandi" and "Keluar".
const account = document.querySelector('[data-principal-account]');

if (account) {
    const accountTrigger = account.querySelector('[data-principal-account-trigger]');
    const accountMenu = account.querySelector('[data-principal-account-menu]');

    if (accountTrigger && accountMenu) {
        const closeAccountMenu = () => {
            accountMenu.setAttribute('hidden', '');
            accountTrigger.setAttribute('aria-expanded', 'false');
        };

        accountTrigger.addEventListener('click', (event) => {
            event.stopPropagation();
            if (accountMenu.hasAttribute('hidden')) {
                accountMenu.removeAttribute('hidden');
                accountTrigger.setAttribute('aria-expanded', 'true');
            } else {
                closeAccountMenu();
            }
        });

        document.addEventListener('click', (event) => {
            if (!account.contains(event.target)) {
                closeAccountMenu();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeAccountMenu();
            }
        });
    }
}

// Tenant database detail: copy the SERVER-derived physical database name to the
// clipboard. The name is rendered by the server (computed from the tenant id);
// this only copies the text already on screen.
const copyDatabase = document.querySelector('[data-copy-database]');
const databaseName = document.querySelector('[data-database-name]');

if (copyDatabase && databaseName) {
    copyDatabase.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(databaseName.textContent.trim());
            if (copyDatabase.dataset.copiedLabel) {
                copyDatabase.textContent = copyDatabase.dataset.copiedLabel;
            }
        } catch (error) {
            // Clipboard is unavailable (e.g. an insecure context): the name
            // stays visible so the operator can copy it by hand.
        }
    });
}

// "Tambah/Ubah Mitra" database mode: reveal the MANUAL database fields only
// when Mode = Manual, so a "server" mode can never submit a half-filled target.
const databaseMode = document.querySelector('[data-database-mode]');
const databaseManual = document.querySelector('[data-database-manual]');

if (databaseMode && databaseManual) {
    const applyDatabaseMode = () => {
        databaseManual.hidden = databaseMode.value !== 'manual';
    };

    applyDatabaseMode();
    databaseMode.addEventListener('change', applyDatabaseMode);
}

// Destructive-action confirmations ("Hapus Brand" / "Hapus Mitra"). Each
// trigger opens the <dialog> named in `data-confirm-action`, which renders the
// exact item name and the impact; only the form INSIDE the dialog performs the
// DELETE. Closing the dialog (Cancel, backdrop, Escape) leaves the record
// untouched.
document.querySelectorAll('[data-confirm-action]').forEach((trigger) => {
    const dialog = document.getElementById(trigger.dataset.confirmAction);

    if (!(dialog instanceof HTMLDialogElement)) {
        return;
    }

    trigger.addEventListener('click', () => dialog.showModal());

    dialog.addEventListener('close', () => trigger.focus());

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    dialog.querySelector('[data-confirm-cancel]')?.addEventListener('click', () => dialog.close());
});

// LBA case detail — optional live microscope capture. The captured frame is
// POSTed to the SAME authenticated, CSRF-protected private intake endpoint as a
// manual import; nothing is stored client-side and the server re-checks every
// gate (editability, consent, tenant and branch scope).
const capture = document.querySelector('[data-lba-capture]');

if (capture) {
    const video = capture.querySelector('[data-lba-capture-video]');
    const startButton = capture.querySelector('[data-lba-capture-start]');
    const stillButton = capture.querySelector('[data-lba-capture-still]');
    const stopButton = capture.querySelector('[data-lba-capture-stop]');
    const status = capture.querySelector('[data-lba-capture-status]');
    const tokenInput = capture.querySelector('[data-lba-capture-token]');
    const storeUrl = capture.dataset.storeUrl;
    const errorMessage = capture.dataset.error;

    let stream = null;

    const setStatus = (message) => {
        if (status) {
            status.textContent = message;
        }
    };

    const stop = () => {
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }
        if (video) {
            video.srcObject = null;
        }
        startButton?.removeAttribute('hidden');
        stillButton?.setAttribute('hidden', '');
        stopButton?.setAttribute('hidden', '');
    };

    const upload = async (blob) => {
        const form = new FormData();
        form.append('image', blob, `capture-${Date.now()}.jpg`);
        if (tokenInput) {
            form.append('_token', tokenInput.value);
        }
        const response = await fetch(storeUrl, {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: { Accept: 'text/html' },
        });
        if (!response.ok) {
            throw new Error('upload failed');
        }
        window.location.reload();
    };

    startButton?.addEventListener('click', async () => {
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: true });
            video.srcObject = stream;
            await video.play();
            startButton.setAttribute('hidden', '');
            stillButton?.removeAttribute('hidden');
            stopButton?.removeAttribute('hidden');
            setStatus('');
        } catch (error) {
            setStatus(errorMessage);
        }
    });

    stillButton?.addEventListener('click', () => {
        if (!stream || !video) {
            return;
        }
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth || 1280;
        canvas.height = video.videoHeight || 720;
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(async (blob) => {
            if (!blob) {
                return;
            }
            try {
                await upload(blob);
            } catch (error) {
                setStatus(errorMessage);
            }
        }, 'image/jpeg', 0.92);
    });

    stopButton?.addEventListener('click', stop);
}

