// File: assets/js/sm-login.js
// Complete login handler with proper form submission prevention

(function () {
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

    // =========================
    // Error handling (fixed)
    // =========================
    function showError(message) {
        log('Displaying error:', message);

        // Use the styled server-error box if present
        let errorContainer = document.getElementById('smServerError');

        // If the template didn't render it, create one and insert at top of form
        if (!errorContainer) {
            const formWrapper = document.querySelector('.sm-login-form') || $('#sm-login-form')?.parentElement;
            if (!formWrapper) {
                // Absolute fallback
                alert(String(message || 'An error occurred.'));
                return;
            }
            errorContainer = document.createElement('div');
            errorContainer.id = 'smServerError';
            errorContainer.className = 'sm-error-server';
            formWrapper.insertBefore(errorContainer, formWrapper.firstChild);
        }

        // Put the message INTO the styled box
        errorContainer.innerHTML =
            '<span class="sm-error-icon">⚠️</span>' + String(message || 'Something went wrong');

        // Mark it as having content so CSS shows it
        errorContainer.classList.add('sm-error-server', 'is-visible', 'has-content');
        errorContainer.style.display = 'block';

        // One-time attention animation
        errorContainer.classList.remove('sm-shake'); // reset if previously added
        void errorContainer.offsetWidth;             // reflow to restart animation
        errorContainer.classList.add('sm-shake');
        setTimeout(() => errorContainer.classList.remove('sm-shake'), 500);
    }

    function hideError() {
        // Prefer our styled box; fall back to any legacy alert container
        const errorContainer =
            document.getElementById('smServerError') ||
            document.querySelector('.sm-alert-error');

        if (errorContainer) {
            errorContainer.classList.remove('is-visible', 'has-content');
            errorContainer.style.display = 'none';
            errorContainer.textContent = '';
        }
    }


    // Reset loading states (fix for back button issue)
    function resetLoadingStates() {
        const forms = document.querySelectorAll('#sm-login-form');
        forms.forEach(form => {
            const buttons = form.querySelectorAll('button[type="submit"]');
            buttons.forEach(button => {
                button.disabled = false;
                button.classList.remove('sm-loading');
                if (button.dataset.originalText) {
                    button.textContent = button.dataset.originalText;
                }
            });
        });
        log('Loading states reset');
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
            button.classList.add('sm-loading'); // harmless (spinner hidden by CSS)
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
                headers: { 'Content-Type': 'application/json' },
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
        if (state) finalUrl = appendQueryParam(finalUrl, 'state', state);

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

        // Reset any existing loading states (fix for back button)
        resetLoadingStates();

        // Remove any existing event listeners to prevent duplicates
        const newForm = form.cloneNode(true);
        form.parentNode.replaceChild(newForm, form);

        // Get references to the new form elements
        const newEmailInput = $('#sm_email');
        const newPasswordInput = $('#sm_password');
        const newRedirectInput = newForm.querySelector('input[name="sm_redirect"]');
        const newStateInput = newForm.querySelector('input[name="sm_state"]');
        const newSubmitButton = newForm.querySelector('button[type="submit"]');

        // Add the submit handler to the new form
        newForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            log('Form submission intercepted - preventing default');
            hideError();

            const email = newEmailInput.value.trim();
            const password = newPasswordInput.value;
            const redirectUrl = newRedirectInput ? newRedirectInput.value : '';
            const state = newStateInput ? newStateInput.value : '';

            // Validate form
            if (!validateForm(email, password)) return;

            // Set loading state
            setLoadingState(newSubmitButton, true);

            try {
                // Attempt login
                const result = await loginUser(email, password);

                // Handle success
                handleLoginSuccess(result.token, redirectUrl, state);
            } catch (error) {
                // Handle error
                showError(error.message || 'Login failed. Please try again.');
                setLoadingState(newSubmitButton, false);
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

    // Reset loading states when page is shown (for back/forward navigation)
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            log('Page shown from back/forward cache - resetting states');
            resetLoadingStates();
        }
    });

})();
