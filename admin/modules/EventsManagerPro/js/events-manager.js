/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: EventsManagerPro v1.0.0
 * File: js/events-manager.js
 *
 * IIFE — Event management UI logic: CRUD, venues, social sharing, image optimization.
 * API URL via window.EMP_BOOT, ok/error response format.
 */
(function(){
'use strict';

var API = (window.EMP_BOOT && EMP_BOOT.endpoints) ? EMP_BOOT.endpoints.api : '';
var currentRightTab = 'upcoming';
var venuesCache = [];
var eventsCache = [];
var defaultBandName = '';

document.addEventListener('DOMContentLoaded', function() {
    flatpickr(".flatpickr-date", {
        dateFormat: "Y-m-d",
        theme: "dark",
        disableMobile: true,
        allowInput: true
    });

    flatpickr(".flatpickr-time", {
        enableTime: true,
        noCalendar: true,
        dateFormat: "H:i",
        time_24hr: false,
        theme: "dark",
        defaultHour: 20,
        defaultMinute: 0,
        disableMobile: true
    });

    loadVenues();
    loadEvents('upcoming');
    loadCreds();
    loadSettings();

    var zone = document.getElementById('drop_zone');
    if (zone) {
        zone.addEventListener('dragover', function(e) { e.preventDefault(); zone.style.borderColor = '#b22222'; });
        zone.addEventListener('dragleave', function(e) { e.preventDefault(); zone.style.borderColor = ''; });
        zone.addEventListener('drop', function(e) {
            e.preventDefault(); zone.style.borderColor = '';
            if (e.dataTransfer.files.length) uploadFile(e.dataTransfer.files[0], 'poster');
        });
    }
});

/* ===== AJAX HELPER ===== */
function post(act, data, cb) {
    var fd = new FormData();
    fd.append('action', act);
    for (var k in data) fd.append(k, data[k]);
    postData(fd, cb);
}

function postData(fd, cb) {
    fetch(API, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.ok) { cb(res); }
            else { alert('Error: ' + (res.error || 'Unknown error')); }
        })
        .catch(function(e) { console.error(e); });
}

/* ===== UI TABS ===== */
window.setLeftTab = function(view) {
    document.querySelectorAll('.lp-tab').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.lp-content').forEach(function(c) { c.classList.remove('active'); });
    var btns = document.querySelectorAll('.lp-tab');
    btns.forEach(function(b) { if (b.getAttribute('onclick').indexOf(view) !== -1) b.classList.add('active'); });
    document.getElementById('view_' + view).classList.add('active');
};

window.setRightTab = function(view) {
    currentRightTab = view;
    document.querySelectorAll('.rp-tab').forEach(function(t) { t.classList.remove('active'); });
    var btns = document.querySelectorAll('.rp-tab');
    btns.forEach(function(b) { if (b.getAttribute('onclick').indexOf(view) !== -1) b.classList.add('active'); });
    loadEvents(view);
};

/* ===== VENUES ===== */
function loadVenues() {
    post('get_venues', {}, function(res) {
        venuesCache = res.data || [];
        renderVenueRack();
        renderVenueManagerList();
    });
}

function renderVenueRack() {
    var rack = document.getElementById('venue_rack');
    rack.innerHTML = '';
    if (venuesCache.length === 0) {
        rack.innerHTML = '<div style="color:#666;font-size:0.8rem;padding:5px;">No venues set.</div>';
        return;
    }
    venuesCache.forEach(function(v) {
        var div = document.createElement('div');
        div.className = 'venue-pill';
        div.dataset.id = v.id;
        div.onclick = function() { selectVenue(v); };
        var img = '';
        if (v.image) {
            var vImgPath = v.image.replace(/^\/+/, '');
            img = '<img src="/' + vImgPath + '">';
        }
        div.innerHTML = img + '<span>' + esc(v.name) + '</span>';
        rack.appendChild(div);
    });
    var currentVid = document.getElementById('venue_id').value;
    if (currentVid) {
        var p = document.querySelector('.venue-pill[data-id="' + currentVid + '"]');
        if (p) p.classList.add('selected');
    }
}

function esc(s) {
    var d = document.createElement('div'); d.textContent = s; return d.innerHTML;
}

function selectVenue(v) {
    document.getElementById('venue_id').value = v.id;
    document.querySelectorAll('.venue-pill').forEach(function(p) { p.classList.remove('selected'); });
    var p = document.querySelector('.venue-pill[data-id="' + v.id + '"]');
    if (p) p.classList.add('selected');

    var timeInput = document.getElementById('evt_start_time');
    if (v.default_time && !timeInput.value && timeInput._flatpickr) {
        timeInput._flatpickr.setDate(v.default_time);
    }
    showVenueInfoCard(v);
}

