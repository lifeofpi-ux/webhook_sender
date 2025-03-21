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
    if (Context::get('act') == 'procBoardInsertDocument')
    {
        // 요청 데이터 가져오기
        $data = Context::getRequestVars();
        
        // document_srl 가져오기 (여러 방법 시도)
        $document_srl = null;
        if($this->get('document_srl')) {
            $document_srl = $this->get('document_srl');
        } elseif(Context::get('document_srl')) {
            $document_srl = Context::get('document_srl');
        } elseif(isset($data->document_srl)) {
            $document_srl = $data->document_srl;
        }
        
        if(!$document_srl) {
            webhook_sender_log("document_srl을 찾을 수 없음", 'ERROR');
            return;
        }
        
        // 문서 정보 로드
        $oDocumentModel = getModel('document');
        $oDocument = $oDocumentModel->getDocument($document_srl);
        $document_srl_check = Context::get('document_srl');
        $oDocumentCheck = $oDocumentModel->getDocument($document_srl_check);
        
        if(!$oDocument->isExists()) {
            webhook_sender_log("문서가 존재하지 않음: {$document_srl}", 'ERROR');
            return;
        }
        
        // 새 글인지 수정인지 확인
        $is_update = false;
        $is_new = false;
        
        // 1. update_order 값으로 먼저 판단 (0보다 크면 확실한 수정)
        if ($oDocument->get('update_order') > 0) {
            $is_update = true;
            $is_new = false;
            webhook_sender_log("게시물 수정 감지 (update_order > 0): {$document_srl}", 'INFO');
        }
        // 2. 현재 요청 act 값과 status 값 확인
        // 추가 확인: 요청에 있는 document_srl을 확인하는 부분을 추가
        elseif (Context::get('document_srl') && $oDocumentCheck->isExists() && $oDocumentCheck->get('status') !== 'TEMP') {
            // 이미 발행된 문서가 있고, 임시저장이 아닌 경우 (일반적인 수정)
            $is_update = true;
            $is_new = false;
            webhook_sender_log("게시물 수정 감지 (기존 문서 존재): {$document_srl}", 'INFO');
        }
        else {
            // 3. regdate와 last_update 비교 - 시간 차이가 적으면 새 글로 판단
            $regdate = strtotime($oDocument->get('regdate'));
            $last_update = strtotime($oDocument->get('last_update'));
            $time_diff = abs($last_update - $regdate);
            
            // 4. 등록일과 수정일의 차이가 1분 이내면 새 글로 간주
            if ($time_diff < 60) {
                $is_new = true;
                $is_update = false;
                webhook_sender_log("새 게시물 작성 감지 (시간차 {$time_diff}초): {$document_srl}", 'INFO');
            }
            // 5. 일단 새 글로 처리하고 로그에 기록
            else {
                // 임시 저장에서 정식 발행으로 전환되는 경우도 새 글로 처리
                $is_new = true;
                $is_update = false;
                webhook_sender_log("새 게시물 작성 감지 (임시저장에서 발행): {$document_srl}, 시간차: {$time_diff}초", 'INFO');
            }
        }
        
        // 트리거 옵션에 따라 웹훅 발송 여부 결정
        if($is_new && $trigger_on_new !== 'Y') {
            webhook_sender_log("새 게시물 작성 시 웹훅 발송이 비활성화되어 있습니다.", 'INFO');
            return;
        }
        
        if($is_update && $trigger_on_update !== 'Y') {
            webhook_sender_log("게시물 수정 시 웹훅 발송이 비활성화되어 있습니다.", 'INFO');
            return;
        }
        
        // 웹훅 데이터 준비
        $webhook_data = webhook_sender_prepare_data($oDocument, $data->mid, $document_srl, $is_new);
        
        // 웹훅 전송 (비동기)
        $result = webhook_sender_send($webhook_url, $webhook_data, true);
        
        if($result) {
            webhook_sender_log("웹훅 발송 요청 성공: " . ($is_new ? "새 게시물" : "수정된 게시물"), 'INFO');
        } else {
            webhook_sender_log("웹훅 발송 요청 실패: " . ($is_new ? "새 게시물" : "수정된 게시물"), 'ERROR');
        }
    }
} 