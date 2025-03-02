<?php
if (!defined('__XE__')) exit();

/**
 * 로그 기록 함수 - 필요한 경우에만 기록
 */
function webhook_sender_log($message, $level = 'INFO', $force_debug = false) {
    // 로그 레벨에 따라 시스템 로그에 기록
    if ($level === 'ERROR' || $level === 'CRITICAL' || $force_debug) {
        $log_prefix = '[WEBHOOK_SENDER] ' . $level . ': ';
        
        // 라이믹스의 디버그 함수 활용 (가능한 경우)
        if (function_exists('debugPrint') && $force_debug) {
            debugPrint($log_prefix . $message);
        } else {
            error_log($log_prefix . $message);
        }
    }
}

/**
 * 웹훅 데이터 준비 함수
 */
function webhook_sender_prepare_data($oDocument, $mid, $document_srl, $is_new = true) {
    // 모듈 정보 가져오기
    $oModuleModel = getModel('module');
    $minfo = $oModuleModel->getModuleInfoByMid($mid);

    // 기본 정보 수집
    $title = $oDocument->getTitleText();
    $content = strip_tags($oDocument->getContentText());
    
    // 컨텐츠 길이 제한
    if(strlen($content) > 800) {
        $content = substr($content, 0, 800) . '...';
    }
    
    // 작성자 정보
    $nick_name = $oDocument->getNickName();
    
    // 게시글 URL 생성
    $url = getNotEncodedFullUrl('', 'mid', $mid, 'document_srl', $document_srl);
    
    // 메시지 구성
    $message = $is_new 
        ? "{$nick_name}님이 새 글을 등록했습니다.\n\n"
        : "{$nick_name}님이 게시글을 수정했습니다.\n\n";
    
    // 시간 정보 - 라이믹스의 zdate() 함수 사용
    $regdate = $oDocument->get('regdate');
    $last_update = $oDocument->get('last_update');
    
    // 웹훅 데이터 구성
    $webhook_data = [
        'title' => $title,
        'content' => $content,
        'module_srl' => $minfo->module_srl,
        'board_name' => $minfo->browser_title,
        'url' => $url,
        'message' => $message,
        'author' => $nick_name,
        'member_srl' => $oDocument->get('member_srl'),
        'regdate' => zdate($regdate, 'Y-m-d H:i:s'),
        'last_update' => zdate($last_update, 'Y-m-d H:i:s'),
        'is_new' => $is_new ? true : false,
        'is_update' => $is_new ? false : true
    ];
    
    return $webhook_data;
}

/**
 * 웹훅 전송 함수 - 라이믹스 HTTP 라이브러리 사용
 */
function webhook_sender_send($webhook_url, $data, $async = true) {
    // JSON 형식으로 데이터 변환
    $json_data = json_encode($data);
    
    // HTTP 요청 설정
    $headers = array(
        'Content-Type' => 'application/json',
        'User-Agent' => 'Rhymix Webhook Sender Addon'
    );
    
    $options = array(
        'timeout' => 10,
        'verify' => true, // SSL 인증서 검증 활성화
    );
    
    // 비동기 요청을 위한 Queue 사용 (라이믹스 기능)
    if ($async && class_exists('Rhymix\\Framework\\Drivers\\Queue')) {
        try {
            // Queue에 작업 등록
            $queue_data = [
                'url' => $webhook_url,
                'data' => $json_data,
                'headers' => $headers,
                'options' => $options
            ];
            
            return Rhymix\Framework\Drivers\Queue::push('webhook_sender_queue_callback', $queue_data);
        } catch (Exception $e) {
            webhook_sender_log("Queue 등록 실패: " . $e->getMessage(), 'ERROR');
            
            // 큐 등록 실패 시 동기적으로 요청 시도
            return webhook_sender_send($webhook_url, $data, false);
        }
    }
    
    // 동기적 요청 처리 (라이믹스 HTTP 클래스 사용)
    try {
        if (class_exists('Rhymix\\Framework\\HTTP')) {
            $response = Rhymix\Framework\HTTP::post($webhook_url, $json_data, $headers, $options);
            $success = ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300);
            
            if (!$success) {
                webhook_sender_log("웹훅 전송 실패: HTTP " . $response->getStatusCode(), 'ERROR');
            }
            
            return $success;
        } 
        // 후퇴 방안: 레거시 FileHandler 사용
        else if (class_exists('FileHandler')) {
            $response = FileHandler::getRemoteResource($webhook_url, $json_data, 'POST', 'application/json', 
                array(), array(), array());
            
            $success = ($response !== false);
            if (!$success) {
                webhook_sender_log("웹훅 전송 실패 (FileHandler 사용)", 'ERROR');
            }
            
            return $success;
        }
    } catch (Exception $e) {
        webhook_sender_log("웹훅 전송 중 예외 발생: " . $e->getMessage(), 'ERROR');
        return false;
    }
    
    return false;
}

/**
 * Queue 콜백 함수 - 비동기 웹훅 전송 처리
 */
function webhook_sender_queue_callback($args) {
    try {
        if (class_exists('Rhymix\\Framework\\HTTP')) {
            $response = Rhymix\Framework\HTTP::post(
                $args->url, 
                $args->data, 
                $args->headers, 
                $args->options
            );
            
            $success = ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300);
            return $success;
        }
        
        return false;
    } catch (Exception $e) {
        webhook_sender_log("Queue 콜백 처리 중 예외 발생: " . $e->getMessage(), 'ERROR');
        return false;
    }
}