function showVenueInfoCard(v) {
    var card = document.getElementById('venue_info_card');
    var addrRow = document.getElementById('venue_info_address');
    var phoneRow = document.getElementById('venue_info_phone');
    var webRow = document.getElementById('venue_info_website');

    var hasAddress = v.address && v.address.trim();
    var hasPhone = v.phone && v.phone.trim();
    var hasWebsite = v.website && v.website.trim();

    if (!hasAddress && !hasPhone && !hasWebsite) { card.style.display = 'none'; return; }

    if (hasAddress) { addrRow.querySelector('span').textContent = v.address; addrRow.style.display = 'flex'; }
    else { addrRow.style.display = 'none'; }

    if (hasPhone) { phoneRow.querySelector('span').textContent = v.phone; phoneRow.style.display = 'flex'; }
    else { phoneRow.style.display = 'none'; }

    if (hasWebsite) {
        var link = webRow.querySelector('a');
        link.href = v.website;
        link.textContent = v.website.replace(/^https?:\/\//, '').replace(/\/$/, '');
        webRow.style.display = 'flex';
    } else { webRow.style.display = 'none'; }

    card.style.display = 'block';
}

function hideVenueInfoCard() {
    document.getElementById('venue_info_card').style.display = 'none';
}

function renderVenueManagerList() {
    var list = document.getElementById('venue_list_manager');
    list.innerHTML = '';
    venuesCache.forEach(function(v) {
        var d = document.createElement('div');
        d.className = 'vm-item';
        var img = '<div class="vm-img"></div>';
        if (v.image) {
            var vImgPath = v.image.replace(/^\/+/, '');
            img = '<img src="/' + vImgPath + '" class="vm-img">';
        }
        var details = v.address || '';
        if (v.phone) details += (details ? ' | ' : '') + v.phone;
        d.innerHTML = img
            + '<div style="flex:1"><strong>' + esc(v.name) + '</strong><br><small>' + esc(details) + '</small></div>'
            + '<button class="emp-btn emp-btn-sm" onclick="triggerEditVenue(\'' + v.id + '\')">Edit</button>'
            + '<button class="emp-btn emp-btn-sm" style="color:var(--emp-danger)" onclick="deleteVenue(\'' + v.id + '\')">X</button>';
        list.appendChild(d);
    });
}

window.triggerEditVenue = function(id) {
    var v = venuesCache.find(function(x) { return x.id === id; });
    if (v) editVenue(v);
};

function editVenue(v) {
    document.getElementById('v_id').value = v.id;
    document.getElementById('v_name').value = v.name;
    document.getElementById('v_address').value = v.address;
    document.getElementById('v_phone').value = v.phone || '';
    document.getElementById('v_website').value = v.website || '';
    var vImgPath = v.image ? v.image.replace(/^\/+/, '') : '';
    document.getElementById('v_image_path').value = vImgPath;
    if (v.default_time && document.getElementById('v_default_time')._flatpickr) {
        document.getElementById('v_default_time')._flatpickr.setDate(v.default_time);
    }
    if (vImgPath) {
        document.getElementById('v_preview').src = '/' + vImgPath;
        document.getElementById('v_preview').style.display = 'block';
    } else {
        document.getElementById('v_preview').style.display = 'none';
    }
}

window.saveVenue = function() {
    var fd = new FormData();
    fd.append('action', 'save_venue');
    fd.append('v_id', document.getElementById('v_id').value);
    fd.append('v_name', document.getElementById('v_name').value);
    fd.append('v_address', document.getElementById('v_address').value);
    fd.append('v_phone', document.getElementById('v_phone').value);
    fd.append('v_website', document.getElementById('v_website').value);
    fd.append('v_default_time', document.getElementById('v_default_time').value);
    fd.append('v_image', document.getElementById('v_image_path').value);
    postData(fd, function() { loadVenues(); clearVenueForm(); alert('Venue Saved'); });
};

window.deleteVenue = function(id) {
    if (confirm('Delete Venue?')) post('delete_venue', { id: id }, loadVenues);
};

window.clearVenueForm = function() {
    document.querySelectorAll('#view_venues input').forEach(function(i) { i.value = ''; });
    document.getElementById('v_preview').style.display = 'none';
    var timePicker = document.getElementById('v_default_time');
    if (timePicker && timePicker._flatpickr) timePicker._flatpickr.clear();
};

/* ===== SETTINGS ===== */
function loadSettings() {
    post('get_settings', {}, function(res) {
        var d = res.data || {};
        defaultBandName = d.default_band || '';
        document.getElementById('sets_default_band').value = defaultBandName;
        document.getElementById('sets_cols').value = d.cols_lg || 3;
        document.getElementById('cols_val').innerText = d.cols_lg || 3;
        document.getElementById('sets_desc').checked = (d.show_desc === 'true');
        document.getElementById('sets_venue_link').checked = (d.show_venue_link === 'true');
        document.getElementById('sets_share_fb').checked = (d.share_fb === 'true');
        document.getElementById('sets_share_x').checked = (d.share_x === 'true');
        document.getElementById('sets_share_email').checked = (d.share_email === 'true');

        var evtNameField = document.getElementById('evt_name');
        var evtIdField = document.getElementById('evt_id');
        if (defaultBandName && evtNameField && !evtNameField.value && (!evtIdField || !evtIdField.value)) {
            evtNameField.value = defaultBandName;
        }
    });
}

window.saveSettings = function() {
    var fd = new FormData();
    fd.append('action', 'save_settings');
    defaultBandName = document.getElementById('sets_default_band').value.trim();
    fd.append('default_band', defaultBandName);
    fd.append('cols_lg', document.getElementById('sets_cols').value);
    fd.append('show_desc', document.getElementById('sets_desc').checked);
    fd.append('show_venue_link', document.getElementById('sets_venue_link').checked);
    fd.append('share_fb', document.getElementById('sets_share_fb').checked);
    fd.append('share_x', document.getElementById('sets_share_x').checked);
    fd.append('share_email', document.getElementById('sets_share_email').checked);
    postData(fd, function() { alert('Settings Saved'); });
};

/* ===== CREDENTIALS ===== */
function loadCreds() {
    post('get_creds', {}, function(res) {
        var d = res.data || {};
        document.getElementById('fb_app_id').value = d.fb_app_id || '';
        document.getElementById('fb_page_id').value = d.page_id || '';
        document.getElementById('fb_token').value = d.access_token || '';
        document.getElementById('insta_id').value = d.insta_id || '';
        document.getElementById('insta_token').value = d.insta_token || '';
        document.getElementById('x_api_key').value = d.x_api_key || '';
        document.getElementById('x_secret').value = d.x_secret || '';

        if (d.fb_app_id) {
            window.FB_APP_ID = d.fb_app_id;
            if (typeof FB !== 'undefined') {
                FB.init({ appId: d.fb_app_id, cookie: true, xfbml: true, version: 'v19.0' });
            }
        }
    });
}

window.saveCreds = function() {
    var fd = new FormData();
    fd.append('action', 'save_creds');
    fd.append('fb_app_id', document.getElementById('fb_app_id').value);
    fd.append('fb_page_id', document.getElementById('fb_page_id').value);
    fd.append('fb_token', document.getElementById('fb_token').value);
    fd.append('insta_id', document.getElementById('insta_id').value);
    fd.append('insta_token', document.getElementById('insta_token').value);
    fd.append('x_api_key', document.getElementById('x_api_key').value);
    fd.append('x_secret', document.getElementById('x_secret').value);

    var appId = document.getElementById('fb_app_id').value;
    if (appId) {
        window.FB_APP_ID = appId;
        if (typeof FB !== 'undefined') {
            FB.init({ appId: appId, cookie: true, xfbml: true, version: 'v19.0' });
        }
    }
    postData(fd, function() { alert('Credentials Saved'); });
};

/* ===== EVENTS ===== */
function loadEvents(view) {
    var list = document.getElementById('events_list');
    list.innerHTML = '<p style="text-align:center;padding:20px;color:#666;">Loading...</p>';

    post('fetch_data', { view: view }, function(res) {
        list.innerHTML = '';
        eventsCache = res.data || [];

        if (!eventsCache.length) {
            list.innerHTML = '<p style="text-align:center;padding:20px;color:var(--emp-fg-dim);">No items found.</p>';
            return;
        }

        eventsCache.forEach(function(e) {
            var thumb = '<div style="width:100%;height:100%;background:#222;display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-calendar"></i></div>';
            if (e.image) {
                var imgPath = e.image.replace(/^\/+/, '');
                thumb = '<img src="/' + imgPath + '" onerror="this.style.display=\'none\'">';
            }

            var vIcon = '';
            if (e.venue_data && e.venue_data.image) {
                var vImgPath = e.venue_data.image.replace(/^\/+/, '');
                vIcon = '<img src="/' + vImgPath + '" class="venue-icon-small" title="' + esc(e.venue_data.name) + '">';
            }

            var dateStr = 'TBA';
            if (e.start_date) {
                var d = new Date(e.start_date + 'T' + (e.start_time || '00:00'));
                dateStr = d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }

            var statusClass = 'tag-draft';
            if (e.status === 'published') statusClass = 'tag-pub';
            if (e.status === 'archived') statusClass = 'tag-arch';
            if (e.status === 'trashed') statusClass = 'tag-trash';

            var shortcode = '[[event:' + e.id + ']]';

            var pushes = '<div class="social-push-bar">'
                + '<button class="btn-push push-fb" onclick="shareFacebook(\'' + e.id + '\', event)" title="Share to Facebook"><i class="fa-brands fa-facebook-f"></i> Share</button>'
                + '</div>';

            var statusButtons = '';
            if (e.status === 'draft') {
                statusButtons = '<button class="btn-status btn-to-pub" onclick="setEventStatus(\'' + e.id + '\',\'published\',event)" title="Publish"><i class="fa-solid fa-check"></i></button>'
                    + '<button class="btn-status btn-to-arch" onclick="setEventStatus(\'' + e.id + '\',\'archived\',event)" title="Archive"><i class="fa-solid fa-box-archive"></i></button>';
            } else if (e.status === 'published') {
                statusButtons = '<button class="btn-status btn-to-draft" onclick="setEventStatus(\'' + e.id + '\',\'draft\',event)" title="Back to Draft"><i class="fa-solid fa-pen"></i></button>'
                    + '<button class="btn-status btn-to-arch" onclick="setEventStatus(\'' + e.id + '\',\'archived\',event)" title="Archive"><i class="fa-solid fa-box-archive"></i></button>';
            } else if (e.status === 'archived') {
                statusButtons = '<button class="btn-status btn-to-pub" onclick="setEventStatus(\'' + e.id + '\',\'published\',event)" title="Re-publish"><i class="fa-solid fa-check"></i></button>'
                    + '<button class="btn-status btn-to-draft" onclick="setEventStatus(\'' + e.id + '\',\'draft\',event)" title="Back to Draft"><i class="fa-solid fa-pen"></i></button>';
            } else if (e.status === 'trashed') {
                statusButtons = '<button class="btn-status btn-to-pub" onclick="setEventStatus(\'' + e.id + '\',\'published\',event)" title="Restore"><i class="fa-solid fa-check"></i></button>'
                    + '<button class="btn-status btn-to-draft" onclick="setEventStatus(\'' + e.id + '\',\'draft\',event)" title="Restore Draft"><i class="fa-solid fa-pen"></i></button>';
            }

            var item = document.createElement('div');
            item.className = 'list-item';
            item.onclick = function(ev) {
                if (!ev.target.closest('button') && !ev.target.closest('.dup-btn') && !ev.target.closest('.shortcode-tag') && !ev.target.closest('.btn-status')) {
                    triggerEditEvent(e.id);
                }
            };

            item.innerHTML = '<div class="list-thumb">' + thumb + '</div>'
                + '<div style="flex:1;">'
                + '<h4 style="margin:0;color:#fff;font-size:0.9rem;">' + vIcon + ' ' + esc(e.title) + '</h4>'
                + '<span style="font-size:0.75rem;color:var(--emp-fg-dim);">' + dateStr + '</span><br>'
                + '<span class="status-tag ' + statusClass + '">' + esc(e.status) + '</span>'
                + '<span class="shortcode-tag" onclick="copyShortcode(\'' + shortcode + '\', event)" title="Click to copy">' + shortcode + '</span>'
                + pushes
                + '</div>'
                + '<div class="item-actions">'
                + statusButtons
                + '<i class="fa-solid fa-copy dup-btn" title="Duplicate"></i>'
                + '<button class="btn-delete" onclick="deleteEvent(\'' + e.id + '\', event)" title="Delete"><i class="fa-solid fa-trash"></i></button>'
                + '</div>';

            item.querySelector('.dup-btn').onclick = function(ev) { ev.stopPropagation(); duplicateEvent(e.id); };
            list.appendChild(item);
        });
    });
}

window.copyShortcode = function(code, e) {
    if (e) e.stopPropagation();
    navigator.clipboard.writeText(code).then(function() {
        var tag = e.target;
        var orig = tag.innerText;
        tag.innerText = 'Copied!';
        tag.style.background = 'var(--emp-accent)';
        tag.style.color = '#000';
        setTimeout(function() { tag.innerText = orig; tag.style.background = ''; tag.style.color = ''; }, 1000);
    }).catch(function(err) { alert('Copy failed: ' + err); });
};

function triggerEditEvent(id) {
    var evt = eventsCache.find(function(e) { return e.id === id; });
    if (evt) editEvent(evt);
    else alert('Event not found in cache. Try refreshing.');
}

window.saveEvent = function(status) {
    var msg = document.getElementById('status_msg');
    msg.innerText = 'Saving...';

    var fd = new FormData();
    fd.append('action', 'save_event');
    fd.append('status', status);

    var fieldMap = {
        'evt_id': 'id', 'evt_name': 'event_name', 'evt_title': 'title',
        'venue_id': 'venue_id', 'evt_fb': 'fb_link', 'evt_map': 'map_link',
        'evt_cta_url': 'cta_url', 'evt_content': 'content_html',
        'evt_yt': 'yt_link', 'evt_yt_thumb': 'yt_thumb',
        'evt_remove_image': 'remove_image', 'imported_image_url': 'imported_image_url',
        'final_image_path': 'final_image_path'
    };

    var getPickerValue = function(id) {
        var el = document.getElementById(id);
        if (el && el._flatpickr && el._flatpickr.selectedDates.length > 0) return el.value;
        return el ? el.value : '';
    };

    fd.append('start_date', getPickerValue('evt_start_date'));
    fd.append('start_time', getPickerValue('evt_start_time'));
    fd.append('end_date', getPickerValue('evt_end_date'));
    fd.append('end_time', getPickerValue('evt_end_time'));

    Object.keys(fieldMap).forEach(function(elId) {
        var el = document.getElementById(elId);
        fd.append(fieldMap[elId], el ? el.value : '');
    });

    postData(fd, function(res) {
        msg.innerText = 'Saved as ' + status.toUpperCase();
        loadEvents(currentRightTab);
        clearEventForm();
        setTimeout(function() { msg.innerText = ''; }, 3000);
    });
};

function editEvent(e) {
    setLeftTab('editor');
    document.getElementById('evt_id').value = e.id;
    document.getElementById('evt_name').value = e.event_name || defaultBandName || '';
    document.getElementById('evt_title').value = e.title || '';

    if (e.venue_id && venuesCache.find(function(v) { return v.id === e.venue_id; })) {
        selectVenue(venuesCache.find(function(v) { return v.id === e.venue_id; }));
    } else {
        document.querySelectorAll('.venue-pill').forEach(function(p) { p.classList.remove('selected'); });
        document.getElementById('venue_id').value = '';
        hideVenueInfoCard();
    }

    var setPicker = function(id, val) {
        var el = document.getElementById(id);
        if (el && el._flatpickr) {
            if (val && val.trim()) el._flatpickr.setDate(val, true);
            else el._flatpickr.clear();
        } else if (el) { el.value = val || ''; }
    };

    setTimeout(function() {
        setPicker('evt_start_date', e.start_date);
        setPicker('evt_start_time', e.start_time);
        setPicker('evt_end_date', e.end_date);
        setPicker('evt_end_time', e.end_time);
    }, 50);

    document.getElementById('evt_fb').value = e.fb_link || '';
    document.getElementById('evt_map').value = e.map_link || '';
    document.getElementById('evt_cta_url').value = e.cta_url || '';
    document.getElementById('evt_content').value = e.content_html || '';

    if (e.image) {
        var imgPath = e.image.replace(/^\/+/, '');
        document.getElementById('img_preview').src = '/' + imgPath;
        document.getElementById('final_image_path').value = imgPath;
        document.getElementById('preview_container').style.display = 'flex';
        document.getElementById('drop_zone').style.display = 'none';
        document.getElementById('evt_remove_image').value = 'false';
    } else {
        markImageRemove();
    }

    document.getElementById('evt_yt').value = e.yt_link || '';
    document.getElementById('evt_yt_thumb').value = e.yt_thumb || '';
    document.getElementById('yt_preview_div').style.display = e.yt_link ? 'block' : 'none';
}

window.deleteEvent = function(id, e) {
    if (e) e.stopPropagation();
    if (currentRightTab === 'trash') {
        if (confirm('Permanently delete this event? Cannot be undone.')) {
            post('delete_event', { id: id }, function() { loadEvents(currentRightTab); clearEventForm(); });
        }
    } else {
        if (confirm('Move to Trash?')) {
            post('set_status', { id: id, new_status: 'trashed' }, function() { loadEvents(currentRightTab); clearEventForm(); });
        }
    }
};

function duplicateEvent(id) {
    if (confirm('Duplicate as Draft?')) {
        post('duplicate_event', { id: id }, function() { setRightTab('drafts'); alert('Duplicated!'); });
    }
}

window.setEventStatus = function(id, newStatus, e) {
    if (e) e.stopPropagation();
    post('set_status', { id: id, new_status: newStatus }, function() { loadEvents(currentRightTab); });
};

/* ===== UPLOAD ===== */
window.uploadFile = function(file, type) {
    if (!file) return;

    // Apply the server response to the right form fields once the (single) file
    // lands. Shared between the LumUpload path and the legacy fallback.
    function applyResult(res) {
        if (res && res.ok) {
            if (type === 'poster') {
                document.getElementById('img_preview').src = res.url;
                document.getElementById('final_image_path').value = res.path;
                document.getElementById('preview_container').style.display = 'flex';
                document.getElementById('drop_zone').style.display = 'none';
                document.getElementById('evt_remove_image').value = 'false';
            } else {
                document.getElementById('v_preview').src = res.url;
                document.getElementById('v_preview').style.display = 'block';
                document.getElementById('v_image_path').value = res.url;
            }
        } else { alert('Upload Error: ' + ((res && res.error) || '')); }
    }

    // Shared per-file progress meter (LumUpload), anchored at the poster drop
    // zone. Falls back to the legacy inline bar if the component isn't loaded.
    var LU = (window.LumUpload && window.LumUpload.__real) ? window.LumUpload : null;
    if (LU && LU.uploadEach) {
        LU.uploadEach({
            url: API, files: [file], field: 'file',
            data: { action: 'upload_file', upload_type: type },
            anchor: type === 'poster' ? '#drop_zone' : null,
            okWhen: function(res) { return res && res.ok !== false; },   // returns {ok:bool}
            onAllDone: function(results) { applyResult(results[0] && results[0].data); }
        });
        return;
    }

    // Legacy fallback (no progress component present).
    if (type === 'poster') document.getElementById('progress_wrap').style.display = 'block';

    var fd = new FormData();
    fd.append('action', 'upload_file');
    fd.append('file', file);
    fd.append('upload_type', type);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', API, true);

    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable && type === 'poster') {
            document.getElementById('progress_fill').style.width = ((e.loaded / e.total) * 100) + '%';
        }
    };

    xhr.onload = function() {
        applyResult(JSON.parse(xhr.responseText));
        if (type === 'poster') setTimeout(function() { document.getElementById('progress_wrap').style.display = 'none'; }, 500);
    };
    xhr.send(fd);
};

