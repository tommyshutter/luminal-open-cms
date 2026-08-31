/**
 * AudienceBuilder — Form Builder (Admin)
 *
 * Manages the Forms tab: card grid, editor modal, field CRUD.
 * Namespace: ABForms
 */
(function() {
'use strict';

var API = window.AB_BOOT ? window.AB_BOOT.apiUrl : '';
var fieldCounter = 0;

var FIELD_TYPES = [
    { value: 'text',     label: 'Text' },
    { value: 'email',    label: 'Email' },
    { value: 'tel',      label: 'Phone' },
    { value: 'url',      label: 'URL' },
    { value: 'number',   label: 'Number' },
    { value: 'date',     label: 'Date' },
    { value: 'textarea', label: 'Textarea' },
    { value: 'select',   label: 'Dropdown' },
    { value: 'radio',    label: 'Radio Buttons' },
    { value: 'checkbox', label: 'Checkbox' },
    { value: 'hidden',   label: 'Hidden' }
];

/* ── API helper ── */
function api(action, data) {
    return fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ action: action }, data || {}))
    }).then(function(r) { return r.json(); });
}

/* ── Grid ── */
function loadForms() {
    var grid = document.getElementById('abFormsGrid');
    if (!grid) return;
    grid.innerHTML = '<div style="color:rgba(255,255,255,.4);padding:20px;">Loading forms…</div>';

    api('list_forms').then(function(j) {
        if (!j.ok) { grid.innerHTML = '<div style="color:#ef5350;padding:20px;">Error: ' + (j.error || 'unknown') + '</div>'; return; }

        var html = '';
        (j.forms || []).forEach(function(f) {
            var sc = '[[ab-form:' + f.slug + ']]';
            html += '<div class="ab-form-card">';
            html += '  <div class="ab-form-card-title">' + esc(f.title || f.slug) + '</div>';
            html += '  <div class="ab-form-card-meta">';
            html += '    <span class="ab-form-card-badge">' + f.field_count + ' field' + (f.field_count !== 1 ? 's' : '') + '</span>';
            if (f.updated_at) html += '    <span>' + f.updated_at.substring(0, 10) + '</span>';
            html += '  </div>';
            html += '  <div class="ab-form-card-shortcode" onclick="ABForms.copyShortcode(this)" title="Click to copy">';
            html += '    <span>' + esc(sc) + '</span><span class="copy-icon"><i class="fa-regular fa-copy"></i></span>';
            html += '  </div>';
            html += '  <div class="ab-form-card-actions">';
            html += '    <button onclick="ABForms.openEditor(\'' + esc(f.slug) + '\')"><i class="fa-solid fa-pen-to-square"></i> Edit</button>';
            html += '    <button class="ab-fca-delete" onclick="ABForms.deleteForm(\'' + esc(f.slug) + '\')"><i class="fa-solid fa-trash"></i> Delete</button>';
            html += '  </div>';
            html += '</div>';
        });

        // New form card
        html += '<div class="ab-form-card ab-form-card-new" onclick="ABForms.openEditor()">';
        html += '  <div class="ab-form-card-new-inner"><i class="fa-solid fa-plus"></i><span>New Form</span></div>';
        html += '</div>';

        grid.innerHTML = html;
    });
}

/* ── Copy shortcode ── */
function copyShortcode(el) {
    var text = el.querySelector('span').textContent;
    navigator.clipboard.writeText(text).then(function() {
        var icon = el.querySelector('.copy-icon');
        if (icon) { icon.innerHTML = '<i class="fa-solid fa-check" style="color:#4caf50"></i>'; setTimeout(function() { icon.innerHTML = '<i class="fa-regular fa-copy"></i>'; }, 1500); }
    });
}

