<?php
/**
 * Printful Configuration Handler
 * 
 * File: SITE_ROOT/admin/printful-mgr/includes/printful-config.php
 * Version: 2025.11.09.1430
 * 
 * Manages Printful API configuration settings.
 * Stores and retrieves configuration from JSON file.
 */

class PrintfulConfig {
    /**
     * Luminal CMS framework-level Printful affiliate link.
     * Used by the admin banner and storefront footer CTA across ALL sites
     * regardless of per-site config — this is the platform's affiliate, not the
     * customer's. Per-site `affiliate_link` (in saved config) is for the
     * site owner's own affiliate program tracking and is independent of this.
     */
    public const LUMINAL_AFFILIATE_LINK = 'https://www.printful.com/a/12997513:b269bfd8142a38fa45e7808e13d777ac';

    private $configFile;
    private $config;
    
    /**
     * Constructor - initializes configuration file path
     */
    public function __construct() {
        $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : realpath(__DIR__ . '/../../../..');
        $this->configFile = $siteRoot . '/admin/data/printful/printful-config.json';
        $this->ensureDataDirectory();
        $this->loadConfig();
    }
    
    /**
     * Ensure data directory exists
     */
    private function ensureDataDirectory() {
        $dataDir = dirname($this->configFile);
        if (!file_exists($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        
        // Create .htaccess to protect data directory
        $htaccessFile = $dataDir . '/.htaccess';
        if (!file_exists($htaccessFile)) {
            file_put_contents($htaccessFile, "Deny from all");
        }
    }
    
    /**
     * Load configuration from file
     * 
     * @return array Configuration data
     */
    private function loadConfig() {
        if (file_exists($this->configFile)) {
            $json = file_get_contents($this->configFile);
            $this->config = json_decode($json, true);
        } else {
            $this->config = $this->getDefaultConfig();
        }
        return $this->config;
    }
    
    /**
     * Get default configuration
     * 
     * @return array Default configuration values
     */
    private function getDefaultConfig() {
        return [
            'api_key' => '',
            'store_id' => '',
            'currency' => 'USD',
            'sync_enabled' => 0,
            'last_sync' => null,
            'sync_frequency' => 'manual',
            'referral_link' => 'https://www.printful.com/give-5-get-5/6ENECD',
            'affiliate_id' => '',
            'affiliate_link' => '',
            'affiliate_notes' => ''
        ];
    }
    
    /**
     * Get current configuration
     * 
     * @return array Current configuration
     */
    public function getConfig() {
        return $this->config;
    }
    
    /**
     * Get specific config value
     * 
     * @param string $key Configuration key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed Configuration value
     */
    public function get($key, $default = null) {
        return $this->config[$key] ?? $default;
    }
    
    /**
     * Save configuration
     * 
     * @param array $newConfig New configuration data
     * @return bool Success status
     */
    public function saveConfig($newConfig) {
        $this->config = array_merge($this->config, $newConfig);
        $json = json_encode($this->config, JSON_PRETTY_PRINT);
        return file_put_contents($this->configFile, $json) !== false;
    }
    
    /**
     * Update last sync timestamp
     * 
     * @return bool Success status
     */
    public function updateLastSync() {
        $this->config['last_sync'] = date('Y-m-d H:i:s');
        return $this->saveConfig([]);
    }
    
    /**
     * Check if API is configured
     * 
     * @return bool True if API key exists
     */
    public function isConfigured() {
        return !empty($this->config['api_key']);
    }
}
?>
