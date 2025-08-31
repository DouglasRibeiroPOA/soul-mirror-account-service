  <div class="sm-registration-container">
        <div class="sm-registration-header">
            <h2>Join Soul Mirror</h2>
            <p>Create your account to begin your journey</p>
        </div>
        
        <div class="sm-registration-form">
            <div class="sm-error-server" id="smServerError"></div>
            
            <form id="smRegisterForm">
                <div class="sm-form-group">
                    <label for="smFullName">Full Name</label>
                    <input type="text" id="smFullName" name="full_name" required>
                    <i class="fas fa-user"></i>
                    <div class="sm-error-message" id="smFullNameError">Please enter your full name</div>
                </div>
                
                <div class="sm-form-group">
                    <label for="smEmail">Email Address</label>
                    <input type="email" id="smEmail" name="email" required>
                    <i class="fas fa-envelope"></i>
                    <div class="sm-error-message" id="smEmailError">Please enter a valid email address</div>
                </div>
                
                <div class="sm-form-group">
                    <label for="smDateOfBirth">Date of Birth</label>
                    <input type="date" id="smDateOfBirth" name="date_of_birth" required>
                    <i class="fas fa-calendar-days"></i>
                    <div class="sm-error-message" id="smDobError">Please enter your date of birth</div>
                </div>
                
                <div class="sm-form-group">
                    <label for="smPassword">Password</label>
                    <input type="password" id="smPassword" name="password" required>
                    <i class="fas fa-lock"></i>
                    <div class="sm-error-message" id="smPasswordError">Password must be at least 8 characters with uppercase, lowercase, and number</div>
                </div>
                
                <div class="sm-form-group">
                    <label for="smConfirmPassword">Confirm Password</label>
                    <input type="password" id="smConfirmPassword" name="confirm_password" required>
                    <i class="fas fa-lock"></i>
                    <div class="sm-error-message" id="smConfirmPasswordError">Passwords do not match</div>
                </div>
                
                <button type="submit" class="sm-submit-btn">
                    <span class="sm-button-text">Create Account</span>
                </button>
            </form>
            
            <div class="sm-form-footer">
                Already have an account? <a href="/login">Sign In</a>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="sm-modal-overlay" id="successModal">
        <div class="sm-modal">
            <div class="sm-modal-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3>Registration Successful!</h3>
            <p>Your account has been created successfully. You will be redirected to the login page shortly.</p>
            <button class="sm-modal-btn" id="modalOkButton">OK</button>
        </div>
    </div>