window.clearEventForm = function() {
    document.querySelectorAll('#view_editor input[type="text"], #view_editor input[type="url"], #view_editor input[type="hidden"], #view_editor textarea').forEach(function(i) { i.value = ''; });
    if (defaultBandName) document.getElementById('evt_name').value = defaultBandName;
    markImageRemove();
    document.querySelectorAll('.flatpickr-date, .flatpickr-time').forEach(function(f) { if (f._flatpickr) f._flatpickr.clear(); });
    document.querySelectorAll('.venue-pill').forEach(function(p) { p.classList.remove('selected'); });
    document.getElementById('yt_preview_div').style.display = 'none';
    hideVenueInfoCard();
};

window.markImageRemove = function() {
    document.getElementById('final_image_path').value = '';
    document.getElementById('imported_image_url').value = '';
    document.getElementById('evt_remove_image').value = 'true';
    document.getElementById('preview_container').style.display = 'none';
    document.getElementById('drop_zone').style.display = 'flex';
    document.getElementById('img_preview').src = '';
};

window.importYt = function() {
    var url = document.getElementById('evt_yt').value;
    if (!url) return;
    post('import_yt', { url: url }, function(res) {
        document.getElementById('evt_yt_thumb').value = res.data.thumb;
        document.getElementById('yt_preview_div').style.display = 'block';
        document.getElementById('yt_preview_div').innerText = 'Video Found: ' + res.data.title;
    });
};

