/**
 * Vocab Sync — admin enhancements.
 *
 * Everything here is progressive enhancement. With JavaScript off the page
 * still works: the search form has a submit button, and Rename is a plain
 * link to ?edit=<id> that opens an inline field. This file only removes the
 * round trips.
 */
(function () {
    'use strict';

    var listWrap = document.getElementById('vs-term-list');
    var toolbar = document.getElementById('vs-toolbar');
    if (!listWrap || !toolbar) {
        return;
    }

    /* ---- live search ---------------------------------------------------
     * Fetches the same page and swaps in the new list, rather than
     * submitting the form. A form submit reloads the document, which throws
     * away focus and the caret position — you cannot type into a field that
     * is destroyed between keystrokes.
     */

    var searchInput = document.getElementById('q');
    var applyButton = document.getElementById('vs-apply');
    var timer = null;
    var inFlight = null;
    var seq = 0;

    if (applyButton) {
        applyButton.hidden = true;
    }


    /**
     * Tell Omeka to handle a table that was just put in.
     *
     * Admin tables are folded into cards on narrow screens by tablesaw, which runs
     * only when the page loads · a table swapped in with fetch never went through it,
     * so on a phone it became a raw table squeezed until the buttons overlapped
     * (seen after changing Sort by on an iPhone).
     */
    function enhanceTables() {
        try {
            $(document).trigger('enhance.tablesaw');
        } catch (e) {
            // // No tablesaw: leave it an ordinary table, and break nothing else.
        }
    }

    function currentUrl(overrides) {
        var params = new URLSearchParams(new FormData(toolbar));
        Object.keys(overrides || {}).forEach(function (k) {
            if (overrides[k] === null) {
                params.delete(k);
            } else {
                params.set(k, overrides[k]);
            }
        });
        params.delete('edit');
        return toolbar.getAttribute('action') + '?' + params.toString();
    }

    function load(url) {
        var mine = ++seq;
        listWrap.classList.add('vs-loading');

        if (inFlight) {
            inFlight.abort();
        }
        var controller = new AbortController();
        inFlight = controller;

        fetch(url, { signal: controller.signal, credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.text();
            })
            .then(function (html) {
                // Ignore a response that a newer keystroke has superseded.
                if (mine !== seq) { return; }

                // DOMParser, not a regex: the document is inert, nothing in it
                // runs, and parsing someone else's markup with patterns is a
                // bug waiting to happen.
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.getElementById('vs-term-list');
                if (fresh) {
                    listWrap.innerHTML = fresh.innerHTML;
                    bindRenameButtons();
                    enhanceTables();
                }
                var count = doc.getElementById('vs-term-count');
                var liveCount = document.getElementById('vs-term-count');
                if (count && liveCount) {
                    liveCount.innerHTML = count.innerHTML;
                }
                window.history.replaceState(null, '', url);
            })
            .catch(function (err) {
                if (err.name === 'AbortError') { return; }
                // Fall back to a normal page load so the user still gets results.
                window.location.href = url;
            })
            .then(function () {
                if (mine === seq) {
                    listWrap.classList.remove('vs-loading');
                }
            });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            window.clearTimeout(timer);
            // 250 ms: long enough that a normal typing burst is one request,
            // short enough that the list feels attached to the keyboard.
            timer = window.setTimeout(function () {
                load(currentUrl({ page: 1 }));
            }, 250);
        });

        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && searchInput.value !== '') {
                e.preventDefault();
                searchInput.value = '';
                load(currentUrl({ page: 1 }));
            }
        });
    }

    toolbar.addEventListener('change', function (e) {
        if (e.target === searchInput) { return; }
        load(currentUrl({ page: 1 }));
    });

    toolbar.addEventListener('submit', function (e) {
        e.preventDefault();
        window.clearTimeout(timer);
        load(currentUrl({ page: 1 }));
    });

    /* ---- rename in a dialog --------------------------------------------
     * Renaming used to navigate to ?edit=<id>, which reloaded the page at the
     * top and left you hunting for the row you were on. A dialog changes
     * nothing about scroll position.
     */

    var dialog = document.getElementById('vs-rename-dialog');
    var dialogInput = document.getElementById('vs-rename-input');
    var dialogTermId = document.getElementById('vs-rename-term-id');
    var dialogCurrent = document.getElementById('vs-rename-current');
    var dialogCancel = document.getElementById('vs-rename-cancel');

    function bindRenameButtons() {
        if (!dialog || typeof dialog.showModal !== 'function') {
            return; // no <dialog> support: links keep working as before
        }
        listWrap.querySelectorAll('[data-vs-rename]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                dialogTermId.value = btn.getAttribute('data-term-id');
                /* the wording the rename starts from, so a rename made in between is refused (1.38.3) */
                var was = document.getElementById('vs-rename-was');
                if (was) { was.value = btn.getAttribute('data-term-label'); }
                dialogInput.value = btn.getAttribute('data-term-label');
                dialogCurrent.textContent = btn.getAttribute('data-term-label');
                dialog.showModal();
                dialogInput.focus();
                dialogInput.select();
            });
        });
    }

    if (dialogCancel && dialog) {
        dialogCancel.addEventListener('click', function (e) {
            e.preventDefault();
            dialog.close();
        });
    }

    bindRenameButtons();
}());
