<?php
$host = "localhost:3306";
$user = "root";      // your DB username
$pass = "";          // your DB password
$db   = "maggie_monthlyreport";   // your DB name

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
