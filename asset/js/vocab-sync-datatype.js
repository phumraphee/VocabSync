/**
 * Makes the Vocab Sync dropdown searchable by typing, with the Chosen Omeka already has.
 *
 * Real lists hold hundreds of terms; scrolling for one line at a time does not work.
 *
 * ── Traps already met ─────────────────────────────────────────────
 * Omeka keeps the markup of a value field as a hidden template and clones it
 * each time a value is added · if Chosen is set up on the template, the clone
 * carries Chosen's look but no JS instance is bound to it = it shows
 * "Select or type to search" but nothing happens when clicked.
 *
 * So (1) the template is never touched, and (2) the Chosen leftovers that come
 * with a clone are cleaned away before each new set-up.
 *
 * ── Added in 1.36.0 ──────────────────────────────────────────────────
 * - retired terms: form() sends them too (data-retired) so a value already set is not lost;
 *   the script removes them from every field that does not have that term selected, before Chosen
 * - the hidden vocabsync:term_id field always follows the selected term
 * - typing a term that does not exist → a "+ Add …" row → a confirmation → the term is added (or
 *   a retired term brought back), and the new option goes into every field of the same vocab,
 *   the template included; otherwise a field added later would not have it
 *
 * change is always bound with jQuery .on(): Chosen fires change through jQuery, which
 * does not call listeners bound with addEventListener
 */
