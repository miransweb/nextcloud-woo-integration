<?php
/**
 * My Account Dashboard
 *
 * Custom template for NCWI plugin
 *
 * @version 1.0.0
 */

defined('ABSPATH') || exit;
?>

<p>
    <?php 
    printf(
        __('Hello %s,', 'woocommerce'), 
        '<strong>' . esc_html(wp_get_current_user()->display_name) . '</strong>'
    ); 
    ?>
</p>

<p><?php _e('From your account dashboard you can:', 'woocommerce'); ?></p>

<ul>
    <li><a href="<?php echo esc_url(wc_get_account_endpoint_url('nextcloud-accounts')); ?>">
        <?php _e('Manage your Nextcloud accounts', 'nc-woo-integration'); ?>
    </a></li>
    <li><a href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>">
        <?php _e('View your recent orders', 'woocommerce'); ?>
    </a></li>
    <li><a href="<?php echo esc_url(wc_get_account_endpoint_url('subscriptions')); ?>">
        <?php _e('Manage your subscriptions', 'woocommerce'); ?>
    </a></li>
    <li><a href="<?php echo esc_url(wc_get_account_endpoint_url('edit-address')); ?>">
        <?php _e('Edit your shipping and billing addresses', 'woocommerce'); ?>
    </a></li>
    <li><a href="<?php echo esc_url(wc_get_account_endpoint_url('edit-account')); ?>">
        <?php _e('Change your account details and password', 'woocommerce'); ?>
    </a></li>
</ul>

<div class="ncwi-dashboard-info" style="margin-top: 30px; padding: 20px; background: #f5f5f5; border-radius: 5px;">
    <h3><?php _e('Link Nextcloud Account', 'nc-woo-integration'); ?></h3>
    <p><?php _e('To use your Nextcloud licenses:', 'nc-woo-integration'); ?></p>
    <ol>
        <li><?php _e('Go to', 'nc-woo-integration'); ?> 
            <a href="<?php echo wc_get_account_endpoint_url('nextcloud-accounts'); ?>">
                <?php _e('Nextcloud Accounts', 'nc-woo-integration'); ?>
            </a>
        </li>
        <li><?php _e('Create a new account or link an existing account', 'nc-woo-integration'); ?></li>
        <li><?php _e('Link your active subscriptions to the account', 'nc-woo-integration'); ?></li>
    </ol>
</div>

<?php
/**
 * My Account dashboard.
 *
 * @since 2.6.0
 */
do_action('woocommerce_account_dashboard');

/**
 * Deprecated woocommerce_before_my_account action.
 *
 * @deprecated 2.6.0
 */
do_action('woocommerce_before_my_account');

/**
 * Deprecated woocommerce_after_my_account action.
 *
 * @deprecated 2.6.0
 */
do_action('woocommerce_after_my_account');