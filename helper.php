<?php

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class helper_plugin_slacknotifier extends DokuWiki_Plugin {

    var $_event = null;
    var $_event_type = array(
        "E" => "edit",
        "e" => "edit_minor",
        "C" => "create",
        "D" => "delete",
        "R" => "revert",
    );
    var $_summary = null;
    var $_payload = null;

    public function setPayload($payload) {
        $this->_payload = $payload;
    }

    public function attic_write($payload) {
        if( strpos($filename, 'data/attic') !== false ) {
            return true;
        }
        return false;
    }

    public function valid_namespace() {
        global $INFO;
        $validNamespaces = $this->getConf('namespaces');
        if ( !empty($validNamespaces) ) {
            $validNamespacesArr = explode(',', $validNamespaces);
            foreach($validNamespacesArr as $namespace) {
                if ( strpos($namespace, $INFO['namespace']) === 0 ) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }

    public function set_event($event) {
        $this->_opt = print_r($event, true);
        $changeType = $event->data['changeType'];
        $event_type = $this->_event_type[$changeType];
        $summary = $event->data['summary'];
        if ( !empty($summary) ) {
            $this->_summary = $summary;
        }
        if ( $event_type == 'create' && $this->getConf('notify_create') == 1 ) {
            $this->_event = 'create';
            return true;
        } elseif( $event_type == 'edit' && $this->getConf('notify_edit') == 1 ) {
            $this->_event = 'edit';
            return true;
        } elseif( $event_type == 'edit minor' && ($this->getConf('notify_edit') == 1) && ($this->getConf('notify_edit_minor') == 1) ) {
            $this->_event = 'edit minor';
            return true;
        } elseif( $event_type == 'delete' && $this->getConf('notify_delete') == 1 ) {
            $this->_event = 'delete';
            return true;
        }

        return false;
    }

    public function set_payload_text($event) {
        global $conf;
        global $lang;
        global $INFO;
        $event_name = '';
        switch ($this->_event) {
            case 'create':
                $title = $this->getLang('t_created');
                $event_name = $this->getLang('e_created');
                break;
            case 'edit':
                $title = $this->getLang('t_updated');
                $event_name = $this->getLang('e_updated');
                break;
            case 'edit minor':
                $title = $this->getLang('t_minor_upd');
                $event_name = $this->getLang('e_minor_upd');
                break;
            case 'delete':
                $title = $this->getLang('t_removed');
                $event_name = $this->getLang('e_removed');
                break;
        }
        
        $user = $INFO['userinfo']['name'];
        $link = $this->_get_url($event, null);
        $page = $event->data['id'];
        $description = "{$user} {$event_name} [__{$page}__]({$link})";
        
        if( $this->_event != 'delete' ) {
            $oldRev = $INFO['meta']['last_change']['date'];
            if( !empty($oldRev) ) {
                $diffURL = $this->_get_url($event, $oldRev);
                $description .= " \([" . $this->getLang('compare') . "]({$diffURL})\)";
            }
        }
        
        $summary = $this->_summary;
        if( (strpos($this->_event, 'edit') !== false) && $this -> getConf('notify_show_summary') ) {
            if( $summary ) $description .= "\n" . $lang['summary'] . ": " . $summary;
        }
        
        $headerTitle = array(
            "type" => "header",
            "text" => array(
                "type" => "plain_text",
                "text" => $title,
                "emoji" => true
            )
        );

        $sectionDescription = array(
            "type" => "section",
            "text" => array(
                "type" => "mrkdwn",
                "text" => $description
            )
        );

        $sectionFooter = array(
            "type" => "section",
            "text" => array(
                "type" => "plain_text",
                "text" => "Dokuwiki SlackNotifier"
            )
        );

        $payload = array(
            "text" => "DokuWiki " . $title,
            "blocks" => array(
                $headerTitle,
                $sectionDescription,
                $sectionFooter
            )
        );

        $this -> _payload = $payload;
    }

    private function _get_url($event = null, $Rev) {
        global $ID;
        global $conf;
        $oldRev = $event -> data['oldRevision'];
		$page = $event -> data['id'];
		if( ($conf['userewrite'] == 1 || $conf['userewrite'] == 2) && $conf['useslash'] == true )
			$page = str_replace ( ":", "/", $page );
        switch($conf['userewrite']) {
            case 0:
                $url = DOKU_URL . "doku.php?id={$page}";
                break;
            case 1:
                $url = DOKU_URL . $page;
                break;
            case 2:
                $url = DOKU_URL . "doku.php/{$page}";
                break;
        }
        if( $Rev != null ) {
            switch($conf['userewrite']) {
                case 0:
                    $url .= "&do=diff&rev={$Rev}";
                    break;
                case 1:
                case 2:
                    $url .= "?do=diff&rev={$Rev}";
                    break;
            }
        }
        return $url;
    }

    public function submit_payload() {
        global $conf;

        // extract separate webhook URLs
        $webhooks_array = explode(';', $this -> getConf('webhook'));
        foreach( $webhooks_array as $webhook ) {
            
            // init curl
            $ch = curl_init($webhook);
            
            // use proxy if defined
            $proxy = $conf['proxy'];
            if( !empty($proxy['host']) ) {
                // configure proxy address and port
                $proxyAddress = $proxy['host'] . ':' . $proxy['port'];
                curl_setopt($ch, CURLOPT_PROXY, $proxyAddress);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                
                // include username and password if defined
                if( !empty($proxy['user']) && !empty($proxy['pass']) ) {
                    $proxyAuth = $proxy['user'] . ':' . conf_decodeString($proxy['port']);
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyAuth);
                }
            }
            
            // submit payload
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            $json_payload = json_encode($this->_payload);
            $payload_length = 'Content-length: ' . strlen($json_payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/json', $payload_length));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            
            // close curl
            Curl_close ( $ch );

        }
        
    }

    public function shouldBeSend($filename){
        $send = true;
        if( $this->attic_write ($filename) ) $send = false;
        if( !$this->valid_namespace() ) $send = false;
        return $send;
    }

    public function sendUpdate($event) {
        
        if( !($this->shouldBeSend($event->data['file']) && !$this->set_event($event)) ) return ;
        // set payload text
        $this->set_payload_text($event);
        
        // submit payload
        $helper->submit_payload();
    }
}

?>