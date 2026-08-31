<?php
/**
 * My Store — Checkout Process
 * Complete checkout flow with payment provider integration
 *
 * File: /admin/modules/MyStore/views/mystore-checkout.php
 * Purpose: Complete checkout process for store payments
 */

if (!defined('MYSTORE_SYSTEM') && !defined('PAYPAL_SYSTEM')) {
    die('Direct access not allowed');
}

// Get cart items and settings
require_once __DIR__ . '/../includes/mystore-shipping.php';
$cart = paypal_get_cart_items();
$settings = paypal_get_all_settings();
$subtotal = paypal_get_cart_total_fixed();
$tax = $subtotal * (($settings['taxRate'] ?? 0) / 100);
$cartQty = mystore_cart_quantity($cart);
$shippingCost = mystore_calc_shipping($cartQty, $subtotal, $settings);
$total = $subtotal + $tax + $shippingCost;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'send_verification':
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            if (!$email) { echo json_encode(['success' => false, 'message' => 'Invalid email address']); exit; }
            // Spam check first
            $spam_flags = mystore_check_spam_email($email);
            if (count($spam_flags) >= 2) { echo json_encode(['success' => false, 'message' => 'This email address appears invalid. Please use a real email address.']); exit; }
            // Rate limit: 1 code per 60s
            if (session_status() === PHP_SESSION_NONE) session_start();
            $v = $_SESSION['mystore_email_verify'] ?? null;
            if ($v && $v['email'] === $email && (time() - ($v['sent_at'] ?? 0)) < 60) {
                echo json_encode(['success' => false, 'message' => 'Please wait before requesting another code.']);
                exit;
            }
            $sent = mystore_send_verification($email);
            if ($sent) {
                $_SESSION['mystore_email_verify']['sent_at'] = time();
                echo json_encode(['success' => true, 'message' => 'Verification code sent to ' . $email]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to send verification email. Please try again.']);
            }
            exit;

        case 'verify_code':
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            $code = preg_replace('/[^0-9]/', '', $_POST['code'] ?? '');
            if (!$email || !$code) { echo json_encode(['success' => false, 'message' => 'Missing email or code']); exit; }
            $result = mystore_verify_code($email, $code);
            echo json_encode(['success' => $result['ok'], 'message' => $result['error'] ?? 'Email verified!']);
            exit;

        case 'create_order':
            $customerInfo = [
                'name' => sanitize_text_field($_POST['name'] ?? ''),
                'email' => sanitize_text_field($_POST['email'] ?? ''),
                'phone' => sanitize_text_field($_POST['phone'] ?? ''),
                'address' => [
                    'street' => sanitize_text_field($_POST['street'] ?? ''),
                    'city' => sanitize_text_field($_POST['city'] ?? ''),
                    'state' => sanitize_text_field($_POST['state'] ?? ''),
                    'zip' => sanitize_text_field($_POST['zip'] ?? ''),
                    'country' => sanitize_text_field($_POST['country'] ?? '')
                ]
            ];
            
            $order = paypal_create_order($cart, $customerInfo);
            if ($order) {
                echo json_encode(['success' => true, 'order' => $order]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create order']);
            }
            exit;
            
        case 'complete_payment':
            $orderId = sanitize_text_field($_POST['order_id'] ?? '');
            $paymentData = json_decode($_POST['payment_data'] ?? ($_POST['paypal_data'] ?? '{}'), true);

            if (paypal_update_order_status($orderId, 'completed', $paymentData)) {
                // Clear cart
                if (session_status() === PHP_SESSION_NONE) session_start();
                $_SESSION['mystore_cart'] = [];
                
                echo json_encode(['success' => true, 'message' => 'Payment completed successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to complete payment']);
            }
            exit;
    }
}
?>

