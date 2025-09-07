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
            login: window.location.origin + '/wp-json/soulmirror/v1/login',
            session: window.location.origin + '/wp-json/soulmirror/v1/session' // check session on Account domain
        },
        // Optional: keep this in sync with your PHP whitelist
        allowedHosts: [
            'palmreading.vitalguideshop.com',
            'aura.vitalguideshop.com',
            'soul-mirror.local',
            'palm-reading.local'
        ]
    };

    // Utils
    function $(sel) { return document.querySelector(sel); }
    function log() { if (config.debug && window.console) console.log.apply(console, arguments); }

    function getQueryParam(name) {
        const url = new URL(window.location.href);
        return url.searchParams.get(name);
    }

    function hostFrom(urlStr) {
        try { return new URL(urlStr, window.location.origin).host.toLowerCase(); }
        catch { return ''; }
    }

    function isWhitelisted(urlStr) {
        const host = hostFrom(urlStr);
        if (!host) return true; // relative allowed
        if (host === window.location.host.toLowerCase()) return true; // same-origin
        if (!Array.isArray(config.allowedHosts)) return false;
        return config.allowedHosts.map(h => h.toLowerCase()).includes(host);
    }

    function pickRedirect(hiddenValue) {
        // Priority: hidden field → ?redirect → ?redirect_uri → document.referrer → "/"
        const qRedirect = getQueryParam('redirect');
        const qRedirectUri = getQueryParam('redirect_uri');
        let candidate = hiddenValue || qRedirect || qRedirectUri || '';

        if (!candidate && document.referrer) {
            // Use referrer ONLY if same-origin or whitelisted
            if (isWhitelisted(document.referrer)) candidate = document.referrer;
        }

        return candidate || '/';
    }

    // =========================
    // Error handling (fixed)
    // =========================
    function showError(message) {
        log('Displaying error:', message);

        let errorContainer = document.getElementById('smServerError');

        if (!errorContainer) {
            const formWrapper = document.querySelector('.sm-login-form') || $('#sm-login-form')?.parentElement;
            if (!formWrapper) { alert(String(message || 'An error occurred.')); return; }
            errorContainer = document.createElement('div');
            errorContainer.id = 'smServerError';
            errorContainer.className = 'sm-error-server';
            formWrapper.insertBefore(errorContainer, formWrapper.firstChild);
        }

        errorContainer.innerHTML =
            '<span class="sm-error-icon">⚠️</span>' + String(message || 'Something went wrong');

        errorContainer.classList.add('sm-error-server', 'is-visible', 'has-content');
        errorContainer.style.display = 'block';

        errorContainer.classList.remove('sm-shake');
        void errorContainer.offsetWidth;
        errorContainer.classList.add('sm-shake');
        setTimeout(() => errorContainer.classList.remove('sm-shake'), 500);
    }

    function hideError() {
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
        if (!email) { showError('Please enter your email address.'); return false; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showError('Please enter a valid email address.'); return false; }
        if (!password) { showError('Please enter your password.'); return false; }
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
            const data = await response.json().catch(() => { throw new Error('Invalid server response'); });
            log('Response data:', data);
            if (!response.ok) throw new Error(data.error || `Login failed (${response.status})`);
            if (!data.token) throw new Error('No authentication token received');
            return data;
        } catch (error) {
            log('Login error:', error);
            throw error;
        }
    }

    // Success handling
    function handleLoginSuccess(token, redirectUrl, state) {
        log('Login successful, token received');

        if (config.cookie.set) {
            document.cookie = `${config.cookie.name}=${encodeURIComponent(token)}${config.cookie.attributes}`;
            log('JWT cookie set');
        }

        let finalUrl = pickRedirect(redirectUrl);
        finalUrl = appendQueryParam(finalUrl, 'token', token);
        if (state) finalUrl = appendQueryParam(finalUrl, 'state', state);

        log('Redirecting to:', finalUrl);

        if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire({
                icon: 'success',
                title: 'Welcome back!',
                text: 'Logged in successfully. Click OK to continue.',
                confirmButtonText: 'OK',
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(() => { window.location.href = finalUrl; });
        } else {
            window.location.href = finalUrl;
        }
    }

    function appendQueryParam(url, key, value) {
        if (!value) return url;
        try { const urlObj = new URL(url, window.location.origin); urlObj.searchParams.set(key, value); return urlObj.toString(); }
        catch (e) { const sep = url.includes('?') ? '&' : '?'; return `${url}${sep}${encodeURIComponent(key)}=${encodeURIComponent(value)}`; }
    }

    // Auto-bounce if already logged into the Account Service
    async function tryAutoBounce(redirectUrlRaw, state) {
        const redirectUrl = pickRedirect(redirectUrlRaw);
        try {
            const r = await fetch(config.endpoints.session, { credentials: 'include' });
            if (!r.ok) return;
            const data = await r.json();
            if (!data.logged_in || !data.token) return;

            let finalUrl = appendQueryParam(redirectUrl, 'token', data.token);
            if (state) finalUrl = appendQueryParam(finalUrl, 'state', state);

            log('Auto-bounce: already logged in, redirecting to', finalUrl);
            window.location.href = finalUrl;
        } catch (e) {
            log('Auto-bounce check failed (ignored):', e);
        }
    }

    // Main initialization (now async so we can await tryAutoBounce)
    async function initializeLoginForm() {
        const form = $('#sm-login-form');
        if (!form) { log('Login form not found'); return; }

        const redirectInput = form.querySelector('input[name="sm_redirect"]');
        const stateInput    = form.querySelector('input[name="sm_state"]');
        const redirectUrl   = redirectInput ? redirectInput.value : '';
        const state         = stateInput ? stateInput.value : '';

        // Await auto-bounce before wiring up the form
        await tryAutoBounce(redirectUrl, state);

        const emailInput    = $('#sm_email');
        const passwordInput = $('#sm_password');
        const submitButton  = form.querySelector('button[type="submit"]');

        if (!emailInput || !passwordInput) { log('Required form fields not found'); return; }

        resetLoadingStates();

        // Remove any existing event listeners to prevent duplicates
        const newForm = form.cloneNode(true);
        form.parentNode.replaceChild(newForm, form);

        // Re-select references on the cloned form
        const newEmailInput  = $('#sm_email');
        const newPasswordInput = $('#sm_password');
        const newRedirectInput = newForm.querySelector('input[name="sm_redirect"]');
        const newStateInput    = newForm.querySelector('input[name="sm_state"]');
        const newSubmitButton  = newForm.querySelector('button[type="submit"]');

        newForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            hideError();

            const email = newEmailInput.value.trim();
            const password = newPasswordInput.value;
            const redirectUrl2 = newRedirectInput ? newRedirectInput.value : '';
            const state2 = newStateInput ? newStateInput.value : '';

            if (!validateForm(email, password)) return;

            setLoadingState(newSubmitButton, true);

            try {
                const result = await loginUser(email, password);
                handleLoginSuccess(result.token, redirectUrl2, state2);
            } catch (error) {
                showError(error.message || 'Login failed. Please try again.');
                setLoadingState(newSubmitButton, false);
            }
        });

        log('Login form initialized successfully');
        window.smLoginInitialized = true;
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => { initializeLoginForm(); });
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