/* ===== SEO TITLE ===== */
window.generateSeoTitle = function(manual) {
    var titleInput = document.getElementById('evt_title');
    var eventId = document.getElementById('evt_id') ? document.getElementById('evt_id').value : '';

    if (eventId && !manual) return;
    if (titleInput && titleInput.value && titleInput.value.trim() && !manual) return;

    var eventName = document.getElementById('evt_name') ? document.getElementById('evt_name').value.trim() : '';
    var startDate = document.getElementById('evt_start_date') ? document.getElementById('evt_start_date').value : '';
    var venueId = document.getElementById('venue_id') ? document.getElementById('venue_id').value : '';

    if (!eventName) { if (manual) alert('Please enter an Event/Band Name first'); return; }

    var titleParts = [eventName.toUpperCase(), 'LIVE'];

    if (startDate) {
        var date = new Date(startDate + 'T12:00:00');
        if (!isNaN(date)) {
            var days = ['SUN','MON','TUE','WED','THU','FRI','SAT'];
            var months = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
            titleParts.push(days[date.getDay()] + ' ' + months[date.getMonth()] + ' ' + date.getDate() + ', ' + date.getFullYear());
        }
    }

    if (venueId) {
        var venue = venuesCache.find(function(v) { return v.id === venueId; });
        if (venue) {
            titleParts.push(venue.name.toUpperCase());
            var cityZip = extractCityZip(venue.address);
            if (cityZip) titleParts.push(cityZip);
        }
    }

    titleInput.value = titleParts.join(' - ');
    if (manual) {
        titleInput.style.borderColor = 'var(--emp-accent)';
        setTimeout(function() { titleInput.style.borderColor = ''; }, 1000);
    }
};