/* ── Editor ── */
function openEditor(slug) {
    var overlay = document.getElementById('abFormEditorOverlay');
    if (!overlay) return;

    // Reset
    fieldCounter = 0;
    document.getElementById('abFeTitle').value = '';
    document.getElementById('abFeSlug').value = '';
    document.getElementById('abFeSubmitText').value = 'Submit';
    document.getElementById('abFeSuccessMsg').value = 'Thank you! Your submission has been received.';
    document.getElementById('abFeCssClass').value = '';
    document.getElementById('abFeFormStyle').value = 'default';
    document.getElementById('abFeCustomCss').value = '';
    document.getElementById('abFeDisplayMode').value = 'embedded';
    document.getElementById('abFeTriggerLabel').value = '';
    toggleCustomCssField();
    document.getElementById('abFeFieldList').innerHTML = '';
    document.getElementById('abFeDeleteBtn').style.display = slug ? '' : 'none';
    document.getElementById('abFeStatus').textContent = '';

    if (slug) {
        document.getElementById('abFeStatus').textContent = 'Loading…';
        api('get_form', { slug: slug }).then(function(j) {
            if (!j.ok) { document.getElementById('abFeStatus').textContent = 'Error: ' + (j.error || ''); return; }
            var f = j.form;
            document.getElementById('abFeTitle').value = f.title || '';
            document.getElementById('abFeSlug').value = f.slug || '';
            document.getElementById('abFeSubmitText').value = f.submit_text || 'Submit';
            document.getElementById('abFeSuccessMsg').value = f.success_message || '';
            document.getElementById('abFeCssClass').value = f.css_class || '';
            document.getElementById('abFeFormStyle').value = f.form_style || 'default';
            document.getElementById('abFeCustomCss').value = f.custom_css || '';
            document.getElementById('abFeDisplayMode').value = f.display_mode || 'embedded';
            document.getElementById('abFeTriggerLabel').value = f.trigger_label || '';
            toggleTriggerLabelField();
            toggleCustomCssField();

            (f.fields || []).forEach(function(fld) { addFieldCard(fld); });
            document.getElementById('abFeStatus').textContent = '';
        });
    } else {
        // New form — add a default field
        addFieldCard({ type: 'text', name: 'name', label: 'Full Name', placeholder: 'John Doe', required: true, width: 'full' });
        addFieldCard({ type: 'email', name: 'email', label: 'Email', placeholder: 'john@example.com', required: true, width: 'half' });
        addFieldCard({ type: 'tel', name: 'phone', label: 'Phone', placeholder: '(555) 123-4567', required: false, width: 'half' });
    }

    overlay.classList.add('active');
    // Relocate to body to escape backdrop-filter trap
    if (overlay.parentElement !== document.body) document.body.appendChild(overlay);

    initSortable();
}

function closeEditor() {
    var overlay = document.getElementById('abFormEditorOverlay');
    if (overlay) overlay.classList.remove('active');
    loadForms();
}

/* ── Field cards ── */
function addFieldCard(data) {
    data = data || { type: 'text', name: '', label: '', placeholder: '', required: false, width: 'full' };
    fieldCounter++;
    var fid = 'abFld-' + fieldCounter;
    var list = document.getElementById('abFeFieldList');
    if (!list) return;

    var card = document.createElement('div');
    card.className = 'ab-form-field-card';
    card.setAttribute('data-fid', fid);

    var typeOpts = '';
    FIELD_TYPES.forEach(function(t) {
        typeOpts += '<option value="' + t.value + '"' + (t.value === data.type ? ' selected' : '') + '>' + t.label + '</option>';
    });

    var needsOptions = data.type === 'select' || data.type === 'radio';
    var optionsHtml = '';
    if (needsOptions && data.options && data.options.length) {
        data.options.forEach(function(o) {
            optionsHtml += '<div class="ab-ffc-option-row"><input type="text" value="' + esc(o) + '"><button type="button" onclick="ABForms.removeOption(this)" title="Remove">&times;</button></div>';
        });
    }

    card.innerHTML =
        '<div class="ab-ffc-header">' +
        '  <span class="ab-ffc-drag" title="Drag to reorder"><i class="fa-solid fa-grip-vertical"></i></span>' +
        '  <select onchange="ABForms.onTypeChange(this)">' + typeOpts + '</select>' +
        '  <div class="ab-ffc-actions">' +
        '    <button type="button" onclick="ABForms.moveField(this,-1)" title="Move up"><i class="fa-solid fa-chevron-up"></i></button>' +
        '    <button type="button" onclick="ABForms.moveField(this,1)" title="Move down"><i class="fa-solid fa-chevron-down"></i></button>' +
        '    <button type="button" class="ab-ffc-del" onclick="ABForms.removeField(this)" title="Remove field"><i class="fa-solid fa-trash"></i></button>' +
        '  </div>' +
        '</div>' +
        '<div class="ab-ffc-body">' +
        '  <div><label>Field Name</label><input type="text" class="ab-ffc-name" value="' + esc(data.name || '') + '" placeholder="field_name"></div>' +
        '  <div><label>Label</label><input type="text" class="ab-ffc-label" value="' + esc(data.label || '') + '" placeholder="Display label"></div>' +
        '  <div><label>Placeholder</label><input type="text" class="ab-ffc-placeholder" value="' + esc(data.placeholder || '') + '" placeholder="Hint text"></div>' +
        '  <div><label>Default Value</label><input type="text" class="ab-ffc-default" value="' + esc(data.default || '') + '" placeholder="(optional)"></div>' +
        '</div>' +
        '<div class="ab-ffc-bottom">' +
        '  <label><input type="checkbox" class="ab-ffc-required"' + (data.required ? ' checked' : '') + '> Required</label>' +
        '  <label><input type="checkbox" class="ab-ffc-half"' + (data.width === 'half' ? ' checked' : '') + '> Half Width</label>' +
        '</div>' +
        '<div class="ab-ffc-options" style="display:' + (needsOptions ? 'block' : 'none') + ';">' +
        '  <div class="ab-ffc-options-title">Options</div>' +
        '  <div class="ab-ffc-options-list">' + optionsHtml + '</div>' +
        '  <button type="button" class="ab-ffc-add-option" onclick="ABForms.addOption(this)">+ Add Option</button>' +
        '</div>';

    list.appendChild(card);
}

