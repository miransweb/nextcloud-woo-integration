jQuery(document).ready(function($) {
    'use strict';
    
    // Modal handlers
    const addAccountModal = $('#ncwi-add-account-modal');
    const manageAccountModal = $('#ncwi-manage-account-modal');
    
    // Show add account modal
    $('#ncwi-add-account').on('click', function() {
        addAccountModal.show();
    });
    
    // Close modals
    $('.ncwi-modal-close').on('click', function() {
        $(this).closest('.ncwi-modal').hide();
    });
    
    // Close modal on outside click
    $(window).on('click', function(e) {
        if ($(e.target).hasClass('ncwi-modal')) {
            $(e.target).hide();
        }
    });
    
    // Tab switching
    $('.ncwi-tab-btn').on('click', function() {
        const tab = $(this).data('tab');
        
        $('.ncwi-tab-btn').removeClass('active');
        $(this).addClass('active');
        
        $('.ncwi-tab-content').hide();
        $('#ncwi-' + tab + '-tab').show();
    });
    
    // Create account form
    $('#ncwi-create-account-form').on('submit', function(e) {
        e.preventDefault();
        
        const form = $(this);
        const submitBtn = form.find('button[type="submit"]');
        const originalText = submitBtn.text();
        
        submitBtn.prop('disabled', true).text(ncwi_ajax.i18n.processing);
        
        $.ajax({
            url: ncwi_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ncwi_create_account',
                nonce: ncwi_ajax.nonce,
                username: form.find('#ncwi-new-username').val(),
                email: form.find('#ncwi-new-email').val()
            },
            success: function(response) {
                if (response.success) {
                    // Check if verification is pending
                    if (response.data && response.data.status === 'pending_verification') {
                        showNotice(response.data.message || 'Check your email for the verification link.', 'info');
                        $('.ncwi-verification-notice').show();
                    } else {
                        showNotice(response.data.message || 'Account successfully created.', 'success');
                    }
                    
                    addAccountModal.hide();
                    form[0].reset();
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    showNotice(response.data, 'error');
                }
            },
            error: function() {
                showNotice(ncwi_ajax.i18n.error, 'error');
            },
            complete: function() {
                submitBtn.prop('disabled', false).text(originalText);
            }
        });
    });
    
    // Link account form
    $('#ncwi-link-account-form').on('submit', function(e) {
        e.preventDefault();
        
        const form = $(this);
        const submitBtn = form.find('button[type="submit"]');
        const originalText = submitBtn.text();
        
        submitBtn.prop('disabled', true).text(ncwi_ajax.i18n.processing);
        
        $.ajax({
            url: ncwi_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ncwi_link_account',
                nonce: ncwi_ajax.nonce,
                username: form.find('#ncwi-link-username').val(),
                email: form.find('#ncwi-link-email').val(),
                password: form.find('#ncwi-link-password').val()
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    addAccountModal.hide();
                    form[0].reset();
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    showNotice(response.data, 'error');
                }
            },
            error: function() {
                showNotice(ncwi_ajax.i18n.error, 'error');
            },
            complete: function() {
                submitBtn.prop('disabled', false).text(originalText);
            }
        });
    });
    
    // Resend verification email
    $('.ncwi-resend-verification-btn').on('click', function() {
        const btn = $(this);
        const accountId = btn.data('account-id');
        const email = btn.data('email');
        const originalText = btn.text();
        
        btn.prop('disabled', true).text(ncwi_ajax.i18n.processing);
        
        $.ajax({
            url: ncwi_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ncwi_resend_verification',
                nonce: ncwi_ajax.nonce,
                account_id: accountId,
                email: email
            },
            success: function(response) {
                if (response.success) {
                    showNotice('Verification email resend. Check your inbox!', 'success');
                    $('.ncwi-verification-notice').show();
                } else {
                    showNotice(response.data || 'Could not send email verification', 'error');
                }
            },
            error: function() {
                showNotice(ncwi_ajax.i18n.error, 'error');
            },
            complete: function() {
                btn.prop('disabled', false).text(originalText);
            }
        });
    });
    
    // Check verification status
    $('.ncwi-check-verification-btn').on('click', function() {
        const btn = $(this);
        const accountId = btn.data('account-id');
        const originalText = btn.text();
        
        btn.prop('disabled', true).text('Checking...');
        
        $.ajax({
            url: ncwi_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ncwi_check_verification',
                nonce: ncwi_ajax.nonce,
                account_id: accountId
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.verified) {
                        showNotice('Email is verified! Page is being refreshed...', 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotice('Email not yet verified. Check your inbox for the verification link.', 'info');
                    }
                } else {
                    showNotice(response.data || 'Could not check verification status', 'error');
                }
            },
            error: function() {
                showNotice(ncwi_ajax.i18n.error, 'error');
            },
            complete: function() {
                btn.prop('disabled', false).text(originalText);
            }
        });
    });
    
    // Manage account - UPDATED
    $('.ncwi-manage-btn').on('click', function() {
        const btn = $(this);
        const accountId = btn.data('account-id');
        
        // Prevent multiple clicks
        if (btn.hasClass('loading')) {
            return;
        }
        
        btn.addClass('loading');
        
        $('#ncwi-manage-account-content').html('<p>' + ncwi_ajax.i18n.processing + '</p>');
        manageAccountModal.show();
        
        $.ajax({
            url: ncwi_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ncwi_get_account_details',
                nonce: ncwi_ajax.nonce,
                account_id: accountId
            },
            success: function(response) {
                if (response.success) {
                    renderManageAccountContent(response.data);
                } else {
                    $('#ncwi-manage-account-content').html(
                        '<p class="ncwi-error">' + response.data + '</p>'
                    );
                }
            },
            error: function() {
                $('#ncwi-manage-account-content').html(
                    '<p class="ncwi-error">' + ncwi_ajax.i18n.error + '</p>'
                );
            },
            complete: function() {
                btn.removeClass('loading');
            }
        });
    });
    
    // Refresh account - NIEUW: ALLEEN RELOAD
    $('.ncwi-refresh-btn').on('click', function() {
        // Just reload the page
        location.reload();
    });
    
    // Debounce function to prevent rapid clicks
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    // Render manage account content
    function renderManageAccountContent(data) {
        const account = data.account;
        const availableSubs = data.available_subscriptions;
        
        let html = '<div class="ncwi-account-details">';
        
        // Account info
        html += '<h4>Account information</h4>';
        html += '<table class="ncwi-info-table">';
        html += '<tr><td><strong>Username:</strong></td><td>' + account.nc_username + '</td></tr>';
        html += '<tr><td><strong>Email:</strong></td><td>' + account.nc_email + '</td></tr>';
        html += '<tr><td><strong>Server:</strong></td><td><a href="' + account.nc_server + '" target="_blank">' + account.nc_server + '</a></td></tr>';
        html += '<tr><td><strong>Status:</strong></td><td>' + account.status + '</td></tr>';
        
        // Add verification status
        if (account.status === 'pending_verification') {
            html += '<tr><td><strong>Email verification:</strong></td><td style="color: #ff9800;">Awaits verification</td></tr>';
        } else if (account.status === 'active') {
            html += '<tr><td><strong>Email verification:</strong></td><td style="color: #4caf50;">Verified ✔</td></tr>';
        }
        
        if (account.current_quota) {
            html += '<tr><td><strong>Quota:</strong></td><td>' + account.used_space + ' / ' + account.current_quota + '</td></tr>';
        }
        
        if (account.last_login) {
            html += '<tr><td><strong>Last login:</strong></td><td>' + account.last_login + '</td></tr>';
        }
        
        html += '</table>';
        
        // Verification actions for pending accounts
        if (account.status === 'pending_verification') {
            html += '<div class="ncwi-verification-actions" style="margin: 20px 0; padding: 15px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px;">';
            html += '<h4>Email verification required</h4>';
            html += '<p>This account awaits email verification. Check your inbox for verification link.</p>';
            html += '<button class="button ncwi-resend-verification-modal-btn" data-account-id="' + account.id + '" data-email="' + account.nc_email + '">Resend verification</button>';
            html += ' <button class="button ncwi-check-verification-modal-btn" data-account-id="' + account.id + '">Check status</button>';
            html += '</div>';
        }
        
        // Linked subscriptions
        if (account.subscriptions && account.subscriptions.length > 0) {
            html += '<h4>Linked subscriptions</h4>';
            html += '<table class="ncwi-subscriptions-table">';
            html += '<thead><tr><th>ID</th><th>Status</th><th>Quota</th><th>Actions</th></tr></thead>';
            html += '<tbody>';
            
            account.subscriptions.forEach(function(sub) {
                html += '<tr>';
                html += '<td>#' + sub.id + '</td>';
                html += '<td>' + sub.status + '</td>';
                html += '<td>' + sub.quota + '</td>';
                html += '<td><button class="button ncwi-unlink-sub-btn" data-subscription-id="' + sub.id + '" data-account-id="' + account.id + '">Unlink</button></td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
        }
        
        // Available subscriptions to link
        if (availableSubs && availableSubs.length > 0 && account.status === 'active') {
            html += '<h4>Available subscriptions</h4>';
            html += '<p>Link a subscription to this account:</p>';
            html += '<select id="ncwi-link-subscription-select" style="margin-bottom:15px;">';
            html += '<option value="">-- Select a subscription --</option>';
            
            availableSubs.forEach(function(sub) {
                html += '<option value="' + sub.id + '">' + sub.name + ' (Status: ' + sub.status + ')</option>';
            });
            
            html += '</select>';
            html += ' <button class="button" id="ncwi-link-subscription-btn" data-account-id="' + account.id + '">Link</button>';
        }
        
        // Actions
        html += '<div class="ncwi-account-actions">';
        
        if (account.status !== 'unlinked') {
            html += '<button class="button ncwi-unlink-account-btn" data-account-id="' + account.id + '">Unlink account</button> ';
        }
        
        if (!account.subscriptions || account.subscriptions.length === 0) {
            html += '<button class="button ncwi-delete-account-btn" data-account-id="' + account.id + '">Delete account</button>';
        }
        
        html += '</div>';
        html += '</div>';
        
        $('#ncwi-manage-account-content').html(html);
        
        // Bind event handlers
        bindManageAccountHandlers();
    }
    
    // Bind manage account handlers
    function bindManageAccountHandlers() {
        // Resend verification from modal
        $('.ncwi-resend-verification-modal-btn').on('click', function() {
            const btn = $(this);
            const accountId = btn.data('account-id');
            const email = btn.data('email');
            const originalText = btn.text();
            
            btn.prop('disabled', true).text('Versturen...');
            
            $.ajax({
                url: ncwi_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ncwi_resend_verification',
                    nonce: ncwi_ajax.nonce,
                    account_id: accountId,
                    email: email
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('Verification email resend!', 'success');
                    } else {
                        showNotice(response.data || 'Could not send verification email', 'error');
                    }
                },
                complete: function() {
                    btn.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Check verification from modal
        $('.ncwi-check-verification-modal-btn').on('click', function() {
            const btn = $(this);
            const accountId = btn.data('account-id');
            const originalText = btn.text();
            
            btn.prop('disabled', true).text('Checking...');
            
            $.ajax({
                url: ncwi_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ncwi_check_verification',
                    nonce: ncwi_ajax.nonce,
                    account_id: accountId
                },
                success: function(response) {
                    if (response.success && response.data.verified) {
                        showNotice('Email has been verified!', 'success');
                        manageAccountModal.hide();
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotice('Email not yet verified.', 'info');
                    }
                },
                complete: function() {
                    btn.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Link subscription - NIEUW: FIRE & FORGET
        $('#ncwi-link-subscription-btn').on('click', function() {
            const accountId = $(this).data('account-id');
            const subscriptionId = $('#ncwi-link-subscription-select').val();
            
            if (!subscriptionId) {
                alert('Select a subscription');
                return;
            }
            
            // Just fire the AJAX and reload - don't wait for response
            $.ajax({
                url: ncwi_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ncwi_link_subscription',
                    nonce: ncwi_ajax.nonce,
                    account_id: accountId,
                    subscription_id: subscriptionId
                }
            });
            
            // Close modal and reload immediately
            manageAccountModal.hide();
            showNotice('Subscription is being linked...', 'info');
            
            // Small delay to ensure AJAX request is sent
            setTimeout(function() {
                location.reload();
            }, 100);
        });
        
        // Unlink subscription
        $('.ncwi-unlink-sub-btn').on('click', function() {
            if (!confirm(ncwi_ajax.i18n.confirm_unlink)) {
                return;
            }
            
            const btn = $(this);
            const accountId = btn.data('account-id');
            const subscriptionId = btn.data('subscription-id');
            
            btn.prop('disabled', true).text(ncwi_ajax.i18n.processing);
            
            $.ajax({
                url: ncwi_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ncwi_unlink_subscription',
                    nonce: ncwi_ajax.nonce,
                    account_id: accountId,
                    subscription_id: subscriptionId
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                        manageAccountModal.hide();
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        showNotice(response.data, 'error');
                        btn.prop('disabled', false).text('Ontkoppelen');
                    }
                },
                error: function() {
                    showNotice(ncwi_ajax.i18n.error, 'error');
                    btn.prop('disabled', false).text('Ontkoppelen');
                }
            });
        });
        
        // Unlink account
        $('.ncwi-unlink-account-btn').on('click', function() {
            if (!confirm(ncwi_ajax.i18n.confirm_unlink)) {
                return;
            }
            
            const accountId = $(this).data('account-id');
            
            $.ajax({
                url: ncwi_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ncwi_unlink_account',
                    nonce: ncwi_ajax.nonce,
                    account_id: accountId
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                        manageAccountModal.hide();
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        showNotice(response.data, 'error');
                    }
                },
                error: function() {
                    showNotice(ncwi_ajax.i18n.error, 'error');
                }
            });
        });
        
        // Delete account
        $('.ncwi-delete-account-btn').on('click', function() {
            if (!confirm(ncwi_ajax.i18n.confirm_delete)) {
                return;
            }
            
            const accountId = $(this).data('account-id');
            
            $.ajax({
                url: ncwi_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ncwi_delete_account',
                    nonce: ncwi_ajax.nonce,
                    account_id: accountId
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                        manageAccountModal.hide();
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        showNotice(response.data, 'error');
                    }
                },
                error: function() {
                    showNotice(ncwi_ajax.i18n.error, 'error');
                }
            });
        });
    }
    
    // Show notice
    function showNotice(message, type) {
        const noticeClass = type === 'success' ? 'woocommerce-message' : 
                          type === 'error' ? 'woocommerce-error' : 
                          'woocommerce-info';
        const notice = $('<div class="' + noticeClass + '" role="alert">' + message + '</div>');
        
        $('.ncwi-accounts-wrapper').before(notice);
        
        $('html, body').animate({
            scrollTop: notice.offset().top - 100
        }, 300);
        
        setTimeout(function() {
            notice.fadeOut(function() {
                notice.remove();
            });
        }, 5000);
    }
});