// File: assets/js/sm-register.js (drop-in hardened version)

document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('sm-registration-form');
  if (!form) return; // Not on the registration page → do nothing

  var fullnameEl        = document.getElementById('fullname');
  var emailEl           = document.getElementById('email');
  var passwordEl        = document.getElementById('password');
  var confirmPasswordEl = document.getElementById('confirm-password');

  // Optional birthday support (if present in your template)
  var dobEl       = document.getElementById('dob');        // <input type="date" id="dob">
  var dobErrorEl  = document.getElementById('dob-error');  // <div id="dob-error" class="sm-error-message"></div>

  // If any critical element is missing, don’t bind submit (avoids null errors)
  if (!fullnameEl || !emailEl || !passwordEl || !confirmPasswordEl) return;

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    clearErrors();

    var fullname        = (fullnameEl.value || '').trim();
    var email           = (emailEl.value || '').trim();
    var password        = passwordEl.value || '';
    var confirmPassword = confirmPasswordEl.value || '';
    var isValid         = true;

    // Full Name
    if (!fullname) {
      showError('fullname-error', 'Full name is required');
      isValid = false;
    } else if (fullname.length < 3) {
      showError('fullname-error', 'Full name must be at least 3 characters');
      isValid = false;
    }

    // Email
    if (!email) {
      showError('email-error', 'Email is required');
      isValid = false;
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showError('email-error', 'Please enter a valid email');
      isValid = false;
    }

    // Optional: Birthday (if the field exists)
    if (dobEl) {
      var dobVal = dobEl.value; // YYYY-MM-DD (from <input type="date">)
      if (!dobVal) {
        showError('dob-error', 'Birth date is required');
        isValid = false;
      } else {
        // Basic sanity: not in the future, not before 1900-01-01
        var ts   = Date.parse(dobVal);
        var min  = Date.parse('1900-01-01');
        var today= Date.parse(new Date().toISOString().slice(0,10));
        if (isNaN(ts) || ts < min || ts > today) {
          showError('dob-error', 'Please choose a valid birth date');
          isValid = false;
        }
      }
    }

    // Password
    if (!password) {
      showError('password-error', 'Password is required');
      isValid = false;
    } else if (password.length < 8) {
      showError('password-error', 'Password must be at least 8 characters');
      isValid = false;
    }

    // Confirm Password
    if (!confirmPassword) {
      showError('confirm-password-error', 'Please confirm your password');
      isValid = false;
    } else if (password !== confirmPassword) {
      showError('confirm-password-error', 'Passwords do not match');
      isValid = false;
    }

    // Submit if valid (let server handle final creation & redirect)
    if (isValid) {
      // If your form posts normally to PHP, just:
      form.submit();

      // If you were doing JS-only “success”:
      // alert('Registration successful!');
      // form.reset();
    }
  });

  function showError(elementId, message) {
    var el = document.getElementById(elementId);
    if (!el) return;
    el.textContent = message;
    el.style.display = 'block';
  }

  function clearErrors() {
    var errors = document.querySelectorAll('.sm-error-message');
    errors.forEach(function (err) {
      err.textContent = '';
      err.style.display = 'none';
    });
  }
});