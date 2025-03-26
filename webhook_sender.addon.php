<?php
if (!defined('__XE__')) exit();

/**
 * Webhook Sender Addon
 * 게시물 작성 시 웹훅을 통해 외부 시스템으로 알림을 전송
 */

// 필요한 함수 파일 포함
require_once __DIR__ . '/webhook_functions.php';

/**
 * 애드온의 호출 시점에 따른 처리
 */
if ($called_position == 'before_module_init')
{
    // 관리자 알림이 있는 경우 표시 (관리자 페이지에서만)
    if(Context::get('module') == 'admin' && isset($_SESSION['webhook_sender_admin_notification']))
    {
        $notification = $_SESSION['webhook_sender_admin_notification'];
        
        // 24시간이 지난 알림은 자동 삭제
        if(time() - $notification['time'] > 86400)
        {
            unset($_SESSION['webhook_sender_admin_notification']);
        }
        else
        {
            // 관리자 알림 표시
            $oAdminController = getAdminController();
            if(is_object($oAdminController) && method_exists($oAdminController, 'addWarning'))
            {
                $oAdminController->addWarning($notification['message']);
            }
        }
    }
}
else if ($called_position == 'after_module_proc')
{
    // 관리자 페이지에서는 웹훅 발송 안함
    if(Context::get('module') == 'admin')
    {
        return;
    }
    
    // 현재 모듈 정보 가져오기
    $current_module_info = Context::get('current_module_info');
    
    // 글 작성 페이지에서는 웹훅 발송 안함
    if(Context::get('act') == 'dispBoardWrite')
    {
        return;
    }
    
    // 애드온 설정 불러오기
    $addon_config = null;
    $oAddonModel = getModel('addon');
    if(is_object($oAddonModel) && method_exists($oAddonModel, 'getAddonConfig'))
    {
        $addon_config = $oAddonModel->getAddonConfig('webhook_sender');
    }
    
    // 웹훅 URL이 없으면 처리 중단
    if(!$addon_config || (empty($addon_config->webhook_url) && empty($addon_config['webhook_url'])))
    {
        return;
    }
    
    // 웹훅 URL 가져오기
    $webhook_url = !empty($addon_config->webhook_url) ? $addon_config->webhook_url : $addon_config['webhook_url'];
    
    // 트리거 옵션 가져오기
    $trigger_on_new = 'Y';
    $trigger_on_update = 'Y';
    
    if(isset($addon_config->trigger_on_new))
    {
        $trigger_on_new = $addon_config->trigger_on_new;
    }
    elseif(isset($addon_config['trigger_on_new']))
    {
        $trigger_on_new = $addon_config['trigger_on_new'];
    }
    
    if(isset($addon_config->trigger_on_update))
    {
        $trigger_on_update = $addon_config->trigger_on_update;
    }
    elseif(isset($addon_config['trigger_on_update']))
    {
        $trigger_on_update = $addon_config['trigger_on_update'];
    }
    
    // 트리거 옵션이 모두 비활성화된 경우 웹훅 발송하지 않음
    if($trigger_on_new === 'N' && $trigger_on_update === 'N')
    {
        webhook_sender_log("웹훅 트리거 옵션이 모두 비활성화되어 있습니다.", 'INFO');
        return;
    }
    
    // 게시글 작성 - procBoardInsertDocument일 때만 처리
    if (Context::get('act') == 'procBoardInsertDocument' || Context::get('act') == 'procBoardUpdateDocument')
    {
        try {
            // 현재 액션 로깅
            webhook_sender_log("현재 액션: " . Context::get('act'), 'INFO');
            
            // 요청 데이터 가져오기
            $data = Context::getRequestVars();
            webhook_sender_log("요청 데이터: " . print_r($data, true), 'DEBUG');
            
            // document_srl 가져오기 (여러 방법 시도)
            $document_srl = null;
            if(isset($data->document_srl)) {
                $document_srl = $data->document_srl;
                webhook_sender_log("요청 데이터에서 document_srl 가져옴: " . $document_srl, 'DEBUG', true);
            } elseif(Context::get('document_srl')) {
                $document_srl = Context::get('document_srl');
                webhook_sender_log("Context에서 document_srl 가져옴: " . $document_srl, 'DEBUG', true);
            } else {
                // Context 객체에서 직접 시도
                $oContext = Context::getInstance();
                if(method_exists($oContext, 'get') && $oContext->get('document_srl')) {
                    $document_srl = $oContext->get('document_srl');
                    webhook_sender_log("Context 객체에서 document_srl 가져옴: " . $document_srl, 'DEBUG', true);
                }
            }
            
            // webhook_sender.addon 2.php에서 사용하는 방법 추가
            if(!$document_srl) {
                // this 객체에서 시도 (addon 2.php 방식)
                if(isset($this) && method_exists($this, 'get') && $this->get('document_srl')) {
                    $document_srl = $this->get('document_srl');
                    webhook_sender_log("this 객체에서 document_srl 가져옴: " . $document_srl, 'INFO', true);
                }
                
                // Request 객체에서 시도
                $oRequest = Context::getRequestVars();
                if(isset($oRequest->document_srl) && $oRequest->document_srl) {
                    $document_srl = $oRequest->document_srl;
                    webhook_sender_log("Request 객체에서 document_srl 가져옴: " . $document_srl, 'INFO', true);
                }
            }
            
            if(!$document_srl) {
                webhook_sender_log("최종적으로 document_srl을 찾을 수 없어 웹훅 발송을 중단합니다.", 'ERROR', true);
                return;
            }
            
            // 정수로 변환하여 유효성 확인
            $document_srl = intval($document_srl);
            if($document_srl <= 0) {
                webhook_sender_log("유효하지 않은 document_srl 값: {$document_srl}", 'ERROR', true);
                return;
            }
            
            webhook_sender_log("유효한 document_srl 확인: {$document_srl}, 문서 정보 로드 시도", 'INFO', true);
            
            // 문서 정보 가져오기
            $oDocumentModel = getModel('document');
            $oDocument = $oDocumentModel->getDocument($document_srl);
            
            if(!$oDocument || !$oDocument->isExists()) {
                webhook_sender_log("문서가 존재하지 않음: {$document_srl}", 'ERROR', true);
                return;
            }
            
            webhook_sender_log("문서 정보 로드 성공 - 문서번호: {$document_srl}, 제목: " . $oDocument->getTitle(), 'INFO', true);
            
            // 새 글/수정 글 판별
            $is_new = false;
            $is_update = false;
            
            // regdate와 last_update 비교
            $regdate = $oDocument->get('regdate');
            $last_update = $oDocument->get('last_update');
            
            webhook_sender_log("문서 날짜 비교 - regdate: {$regdate}, last_update: {$last_update}, 문서번호: {$document_srl}", 'INFO', true);
            
            if ($regdate === $last_update) {
                $is_new = true;
                $is_update = false;  // 명시적으로 설정
                webhook_sender_log("새 게시물 작성 감지 (regdate = last_update): {$document_srl}", 'INFO', true);
            } else {
                $is_update = true;
                $is_new = false;
                webhook_sender_log("게시물 수정 감지 (regdate != last_update): {$document_srl}", 'INFO', true);
            }
            
            // 트리거 옵션에 따라 웹훅 발송 여부 결정
            if($is_new && $trigger_on_new !== 'Y') {
                webhook_sender_log("새 게시물 작성 시 웹훅 발송이 비활성화되어 있습니다. 문서번호: {$document_srl}", 'INFO', true);
                return;
            }
            
            if($is_update && $trigger_on_update !== 'Y') {
                webhook_sender_log("게시물 수정 시 웹훅 발송이 비활성화되어 있습니다. 문서번호: {$document_srl}", 'INFO', true);
                return;
            }
            
            webhook_sender_log("웹훅 발송 조건 충족 - 타입: " . ($is_new ? '새 게시물' : '수정된 게시물') . ", 문서번호: {$document_srl}", 'INFO', true);
            
            // 웹훅 데이터 준비
            $webhook_data = array(
                'title' => $oDocument->getTitle(),
                'content' => $oDocument->getSummary(500),
                'module_srl' => $oDocument->get('module_srl'),
                'board_name' => $oDocument->getModuleName(),
                'url' => $oDocument->getPermanentUrl(),
                'message' => $is_new ? '새 게시물이 작성되었습니다.' : '게시물이 수정되었습니다.',
                'author' => $oDocument->getNickName(),
                'member_srl' => $oDocument->get('member_srl'),
                'regdate' => date('Y-m-d H:i:s', strtotime($regdate)),
                'last_update' => date('Y-m-d H:i:s', strtotime($last_update)),
                'is_new' => $is_new,
                'is_update' => $is_update,
                'timestamp' => time(),
                'webhook_sent_at' => date('Y-m-d H:i:s')
            );
            
            webhook_sender_log("웹훅 전송 준비 완료 - 제목: " . $webhook_data['title'] . ", 타입: " . ($is_new ? '새 게시물' : '수정된 게시물'), 'INFO', true);

            // 웹훅 전송 (비동기, 최대 3회 재시도)
            $max_retries = 3;
            $retry_count = 0;
            $success = false;

            while (!$success && $retry_count < $max_retries) {
                try {
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $webhook_url);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($webhook_data));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);

                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curl_error = curl_error($ch);
                    curl_close($ch);

                    if ($http_code >= 200 && $http_code < 300) {
                        $success = true;
                        webhook_sender_log("웹훅 발송 성공 - 문서번호: {$document_srl}, HTTP 코드: {$http_code}", 'INFO', true);
                    } else {
                        throw new Exception("웹훅 발송 실패 (HTTP {$http_code}): {$curl_error}, 응답: " . substr($response, 0, 200));
                    }
                } catch (Exception $e) {
                    $retry_count++;
                    webhook_sender_log("웹훅 발송 실패 (시도 {$retry_count}/{$max_retries}): " . $e->getMessage(), 'ERROR', true);
                    
                    if ($retry_count < $max_retries) {
                        sleep(1);
                    }
                }
            }

            if (!$success) {
                webhook_sender_log("최대 재시도 횟수 초과 - 웹훅 발송 실패 (문서번호: {$document_srl}, URL: {$webhook_url})", 'ERROR', true);
            }

        } catch (Exception $e) {
            webhook_sender_log("예외 발생: " . $e->getMessage(), 'ERROR', true);
        }
    }
} 