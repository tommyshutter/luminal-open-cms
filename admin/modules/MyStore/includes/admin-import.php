<?php
/**
 * PayPal Shopping Cart System - Admin Import Functions
 * Handles CSV import and data migration features
 * 
 * File: /admin/paypal/includes/admin-import.php
 * Version: 2025.10.01
 */

// Prevent direct access
if (!defined('PAYPAL_SYSTEM')) {
    exit('Direct access denied');
}

/**
 * Handle CSV file upload and import
 */
function paypal_handle_csv_import($file) {
    $result = [
        'success' => false,
        'message' => '',
        'imported' => 0,
        'updated' => 0,
        'errors' => [],
        'warnings' => [],
        'products' => []
    ];
    
    // Validate file
    if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
        $result['message'] = 'No file uploaded';
        return $result;
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $result['message'] = 'File upload error: ' . $file['error'];
        return $result;
    }
    
    if ($file['size'] > ADMIN_MAX_UPLOAD_SIZE) {
        $result['message'] = 'File too large. Maximum size: ' . paypal_format_bytes(ADMIN_MAX_UPLOAD_SIZE);
        return $result;
    }
    
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($file_extension !== 'csv') {
        $result['message'] = 'Invalid file type. Only CSV files are allowed.';
        return $result;
    }
    
    // Parse CSV
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        $result['message'] = 'Could not read uploaded file';
        return $result;
    }
    
    $headers = fgetcsv($handle);
    if (!$headers) {
        $result['message'] = 'Invalid CSV file - no headers found';
        fclose($handle);
        return $result;
    }
    
    // Validate required headers (more flexible matching)
    $required_headers = ['sku', 'name', 'price'];
    $missing_headers = [];
    
    foreach ($required_headers as $required) {
        $found = false;
        foreach ($headers as $header) {
            $header_lower = strtolower(trim($header));
            if ($required === 'name' && (strpos($header_lower, 'name') !== false || strpos($header_lower, 'title') !== false)) {
                $found = true;
                break;
            } elseif ($required === 'price' && strpos($header_lower, 'price') !== false) {
                $found = true;
                break;
            } elseif ($required === 'sku' && strpos($header_lower, 'sku') !== false) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $missing_headers[] = $required;
        }
    }
    
    if (!empty($missing_headers)) {
        $result['message'] = 'Missing required headers: ' . implode(', ', $missing_headers);
        fclose($handle);
        return $result;
    }
    
    // Create header mapping
    $header_map = [];
    foreach ($headers as $index => $header) {
        $header_map[strtolower(trim($header))] = $index;
    }
    
    $imported_count = 0;
    $updated_count = 0;
    $line_number = 1;
    
    // Load existing products to check for duplicates  
    $existing_products = paypal_get_products();
    $existing_skus = array_column($existing_products, 'sku');
    
    // Process each row
    while (($row = fgetcsv($handle)) !== false) {
        $line_number++;
        
        try {
            $product = paypal_parse_csv_row($row, $header_map, $line_number);
            
            if ($product && !empty($product['sku'])) {
                // Check if product already exists
                $existing_key = array_search($product['sku'], $existing_skus);
                
                if ($existing_key !== false) {
                    // Update existing product
                    $product['mtime'] = time();
                    if (paypal_save_product_to_filesystem($product)) {
                        $updated_count++;
                        $result['products'][] = $product;
                    } else {
                        $result['errors'][] = "Line $line_number: Failed to update product {$product['sku']}";
                    }
                } else {
                    // Add new product
                    $product['created'] = time();
                    $product['mtime'] = time();
                    if (paypal_save_product_to_filesystem($product)) {
                        $imported_count++;
                        $result['products'][] = $product;
                        $existing_skus[] = $product['sku']; // Track for duplicate detection
                    } else {
                        $result['errors'][] = "Line $line_number: Failed to save product {$product['sku']}";
                    }
                }
            } elseif (empty($product['sku'])) {
                $result['errors'][] = "Line $line_number: Missing or invalid SKU";
            } else {
                $result['errors'][] = "Line $line_number: Invalid product data";
            }
        } catch (Exception $e) {
            $result['errors'][] = "Line $line_number: " . $e->getMessage();
        }
    }
    
    fclose($handle);
    
    $result['success'] = true;
    $result['imported'] = $imported_count;
    $result['updated'] = $updated_count;
    
    if ($imported_count > 0 && $updated_count > 0) {
        $result['message'] = "Import complete: $imported_count added, $updated_count updated";
    } elseif ($imported_count > 0) {
        $result['message'] = "Successfully imported $imported_count products";
    } elseif ($updated_count > 0) {
        $result['message'] = "Successfully updated $updated_count products";
    } else {
        $result['message'] = "No products were imported or updated";
    }
    
    if (!empty($result['errors'])) {
        $result['message'] .= ' with ' . count($result['errors']) . ' errors';
    }
    
    // Log the import
    paypal_log_admin_activity('csv_import', "Imported $imported_count products from {$file['name']}");
    
    return $result;
}

