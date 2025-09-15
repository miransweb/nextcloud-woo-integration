<?php
/**
 * Nextcloud Accounts template - Enhanced with verification status
 *
 * @var array $accounts
 * @var int $user_id
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="ncwi-accounts-wrapper">
    
    <div class="ncwi-accounts-header">
        <h3><?php _e('My Nextcloud Accounts', 'nc-woo-integration'); ?></h3>
        <button class="button ncwi-add-account-btn" id="ncwi-add-account">
            <?php _e('+ New Account', 'nc-woo-integration'); ?>
        </button>
    </div>
    
    <?php if (empty($accounts)): ?>
        
        <div class="woocommerce-message woocommerce-message--info">
            <p><?php _e('You don\'t have any Nextcloud accounts yet. Create a new account or link an existing one.', 'nc-woo-integration'); ?></p>
        </div>
        
    <?php else: ?>
        
        <div class="ncwi-accounts-list">
            <?php foreach ($accounts as $account): ?>
                <div class="ncwi-account-item" data-account-id="<?php echo esc_attr($account['id']); ?>">
                    <div class="ncwi-account-main">
                        <div class="ncwi-account-info">
                            <h4><?php echo esc_html($account['nc_email']); ?></h4>
                            <span class="ncwi-server"><?php echo esc_html(parse_url($account['nc_server'], PHP_URL_HOST)); ?></span>
                        </div>
                        <div class="ncwi-account-status">
                            <?php
                            $status_class = 'ncwi-status-' . $account['status'];
                            $status_text = '';
                            
                            switch ($account['status']) {
                                case 'active':
                                    $status_text = __('Active', 'nc-woo-integration');
                                    break;
                                case 'pending_verification':
                                    $status_text = __('Verification required', 'nc-woo-integration');
                                    break;
                                case 'suspended':
                                    $status_text = __('Blocked', 'nc-woo-integration');
                                    break;
                                case 'deleted':
                                    $status_text = __('Deleted', 'nc-woo-integration');
                                    break;
                                default:
                                    $status_text = ucfirst($account['status']);
                            }
                            ?>
                            <span class="ncwi-status <?php echo esc_attr($status_class); ?>">
                                <?php echo esc_html($status_text); ?>
                            </span>
                            
                            <?php if ($account['status'] === 'pending_verification'): ?>
                                <span class="ncwi-verification-badge ncwi-unverified">
                                    <?php _e('Email not verified', 'nc-woo-integration'); ?>
                                </span>
                            <?php elseif ($account['status'] === 'active'): ?>
                                <span class="ncwi-verification-badge ncwi-verified">
                                    <?php _e('Verified', 'nc-woo-integration'); ?> ✓
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="ncwi-account-meta">
                        <?php if (!empty($account['subscriptions'])): ?>
                            <div class="ncwi-subscriptions">
                                <strong><?php _e('Subscriptions:', 'nc-woo-integration'); ?></strong>
                                <?php foreach ($account['subscriptions'] as $sub): ?>
                                    <a href="<?php echo esc_url(wc_get_account_endpoint_url('view-subscription/' . $sub['id'])); ?>">
                    #<?php echo esc_html($sub['id']); ?>
                    <?php if (!empty($sub['quota'])): ?>
                        (<?php echo esc_html($sub['quota']); ?>)
                    <?php endif; ?>
                </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="ncwi-no-subscriptions">
                                <?php _e('No subscriptions', 'nc-woo-integration'); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="ncwi-account-actions">
                        <button class="button ncwi-manage-btn" 
                                data-account-id="<?php echo esc_attr($account['id']); ?>">
                            <?php _e('Manage', 'nc-woo-integration'); ?>
                        </button>
                        
                        <?php if ($account['status'] === 'active'): ?>
                            <button class="button ncwi-refresh-btn" 
                                    data-account-id="<?php echo esc_attr($account['id']); ?>">
                                <?php _e('Refresh', 'nc-woo-integration'); ?>
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($account['status'] === 'pending_verification'): ?>
                            <button class="button ncwi-resend-verification-btn" 
                                    data-account-id="<?php echo esc_attr($account['id']); ?>"
                                    data-email="<?php echo esc_attr($account['nc_email']); ?>">
                                <?php _e('Verify again', 'nc-woo-integration'); ?>
                            </button>
                            <button class="button ncwi-check-verification-btn" 
                                    data-account-id="<?php echo esc_attr($account['id']); ?>">
                                <?php _e('Check status', 'nc-woo-integration'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
    <?php endif; ?>
    
    <!-- Email Verification Notice -->
    <div class="ncwi-verification-notice" style="margin-top: 20px; padding: 15px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; display: none;">
        <h4 style="margin-top: 0;"><?php _e('Email verification information', 'nc-woo-integration'); ?></h4>
        <p><?php _e('The verification email is sent by the Nextcloud server. If you don\'t receive an email:', 'nc-woo-integration'); ?></p>
        <ul>
            <li><?php _e('Check your spam folder', 'nc-woo-integration'); ?></li>
            <li><?php _e('Check if the email address is correct', 'nc-woo-integration'); ?></li>
            <li><?php _e('Please wait a few minutes and try again', 'nc-woo-integration'); ?></li>
            <li><?php _e('If the problem persists, please contact your administrator', 'nc-woo-integration'); ?></li>
        </ul>
    </div>
</div>

<style>
.ncwi-accounts-wrapper {
    margin: 20px 0;
}

.ncwi-accounts-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 10px;
}

.ncwi-accounts-header h3 {
    margin: 0;
}

.ncwi-accounts-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.ncwi-account-item {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 15px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.ncwi-account-main {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 10px;
}

.ncwi-account-info h4 {
    margin: 0 0 5px 0;
    font-size: 16px;
    word-break: break-word;
}

.ncwi-server {
    color: #666;
    font-size: 14px;
}

.ncwi-account-status {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.ncwi-status {
    padding: 4px 10px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
    white-space: nowrap;
}

.ncwi-status-active {
    background: #4caf50;
    color: white;
}

.ncwi-status-pending_verification {
    background: #ff9800;
    color: white;
}

.ncwi-status-suspended,
.ncwi-status-deleted {
    background: #f44336;
    color: white;
}

.ncwi-verification-badge {
    font-size: 12px;
    padding: 3px 8px;
    border-radius: 3px;
}

.ncwi-verified {
    background: #e8f5e9;
    color: #2e7d32;
}

.ncwi-unverified {
    background: #fff3e0;
    color: #e65100;
}

.ncwi-account-meta {
    font-size: 14px;
    color: #666;
}

.ncwi-subscriptions a {
    margin: 0 5px;
}

.ncwi-account-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.ncwi-account-actions .button {
    font-size: 13px;
    padding: 5px 12px;
}

.ncwi-resend-verification-btn {
    background: #ff9800 !important;
    color: white !important;
    border-color: #ff9800 !important;
}

.ncwi-resend-verification-btn:hover {
    background: #f57c00 !important;
    border-color: #f57c00 !important;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .ncwi-account-main {
        flex-direction: column;
    }
    
    .ncwi-account-actions {
        width: 100%;
    }
    
    .ncwi-account-actions .button {
        flex: 1;
        text-align: center;
    }
}

/* WooCommerce specific overrides */
.woocommerce-MyAccount-content .ncwi-accounts-wrapper {
    max-width: 100%;
}
</style>