function removeField(btn) {
    var card = btn.closest('.ab-form-field-card');
    if (card) card.remove();
}

function moveField(btn, dir) {
    var card = btn.closest('.ab-form-field-card');
    if (!card) return;
    var list = card.parentElement;
    if (dir === -1 && card.previousElementSibling) {
        list.insertBefore(card, card.previousElementSibling);
    } else if (dir === 1 && card.nextElementSibling) {
        list.insertBefore(card.nextElementSibling, card);
    }
}

function onTypeChange(sel) {
    var card = sel.closest('.ab-form-field-card');
    var optSection = card.querySelector('.ab-ffc-options');
    var needs = sel.value === 'select' || sel.value === 'radio';
    optSection.style.display = needs ? 'block' : 'none';
}

function addOption(btn) {
    var optList = btn.previousElementSibling;
    var row = document.createElement('div');
    row.className = 'ab-ffc-option-row';
    row.innerHTML = '<input type="text" value="" placeholder="Option text"><button type="button" onclick="ABForms.removeOption(this)" title="Remove">&times;</button>';
    optList.appendChild(row);
    row.querySelector('input').focus();
}

function removeOption(btn) {
    btn.parentElement.remove();
}

/* ── Slug generation ── */
function generateSlug(title) {
    return title.toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '')
        .substring(0, 50);
}

/* ── Save form ── */
function saveForm() {
    var title = document.getElementById('abFeTitle').value.trim();
    var slug  = document.getElementById('abFeSlug').value.trim();
    var status = document.getElementById('abFeStatus');

    if (!title) { status.textContent = 'Title is required'; return; }
    if (!slug) slug = generateSlug(title);
    slug = slug.replace(/[^a-z0-9_-]/gi, '').toLowerCase();
    if (!slug) { status.textContent = 'Invalid slug'; return; }

    // Collect fields from DOM
    var cards = document.querySelectorAll('#abFeFieldList .ab-form-field-card');
    var fields = [];
    cards.forEach(function(card) {
        var type = card.querySelector('.ab-ffc-header select').value;
        var name = card.querySelector('.ab-ffc-name').value.trim();
        var label = card.querySelector('.ab-ffc-label').value.trim();
        var placeholder = card.querySelector('.ab-ffc-placeholder').value.trim();
        var defaultVal = card.querySelector('.ab-ffc-default').value.trim();
        var required = card.querySelector('.ab-ffc-required').checked;
        var half = card.querySelector('.ab-ffc-half').checked;

        if (!name) name = generateSlug(label || 'field');

        var field = {
            id: 'fld-' + (fields.length + 1),
            type: type,
            name: name,
            label: label,
            placeholder: placeholder,
            required: required,
            width: half ? 'half' : 'full'
        };
        if (defaultVal) field['default'] = defaultVal;

        // Collect options for select/radio
        if (type === 'select' || type === 'radio') {
            var opts = [];
            card.querySelectorAll('.ab-ffc-options-list .ab-ffc-option-row input').forEach(function(inp) {
                var v = inp.value.trim();
                if (v) opts.push(v);
            });
            field.options = opts;
        }

        fields.push(field);
    });

    if (!fields.length) { status.textContent = 'Add at least one field'; return; }

    var form = {
        slug: slug,
        title: title,
        fields: fields,
        submit_text: document.getElementById('abFeSubmitText').value.trim() || 'Submit',
        success_message: document.getElementById('abFeSuccessMsg').value.trim() || 'Thank you!',
        css_class: document.getElementById('abFeCssClass').value.trim(),
        form_style: document.getElementById('abFeFormStyle').value,
        custom_css: document.getElementById('abFeCustomCss').value,
        display_mode: document.getElementById('abFeDisplayMode').value,
        trigger_label: document.getElementById('abFeTriggerLabel').value.trim(),
        tracking: { pixel_id: '', conversion_event: '', custom_params: {} }
    };

    status.textContent = 'Saving…';
    api('save_form', { form: form }).then(function(j) {
        if (j.ok) {
            closeEditor();
        } else {
            status.textContent = 'Error: ' + (j.error || 'unknown');
        }
    }).catch(function(e) {
        status.textContent = 'Network error';
    });
}

