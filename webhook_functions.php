<?php
if (!defined('__XE__')) exit();

/**
 * 로그 기록 함수 - 필수 오류만 기록
 */
function webhook_sender_log($message, $level = 'INFO', $force_debug = false) {
    // 오류 레벨일 경우에만 시스템 로그에 기록
    if ($level === 'ERROR' || $level === 'CRITICAL') {
        error_log('WEBHOOK_SENDER: ' . $message);
    }
}

/**
 * 웹훅 전송 함수 (동기식 - 응답 대기)
 */
function webhook_sender_send_sync($webhook_url, $postData, $max_retries = 3) {
    $attempt = 0;
    $success = false;

    // JSON 형식으로 변환
    $json_data = json_encode($postData);

    while ($attempt < $max_retries && !$success) {
        $ch = curl_init($webhook_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json_data,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $success = true;
        } else {
            $attempt++;
            if ($attempt < $max_retries) {
                sleep(2);
            }
        }

        // 오류 발생 시에만 로그 기록
        if (!$success && $attempt == $max_retries) {
            webhook_sender_log("Failed to send webhook after {$max_retries} attempts. Last error: {$error}", 'ERROR');
        }
    }

    return $success;
}

/**
 * 웹훅 전송 함수 (비동기식 - 응답 대기 없음)
 */
function webhook_sender_send_async($url, $params, $type='POST')  
{  
    // JSON 형식으로 데이터 변환
    $json_data = json_encode($params);
    
    $parts = parse_url($url);  
    
    if (!isset($parts['path'])) {
        $parts['path'] = '/';
    }
  
    if ($parts['scheme'] == 'http')  
    {  
        $fp = fsockopen($parts['host'], isset($parts['port'])?$parts['port']:80, $errno, $errstr, 30);  
    }  
    else if ($parts['scheme'] == 'https')  
    {  
        $fp = fsockopen("ssl://" . $parts['host'], isset($parts['port'])?$parts['port']:443, $errno, $errstr, 30);  
    }  
    
    if (!$fp) {
        webhook_sender_log("Failed to open socket: $errstr ($errno)", 'ERROR');
        return false;
    }
  
    // HTTP 요청 헤더 구성
    $out = "$type ".$parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '')." HTTP/1.1\r\n";  
    $out.= "Host: ".$parts['host']."\r\n";  
    $out.= "Content-Type: application/json\r\n";
    $out.= "Content-Length: ".strlen($json_data)."\r\n";  
    $out.= "Connection: Close\r\n\r\n";  
    $out.= $json_data;  
  
    fwrite($fp, $out);  
    fclose($fp);  
    
    return true;
}
