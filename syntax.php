<?php
use dokuwiki\Extension\SyntaxPlugin;
use dokuwiki\Logger;

require_once __DIR__ . '/EmlRenderer.php';

class syntax_plugin_emlview extends SyntaxPlugin {

    public function getType() {
        return 'substition';
    }

    public function getSort() {
        return 999;
    }

    public function connectTo($mode) { }

    public function handle($match, $state, $pos, Doku_Handler $handler) {
        return false;
    }

    public function render($format, Doku_Renderer $renderer, $data) {
        if ($format === 'xhtml') {
            [$src, $title, $align, $width, $height, $cache, $linking] = $data;
            if ($linking === 'linkonly') {
                $this->renderLink($renderer, $src, $title);
            } else {
                $this->renderEml($renderer, $src);
            }
		} else {
			$renderer->internalmedia(...$data);
		}
        return true;
    }

    public function renderLink($renderer, $src, $title) {
        $url = wl($ID, ['do' => 'emlview', 'media_id' => $src]);
        if (!$title) {
            $title = basename(mediaFN($src));
        }
        $title = $renderer->_xmlEntities(urldecode($title));
        $renderer->doc .= "<a class='media mediafile mf_eml' href='${url}'>${title}</a>";
    }

    public function renderEml($renderer, $src) {
        ob_start();
        EmlRenderer::render($src);
        $renderer->doc .= ob_get_clean();
    }
}