/* ── Delete form ── */
function deleteForm(slug) {
    if (!confirm('Delete form "' + slug + '"? This cannot be undone.')) return;
    api('delete_form', { slug: slug }).then(function(j) {
        if (j.ok) loadForms();
        else alert('Delete failed: ' + (j.error || 'unknown'));
    });
}

/* ── Sortable (drag reorder) ── */
function initSortable() {
    var list = document.getElementById('abFeFieldList');
    if (!list || !window.Sortable) return;
    if (list._sortable) list._sortable.destroy();
    list._sortable = new Sortable(list, {
        handle: '.ab-ffc-drag',
        animation: 150,
        ghostClass: 'sortable-ghost'
    });
}

/* ── Title → slug auto-fill ── */
function onTitleInput() {
    var titleEl = document.getElementById('abFeTitle');
    var slugEl = document.getElementById('abFeSlug');
    if (titleEl && slugEl && !slugEl._userEdited) {
        slugEl.value = generateSlug(titleEl.value);
    }
}

/* ── Escape HTML ── */
function esc(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s || ''));
    return d.innerHTML;
}

/* ── Toggle custom CSS field visibility ── */
function toggleCustomCssField() {
    var el = document.getElementById('abFeCustomCssField');
    if (el) el.style.display = document.getElementById('abFeFormStyle').value === 'custom' ? '' : 'none';
}

/* ── Toggle lightbox trigger-label field (only for display_mode=lightbox) ── */
function toggleTriggerLabelField() {
    var el = document.getElementById('abFeTriggerLabelField');
    if (el) el.style.display = document.getElementById('abFeDisplayMode').value === 'lightbox' ? '' : 'none';
}

/* ════════════════════════════════════════════════════════════════
   QUICK START — standard forms: style picker + live preview +
   copy-shortcode + on-demand create (no auto-seed).
   ════════════════════════════════════════════════════════════════ */
var QS_FORMS = [
    { slug: 'contact', title: 'Contact Us', submit: 'Send Message',
      fields: [ { type: 'text', label: 'Name', w: 'half' }, { type: 'email', label: 'Email', w: 'half' }, { type: 'textarea', label: 'Message', w: 'full' } ] },
    { slug: 'signup', title: 'Stay in the Loop', submit: 'Subscribe',
      fields: [ { type: 'text', label: 'Name', w: 'half' }, { type: 'email', label: 'Email', w: 'half' } ] }
];

function qsFieldHtml(f) {
    var input = (f.type === 'textarea') ? '<textarea rows="3" disabled></textarea>'
                                        : '<input type="' + f.type + '" disabled>';
    return '<div class="ab-field ab-field-' + f.w + '"><label>' + f.label + '</label>' + input + '</div>';
}

function qsFormHtml(def, style) {
    var rows = '', i = 0;
    while (i < def.fields.length) {
        if (def.fields[i].w === 'half') {
            var row = '<div class="ab-field-row">';
            while (i < def.fields.length && def.fields[i].w === 'half') { row += qsFieldHtml(def.fields[i]); i++; }
            rows += row + '</div>';
        } else { rows += qsFieldHtml(def.fields[i]); i++; }
    }
    var styleAttr = (style && style !== 'default') ? ' data-ab-style="' + style + '"' : '';
    return '<div class="ab-qs-card">' +
        '<div class="ab-form-wrap"' + styleAttr + '>' +
            '<h3 class="ab-form-title">' + def.title + '</h3>' +
            '<form onsubmit="return false">' + rows +
                '<div class="ab-field ab-field-full"><button type="button" class="ab-submit">' + def.submit + '</button></div>' +
            '</form>' +
        '</div>' +
        '<div class="ab-form-card-shortcode" onclick="ABForms.copyShortcode(this)" title="Click to copy">' +
            '<span>[[ab-form:' + def.slug + ']]</span><span class="copy-icon"><i class="fa-regular fa-copy"></i></span>' +
        '</div>' +
    '</div>';
}

