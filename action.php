<?php
use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;
use dokuwiki\Logger;

class action_plugin_emlview extends ActionPlugin {
	public function register(EventHandler $controller) {
		$controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handle_ajax_call');
		$controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_emlview_preprocess');
		$controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, 'handle_emlview');
        $controller->register_hook('PARSER_HANDLER_DONE', 'BEFORE', $this, 'handle_parse_handler_done');
	}

    public function handle_parse_handler_done(Event $event, $param) {
        foreach ($event->data->calls as &$call) {
            if ($call[0] === 'internalmedia') {
                $src = $call[1][0];
                if (str_ends_with($src, '.eml')) {
                    $call = ['plugin', ['emlview', $call[1], DOKU_LEXER_SPECIAL, ''], $call[2]];
                }
            }
        }
    }

    public function handle_emlview_preprocess(Event $event) {
        if ($event->data !== 'emlview') {
            return;
        }
        $event->preventDefault();
    }

	public function handle_emlview(Event $event) {
		if ($event->data !== 'emlview') {
			return;
		}

		global $INPUT;
		$event->preventDefault();

		require_once __DIR__ . '/EmlRenderer.php';
		EmlRenderer::render($INPUT->str('media_id'));
	}

	public function handle_ajax_call(Event $event) {
		global $INPUT;
		if ($event->data === 'emlview_download_attachment') {
            require_once __DIR__ . '/EmlRenderer.php';
            require_once 'Mail/RFC822.php';
            $id = $INPUT->str('id');
            $eml_path = mediaFN($id);
            $parser = new Phemail\MessageParser();
            $message = $parser->parse($eml_path);
            if ($INPUT->str('disposition') === 'attachment') {
                $attachments = $message->getAttachments(true);
            } else {
                $attachments = $message->getParts(true);
            }
            $attachment = $attachments[$INPUT->int('index')];
            $data = EmlRenderer::decodeMessageContents($attachment);
            header('Content-Type: ' . $attachment->getHeaderValue('content-type'));
            $filename = $attachment->getHeaderAttribute('content-disposition', 'filename');
            if ($filename) {
                header('Content-Disposition: inline; ' . "filename*=UTF-8''" . rawurlencode($filename));
            }
            header('Content-Length: ' . strlen($data));
            echo $data;
            exit;
		}
	}
}
