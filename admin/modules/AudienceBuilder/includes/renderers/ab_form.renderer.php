<?php
/**
 * AudienceBuilder Form Renderer
 *
 * Renders [[ab-form:slug]] shortcodes as HTML forms with data-ab-form.
 * The existing ab-form-hook.js auto-wires the submit to ab-submit.php.
 *
 * @module  AudienceBuilder
 * @version 1.0.0
 */
declare(strict_types=1);

if (!function_exists('render_ab_form')) {
function render_ab_form(string $slug, array $attrs = []): string {
    if ($slug === '') return '<!-- ab-form: no slug -->';

    $safeSlug = preg_replace('/[^a-z0-9_-]/i', '', $slug);
    $formFile = SITE_ROOT . '/admin/data/AudienceBuilder/forms/' . $safeSlug . '.json';

    if (!is_file($formFile)) {
        return '<!-- ab-form: "' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '" not found -->';
    }

    $form = json_decode((string)file_get_contents($formFile), true);
    if (!is_array($form) || empty($form['fields'])) {
        return '<!-- ab-form: "' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '" has no fields -->';
    }

    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $formId    = $h($safeSlug);
    $title     = $form['title'] ?? '';
    $submitTxt = $form['submit_text'] ?? 'Submit';
    $successMs = $form['success_message'] ?? 'Thank you! Your submission has been received.';
    $cssClass  = trim($form['css_class'] ?? '');
    $fields    = $form['fields'] ?? [];

    // Tracking data attributes
    $pixelId = trim($form['tracking']['pixel_id'] ?? '');
    $convEvt = trim($form['tracking']['conversion_event'] ?? '');
    $trackAttrs = '';
    if ($pixelId !== '') $trackAttrs .= ' data-ab-track-pixel="' . $h($pixelId) . '"';
    if ($convEvt !== '') $trackAttrs .= ' data-ab-track-event="' . $h($convEvt) . '"';

    $formStyle    = trim($form['form_style'] ?? 'default');
    $customCss    = $form['custom_css'] ?? '';
    $displayMode  = trim($form['display_mode'] ?? 'embedded');   // embedded | lightbox
    $triggerLabel = trim($form['trigger_label'] ?? '') ?: ($title !== '' ? $title : 'Open Form');

    $wrapClass = 'ab-form-wrap' . ($cssClass !== '' ? ' ' . $h($cssClass) : '');
    $styleAttr = ($formStyle !== '' && $formStyle !== 'default') ? ' data-ab-style="' . $h($formStyle) . '"' : '';

    // Build the form body (.ab-form-wrap) into $wrap; embedded inlines it,
    // lightbox wraps it in a trigger + modal overlay (submit stays AJAX).
    $wrap = '<div class="' . $wrapClass . '"' . $styleAttr . '>' . "\n";

    if ($title !== '') {
        $wrap .= '  <h3 class="ab-form-title">' . $h($title) . '</h3>' . "\n";
    }

    $wrap .= '  <form data-ab-form data-form-id="' . $formId . '"' . $trackAttrs . ' novalidate>' . "\n";

    // Group consecutive half-width fields into rows
    $i = 0;
    $total = count($fields);
    while ($i < $total) {
        $field = $fields[$i];
        $width = $field['width'] ?? 'full';
        $type  = $field['type'] ?? 'text';

        if ($width === 'half' && $type !== 'hidden') {
            // Start a row — collect consecutive half-width fields
            $wrap .= '    <div class="ab-field-row">' . "\n";
            while ($i < $total) {
                $f = $fields[$i];
                if (($f['width'] ?? 'full') !== 'half' || ($f['type'] ?? 'text') === 'hidden') break;
                $wrap .= _ab_render_field($f, $h, 'half');
                $i++;
            }
            $wrap .= '    </div>' . "\n";
        } elseif ($type === 'hidden') {
            $wrap .= '    <input type="hidden" name="' . $h($field['name'] ?? '') . '" value="' . $h($field['default'] ?? '') . '">' . "\n";
            $i++;
        } else {
            $wrap .= _ab_render_field($field, $h, 'full');
            $i++;
        }
    }

    $wrap .= '    <div class="ab-field ab-field-full">' . "\n";
    $wrap .= '      <button type="submit" class="ab-submit">' . $h($submitTxt) . '</button>' . "\n";
    $wrap .= '    </div>' . "\n";
    $wrap .= '  </form>' . "\n";

    // Success message (hidden, revealed by ab-form-hook.js)
    $wrap .= '  <div class="ab-form-success" id="ab-form-' . $formId . '-success" style="display:none;">' . "\n";
    $wrap .= '    <i class="fa-solid fa-circle-check"></i> ' . $h($successMs) . "\n";
    $wrap .= '  </div>' . "\n";
    $wrap .= '</div>' . "\n";

    // Assemble output: optional custom CSS, then embedded inline OR lightbox.
    $out = '';
    if ($formStyle === 'custom' && $customCss !== '') {
        $out .= '<style>' . $customCss . '</style>' . "\n";
    }
    if ($displayMode === 'lightbox') {
        $lbId = 'ab-lb-' . $formId;
        $out .= '<div class="ab-lightbox-launch">' . "\n";
        $out .= '  <button type="button" class="ab-submit ab-lightbox-trigger" data-ab-lightbox-open="' . $lbId . '">' . $h($triggerLabel) . '</button>' . "\n";
        $out .= '</div>' . "\n";
        $out .= '<div class="ab-lightbox-overlay" id="' . $lbId . '" data-ab-lightbox hidden>' . "\n";
        $out .= '  <div class="ab-lightbox-box">' . "\n";
        $out .= '    <button type="button" class="ab-lightbox-close" data-ab-lightbox-close aria-label="Close">&times;</button>' . "\n";
        $out .= $wrap;
        $out .= '  </div>' . "\n";
        $out .= '</div>' . "\n";
    } else {
        $out .= $wrap;
    }

    return $out;
}
}

