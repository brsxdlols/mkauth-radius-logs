(function () {
    var list = document.getElementById('logs') || document.getElementById('radiusLogList');
    if (!list) {
        return;
    }

    var style = document.createElement('style');
    style.textContent = '.radius-status-flags{display:flex;flex-wrap:wrap;gap:5px;margin-top:5px}.radius-status-flag{display:inline-block;border:0;border-radius:6px;padding:3px 7px;font:600 10px/1.4 Arial,sans-serif;background:#334155;color:#e2e8f0}.radius-status-flag.is-blocked{background:#582632;color:#fda4af}.radius-status-flag.is-action{cursor:pointer}.radius-status-flag.is-action:hover{background:#793244}.radius-hide-disabled{display:inline-flex;align-items:center;gap:7px;font:12px Arial,sans-serif;color:#475569;margin:6px 0}.radius-status-hidden{display:none!important}';
    document.head.appendChild(style);

    var label = document.createElement('label');
    var input = document.createElement('input');
    var gear = document.createElement('button');
    var settings = document.createElement('dialog');
    var title = document.createElement('h3');
    var help = document.createElement('p');
    var feedback = document.createElement('p');
    var close = document.createElement('button');
    var toolbar = document.querySelector('.log-search') || document.querySelector('.radius-filters');
    var storageKey = 'radius-hide-disabled-persistent';
    var cache = {};
    var busy = false;
    var pending = null;

    input.type = 'checkbox';
    label.className = 'radius-hide-disabled';
    label.appendChild(input);
    label.appendChild(document.createTextNode('Ocultar logins desativados'));

    gear.type = 'button';
    gear.textContent = '⚙';
    gear.title = 'Configurações do Log RADIUS';
    gear.setAttribute('aria-label', 'Configurações do Log RADIUS');
    gear.style.cssText = 'display:inline-grid;place-items:center;width:34px;height:34px;flex:none;align-self:flex-start;border:1px solid #d7e2f0;border-radius:9px;background:#fff;color:#2563eb;font:22px/1 Arial;cursor:pointer';
    if (list.id === 'logs') {
        gear.style.padding = '0';
        gear.style.boxSizing = 'border-box';
        gear.style.alignSelf = 'center';
    }
    if (toolbar) {
        toolbar.appendChild(gear);
    } else {
        list.parentNode.insertBefore(gear, list);
    }

    settings.setAttribute('aria-label', 'Configurações do Log RADIUS');
    settings.style.cssText = 'box-sizing:border-box;width:min(420px,90vw);padding:24px;border:1px solid #dce5f1;border-radius:16px;background:#fff;color:#18324a;box-shadow:0 24px 70px #0004;font:14px/1.5 Arial,sans-serif';
    title.textContent = 'Configurações do Log RADIUS';
    title.style.cssText = 'margin:0 0 16px;font-size:18px';
    help.textContent = 'A escolha fica salva neste navegador para este MK-Auth e também vale no monitor ao vivo.';
    help.style.cssText = 'font-size:12px;color:#64748b;margin:12px 0';
    feedback.setAttribute('role', 'status');
    feedback.style.cssText = 'font-size:12px;color:#16804a;min-height:18px';
    close.type = 'button';
    close.textContent = 'Concluir';
    close.style.cssText = 'border:0;border-radius:9px;padding:10px 18px;background:#2563eb;color:#fff;cursor:pointer;font-weight:600';
    settings.appendChild(title);
    settings.appendChild(label);
    settings.appendChild(help);
    settings.appendChild(feedback);
    settings.appendChild(close);
    document.body.appendChild(settings);

    gear.onclick = function () {
        feedback.textContent = '';
        if (typeof settings.showModal === 'function') {
            settings.showModal();
        }
    };
    close.onclick = function () {
        settings.close();
    };
    settings.addEventListener('close', function () {
        gear.focus();
    });

    try {
        var stored = window.localStorage.getItem(storageKey);
        input.checked = (stored === null ? window.sessionStorage.getItem('radius-hide-disabled') : stored) === '1';
    } catch (storageError) {
        input.checked = false;
    }

    function loginOf(entry) {
        var node = entry.querySelector('.radius-client-link');
        var text;
        if (!node) {
            return '';
        }
        text = node.textContent.trim();
        if (text.indexOf(' • ') !== -1) {
            text = text.slice(text.lastIndexOf(' • ') + 3);
        }
        return text.trim().toLowerCase();
    }

    function showStatusNotice(titleText, message) {
        if (typeof window.mkaCompactNotice === 'function') {
            window.mkaCompactNotice(titleText, message);
        } else {
            window.alert(titleText + '\n\n' + message);
        }
    }

    function paint() {
        var entries = list.querySelectorAll('.radius-log-entry');
        var index;
        var entry;
        var state;
        var signature;
        var oldFlags;
        var flags;
        var badge;

        observer.disconnect();
        for (index = 0; index < entries.length; index++) {
            entry = entries[index];
            state = cache[loginOf(entry)];
            entry.classList.toggle('radius-status-hidden', !!(input.checked && state && state.disabled));
            signature = state ? JSON.stringify(state) : '';
            if (entry.getAttribute('data-client-status') === signature) {
                continue;
            }
            entry.setAttribute('data-client-status', signature);
            oldFlags = entry.querySelector('.radius-status-flags');
            if (oldFlags) {
                oldFlags.parentNode.removeChild(oldFlags);
            }
            if (!state) {
                continue;
            }

            flags = document.createElement('div');
            flags.className = 'radius-status-flags';
            if (state.disabled) {
                badge = document.createElement('span');
                badge.className = 'radius-status-flag';
                badge.textContent = 'Desativado';
                flags.appendChild(badge);
            }
            if (state.blocked) {
                badge = document.createElement(state.missing_pages ? 'button' : 'span');
                badge.className = 'radius-status-flag is-blocked' + (state.missing_pages ? ' is-action' : '');
                badge.textContent = 'Bloqueado';
                if (state.missing_pages) {
                    badge.type = 'button';
                    badge.onclick = function () {
                        showStatusNotice('Bloqueado', 'Cliente sem PGCORTE/PGAVISO marcados');
                    };
                }
                flags.appendChild(badge);
            }
            if (flags.childNodes.length > 0) {
                entry.querySelector('.radius-log-meta').appendChild(flags);
            }
        }
        observer.observe(list, { childList: true, subtree: true });
    }

    var observer = new MutationObserver(function () {
        paint();
        window.clearTimeout(pending);
        pending = window.setTimeout(function () {
            refresh(false);
        }, 150);
    });

    function refresh(force) {
        var entryNodes;
        var keys = [];
        var missing = [];
        var known = {};
        var index;
        var key;

        if (busy || document.hidden) {
            return;
        }

        entryNodes = list.querySelectorAll('.radius-log-entry');
        for (index = 0; index < entryNodes.length; index++) {
            key = loginOf(entryNodes[index]);
            if (key !== '' && !known[key]) {
                known[key] = true;
                keys.push(key);
            }
        }
        for (index = 0; index < keys.length; index++) {
            if (force || !Object.prototype.hasOwnProperty.call(cache, keys[index])) {
                missing.push(keys[index]);
            }
        }
        if (missing.length === 0) {
            return;
        }

        busy = true;
        window.fetch('client_status.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ logins: missing.slice(0, 2000) })
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Status indisponível');
            }
            return response.json();
        }).then(function (data) {
            var currentKey;
            for (var itemIndex = 0; itemIndex < missing.length; itemIndex++) {
                currentKey = missing[itemIndex];
                cache[currentKey] = data[currentKey] || null;
            }
            paint();
        }).catch(function () {
            return;
        }).then(function () {
            busy = false;
        });
    }

    input.onchange = function () {
        try {
            window.localStorage.setItem(storageKey, input.checked ? '1' : '0');
            feedback.textContent = 'Configuração salva automaticamente.';
        } catch (storageError) {
            feedback.textContent = 'Não foi possível salvar neste navegador. A escolha vale apenas nesta página.';
        }
        paint();
    };

    window.addEventListener('storage', function (event) {
        if (event.key === storageKey) {
            input.checked = event.newValue === '1';
            paint();
        }
    });

    paint();
    refresh(true);
    var timer = window.setInterval(function () {
        refresh(true);
    }, 15000);

    window.addEventListener('pagehide', function () {
        window.clearInterval(timer);
        window.clearTimeout(pending);
        observer.disconnect();
    });
}());
