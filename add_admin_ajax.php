<?php
// add_admin_ajax.php
session_start();
require_once "config.php";

// Check if the user is logged in and is a super admin
if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'super_admin') {
    echo "Unauthorized access";
    exit();
}

// Process only if it's a POST request
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fullName = trim($_POST['full_name']);
    $role = $_POST['role'];
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $confirmPassword = trim($_POST['confirm_password']);
    
    // Validation
    $errors = [];
    
    if (empty($fullName)) {
        $errors[] = "Full name is required";
    }
    
    if (empty($role) || !in_array($role, ['admin', 'staff'])) {
        $errors[] = "Valid role is required";
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Valid email is required";
    }
    
    if (empty($username)) {
        $errors[] = "Username is required";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match";
    }
    
    // Check if email or username already exists
    $checkStmt = $conn->prepare("SELECT * FROM admin_staff WHERE email = :email OR username = :username");
    $checkStmt->bindParam(':email', $email);
    $checkStmt->bindParam(':username', $username);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() > 0) {
        $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if ($existingUser['email'] == $email) {
            $errors[] = "Email already in use";
        }
        if ($existingUser['username'] == $username) {
            $errors[] = "Username already in use";
        }
    }
    
    // If no errors, add the admin
    if (empty($errors)) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        // Normalize role label to match dashboards and login routing
        $dbRole = ($role === 'staff') ? 'health_staff' : $role;

        $insertStmt = $conn->prepare("INSERT INTO admin_staff (full_name, role, email, username, password) VALUES (:fullName, :role, :email, :username, :password)");
        $insertStmt->bindParam(':fullName', $fullName);
        $insertStmt->bindParam(':role', $dbRole);
        $insertStmt->bindParam(':email', $email);
        $insertStmt->bindParam(':username', $username);
        $insertStmt->bindParam(':password', $hashedPassword);
        
        if ($insertStmt->execute()) {
            echo "Admin added successfully!";
        } else {
            echo "Failed to add admin. Please try again.";
        }
    } else {
        // Return the first error
        echo $errors[0];
    }
} else {
    echo "Invalid request method";
}
?>