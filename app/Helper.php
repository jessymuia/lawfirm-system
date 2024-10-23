<?php

use Illuminate\Support\Facades\Log;

function sendSMS(\App\Models\User $user, $message): void
{
    $curl = curl_init();

    $apikey = "a7cc0baf1c2d20cbb9fd390e9cf702a1";
    $partnerID = 9575;
    $mobile = sanitizePhone($user->phone_number);
    $shortcode = "O_NJOGA ADV";
    $pass_type = "plain";

    $postData = json_encode([
        'apikey' => $apikey,
        'partnerID' => $partnerID,
        'mobile' => $mobile,
        'message' => $message,
        'shortcode' => $shortcode,
        'pass_type' => $pass_type,
    ]);

    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://sms.textsms.co.ke/api/services/sendsms/',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Cookie: PHPSESSID=t37bf6ls0p7154sv8q84e8vm86'
        ),
    ]);

    $response = curl_exec($curl);
    Log::info($response);
}
function sendCustomSMS($phone_number, $message): void
{
    $curl = curl_init();

    $apikey = "a7cc0baf1c2d20cbb9fd390e9cf702a1";
    $partnerID = 9575;
    $mobile = sanitizePhone($phone_number);
    $shortcode = "O_NJOGA ADV";
    $pass_type = "plain";

    $postData = json_encode([
        'apikey' => $apikey,
        'partnerID' => $partnerID,
        'mobile' => $mobile,
        'message' => $message,
        'shortcode' => $shortcode,
        'pass_type' => $pass_type,
    ]);

    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://sms.textsms.co.ke/api/services/sendsms/',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Cookie: PHPSESSID=t37bf6ls0p7154sv8q84e8vm86'
        ),
    ]);

    $response = curl_exec($curl);
    Log::info($response);
}

function sanitizePhone($phoneNumber): string
{
    $phone = preg_replace("/[^0-9]/","",$phoneNumber);
    $lastNine = substr($phone,-9);

    $result = "254".$lastNine;
    Log::info($result);

    return $result;
}
