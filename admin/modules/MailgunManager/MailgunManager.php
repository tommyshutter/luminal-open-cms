<?php
/**
 * Luminal CMS — Mailgun Manager Module
 *
 * Mailgun email service configuration and testing. Manage API credentials,
 * sending domains, DNS verification, and test email delivery.
 *
 * @package    LuminalCMS
 * @module     MailgunManager
 * @version    1.0.0
 * @file       /admin/modules/MailgunManager/MailgunManager.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';

guard_require_auth();

// Include admin header
require_once SITE_ROOT . '/admin/admin_header.php';
?>

<h1 class="panel_header_h1" style="color:white">Mailgun Manager</h1>

<link rel="stylesheet" href="<?= sc_asset('/admin/modules/MailgunManager/css/mailgun-styles.css') ?>">

<div class="mg-wrap">

    <section class="mg-card">
        <h3>API Credentials</h3>
        <div class="mg-form">
            <div class="mg-form-row">
                <label class="mg-label">API Key</label>
                <input type="password" id="mg-api-key" class="mg-input" placeholder="key-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" autocomplete="off">
                <button class="btn btn-sm btn-secondary" onclick="mailgunMgr.toggleKey()" id="mg-toggle-key" title="Show/hide">Show</button>
            </div>

            <div class="mg-form-row">
                <label class="mg-label">Sending Domain</label>
                <input type="text" id="mg-domain" class="mg-input" placeholder="mg.yourdomain.com">
            </div>

            <div class="mg-form-row">
                <label class="mg-label">From Name</label>
                <input type="text" id="mg-from-name" class="mg-input" placeholder="My Website">
            </div>

            <div class="mg-form-row">
                <label class="mg-label">From Email</label>
                <input type="text" id="mg-from-email" class="mg-input" placeholder="noreply@mg.yourdomain.com">
            </div>

            <div class="mg-form-row">
                <label class="mg-label">Region</label>
                <select id="mg-region" class="mg-select">
                    <option value="us">US (api.mailgun.net)</option>
                    <option value="eu">EU (api.eu.mailgun.net)</option>
                </select>
            </div>
        </div>

        <div class="mg-actions">
            <button class="btn btn-primary btn-sm" onclick="mailgunMgr.save()">Save Credentials</button>
            <button class="btn btn-success btn-sm" onclick="mailgunMgr.test()">Test Connection</button>
        </div>
        <div id="mg-status" class="mg-hint" style="margin-top:8px"></div>
    </section>

    <section class="mg-card">
        <h3>Send Test Email</h3>
        <div class="mg-form">
            <div class="mg-form-row">
                <label class="mg-label">Recipient</label>
                <input type="email" id="mg-test-to" class="mg-input" placeholder="you@example.com">
            </div>
        </div>
        <div class="mg-actions">
            <button class="btn btn-primary btn-sm" onclick="mailgunMgr.sendTest()">Send Test Email</button>
        </div>
        <div id="mg-test-status" class="mg-hint" style="margin-top:8px"></div>
    </section>

    <section class="mg-card">
        <h3>DNS Setup</h3>
        <p class="mg-hint">After configuring your sending domain in Mailgun, add these DNS records to your domain registrar. Click "Verify DNS" to check their status via the Mailgun API.</p>

        <div class="mg-actions" style="margin-bottom:10px">
            <button class="btn btn-primary btn-sm" onclick="mailgunMgr.checkDns()">Verify DNS</button>
        </div>

        <div id="mg-dns-results" class="mg-dns-results">
            <div class="mg-dns-empty">Save credentials and click "Verify DNS" to check your domain records.</div>
        </div>
    </section>

    <section class="mg-card">
        <h3>Integration Status</h3>
        <div id="mg-integrations" class="mg-integrations">
            <div class="mg-integration-row">
                <span class="mg-integration-label">Event Share Emails</span>
                <span class="mg-integration-status" id="mg-int-events">Pending</span>
            </div>
            <div class="mg-integration-row">
                <span class="mg-integration-label">Password Reset Emails</span>
                <span class="mg-integration-status" id="mg-int-password">Pending</span>
            </div>
            <div class="mg-integration-row">
                <span class="mg-integration-label">Contact Form Emails</span>
                <span class="mg-integration-status" id="mg-int-contact">Pending</span>
            </div>
        </div>
        <p class="mg-hint" style="margin-top:10px">Integrations will be wired up once credentials are configured and verified.</p>
    </section>

</div>

<script src="<?= sc_asset('/admin/modules/MailgunManager/js/mailgun-ops.js') ?>"></script>
