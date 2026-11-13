<?php
/**
 * Printful API Handler - COMPLETE VERSION WITH AUTO STORE DETECTION
 * 
 * File: SITE_ROOT/admin/printful-mgr/includes/printful-api.php
 * Version: 2025.11.09.1730
 * 
 * Features:
 * - Automatic store detection and selection
 * - Multi-store support
 * - Complete product sync with store_id
 * - Order management
 * - Store info caching
 */

class PrintfulAPI {
    private $config;
    private $apiBase = 'https://api.printful.com';
    private $apiKey;
    private $storeId;
    private $productsFile;
    private $ordersFile;
    private $storesFile;
    
    public function __construct($config) {
        $this->config = $config;
        $this->apiKey = $config->get('api_key');
        $this->storeId = $config->get('store_id');
        $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : realpath(__DIR__ . '/../../../..');
        $dataDir = $siteRoot . '/admin/data/printful';
        $this->productsFile = $dataDir . '/products.json';
        $this->ordersFile = $dataDir . '/orders.json';
        $this->storesFile = $dataDir . '/stores.json';
    }
    
    /**
     * Make API request
     */
    private function makeRequest($endpoint, $method = 'GET', $data = null) {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'API key not configured'
            ];
        }
        
        $url = $this->apiBase . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ]);
        
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        if ($error) {
            return [
                'success' => false,
                'message' => 'Connection error: ' . $error
            ];
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'data' => $result,
                'code' => $httpCode
            ];
        } else {
            return [
                'success' => false,
                'message' => $result['error']['message'] ?? 'API request failed',
                'code' => $httpCode,
                'response' => $result
            ];
        }
    }
    
    /**
     * Test connection AND get stores automatically
     */
    public function testConnection() {
        $response = $this->makeRequest('/stores');
        
        if (!$response['success']) {
            return $response;
        }
        
        $stores = $response['data']['result'] ?? [];
        
        // Cache stores
        if (!empty($stores)) {
            file_put_contents($this->storesFile, json_encode($stores, JSON_PRETTY_PRINT));
        }
        
        return [
            'success' => true,
            'message' => 'Connected successfully',
            'stores' => $stores,
            'store_count' => count($stores)
        ];
    }
    
    /**
     * Get available stores
     */
    public function getStores() {
        // Try cache first
        if (file_exists($this->storesFile)) {
            $stores = json_decode(file_get_contents($this->storesFile), true);
            if ($stores) {
                return [
                    'success' => true,
                    'stores' => $stores
                ];
            }
        }
        
        // Fetch fresh
        return $this->testConnection();
    }
    
    /**
     * Sync products with automatic store_id handling
     */
    public function syncProducts() {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'API key not configured'
            ];
        }
        
        // If no store ID, try to get first store automatically
        if (empty($this->storeId)) {
            $storesResult = $this->getStores();
            if ($storesResult['success'] && !empty($storesResult['stores'])) {
                $this->storeId = $storesResult['stores'][0]['id'];
                // Save it
                $this->config->saveConfig(['store_id' => $this->storeId]);
            } else {
                return [
                    'success' => false,
                    'message' => 'No store ID available. Please select a store first.'
                ];
            }
        }
        
        // Build endpoint with store_id
        $endpoint = '/sync/products?store_id=' . $this->storeId;
        
        $response = $this->makeRequest($endpoint);
        
        if (!$response['success']) {
            return $response;
        }
        
        $products = $response['data']['result'] ?? [];
        
        // Cache products
        file_put_contents($this->productsFile, json_encode($products, JSON_PRETTY_PRINT));
        
        // Update last sync time
        $this->config->saveConfig(['last_sync' => date('Y-m-d H:i:s')]);
        
        return [
            'success' => true,
            'message' => 'Products synced successfully',
            'product_count' => count($products),
            'products' => $products
        ];
    }
    
    /**
     * Get cached products
     */
    public function getProducts() {
        if (!file_exists($this->productsFile)) {
            return [
                'success' => false,
                'message' => 'No products cached. Please sync first.',
                'products' => []
            ];
        }
        
        $products = json_decode(file_get_contents($this->productsFile), true);
        
        return [
            'success' => true,
            'products' => $products ?? [],
            'product_count' => count($products ?? [])
        ];
    }
    
    /**
     * Get orders
     */
    public function getOrders() {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'API key not configured',
                'orders' => []
            ];
        }
        
        $endpoint = '/orders';
        if (!empty($this->storeId)) {
            $endpoint .= '?store_id=' . $this->storeId;
        }
        
        $response = $this->makeRequest($endpoint);
        
        if (!$response['success']) {
            return array_merge($response, ['orders' => []]);
        }
        
        $orders = $response['data']['result'] ?? [];
        
        return [
            'success' => true,
            'orders' => $orders,
            'order_count' => count($orders)
        ];
    }
    
    /**
     * Get sync status
     */
    public function getSyncStatus() {
        $lastSync = $this->config->get('last_sync', 'Never');
        $productCount = 0;
        
        if (file_exists($this->productsFile)) {
            $products = json_decode(file_get_contents($this->productsFile), true);
            $productCount = count($products ?? []);
        }
        
        return [
            'success' => true,
            'last_sync' => $lastSync,
            'product_count' => $productCount,
            'store_id' => $this->storeId
        ];
    }
}
?>