/**
 * Parse a CSV row into product data
 */
function paypal_parse_csv_row($row, $header_map, $line_number = 0) {
    if (empty($row)) {
        return null;
    }
    
    $product = [
        'sku' => '',
        'enabled' => true,
        'name' => '',
        'price' => '0.00',
        'desc' => '',
        'category' => '',
        'image' => '',
        'variations' => ['groups' => []],
        'mtime' => time()
    ];
    
    // Flexible column mapping - find headers by pattern
    $sku_index = paypal_find_header_index($header_map, ['sku']);
    $name_index = paypal_find_header_index($header_map, ['name', 'title', 'product name']);
    $price_index = paypal_find_header_index($header_map, ['price', 'cost']);
    $category_index = paypal_find_header_index($header_map, ['category', 'type']);
    $desc_index = paypal_find_header_index($header_map, ['desc', 'description', 'details']);
    $image_index = paypal_find_header_index($header_map, ['image', 'photo', 'picture']);
    $enabled_index = paypal_find_header_index($header_map, ['enabled', 'active', 'status']);
    
    // Required: SKU
    if ($sku_index !== false && isset($row[$sku_index])) {
        $product['sku'] = trim($row[$sku_index]);
    } else {
        throw new Exception("Missing SKU");
    }
    
    // Required: Name
    if ($name_index !== false && isset($row[$name_index])) {
        $product['name'] = trim($row[$name_index]);
    } else {
        throw new Exception("Missing product name");
    }
    
    // Required: Price
    if ($price_index !== false && isset($row[$price_index])) {
        $price_str = preg_replace('/[^0-9.]/', '', $row[$price_index]);
        $price = floatval($price_str);
        if ($price < 0) {
            throw new Exception("Invalid price format");
        }
        $product['price'] = number_format($price, 2, '.', '');
    } else {
        throw new Exception("Missing price");
    }
    
    // Optional fields
    if ($category_index !== false && isset($row[$category_index])) {
        $product['category'] = trim($row[$category_index]);
    }
    
    if ($desc_index !== false && isset($row[$desc_index])) {
        $product['desc'] = trim($row[$desc_index]);
    }
    
    if ($image_index !== false && isset($row[$image_index])) {
        $product['image'] = trim($row[$image_index]);
    }
    
    if ($enabled_index !== false && isset($row[$enabled_index])) {
        $enabled_value = strtolower(trim($row[$enabled_index]));
        $product['enabled'] = in_array($enabled_value, ['true', '1', 'yes', 'enabled']);
    }
    
    if (isset($header_map['name'])) {
        $product['name'] = trim($row[$header_map['name']]);
    }
    
    if (isset($header_map['price'])) {
        $price = trim($row[$header_map['price']]);
        // Remove currency symbols and format as decimal
        $price = preg_replace('/[^\d\.]/', '', $price);
        $product['price'] = number_format((float)$price, 2, '.', '');
    }
    
    if (isset($header_map['description']) || isset($header_map['desc'])) {
        $desc_index = $header_map['description'] ?? $header_map['desc'];
        $product['desc'] = trim($row[$desc_index]);
    }
    
    if (isset($header_map['category'])) {
        $product['category'] = trim($row[$header_map['category']]);
    }
    
    if (isset($header_map['image'])) {
        $product['image'] = trim($row[$header_map['image']]);
    }
    
    // Handle variations if present
    if (isset($header_map['variations'])) {
        $variations_json = trim($row[$header_map['variations']]);
        if (!empty($variations_json)) {
            $decoded = json_decode($variations_json, true);
            if ($decoded && is_array($decoded)) {
                $product['variations'] = $decoded;
            }
        }
    }
    
    // Validate required fields
    if (empty($product['sku']) || empty($product['name'])) {
        throw new Exception('Missing required fields: SKU and Name are required');
    }
    
    return $product;
}

/**
 * Find header index by searching for multiple possible column names
 */
function paypal_find_header_index($header_map, $search_terms) {
    foreach ($search_terms as $term) {
        if (isset($header_map[strtolower($term)])) {
            return $header_map[strtolower($term)];
        }
    }
    
    // Try partial matches
    foreach ($search_terms as $term) {
        foreach ($header_map as $header => $index) {
            if (strpos(strtolower($header), strtolower($term)) !== false) {
                return $index;
            }
        }
    }
    
    return false;
}

/**
 * Export products to CSV
 */
