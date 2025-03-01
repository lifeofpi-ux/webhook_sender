<?php
if (!defined('__XE__')) exit();

/**
 * Webhook Sender Addon
 */

// 필요한 함수 파일 포함
require_once __DIR__ . '/webhook_functions.php';

// 어드민 페이지에서는 설정만 처리하고 웹훅 발송은 하지 않음
if(Context::get('module') == 'admin') {
    return;
}

if ($called_position == 'after_module_proc')
{
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
        
        // 모듈 정보 가져오기
        $oModuleModel = &getModel('module');
        $minfo = $oModuleModel->getModuleInfoByMid($data->mid);
        
        // 웹훅 URL 및 설정 로드
        $webhook_url_found = false;
        $target_module_srls = '';
        $trigger_on_new = 'Y'; // 기본값
        $trigger_on_update = 'Y'; // 기본값
        
        try {
            // AddonModel을 사용하여 설정 가져오기
            $oAddonModel = getModel('addon');
            if(is_object($oAddonModel) && method_exists($oAddonModel, 'getAddonConfig')) {
                $addon_config = $oAddonModel->getAddonConfig('webhook_sender');
                
                if($addon_config && !empty($addon_config->webhook_url)) {
                    $webhook_url = $addon_config->webhook_url;
                    $webhook_url_found = true;
                    
                    // 대상 게시판 SRL 가져오기
                    if(!empty($addon_config->target_module_srls)) {
                        $target_module_srls = $addon_config->target_module_srls;
                    }
                    
                    // 트리거 옵션 가져오기
                    if(isset($addon_config->trigger_on_new)) {
                        $trigger_on_new = $addon_config->trigger_on_new;
                    }
                    if(isset($addon_config->trigger_on_update)) {
                        $trigger_on_update = $addon_config->trigger_on_update;
                    }
                } else if($addon_config && is_array($addon_config) && !empty($addon_config['webhook_url'])) {
                    $webhook_url = $addon_config['webhook_url'];
                    $webhook_url_found = true;
                    
                    // 대상 게시판 SRL 가져오기
                    if(!empty($addon_config['target_module_srls'])) {
                        $target_module_srls = $addon_config['target_module_srls'];
                    }
                    
                    // 트리거 옵션 가져오기
                    if(isset($addon_config['trigger_on_new'])) {
                        $trigger_on_new = $addon_config['trigger_on_new'];
                    }
                    if(isset($addon_config['trigger_on_update'])) {
                        $trigger_on_update = $addon_config['trigger_on_update'];
                    }
                }
            }
        } catch(Exception $e) {
            webhook_sender_log("AddonModel->getAddonConfig() 호출 중 오류 발생: " . $e->getMessage(), 'ERROR');
        }
        
        // 웹훅 URL을 찾지 못한 경우 관리자에게 알림
        if(!$webhook_url_found) {
            // 관리자에게 알림 메시지 표시 (세션에 저장)
            $admin_notification = "Webhook Sender 애드온 설정이 필요합니다. 관리자 페이지에서 webhook_url을 설정해주세요.";
            
            // 관리자 페이지 링크 생성
            $admin_url = getNotEncodedUrl('', 'module', 'admin', 'act', 'dispAddonAdminIndex');
            
            // 세션에 알림 저장 (관리자 페이지에서 표시)
            $_SESSION['webhook_sender_admin_notification'] = [
                'message' => $admin_notification,
                'url' => $admin_url,
                'time' => time()
            ];
            
            return;
        }
        
        // 트리거 옵션이 모두 비활성화된 경우 웹훅 발송하지 않음
        if($trigger_on_new === 'N' && $trigger_on_update === 'N') {
            webhook_sender_log("웹훅 트리거 옵션이 모두 비활성화되어 있습니다.", 'INFO');
            return;
        }
        
        // 특정 게시판에만 웹훅을 발송하는 경우, 현재 게시판이 대상인지 확인
        if(!empty($target_module_srls)) {
            $target_modules = explode(',', $target_module_srls);
            $target_modules = array_map('trim', $target_modules);
            
            // 현재 게시판이 대상 목록에 없으면 웹훅 발송하지 않음
            if(!in_array($minfo->module_srl, $target_modules)) {
                webhook_sender_log("웹훅 발송 대상 게시판이 아님: {$minfo->module_srl}", 'INFO');
                return;
            }
        }
        
        // 문서 정보 로드
        $oDocumentModel = &getModel('document');
        $oDocument = $oDocumentModel->getDocument($document_srl);
        $document_srl_check = Context::get('document_srl');
        $oDocumentCheck = $oDocumentModel->getDocument($document_srl_check);
        
        if(!$oDocument->isExists()) {
            webhook_sender_log("문서가 존재하지 않음: {$document_srl}", 'ERROR');
            return;
        }
        
        // 새 글인지 수정인지 확인 (더 명확한 방식으로 계산)
        $is_update = false;
        $is_new = false;
        
        if($oDocumentCheck->isExists() && $oDocumentCheck->get('status') !== 'TEMP') {
            $is_update = true;
            $is_new = false;
            webhook_sender_log("게시물 수정 감지: {$document_srl}", 'INFO');
        } else {
            $is_new = true;
            $is_update = false;
            webhook_sender_log("새 게시물 작성 감지: {$document_srl}", 'INFO');
        }
        
        // 트리거 옵션에 따라 웹훅 발송 여부 결정 (더 명확한 조건문 사용)
        if($is_new && $trigger_on_new !== 'Y') {
            webhook_sender_log("새 게시물 작성 시 웹훅 발송이 비활성화되어 있습니다.", 'INFO');
            return;
        }
        
        if($is_update && $trigger_on_update !== 'Y') {
            webhook_sender_log("게시물 수정 시 웹훅 발송이 비활성화되어 있습니다.", 'INFO');
            return;
        }
        
        // 제목과 내용 가져오기
        $title = $oDocument->getTitleText();
        $content = strip_tags($oDocument->getContentText());
        
        if(strlen($content) > 800) {
            $content = substr($content, 0, 800) . '...';
        }
        
        // 작성자 정보
        $nick_name = $oDocument->getNickName() ? $oDocument->getNickName() : $data->nick_name;
        
        // 게시글 URL 생성
        $url = getNotEncodedFullUrl('', 'mid', $data->mid, 'document_srl', $document_srl);
        
        // 새 글 또는 수정 여부 확인
        $message = "";
        if($is_update) {
            $message = "{$nick_name}님이 게시글을 수정했습니다.\n\n";
        } else {
            $message = "{$nick_name}님이 새 글을 등록했습니다.\n\n";
        }
        
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
            'regdate' => $oDocument->get('regdate'),
            'created_at' => date('Y-m-d H:i:s', strtotime($oDocument->get('regdate'))),
            'updated_at' => date('Y-m-d H:i:s', strtotime($oDocument->get('last_update'))),
            'timestamp' => date('Y-m-d H:i:s'),
            'is_new' => $is_new,
            'is_update' => $is_update
        ];
        
        // 웹훅 전송
        try {
            // JSON 형식으로 웹훅 전송
            $ch = curl_init($webhook_url);
            if(!$ch) {
                webhook_sender_log("curl_init 실패", 'ERROR');
                return;
            }
            
            $json_data = json_encode($webhook_data);
            
            // CURL 옵션 설정
            $curl_options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json_data,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_USERAGENT => 'XE/Rhymix Webhook Sender Addon'
            ];
            
            curl_setopt_array($ch, $curl_options);
            
            // 웹훅 실행
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            
            webhook_sender_log("웹훅 발송 완료: HTTP {$http_code}, 새 글: {$is_new}, 수정: {$is_update}", 'INFO');
            
            // 오류 발생 시 로그 기록
            if(!($http_code >= 200 && $http_code < 300)) {
                webhook_sender_log("웹훅 전송 실패: HTTP {$http_code}, 오류: {$error}", 'ERROR');
                
                // 오류 원인 분석
                if($errno == 6) {
                    webhook_sender_log("호스트 이름을 확인할 수 없음. URL이 올바른지 확인하세요.", 'ERROR');
                } elseif($errno == 7) {
                    webhook_sender_log("서버에 연결할 수 없음. 방화벽이나 네트워크 설정을 확인하세요.", 'ERROR');
                } elseif($errno == 28) {
                    webhook_sender_log("요청 시간 초과. 서버 응답이 너무 느립니다.", 'ERROR');
                } elseif($errno == 35) {
                    webhook_sender_log("SSL 연결 오류. SSL 인증서 문제일 수 있습니다.", 'ERROR');
                } elseif($errno == 60) {
                    webhook_sender_log("SSL 인증서 검증 실패. 인증서가 유효하지 않습니다.", 'ERROR');
                }
            }
        } catch(Exception $e) {
            webhook_sender_log("웹훅 전송 예외 발생: " . $e->getMessage(), 'ERROR');
        }
    }
} 