(function () {
    // Set sidebar width based on viewport
    function updateSidebarWidth() {
        const isMobile = window.matchMedia('(max-width: 500px)').matches;
        if (isMobile) {
            document.documentElement.style.setProperty('--sidebar-width', '0px');
        } else {
            document.documentElement.style.setProperty('--sidebar-width', '238px');
        }
    }

    updateSidebarWidth();
    window.addEventListener('resize', updateSidebarWidth);

    const toggle = document.querySelector('.js-sidebar-toggle');
    const sidebar = document.querySelector('.app-sidebar');
    const sidebarOverlay = document.querySelector('.js-sidebar-overlay');

    function isMobileSidebar() {
        return window.matchMedia('(max-width: 500px)').matches;
    }

    function updateSidebarButton() {
        if (!toggle) {
            return;
        }

        const expanded = isMobileSidebar()
            ? document.body.classList.contains('sidebar-open')
            : !document.body.classList.contains('sidebar-collapsed');

        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    function closeMobileSidebar() {
        document.body.classList.remove('sidebar-open');

        if (sidebarOverlay) {
            sidebarOverlay.hidden = true;
        }

        updateSidebarButton();
    }

    if (toggle) {
        toggle.addEventListener('click', () => {
            if (isMobileSidebar()) {
                const shouldOpen = !document.body.classList.contains('sidebar-open');
                document.body.classList.toggle('sidebar-open', shouldOpen);

                if (sidebarOverlay) {
                    sidebarOverlay.hidden = !shouldOpen;
                }
            } else {
                document.body.classList.toggle('sidebar-collapsed');
                closeMobileSidebar();
            }

            updateSidebarButton();
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeMobileSidebar);
    }

    if (sidebar) {
        sidebar.addEventListener('click', (event) => {
            if (event.target.closest('a') && isMobileSidebar()) {
                closeMobileSidebar();
            }
        });
    }

    window.addEventListener('resize', () => {
        closeMobileSidebar();
        updateSidebarButton();
    });

    updateSidebarButton();

    const userMenu = document.querySelector('.js-user-menu');
    const userMenuToggle = document.querySelector('.js-user-menu-toggle');

    function closeUserMenu() {
        if (userMenu instanceof HTMLDetailsElement) {
            userMenu.open = false;
        }

        if (userMenuToggle) {
            userMenuToggle.setAttribute('aria-expanded', 'false');
        }
    }

    if (userMenu instanceof HTMLDetailsElement && userMenuToggle) {
        userMenu.addEventListener('toggle', () => {
            userMenuToggle.setAttribute('aria-expanded', userMenu.open ? 'true' : 'false');
        });
    }

    document.addEventListener('click', (event) => {
        if (userMenu && !userMenu.contains(event.target)) {
            closeUserMenu();
        }
    });

    if (userMenuToggle) {
        userMenuToggle.addEventListener('click', (event) => {
            event.stopPropagation();
        });
    }

    const themeButtons = document.querySelectorAll('.theme-dot[data-theme]');
    const progressBars = document.querySelectorAll('.progress[data-progress-percent]');
    let themeController = null;

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function applyTheme(theme) {
        const nextTheme = theme === 'dark' ? 'dark' : 'light';
        document.body.classList.toggle('dark-mode', nextTheme === 'dark');
        document.body.dataset.theme = nextTheme;

        themeButtons.forEach((button) => {
            button.classList.toggle('is-active', button.dataset.theme === nextTheme);
        });

        document.querySelectorAll('select[name="theme"]').forEach((select) => {
            select.value = nextTheme;
        });

        try {
            window.localStorage.setItem('mein-schreibtisch-theme', nextTheme);
        } catch (error) {
            // Browser kann localStorage blockieren; die Seite bleibt trotzdem nutzbar.
        }
    }

    async function persistTheme(theme) {
        const token = csrfToken();

        if (!token) {
            return;
        }

        if (themeController) {
            themeController.abort();
        }

        themeController = new AbortController();
        const formData = new FormData();
        formData.append('theme', theme === 'dark' ? 'dark' : 'light');
        formData.append('csrf_token', token);

        try {
            const response = await fetch('update_theme_ajax.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json'
                },
                signal: themeController.signal
            });
            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error('Theme konnte nicht gespeichert werden.');
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.warn('Theme konnte nicht gespeichert werden.');
            }
        }
    }

    try {
        window.localStorage.setItem('mein-schreibtisch-theme', document.body.dataset.theme || 'light');
    } catch (error) {
        // Browser kann localStorage blockieren; die Seite bleibt trotzdem nutzbar.
    }

    themeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const nextTheme = button.dataset.theme === 'dark' ? 'dark' : 'light';
            applyTheme(nextTheme);
            persistTheme(nextTheme);
        });
    });

    progressBars.forEach((progress) => {
        const fill = progress.querySelector('.progress-fill');
        const percent = Math.max(0, Math.min(100, Number(progress.dataset.progressPercent || 0)));

        if (fill) {
            fill.style.width = `${percent}%`;
        }
    });

    const searchWrapper = document.querySelector('.js-global-search');
    const searchInput = document.querySelector('.js-global-search-input');
    const searchResults = document.querySelector('.js-global-search-results');
    let searchTimer = null;
    let searchController = null;

    function hideSearchResults() {
        if (searchResults) {
            searchResults.hidden = true;
            searchResults.replaceChildren();
        }
    }

    function createSearchMessage(message) {
        const item = document.createElement('div');
        item.className = 'search-empty';
        item.textContent = message;
        return item;
    }

    function renderSearchResults(results) {
        if (!searchResults) {
            return;
        }

        searchResults.replaceChildren();

        if (!Array.isArray(results) || results.length === 0) {
            searchResults.append(createSearchMessage('Keine Treffer gefunden.'));
            searchResults.hidden = false;
            return;
        }

        results.forEach((result) => {
            const link = document.createElement('a');
            link.className = 'search-result';
            link.href = result.url || '#';

            const type = document.createElement('span');
            type.className = 'search-result-type';
            type.textContent = result.type || 'Treffer';

            const title = document.createElement('strong');
            title.textContent = result.title || 'Ohne Titel';

            const subtitle = document.createElement('span');
            subtitle.className = 'search-result-subtitle';
            subtitle.textContent = result.subtitle || '';

            link.append(type, title, subtitle);
            searchResults.append(link);
        });

        searchResults.hidden = false;
    }

    async function runGlobalSearch(query) {
        if (!searchResults) {
            return;
        }

        if (searchController) {
            searchController.abort();
        }

        searchController = new AbortController();
        searchResults.replaceChildren(createSearchMessage('Suche laeuft...'));
        searchResults.hidden = false;

        try {
            const response = await fetch(`search_ajax.php?q=${encodeURIComponent(query)}`, {
                headers: {
                    'Accept': 'application/json'
                },
                signal: searchController.signal
            });
            const data = await response.json();

            if (!data.success) {
                renderSearchResults([]);
                return;
            }

            renderSearchResults(data.results);
        } catch (error) {
            if (error.name !== 'AbortError') {
                searchResults.replaceChildren(createSearchMessage('Suche nicht verfuegbar.'));
                searchResults.hidden = false;
            }
        }
    }

    if (searchInput && searchResults) {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.trim();
            window.clearTimeout(searchTimer);

            if (query.length < 2) {
                hideSearchResults();
                return;
            }

            searchTimer = window.setTimeout(() => runGlobalSearch(query), 180);
        });

        searchInput.addEventListener('focus', () => {
            if (searchInput.value.trim().length >= 2 && searchResults.children.length > 0) {
                searchResults.hidden = false;
            }
        });
    }

    document.addEventListener('keydown', (event) => {
        const key = event.key.toLowerCase();

        if ((event.ctrlKey || event.metaKey) && key === 'k' && searchInput) {
            event.preventDefault();
            searchInput.focus();
            searchInput.select();
        }

        if (event.key === 'Escape') {
            hideSearchResults();
            closeUserMenu();
            if (document.activeElement === searchInput) {
                searchInput.blur();
            }
        }
    });

    document.addEventListener('click', (event) => {
        if (searchWrapper && !searchWrapper.contains(event.target)) {
            hideSearchResults();
        }
    });

    document.querySelectorAll('.js-dropzone-form').forEach((form) => {
        const dropzone = form.querySelector('.js-dropzone');
        const input = form.querySelector('.js-dropzone-input');
        const fileLabel = form.querySelector('.js-dropzone-file');

        if (!dropzone || !input || !fileLabel) {
            return;
        }

        function updateDropzoneLabel() {
            const file = input.files && input.files[0];

            if (!file) {
                fileLabel.textContent = 'Noch keine Datei ausgewaehlt.';
                return;
            }

            const sizeInMb = file.size / 1024 / 1024;
            const sizeLabel = sizeInMb >= 1
                ? `${sizeInMb.toFixed(2)} MB`
                : `${Math.max(1, Math.round(file.size / 1024))} KB`;

            fileLabel.textContent = `${file.name} (${sizeLabel})`;
        }

        function preventDefault(event) {
            event.preventDefault();
            event.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach((eventName) => {
            dropzone.addEventListener(eventName, (event) => {
                preventDefault(event);
                dropzone.classList.add('is-dragover');
            });
        });

        ['dragleave', 'dragend', 'drop'].forEach((eventName) => {
            dropzone.addEventListener(eventName, (event) => {
                preventDefault(event);
                dropzone.classList.remove('is-dragover');
            });
        });

        dropzone.addEventListener('drop', (event) => {
            const files = event.dataTransfer && event.dataTransfer.files;

            if (!files || files.length === 0) {
                return;
            }

            try {
                if (files.length === 1 || typeof DataTransfer === 'undefined') {
                    input.files = files;
                } else {
                    const transfer = new DataTransfer();
                    transfer.items.add(files[0]);
                    input.files = transfer.files;
                }

                updateDropzoneLabel();
            } catch (error) {
                fileLabel.textContent = 'Bitte Datei ueber den Dateidialog auswaehlen.';
            }
        });

        input.addEventListener('change', updateDropzoneLabel);
        updateDropzoneLabel();
    });

    function openDateTimePicker(input) {
        input.focus();

        if (typeof input.showPicker !== 'function') {
            return;
        }

        try {
            input.showPicker();
        } catch (error) {
            // Einige Browser erlauben showPicker nur direkt nach einer User-Aktion.
        }
    }

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;

        if (!target) {
            return;
        }

        const presetButton = target.closest('[data-due-date-value]');

        if (presetButton) {
            event.preventDefault();

            const form = presetButton.closest('form');
            const input = form
                ? form.querySelector('input[type="datetime-local"][name="due_date"]')
                : null;

            if (!input) {
                return;
            }

            input.value = presetButton.dataset.dueDateValue || '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.focus();
            return;
        }

        const dateTimeInput = target.closest('input[type="datetime-local"]');

        if (dateTimeInput && !dateTimeInput.disabled && !dateTimeInput.readOnly) {
            openDateTimePicker(dateTimeInput);
        }
    });

    const timeElement = document.querySelector('.js-topbar-time');
    const dateElement = document.querySelector('.js-topbar-date');

    function updateTopbarClock() {
        const now = new Date();
        const language = document.body.dataset.language === 'en' ? 'en-US' : 'de-DE';

        if (timeElement) {
            timeElement.textContent = new Intl.DateTimeFormat(language, {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            }).format(now);
        }

        if (dateElement) {
            dateElement.textContent = new Intl.DateTimeFormat(language, {
                weekday: 'long',
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            }).format(now);
        }
    }

    updateTopbarClock();
    window.setInterval(updateTopbarClock, 1000);
})();