function qsRenderPreviews() {
    var wrap = document.getElementById('abQsPreviews');
    if (!wrap) return;
    var sel = document.getElementById('abQsStyle');
    var style = sel ? sel.value : 'default';
    wrap.innerHTML = QS_FORMS.map(function(d) { return qsFormHtml(d, style); }).join('');
}

function qsCreate() {
    var btn = document.getElementById('abQsCreate');
    var sel = document.getElementById('abQsStyle');
    var pixelEl = document.getElementById('abQsPixel');
    var style = sel ? sel.value : 'default';
    var pixel = pixelEl ? pixelEl.value.trim() : '';
    var orig = btn ? btn.innerHTML : '';
    if (btn) btn.disabled = true;
    api('create_basic_forms', { style: style, pixel: pixel }).then(function(j) {
        if (btn) btn.disabled = false;
        if (j && j.ok) {
            var made = (j.created || []).length;
            if (btn) {
                btn.innerHTML = '<i class="fa-solid fa-check"></i> ' + (made ? ('Created ' + made + ' form' + (made > 1 ? 's' : '')) : 'Already created');
                setTimeout(function() { btn.innerHTML = orig; }, 2200);
            }
            loadForms();
        } else if (btn) {
            btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Failed';
            setTimeout(function() { btn.innerHTML = orig; }, 2200);
        }
    });
}

function initQuickstart() {
    var styleSel = document.getElementById('abQsStyle');
    if (styleSel) styleSel.addEventListener('change', qsRenderPreviews);
    var createBtn = document.getElementById('abQsCreate');
    if (createBtn) createBtn.addEventListener('click', qsCreate);
    qsRenderPreviews();
}

/* ── Init ── */
function init() {
    initQuickstart();

    // Tab wiring: when Forms tab is clicked, load forms + (re)render quickstart preview
    var formsTab = document.querySelector('.ab-tab[data-tab="forms"]');
    if (formsTab) {
        formsTab.addEventListener('click', function() { loadForms(); qsRenderPreviews(); });
    }

    // Title → slug auto
    var titleEl = document.getElementById('abFeTitle');
    if (titleEl) titleEl.addEventListener('input', onTitleInput);

    // Mark slug as user-edited if they type in it
    var slugEl = document.getElementById('abFeSlug');
    if (slugEl) slugEl.addEventListener('input', function() { slugEl._userEdited = true; });

    // Close button
    var closeBtn = document.getElementById('abFeCloseBtn');
    if (closeBtn) closeBtn.addEventListener('click', closeEditor);

    // Save button
    var saveBtn = document.getElementById('abFeSaveBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveForm);

    // Cancel button
    var cancelBtn = document.getElementById('abFeCancelBtn');
    if (cancelBtn) cancelBtn.addEventListener('click', closeEditor);

    // Delete button
    var deleteBtn = document.getElementById('abFeDeleteBtn');
    if (deleteBtn) deleteBtn.addEventListener('click', function() {
        var slug = document.getElementById('abFeSlug').value.trim();
        if (slug) deleteForm(slug);
    });

    // Form style dropdown
    var styleEl = document.getElementById('abFeFormStyle');
    if (styleEl) styleEl.addEventListener('change', toggleCustomCssField);

    // Display-mode dropdown (embedded | lightbox)
    var dispEl = document.getElementById('abFeDisplayMode');
    if (dispEl) dispEl.addEventListener('change', toggleTriggerLabelField);

    // Add field button
    var addBtn = document.getElementById('abFeAddFieldBtn');
    if (addBtn) addBtn.addEventListener('click', function() { addFieldCard(); initSortable(); });
}

document.addEventListener('DOMContentLoaded', init);

/* ── Public namespace ── */
window.ABForms = {
    loadForms: loadForms,
    openEditor: openEditor,
    closeEditor: closeEditor,
    addFieldCard: addFieldCard,
    removeField: removeField,
    moveField: moveField,
    onTypeChange: onTypeChange,
    addOption: addOption,
    removeOption: removeOption,
    deleteForm: deleteForm,
    saveForm: saveForm,
    copyShortcode: copyShortcode
};

})();