<!-- Add Account Modal  -->
<div id="ncwi-add-account-modal" class="ncwi-modal" style="display: none;">
    <div class="ncwi-modal-content">
        <span class="ncwi-modal-close">&times;</span>
        <h3><?php _e('Add Nextcloud account', 'nc-woo-integration'); ?></h3>
        
        <div class="ncwi-tabs">
            <button class="ncwi-tab-btn active" data-tab="create">
                <?php _e('Create new account', 'nc-woo-integration'); ?>
            </button>
            <button class="ncwi-tab-btn" data-tab="link">
                <?php _e('Link existing account', 'nc-woo-integration'); ?>
            </button>
        </div>
        
        <div class="ncwi-tab-content" id="ncwi-create-tab">
            <form id="ncwi-create-account-form">
                <p>
                    <label for="ncwi-new-username"><?php _e('Gebruikersnaam', 'nc-woo-integration'); ?></label>
                    <input type="text" id="ncwi-new-username" name="username" required>
                    <span class="description">
                        <?php _e('Choose an username for your Nextcloud account', 'nc-woo-integration'); ?>
                    </span>
                </p>
                
                <p>
                    <label for="ncwi-new-email"><?php _e('Email', 'nc-woo-integration'); ?></label>
                    <input type="email" id="ncwi-new-email" name="email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" required>
                    <span class="description">
                        <?php _e('You will receive a verification email at this address', 'nc-woo-integration'); ?>
                    </span>
                </p>
                
                <div class="ncwi-info-box" style="background: #e3f2fd; padding: 10px; margin: 10px 0; border-radius: 4px;">
                    <strong><?php _e('Attention:', 'nc-woo-integration'); ?></strong>
                    <?php _e('After creating your account, you must verify your email address before you can use the account.', 'nc-woo-integration'); ?>
                </div>
                
                <p>
                    <button type="submit" class="button button-primary">
                        <?php _e('Create account', 'nc-woo-integration'); ?>
                    </button>
                </p>
            </form>
        </div>
        
        <div class="ncwi-tab-content" id="ncwi-link-tab" style="display: none;">
            <form id="ncwi-link-account-form">
                <p>
                    <label for="ncwi-link-username"><?php _e('Nextcloud username', 'nc-woo-integration'); ?></label>
                    <input type="text" id="ncwi-link-username" name="username" required>
                </p>
                
                <p>
                    <label for="ncwi-link-email"><?php _e('Nextcloud email', 'nc-woo-integration'); ?></label>
                    <input type="email" id="ncwi-link-email" name="email" required>
                </p>
                
                <p>
                    <label for="ncwi-link-password"><?php _e('Nextcloud password', 'nc-woo-integration'); ?></label>
                    <input type="password" id="ncwi-link-password" name="password" required>
                    <span class="description">
                        <?php _e('Your password is used for verification and will not be stored.', 'nc-woo-integration'); ?>
                    </span>
                </p>
                
                <p>
                    <button type="submit" class="button button-primary">
                        <?php _e('Link account', 'nc-woo-integration'); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>
</div>

<!-- Manage Account Modal  -->
<div id="ncwi-manage-account-modal" class="ncwi-modal" style="display: none;">
    <div class="ncwi-modal-content">
        <span class="ncwi-modal-close">&times;</span>
        <h3><?php _e('Manage account', 'nc-woo-integration'); ?></h3>
        
        <div id="ncwi-manage-account-content">
            <!-- Content loaded via AJAX -->
        </div>
    </div>
</div>