function extractCityZip(address) {
    if (!address) return '';
    var patterns = [
        /([A-Za-z\s]+),?\s*([A-Z]{2})\s*(\d{5}(?:-\d{4})?)\s*$/i,
        /([A-Za-z\s]+),?\s*(Alabama|Alaska|Arizona|Arkansas|California|Colorado|Connecticut|Delaware|Florida|Georgia|Hawaii|Idaho|Illinois|Indiana|Iowa|Kansas|Kentucky|Louisiana|Maine|Maryland|Massachusetts|Michigan|Minnesota|Mississippi|Missouri|Montana|Nebraska|Nevada|New Hampshire|New Jersey|New Mexico|New York|North Carolina|North Dakota|Ohio|Oklahoma|Oregon|Pennsylvania|Rhode Island|South Carolina|South Dakota|Tennessee|Texas|Utah|Vermont|Virginia|Washington|West Virginia|Wisconsin|Wyoming)\s*(\d{5}(?:-\d{4})?)\s*$/i
    ];
    for (var i = 0; i < patterns.length; i++) {
        var match = address.match(patterns[i]);
        if (match) return match[1].trim().toUpperCase() + ' ' + match[3];
    }
    var zipMatch = address.match(/(\d{5}(?:-\d{4})?)\s*$/);
    return zipMatch ? zipMatch[1] : '';
}

