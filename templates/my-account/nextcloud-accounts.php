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
        <h3><?php _e('Mijn Nextcloud Accounts', 'nc-woo-integration'); ?></h3>
        <button class="button ncwi-add-account-btn" id="ncwi-add-account">
            <?php _e('+ Nieuw Account', 'nc-woo-integration'); ?>
        </button>
    </div>
    
    <?php if (empty($accounts)): ?>
        
        <div class="woocommerce-message woocommerce-message--info">
            <p><?php _e('Je hebt nog geen Nextcloud accounts. Maak een nieuw account aan of koppel een bestaand account.', 'nc-woo-integration'); ?></p>
        </div>
        
    <?php else: ?>
        
        <table class="woocommerce-table woocommerce-table--ncwi-accounts shop_table shop_table_responsive">
            <thead>
                <tr>
                    <th><?php _e('Gebruikersnaam', 'nc-woo-integration'); ?></th>
                    <th><?php _e('Email', 'nc-woo-integration'); ?></th>
                    <th><?php _e('Server', 'nc-woo-integration'); ?></th>
                    <th><?php _e('Status', 'nc-woo-integration'); ?></th>
                    <th><?php _e('Email Verificatie', 'nc-woo-integration'); ?></th>
                    <th><?php _e('Quota', 'nc-woo-integration'); ?></th>
                    <th><?php _e('Subscriptions', 'nc-woo-integration'); ?></th>
                    <th><?php _e('Acties', 'nc-woo-integration'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $account): ?>
                    <tr class="ncwi-account-row" data-account-id="<?php echo esc_attr($account['id']); ?>">
                        <td data-title="<?php esc_attr_e('Gebruikersnaam', 'nc-woo-integration'); ?>">
                            <strong><?php echo esc_html($account['nc_username']); ?></strong>
                        </td>
                        <td data-title="<?php esc_attr_e('Email', 'nc-woo-integration'); ?>">
                            <?php echo esc_html($account['nc_email']); ?>
                        </td>
                        <td data-title="<?php esc_attr_e('Server', 'nc-woo-integration'); ?>">
                            <a href="<?php echo esc_url($account['nc_server']); ?>" target="_blank">
                                <?php echo esc_html(parse_url($account['nc_server'], PHP_URL_HOST)); ?>
                            </a>
                        </td>
                        <td data-title="<?php esc_attr_e('Status', 'nc-woo-integration'); ?>">
                            <?php
                            $status_class = 'ncwi-status-' . $account['status'];
                            $status_text = '';
                            
                            switch ($account['status']) {
                                case 'active':
                                    $status_text = __('Actief', 'nc-woo-integration');
                                    break;
                                case 'pending_verification':
                                    $status_text = __('Verificatie vereist', 'nc-woo-integration');
                                    break;
                                case 'suspended':
                                    $status_text = __('Geblokkeerd', 'nc-woo-integration');
                                    break;
                                case 'unlinked':
                                    $status_text = __('Ontkoppeld', 'nc-woo-integration');
                                    break;
                                default:
                                    $status_text = ucfirst($account['status']);
                            }
                            ?>
                            <span class="ncwi-status <?php echo esc_attr($status_class); ?>">
                                <?php echo esc_html($status_text); ?>
                            </span>
                        </td>
                        <td data-title="<?php esc_attr_e('Email Verificatie', 'nc-woo-integration'); ?>">
                            <?php if ($account['status'] === 'pending_verification'): ?>
                                <div class="ncwi-verification-status">
                                    <span class="ncwi-status ncwi-status-unverified" style="background: #ff9800;">
                                        <?php _e('Niet geverifieerd', 'nc-woo-integration'); ?>
                                    </span>
                                    <br/>
                                    <small><?php _e('Check je email voor de verificatie link', 'nc-woo-integration'); ?></small>
                                </div>
                            <?php elseif ($account['status'] === 'active'): ?>
                                <span class="ncwi-status ncwi-status-verified" style="background: #4caf50;">
                                    <?php _e('Geverifieerd', 'nc-woo-integration'); ?> ✓
                                </span>
                            <?php else: ?>
                                <span class="ncwi-no-data">-</span>
                            <?php endif; ?>
                        </td>
                        <td data-title="<?php esc_attr_e('Quota', 'nc-woo-integration'); ?>">
                            <?php if (!empty($account['current_quota'])): ?>
                                <div class="ncwi-quota-info">
                                    <span class="ncwi-quota-used">
                                        <?php echo esc_html($account['used_space'] ?? '0'); ?>
                                    </span>
                                    /
                                    <span class="ncwi-quota-total">
                                        <?php echo esc_html($account['current_quota']); ?>
                                    </span>
                                </div>
                            <?php else: ?>
                                <span class="ncwi-no-data">-</span>
                            <?php endif; ?>
                        </td>
                        <td data-title="<?php esc_attr_e('Subscriptions', 'nc-woo-integration'); ?>">
                            <?php if (!empty($account['subscriptions'])): ?>
                                <ul class="ncwi-subscription-list">
                                    <?php foreach ($account['subscriptions'] as $sub): ?>
                                        <li>
                                            <a href="<?php echo esc_url(wc_get_account_endpoint_url('view-subscription/' . $sub['id'])); ?>">
                                                #<?php echo esc_html($sub['id']); ?>
                                            </a>
                                            (<?php echo esc_html($sub['quota']); ?>)
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <span class="ncwi-no-subscriptions">
                                    <?php _e('Geen subscriptions', 'nc-woo-integration'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td data-title="<?php esc_attr_e('Acties', 'nc-woo-integration'); ?>">
                            <div class="ncwi-actions">
                                <button class="button ncwi-manage-btn" 
                                        data-account-id="<?php echo esc_attr($account['id']); ?>">
                                    <?php _e('Beheer', 'nc-woo-integration'); ?>
                                </button>
                                
                                <?php if ($account['status'] === 'active'): ?>
                                    <button class="button ncwi-refresh-btn" 
                                            data-account-id="<?php echo esc_attr($account['id']); ?>">
                                        <?php _e('Ververs', 'nc-woo-integration'); ?>
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($account['status'] === 'pending_verification'): ?>
                                    <button class="button ncwi-resend-verification-btn" 
                                            data-account-id="<?php echo esc_attr($account['id']); ?>"
                                            data-email="<?php echo esc_attr($account['nc_email']); ?>"
                                            style="background: #ff9800; color: white;">
                                        <?php _e('Verificatie opnieuw sturen', 'nc-woo-integration'); ?>
                                    </button>
                                    <button class="button ncwi-check-verification-btn" 
                                            data-account-id="<?php echo esc_attr($account['id']); ?>">
                                        <?php _e('Check status', 'nc-woo-integration'); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
    <?php endif; ?>
    
    <!-- Email Verification Notice -->
    <div class="ncwi-verification-notice" style="margin-top: 20px; padding: 15px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; display: none;">
        <h4 style="margin-top: 0;"><?php _e('Email Verificatie Informatie', 'nc-woo-integration'); ?></h4>
        <p><?php _e('De verificatie email wordt verstuurd door de Nextcloud server. Als je geen email ontvangt:', 'nc-woo-integration'); ?></p>
        <ul>
            <li><?php _e('Check je spam folder', 'nc-woo-integration'); ?></li>
            <li><?php _e('Controleer of het email adres correct is', 'nc-woo-integration'); ?></li>
            <li><?php _e('Wacht enkele minuten en probeer opnieuw', 'nc-woo-integration'); ?></li>
            <li><?php _e('Neem contact op met de beheerder als het probleem aanhoudt', 'nc-woo-integration'); ?></li>
        </ul>
    </div>
</div>

<!-- Add Account Modal -->
<div id="ncwi-add-account-modal" class="ncwi-modal" style="display: none;">
    <div class="ncwi-modal-content">
        <span class="ncwi-modal-close">&times;</span>
        <h3><?php _e('Nextcloud Account Toevoegen', 'nc-woo-integration'); ?></h3>
        
        <div class="ncwi-tabs">
            <button class="ncwi-tab-btn active" data-tab="create">
                <?php _e('Nieuw Account Aanmaken', 'nc-woo-integration'); ?>
            </button>
            <button class="ncwi-tab-btn" data-tab="link">
                <?php _e('Bestaand Account Koppelen', 'nc-woo-integration'); ?>
            </button>
        </div>
        
        <div class="ncwi-tab-content" id="ncwi-create-tab">
            <form id="ncwi-create-account-form">
                <p>
                    <label for="ncwi-new-username"><?php _e('Gebruikersnaam', 'nc-woo-integration'); ?></label>
                    <input type="text" id="ncwi-new-username" name="username" required>
                    <span class="description">
                        <?php _e('Kies een unieke gebruikersnaam voor je Nextcloud account', 'nc-woo-integration'); ?>
                    </span>
                </p>
                
                <p>
                    <label for="ncwi-new-email"><?php _e('Email', 'nc-woo-integration'); ?></label>
                    <input type="email" id="ncwi-new-email" name="email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" required>
                    <span class="description">
                        <?php _e('Je ontvangt een verificatie email op dit adres', 'nc-woo-integration'); ?>
                    </span>
                </p>

                
                
                <div class="ncwi-info-box" style="background: #e3f2fd; padding: 10px; margin: 10px 0; border-radius: 4px;">
                    <strong><?php _e('Let op:', 'nc-woo-integration'); ?></strong>
                    <?php _e('Na het aanmaken moet je je email adres verifiëren voordat je het account kunt gebruiken.', 'nc-woo-integration'); ?>
                </div>
                
                <p>
                    <button type="submit" class="button button-primary">
                        <?php _e('Account Aanmaken', 'nc-woo-integration'); ?>
                    </button>
                </p>
            </form>
        </div>
        
        <div class="ncwi-tab-content" id="ncwi-link-tab" style="display: none;">
            <form id="ncwi-link-account-form">
                <p>
                    <label for="ncwi-link-username"><?php _e('Nextcloud Gebruikersnaam', 'nc-woo-integration'); ?></label>
                    <input type="text" id="ncwi-link-username" name="username" required>
                </p>
                
                <p>
                    <label for="ncwi-link-email"><?php _e('Nextcloud Email', 'nc-woo-integration'); ?></label>
                    <input type="email" id="ncwi-link-email" name="email" required>
                </p>
                
                <p>
                    <label for="ncwi-link-password"><?php _e('Nextcloud Wachtwoord', 'nc-woo-integration'); ?></label>
                    <input type="password" id="ncwi-link-password" name="password" required>
                    <span class="description">
                        <?php _e('Je wachtwoord wordt gebruikt voor verificatie en wordt niet opgeslagen', 'nc-woo-integration'); ?>
                    </span>
                </p>
                
                <p>
                    <button type="submit" class="button button-primary">
                        <?php _e('Account Koppelen', 'nc-woo-integration'); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>
</div>

<!-- Manage Account Modal -->
<div id="ncwi-manage-account-modal" class="ncwi-modal" style="display: none;">
    <div class="ncwi-modal-content">
        <span class="ncwi-modal-close">&times;</span>
        <h3><?php _e('Account Beheren', 'nc-woo-integration'); ?></h3>
        
        <div id="ncwi-manage-account-content">
            <!-- Content loaded via AJAX -->
        </div>
    </div>
</div>