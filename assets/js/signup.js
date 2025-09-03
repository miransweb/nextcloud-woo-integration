jQuery(document).ready(function($) {
    'use strict';
    
    function updateProgress(step, status) {
        var $step = $('#step-' + step);
        var $icon = $step.find('.ncwi-step-icon');
        
        if (status === 'active') {
            $step.addClass('active').removeClass('complete');
            $icon.addClass('spinning').html('<span class="spinner"></span>');
        } else if (status === 'complete') {
            $step.removeClass('active').addClass('complete');
            $icon.removeClass('spinning').html('<span class="checkmark">✓</span>');
        }
        
        // Update progress bar
        var totalSteps = $('.ncwi-step').length;
        var completedSteps = $('.ncwi-step.complete').length;
        var activeSteps = $('.ncwi-step.active').length;
        var progress = ((completedSteps + (activeSteps * 0.5)) / totalSteps) * 100;
        
        $('.ncwi-progress-bar-fill').css('width', progress + '%');
    }
    
    function showProgress() {
        $('#ncwi-signup-progress').fadeIn();
        $('#ncwi-direct-signup').addClass('processing');
        
        // Start with validation
        setTimeout(function() {
            updateProgress('validate', 'active');
        }, 100);
    }
    
    function hideProgress() {
        $('#ncwi-signup-progress').fadeOut();
        $('#ncwi-direct-signup').removeClass('processing');
    }
    
    // Simulate progress steps
    function simulateProgress() {
        // Validation complete after 500ms
        setTimeout(function() {
            updateProgress('validate', 'complete');
            updateProgress('shop', 'active');
        }, 500);
        
        // Shop account after 2s
        setTimeout(function() {
            updateProgress('shop', 'complete');
            updateProgress('nextcloud', 'active');
        }, 2000);
        
        // Nextcloud setup indication after 4s
        setTimeout(function() {
            updateProgress('nextcloud', 'active');
            $('.ncwi-progress-message').text(ncwi_signup.i18n.nextcloud_setup);
        }, 4000);
    }
    
    // Handle signup form submission
    $('#ncwi-direct-signup').on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $button = $('#ncwi-signup-btn');
        var $messages = $('#ncwi-signup-messages');
        
        // Validate passwords match
        if ($('#signup_password').val() !== $('#signup_password2').val()) {
            showMessage('error', ncwi_signup.i18n.password_mismatch);
            return;
        }
        
        // Disable button and show progress
        $button.prop('disabled', true).text(ncwi_signup.i18n.processing);
        $messages.hide();
        
        // Show progress indicator
        showProgress();
        simulateProgress();
        
        // Submit form
        $.ajax({
            url: ncwi_signup.ajax_url,
            type: 'POST',
            data: $form.serialize() + '&action=ncwi_direct_signup',
            success: function(response) {
                if (response.success) {
                    // Complete all steps
                    updateProgress('nextcloud', 'complete');
                    updateProgress('email', 'complete');
                    updateProgress('complete', 'active');
                    
                    setTimeout(function() {
                        updateProgress('complete', 'complete');
                        $('.ncwi-progress-message').text(response.data.message);
                        
                        // Redirect after showing success
                        setTimeout(function() {
                            window.location.href = response.data.redirect;
                        }, 1500);
                    }, 500);
                } else {
                    hideProgress();
                    showMessage('error', response.data.message);
                    $button.prop('disabled', false).text(ncwi_signup.i18n.create_account);
                }
            },
            error: function() {
                hideProgress();
                showMessage('error', ncwi_signup.i18n.error);
                $button.prop('disabled', false).text(ncwi_signup.i18n.create_account);
            }
        });
    });
    
    // Helper function to show messages
    function showMessage(type, message) {
        var $messages = $('#ncwi-signup-messages');
        var className = type === 'success' ? 'woocommerce-message' : 'woocommerce-error';
        
        $messages.html('<div class="' + className + '" role="alert">' + message + '</div>').show();
        
        // Scroll to message
        $('html, body').animate({
            scrollTop: $messages.offset().top - 100
        }, 300);
    }
    
    // Password strength indicator (optional)
    $('#signup_password').on('keyup', function() {
        var password = $(this).val();
        var strength = checkPasswordStrength(password);
        
        // You can add a visual indicator here if needed
    });
    
    function checkPasswordStrength(password) {
        var strength = 0;
        
        if (password.length >= 8) strength++;
        if (password.match(/[a-z]+/)) strength++;
        if (password.match(/[A-Z]+/)) strength++;
        if (password.match(/[0-9]+/)) strength++;
        if (password.match(/[$@#&!]+/)) strength++;
        
        return strength;
    }
});