/* ===== IMAGE OPTIMIZATION ===== */
window.optimizeCurrentImage = function() {
    var path = document.getElementById('final_image_path').value;
    if (!path) { alert('No image to optimize. Upload an image first.'); return; }

    var btn = document.querySelector('.btn-optimize');
    var origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Optimizing...';
    var cropSquare = document.getElementById('crop_square') ? document.getElementById('crop_square').checked : false;

    var fd = new FormData();
    fd.append('action', 'optimize_image');
    fd.append('path', path);
    fd.append('crop_square', cropSquare ? '1' : '0');

    fetch(API, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            if (res.ok) {
                document.getElementById('img_preview').src = res.url + '?t=' + Date.now();
                document.getElementById('final_image_path').value = res.path;
                alert('Image optimized!\n' + res.old_size + ' -> ' + res.new_size + '\nSaved: ' + res.saved);
            } else { alert('Error: ' + (res.error || 'Optimization failed')); }
        })
        .catch(function(e) { btn.disabled = false; btn.innerHTML = origHtml; console.error(e); alert('Error optimizing image'); });
};

window.bulkOptimize = function() {
    if (!confirm('Optimize all event images?\n\nResize oversized images to max 1200px and compress for social sharing.')) return;

    var btn = document.getElementById('btn_bulk_optimize');
    var resultDiv = document.getElementById('bulk_optimize_result');
    var origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Optimizing...';
    resultDiv.innerHTML = 'Processing images, please wait...';

    var fd = new FormData();
    fd.append('action', 'bulk_optimize');

    fetch(API, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            if (res.ok) {
                if (res.optimized > 0) {
                    resultDiv.innerHTML = 'Done! Optimized ' + res.optimized + ' image(s), saved ' + res.saved;
                    loadEvents(currentRightTab);
                } else { resultDiv.innerHTML = 'All images are already optimized.'; }
            } else { resultDiv.innerHTML = 'Error: ' + (res.error || 'Optimization failed'); }
        })
        .catch(function(e) { btn.disabled = false; btn.innerHTML = origHtml; resultDiv.innerHTML = 'Error: Request failed'; console.error(e); });
};

