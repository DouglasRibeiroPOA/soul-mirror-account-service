<?php
/**
 * Template: Login Form
 * Path: templates/sm-login-form.php
 */
if (!defined('ABSPATH')) exit;

// Get redirect URL with fallback
$redirect = !empty($_GET['redirect_to']) ? esc_url($_GET['redirect_to']) : home_url();
$state = !empty($_GET['state']) ? sanitize_text_field($_GET['state']) : '';

// Get lost password URL
$lost_pw_url = wp_lostpassword_url();

// Get registration URL
$register_url = wp_registration_url();

// Get the correct path to the JavaScript file
$plugin_url = plugin_dir_url(__FILE__);
// Go up two levels from templates directory to plugin root, then to assets/js
$js_url = $plugin_url . '../../assets/js/sm-login.js';
?>
<div class="sm-login-container">
  <div class="sm-login-card">
    <div class="sm-login-header">
      <h2>Sign In</h2>
      <div class="sm-alert sm-alert-error" style="display:none"></div>
    </div>

    <form class="sm-login-form" id="sm-login-form" novalidate>
      <div class="sm-form-group">
        <label for="sm_email" class="sm-form-label">Email Address</label>
        <input
          type="email"
          id="sm_email"
          name="sm_email"
          class="sm-form-input"
          required
          autocomplete="email"
          placeholder="Enter your email"
          value="<?php echo !empty($_GET['sm_email']) ? esc_attr($_GET['sm_email']) : ''; ?>">
      </div>

      <div class="sm-form-group">
        <label for="sm_password" class="sm-form-label">Password</label>
        <input
          type="password"
          id="sm_password"
          name="sm_password"
          class="sm-form-input"
          required
          autocomplete="current-password"
          placeholder="Enter your password">
      </div>

      <input type="hidden" name="sm_redirect" value="<?php echo esc_attr($redirect); ?>">
      <?php if (!empty($state)) : ?>
        <input type="hidden" name="sm_state" value="<?php echo esc_attr($state); ?>">
      <?php endif; ?>

      <div class="sm-form-group sm-form-actions">
        <button type="submit" class="sm-btn sm-btn-primary sm-btn-block">Login</button>
      </div>
    </form>

    <div class="sm-login-footer">
      <div class="sm-footer-links">
        <a href="<?php echo esc_url($lost_pw_url); ?>" class="sm-footer-link">Forgot Password?</a>
        <span class="sm-footer-separator">·</span>
        <a href="<?php echo esc_url($register_url); ?>" class="sm-footer-link">Create Account</a>
      </div>
    </div>
  </div>
</div>

<!-- Add the login JavaScript directly to ensure it loads -->
<script>
// File: assets/js/sm-login.js
// Complete login handler with proper form submission prevention

