<?php
/**
 * PayPal Orders Management View
 * View and manage customer orders
 * 
 * File: /admin/paypal/views/paypal-orders.php
 * Version: 2025.10.01
 */

if (!defined('PAYPAL_SYSTEM')) {
    die('Direct access denied');
}

// Handle order status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_order_status') {
        $order_id = sanitize_text_field($_POST['order_id'] ?? '');
        $status = sanitize_text_field($_POST['status'] ?? '');
        $notes = sanitize_text_field($_POST['notes'] ?? '');
        
        if (paypal_update_order_status($order_id, $status, $notes)) {
            paypal_add_message('Order status updated successfully', 'success');
        } else {
            paypal_add_message('Failed to update order status', 'error');
        }
    }
}

// Get orders
$orders = paypal_get_all_orders();
$total_orders = count($orders);
$pending_orders = count(array_filter($orders, function($o) { return $o['status'] === 'processing'; }));
$completed_orders = count(array_filter($orders, function($o) { return $o['status'] === 'completed'; }));

// Calculate revenue
$total_revenue = array_sum(array_map(function($o) { return $o['totals']['total']; }, $orders));

// Status filter
$status_filter = $_GET['status'] ?? 'all';
if ($status_filter !== 'all') {
    $orders = array_filter($orders, function($o) use ($status_filter) {
        return $o['status'] === $status_filter;
    });
}

// Search filter
$search = $_GET['search'] ?? '';
if ($search) {
    $orders = array_filter($orders, function($o) use ($search) {
        return stripos($o['order_id'], $search) !== false ||
               stripos($o['customer']['email'], $search) !== false ||
               stripos($o['customer']['first_name'] . ' ' . $o['customer']['last_name'], $search) !== false;
    });
}
?>

<div class="orders-container">
    <!-- Orders Header -->
    <div class="orders-header glass-card p-6 mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between">
            <div>
                <h1 class="text-3xl font-bold mb-2">Order Management</h1>
                <p class="text-gray-400">View and manage customer orders</p>
            </div>
            
            <div class="flex gap-2 mt-4 md:mt-0">
                <button onclick="exportOrders()" class="btn btn-outline">
                    <i class="fas fa-download mr-2"></i>
                    Export CSV
                </button>
                <button onclick="refreshOrders()" class="btn btn-primary">
                    <i class="fas fa-sync mr-2"></i>
                    Refresh
                </button>
            </div>
        </div>
    </div>

    <!-- Order Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="glass-card p-4">
            <div class="text-2xl font-bold"><?= $total_orders ?></div>
            <p class="text-gray-400 text-sm">Total Orders</p>
        </div>
        <div class="glass-card p-4">
            <div class="text-2xl font-bold text-yellow-400"><?= $pending_orders ?></div>
            <p class="text-gray-400 text-sm">Pending</p>
        </div>
        <div class="glass-card p-4">
            <div class="text-2xl font-bold text-green-400"><?= $completed_orders ?></div>
            <p class="text-gray-400 text-sm">Completed</p>
        </div>
        <div class="glass-card p-4">
            <div class="text-2xl font-bold text-blue-400">$<?= number_format($total_revenue, 2) ?></div>
            <p class="text-gray-400 text-sm">Total Revenue</p>
        </div>
    </div>

    <!-- Filters and Search -->
    <div class="glass-card p-4 mb-6">
        <form method="GET" class="flex flex-col md:flex-row gap-4">
            <input type="hidden" name="view" value="orders">
            
            <div class="flex-1">
                <input type="text" name="search" placeholder="Search orders..." 
                       value="<?= htmlspecialchars($search) ?>"
                       class="w-full p-2 rounded border">
            </div>
            
            <div>
                <select name="status" class="p-2 rounded border">
                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Orders</option>
                    <option value="processing" <?= $status_filter === 'processing' ? 'selected' : '' ?>>Processing</option>
                    <option value="shipped" <?= $status_filter === 'shipped' ? 'selected' : '' ?>>Shipped</option>
                    <option value="delivered" <?= $status_filter === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                    <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search mr-2"></i>
                Filter
            </button>
        </form>
    </div>

    <!-- Orders Table -->
    <div class="glass-card overflow-hidden">
        <?php if (empty($orders)): ?>
            <div class="p-12 text-center">
                <i class="fas fa-shopping-bag text-6xl text-gray-400 mb-4"></i>
                <h3 class="text-xl font-bold mb-2">No Orders Found</h3>
                <p class="text-gray-400">
                    <?php if ($search || $status_filter !== 'all'): ?>
                        No orders match your current filters.
                    <?php else: ?>
                        No orders have been placed yet.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-700">
                        <tr>
                            <th class="p-3 text-left">Order ID</th>
                            <th class="p-3 text-left">Customer</th>
                            <th class="p-3 text-left">Date</th>
                            <th class="p-3 text-left">Items</th>
                            <th class="p-3 text-left">Total</th>
                            <th class="p-3 text-left">Status</th>
                            <th class="p-3 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr class="border-b border-gray-600 hover:bg-gray-800/50">
                                <td class="p-3">
                                    <div class="font-medium"><?= htmlspecialchars($order['order_id']) ?></div>
                                    <div class="text-sm text-gray-400">
                                        <?= htmlspecialchars($order['payment_method']) ?>
                                    </div>
                                </td>
                                <td class="p-3">
                                    <div class="font-medium">
                                        <?= htmlspecialchars($order['customer']['first_name'] . ' ' . $order['customer']['last_name']) ?>
                                    </div>
                                    <div class="text-sm text-gray-400">
                                        <?= htmlspecialchars($order['customer']['email']) ?>
                                    </div>
                                </td>
                                <td class="p-3">
                                    <div class="font-medium">
                                        <?= date('M j, Y', strtotime($order['created'])) ?>
                                    </div>
                                    <div class="text-sm text-gray-400">
                                        <?= date('g:i A', strtotime($order['created'])) ?>
                                    </div>
                                </td>
                                <td class="p-3">
                                    <div class="font-medium"><?= count($order['items']) ?> items</div>
                                    <div class="text-sm text-gray-400">
                                        <?= array_sum(array_column($order['items'], 'quantity')) ?> qty
                                    </div>
                                </td>
                                <td class="p-3">
                                    <div class="font-bold text-lg">$<?= number_format($order['totals']['total'], 2) ?></div>
                                </td>
                                <td class="p-3">
                                    <span class="status-badge status-<?= $order['status'] ?>">
                                        <?= ucfirst($order['status']) ?>
                                    </span>
                                </td>
                                <td class="p-3">
                                    <div class="flex gap-2">
                                        <button onclick="viewOrder('<?= htmlspecialchars($order['order_id']) ?>')" 
                                                class="btn-icon" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button onclick="updateOrderStatus('<?= htmlspecialchars($order['order_id']) ?>', '<?= htmlspecialchars($order['status']) ?>')" 
                                                class="btn-icon" title="Update Status">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button onclick="printOrder('<?= htmlspecialchars($order['order_id']) ?>')" 
                                                class="btn-icon" title="Print">
                                            <i class="fas fa-print"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Order Details Modal -->
