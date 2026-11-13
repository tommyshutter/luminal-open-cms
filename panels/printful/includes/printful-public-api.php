<?php
/**
 * Printful Public API Handler
 * 
 * File: SITE_ROOT/panels/printful/includes/printful-public-api.php
 * Version: 2025.11.09.1600
 * 
 * Handles public-facing product retrieval.
 * Reads from cached product data created by admin sync.
 */

class PrintfulPublicAPI {
    
    private $dataDir;
    private $productsFile;
    
    /**
     * Constructor - sets up data paths
     */
    public function __construct() {
        // Get site root - go up 3 levels from this file
        $siteRoot = dirname(dirname(dirname(__DIR__)));
        
        // Set data directory path
        $this->dataDir = $siteRoot . '/admin/data/printful';
        $this->productsFile = $this->dataDir . '/products.json';
    }
    
    /**
     * Get products for public display
     * 
     * @return array Result with products array
     */
    public function getProducts() {
        // Check if products file exists
        if (!file_exists($this->productsFile)) {
            return [
                'success' => false,
                'message' => 'No products available',
                'products' => []
            ];
        }
        
        // Read products from file
        $productsJson = file_get_contents($this->productsFile);
        $products = json_decode($productsJson, true);
        
        // Check for JSON errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Error reading products',
                'products' => []
            ];
        }
        
        // Filter to only active/published products
        $activeProducts = array_filter($products, function($product) {
            // You can add additional filtering logic here
            return true;
        });
        
        return [
            'success' => true,
            'products' => $activeProducts,
            'count' => count($activeProducts)
        ];
    }
    
    /**
     * Get a single product by ID
     * 
     * @param string $productId Product ID to retrieve
     * @return array Result with product data
     */
    public function getProduct($productId) {
        $productsResult = $this->getProducts();
        
        if (!$productsResult['success']) {
            return [
                'success' => false,
                'message' => 'Products not available',
                'product' => null
            ];
        }
        
        $products = $productsResult['products'];
        
        // Find product by ID
        foreach ($products as $product) {
            if ($product['id'] == $productId) {
                return [
                    'success' => true,
                    'product' => $product
                ];
            }
        }
        
        return [
            'success' => false,
            'message' => 'Product not found',
            'product' => null
        ];
    }
    
    /**
     * Search products by name
     * 
     * @param string $query Search query
     * @return array Result with matching products
     */
    public function searchProducts($query) {
        $productsResult = $this->getProducts();
        
        if (!$productsResult['success']) {
            return [
                'success' => false,
                'message' => 'Products not available',
                'products' => []
            ];
        }
        
        $products = $productsResult['products'];
        $query = strtolower(trim($query));
        
        // Filter products by search query
        $matchingProducts = array_filter($products, function($product) use ($query) {
            $name = strtolower($product['name'] ?? '');
            return strpos($name, $query) !== false;
        });
        
        return [
            'success' => true,
            'products' => array_values($matchingProducts),
            'count' => count($matchingProducts)
        ];
    }
    
    /**
     * Filter products by category
     * 
     * @param string $category Category to filter by
     * @return array Result with filtered products
     */
    public function filterByCategory($category) {
        $productsResult = $this->getProducts();
        
        if (!$productsResult['success']) {
            return [
                'success' => false,
                'message' => 'Products not available',
                'products' => []
            ];
        }
        
        $products = $productsResult['products'];
        $category = strtolower(trim($category));
        
        // Filter products by category
        $filteredProducts = array_filter($products, function($product) use ($category) {
            // This is a simplified example - adjust based on your product structure
            $productCategory = strtolower($product['category'] ?? '');
            return $productCategory === $category;
        });
        
        return [
            'success' => true,
            'products' => array_values($filteredProducts),
            'count' => count($filteredProducts)
        ];
    }
}
?>
