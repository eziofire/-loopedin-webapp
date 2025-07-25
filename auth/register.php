<?php
include '../config/db.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];

    // Basic validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        die("Invalid email.");
    }
    if (strlen($password) < 6) {
        die("Password must be ≥ 6 characters.");
    }

    // Check duplicate email
    $dup = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $dup->bind_param("s", $email);
    $dup->execute();
    if ($dup->get_result()->num_rows) {
        die("Email already registered.");
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare(
        "INSERT INTO users (name, email, password) VALUES (?, ?, ?)"
    );
    $stmt->bind_param("sss", $name, $email, $hash);
    $stmt->execute();

    header("Location: ../index.php?registered=1");
    exit();
}
?>
