<?php
// PROGRESS/api/email/send_product_link.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
require_once __DIR__ . '/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendProductLinkEmail($toEmail, $userName, $productName, $downloadLink, $price) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;

        // Sender & Recipient
        $mail->setFrom(SMTP_FROM_EMAIL, "NexusPay Store");
        $mail->addAddress($toEmail, $userName);

        // Email Content
        $mail->isHTML(true);
        $mail->Subject = "Download Ready: $productName";
        
        // Professional HTML Template with Button
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #eee; border-radius: 10px; overflow: hidden;'>
                <div style='background: #00B4DB; padding: 20px; text-align: center; color: white;'>
                    <h2 style='margin:0;'>Purchase Successful!</h2>
                </div>
                <div style='padding: 20px; color: #333;'>
                    <p>Hi <b>$userName</b>,</p>
                    <p>Thank you for your purchase of <strong>$productName</strong> for <strong>$price NEX</strong>.</p>
                    <p>Your digital file is ready. Click the button below to download it securely:</p>
                    
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='$downloadLink' style='background-color: #10B981; color: white; padding: 15px 25px; text-decoration: none; border-radius: 50px; font-weight: bold; font-size: 16px; display: inline-block;'>
                            Download File ⬇️
                        </a>
                    </div>
                    
                    <p style='font-size: 12px; color: #777;'>If the button doesn't work, copy this link:<br>
                    <a href='$downloadLink'>$downloadLink</a></p>
                </div>
                <div style='background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #999;'>
                    &copy; 2025 NexusPay Marketplace
                </div>
            </div>
        ";

        $mail->AltBody = "Hi $userName, Thank you for buying $productName. Download here: $downloadLink";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Product Link Email Error: " . $mail->ErrorInfo);
        return false;
    }
}
?>