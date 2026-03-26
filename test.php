<?php
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    // Server settings
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'karenjuduncan750@gmail.com';
    $mail->Password   = 'utbipkrqhonwsquq'; // app password
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;

    // Enable debug (VERY IMPORTANT)
    $mail->SMTPDebug = 2;

    // Sender
    $mail->setFrom('karenjuduncan750@gmail.com', 'MUWASCO REPORT');

    // Recipient
    $mail->addAddress('karenjuduncan750@gmail.com');

    // Content
    $mail->isHTML(true);
    $mail->Subject = 'Test Email';
    $mail->Body    = 'If you see this, SMTP is working ✅';

    $mail->send();
    echo "✅ Email sent successfully";

} catch (Exception $e) {
    echo "❌ Error: {$mail->ErrorInfo}";
}