<!-- Checkout Process -->
<div id="checkout-container" class="container" style="max-width: 1200px; margin: 0 auto; padding: 2rem;">
    <div style="display: grid; grid-template-columns: 1fr 400px; gap: 2rem; align-items: start;">
        
        <!-- Main Checkout Form -->
        <div class="glass-card" style="padding: 2rem; border-radius: var(--radius);">
            <h2 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-credit-card"></i>
                Checkout
            </h2>
            
            <!-- Welcome Message -->
            <div style="background: rgba(251, 191, 36, 0.1); border: 1px solid rgba(251, 191, 36, 0.3); border-radius: var(--radius); padding: 1rem; margin-bottom: 2rem;">
                <p style="margin: 0; color: var(--primary);">
                    <?= htmlspecialchars($settings['checkoutWelcomeMessage'] ?? 'Welcome to our secure checkout. Please review your order below.') ?>
                </p>
            </div>
            
            <!-- Customer Information Form -->
            <form id="checkout-form">
                <div style="margin-bottom: 2rem;">
                    <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Customer Information</h3>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <label for="customer-name" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Full Name *</label>
                            <input type="text" id="customer-name" name="name" required class="form-input" placeholder="John Doe">
                        </div>
                        <div>
                            <label for="customer-email" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Email Address *</label>
                            <input type="email" id="customer-email" name="email" required class="form-input" placeholder="john@example.com">
                        </div>
                    </div>

                    <!-- Email Verification -->
                    <div id="email-verify-section" style="margin-bottom:1rem;padding:1rem;border-radius:var(--radius);border:1px solid var(--border);background:rgba(255,255,255,0.02)">
                        <div id="email-verify-prompt">
                            <p style="margin:0 0 0.75rem;font-size:0.85rem;color:var(--muted-foreground)">
                                <i class="fas fa-shield-alt" style="color:var(--primary)"></i>
                                To protect against fraud, please verify your email before checkout.
                            </p>
                            <button type="button" id="send-verify-btn" class="btn btn-outline" style="font-size:0.85rem;padding:0.5rem 1rem" onclick="sendVerification()">
                                <i class="fas fa-envelope"></i> Send Verification Code
                            </button>
                        </div>
                        <div id="email-verify-code" style="display:none">
                            <p style="margin:0 0 0.75rem;font-size:0.85rem;color:var(--muted-foreground)">
                                Enter the 6-digit code sent to your email:
                            </p>
                            <div style="display:flex;gap:0.5rem;align-items:center">
                                <input type="text" id="verify-code-input" class="form-input" maxlength="6" pattern="[0-9]{6}" placeholder="000000" style="width:140px;text-align:center;font-size:1.2rem;letter-spacing:0.2em;font-weight:bold">
                                <button type="button" class="btn btn-primary" style="font-size:0.85rem;padding:0.5rem 1rem" onclick="submitVerification()">Verify</button>
                                <button type="button" class="btn btn-outline" style="font-size:0.75rem;padding:0.4rem 0.75rem" onclick="sendVerification()">Resend</button>
                            </div>
                            <p id="verify-error" style="display:none;margin:0.5rem 0 0;font-size:0.78rem;color:#dc2626"></p>
                        </div>
                        <div id="email-verified" style="display:none">
                            <p style="margin:0;font-size:0.85rem;color:#22c55e;font-weight:600">
                                <i class="fas fa-check-circle"></i> Email verified successfully
                            </p>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 1rem;">
                        <label for="customer-phone" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Phone Number</label>
                        <input type="tel" id="customer-phone" name="phone" class="form-input" placeholder="(555) 123-4567">
                    </div>
                </div>
                
                <div style="margin-bottom: 2rem;">
                    <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Shipping Address</h3>
                    
                    <div style="margin-bottom: 1rem;">
                        <label for="address-street" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Street Address *</label>
                        <input type="text" id="address-street" name="street" required class="form-input" placeholder="123 Main Street">
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <label for="address-city" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">City *</label>
                            <input type="text" id="address-city" name="city" required class="form-input" placeholder="City">
                        </div>
                        <div>
                            <label for="address-state" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">State *</label>
                            <input type="text" id="address-state" name="state" required class="form-input" placeholder="State">
                        </div>
                        <div>
                            <label for="address-zip" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">ZIP Code *</label>
                            <input type="text" id="address-zip" name="zip" required class="form-input" placeholder="12345">
                        </div>
                    </div>
                    
                    <div>
                        <label for="address-country" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Country *</label>
                        <select id="address-country" name="country" required class="form-input">
                            <option value="United States">United States</option>
                            <option value="Canada">Canada</option>
                            <option value="United Kingdom">United Kingdom</option>
                            <option value="Australia">Australia</option>
                            <option value="Germany">Germany</option>
                            <option value="France">France</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>
            </form>
            
            <!-- Payment Section -->
            <div style="margin-bottom: 2rem;">
                <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Payment Method</h3>

                <div style="background: rgba(255, 255, 255, 0.05); border-radius: var(--radius); padding: 1.5rem; text-align: center;">
                    <div style="margin-bottom: 1rem;">
                        <i class="fas fa-lock" style="font-size: 2rem; color: var(--primary); margin-bottom: 0.5rem;"></i>
                        <p style="margin: 0; color: var(--muted-foreground);">Secure Checkout</p>
                    </div>

                    <!-- Payment Provider Button Container (populated by payment provider integration) -->
                    <div id="payment-button-container" style="margin-top: 1rem;"></div>

                    <!-- Place Order Button -->
                    <button type="button" id="place-order-btn" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1.125rem; margin-top: 1rem;" onclick="placeOrder()">
                        <i class="fas fa-credit-card"></i>
                        Place Order - $<?= number_format($total, 2) ?>
                    </button>
                </div>
            </div>
            
            <!-- Terms and Conditions -->
            <div style="font-size: 0.875rem; color: var(--muted-foreground); text-align: center;">
                <label style="display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <input type="checkbox" id="agree-terms" required>
                    <span>I agree to the <a href="#" style="color: var(--primary);">Terms of Service</a> and <a href="#" style="color: var(--primary);">Privacy Policy</a></span>
                </label>
            </div>
        </div>
        
        <!-- Order Summary -->
        <div class="glass-card" style="padding: 1.5rem; border-radius: var(--radius); height: fit-content; position: sticky; top: 2rem;">
            <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Order Summary</h3>
            
            <!-- Cart Items -->
            <div style="margin-bottom: 1rem;">
                <?php if (empty($cart)): ?>
                    <div style="text-align: center; padding: 2rem 0; color: var(--muted-foreground);">
                        <i class="fas fa-shopping-cart" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                        <p style="margin: 0;"><?= htmlspecialchars($settings['cartEmptyMessage'] ?? 'Your cart is empty. Browse our products to get started!') ?></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($cart as $item): ?>
                        <div style="display: flex; align-items: center; gap: 1rem; padding: 0.75rem 0; border-bottom: 1px solid var(--border);">
                            <div style="width: 3rem; height: 3rem; border-radius: var(--radius); overflow: hidden; background: var(--muted); flex-shrink: 0;">
                                <?php if (!empty($item['image'])): ?>
                                    <img src="<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                <?php else: ?>
                                    <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: var(--muted-foreground);">
                                        <i class="fas fa-image"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="flex: 1;">
                                <h4 style="margin: 0; font-size: 0.875rem; font-weight: 500;"><?= htmlspecialchars($item['name']) ?></h4>
                                <p style="margin: 0.25rem 0 0 0; font-size: 0.75rem; color: var(--muted-foreground);">
                                    Qty: <?= intval($item['quantity']) ?> × $<?= number_format(floatval($item['price']), 2) ?>
                                </p>
                            </div>
                            <div style="font-weight: 500;">
                                $<?= number_format(floatval($item['price']) * intval($item['quantity']), 2) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Order Totals -->
            <div style="border-top: 1px solid var(--border); padding-top: 1rem;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                    <span>Subtotal:</span>
                    <span>$<?= number_format($subtotal, 2) ?></span>
                </div>
                
                <?php if ($tax > 0): ?>
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                    <span>Tax (<?= $settings['taxRate'] ?>%):</span>
                    <span>$<?= number_format($tax, 2) ?></span>
                </div>
                <?php endif; ?>
                
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                    <span>Shipping:</span>
                    <span>
                        <?php if ($shippingCost == 0): ?>
                            <span style="color: var(--primary);">FREE</span>
                        <?php else: ?>
                            $<?= number_format($shippingCost, 2) ?>
                        <?php endif; ?>
                    </span>
                </div>
                
                <?php $freeThr = mystore_free_shipping_threshold($settings); ?>
                <?php if ($shippingCost > 0 && $freeThr > 0 && $subtotal < $freeThr): ?>
                <div style="font-size: 0.75rem; color: var(--muted-foreground); margin-bottom: 1rem;">
                    Add $<?= number_format($freeThr - $subtotal, 2) ?> more for free shipping
                </div>
                <?php endif; ?>
                
                <div style="display: flex; justify-content: space-between; font-size: 1.125rem; font-weight: bold; padding-top: 1rem; border-top: 1px solid var(--border);">
                    <span>Total:</span>
                    <span style="color: var(--primary);">$<?= number_format($total, 2) ?></span>
                </div>
            </div>
            
            <!-- Security Badge -->
            <div style="text-align: center; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border);">
                <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; font-size: 0.75rem; color: var(--muted-foreground);">
                    <i class="fas fa-shield-alt"></i>
                    <span>Secure 256-bit SSL encryption</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Success Modal -->
