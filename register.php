<?php
include 'config/db.php'; // Remove '../' since config is subdirectory
session_start();

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];

    // Basic validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be ≥ 6 characters.";
    } else {
        // Check duplicate email
        $dup = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $dup->bind_param("s", $email);
        $dup->execute();
        if ($dup->get_result()->num_rows) {
            $error = "Email already registered.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                "INSERT INTO users (name, email, password) VALUES (?, ?, ?)"
            );
            $stmt->bind_param("sss", $name, $email, $hash);
            $stmt->execute();

            header("Location: index.php?registered=1"); // Remove '../'
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Register – LoopedIn</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Google Fonts: Inter & Lexend for modern look -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Lexend:wght@700&display=swap" rel="stylesheet">
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      min-height: 100vh;
      background: #0a0a0f;
      font-family: 'Inter', Arial, sans-serif;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .register-card {
      border: none;
      border-radius: 1.5rem;
      box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.22);
      background: rgba(24,26,37,0.98);
      padding: 2.5rem 2rem 2rem 2rem;
      max-width: 370px;
      width: 100%;
      margin: 0 auto;
      animation: fadeInUp 0.8s cubic-bezier(.5,1.5,.5,1) 0.1s both;
    }
    .register-title {
      font-family: 'Lexend', 'Inter', Arial, sans-serif;
      font-weight: 700;
      color: #fff;
      font-size: 2.1rem;
      letter-spacing: 1px;
      margin-bottom: 1.2rem;
      text-shadow: 0 2px 8px #2e2e4a44;
    }
    .form-label {
      font-family: 'Inter', Arial, sans-serif;
      font-weight: 600;
      color: #e0e0e7;
      letter-spacing: 0.5px;
    }
    .form-control {
      font-family: 'Inter', Arial, sans-serif;
      font-size: 1rem;
      border-radius: 0.7rem;
      background: #191a23;
      color: #fff;
      border: 1.5px solid #26273a;
      transition: border-color 0.2s;
    }
    .form-control:focus {
      border-color: #ffb44c;
      box-shadow: 0 0 0 0.15rem #ffb44c33;
      background: #222236;
      color: #fff;
    }
    .btn-primary {
      font-family: 'Lexend', Arial, sans-serif;
      background: linear-gradient(90deg, #ffb44c 0%, #ff4c60 100%);
      border: none;
      color: #fff;
      font-weight: 700;
      font-size: 1.1rem;
      letter-spacing: 1px;
      border-radius: 0.7rem;
      box-shadow: 0 4px 12px rgba(255, 180, 76, 0.10);
      transition: background 0.2s, transform 0.15s;
    }
    .btn-primary:hover {
      background: linear-gradient(90deg, #ff4c60 0%, #ffb44c 100%);
      transform: scale(1.04);
      color: #fff;
    }
    .login-link {
      color: #ffb44c;
      text-decoration: none;
      font-family: 'Inter', Arial, sans-serif;
      font-weight: 600;
      transition: color 0.2s;
    }
    .login-link:hover {
      color: #ff4c60;
      text-decoration: underline;
    }
    .error-alert {
      background: rgba(255, 76, 96, 0.1);
      border: 1px solid rgba(255, 76, 96, 0.2);
      border-radius: 0.7rem;
      color: #ff4c60;
      font-size: 0.9rem;
      font-weight: 500;
    }
    /* Animations */
    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(40px);}
      to { opacity: 1; transform: translateY(0);}
    }
    .habit-icon-anim {
      display: block;
      margin: 0 auto 1.2rem auto;
      width: 60px;
      height: 60px;
      animation: bounceIn 1s cubic-bezier(.5,1.5,.5,1);
      filter: drop-shadow(0 2px 8px #ffb44c44);
    }
    @keyframes bounceIn {
      0% { transform: scale(0.7) translateY(-30px); opacity: 0;}
      60% { transform: scale(1.1) translateY(10px); opacity: 1;}
      80% { transform: scale(0.95) translateY(-5px);}
      100% { transform: scale(1) translateY(0);}
    }
    .login-section {
      margin-top: 2.2rem;
      color: #e0e0e7;
      font-size: 1rem;
      text-align: center;
      opacity: 1;
      transition: opacity 0.2s;
      min-height: 1.5em;
      display: block;
    }
    @media (max-width: 400px) {
      .register-card { padding: 1.5rem 0.5rem 1.2rem 0.5rem; }
      .habit-icon-anim { width: 40px; height: 40px; }
      .register-title { font-size: 1.25rem; }
      .login-section { font-size: 0.95rem; }
    }
  </style>
</head>
<body>
  <div class="register-card">
    <div class="text-center mb-3">
      <svg class="habit-icon-anim" viewBox="0 0 64 64" fill="none">
        <rect x="8" y="14" width="48" height="36" rx="8" fill="#191a23" stroke="#ffb44c" stroke-width="2.5"/>
        <rect x="8" y="26" width="48" height="24" rx="4" fill="#ffb44c" />
        <rect x="18" y="32" width="6" height="6" rx="2" fill="#ff4c60">
          <animate attributeName="y" values="32;28;32" dur="1.2s" repeatCount="indefinite"/>
        </rect>
        <rect x="30" y="32" width="6" height="6" rx="2" fill="#fff"/>
        <rect x="42" y="32" width="6" height="6" rx="2" fill="#fff"/>
        <rect x="18" y="42" width="6" height="6" rx="2" fill="#fff"/>
        <rect x="30" y="42" width="6" height="6" rx="2" fill="#fff"/>
        <rect x="42" y="42" width="6" height="6" rx="2" fill="#fff"/>
        <rect x="20" y="14" width="4" height="8" rx="2" fill="#ffb44c"/>
        <rect x="40" y="14" width="4" height="8" rx="2" fill="#ffb44c"/>
      </svg>
      <div class="register-title"><strong>Create Account</strong></div>
    </div>
    <?php if ($error): ?>
      <div class="alert error-alert py-2 text-center mb-3"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST" autocomplete="off">
      <div class="mb-3">
        <label class="form-label" for="name">Full Name</label>
        <input type="text" name="name" class="form-control form-control-lg" id="name" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label" for="email">Email address</label>
        <input type="email" name="email" class="form-control form-control-lg" id="email" required>
      </div>
      <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input type="password" name="password" class="form-control form-control-lg" id="password" required>
      </div>
      <button type="submit" class="btn btn-primary w-100 py-2 mt-2">Create Account</button>
    </form>
    <div class="login-section">
      Already have an account? <a class="login-link" href="index.php">Sign in instead</a>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