/* ===== FACEBOOK SHARE MODAL ===== */
window.shareFacebook = function(id, e) {
    if (e) e.stopPropagation();
    var evt = eventsCache.find(function(ev) { return ev.id === id; });
    if (!evt) { alert('Event not found'); return; }
    window._currentShareEventId = id;
    window._currentShareEvent = evt;
    showFacebookShareModal(evt);
};

function showFacebookShareModal(evt) {
    var existing = document.getElementById('fb-share-modal');
    if (existing) existing.remove();

    var hasAppId = !!window.FB_APP_ID;
    var hasPageToken = document.getElementById('fb_token') ? document.getElementById('fb_token').value : '';
    var hasPageId = document.getElementById('fb_page_id') ? document.getElementById('fb_page_id').value : '';
    var canPostToPage = hasPageToken && hasPageId;

    var evtImagePath = evt.image ? '/' + evt.image.replace(/^\/+/, '') : '';
    var evtImageHtml = evtImagePath
        ? '<img src="' + esc(evtImagePath) + '" alt="">'
        : '<div class="fb-share-no-img"><i class="fa-solid fa-calendar"></i></div>';

    var modal = document.createElement('div');
    modal.id = 'fb-share-modal';
    modal.className = 'fb-share-modal-overlay';
    modal.innerHTML = '<div class="fb-share-modal">'
        + '<div class="fb-share-modal-header">'
        + '<h3><i class="fa-brands fa-facebook"></i> Share to Facebook</h3>'
        + '<button onclick="closeFacebookShareModal()" class="fb-share-close">&times;</button>'
        + '</div>'
        + '<div class="fb-share-modal-body">'
        + '<div class="fb-share-event-preview">' + evtImageHtml + '<div><strong>' + esc(evt.title || 'Untitled') + '</strong><br><small>' + formatEventDate(evt) + '</small></div></div>'
        + '<div class="fb-share-options">'
        + '<button onclick="fbShareAdvanced()" class="fb-share-option fb-share-option-primary" ' + (!hasAppId ? 'disabled title="Requires FB App ID"' : '') + '>'
        + '<i class="fa-solid fa-users"></i><div><strong>Share Dialog</strong><span>Post to Timeline, Groups, or Pages</span></div>'
        + (!hasAppId ? '<i class="fa-solid fa-lock" style="margin-left:auto;color:var(--emp-warning);"></i>' : '<i class="fa-solid fa-chevron-right" style="margin-left:auto;opacity:0.5;"></i>')
        + '</button>'
        + '<button onclick="fbPostToPage()" class="fb-share-option" ' + (!canPostToPage ? 'disabled title="Requires Page ID and Token"' : '') + '>'
        + '<i class="fa-solid fa-building"></i><div><strong>Post to Page (Direct)</strong><span>Publish immediately using saved credentials</span></div>'
        + (!canPostToPage ? '<i class="fa-solid fa-lock" style="margin-left:auto;color:var(--emp-warning);"></i>' : '<i class="fa-solid fa-chevron-right" style="margin-left:auto;opacity:0.5;"></i>')
        + '</button>'
        + '<button onclick="fbShareBasic()" class="fb-share-option">'
        + '<i class="fa-solid fa-share"></i><div><strong>Basic Share (Timeline)</strong><span>Simple share to your personal feed</span></div>'
        + '<i class="fa-solid fa-chevron-right" style="margin-left:auto;opacity:0.5;"></i></button>'
        + '<button onclick="fbOpenComposer()" class="fb-share-option">'
        + '<i class="fa-solid fa-pen-to-square"></i><div><strong>Open Facebook Composer</strong><span>Manually paste anywhere on Facebook</span></div>'
        + '<i class="fa-solid fa-external-link" style="margin-left:auto;opacity:0.5;"></i></button>'
        + '</div>'
        + (!hasAppId ? '<div class="fb-share-notice"><i class="fa-solid fa-info-circle"></i><span>Add your <strong>FB App ID</strong> in the <a href="#" onclick="closeFacebookShareModal();setLeftTab(\'creds\');return false;">Accounts tab</a> to unlock advanced sharing.</span></div>' : '')
        + '</div></div>';

    document.body.appendChild(modal);
    modal.addEventListener('click', function(ev) { if (ev.target === modal) closeFacebookShareModal(); });
    var escHandler = function(ev) { if (ev.key === 'Escape') { closeFacebookShareModal(); document.removeEventListener('keydown', escHandler); } };
    document.addEventListener('keydown', escHandler);
}

