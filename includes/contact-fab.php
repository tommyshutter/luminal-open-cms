<?php
/**
 * Contact FAB — Floating ? button + modal contact form
 * Include in footer.php on any site. Posts to AudienceBuilder ab-submit.php.
 * Automatically creates contact.json form if missing.
 *
 * @package LuminalCMS
 */
$_fabSiteRoot = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__);
$_fabFormDir = $_fabSiteRoot . '/admin/data/AudienceBuilder/forms';
$_fabFormFile = $_fabFormDir . '/contact.json';

// Auto-create contact form if missing
if (!is_file($_fabFormFile)) {
    if (!is_dir($_fabFormDir)) @mkdir($_fabFormDir, 0775, true);
    $domain = $_SERVER['HTTP_HOST'] ?? 'unknown';
    $_fabForm = [
        'id' => 'f-' . date('Ymd') . '-' . substr(md5($domain), 0, 5),
        'slug' => 'contact',
        'title' => 'Contact Us',
        'fields' => [
            ['id' => 'fld-1', 'type' => 'text', 'name' => 'name', 'label' => 'Name', 'required' => true, 'width' => 'full'],
            ['id' => 'fld-2', 'type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true, 'width' => 'full'],
            ['id' => 'fld-4', 'type' => 'tel', 'name' => 'phone', 'label' => 'Phone', 'required' => false, 'width' => 'full'],
            ['id' => 'fld-3', 'type' => 'textarea', 'name' => 'message', 'label' => 'Message', 'required' => true, 'width' => 'full'],
        ],
        'submit_text' => 'Send Message',
        'success_message' => 'Thanks! Your message has been sent.',
        'created_at' => date('c'),
    ];
    @file_put_contents($_fabFormFile, json_encode($_fabForm, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($_fabFormFile, 0664);
}
?>
<!-- Contact FAB -->
<button class="contact-fab" id="contactFab" title="Contact Us" onclick="document.getElementById('contactOverlay').style.display='flex'">?</button>
<div class="contact-overlay" id="contactOverlay" style="display:none" onclick="if(event.target===this)this.style.display='none'">
    <div class="contact-modal">
        <div class="contact-modal-header">
            <span>Contact Us</span>
            <button onclick="document.getElementById('contactOverlay').style.display='none'" style="background:none;border:none;color:#888;font-size:1.4rem;cursor:pointer;padding:2px 6px">&times;</button>
        </div>
        <form id="contactFabForm" onsubmit="return contactFabSubmit(event)">
            <div class="contact-field">
                <label>Name</label>
                <input type="text" name="name" id="cfName" required placeholder="Your name">
            </div>
            <div class="contact-field">
                <label>Email</label>
                <input type="email" name="email" id="cfEmail" required placeholder="your@email.com">
            </div>
            <div class="contact-field">
                <label>Phone</label>
                <input type="text" name="phone" id="cfPhone" inputmode="numeric" placeholder="555-123-4567">
            </div>
            <div class="contact-field">
                <label>Message</label>
                <textarea name="message" id="cfMessage" rows="4" required placeholder="How can we help?"></textarea>
            </div>
            <div id="cfStatus" style="display:none;font-size:0.82rem;padding:8px 0"></div>
            <button type="submit" class="contact-submit">Send Message</button>
        </form>
    </div>
</div>

<style>
.contact-fab {
    position:fixed;bottom:24px;right:24px;z-index:9000;
    width:48px;height:48px;border-radius:50%;
    background:#92704a;color:#fff;border:none;
    font-size:1.4rem;font-weight:900;cursor:pointer;
    box-shadow:0 4px 16px rgba(0,0,0,0.4);
    transition:all 0.15s;display:flex;align-items:center;justify-content:center;
    font-family:Georgia,serif;
}
.contact-fab:hover{background:#a8845a;transform:scale(1.1)}
.contact-overlay{position:fixed;inset:0;z-index:9500;background:rgba(0,0,0,0.6);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center}
.contact-modal{width:380px;max-width:90vw;background:#13151a;border:1px solid rgba(255,255,255,0.1);border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,0.6);overflow:hidden;animation:cfSlideUp 0.25s ease-out}
@keyframes cfSlideUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
.contact-modal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid rgba(255,255,255,0.06);font-weight:700;font-size:0.95rem;color:#92704a}
.contact-field{padding:0 20px;margin-top:12px}
.contact-field label{display:block;font-size:0.68rem;font-weight:700;color:#666;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:3px}
.contact-field input,.contact-field textarea{width:100%;padding:10px 12px;border:1px solid rgba(255,255,255,0.1);border-radius:8px;font-size:.85rem;font-family:inherit;color:#e0e0e0;background:rgba(255,255,255,0.04);transition:border-color 0.15s}
.contact-field input:focus,.contact-field textarea:focus{outline:none;border-color:#92704a}
.contact-field textarea{resize:vertical}
.contact-submit{display:block;width:calc(100% - 40px);margin:16px 20px 20px;padding:12px;background:#92704a;color:#fff;border:none;border-radius:8px;font-size:.88rem;font-weight:800;cursor:pointer;font-family:inherit;transition:background 0.15s}
.contact-submit:hover{background:#a8845a}
#cfStatus{padding:4px 20px}
</style>

<script>
function contactFabSubmit(e){
    e.preventDefault();
    var status=document.getElementById('cfStatus');
    status.style.display='block';status.style.color='#888';status.textContent='Sending...';
    var data={
        action:'submit_lead',
        form_slug:'contact',
        name:document.getElementById('cfName').value,
        email:document.getElementById('cfEmail').value,
        phone:document.getElementById('cfPhone').value,
        message:document.getElementById('cfMessage').value,
        source_url:location.pathname
    };
    fetch('/panels/ab-submit.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(data)
    }).then(function(r){return r.json()}).then(function(d){
        if(d.ok){
            status.style.color='#16a34a';status.textContent='Thanks! Message sent.';
            document.getElementById('contactFabForm').reset();
            setTimeout(function(){document.getElementById('contactOverlay').style.display='none';status.style.display='none'},2000);
        }else{
            status.style.color='#dc2626';status.textContent=d.error||'Send failed. Try again.';
        }
    }).catch(function(){status.style.color='#dc2626';status.textContent='Network error.';});
    return false;
}
</script>