if (!function_exists('_ab_render_field')) {
function _ab_render_field(array $field, callable $h, string $widthClass): string {
    $type   = $field['type'] ?? 'text';
    $name   = $field['name'] ?? '';
    $label  = $field['label'] ?? '';
    $ph     = $field['placeholder'] ?? '';
    $req    = !empty($field['required']);
    $opts   = $field['options'] ?? [];
    $id     = 'ab-fld-' . $h($name);

    $reqAttr = $req ? ' required' : '';
    $reqStar = $req ? ' <span class="req">*</span>' : '';

    $cls = 'ab-field ab-field-' . $h($widthClass);
    $out = '      <div class="' . $cls . '">' . "\n";

    if ($type !== 'checkbox' && $label !== '') {
        $out .= '        <label for="' . $id . '">' . $h($label) . $reqStar . '</label>' . "\n";
    }

    switch ($type) {
        case 'textarea':
            $out .= '        <textarea id="' . $id . '" name="' . $h($name) . '" placeholder="' . $h($ph) . '"' . $reqAttr . ' rows="4"></textarea>' . "\n";
            break;

        case 'select':
            $out .= '        <select id="' . $id . '" name="' . $h($name) . '"' . $reqAttr . '>' . "\n";
            $out .= '          <option value="">' . ($ph !== '' ? $h($ph) : '— Select —') . '</option>' . "\n";
            foreach ($opts as $opt) {
                $out .= '          <option value="' . $h($opt) . '">' . $h($opt) . '</option>' . "\n";
            }
            $out .= '        </select>' . "\n";
            break;

        case 'radio':
            $out .= '        <div class="ab-radio-group">' . "\n";
            foreach ($opts as $idx => $opt) {
                $rid = $id . '-' . $idx;
                $out .= '          <label class="ab-radio-label"><input type="radio" name="' . $h($name) . '" value="' . $h($opt) . '" id="' . $rid . '"' . ($idx === 0 && $req ? ' required' : '') . '> ' . $h($opt) . '</label>' . "\n";
            }
            $out .= '        </div>' . "\n";
            break;

        case 'checkbox':
            $out .= '        <label class="ab-checkbox-label"><input type="checkbox" name="' . $h($name) . '" value="1" id="' . $id . '"' . $reqAttr . '> ' . $h($label) . $reqStar . '</label>' . "\n";
            break;

        default: // text, email, tel, url, number, date
            $validTypes = ['text','email','tel','url','number','date'];
            $inputType = in_array($type, $validTypes) ? $type : 'text';
            $out .= '        <input type="' . $inputType . '" id="' . $id . '" name="' . $h($name) . '" placeholder="' . $h($ph) . '"' . $reqAttr . '>' . "\n";
            break;
    }

    $out .= '      </div>' . "\n";
    return $out;
}
}
