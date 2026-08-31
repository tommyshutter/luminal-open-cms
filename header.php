<?php
/**
 * @file SITE-ROOT/header.php
 * @version 2025.08.05
 * @description The definitive modular header file.
 */

// Load core utilities (like loadJsonData)
require_once __DIR__ . '/utils.php';

// Load and instantiate the site settings object. This MUST be first.
require_once __DIR__ . '/header/header_core.php';

// Load the HTML document structure which uses the $settings object.
require_once __DIR__ . '/header/document_header_body.php';

?>