function paypal_export_to_csv($products = null) {
    if ($products === null) {
        $products = paypal_load_products();
    }
    
    $filename = 'paypal_products_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Write headers
    $headers = [
        'SKU',
        'Enabled',
        'Name',
        'Price',
        'Description',
        'Category',
        'Image',
        'Variations',
        'Modified'
    ];
    
    fputcsv($output, $headers);
    
    // Write product data
    foreach ($products as $product) {
        $row = [
            $product['sku'] ?? '',
            ($product['enabled'] ?? false) ? 'true' : 'false',
            $product['name'] ?? '',
            $product['price'] ?? '0.00',
            $product['desc'] ?? '',
            $product['category'] ?? '',
            $product['image'] ?? '',
            json_encode($product['variations'] ?? []),
            date('Y-m-d H:i:s', $product['mtime'] ?? time())
        ];
        
        fputcsv($output, $row);
    }
    
    fclose($output);
    
    // Log the export
    paypal_log_admin_activity('csv_export', 'Exported ' . count($products) . ' products');
    
    exit;
}

/**
 * Import from PayPal JSON format
 */
function paypal_import_from_json($json_data) {
    $result = [
        'success' => false,
        'message' => '',
        'imported' => 0,
        'errors' => []
    ];
    
    try {
        $data = is_string($json_data) ? json_decode($json_data, true) : $json_data;
        
        if (!is_array($data)) {
            $result['message'] = 'Invalid JSON data';
            return $result;
        }
        
        $imported_count = 0;
        
        foreach ($data as $product_data) {
            try {
                if (paypal_validate_product_data($product_data)) {
                    if (paypal_save_product_to_filesystem($product_data)) {
                        $imported_count++;
                    } else {
                        $result['errors'][] = "Failed to save product: " . ($product_data['sku'] ?? 'unknown');
                    }
                } else {
                    $result['errors'][] = "Invalid product data: " . ($product_data['sku'] ?? 'unknown');
                }
            } catch (Exception $e) {
                $result['errors'][] = "Error processing product: " . $e->getMessage();
            }
        }
        
        $result['success'] = true;
        $result['imported'] = $imported_count;
        $result['message'] = "Successfully imported $imported_count products";
        
        if (!empty($result['errors'])) {
            $result['message'] .= ' with ' . count($result['errors']) . ' errors';
        }
        
        // Log the import
        paypal_log_admin_activity('json_import', "Imported $imported_count products from JSON");
        
    } catch (Exception $e) {
        $result['message'] = 'Import failed: ' . $e->getMessage();
    }
    
    return $result;
}

/**
 * Backup current data
 */
function paypal_create_backup() {
    $products = paypal_load_products();
    $backup_data = [
        'version' => '1.0',
        'timestamp' => time(),
        'products' => $products
    ];
    
    $backup_file = PAYPAL_DATA_PATH . '/backup_' . date('Y-m-d_H-i-s') . '.json';
    
    if (file_put_contents($backup_file, json_encode($backup_data, JSON_PRETTY_PRINT))) {
        paypal_log_admin_activity('backup_created', "Backup created: " . basename($backup_file));
        return $backup_file;
    }
    
    return false;
}

/**
 * Restore from backup
 */
function paypal_restore_backup($backup_file) {
    if (!file_exists($backup_file)) {
        return ['success' => false, 'message' => 'Backup file not found'];
    }
    
    $backup_data = json_decode(file_get_contents($backup_file), true);
    
    if (!$backup_data || !isset($backup_data['products'])) {
        return ['success' => false, 'message' => 'Invalid backup file'];
    }
    
    // Create current backup before restore
    paypal_create_backup();
    
    $result = paypal_import_from_json($backup_data['products']);
    
    if ($result['success']) {
        paypal_log_admin_activity('backup_restored', "Restored from: " . basename($backup_file));
    }
    
    return $result;
}

/**
 * Get available backup files
 */
function paypal_get_backup_files() {
    $backup_files = [];
    $data_dir = PAYPAL_DATA_PATH;
    
    if (is_dir($data_dir)) {
        $files = scandir($data_dir);
        foreach ($files as $file) {
            if (preg_match('/^backup_.*\.json$/', $file)) {
                $file_path = $data_dir . '/' . $file;
                $backup_files[] = [
                    'filename' => $file,
                    'path' => $file_path,
                    'size' => filesize($file_path),
                    'modified' => filemtime($file_path)
                ];
            }
        }
        
        // Sort by modification time (newest first)
        usort($backup_files, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
    }
    
    return $backup_files;
}

/**
 * Sample CSV template
 */
function paypal_generate_csv_template() {
    $filename = 'paypal_import_template.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Headers
    fputcsv($output, [
        'SKU',
        'Enabled',
        'Name',
        'Price',
        'Description',
        'Category',
        'Image',
        'Variations'
    ]);
    
    // Sample data
    fputcsv($output, [
        'SAMPLE-001',
        'true',
        'Sample Product',
        '19.99',
        'This is a sample product description',
        'Electronics',
        'https://example.com/image.jpg',
        '{"groups":[]}'
    ]);
    
    fputcsv($output, [
        'SAMPLE-002',
        'false',
        'Another Product',
        '29.99',
        'Another sample product with variations',
        'Clothing',
        'https://example.com/image2.jpg',
        '{"groups":[{"name":"Size","options":["S","M","L"]},{"name":"Color","options":["Red","Blue"]}]}'
    ]);
    
    fclose($output);
    exit;
}
?>