<div id="success-modal" class="modal" style="display: none;">
    <div class="modal-content glass-modal" style="max-width: 500px; padding: 2rem; text-align: center;">
        <div style="margin-bottom: 1rem;">
            <i class="fas fa-check-circle" style="font-size: 4rem; color: #22c55e; margin-bottom: 1rem;"></i>
            <h2 style="margin: 0; color: var(--foreground);">Order Successful!</h2>
        </div>
        
        <div id="success-message" style="margin-bottom: 2rem; color: var(--muted-foreground);">
            <?= htmlspecialchars($settings['thankYouMessage'] ?? 'Thank you for your purchase! Your order has been received and is being processed.') ?>
        </div>
        
        <div style="display: flex; gap: 1rem; justify-content: center;">
            <button class="btn btn-outline" onclick="continueShopping()">Continue Shopping</button>
            <button class="btn btn-primary" onclick="viewOrder()">View Order</button>
        </div>
    </div>
</div>

<script>
let currentOrder = null;
let emailVerified = false;

// ── Email Verification ──
function sendVerification() {
    const email = document.getElementById('customer-email').value.trim();
    if (!email) { showToast('Please enter your email address first', 'error'); return; }

    const btn = document.getElementById('send-verify-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Sending...'; }

    const fd = new FormData();
    fd.append('action', 'send_verification');
    fd.append('email', email);

    fetch('', {method:'POST', body:fd}).then(r => r.json()).then(data => {
        if (data.success) {
            document.getElementById('email-verify-prompt').style.display = 'none';
            document.getElementById('email-verify-code').style.display = '';
            document.getElementById('verify-code-input').focus();
            showToast(data.message, 'success');
        } else {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-envelope"></i> Send Verification Code'; }
            showToast(data.message, 'error');
        }
    }).catch(() => {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-envelope"></i> Send Verification Code'; }
        showToast('Network error — please try again', 'error');
    });
}

