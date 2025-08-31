document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('smRegisterForm');
    const emailInput = document.getElementById('smEmail');
    const passwordInput = document.getElementById('smPassword');
    const confirmPasswordInput = document.getElementById('smConfirmPassword');
    const fullNameInput = document.getElementById('smFullName');
    const dobInput = document.getElementById('smDateOfBirth');
    const serverError = document.getElementById('smServerError');
    const submitBtn = form.querySelector('.sm-submit-btn');
    const buttonText = submitBtn.querySelector('.sm-button-text');
    const successModal = document.getElementById('successModal');
    const modalOkButton = document.getElementById('modalOkButton');

    // Email validation
    emailInput.addEventListener('blur', function () {
        if (!validateEmail(emailInput.value)) {
            showError(emailInput, document.getElementById('smEmailError'));
        } else {
            hideError(emailInput, document.getElementById('smEmailError'));
        }
    });

    // Password validation
    passwordInput.addEventListener('blur', function () {
        if (!validatePassword(passwordInput.value)) {
            showError(passwordInput, document.getElementById('smPasswordError'));
        } else {
            hideError(passwordInput, document.getElementById('smPasswordError'));
        }

        // Check if passwords match when confirm password has value
        if (confirmPasswordInput.value) {
            if (passwordInput.value !== confirmPasswordInput.value) {
                showError(confirmPasswordInput, document.getElementById('smConfirmPasswordError'));
            } else {
                hideError(confirmPasswordInput, document.getElementById('smConfirmPasswordError'));
            }
        }
    });

    // Confirm password validation
    confirmPasswordInput.addEventListener('blur', function () {
        if (passwordInput.value !== confirmPasswordInput.value) {
            showError(confirmPasswordInput, document.getElementById('smConfirmPasswordError'));
        } else {
            hideError(confirmPasswordInput, document.getElementById('smConfirmPasswordError'));
        }
    });

    // Full name validation
    fullNameInput.addEventListener('blur', function () {
        if (!fullNameInput.value.trim()) {
            showError(fullNameInput, document.getElementById('smFullNameError'));
        } else {
            hideError(fullNameInput, document.getElementById('smFullNameError'));
        }
    });

    // Date of birth validation
    dobInput.addEventListener('blur', function () {
        if (!dobInput.value) {
            showError(dobInput, document.getElementById('smDobError'));
        } else {
            hideError(dobInput, document.getElementById('smDobError'));
        }
    });

    // Form submission
    form.addEventListener('submit', function (e) {
        e.preventDefault();

        // Reset previous errors
        hideAllErrors();

        let isValid = true;

        // Validate all fields
        if (!fullNameInput.value.trim()) {
            showError(fullNameInput, document.getElementById('smFullNameError'));
            isValid = false;
        }

        if (!validateEmail(emailInput.value)) {
            showError(emailInput, document.getElementById('smEmailError'));
            isValid = false;
        }

        if (!dobInput.value) {
            showError(dobInput, document.getElementById('smDobError'));
            isValid = false;
        }

        if (!validatePassword(passwordInput.value)) {
            showError(passwordInput, document.getElementById('smPasswordError'));
            isValid = false;
        }

        if (passwordInput.value !== confirmPasswordInput.value) {
            showError(confirmPasswordInput, document.getElementById('smConfirmPasswordError'));
            isValid = false;
        }

        if (isValid) {
            // Prepare form data
            const formData = {
                email: emailInput.value,
                password: passwordInput.value,
                full_name: fullNameInput.value,
                date_of_birth: dobInput.value
            };

            // Show loading state
            const loadingSpinner = document.createElement('span');
            loadingSpinner.className = 'sm-loading';
            buttonText.textContent = ' Creating Account...';
            submitBtn.prepend(loadingSpinner);
            submitBtn.disabled = true;

            // Submit the form via AJAX
            fetch('https://soul-mirror.local/wp-json/soulmirror/v1/register', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(formData)
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Server response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    // Show success modal
                    successModal.classList.add('active');
                    form.reset();
                })
                .catch(error => {
                    // Show error message
                    serverError.textContent = 'Registration failed. Please try again later.';
                    serverError.style.display = 'block';
                    console.error('Error:', error);
                })
                .finally(() => {
                    // Restore button state
                    const spinner = submitBtn.querySelector('.sm-loading');
                    if (spinner) {
                        spinner.remove();
                    }
                    buttonText.textContent = 'Create Account';
                    submitBtn.disabled = false;
                });
        }
    });

    // Modal OK button handler
    modalOkButton.addEventListener('click', function () {
        successModal.classList.remove('active');
        // Redirect to login page
        window.location.href = '/login'; // Change to your login page URL
    });

    // Helper functions
    function validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    function validatePassword(password) {
        // At least 8 characters, 1 uppercase, 1 lowercase, 1 number
        const re = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/;
        return re.test(password);
    }

    function showError(input, errorElement) {
        input.style.borderColor = '#FF5252';
        errorElement.style.display = 'block';
    }

    function hideError(input, errorElement) {
        input.style.borderColor = '#E0E0E0';
        errorElement.style.display = 'none';
    }

    function hideAllErrors() {
        const errorElements = document.querySelectorAll('.sm-error-message');
        errorElements.forEach(element => {
            element.style.display = 'none';
        });

        const inputs = document.querySelectorAll('#smRegisterForm input');
        inputs.forEach(input => {
            input.style.borderColor = '#E0E0E0';
        });

        serverError.style.display = 'none';
    }
});
