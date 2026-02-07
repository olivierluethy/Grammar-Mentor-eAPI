<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: https://grammar-mentor.com"); // deine Domain
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

// Preflight für CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Nur POST erlauben
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

// JSON Body lesen
$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['email'])) {
    http_response_code(400);
    echo json_encode(["error" => "Email missing"]);
    exit;
}

$email = trim($input['email']);

// einfache Validierung
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid email"]);
    exit;
}

// DB Zugangsdaten
$host = "132.148.178.39";
$db   = "Grammar_Mentor_Email";
$user = "OlivierL";
$pass = "Salade1357ol!";
$charset = "utf8mb4";

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Insert (verhindert SQL Injection)
    $stmt = $pdo->prepare("INSERT INTO subscribers (email) VALUES (:email)");
    $stmt->execute([
        ":email" => $email
    ]);

    echo json_encode(["status" => "ok"]);

} catch (PDOException $e) {

    // Duplicate email (UNIQUE constraint)
    if ($e->getCode() == 23000) {
        http_response_code(409);
        echo json_encode(["error" => "Email already subscribed"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Database error"]);
    }
}
