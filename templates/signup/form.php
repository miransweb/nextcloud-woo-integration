<?php
/**
 * Nextcloud Signup Form Template
 *
 * @package NCWI
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="ncwi-signup-wrapper">
    <h2><?php _e('Create Your Nextcloud Account', 'nc-woo-integration'); ?></h2>
    <p><?php _e('Fill in the form below to create your Nextcloud storage account.', 'nc-woo-integration'); ?></p>
    
    <form id="ncwi-direct-signup" class="ncwi-signup-form">
        <p class="form-row form-row-wide">
            <label for="signup_email"><?php _e('Email Address', 'nc-woo-integration'); ?> <span class="required">*</span></label>
            <input type="email" class="input-text" name="email" id="signup_email" required />
            <span class="description"><?php _e('We\'ll use this for your account and send your login details here.', 'nc-woo-integration'); ?></span>
        </p>
        
        <p class="form-row form-row-wide">
            <label for="signup_username"><?php _e('Choose a Username', 'nc-woo-integration'); ?> <span class="required">*</span></label>
            <input type="text" class="input-text" name="username" id="signup_username" required />
            <span class="description"><?php _e('This will be your Nextcloud username.', 'nc-woo-integration'); ?></span>
        </p>
        
        <p class="form-row form-row-first">
            <label for="signup_password"><?php _e('Password', 'nc-woo-integration'); ?> <span class="required">*</span></label>
            <input type="password" class="input-text" name="password" id="signup_password" required />
            <span class="description"><?php _e('Choose a strong password for your account.', 'nc-woo-integration'); ?></span>
        </p>
        
        <p class="form-row form-row-last">
            <label for="signup_password2"><?php _e('Confirm Password', 'nc-woo-integration'); ?> <span class="required">*</span></label>
            <input type="password" class="input-text" name="password2" id="signup_password2" required />
        </p>
        
        <div class="clear"></div>
        
        <p class="form-row">
            <label class="woocommerce-form__label woocommerce-form__label-for-checkbox">
                <input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox" name="terms" id="signup_terms" required />
                <span>
                    <?php printf(
                        __('I agree to the <a href="%s" target="_blank">terms and conditions</a>', 'nc-woo-integration'),
                        get_privacy_policy_url() ?: '#'
                    ); ?>
                </span>
            </label>
        </p>
        
        <?php wp_nonce_field('ncwi_direct_signup', 'signup_nonce'); ?>
        
        <p class="form-row">
            <button type="submit" class="woocommerce-Button button" id="ncwi-signup-btn">
                <?php _e('Create My Nextcloud Account', 'nc-woo-integration'); ?>
            </button>
        </p>
        
        <div id="ncwi-signup-messages" style="display: none;"></div>
        
        <!-- Progress indicator -->
        <div id="ncwi-signup-progress" style="display: none;">
            <div class="ncwi-progress-wrapper">
                <h3><?php _e('Creating your account...', 'nc-woo-integration'); ?></h3>
                <div class="ncwi-progress-steps">
                    <div class="ncwi-step" id="step-validate">
                        <span class="ncwi-step-icon"></span>
                        <span class="ncwi-step-text"><?php _e('Validating information', 'nc-woo-integration'); ?></span>
                    </div>
                    <div class="ncwi-step" id="step-shop">
                        <span class="ncwi-step-icon"></span>
                        <span class="ncwi-step-text"><?php _e('Creating shop account', 'nc-woo-integration'); ?></span>
                    </div>
                    <div class="ncwi-step" id="step-nextcloud">
                        <span class="ncwi-step-icon"></span>
                        <span class="ncwi-step-text"><?php _e('Setting up Nextcloud storage', 'nc-woo-integration'); ?></span>
                    </div>
                    <div class="ncwi-step" id="step-email">
                        <span class="ncwi-step-icon"></span>
                        <span class="ncwi-step-text"><?php _e('Sending confirmation email', 'nc-woo-integration'); ?></span>
                    </div>
                    <div class="ncwi-step" id="step-complete">
                        <span class="ncwi-step-icon"></span>
                        <span class="ncwi-step-text"><?php _e('Finalizing setup', 'nc-woo-integration'); ?></span>
                    </div>
                </div>
                <div class="ncwi-progress-bar">
                    <div class="ncwi-progress-bar-fill"></div>
                </div>
                <p class="ncwi-progress-message"><?php _e('Please wait while we set up your account. This may take a moment...', 'nc-woo-integration'); ?></p>
            </div>
        </div>
    </form>
    
    <div class="ncwi-login-link">
        <p><?php _e('Already have an account?', 'nc-woo-integration'); ?> 
        <a href="<?php echo wc_get_page_permalink('myaccount'); ?>"><?php _e('Log in here', 'nc-woo-integration'); ?></a></p>
    </div>
</div>