<div id="order-details-modal" class="modal hidden">
    <div class="modal-backdrop" onclick="closeModal('order-details-modal')"></div>
    <div class="modal-content glass-modal max-w-4xl">
        <div class="modal-header">
            <h2 class="text-xl font-bold">Order Details</h2>
            <button onclick="closeModal('order-details-modal')" class="close-btn">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div id="order-details-content" class="modal-body">
            <!-- Order details will be loaded here -->
        </div>
    </div>
</div>

<!-- Order Status Update Modal -->
<div id="status-update-modal" class="modal hidden">
    <div class="modal-backdrop" onclick="closeModal('status-update-modal')"></div>
    <div class="modal-content glass-modal">
        <div class="modal-header">
            <h2 class="text-xl font-bold">Update Order Status</h2>
            <button onclick="closeModal('status-update-modal')" class="close-btn">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form method="POST" class="modal-body space-y-4">
            <input type="hidden" name="action" value="update_order_status">
            <input type="hidden" name="order_id" id="status-order-id">
            
            <div>
                <label class="block text-sm font-medium mb-1">Order Status</label>
                <select name="status" id="status-select" class="w-full p-3 rounded border">
                    <option value="processing">Processing</option>
                    <option value="shipped">Shipped</option>
                    <option value="delivered">Delivered</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Notes (optional)</label>
                <textarea name="notes" rows="3" class="w-full p-3 rounded border"
                          placeholder="Add any notes about this status update..."></textarea>
            </div>
            
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary flex-1">
                    <i class="fas fa-save mr-2"></i>
                    Update Status
                </button>
                <button type="button" onclick="closeModal('status-update-modal')" class="btn btn-outline">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function viewOrder(orderId) {
    // In a real implementation, this would fetch order details via AJAX
    document.getElementById('order-details-content').innerHTML = `
        <div class="text-center p-8">
            <i class="fas fa-spinner fa-spin text-2xl mb-4"></i>
            <p>Loading order details for ${orderId}...</p>
        </div>
    `;
    
    showModal('order-details-modal');
    
    // Mock order details loading
    setTimeout(() => {
        document.getElementById('order-details-content').innerHTML = `
            <div class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h3 class="font-bold mb-2">Customer Information</h3>
                        <div class="space-y-1 text-sm">
                            <p><strong>Name:</strong> John Doe</p>
                            <p><strong>Email:</strong> john@example.com</p>
                            <p><strong>Phone:</strong> (555) 123-4567</p>
                        </div>
                    </div>
                    <div>
                        <h3 class="font-bold mb-2">Shipping Address</h3>
                        <div class="text-sm">
                            <p>123 Main St</p>
                            <p>Anytown, CA 12345</p>
                        </div>
                    </div>
                </div>
                
                <div>
                    <h3 class="font-bold mb-2">Order Items</h3>
                    <div class="space-y-2">
                        <div class="flex justify-between items-center p-2 bg-gray-700 rounded">
                            <span>Sample Product</span>
                            <span>$25.00</span>
                        </div>
                    </div>
                </div>
                
                <div class="text-right">
                    <p class="text-lg font-bold">Total: $25.00</p>
                </div>
            </div>
        `;
    }, 1000);
}

function updateOrderStatus(orderId, currentStatus) {
    document.getElementById('status-order-id').value = orderId;
    document.getElementById('status-select').value = currentStatus;
    showModal('status-update-modal');
}

function exportOrders() {
    // In a real implementation, this would generate a CSV export
    showToast('Export functionality coming soon!', 'info');
}

function refreshOrders() {
    window.location.reload();
}

function printOrder(orderId) {
    // In a real implementation, this would open a print-friendly order view
    showToast('Print functionality coming soon!', 'info');
}
</script>