<?php
$host = "localhost";
$user = "root";          // XAMPP default
$pass = "";              // XAMPP default
$db   = "habit_tracker"; // database you created

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