window.closeFacebookShareModal = function() {
    var modal = document.getElementById('fb-share-modal');
    if (modal) modal.remove();
};

function formatEventDate(evt) {
    if (!evt.start_date) return 'Date TBA';
    var d = new Date(evt.start_date + 'T' + (evt.start_time || '00:00'));
    return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

window.fbShareAdvanced = function() {
    var evt = window._currentShareEvent;
    if (!evt) return;
    var siteUrl = window.location.origin;
    var eventUrl = siteUrl + '/event.php?id=' + encodeURIComponent(evt.id) + '&_cb=' + Date.now();
    closeFacebookShareModal();
    if (typeof FB !== 'undefined' && window.FB_APP_ID) {
        FB.ui({ method: 'share', href: eventUrl, quote: evt.title || 'Check out this event!' }, function(response) {
            if (response && response.error_message) alert('Facebook Share Error: ' + response.error_message);
        });
    } else {
        var dialogUrl = 'https://www.facebook.com/dialog/share?app_id=' + encodeURIComponent(window.FB_APP_ID) + '&href=' + encodeURIComponent(eventUrl) + '&redirect_uri=' + encodeURIComponent(window.location.href);
        window.open(dialogUrl, 'fb-share-dialog', 'width=626,height=436,scrollbars=yes');
    }
};

window.fbPostToPage = function() {
    var evt = window._currentShareEvent;
    if (!evt) return;
    if (!confirm('Post "' + evt.title + '" directly to your Facebook Page?')) return;
    closeFacebookShareModal();
    post('push_social', { platform: 'FB', id: evt.id }, function(res) {
        alert('Posted to Facebook Page!\n\n' + (res.message || 'Success'));
    });
};

window.fbShareBasic = function() {
    var evt = window._currentShareEvent;
    if (!evt) return;
    closeFacebookShareModal();
    var siteUrl = window.location.origin;
    var eventUrl = siteUrl + '/event.php?id=' + encodeURIComponent(evt.id) + '&_cb=' + Date.now();
    var fbShareUrl = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(eventUrl);
    window.open(fbShareUrl, 'fb-share', 'width=600,height=400,scrollbars=yes');
};

window.fbOpenComposer = function() {
    var evt = window._currentShareEvent;
    if (!evt) return;
    closeFacebookShareModal();
    var siteUrl = window.location.origin;
    var eventUrl = siteUrl + '/event.php?id=' + encodeURIComponent(evt.id);
    var dateStr = evt.start_date ? formatEventDate(evt) : '';
    var venue = evt.venue_data ? evt.venue_data.name : '';
    var shareText = '\uD83D\uDCC5 ' + (evt.title || 'Check out this event!') + '\n';
    if (dateStr) shareText += '\uD83D\uDD52 ' + dateStr + '\n';
    if (venue) shareText += '\uD83D\uDCCD ' + venue + '\n';
    shareText += '\n\uD83D\uDD17 ' + eventUrl;
    navigator.clipboard.writeText(shareText).then(function() {
        alert('Event details copied to clipboard!\n\nPaste them anywhere on Facebook.');
        window.open('https://www.facebook.com/', '_blank');
    }).catch(function() {
        prompt('Copy this text to share on Facebook:', shareText);
        window.open('https://www.facebook.com/', '_blank');
    });
};

})();
