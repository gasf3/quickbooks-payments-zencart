<?php
/**
 * QuickBooks Payments Admin Tool
 * For Void and Refund Operations
 *
 * Installation: Upload this file to your ZenCart ADMIN folder
 * (the folder name is unique to your installation for security)
 * Access via: https://yoursite.com/YOUR_ADMIN_FOLDER/quickbooks_admin.php
 */

require('includes/application_top.php');

// Check admin login
if (!isset($_SESSION['admin_id'])) {
    zen_redirect(zen_href_link(FILENAME_LOGIN, '', 'SSL'));
}

// CSRF token management - generate if not present
if (empty($_SESSION['qb_admin_csrf_token'])) {
    $_SESSION['qb_admin_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['qb_admin_csrf_token'];

// Only accept state-changing actions via POST (prevents CSRF via link)
$action = $_POST['action'] ?? '';
$order_id = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
$message = '';
$error = '';

// Load the QuickBooks payment module
require_once(DIR_FS_CATALOG . 'includes/modules/payment/quickbooks_payments.php');
$qb = new quickbooks_payments();

// Validate CSRF token for all POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($action)) {
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $submitted_token)) {
        $error = 'Security validation failed. Please try again.';
        $action = ''; // Block the action
    }
}

// Handle actions (POST-only with CSRF validation)
if ($action == 'void' && $order_id > 0) {
    $result = $qb->_doVoid($order_id);
    if ($result === 'success') {
        $message = "Order #$order_id has been successfully VOIDED.";
    } else {
        $error = $result;
    }
}

if ($action == 'refund' && $order_id > 0) {
    $refund_amount = isset($_POST['refund_amount']) ? (float)$_POST['refund_amount'] : 0;
    $result = $qb->_doRefund($order_id, $refund_amount);
    if ($result === 'success') {
        $message = "Order #$order_id has been successfully REFUNDED.";
    } else {
        $error = $result;
    }
}

// Regenerate CSRF token after successful action to prevent replay
if (!empty($message)) {
    $_SESSION['qb_admin_csrf_token'] = bin2hex(random_bytes(32));
    $csrf_token = $_SESSION['qb_admin_csrf_token'];
}

// Get transaction details if order_id provided
$transactions = array();
$order_info = null;
$can_void = false;
$can_refund = false;

if ($order_id > 0) {
    $transactions = $qb->getTransactionDetails($order_id);
    $can_void = $qb->canVoid($order_id);
    $can_refund = $qb->canRefund($order_id);

    // Get order info
    $order_query = $db->Execute("SELECT * FROM " . TABLE_ORDERS . " WHERE orders_id = " . (int)$order_id);
    if (!$order_query->EOF) {
        $order_info = $order_query->fields;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>QuickBooks Payments Admin</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2c5aa0; }
        .form-group { margin: 15px 0; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="number"] { padding: 10px; width: 200px; border: 1px solid #ccc; border-radius: 4px; }
        button, .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; margin-right: 10px; }
        .btn-primary { background: #2c5aa0; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-warning { background: #ffc107; color: black; }
        .btn-success { background: #28a745; color: white; }
        .alert { padding: 15px; border-radius: 5px; margin: 15px 0; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        .status-success { color: #28a745; font-weight: bold; }
        .status-failed { color: #dc3545; font-weight: bold; }
        .order-info { background: #e9ecef; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .actions { margin: 20px 0; padding: 20px; background: #f8f9fa; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>QuickBooks Payments Admin</h1>
        <p><a href="<?php echo zen_href_link(FILENAME_DEFAULT); ?>">&laquo; Back to Admin</a></p>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="get" action="">
            <div class="form-group">
                <label for="order_id">Order ID:</label>
                <input type="text" name="order_id" id="order_id" value="<?php echo $order_id > 0 ? $order_id : ''; ?>" placeholder="Enter Order ID">
                <button type="submit" class="btn btn-primary">Look Up</button>
            </div>
        </form>

        <?php if ($order_id > 0 && $order_info): ?>
            <div class="order-info">
                <h3>Order #<?php echo $order_id; ?></h3>
                <p>
                    <strong>Customer:</strong> <?php echo htmlspecialchars($order_info['customers_name']); ?><br>
                    <strong>Email:</strong> <?php echo htmlspecialchars($order_info['customers_email_address']); ?><br>
                    <strong>Total:</strong> $<?php echo number_format($order_info['order_total'], 2); ?><br>
                    <strong>Date:</strong> <?php echo htmlspecialchars($order_info['date_purchased']); ?><br>
                    <strong>Payment Method:</strong> <?php echo htmlspecialchars($order_info['payment_method']); ?>
                </p>
            </div>

            <?php if (count($transactions) > 0): ?>
                <h3>Transaction History</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Charge ID</th>
                            <th>Auth Code</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $trans): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($trans['dtime']); ?></td>
                                <td><?php echo htmlspecialchars($trans['trans_type']); ?></td>
                                <td><?php echo htmlspecialchars($trans['status']); ?></td>
                                <td style="font-size: 11px;"><?php echo htmlspecialchars($trans['charge_id']); ?></td>
                                <td><?php echo htmlspecialchars($trans['auth_code']); ?></td>
                                <td class="<?php echo $trans['response_code'] == '0' ? 'status-success' : 'status-failed'; ?>">
                                    <?php echo $trans['response_code'] == '0' ? 'SUCCESS' : 'FAILED'; ?>
                                    <?php if ($trans['message']): ?>
                                        <br><small><?php echo htmlspecialchars($trans['message']); ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No QuickBooks transactions found for this order.</p>
            <?php endif; ?>

            <div class="actions">
                <h3>Actions</h3>

                <?php if ($can_void): ?>
                    <form method="post" action="" style="display: inline-block; margin-right: 20px;" onsubmit="return confirm('Are you sure you want to VOID this transaction?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                        <input type="hidden" name="action" value="void">
                        <button type="submit" class="btn btn-warning">Void Transaction</button>
                    </form>
                <?php else: ?>
                    <button class="btn" disabled style="opacity: 0.5;">Void Not Available</button>
                <?php endif; ?>

                <?php if ($can_refund): ?>
                    <form method="post" action="" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to REFUND this transaction?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                        <input type="hidden" name="action" value="refund">
                        <label style="display: inline;">Refund Amount: $</label>
                        <input type="number" name="refund_amount" step="0.01" value="<?php echo number_format($order_info['order_total'], 2, '.', ''); ?>" style="width: 100px;">
                        <button type="submit" class="btn btn-danger">Refund</button>
                        <small>(Leave amount for full refund, or enter partial amount)</small>
                    </form>
                <?php else: ?>
                    <button class="btn" disabled style="opacity: 0.5;">Refund Not Available</button>
                <?php endif; ?>
            </div>

        <?php elseif ($order_id > 0): ?>
            <div class="alert alert-danger">Order #<?php echo $order_id; ?> not found.</div>
        <?php endif; ?>

    </div>
</body>
</html>
<?php require('includes/application_bottom.php'); ?>