function submitVerification() {
    const email = document.getElementById('customer-email').value.trim();
    const code = document.getElementById('verify-code-input').value.trim();
    if (!code || code.length < 6) { document.getElementById('verify-error').style.display='block'; document.getElementById('verify-error').textContent='Enter the 6-digit code'; return; }

    const fd = new FormData();
    fd.append('action', 'verify_code');
    fd.append('email', email);
    fd.append('code', code);

    fetch('', {method:'POST', body:fd}).then(r => r.json()).then(data => {
        if (data.success) {
            emailVerified = true;
            document.getElementById('email-verify-code').style.display = 'none';
            document.getElementById('email-verified').style.display = '';
            document.getElementById('customer-email').readOnly = true;
            document.getElementById('customer-email').style.opacity = '0.7';
            showToast('Email verified!', 'success');
        } else {
            document.getElementById('verify-error').style.display = 'block';
            document.getElementById('verify-error').textContent = data.message;
        }
    }).catch(() => {
        document.getElementById('verify-error').style.display = 'block';
        document.getElementById('verify-error').textContent = 'Network error';
    });
}

// Reset verification if email changes
document.addEventListener('DOMContentLoaded', () => {
    const emailInput = document.getElementById('customer-email');
    if (emailInput) {
        emailInput.addEventListener('input', () => {
            if (emailVerified) {
                emailVerified = false;
                emailInput.readOnly = false;
                emailInput.style.opacity = '';
                document.getElementById('email-verify-prompt').style.display = '';
                document.getElementById('email-verify-code').style.display = 'none';
                document.getElementById('email-verified').style.display = 'none';
                const btn = document.getElementById('send-verify-btn');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-envelope"></i> Send Verification Code'; }
            }
        });
    }
});

