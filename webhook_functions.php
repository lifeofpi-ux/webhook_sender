<?php
if (!defined('__XE__')) exit();

/**
 * 로그 기록 함수 - 필요한 정보만 효율적으로 기록
 */
function webhook_sender_log($message, $level = 'INFO', $force_debug = false) {
    // 로그 디렉토리 경로 설정
    $log_dir = RX_BASEDIR . 'files/logs';
    
    // 로그 디렉토리가 없으면 생성
    if (!file_exists($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    
    // 로그 파일 경로
    $log_file = $log_dir . '/webhook_sender.log';
    
    // 로그 메시지 구성
    $log_prefix = '[' . date('Y-m-d H:i:s') . '] [WEBHOOK_SENDER] ' . $level . ': ';
    $log_message = $log_prefix . $message . "\n";
    
    // 로그 파일에 기록
    @file_put_contents($log_file, $log_message, FILE_APPEND);
    
    // 에러 레벨인 경우나 강제 디버그 모드인 경우 PHP 에러 로그에도 기록
    if ($level === 'ERROR' || $level === 'CRITICAL' || $force_debug) {
        error_log($log_prefix . $message);
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
    
    // 콘텐츠 길이 제한 설정 가져오기
    $content_length_limit = 0; // 기본값: 글자수 제한없음
    
    // 애드온 설정 불러오기
    $oAddonModel = getModel('addon');
    if(is_object($oAddonModel) && method_exists($oAddonModel, 'getAddonConfig')) {
        $addon_config = $oAddonModel->getAddonConfig('webhook_sender');
        
        if(isset($addon_config->content_length_limit)) {
            $content_length_limit = intval($addon_config->content_length_limit);
        }
        // 객체를 배열처럼 접근하는 대신 is_array 체크 후 접근
        elseif(is_array($addon_config) && isset($addon_config['content_length_limit'])) {
            $content_length_limit = intval($addon_config['content_length_limit']);
        }
    }
    
    // strip_tags 대신 라이믹스의 getSummary() 메서드 사용
    // getSummary()는 HTML 태그를 더 효과적으로 제거하고 요약 텍스트를 생성
    if($content_length_limit > 0) {
        $content = $oDocument->getSummary($content_length_limit);
        webhook_sender_log("콘텐츠 길이가 제한되었습니다. 제한: {$content_length_limit}자", 'INFO');
    } else {
        // 길이 제한이 없을 경우 전체 내용을 가져옴 (기본값은 대략 500자)
        $max_summary_length = 2000; // 충분히 큰 값으로 설정
        $content = $oDocument->getSummary($max_summary_length);
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
    
    // 특수 문자가 포함된 내용 처리
    $content = preg_replace('/[^\p{L}\p{N}\s\.\,\?\!\:\;\-\_\(\)\[\]\{\}\'\"\%\&\@\#\+\=\*\/\\\\]/u', '', $content);
    
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
    $json_data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    // JSON 인코딩 오류 확인
    if(json_last_error() !== JSON_ERROR_NONE) {
        webhook_sender_log("JSON 인코딩 오류: " . json_last_error_msg() . ". 다시 시도합니다.", 'ERROR');
        // 오류 발생 시 대체 옵션으로 시도
        $json_data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    
    // HTTP 요청 설정
    $headers = array(
        'Content-Type' => 'application/json',
        'User-Agent' => 'Rhymix Webhook Sender Addon'
    );
    
    $options = array(
        'timeout' => 10,
        'verify' => true, // SSL 인증서 검증 활성화
    );
    
    // 비동기 요청을 위한 Queue 사용 (라이믹스 코어 기능 활용으로 변경) 기진곰님 제안 사항 반영
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
