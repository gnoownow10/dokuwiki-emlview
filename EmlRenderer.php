<?php
require_once 'Mail/RFC822.php';

class EmlRenderer {

    static function escapeHtml($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    static function render($src) {
        $eml_path = mediaFN($src);
        $eml_link = ml($src);
        $data = ['id' => $src];
        $parser = new Phemail\MessageParser();
        $message = $data['message'] = $parser->parse($eml_path);
        $subject = self::escapeHtml($message->getHeaderValue('subject', '(no subject)'));
        echo "<div class='emlview-container'>";
        echo "<b class='emlview-subject  media mediafile mf_eml'>$subject</b> ";
        echo "<dl>";
        self::renderHeader($message, 'date');

        echo "<dt>From</dt>";
        echo "<dd>";
        self::renderAddressList($message->getHeaderValue('from'));
        echo "</dd>";

        echo "<dt>To</dt>";
        echo "<dd>";
        self::renderAddressList($message->getHeaderValue('to'));
        echo "</dd>";
        $cc = $message->getHeaderValue('cc');
        if (!is_null($cc)) {
            echo "<dt>CC</dt>";
            echo "<dd>";
            self::renderAddressList($cc);
            echo "</dd>";
        }
        echo "</dl>";
        echo "<div class='emlview-content'>";
        self::renderMessageContent($message, $data);
        echo "</div>";
        echo "<a href='$eml_link' class='emlview-download'>Download the message</a>";
        echo "</div>";
    }

    static function renderHeader($message, $header_name, $display_name = null) {
        $header = $message->getHeader($header_name);
        if (!is_null($header)) {
            if (is_null($display_name)) {
                $display_name = str_replace("-", " ", ucfirst($header_name));
            }
            echo "<dt>" . self::escapeHtml($display_name) . "</dt>";
            echo "<dd>" . self::escapeHtml($header->getValue()) . "</dd>";
        }
    }

    static function renderAddressList($raw_addresses) {
        $parser = new Mail_RFC822();
        $addresses = $parser->parseAddressList($raw_addresses, null, null, false);
        echo "<ul class='emlview-addresses'>";
        foreach ($addresses as $address) {
            $safe_name = self::escapeHtml(iconv_mime_decode($address->personal));
            $safe_email = $address->mailbox . '@' . $address->host;
            echo "<li>$safe_name &lt;$safe_email&gt;</li>";
        }
        echo "</ul>";
    }

    static function renderMessageContent($message, $data) {
        $content_type = $message->getHeaderValue('content-type');
        switch ($content_type) {
            case 'multipart/mixed':
                foreach ($message->getParts() as $part) {
                    $content_disposition = $part->getHeaderValue('content-disposition');
                    if ($content_disposition === 'inline') {
                        self::renderAttachment($part, $data);
                    } else {
                        self::renderMessageContent($part, $data);
                    }
                }
                $attachments = $message->getAttachments();
                if (count($attachments)) {
                    echo "<dl class='emlview-attachments'><dt>Attachments</dt><dd><ul>";
                    foreach ($message->getAttachments() as $attachment) {
                        echo "<li>";
                        self::renderAttachment($attachment, $data);
                        echo "</li>";
                    }
                    echo "</ul></dd></dl>";
                }
                break;
            case 'multipart/alternative':
                $alternatives = [];
                foreach ($message->getParts() as $part) {
                    $alternatives[$part->getHeaderValue('content-type')] = $part;
                }
                if (array_key_exists('text/html', $alternatives)) {
                    self::renderMessageContent($alternatives['text/html'], $data);
                } else if (array_key_exists('text/plain', $alternatives)) {
                    self::renderMessageContent($alternatives['text/plain'], $data);
                } else {
                    foreach ($message->getParts() as $part) {
                        self::renderMessageContent($part, $data);
                    }
                }
                break;
            case 'multipart/related':
                $content_type = $message->getHeader('content-type');
                $start = ($content_type) ? $content_type->getAttribute('start') : null;
                $parts = $message->getParts();
                if (!array_key_exists('parts_by_cid', $data)) {
                    $data['parts_by_cid'] = [];
                }
                foreach ($parts as $part) {
                    $cid = $part->getHeaderValue('content-id');
                    if (!is_null($cid)) {
                        $data['parts_by_cid'][$cid] = $part;
                    }
                }
                foreach ($message->getAttachments() as $attachment) {
                    $cid = $attachment->getHeaderValue('content-id');
                    if (!is_null($cid)) {
                        $data['parts_by_cid'][$cid] = $attachment;
                    }
                }
                if ($start) {
                    if (array_key_exists($start, $parts_by_cid)) {
                        $root = $data['parts_by_cid'][$start];
                    } else {
                        $root = null;
                    }
                } else {
                    $root = $parts[0];
                }
                if (is_null($root)) {
                    echo "<span class='emlview-error'>(Root part not found)</span>";
                } else {
                    self::renderMessageContent($root, $data);
                }
                break;
            case 'text/plain':
                echo "<pre class='emlview-content-text-plain'>";
                echo self::escapeHtml(self::decodeMessageContents($message));
                echo "</pre>";
                break;
            case 'text/html':
                $purifier_config = HTMLPurifier_Config::createDefault();
                $purifier_config->set('URI.AllowedSchemes', ['data' => true, 'cid' => true]);
                $purifier_config->set('Core.RemoveInvalidImg', false);
                $uri_filter = new class($data['parts_by_cid']) extends HTMLPurifier_URIFilter {
                    public function __construct(private $parts_by_cid) {}
                    public function filter(&$uri, $config, $context) {
                        if ($uri->scheme === 'cid') {
                            $cid = "<{$uri->path}>";
                            if (array_key_exists($cid, $this->parts_by_cid)) {
                                $part = $this->parts_by_cid[$cid];
                                $content_type = $part->getHeaderValue('content-type');
                                $content = self::decodeMessageContents($part);
                                $encoded = rawurlencode($content);
                                $path = "$content_type,$encoded";
                                $uri = new HTMLPurifier_URI("data", null, null, null, $path, null, null);
                            } else {
                                throw new InvalidArgumentException("no part with id: $cid in " . implode(",", array_keys($this->parts_by_cid)));
                            }
                        }
                        return true;
                    }
                };
                $uri_def = $purifier_config->getDefinition("URI");
                $uri_def->addFilter($uri_filter, $purifier_config);
                $purifier = new HTMLPurifier($purifier_config);
                $clean_html = $purifier->purify(self::decodeMessageContents($message));
                echo "<div class='emlview-content-text-html'>$clean_html</div>";
                break;
            default:
                echo "<div class='emlview-error'>Unsupported content type: {$content_type}";
                echo "<div>Message structure: <ul>";
                self::renderMessageContent($message, $data);
                echo "</ul></div>";
                echo "</div>";
                break;
        }
    }

    static function renderAttachment($attachment, $data) {
        $attachments = $data['message']->getAttachments(true);
        $index = array_search($attachment, $attachments);
        if ($index === false) {
            $parts = $data['message']->getParts(true);
            $index = array_search($attachment, $parts);
            if ($index === false) {
                throw new InvalidArgumentException("Attachment not found");
            } else {
                $disposition = $parts[$index]->getHeaderValue('content-disposition');
            }
        } else {
            $disposition = 'attachment';
        }
        $filename = self::escapeHtml($attachment->getHeaderAttribute('content-disposition', 'filename', '(no filename)'));
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $content_type = $attachment->getHeaderValue('content-type');
        $url = DOKU_BASE . 'lib/exe/ajax.php?' . http_build_query([
            'call' => 'emlview_download_attachment',
            'id' => $data['id'],
            'index' => $index,
            'disposition' => $disposition
        ]);
        echo "<a href='$url' class='mediafile mf_$ext'>$filename</a>";
        echo " ($content_type)";
    }

    public static function decodeMessageContents($message) {
        $content_transfer_encoding = $message->getHeaderValue('content-transfer-encoding');
        $content = $message->getContents();
        $content = match ($content_transfer_encoding) {
            'quoted-printable' => quoted_printable_decode($content),
            'base64' => base64_decode($content),
            '7bit', '8bit', 'binary', null => $content,
            default => throw new InvalidArgumentException("Unsupported encoding: $content_transfer_encoding"),
        };
        $charset = $message->getHeaderAttribute('content-type', 'charset');
        if ($charset) {
            $charset = match ($charset) {
                'ks_c_5601-1987' => 'euc-kr',
                default => $charset,
            };
            $content = iconv($charset, 'utf-8', $content);
        }
        return $content;
    }
}

