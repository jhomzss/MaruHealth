<?php
//register.php
include 'config.php';

$registrationSuccess = false;
$errors = [];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Sanitize and validate user input
        $firstName = filter_input(INPUT_POST, 'firstName', FILTER_SANITIZE_STRING);
        $lastName = filter_input(INPUT_POST, 'lastName', FILTER_SANITIZE_STRING);
        $middleName = filter_input(INPUT_POST, 'middleName', FILTER_SANITIZE_STRING);
        $gender = filter_input(INPUT_POST, 'gender', FILTER_SANITIZE_STRING);
        $birthday = filter_input(INPUT_POST, 'birthday', FILTER_SANITIZE_STRING);
        $address = filter_input(INPUT_POST, 'address', FILTER_SANITIZE_STRING);
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirmPassword'] ?? '';

        // Validate required fields
        if (empty($firstName)) $errors[] = "First name is required";
        if (empty($lastName)) $errors[] = "Last name is required";
        if (empty($gender)) $errors[] = "Gender is required";
        if (empty($birthday)) $errors[] = "Date of birth is required";
        if (empty($address)) $errors[] = "Address is required";
        if (empty($email)) $errors[] = "Email is required";
        if (empty($phone)) $errors[] = "Phone number is required";
        if (empty($password)) $errors[] = "Password is required";
        if (empty($confirmPassword)) $errors[] = "Password confirmation is required";

        // Validate email format
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format";
        }

        // Validate phone format (Philippines format: +639XXXXXXXXX or 09XXXXXXXXX)
        if (!empty($phone) && !preg_match('/^(\+63|0)[9][0-9]{9}$/', $phone)) {
            $errors[] = "Invalid phone number format";
        }

        // Validate password strength
        if (!empty($password) && !preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password)) {
            $errors[] = "Password must be at least 8 characters and include uppercase, lowercase, and numbers";
        }

        // Validate password confirmation
        if ($password !== $confirmPassword) {
            $errors[] = "Passwords do not match";
        }

        // Validate user age (18+ years)
        if (!empty($birthday)) {
            $birthDate = new DateTime($birthday);
            $today = new DateTime();
            $age = $today->diff($birthDate)->y;
            
            if ($age < 18) {
                $errors[] = "You must be at least 18 years old to register";
            }
        }

        // Validate file upload
        // Determine which file field has the uploaded ID based on category sections
        $fileFieldCandidates = ['self_validID_front', 'child_validID_front', 'senior_validID_front', 'validID_front']; // include legacy name for safety
        $activeFileField = null;
        foreach ($fileFieldCandidates as $candidate) {
            if (isset($_FILES[$candidate]) && is_array($_FILES[$candidate]) && $_FILES[$candidate]['error'] === UPLOAD_ERR_OK) {
                $activeFileField = $candidate;
                break;
            }
        }

        if ($activeFileField === null) {
            // Fallback: accept any uploaded file present in $_FILES even if error code is unreliable
            foreach ($_FILES as $fname => $fdata) {
                if (is_array($fdata) && !empty($fdata['tmp_name']) && (isset($fdata['size']) && (int)$fdata['size'] > 0)) {
                    $activeFileField = $fname;
                    break;
                }
            }
        }

        if ($activeFileField === null) {
            $errors[] = "Valid ID is required";
        } else {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
            $fileType = $_FILES[$activeFileField]["type"] ?? '';
            $fileSize = $_FILES[$activeFileField]["size"] ?? 0;
            $maxSize = 5 * 1024 * 1024; // 5MB

            if (!in_array($fileType, $allowedTypes)) {
                $errors[] = "Invalid file type. Please upload JPEG or PNG images only";
            }

            if ($fileSize > $maxSize) {
                $errors[] = "File size exceeds the 5MB limit";
            }
        }

        // If no errors, proceed with registration
        if (empty($errors)) {
            // Check if email or phone already exists in BOTH users AND pending_users tables
            $stmt = $conn->prepare("
                SELECT 'users' as source FROM users WHERE email = :email OR phone_number = :phone
                UNION
                SELECT 'pending_users' as source FROM pending_users WHERE email = :email OR phone_number = :phone
            ");
            $stmt->execute([':email' => $email, ':phone' => $phone]);
            
            if ($stmt->rowCount() > 0) {
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result['source'] === 'users') {
                    $errors[] = "Email or phone number is already registered with an active account";
                } else {
                    $errors[] = "Email or phone number is already pending approval";
                }
            } else {
                // Hash the password
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                // Handle file uploads
                $uploadDir = "images/uploads/IDs/"; // Ensure this directory exists and is writable
                
                // Generate unique filename to prevent overwriting
                $fileExtension = pathinfo($_FILES[$activeFileField]["name"], PATHINFO_EXTENSION);
                $newFileName = uniqid('id_') . '.' . $fileExtension;
                $validIdFrontPath = $uploadDir . $newFileName;

                if (!move_uploaded_file($_FILES[$activeFileField]["tmp_name"], $validIdFrontPath)) {
                    $errors[] = "Error uploading files. Please try again.";
                } else {
                    // Insert into pending_users table
                    $stmt = $conn->prepare("INSERT INTO pending_users 
                        (first_name, last_name, middle_name, gender, birthday, address, email, phone_number, valid_id_front, password, date_registered) 
                        VALUES 
                        (:firstName, :lastName, :middleName, :gender, :birthday, :address, :email, :phone, :validIDFront, :password, NOW())");

                    $stmt->execute([
                        ':firstName' => $firstName,
                        ':lastName' => $lastName,
                        ':middleName' => $middleName,
                        ':gender' => $gender,
                        ':birthday' => $birthday,
                        ':address' => $address,
                        ':email' => $email,
                        ':phone' => $phone,
                        ':validIDFront' => $validIdFrontPath,
                        ':password' => $hashedPassword
                    ]);

                    // Registration successful
                    $registrationSuccess = true;
                }
            }
        }
    } catch (PDOException $e) {
        $errors[] = "Registration failed: " . $e->getMessage();
    }
}
?>


