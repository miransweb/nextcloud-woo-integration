jQuery(document).ready(function($) {
    'use strict';
    
    // Test API connection
    $('.ncwi-test-connection').on('click', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const originalText = button.text();
        
        button.prop('disabled', true)
              .html(originalText + ' <span class="ncwi-spinner"></span>');
        
        // Redirect to test connection
        window.location.href = button.attr('href');
    });
    
    // Toggle password visibility
    $('.ncwi-toggle-password').on('click', function() {
        const input = $(this).siblings('input');
        const type = input.attr('type') === 'password' ? 'text' : 'password';
        input.attr('type', type);
        
        $(this).text(type === 'password' ? 'Show' : 'Hide');
    });
    
    // Settings form validation
    $('#ncwi-settings-form').on('submit', function(e) {
        let valid = true;
        let errors = [];
        
        // Validate API URL
        const apiUrl = $('#ncwi_deployer_api_url').val();
        if (apiUrl && !isValidUrl(apiUrl)) {
            errors.push('Deployer API URL is not a valid URL');
            valid = false;
        }
        
        // Validate Nextcloud URL
        const ncUrl = $('#ncwi_nextcloud_api_url').val();
        if (ncUrl && !isValidUrl(ncUrl)) {
            errors.push('Nextcloud API URL is not a valid URL');
            valid = false;
        }
        
        // Validate quota format
        const defaultQuota = $('#ncwi_default_quota').val();
        const trialQuota = $('#ncwi_trial_quota').val();
        
        if (defaultQuota && !isValidQuota(defaultQuota)) {
            errors.push('Default quota format is invalid (use format like 5GB, 100MB)');
            valid = false;
        }
        
        if (trialQuota && !isValidQuota(trialQuota)) {
            errors.push('Trial quota format is invalid (use format like 1GB, 500MB)');
            valid = false;
        }
        
        if (!valid) {
            e.preventDefault();
            alert('Please fix the following errors:\n\n' + errors.join('\n'));
        }
    });
    
    // Helper function to validate URL
    function isValidUrl(string) {
        try {
            new URL(string);
            return true;
        } catch (_) {
            return false;
        }
    }
    
    // Helper function to validate quota format
    function isValidQuota(quota) {
        const pattern = /^\d+(\.\d+)?\s*(B|KB|MB|GB|TB)$/i;
        return pattern.test(quota.trim());
    }
    
    // Auto-save indicator
    let saveTimeout;
    $('input, select, textarea').on('change', function() {
        clearTimeout(saveTimeout);
        
        const $indicator = $('#ncwi-autosave-indicator');
        if ($indicator.length === 0) {
            $('<span id="ncwi-autosave-indicator" style="margin-left: 10px; color: #666;">Changes detected</span>')
                .insertAfter('.submit button');
        }
        
        saveTimeout = setTimeout(function() {
            $('#ncwi-autosave-indicator').fadeOut(function() {
                $(this).remove();
            });
        }, 3000);
    });
    
    // Sync interval change warning
    $('#ncwi_sync_interval').on('change', function() {
        const value = $(this).val();
        let message = '';
        
        if (value === 'hourly') {
            message = 'Hourly sync may increase server load. Recommended for high-traffic sites only.';
        } else if (value === 'daily') {
            message = 'Daily sync may result in delayed quota updates.';
        }
        
        if (message) {
            const $warning = $(this).siblings('.ncwi-sync-warning');
            if ($warning.length === 0) {
                $('<p class="ncwi-sync-warning" style="color: #d54e21; margin-top: 5px;">' + message + '</p>')
                    .insertAfter($(this).siblings('.description'));
            } else {
                $warning.text(message);
            }
        }
    });
    
    // Copy to clipboard functionality
    $('.ncwi-copy-to-clipboard').on('click', function() {
        const text = $(this).data('copy');
        const $temp = $('<input>');
        
        $('body').append($temp);
        $temp.val(text).select();
        document.execCommand('copy');
        $temp.remove();
        
        const $this = $(this);
        const originalText = $this.text();
        
        $this.text('Copied!');
        setTimeout(function() {
            $this.text(originalText);
        }, 2000);
    });
    
    // Collapsible sections
    $('.ncwi-collapsible').on('click', function() {
        const $content = $(this).next('.ncwi-collapsible-content');
        $content.slideToggle();
        
        $(this).toggleClass('active');
    });
});