<?php
$servername = "45.89.28.7";
$username = "efekanra_eticaret";
$password = "Efekan.9876";
$dbname = "efekanra_eticaret";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
?> 