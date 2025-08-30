document.getElementById('sm-registration-form').addEventListener('submit', function (e) {
    e.preventDefault();

    // Reset error messages
    clearErrors();

    // Get values
    const fullname = document.getElementById('fullname').value.trim();
    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm-password').value;

    // Validate
    let isValid = true;

    // Full Name validation
    if (!fullname) {
        showError('fullname-error', 'Full name is required');
        isValid = false;
    }

    // Email validation
    if (!email) {
        showError('email-error', 'Email is required');
        isValid = false;
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showError('email-error', 'Please enter a valid email');
        isValid = false;
    }

    // Password validation
    if (!password) {
        showError('password-error', 'Password is required');
        isValid = false;
    } else if (password.length < 8) {
        showError('password-error', 'Password must be at least 8 characters');
        isValid = false;
    }

    // Confirm Password validation
    if (!confirmPassword) {
        showError('confirm-password-error', 'Please confirm your password');
        isValid = false;
    } else if (password !== confirmPassword) {
        showError('confirm-password-error', 'Passwords do not match');
        isValid = false;
    }

    // If valid, submit form
    if (isValid) {
        // Here you would typically send data to server
        alert('Registration successful!');
        this.reset();
    }
});

function showError(elementId, message) {
    const element = document.getElementById(elementId);
    element.textContent = message;
    element.style.display = 'block';
}

function clearErrors() {
    const errors = document.querySelectorAll('.sm-error-message');
    errors.forEach(error => {
        error.textContent = '';
        error.style.display = 'none';
    });
}