// Payment provider initialization will be handled by PaymentProviders module in Phase 3
function initPayment() {
    // Placeholder — payment provider buttons will be rendered into #payment-button-container
    // by the active payment provider integration (PayPal, Stripe, etc.)
}

function placeOrder() {
    const form = document.getElementById('checkout-form');
    const termsChecked = document.getElementById('agree-terms').checked;
    
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    if (!termsChecked) {
        showToast('Please accept the terms and conditions', 'error');
        return;
    }

    if (!emailVerified) {
        showToast('Please verify your email address before placing your order', 'error');
        document.getElementById('email-verify-section').scrollIntoView({behavior:'smooth'});
        return;
    }

    const formData = new FormData(form);
    formData.append('action', 'create_order');
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            currentOrder = data.order;
            showToast('Order created successfully! Redirecting to payment...', 'success');
            
            // In a real implementation, redirect to payment provider
            setTimeout(() => {
                completePayment('MOCK_ORDER_ID', { id: currentOrder.order_id });
            }, 2000);
        } else {
            showToast(data.message || 'Failed to create order', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to create order', 'error');
    });
}

function completePayment(providerOrderId, providerDetails) {
    const formData = new FormData();
    formData.append('action', 'complete_payment');
    formData.append('order_id', currentOrder ? currentOrder.order_id : 'UNKNOWN');
    formData.append('payment_data', JSON.stringify({
        provider_order_id: providerOrderId,
        details: providerDetails
    }));
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSuccessModal();
            // Clear form
            document.getElementById('checkout-form').reset();
        } else {
            showToast(data.message || 'Payment completion failed', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Payment completion failed', 'error');
    });
}

function showSuccessModal() {
    document.getElementById('success-modal').style.display = 'flex';
}

function continueShopping() {
    document.getElementById('success-modal').style.display = 'none';
    window.location.href = '/'; // Redirect to store
}

function viewOrder() {
    document.getElementById('success-modal').style.display = 'none';
    // Redirect to order view or show order details
    showToast('Order tracking feature coming soon!', 'info');
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', () => {
    <?php if (!empty($cart)): ?>
    initPayment();
    <?php endif; ?>
});

// Make functions globally accessible
window.placeOrder = placeOrder;
window.completePayment = completePayment;
window.continueShopping = continueShopping;
window.viewOrder = viewOrder;
</script>

<style>
/* Additional checkout-specific styles */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(0, 0, 0, 0.8);
    backdrop-filter: blur(8px);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

@media (max-width: 768px) {
    #checkout-container > div {
        grid-template-columns: 1fr !important;
        gap: 1rem !important;
    }
    
    .glass-card {
        padding: 1rem !important;
    }
}
</style>