(function() {
    'use strict';
    
    // Configuration
    const config = {
        debug: true,
        cookie: {
            set: true,
            name: 'sm_jwt',
            attributes: '; path=/; Secure; SameSite=Lax'
        },
        endpoints: {
            login: window.location.origin + '/wp-json/soulmirror/v1/login'
        }
    };
    
    // Utility functions
    function $(selector) {
        return document.querySelector(selector);
    }
    
    function log() {
        if (config.debug && window.console) {
            console.log.apply(console, arguments);
        }
    }
    
    // Error handling
    function showError(message) {
        log('Displaying error:', message);
        
        // Try to find existing error container
        let errorContainer = $('.sm-alert-error');
        
        // Create one if it doesn't exist
        if (!errorContainer) {
            const header = $('.sm-login-header') || $('.sm-login-card');
            if (!header) {
                alert('Error: ' + message);
                return;
            }
            
            errorContainer = document.createElement('div');
            errorContainer.className = 'sm-alert sm-alert-error';
            header.insertBefore(errorContainer, header.firstChild);
        }
        
        // Show the error
        errorContainer.textContent = message;
        errorContainer.style.display = 'block';
        
        // Add animation for visibility
        errorContainer.classList.add('sm-shake');
        setTimeout(() => {
            errorContainer.classList.remove('sm-shake');
        }, 500);
    }
    
    function hideError() {
        const errorContainer = $('.sm-alert-error');
        if (errorContainer) {
            errorContainer.style.display = 'none';
            errorContainer.textContent = '';
        }
    }
    
    // Form handling
    function validateForm(email, password) {
        if (!email) {
            showError('Please enter your email address.');
            return false;
        }
        
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showError('Please enter a valid email address.');
            return false;
        }
        
        if (!password) {
            showError('Please enter your password.');
            return false;
        }
        
        return true;
    }
    
    function setLoadingState(button, isLoading) {
        if (!button) return;
        
        if (isLoading) {
            button.disabled = true;
            button.dataset.originalText = button.textContent;
            button.textContent = 'Signing in...';
            button.classList.add('sm-loading');
        } else {
            button.disabled = false;
            button.textContent = button.dataset.originalText || 'Login';
            button.classList.remove('sm-loading');
        }
    }
    
    // API communication
    async function loginUser(email, password) {
        log('Attempting login for:', email);
        
        try {
            const response = await fetch(config.endpoints.login, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ email, password })
            });
            
            log('Response status:', response.status);
            
            const data = await response.json().catch(() => {
                throw new Error('Invalid server response');
            });
            
            log('Response data:', data);
            
            if (!response.ok) {
                throw new Error(data.error || `Login failed (${response.status})`);
            }
            
            if (!data.token) {
                throw new Error('No authentication token received');
            }
            
            return data;
        } catch (error) {
            log('Login error:', error);
            throw error;
        }
    }
    
    // Success handling
    function handleLoginSuccess(token, redirectUrl, state) {
        log('Login successful, token received');
        
        // Set cookie if enabled
        if (config.cookie.set) {
            document.cookie = `${config.cookie.name}=${encodeURIComponent(token)}${config.cookie.attributes}`;
            log('JWT cookie set');
        }
        
        // Build final redirect URL
        let finalUrl = redirectUrl || '/';
        finalUrl = appendQueryParam(finalUrl, 'token', token);
        
        if (state) {
            finalUrl = appendQueryParam(finalUrl, 'state', state);
        }
        
        log('Redirecting to:', finalUrl);
        
        // Show success message and redirect
        if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire({
                icon: 'success',
                title: 'Welcome back!',
                text: 'Logged in successfully. Click OK to continue.',
                confirmButtonText: 'OK',
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(() => {
                window.location.href = finalUrl;
            });
        } else {
            alert('Logged in successfully. Redirecting...');
            window.location.href = finalUrl;
        }
    }
    
    function appendQueryParam(url, key, value) {
        if (!value) return url;
        
        try {
            const urlObj = new URL(url, window.location.origin);
            urlObj.searchParams.set(key, value);
            return urlObj.toString();
        } catch (e) {
            const separator = url.includes('?') ? '&' : '?';
            return `${url}${separator}${encodeURIComponent(key)}=${encodeURIComponent(value)}`;
        }
    }
    
    // Main initialization
    function initializeLoginForm() {
        const form = $('#sm-login-form');
        if (!form) {
            log('Login form not found');
            return;
        }
        
        const emailInput = $('#sm_email');
        const passwordInput = $('#sm_password');
        const redirectInput = form.querySelector('input[name="sm_redirect"]');
        const stateInput = form.querySelector('input[name="sm_state"]');
        const submitButton = form.querySelector('button[type="submit"]');
        
        if (!emailInput || !passwordInput) {
            log('Required form fields not found');
            return;
        }
        
        // Add the submit handler to the form
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            console.log('Form submission intercepted - preventing default');
            hideError();
            
            const email = emailInput.value.trim();
            const password = passwordInput.value;
            const redirectUrl = redirectInput ? redirectInput.value : '';
            const state = stateInput ? stateInput.value : '';
            
            // Validate form
            if (!validateForm(email, password)) {
                return;
            }
            
            // Set loading state
            setLoadingState(submitButton, true);
            
            try {
                // Attempt login
                const result = await loginUser(email, password);
                
                // Handle success
                handleLoginSuccess(result.token, redirectUrl, state);
            } catch (error) {
                // Handle error
                showError(error.message || 'Login failed. Please try again.');
                setLoadingState(submitButton, false);
            }
        });
        
        log('Login form initialized successfully');
        window.smLoginInitialized = true;
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeLoginForm);
    } else {
        initializeLoginForm();
    }
    
})();
</script>

<!-- Debugging script -->
<script>
// Debugging helper
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, checking login form');
    
    const form = document.getElementById('sm-login-form');
    if (!form) {
        console.error('Login form not found!');
        return;
    }
    
    console.log('Login form found:', form);
    
    // Check if form has novalidate attribute
    if (!form.hasAttribute('novalidate')) {
        console.error('Form is missing novalidate attribute!');
        form.setAttribute('novalidate', 'true');
    }
    
    // Check if our JS is loaded
    setTimeout(function() {
        if (typeof window.smLoginInitialized !== 'undefined') {
            console.log('Login JS is loaded and initialized');
        } else {
            console.error('Login JS failed to initialize');
        }
    }, 1000);
});
</script>