<html>
<head>
    <link rel="stylesheet" href="css/register.css">
    <link rel="stylesheet" href="css/nav_footer.css">
    <link rel="stylesheet" href="css/policy_terms.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Istok+Web&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <title>Register</title>
</head>
<body>
    <nav>
        <div class="logo-container">
            <img src="images/3s logo.png">
            <div>
                <h1>Maru-Health</h1>
                <p>Barangay Marulas 3S Health Station</p>
            </div>
        </div>
    </nav>
    <div class="register-container">
        <div class="register-content">
            <div class="register-box">
                <h2 id="formTitle">REGISTRATION FORM</h2>
                <hr>
                <p>Account creation is strictly limited to individuals who are 18 years old and above. This age requirement is enforced to ensure that all users meet the legal criteria for accessing and using the system. Users must provide accurate information during registration, and may be required to present valid proof of age if necessary. Any accounts created by individuals under the age of 18 will be subject to removal.</p>
                <div class="stepper-wrapper" id="stepper">
                    <div class="stepper-item active">
                        <div class="step-counter">1</div>
                        <div class="step-name">Basic Information</div>
                    </div>
                    <div class="stepper-item">
                        <div class="step-counter">2</div>
                        <div class="step-name">Account Credentials</div>
                    </div>
                    <div class="stepper-item">
                        <div class="step-counter">3</div>
                        <div class="step-name">Review Your Information</div>
                    </div>
                </div>
                <!-- Updated form part that needs to be integrated into the register.php file -->
                <form method="POST" class="register-form" enctype="multipart/form-data" novalidate>
                    <?php if (!empty($errors)): ?>
                    <div class="error-summary">
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <!-- Step 1 -->
                    <div class="form-step active">
                        <div class="group-col">
                            <label>Registering for <span class="required">*</span></label>
                            <select id="category">
                                <option value="self" selected>Self</option> 
                                <option value="child">Child</option> 
                                <option value="senior">Senior Citizen</option>
                            </select>
                        </div>
                        <!-- Self -->
                        <div id="selfFields" class="">
                            <div class="group-col">
                                <label>Last Name <span class="required">*</span></label>
                                <input type="text" name="lastName" required value="<?= htmlspecialchars($_POST['lastName'] ?? '') ?>" autocomplete="off">
                            </div>

                            <div class="group-col">
                                <label>First Name <span class="required">*</span></label>
                                <input type="text" name="firstName" required value="<?= htmlspecialchars($_POST['firstName'] ?? '') ?>" autocomplete="off">
                            </div>

                            <div class="group-col">
                                <label>Middle Name</label>
                                <input type="text" name="middleName" value="<?= htmlspecialchars($_POST['middleName'] ?? '') ?>" autocomplete="off">
                            </div>

                            <div class="group-row">
                                <div class="group-col">
                                    <label>Gender <span class="required">*</span></label>
                                    <select name="gender" required>
                                        <option value="" disabled <?= empty($_POST['gender']) ? 'selected' : '' ?>>Select Gender</option>
                                        <option value="Male" <?= isset($_POST['gender']) && $_POST['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                                        <option value="Female" <?= isset($_POST['gender']) && $_POST['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                                    </select>
                                </div>
                                
                                <div class="group-col">
                                    <label>Date of Birth <span class="required">*</span></label>
                                    <input type="date" name="birthday" required value="<?= htmlspecialchars($_POST['birthday'] ?? '') ?>">
                                    <small class="field-hint">You must be at least 18 years old</small>
                                </div>    
                            </div>   
                            
                            <div class="group-col">
                                <label>Address <span class="required">*</span></label>
                                <input type="text" name="address" required value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" autocomplete="off">
                            </div>

                            <div class="group-col">
                                <label>Upload Valid ID <span class="required">*</span></label>
                                <div class="file-upload">
                                    <label for="file-upload" class="custom-file-upload">
                                        <i class="fas fa-cloud-upload-alt"></i> Add File
                                    </label>
                                    <input id="file-upload" type="file" name="validID_front" onchange="updateFileName()" accept="image/jpeg,image/png" required />
                                    <span id="file-name">No file chosen</span>
                                </div>
                                <small class="field-hint">Max file size: 5MB. Accepted formats: JPEG, PNG</small>
                            </div>

                            <div class="button-container">
                                <button type="button" class="next-step">Next</button>
                            </div>
                        </div>

                        <div id="childFields" class="hidden">
                            <h3>Child Information</h3>
                            <div class="group-col">
                                <label>Last Name <span class="required">*</span></label>
                                <input type="text">
                            </div>

                            <div class="group-col">
                                <label>First Name <span class="required">*</span></label>
                                <input type="text">
                            </div>

                            <div class="group-col">
                                <label>Middle Name</label>
                                <input type="text">
                            </div>

                            <div class="group-row">
                                <div class="group-col">
                                    <label>Gender <span class="required">*</span></label>
                                    <select name="gender" required>
                                        <option value="" disabled <?= empty($_POST['gender']) ? 'selected' : '' ?>>Select Gender</option>
                                        <option value="Male" <?= isset($_POST['gender']) && $_POST['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                                        <option value="Female" <?= isset($_POST['gender']) && $_POST['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                                    </select>
                                </div>
                                
                                <div class="group-col">
                                    <label>Date of Birth <span class="required">*</span></label>
                                    <input type="date">
                                </div>    
                            </div>   
                            
                            <div class="group-col">
                                <label>Address <span class="required">*</span></label>
                                <input type="text">
                            </div>

                            <div class="group-col">
                                <label>Upload School ID / Birth Certificate<span class="required">*</span></label>
                                <div class="file-upload">
                                    <label for="file-upload" class="custom-file-upload">
                                        <i class="fas fa-cloud-upload-alt"></i> Add File
                                    </label>
                                    <input id="file-upload" type="file" name="validID_front" onchange="updateFileName()" accept="image/jpeg,image/png" required />
                                    <span id="file-name">No file chosen</span>
                                </div>
                            </div>

                            <h3>II. Guardian/Authorized Registrant’s Information</h3>

                            <div class="group-col">
                                <label>Full Name <span class="required">*</span></label>
                                <input type="text">
                            </div>

                            <div class="group-col">
                                <label>Relationship <span class="required">*</span></label>
                                <input type="text">
                            </div>

                            <div class="group-col">
                                <label>Phone Number</label>
                                <input type="text">
                            </div>

                            <div class="group-col">
                                <label>Email <span class="required">*</span></label>
                                <input type="text">
                            </div>

                            <div class="group-col">
                                <label>Upload Valid ID <span class="required">*</span></label>
                                <div class="file-upload">
                                    <label for="file-upload" class="custom-file-upload">
                                        <i class="fas fa-cloud-upload-alt"></i> Add File
                                    </label>
                                    <input id="file-upload" type="file" name="validID_front" onchange="updateFileName()" accept="image/jpeg,image/png" required />
                                    <span id="file-name">No file chosen</span>
                                </div>
                                <small class="field-hint">Max file size: 5MB. Accepted formats: JPEG, PNG</small>
                            </div>
                            <div class="button-container">
                                <button type="button" class="next-step">Next</button>
                            </div>
                        </div>

                        <div id="seniorFields" class="hidden">
                            <h3>Senior Information</h3>
                            <div class="group-col">
                                <label>Last Name <span class="required">*</span></label>
                                <input type="text">
                            </div>

                            <div class="group-col">
                                <label>First Name <span class="required">*</span></label>
                                <input type="text">
                            </div>

                            <div class="group-col">
                                <label>Middle Name</label>
                                <input type="text">
                            </div>

                            <div class="group-row">
                                <div class="group-col">
                                    <label>Gender <span class="required">*</span></label>
                                    <select name="gender" required>
                                        <option value="" disabled <?= empty($_POST['gender']) ? 'selected' : '' ?>>Select Gender</option>
                                        <option value="Male" <?= isset($_POST['gender']) && $_POST['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                                        <option value="Female" <?= isset($_POST['gender']) && $_POST['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                                    </select>
                                </div>
                                
                                <div class="group-col">
                                    <label>Date of Birth <span class="required">*</span></label>
                                    <input type="date">
                                </div>    
                            </div>   
                            
                            <div class="group-col">
                                <label>Address <span class="required">*</span></label>
                                <input type="text">
                            </div>

                            <div class="group-col">
                                <label>Upload Valid ID <span class="required">*</span></label>
                                <div class="file-upload">
                                    <label for="file-upload" class="custom-file-upload">
                                        <i class="fas fa-cloud-upload-alt"></i> Add File
                                    </label>
                                    <input id="file-upload" type="file" name="validID_front" onchange="updateFileName()" accept="image/jpeg,image/png" required />
                                    <span id="file-name">No file chosen</span>
                                </div>
                                <small class="field-hint">Max file size: 5MB. Accepted formats: JPEG, PNG</small>
                            </div>

                            <h3>II. Guardian/Authorized Registrant’s Information</h3>

                            <div class="group-col">
                                <label>Full Name <span class="required">*</span></label>
                                <input type="text">
                            </div>

                            <div class="group-col">
                                <label>Relationship <span class="required">*</span></label>
                                <input type="text">
                            </div>

                            <div class="group-col">
                                <label>Phone Number</label>
                                <input type="text">
                            </div>

                            <div class="group-col">
                                <label>Email <span class="required">*</span></label>
                                <input type="text">
                            </div>

                            <div class="group-col">
                                <label>Upload Valid ID <span class="required">*</span></label>
                                <div class="file-upload">
                                    <label for="file-upload" class="custom-file-upload">
                                        <i class="fas fa-cloud-upload-alt"></i> Add File
                                    </label>
                                    <input id="file-upload" type="file" name="validID_front" onchange="updateFileName()" accept="image/jpeg,image/png" required />
                                    <span id="file-name">No file chosen</span>
                                </div>
                                <small class="field-hint">Max file size: 5MB. Accepted formats: JPEG, PNG</small>
                            </div>

                            <div class="button-container">
                                <button type="button" class="next-step">Next</button>
                            </div>

                        </div>
                    </div>

                    <!-- Step 2 -->
                    <div class="form-step">
                        <div class="group-col">
                            <label>Phone Number <span class="required">*</span></label>
                            <input type="tel" name="phone" required value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" autocomplete="off">
                            <small class="field-hint">Format: +639XXXXXXXXX or 09XXXXXXXXX</small>
                        </div>
                        <div class="group-col">
                            <label>E-mail Address <span class="required">*</span></label>
                            <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autocomplete="off">
                        </div>
                        <div class="group-col">
                            <label>Password <span class="required">*</span></label>
                            <input type="password" name="password" required>
                            <small class="field-hint">At least 8 characters with uppercase, lowercase, and numbers</small>
                        </div>
                        <div class="group-col">
                            <label>Confirm Password <span class="required">*</span></label>
                            <input type="password" name="confirmPassword" required>
                        </div>
                        
                        <div class="button-container">
                            <button type="button" class="prev-step">Back</button>
                            <button type="button" class="next-step">Next</button>
                        </div>
                    </div>

                    <!-- Step 3 -->
                    <div class="form-step">
                        <div class="user-info">
                            <div class="group-col">
                                <label>Last Name</label>
                                <span id="reviewLastName"></span>
                            </div>
                            <div class="group-col">
                                <label>First Name</label>
                                <span id="reviewFirstName"></span>
                            </div>
                            <div class="group-col">
                                <label>Middle Name</label>
                                <span id="reviewMiddleName"></span>
                            </div>
                            <div class="group-col">
                                <label>Gender</label>
                                <span id="reviewGender"></span>
                            </div>
                            <div class="group-col">
                                <label>Birthdate</label>
                                <span id="reviewBirthday"></span>
                            </div>
                            <div class="group-col">
                                <label>Address</label>
                                <span id="reviewAddress"></span>
                            </div>

                            <div class="group-col">
                                <label>E-mail Address</label>
                                <span id="reviewEmail"></span>
                            </div>
                            <div class="group-col">
                                <label>Phone Number</label>
                                <span id="reviewPhone"></span>
                            </div>
                        </div>
                        <div class="id-preview">
                            <label>Upload Valid ID</label>
                            <img id="idFrontPreview" src="" alt="Valid ID Front" style="display: none;">
                        </div>

                        <div class="policy_terms">
                            <label class="checkbox-label">
                                <input type="checkbox" name="terms" id="termsCheckbox" required>
                                I have read and accept the 
                                <a href="#" onclick="openModal('privacyModal')">Privacy Policy</a> and the
                                <a href="#" onclick="openModal('termsModal')">Terms and Conditions</a>
                            </label>
                        </div>

                        <div class="button-container">
                            <button type="button" class="prev-step">Back</button>
                            <button type="submit" class="submit-button" id="signUpBtn" disabled>Sign Up</button>
                        </div>
                    </div>
                </form>
                <p style="text-align: center; margin-top: 20px;">
                Already have an account? Go to <a href="login.php" style="color: #8B0000; font-weight: bold;">Log In</a>
                </p>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="success-modal">
        <div class="success-content">
            <div class="success-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3 class="success-title">Registration Successful!</h3>
            <p class="success-message">Thank you for registering! Your account is pending approval. You will receive a confirmation email once your account has been approved.</p>
            <button class="success-button" id="goToLoginBtn">Go to Login</button>
        </div>
    </div>

    <!-- Privacy Policy Modal -->
    <div id="privacyModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('privacyModal')">&times;</span>
            <div class="policy-terms-container">
                <div class="policy-content">
                    <h3 class="section-title">Privacy Policy</h3>
                    <p class="intro">MaruHealth is committed to protecting your privacy. This Privacy Policy explains how we collect, use, and safeguard your personal information.</p>

                    <ol>
                        <li>
                            <strong>Information We Collect</strong>
                            <ul>
                                <li>Personal Information: Name, sex, birth date, civil status, occupation, contact number, email address and 1 valid ID for proof that you are a resident of Barangay Marulas.</li>
                                <li>Medical Information: Consultation history, family folder number and family members.</li>
                                <li>Usage Data: System access logs, device information, and interactions with the platform.</li>
                            </ul>
                        </li>
                        <li>
                            <strong>How Do We Use Your Information</strong>
                            <ul>
                                <li>Providing and improving health services.</li>
                                <li>Sending notifications regarding health center updates and medicine availability.</li>
                                <li>Ensuring security and proper system functionality.</li>
                                <li>Complying with legal and regulatory requirements.</li>
                            </ul>
                        </li>
                        <li>
                            <strong>Data Sharing and Security</strong>
                            <ul>
                                <li>We do not sell or share user data with third parties, except as required by law or for health service purposes.</li>
                                <li>Personal data is encrypted and stored securely to prevent unauthorized access.</li>
                                <li>Users are responsible for keeping their login credentials confidential.</li>
                            </ul>
                        </li>
                        <li>
                            <strong>User Rights</strong>
                            <ul>
                                <li>Access and review their personal information.</li>
                                <li>Request corrections to inaccurate data.</li>
                                <li>Request deletion of their data, subject to legal and operational requirements.</li>
                            </ul>
                        </li>
                        <li>
                            <strong>Contact Information</strong>
                            <p>For privacy-related concerns, please contact Barangay Marulas 3S Health Center.<br>
                            By using MaruHealth, you acknowledge and agree to this Privacy Policy.</p>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <!-- Terms and  Condition Modal -->
    <div id="termsModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('termsModal')">&times;</span>
            <div class="policy-terms-container">
                <div class="policy-content">
                    <h3 class="section-title">Terms & Conditions</h3>
                    <p class="intro">Welcome to MaruHealth, by accessing and using this system, you agree to comply with and be bound by the following terms and conditions. Please read them carefully.</p>

                    <ol>
                        <li>
                            <strong>Acceptance of Terms</strong>
                            <p>By using MaruHealth, you acknowledge that you have read, understood, and agreed to these terms. If you do not agree with any part of these terms, you must discontinue use of the system.</p>
                        </li>

                        <li>
                            <strong>User Registration & Responsibilities</strong>
                            <ul>
                                <li>Users must provide accurate and complete information during registration.</li>
                                <li>Each user is responsible for maintaining the confidentiality of their account credentials.</li>
                                <li>Unauthorized access or use of another user's account is strictly prohibited.</li>
                            </ul>
                        </li>

                        <li>
                            <strong>Services Provided</strong>
                            <p>MaruHealth offers the following features:</p>
                            <ul>
                                <li>Checking health center schedules, announcements and health-related events.</li>
                                <li>Online medicine request.</li>
                                <li>Receiving notifications regarding the status of the requested medicine.</li>
                            </ul>
                        </li>

                        <li>
                            <strong>Privacy and Data Protection</strong>
                            <ul>
                                <li>MaruHealth values user privacy and ensures that personal data is protected in accordance with applicable data protection laws.</li>
                                <li>User information will only be used for health services management purposes.</li>
                            </ul>
                        </li>

                        <li>
                            <strong>Acceptable Use</strong>
                            <p>Users agree to:</p>
                            <ul>
                                <li>Use MaruHealth only for lawful purposes.</li>
                                <li>Refrain from transmitting any malicious software, hacking attempts, or engaging in unauthorized access.</li>
                                <li>Not disrupt the operation of the system or compromise its security.</li>
                            </ul>
                        </li>

                        <li>
                            <strong>Limitation of Liability</strong>
                            <ul>
                                <li>MaruHealth and its administrators are not liable for any damages or losses incurred due to misuse, system downtimes, or incorrect information provided by users.</li>
                                <li>The system does not replace professional medical consultations.</li>
                            </ul>
                        </li>

                        <li>
                            <strong>Termination of Access</strong>
                            <p>MaruHealth may suspend or terminate user access if there is a violation of these terms. Any misuse or unauthorized activity may lead to account deactivation or legal action.</p>
                        </li>

                        <li>
                            <strong>Governing Law</strong>
                            <p>These terms are governed by the laws of the Philippines, and any disputes shall be resolved within the appropriate legal jurisdiction.</p>
                        </li>

                        <li>
                            <strong>Contact Information</strong>
                            <p>For any inquiries or concerns regarding these terms, please contact Barangay Marulas 3S Health Center.</p>
                        </li>
                    </ol>

                    <p class="closing">By using MaruHealth, you acknowledge and agree to these terms and conditions.<br>Thank you for using our system responsibly.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        const category = document.getElementById("category");
        const selfFields = document.getElementById("selfFields");
        const childFields = document.getElementById("childFields");
        const seniorFields = document.getElementById("seniorFields");

        // Ensure only ONE file input (in selected category) is enabled and named "validID_front"
        function setFileFieldForVisibleSection() {
            const map = { self: selfFields, child: childFields, senior: seniorFields };
            const selected = category.value in map ? map[category.value] : selfFields;

            // 1) Globally sanitize all file inputs: remove name/required and disable
            document.querySelectorAll('.file-upload input[type="file"]').forEach(input => {
                input.removeAttribute('name');
                input.removeAttribute('required');
                input.setAttribute('disabled', 'disabled');
            });

            // 2) In the selected section, pick the first file input as authoritative
            if (selected) {
                const inputs = selected.querySelectorAll('input[type="file"]');
                if (inputs.length > 0) {
                    const primary = inputs[0];
                    primary.removeAttribute('disabled');
                    primary.setAttribute('name', 'validID_front');
                    primary.setAttribute('required', 'required');
                    // Ensure any other file inputs in this section remain disabled and unnamed
                    inputs.forEach((inp, idx) => {
                        if (idx === 0) return;
                        inp.removeAttribute('name');
                        inp.setAttribute('disabled', 'disabled');
                    });
                }
            }
        }


        category.addEventListener("change", function() {
            selfFields.classList.add("hidden");
            childFields.classList.add("hidden");
            seniorFields.classList.add("hidden");

            if (this.value === "self") {
            selfFields.classList.remove("hidden");
            } else if (this.value === "child") {
            childFields.classList.remove("hidden");
            } else if (this.value === "senior") {
            seniorFields.classList.remove("hidden");
            }

            // Sync file input attributes for the currently visible section
            setFileFieldForVisibleSection();
        });

        document.addEventListener("DOMContentLoaded", function () {
            // Initial sync on load
            setFileFieldForVisibleSection();
            // Form Step Navigation
            let currentStep = 0;
            const steps = document.querySelectorAll(".form-step");
            const nextBtns = document.querySelectorAll(".next-step");
            const prevBtns = document.querySelectorAll(".prev-step");
            const stepperItems = document.querySelectorAll(".stepper-item");
            
            // File Upload Elements
            const fileInput = document.getElementById('file-upload');
            const fileNameSpan = document.getElementById('file-name');
            const idFrontInput = document.querySelector('input[name="validID_front"]');
            const idFrontPreview = document.getElementById("idFrontPreview");
            
            // Form Fields
            const formFields = {
                firstName: {
                    element: document.querySelector('input[name="firstName"]'),
                    errorMsg: "First name is required",
                    validator: (value) => value.trim().length > 0
                },
                lastName: {
                    element: document.querySelector('input[name="lastName"]'),
                    errorMsg: "Last name is required",
                    validator: (value) => value.trim().length > 0
                },
                middleName: {
                    element: document.querySelector('input[name="middleName"]'),
                    errorMsg: "",
                    validator: () => true // Optional field
                },
                gender: {
                    element: document.querySelector('select[name="gender"]'),
                    errorMsg: "Please select a gender",
                    validator: (value) => value !== ""
                },
                birthday: {
                    element: document.querySelector('input[name="birthday"]'),
                    errorMsg: "Date of birth is required",
                    validator: (value) => {
                        if (!value) return false;
                        
                        // Check if user is at least 18 years old
                        const today = new Date();
                        const birthDate = new Date(value);
                        let age = today.getFullYear() - birthDate.getFullYear();
                        const monthDiff = today.getMonth() - birthDate.getMonth();
                        
                        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                            age--;
                        }
                        
                        return age >= 18;
                    },
                    customErrorMsg: (value) => {
                        if (!value) return "Date of birth is required";
                        
                        // Calculate age for custom message
                        const today = new Date();
                        const birthDate = new Date(value);
                        let age = today.getFullYear() - birthDate.getFullYear();
                        const monthDiff = today.getMonth() - birthDate.getMonth();
                        
                        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                            age--;
                        }
                        
                        return age < 18 ? "You must be at least 18 years old to register" : "";
                    }
                },
                address: {
                    element: document.querySelector('input[name="address"]'),
                    errorMsg: "Address is required",
                    validator: (value) => value.trim().length > 0
                },
                phone: {
                    element: document.querySelector('input[name="phone"]'),
                    errorMsg: "Phone number is required",
                    validator: (value) => {
                        // Philippines phone number validation (format: +639XXXXXXXXX or 09XXXXXXXXX)
                        const phoneRegex = /^(\+63|0)[9][0-9]{9}$/;
                        return phoneRegex.test(value.trim());
                    },
                    customErrorMsg: (value) => {
                        if (!value.trim()) return "Phone number is required";
                        return "Please enter a valid Philippines phone number (format: +639XXXXXXXXX or 09XXXXXXXXX)";
                    }
                },
                email: {
                    element: document.querySelector('input[name="email"]'),
                    errorMsg: "Email is required",
                    validator: (value) => {
                        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        return emailRegex.test(value.trim());
                    },
                    customErrorMsg: (value) => {
                        if (!value.trim()) return "Email is required";
                        return "Please enter a valid email address";
                    }
                },
                password: {
                    element: document.querySelector('input[name="password"]'),
                    errorMsg: "Password is required",
                    validator: (value) => {
                        // Password validation: at least 8 characters, 1 uppercase, 1 lowercase, 1 number
                        const passwordRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/;
                        return passwordRegex.test(value);
                    },
                    customErrorMsg: (value) => {
                        if (!value) return "Password is required";
                        return "Password must be at least 8 characters and include uppercase, lowercase, and numbers";
                    }
                },
                confirmPassword: {
                    element: document.querySelector('input[name="confirmPassword"]'),
                    errorMsg: "Please confirm your password",
                    validator: (value) => {
                        const password = document.querySelector('input[name="password"]').value;
                        return value === password && value.length > 0;
                    },
                    customErrorMsg: (value) => {
                        if (!value) return "Please confirm your password";
                        return "Passwords do not match";
                    }
                },
                validID_front: {
                    element: document.querySelector('input[name="validID_front"]'),
                    errorMsg: "Please upload a valid ID",
                    validator: (value) => {
                        const fileInput = document.querySelector('input[name="validID_front"]');
                        return fileInput.files && fileInput.files.length > 0;
                    }
                }
            };

            // Add "required" attribute to all required fields
            Object.values(formFields).forEach(field => {
                if (field.errorMsg && field.element) {
                    field.element.setAttribute("required", "true");
                }
            });

            // File input change handler
            fileInput.addEventListener('change', function () {
                if (fileInput.files.length > 0) {
                    const file = fileInput.files[0];
                    fileNameSpan.textContent = file.name;
                    
                    // Validate file type and size
                    const validImageTypes = ['image/jpeg', 'image/png', 'image/jpg'];
                    const maxFileSize = 5 * 1024 * 1024; // 5MB
                    
                    if (!validImageTypes.includes(file.type)) {
                        showFieldError(fileInput, "Please upload a valid image file (JPEG, PNG)");
                        fileInput.value = "";
                        fileNameSpan.textContent = "No file chosen";
                        return;
                    }
                    
                    if (file.size > maxFileSize) {
                        showFieldError(fileInput, "File size exceeds 5MB limit");
                        fileInput.value = "";
                        fileNameSpan.textContent = "No file chosen";
                        return;
                    }
                    
                    // Remove error if valid
                    removeFieldError(fileInput);
                    
                } else {
                    fileNameSpan.textContent = 'No file chosen';
                }
            });

            // Preview ID image when selected
            idFrontInput.addEventListener("change", function () {
                if (this.files.length > 0) {
                    idFrontPreview.src = URL.createObjectURL(this.files[0]);
                    idFrontPreview.style.display = "block";
                } else {
                    idFrontPreview.src = "";
                    idFrontPreview.style.display = "none";
                }
            });

            // Error handling functions
            function showFieldError(element, message) {
                removeFieldError(element);
                
                const errorSpan = document.createElement("span");
                errorSpan.className = "field-error";
                errorSpan.textContent = message;
                errorSpan.style.color = "#FF0000";
                errorSpan.style.fontSize = "12px";
                
                // Add red border to highlight the field
                element.style.borderColor = "#FF0000";
                
                // Insert error message after the field
                element.parentNode.appendChild(errorSpan);
            }

            function removeFieldError(element) {
                // Remove existing error messages
                const existingError = element.parentNode.querySelector(".field-error");
                if (existingError) {
                    existingError.remove();
                }
                
                // Reset border color
                element.style.borderColor = "";
            }

            // Function to validate a specific form step
            function validateStep(step) {
                let isValid = true;
                const currentStepElement = steps[step];
                
                // Get all input and select elements in current step
                const inputs = currentStepElement.querySelectorAll("input, select");
                
                // Clear all previous errors
                inputs.forEach(input => removeFieldError(input));
                
                // Validate each field (only visible ones)
                inputs.forEach(input => {
                    // Skip inputs contained within elements hidden by class 'hidden'
                    if (input.closest('.hidden')) return;

                    const fieldName = input.getAttribute("name");
                    if (!fieldName || !formFields[fieldName]) return;
                    
                    const field = formFields[fieldName];
                    const value = input.value;
                    
                    if (!field.validator(value)) {
                        isValid = false;
                        const errorMsg = field.customErrorMsg ? field.customErrorMsg(value) : field.errorMsg;
                        showFieldError(input, errorMsg);
                    }
                });
                
                // Special check for password confirmation
                if (step === 1) {
                    const password = document.querySelector('input[name="password"]').value;
                    const confirmPassword = document.querySelector('input[name="confirmPassword"]').value;
                    
                    if (password !== confirmPassword && confirmPassword.length > 0) {
                        isValid = false;
                        showFieldError(document.querySelector('input[name="confirmPassword"]'), "Passwords do not match");
                    }
                }
                
                return isValid;
            }

            // Function to check if email or phone exists
            async function checkDuplicate(field, value) {
                if (!value.trim()) return true; // Skip if empty

                try {
                    const formData = new FormData();
                    formData.append(field, value);

                    const response = await fetch('check_availability.php', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.exists) {
                        showFieldError(formFields[field].element, result.message);
                        return false;
                    } else {
                        removeFieldError(formFields[field].element);
                        return true;
                    }
                } catch (error) {
                    showFieldError(formFields[field].element, 'Error checking availability');
                    return false;
                }
            }

            // Update step indicator and form visibility
            function updateStep(step) {
                steps.forEach((s, i) => {
                    s.classList.toggle("active", i === step);
                });

                stepperItems.forEach((item, index) => {
                    item.classList.remove("active", "completed");

                    if (index < step) {
                        item.classList.add("completed");
                    } else if (index === step) {
                        item.classList.add("active");
                    }
                });

                // Populate review step
                if (step === 2) {
                    populateReviewStep();
                }
            }

            // Populate the review step with user data
            function populateReviewStep() {
                document.getElementById("reviewFirstName").textContent = document.querySelector('input[name="firstName"]').value;
                document.getElementById("reviewLastName").textContent = document.querySelector('input[name="lastName"]').value;
                document.getElementById("reviewMiddleName").textContent = document.querySelector('input[name="middleName"]').value;
                document.getElementById("reviewGender").textContent = document.querySelector('select[name="gender"]').value;
                document.getElementById("reviewBirthday").textContent = document.querySelector('input[name="birthday"]').value;
                document.getElementById("reviewAddress").textContent = document.querySelector('input[name="address"]').value;
                document.getElementById("reviewEmail").textContent = document.querySelector('input[name="email"]').value;
                document.getElementById("reviewPhone").textContent = document.querySelector('input[name="phone"]').value;

                // Show uploaded ID images
                if (idFrontInput.files.length > 0) {
                    idFrontPreview.src = URL.createObjectURL(idFrontInput.files[0]);
                    idFrontPreview.style.display = "block";
                } else {
                    idFrontPreview.style.display = "none";
                }
            }

            // Add event listeners for next/prev buttons
            nextBtns.forEach((btn) => {
                btn.addEventListener("click", async function () {
                    let isValid = validateStep(currentStep);

                    // For step 1 (contains email and phone), check duplicates
                    if (currentStep === 1) {
                        const emailValid = await checkDuplicate('email', formFields.email.element.value);
                        const phoneValid = await checkDuplicate('phone', formFields.phone.element.value);
                        isValid = isValid && emailValid && phoneValid;
                    }

                    if (isValid && currentStep < steps.length - 1) {
                        currentStep++;
                        updateStep(currentStep);
                    }
                });
            });

            prevBtns.forEach((btn) => {
                btn.addEventListener("click", function () {
                    if (currentStep > 0) {
                        currentStep--;
                        updateStep(currentStep);
                    }
                });
            });

            // Terms checkbox and signup button
            const termsCheckbox = document.getElementById("termsCheckbox");
            const signUpBtn = document.getElementById("signUpBtn");

            termsCheckbox.addEventListener("change", function () {
                signUpBtn.disabled = !this.checked;
            });

            // Real-time validation for email and phone
            ['email', 'phone'].forEach(fieldName => {
                const field = formFields[fieldName];
                if (field.element) {
                    field.element.addEventListener('blur', async function() {
                        if (field.validator(this.value)) {
                            await checkDuplicate(fieldName, this.value);
                        } else {
                            const errorMsg = field.customErrorMsg ? field.customErrorMsg(this.value) : field.errorMsg;
                            showFieldError(this, errorMsg);
                        }
                    });

                    // Optional: Real-time validation on input for immediate feedback
                    field.element.addEventListener('input', async function() {
                        if (field.validator(this.value)) {
                            await checkDuplicate(fieldName, this.value);
                        }
                    });
                }
            });

            // Add input validation on blur for other fields
            Object.keys(formFields).forEach(fieldName => {
                if (fieldName !== 'email' && fieldName !== 'phone' && formFields[fieldName].element) {
                    formFields[fieldName].element.addEventListener('blur', function() {
                        if (!formFields[fieldName].validator(this.value)) {
                            const errorMsg = formFields[fieldName].customErrorMsg ? 
                                formFields[fieldName].customErrorMsg(this.value) : 
                                formFields[fieldName].errorMsg;
                            showFieldError(this, errorMsg);
                        } else {
                            removeFieldError(this);
                        }
                    });
                }
            });

            // Initialize form
            updateStep(currentStep);

            // Ensure correct file input naming/enabling before submit
            const formEl = document.querySelector('form.register-form');
            if (formEl) {
                formEl.addEventListener('submit', function() {
                    setFileFieldForVisibleSection();
                });
            }
        });

        // Modal functions
        function openModal(id) {
            let modal = document.getElementById(id);
            modal.classList.add("show");
        }

        function closeModal(id) {
            let modal = document.getElementById(id);
            modal.classList.remove("show");
        }

        const registrationSuccess = <?= $registrationSuccess ? 'true' : 'false' ?>;
        if (registrationSuccess) {
            document.getElementById("successModal").style.display = "flex";
        }

        document.getElementById("goToLoginBtn").addEventListener("click", function () {
            window.location.href = "login.php";
        });
    </script>

</body>
</html>