(function () {
    'use strict';

    var $ = window.jQuery;
    if (!$) {
        return;
    }

    var SELECTOR = 'select.vocabsync-select';

    /** /** Is it inside the hidden template? If so, do not touch it. */
    function isTemplate(el) {
        var $el = $(el);
        if ($el.closest('.template, #value-templates, .value-template').length) {
            return true;
        }
        // // The template is always hidden and real fields are visible · offsetParent
        // // is used instead of :hidden because it is more reliable for elements really in the DOM.
        return el.offsetParent === null;
    }

    /**
     * Clean away the Chosen leftovers that come with a clone.
     *
     * Chosen does two things to the original select: adds the class chosen-hidden-accessible
     * and puts a div.chosen-container next to it · both get cloned too.
     */
    function stripStaleChosen($sel) {
        $sel.parent().children('.chosen-container').remove();
        $sel
            .removeData('vsChosen')
            .removeData('chosen')
            .removeClass('chosen-hidden-accessible')
            .removeAttr('aria-hidden')
            .removeAttr('tabindex')
            .css({ position: '', clip: '', width: '', height: '', display: '' });
    }

    /** /** The hidden field that holds the id of the selected term (in the same .value). */
    function termIdInput($sel) {
        return $sel.closest('.value').find('input.vocabsync-term-id').first();
    }

    /** /** Make the hidden field match the term selected now. */
    function syncTermId($sel) {
        var $opt = $sel.find('option:selected');
        var id = ($opt.length && $opt.val() !== '') ? String($opt.attr('data-term-id') || '') : '';
        termIdInput($sel).val(id);
    }

    /**
     * Remove retired options, except the one this field has selected (the item's existing value).
     * Must run after Omeka has filled in the values — enhance() is always called after that.
     */
    function pruneRetired($sel) {
        $sel.find('option[data-retired]').each(function () {
            if (!this.selected) {
                $(this).remove();
            }
        });
    }

    function enhance(el) {
        if (isTemplate(el)) {
            return;
        }
        var $sel = $(el);
        if (!$sel.length) {
            return;
        }
        if (!$sel.data('vsPrepared')) {
            // // Once per field, whether or not there is Chosen.
            pruneRetired($sel);
            syncTermId($sel);
            $sel.on('change', function () {
                syncTermId($sel);
                closePanel($sel);
            });
            $sel.data('vsPrepared', true);
        }
        if (!$.fn.chosen) {
            return; // no Chosen: carry on with a plain select, nothing breaks
        }

        // // Already set up and real — skip · a clone does not carry this flag,
        // // because jQuery .clone() does not copy data, so one flag tells the
        // // two cases apart: flag = ours, already set up · no flag but Chosen markup =
        // // leftovers from a clone, to be cleaned and set up again.
        if ($sel.data('vsChosen')) {
            return;
        }

        stripStaleChosen($sel);

        $sel.chosen({
            width: '100%',
            // // Match any part of the term, not only its start — needed because terms are
            // // in two languages on one line, e.g. "Cologne Köln", and typing "Köln" must find it.
            search_contains: true,
            allow_single_deselect: true,
            no_results_text: 'No term matches',
            placeholder_text_single: 'Select or type to search'
        });
        $sel.data('vsChosen', true);
        $sel.trigger('chosen:updated');

        if ($sel.attr('data-can-add') === '1' && $sel.attr('data-api-url')) {
            $sel.on('chosen:no_results', function (e, params) {
                showAddRow($sel, params && params.chosen);
            });
        }
    }

    // // ── Adding a term from the edit page ─────────────────────────────────

    function chosenOf($sel) {
        return $sel.data('chosen') || null;
    }

    function searchText(chosen) {
        return chosen && chosen.search_field ? $.trim(String(chosen.search_field.val() || '')) : '';
    }

    /**
     * The "+ Add …" row after Chosen's "No term matches".
     * It gets the no-results class too, so Chosen clears it itself on every new search
     * (no_results_clear() removes .no-results); nothing to clean up here.
     */
    function showAddRow($sel, chosen) {
        chosen = chosen || chosenOf($sel);
        var text = searchText(chosen);
        if (!chosen || text === '') {
            return;
        }
        var $li = $('<li class="no-results vs-add-term" role="option" tabindex="-1"></li>');
        $li.append(document.createTextNode('+ Add "' + text + '" as a new term'));
        $li.on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            startAdd($sel, text);
        });
        chosen.search_results.append($li);

        // // Enter in the search box while this row is shown = press this row · bound once per field.
        if (!$sel.data('vsEnterBound')) {
            chosen.search_field.on('keyup.vocabsync', function (e) {
                if ((e.which || e.keyCode) !== 13) {
                    return;
                }
                var c = chosenOf($sel);
                // // Only while the dropdown is open — after Esc the row stays in the DOM.
                if (c && c.results_showing && c.search_results.find('li.vs-add-term').length) {
                    e.preventDefault();
                    startAdd($sel, searchText(c));
                }
            });
            $sel.data('vsEnterBound', true);
        }
    }

    function resourceIdFromUrl() {
        var m = /\/admin\/(?:item|item-set|media)\/(\d+)\/edit/.exec(window.location.pathname || '');
        return m ? m[1] : '';
    }

    function api($sel, data) {
        data.resource_id = resourceIdFromUrl();
        return $.ajax({
            type: 'POST',
            url: $sel.attr('data-api-url'),
            data: data,
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
    }

    function errorText(xhr) {
        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
            return xhr.responseJSON.message;
        }
        return 'Could not reach the server. Your session may have expired; '
            + 'reload the page and try again. Nothing was changed.';
    }

    /** /** The confirmation box under this field's dropdown (made the first time it is used). */
    function panelFor($sel) {
        var $value = $sel.closest('.value');
        var $panel = $value.find('.vocabsync-panel').first();
        if (!$panel.length) {
            $panel = $('<div class="vocabsync-panel" role="alertdialog" aria-live="polite"></div>');
            var $tools = $value.find('.vocabsync-tools').first();
            if ($tools.length) {
                $tools.before($panel);
            } else {
                $sel.parent().append($panel);
            }
        }
        return $panel;
    }

    function closePanel($sel) {
        $sel.closest('.value').find('.vocabsync-panel').remove();
    }

    function vocabName($sel) {
        return $sel.attr('data-vocab-label') || 'this vocabulary';
    }

    /**
     * Draws the box: text + buttons · buttons = [{label, primary, onClick}]
     * Buttons are always type="button", otherwise they would submit the item form.
     */
    function renderPanel($sel, kind, message, buttons) {
        var $panel = panelFor($sel);
        $panel.empty().attr('data-kind', kind);
        $('<p class="vocabsync-panel-text"></p>').text(message).appendTo($panel);
        var $row = $('<div class="vocabsync-panel-buttons"></div>').appendTo($panel);
        $.each(buttons || [], function (i, b) {
            $('<button type="button"></button>')
                .addClass(b.primary ? 'button vocabsync-confirm' : 'button vocabsync-cancel')
                .text(b.label)
                .on('click', function (e) {
                    e.preventDefault();
                    b.onClick($(this));
                })
                .appendTo($row);
        });
        var $first = $panel.find('button.vocabsync-confirm').first();
        if ($first.length) {
            $first.trigger('focus');
        }
        return $panel;
    }

    function busy($sel, on) {
        panelFor($sel).find('button').prop('disabled', !!on);
    }

    function cancelButton($sel) {
        return { label: 'Cancel', onClick: function () { closePanel($sel); } };
    }

    /** /** First step: ask the server whether the term is new / already there / retired (read only). */
    function startAdd($sel, text) {
        if (text === '') {
            return;
        }
        $sel.trigger('chosen:close');
        renderPanel($sel, 'wait', 'Checking "' + text + '"…', []);
        api($sel, { op: 'check', label: text }).done(function (res) {
            handleStatus($sel, text, res);
        }).fail(function (xhr) {
            renderPanel($sel, 'error', errorText(xhr), [cancelButton($sel)]);
        });
    }

    function handleStatus($sel, text, res) {
        if (!res || !res.status) {
            renderPanel($sel, 'error', errorText(null), [cancelButton($sel)]);
            return;
        }
        if (res.status === 'new') {
            renderPanel(
                $sel, 'confirm-add',
                'Add "' + text + '" to ' + vocabName($sel) + '? It becomes a choice for every '
                    + 'resource that uses this vocabulary. You can rename, retire or cancel it '
                    + 'later in Manage terms.',
                [{ label: 'Add term', primary: true, onClick: function () { confirmAdd($sel, text); } },
                    cancelButton($sel)]
            );
        } else if (res.status === 'retired') {
            renderPanel(
                $sel, 'confirm-reactivate',
                '"' + res.label + '" is already in ' + vocabName($sel) + ', but it is retired. '
                    + 'Reactivate it so it can be chosen again?',
                [{ label: 'Reactivate term', primary: true,
                    onClick: function () { confirmReactivate($sel, res.id); } },
                    cancelButton($sel)]
            );
        } else if (res.status === 'active') {
            // // Already there (differing only in upper and lower case, say) — no need to add; select it.
            applyTerm($sel, res);
            renderPanel($sel, 'info',
                '"' + res.label + '" is already in ' + vocabName($sel) + '. It has been selected.',
                [{ label: 'OK', primary: true, onClick: function () { closePanel($sel); } }]);
        } else {
            renderPanel($sel, 'error', res.message || errorText(null), [cancelButton($sel)]);
        }
    }

    /** /** Confirmation step: the server checks again before writing; it does not trust the check. */
    function confirmAdd($sel, text) {
        busy($sel, true);
        var before = $sel.val();
        api($sel, { op: 'add', label: text }).done(function (res) {
            if (res && res.status === 'added') {
                applyTerm($sel, res, $sel.val() !== before);
                done($sel, 'Added "' + res.label + '" to ' + vocabName($sel) + '.', $sel.val() !== res.label);
            } else {
                handleStatus($sel, text, res); // somebody added it first / it is retired
            }
        }).fail(function (xhr) {
            renderPanel($sel, 'error', errorText(xhr), [cancelButton($sel)]);
        });
    }

    function confirmReactivate($sel, termId) {
        busy($sel, true);
        var before = $sel.val();
        api($sel, { op: 'reactivate', term_id: termId }).done(function (res) {
            if (res && (res.status === 'reactivated' || res.status === 'already_active')) {
                applyTerm($sel, res, $sel.val() !== before);
                done($sel, 'Reactivated "' + res.label + '".', $sel.val() !== res.label);
            } else {
                renderPanel($sel, 'error', (res && res.message) || errorText(null), [cancelButton($sel)]);
            }
        }).fail(function (xhr) {
            renderPanel($sel, 'error', errorText(xhr), [cancelButton($sel)]);
        });
    }

    function done($sel, message, notSelected) {
        renderPanel($sel, 'done',
            message + (notSelected
                ? ' It was not selected here, because you changed this field while it was being saved.'
                : ' It is selected here; save this resource to keep the selection.'),
            [{ label: 'OK', primary: true, onClick: function () { closePanel($sel); } }]);
    }

    /**
     * Put the term into every select of the same vocab on the page (the hidden template too)
     * and select it in the field that was used.
     */
    function applyTerm($sel, term, keepSelection) {
        var vocabId = $sel.attr('data-vocab-id');
        $('select.vocabsync-select').filter(function () {
            return $(this).attr('data-vocab-id') === vocabId;
        }).each(function () {
            var $s = $(this);
            var $opt = $s.find('option').filter(function () {
                return String($(this).attr('data-term-id')) === String(term.id);
            }).first();
            if (!$opt.length) {
                $opt = $('<option></option>').attr('data-term-id', term.id).appendTo($s);
            }
            $opt.val(term.label).text(term.label).removeAttr('data-retired');
            if ($s.data('vsChosen')) {
                $s.trigger('chosen:updated');
            }
        });
        if (keepSelection) {
            return; // the user changed this field while waiting — do not overwrite their choice
        }
        $sel.val(term.label);
        syncTermId($sel);
        $sel.trigger('change');
        if ($sel.data('vsChosen')) {
            $sel.trigger('chosen:updated');
        }
    }

    function enhanceWithin($scope) {
        $scope.find(SELECTOR).addBack(SELECTOR).each(function () {
            enhance(this);
        });
    }

    // // Value fields made or replaced after the page loaded — the main path.
    $(document).on('o:prepare-value', function (e, dataType, value) {
        var $scope = (value && value.length) ? $(value) : $(document);
        // // Let Omeka finish filling in the select first, then set up Chosen.
        window.setTimeout(function () {
            enhanceWithin($scope);
        }, 0);
    });

    // // Sections (tabs) of the edit page hidden at load, e.g. a page opened with #item-media:
    // // their fields have offsetParent = null, so they are skipped at load, and switching tabs
    // // makes no mutation for the observer to see · Omeka fires o:section-opened every time
    // // a tab is opened (global.js switchActiveSection, 4.2.1).
    $(document).on('o:section-opened', function (e) {
        enhanceWithin($(e.target));
    });

    $(document).ready(function () {
        // // Values already there when the edit page opens (not the template, which isTemplate guards).
        enhanceWithin($(document));

        // // Second net: a select of ours that appears without going through o:prepare-value
        if (!window.MutationObserver) {
            return;
        }
        var root = document.getElementById('resource-form') || document.body;
        var observer = new MutationObserver(function () {
            $(SELECTOR).each(function () {
                if (!isTemplate(this) && !$(this).data('vsChosen')) {
                    enhance(this);
                }
            });
        });
        observer.observe(root, { childList: true, subtree: true });
    });
}());
