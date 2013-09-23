<?php

/**
 * @version 1.3
 * @author Denis Sobolev <dns.sobol@gmail.com>
 * @author Steve Ludovicy <steve@gms.lu>
 */
class message_label extends rcube_plugin
{

    public $task = 'mail|settings';
    public $rc;

    public function init()
    {
        $rcmail = rcmail::get_instance();
        $this->rc = $rcmail;

        if (isset($_SESSION['user_id'])) {
            $this->add_texts('localization', true);
            $this->add_hook('messages_list', array($this, 'message_set_label'));
            $this->add_hook('preferences_list', array($this, 'label_preferences'));
            $this->add_hook('preferences_save', array($this, 'label_save'));
            $this->add_hook('preferences_sections_list', array($this, 'preferences_section_list'));
            $this->add_hook('storage_init', array($this, 'flag_message_load'));

            if ($rcmail->action == '' || $rcmail->action == 'show') {
                $labellink = $this->api->output->button(array('command' => 'plugin.label_redirect', 'type' => 'link', 'class' => 'active', 'content' => $this->gettext('label_pref')));
                $this->api->add_content(html::tag('li', array('class' => 'separator_above'), $labellink), 'mailboxoptions');
            }

            if ($rcmail->action == '' && $rcmail->task == 'mail') {
                $this->add_hook('template_object_mailboxlist', array($this, 'folder_list_label'));
                $this->add_hook('render_page', array($this, 'render_labels_menu'));
            }

            $this->add_hook('startup', array($this, 'startup'));

            $this->register_action('plugin.message_label_redirect', array($this, 'message_label_redirect'));
            $this->register_action('plugin.message_label_search', array($this, 'message_label_search'));
            $this->register_action('plugin.message_label_mark', array($this, 'message_label_mark'));
            $this->register_action('plugin.message_label_move', array($this, 'message_label_move'));
            $this->register_action('plugin.message_label_delete', array($this, 'message_label_delete'));
            $this->register_action('plugin.not_label_folder_search', array($this, 'not_label_folder_search'));
            $this->register_action('plugin.message_label_setlabel', array($this, 'message_label_imap_set'));
            $this->register_action('plugin.message_label_display_list', array($this, 'display_list_preferences'));


            $this->include_script('message_label.js');
            $this->include_script('colorpicker/mColorPicker.js');

            $this->include_stylesheet($this->local_skin_path() . '/message_label.css');
            $this->coltypesSet = false;
        }
    }

    /**
     * Called when the application is initialized
     * redirect to internal function
     *
     * @access  public
     */
    public function startup($args)
    {
        $search = get_input_value('_search', RCUBE_INPUT_GET);
        if (!isset($search)) {
            $search = get_input_value('_search', RCUBE_INPUT_POST);
        }

        $uid = get_input_value('_uid', RCUBE_INPUT_GET);
        $draft_uid = get_input_value('_draft_uid', RCUBE_INPUT_GET);
        $mbox = get_input_value('_mbox', RCUBE_INPUT_GET);
        $page = get_input_value('_page', RCUBE_INPUT_GET);
        $sort = get_input_value('_sort', RCUBE_INPUT_GET);

        if (!empty($uid)) {
            $parts = explode('__MB__', $uid);
            if (count($parts) == 2) {
                $search = 'labelsearch';
            }
        }
        if (!empty($draft_uid)) {
            $parts = explode('__MB__', $draft_uid);
            if (count($parts) == 2) {
                $search = 'labelsearch';
            }
        }

        if ($search == 'labelsearch') {
            if ($args['action'] == 'show' && !empty($uid)) {
                $parts = explode('__MB__', $uid);
                $uid = $parts[0];
                $this->rc->output->redirect(array('_task' => 'mail', '_action' => $args['action'], '_mbox' => $mbox, '_uid' => $uid));
            }
            if ($args['action'] == 'compose') {
                $draft_uid = get_input_value('_draft_uid', RCUBE_INPUT_GET);
                $parts = explode('__MB__', $draft_uid);
                $draft_uid = $parts[0];
                if (!empty($draft_uid)) {
                    $this->rc->output->redirect(array('_task' => 'mail', '_action' => $args['action'], '_mbox' => $mbox, '_draft_uid' => $draft_uid));
                }
            }
            if ($args['action'] == 'list') {
                $this->rc->output->command('label_search', '_page=' . $page . '&_sort=' . $sort);
                $this->rc->output->send();
                $args['abort'] = true;
            }
            if ($args['action'] == 'mark') {
                $flag = get_input_value('_flag', RCUBE_INPUT_POST);
                $uid = get_input_value('_uid', RCUBE_INPUT_POST);

                $post_str = '_flag=' . $flag . '&_uid=' . $uid;
                if ($quiet = get_input_value('_quiet', RCUBE_INPUT_POST)) {
                    $post_str .= '&_quiet=' . $quiet;
                }
                if ($from = get_input_value('_from', RCUBE_INPUT_POST)) {
                    $post_str .= '&_from=' . $from;
                }
                if ($count = get_input_value('_count', RCUBE_INPUT_POST)) {
                    $post_str .= '&_count=' . $count;
                }
                if ($ruid = get_input_value('_ruid', RCUBE_INPUT_POST)) {
                    $post_str .= '&_ruid=' . $ruid;
                }
                $this->rc->output->command('label_mark', $post_str);
                $this->rc->output->send();
                $args['abort'] = true;
            }

        } elseif ($_SESSION['label_folder_search']['uid_mboxes']) {
            // if action is empty then the page has been refreshed
            if (!$args['action'] || $args['action'] == 'moveto') {
                $_SESSION['label_folder_search']['uid_mboxes'] = 0;
                $_SESSION['label_id'] = 0;
            }
        }
        return $args;
    }

    /**
     * Set label by config to mail from maillist
     *
     * @access  public
     */
    public function message_set_label($p)
    {
        $prefs = $this->rc->config->get('message_label', array());

        if (!count($prefs) or !isset($p['messages']) or !is_array($p['messages'])) {
            return $p;
        }

        foreach ($p['messages'] as $message) {
            $type = 'filter';
            $color = '';
            $ret_key = array();
            foreach ($prefs as $key => $p) {
                if ($p['header'] == 'subject') {
                    $cont = trim(rcube_mime::decode_header($message->$p['header'], $message->charset));
                } else {
                    $cont = $message->$p['header'];
                }
                if (stristr($cont, $p['input'])) {
                    array_push($ret_key, array('id' => $key, 'type' => $type));
                }
            }

            if (!empty($message->flags)) {
                foreach ($message->flags as $flag => $set_val) {
                    if (stripos($flag, 'ulabels') === 0) {
                        $flag_id = str_ireplace('ulabels_', '', $flag);
                        if (!empty($ret_key)) {
                            foreach ($ret_key as $key_search => $value) {
                                $id = $value['id'];
                                if ($prefs[$id]['id'] == strtolower($flag_id) && $value['type'] == 'filter') {
                                    unset($ret_key[$key_search]);
                                }
                            }
                        }
                    }

                    $type = 'label';
                    if (stripos($flag, 'labels') === 0) {
                        $flag_id = str_ireplace('labels_', '', $flag);
                        foreach ($prefs as $key => $p) {
                            if ($p['id'] == strtolower($flag_id)) {
                                $flabel = false;
                                if (!empty($ret_key)) {
                                    foreach ($ret_key as $key_filter => $value_filter) {
                                        $searh_filter = array('id' => $key, 'type' => 'filter');
                                        if ($value_filter == $searh_filter) {
                                            $flabel = $key_filter;
                                        }
                                    }
                                }
                                if ($flabel !== false) {
                                    unset($ret_key[$flabel]);
                                    $type = 'flabel';
                                }
                                array_push($ret_key, array('id' => $key, 'type' => $type));
                            }
                        }
                    }
                }
            }

            if (!empty($ret_key)) {
                sort($ret_key);
                $message->list_flags['extra_flags']['plugin_label'] = array();
                $k = 0;
                foreach ($ret_key as $label_id) {
                    !empty($p['text']) ? $text = $p['text'] : $text = 'label';
                    $id = $label_id['id'];
                    $type = $label_id['type'];
                    $brightness = $this->calc_brightness($prefs[$id]['color']);
                    $color = ($brightness < 130) ? "#FFFFFF" : "#000000";
                    $colorClass = ($brightness < 130) ? 'light' : 'dark';
                    $message->list_flags['extra_flags']['plugin_label'][$k]['color'] = $prefs[$id]['color'];
                    $message->list_flags['extra_flags']['plugin_label'][$k]['class'] = $colorClass;
                    $message->list_flags['extra_flags']['plugin_label'][$k]['text'] = $prefs[$id]['text'];
                    $message->list_flags['extra_flags']['plugin_label'][$k]['id'] = $prefs[$id]['id'];
                    $message->list_flags['extra_flags']['plugin_label'][$k]['type'] = $type;
                    $k++;
                }
            }
        }
        return $p;
    }

    /**
     * set flags when search labels by filter and label
     *
     */
    public function message_label_imap_set()
    {
        if (($uids = get_input_value('_uid', RCUBE_INPUT_POST)) && ($flag = get_input_value('_flag', RCUBE_INPUT_POST))) {
            $flag = $a_flags_map[$flag] ? $a_flags_map[$flag] : strtoupper($flag);
            $type = get_input_value('_type', RCUBE_INPUT_POST);
            $label_search = get_input_value('_label_search', RCUBE_INPUT_POST);

            if ($label_search) {
                $uids = explode(',', $uids);
                // mark each uid individually because the mailboxes may differ
                foreach ($uids as $uid) {
                    $mbox = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['mbox'];
                    $this->rc->storage->set_folder($mbox);
                    if ($type == 'flabel') {
                        $unlabel = 'UN' . $flag;
                        $marked = $this->rc->imap->set_flag($_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'], $unlabel);
                        $unfiler = 'U' . $flag;
                        $marked = $this->rc->imap->set_flag($_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'], $unfiler);
                    } else {
                        $marked = $this->rc->imap->set_flag($_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'], $flag);
                    }
                    if (!$marked) {
                        // send error message
                        $this->rc->output->show_message('errormarking', 'error');
                        $this->rc->output->send();
                        exit;
                    } elseif (empty($_POST['_quiet'])) {
                        $this->rc->output->show_message('messagemarked', 'confirmation');
                    }
                }
            } else {
                if ($type == 'flabel') {
                    $unlabel = 'UN' . $flag;
                    $marked = $this->rc->imap->set_flag($uids, $unlabel);
                    $unfiler = 'U' . $flag;
                    $marked = $this->rc->imap->set_flag($uids, $unfiler);
                } else {
                    $marked = $this->rc->imap->set_flag($uids, $flag);
                }

                if (!$marked) {
                    // send error message
                    if ($_POST['_from'] != 'show') {
                        $this->rc->output->command('list_mailbox');
                    }
                    rcmail_display_server_error('errormarking');
                    $this->rc->output->send();
                    exit;
                } elseif (empty($_POST['_quiet'])) {
                    $this->rc->output->show_message('messagemarked', 'confirmation');
                }
            }

            if (!empty($_POST['_update'])) {
                $this->rc->output->command('clear_message_list');
                $this->rc->output->command('list_mailbox');
            }
        }
        $this->rc->output->send();
    }

    /**
     * Serching mail by label id
     * js function label_search
     *
     * @access  public
     */
    public function message_label_search()
    {
        $storage = $this->rc->get_storage();
        $list = $storage->list_folders_subscribed();
        $delimiter = $storage->get_hierarchy_delimiter();
        $folders = array();
        foreach ($list as $folder) {
            $folders[md5($folder)] = $folder;
        }
        $this->rc->output->set_env('ml_md5_folders', $folders);

        $this->rc->imap->set_page(1);
        $this->rc->imap->set_search_set(null);
        $_SESSION['page'] = 1;
        $page = get_input_value('_page', RCUBE_INPUT_POST);

        $page = $page ? $page : 1;

        $id = get_input_value('_id', RCUBE_INPUT_POST);

        // is there a sort type for this request?
        if ($sort = get_input_value('_sort', RCUBE_INPUT_POST)) {
            // yes, so set the sort vars
            list($sort_col, $sort_order) = explode('_', $sort);

            // set session vars for sort (so next page and task switch know how to sort)
            $save_arr = array();
            $_SESSION['sort_col'] = $save_arr['message_sort_col'] = $sort_col;
            $_SESSION['sort_order'] = $save_arr['message_sort_order'] = $sort_order;
        } else {
            // use session settings if set, defaults if not
            $sort_col = isset($_SESSION['sort_col']) ? $_SESSION['sort_col'] : $this->rc->config->get('message_sort_col');
            $sort_order = isset($_SESSION['sort_order']) ? $_SESSION['sort_order'] : $this->rc->config->get('message_sort_order');
        }

        isset($id) ? $_SESSION['label_id'] = $id : $id = $_SESSION['label_id'];

        $prefs = $this->rc->config->get('message_label', array());

        // get search string
        $str = '';
        foreach ($prefs as $p) {
            if ($p['id'] == $id) {
                $str = $p['input'];
                $filter = 'ALL';
                $header = $p['header'];
                $folders[0] = $p['folder'];
            }
        }

        // add list filter string
        $search_str = $filter && $filter != 'ALL' ? $filter : '';
        $_SESSION['search_filter'] = $filter;

        $subject[$header] = 'HEADER ' . $header;
        $search = $srch ? trim($srch) : trim($str);

        if ($subject) {
            $search_str .= str_repeat(' OR', count($subject) - 1);
            foreach ($subject as $sub) {
                $search_str .= sprintf(" %s {%d}\r\n%s", $sub, strlen($search), $search);
            }
            $_SESSION['search_mods'] = $subject;
        }

        $search_str = trim($search_str);
        $count = 0;
        $result_h = array();
        $tmp_page_size = $this->rc->storage->get_pagesize();

        if ($use_saved_list && $_SESSION['all_folder_search']['uid_mboxes']) {
            $result_h = $this->get_search_result();
        } else {
            $result = $this->perform_search($search_str, $folders, $id, $page);
            $result_h = $result['result'];
            $count = $result['count'];
            $this->rc->output->set_env('label_folder_search_uid_mboxes', $result['uid_mboxes']);
        }

        $this->rc->output->set_env('label_folder_search_active', 1);

        $this->sort_search_result($result_h);
        $sfolders = array();
        $foldersUsort = $this->rc->imap->list_folders_subscribed();
        foreach ($foldersUsort as $key => $row) {
            $sfolders[$row] = $key;
        }
        usort($result_h, $this->build_sorter($sfolders));

        if ($count > 0) {
            if ($search_str) {
                $this->rc->output->show_message('searchsuccessful', 'confirmation', array('nr' => $count));
            }
        } elseif ($err_code = $this->rc->imap->get_error_code()) { // handle IMAP errors (e.g. #1486905)
            // handle IMAP errors (e.g. #1486905)
            rcmail_display_server_error();
        } else {
            $this->rc->output->show_message('searchnomatch', 'notice');
        }

        // update message count display and reset threads
        $this->rc->output->set_env('search_request', "labelsearch");
        $this->rc->output->set_env('search_labels', $id);
        $this->rc->output->set_env('messagecount', $count);
        $this->rc->output->set_env('pagecount', ceil($count / $this->rc->storage->get_pagesize()));
        $this->rc->output->set_env('threading', (bool) false);
        $this->rc->output->set_env('threads', 0);

        $this->rc->output->command('set_rowcount', rcmail_get_messagecount_text($count, $page));

        $this->rc->output->send();
        exit;
    }

    function build_sorter($sfolders)
    {
        return function ($a, $b) use ($sfolders)
        {
            return $sfolders[$a->avmbox] > $sfolders[$b->avmbox];
        };
    }


    private function perform_search($search_string, $folders, $label_id, $page = 1)
    {
        // Search all folders and build a final set
        if ($folders[0] == 'all' || empty($folders)) {
            $folders_search = $this->rc->imap->list_folders_subscribed();
        } else {
            $folders_search = $folders;
        }
        $count = 0;
        $folder_count = array();
        foreach ($folders_search as $mbox) {
            if ($mbox == $this->rc->config->get('trash_mbox')) {
                continue;
            }
            $this->rc->storage->set_folder($mbox);
            $this->rc->storage->search($mbox, $search_string, RCMAIL_CHARSET, $_SESSION['sort_col']);
            $result = array();
            $fcount = $this->rc->storage->count($mbox, 'ALL', !empty($_REQUEST['_refresh']));
            $count += $fcount;
            $folder_count[$mbox] = $fcount;
        }
        foreach ($folder_count as $k => $v) {
            if ($v == 0) {
                unset($folder_count[$k]);
            }
        }
        $fetch = $this->do_pagination($folder_count, $page);
        $mails = array();
        $currentMailbox = "";
        $displayOptions = $this->rc->config->get('message_label_display_options', array());
        $showMboxColumn = isset($displayOptions['_show_message_mbox_info']) && $displayOptions['_show_message_mbox_info'] ? true : false;
        $uid_mboxes = array();
        foreach ($fetch as $mailbox => $data) {
            if ($currentMailbox != $mailbox) {
                $currentMailbox = $mailbox;
                if (isset($displayOptions['_show_message_label_header']) && $displayOptions['_show_message_label_header'] === true) {
                    $this->rc->output->command('message_label_add_mbox', $mailbox, $folder_count[$mailbox], $showMboxColumn);
                }
            }
            $uid_mboxes = array_merge($uid_mboxes, $this->getMails($mailbox, $data, $search_string, $showMboxColumn));
        }

        return array('result' => array(), 'count' => $count, 'uid_mboxes' => $uid_mboxes);
    }

    private function getMails($mailbox, $data, $search_string, $showMboxColumn)
    {
        $pageSize = $this->rc->storage->get_pagesize();
        $msgNum = $data['from'];
        $startPage =  ceil($msgNum/$pageSize);
        $msgMod = $msgNum % $pageSize;
        $multiPage = "false";
        $firstArrayElement = $msgMod == 0 ? ($pageSize-1) : ($msgMod-1);
        $quantity = $data['to'] - $data['from'];
        if ($data['from'] + $quantity > $pageSize) {
            $multiPage = "true";
        }
        $this->rc->storage->set_folder($mailbox);
        $this->rc->storage->search($mailbox, $search_string, RCMAIL_CHARSET, $_SESSION['sort_col']);
        $messages = $this->rc->storage->list_messages('', $startPage);
        if ($multiPage) {
            $messages = array_merge($messages, $this->rc->storage->list_messages('', $startPage+1));
        }
        //FIRST: 0 QUANTITY: 2
        $sliceTo = $quantity + 1;
        $mslice = array_slice($messages, $firstArrayElement, $sliceTo, true);
        $messages = $mslice;
        $avbox = array();
        $showAvmbox = false;
        foreach ($messages as $set_flag) {
            $set_flag->flags['skip_mbox_check'] = true;
            if ($showMboxColumn === true) {
                $set_flag->avmbox = $mailbox;
                $avbox[] = 'avmbox';
                $showAvmbox = true;
            }
        }
        $uid_mboxes = $this->rcmail_js_message_list($messages, false, null, $showAvmbox, $avbox, $showMboxColumn);

        return $uid_mboxes;
    }

    private function do_pagination($folders, $onPage)
    {
        $perPage = $this->rc->storage->get_pagesize();
        $from = $perPage * $onPage - $perPage + 1;
        $to = $from + $perPage - 1;
        $got = 0;
        $pos = 0;
        $cbox = "";
        $boxStart = 0;
        $boxStop = 0;
        $fetch = array();
        foreach ($folders as $box => $num) {
            $i = $num;
            if ($box != $cbox) {
                $boxStart = 0;
                $boxStop = 0;
                $cbox = $box;
            }
            while ($i--) {
                $pos++;
                $boxStart++;
                if ($pos >= $from && $pos <= $to) {
                    if (!isset($fetch[$box])) {
                        $fetch[$box] = array("from" => $boxStart);
                    }
                    $fetch[$box]['to'] = $boxStart;
                    $got++;
                }
            }
            if ($got >= $perPage) {
                break;
            }
        }

        return $fetch;
    }

    /**
     * return javascript commands to add rows to the message list
     */
    public function rcmail_js_message_list($a_headers, $insert_top = false, $a_show_cols = null, $avmbox = false, $avcols = array(), $showMboxColumn = false)
    {
        global $CONFIG, $RCMAIL, $OUTPUT;
        $uid_mboxes = array();
    
        if (empty($a_show_cols)) {
            if (!empty($_SESSION['list_attrib']['columns'])) {
                $a_show_cols = $_SESSION['list_attrib']['columns'];
            } else {
                $a_show_cols = is_array($CONFIG['list_cols']) ? $CONFIG['list_cols'] : array('subject');
            }
        } else {
            if (!is_array($a_show_cols)) {
                $a_show_cols = preg_split('/[\s,;]+/', strip_quotes($a_show_cols));
            }
            $head_replace = true;
        }
       
        $mbox = $RCMAIL->storage->get_folder();
    
        // make sure 'threads' and 'subject' columns are present
        if (!in_array('subject', $a_show_cols)) {
            array_unshift($a_show_cols, 'subject');
        }
        if (!in_array('threads', $a_show_cols)) {
            array_unshift($a_show_cols, 'threads');
        }
        $_SESSION['list_attrib']['columns'] = $a_show_cols;
    
        // Make sure there are no duplicated columns (#1486999)
        $a_show_cols = array_merge($a_show_cols, $avcols);
        $a_show_cols = array_unique($a_show_cols);
    
        // Plugins may set header's list_cols/list_flags and other rcube_message_header variables
        // and list columns
        $plugin = $RCMAIL->plugins->exec_hook(
            'messages_list',
            array('messages' => $a_headers, 'cols' => $a_show_cols)
        );
        $a_show_cols = $plugin['cols'];
        $a_headers   = $plugin['messages'];
        $thead = $head_replace ? rcmail_message_list_head($_SESSION['list_attrib'], $a_show_cols) : null;

        // get name of smart From/To column in folder context
        if (($f = array_search('fromto', $a_show_cols)) !== false) {
            $smart_col = rcmail_message_list_smart_column_name();
        }
        if ($this->coltypesSet == false) {
            $OUTPUT->command('set_message_coltypes', $a_show_cols, $thead, $smart_col);
            if ($showMboxColumn === true) {
                $OUTPUT->command('plugin.avaddheader', array());
            }
            $this->coltypesSet = true;
        }
    
        if (empty($a_headers)) {
            return;
        }
    
        // remove 'threads', 'attachment', 'flag', 'status' columns, we don't need them here
        foreach (array('threads', 'attachment', 'flag', 'status', 'priority') as $col) {
            if (($key = array_search($col, $a_show_cols)) !== false) {
                unset($a_show_cols[$key]);
            }
        }
        // loop through message headers
        foreach ($a_headers as $n => $header) {
            if (empty($header)) {
                continue;
            }
            $a_msg_cols = array();
            $a_msg_flags = array();
            // format each col; similar as in rcmail_message_list()
            foreach ($a_show_cols as $col) {
                $col_name = $col == 'fromto' ? $smart_col : $col;
    
                if (in_array($col_name, array('from', 'to', 'cc', 'replyto'))) {
                    $cont = rcmail_address_string($header->$col_name, 3, false, null, $header->charset);
                } elseif ($col == 'subject') {
                    $cont = trim(rcube_mime::decode_header($header->$col, $header->charset));
                    if (!$cont) {
                        $cont = rcube_label('nosubject');
                    }
                    $cont = Q($cont);
                } elseif ($col == 'size') {
                    $cont = show_bytes($header->$col);
                } elseif ($col == 'date') {
                    $cont = format_date($header->date);
                } else {
                    $cont = Q($header->$col);
                }
                $a_msg_cols[$col] = $cont;
            }

            $a_msg_flags = array_change_key_case(array_map('intval', (array) $header->flags));
            if ($header->depth) {
                $a_msg_flags['depth'] = $header->depth;
            } elseif ($header->has_children) {
                $roots[] = $header->uid;
            }
            if ($header->parent_uid) {
                $a_msg_flags['parent_uid'] = $header->parent_uid;
            }
            if ($header->has_children) {
                $a_msg_flags['has_children'] = $header->has_children;
            }
            if ($header->unread_children) {
                $a_msg_flags['unread_children'] = $header->unread_children;
            }
            if ($header->others['list-post']) {
                $a_msg_flags['ml'] = 1;
            }
            if ($header->priority) {
                $a_msg_flags['prio'] = (int) $header->priority;
            }
            $a_msg_flags['ctype'] = Q($header->ctype);
            $a_msg_flags['mbox'] = $mbox;
            if (!empty($header->list_flags) && is_array($header->list_flags)) {
                $a_msg_flags = array_merge($a_msg_flags, $header->list_flags);
            }
            if (!empty($header->list_cols) && is_array($header->list_cols)) {
                $a_msg_cols = array_merge($a_msg_cols, $header->list_cols);
            }
            if ($showMboxColumn === true) {
                $a_msg_flags['avmbox'] = $avmbox;
            }

            $OUTPUT->command(
                'add_message_row',
                $header->uid . '__MB__' . md5($mbox),
                $a_msg_cols,
                $a_msg_flags,
                $insert_top
            );
            $id = $header->uid . '__MB__' . md5($mbox);
            $uid_mboxes[$id] = array('uid' => $header->uid, 'mbox' => $mbox, 'md5mbox' => md5($mbox));
        }

        if ($RCMAIL->storage->get_threading()) {
            $OUTPUT->command('init_threads', (array) $roots, $mbox);
        }

        return $uid_mboxes;
    }

    /**
     * Slice message header array to return only the messages corresponding the page parameter
     *
     * @param   array    Indexed array with message header objects
     * @param   int      Current page to list
     * @return  array    Sliced array with message header objects
     * @access  public
     */
    public function get_paged_result($result_h, $page) {
        // Apply page size rules
        if (count($result_h) > $this->rc->storage->get_pagesize()) {
            $result_h = array_slice($result_h, 0, $this->rc->storage->get_pagesize());
        }

        return $result_h;
    }

    /**
     * Called when message statuses are modified or they have been flagged
     *
     * @access  public
     */
    public function message_label_mark()
    {

        $a_flags_map = array(
            'undelete' => 'UNDELETED',
            'delete' => 'DELETED',
            'read' => 'SEEN',
            'unread' => 'UNSEEN',
            'flagged' => 'FLAGGED',
            'unflagged' => 'UNFLAGGED');

        if (($uids = get_input_value('_uid', RCUBE_INPUT_POST)) && ($flag = get_input_value('_flag', RCUBE_INPUT_POST))) {

            $flag = $a_flags_map[$flag] ? $a_flags_map[$flag] : strtoupper($flag);

            if ($flag == 'DELETED' && $this->rc->config->get('skip_deleted') && $_POST['_from'] != 'show') {
                // count messages before changing anything
                $old_count = count($_SESSION['label_folder_search']['uid_mboxes']);
                $old_pages = ceil($old_count / $this->rc->storage->get_pagesize());
                $count = sizeof(explode(',', $uids));
            }

            $uids = explode(',', $uids);

            // mark each uid individually because the mailboxes may differ
            foreach ($uids as $uid) {
                $mbox = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['mbox'];
                $this->rc->storage->set_folder($mbox);
                $marked = $this->rc->imap->set_flag($_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'], $flag);

                if (!$marked) {
                    // send error message
                    if ($_POST['_from'] != 'show') {
                        $this->rc->output->command('list_mailbox');
                    }
                    $this->rc->output->show_message('errormarking', 'error');
                    $this->rc->output->send();
                    exit;
                } elseif (empty($_POST['_quiet'])) {
                    $this->rc->output->show_message('messagemarked', 'confirmation');
                }
            }

            $skip_deleted = $this->rc->config->get('skip_deleted');
            $read_when_deleted = $this->rc->config->get('read_when_deleted');

            if ($flag == 'DELETED' && $read_when_deleted && !empty($_POST['_ruid'])) {
                $uids = get_input_value('_ruid', RCUBE_INPUT_POST);
                $uids = explode(',', $uids);

                foreach ($uids as $uid) {
                    $mbox = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['mbox'];
                    $this->rc->storage->set_folder($mbox);
                    $read = $this->rc->imap->set_flag($_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'], 'SEEN');

                    if ($read != -1 && !$skip_deleted) {
                        $this->rc->output->command('flag_deleted_as_read', $uid);
                    }
                }
            }

            if ($flag == 'SEEN' || $flag == 'UNSEEN' || ($flag == 'DELETED' && !$skip_deleted)) {
                // just update unread count for all mailboxes, easier than figuring out which were changed
                $mbox_names = $this->rc->storage->list_folders_subscribed();

                foreach ($mbox_names as $mbox) {
                    $this->rc->output->command('set_unread_count', $mbox, $this->rc->imap->messagecount($mbox, 'UNSEEN'), ($mbox == 'INBOX'));
                }
            } elseif ($flag == 'DELETED' && $skip_deleted) {
                if ($_POST['_from'] == 'show') {
                    if ($next = get_input_value('_next_uid', RCUBE_INPUT_GPC)) {
                        $this->rc->output->command('show_message', $next);
                    } else {
                        $this->rc->output->command('command', 'list');
                    }
                } else {
                    // refresh saved search set after moving some messages
                    if (($search_request = get_input_value('_search', RCUBE_INPUT_GPC)) && $_SESSION['label_folder_search']['uid_mboxes']) {
                        $_SESSION['search'][$search_request] = $this->perform_search($this->rc->imap->search_string);
                    }
                    $msg_count = count($_SESSION['label_folder_search']['uid_mboxes']);
                    $pages = ceil($msg_count / $this->rc->storage->get_pagesize());
                    $nextpage_count = $old_count - $this->rc->storage->get_pagesize() * $_SESSION['page'];
                    $remaining = $msg_count - $this->rc->storage->get_pagesize() * ($_SESSION['page'] - 1);
                    // jump back one page (user removed the whole last page)
                    if ($_SESSION['page'] > 1 && $nextpage_count <= 0 && $remaining == 0) {
                        $this->rc->imap->set_page($_SESSION['page'] - 1);
                        $_SESSION['page'] = $this->rc->imap->list_page;
                        $jump_back = true;
                    }
                    // update message count display
                    $this->rc->output->set_env('messagecount', $msg_count);
                    $this->rc->output->set_env('current_page', $this->rc->imap->list_page);
                    $this->rc->output->set_env('pagecount', $pages);
                    // update mailboxlist
                    foreach ($this->rc->storage->list_folders_subscribed() as $mbox) {
                        $unseen_count = $msg_count ? $this->rc->imap->messagecount($mbox, 'UNSEEN') : 0;
                        $this->rc->output->command('set_unread_count', $mbox, $unseen_count, ($mbox == 'INBOX'));
                    }
                    $this->rc->output->command('set_rowcount', rcmail_get_messagecount_text($msg_count));

                    // add new rows from next page (if any)
                    if (($jump_back || $nextpage_count > 0)) {
                        $sort_col = isset($_SESSION['sort_col']) ? $_SESSION['sort_col'] : $this->rc->config->get('message_sort_col');
                        $sort_order = isset($_SESSION['sort_order']) ? $_SESSION['sort_order'] : $this->rc->config->get('message_sort_order');

                        $a_headers = $this->get_search_result();
                        $this->sort_search_result($a_headers);
                        $a_headers = array_slice($a_headers, $sort_order == 'DESC' ? 0 : -$count, $count);
                        rcmail_js_message_list($a_headers, false, false);
                    }
                }
            }
            $this->rc->output->send();
        }
        exit;
    }

    /**
     * Get message header results for the current all folder search
     *
     * @return  array    Indexed array with message header objects
     * @access  public
     */
    public function get_search_result()
    {
        $result_h = array();

        foreach ($_SESSION['label_folder_search']['uid_mboxes'] as $id => $uid_mbox) {
            $uid = $uid_mbox['uid'];
            $mbox = $uid_mbox['mbox'];

            $result_h = $this->rc->imap->fetch_headers($mbox, 1, $_SESSION['sort_col'], $_SESSION['sort_order']);
            $row->uid = $id;
            array_push($result_h, $row);
        }
        return $result_h;
    }

    /**
     * Called when messages are moved
     *
     * @access  public
     */
    public function message_label_move()
    {
        if (!empty($_POST['_uid']) && !empty($_POST['_target_mbox'])) {
            $uids = get_input_value('_uid', RCUBE_INPUT_POST);
            $target = get_input_value('_target_mbox', RCUBE_INPUT_POST);
            $uids = explode(',', $uids);

            foreach ($uids as $uid) {
                $mbox = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['mbox'];
                $this->rc->storage->set_folder($mbox);
                $ruid = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'];

                // flag messages as read before moving them
                if ($this->rc->config->get('read_when_deleted') && $target == $this->rc->config->get('trash_mbox')) {
                    $this->rc->imap->set_flag($ruid, 'SEEN');
                }
                $moved = $this->rc->imap->move_message($ruid, $target, $mbox);
            }

            if (!$moved) {
                // send error message
                if ($_POST['_from'] != 'show') {
                    $this->rc->output->command('list_mailbox');
                }
                $this->rc->output->show_message('errormoving', 'error');
                $this->rc->output->send();
                exit;
            } else {
                $this->rc->output->command('list_mailbox');
                $this->rc->output->show_message('messagemoved', 'confirmation');
                $this->rc->output->send();
                exit;
            }
        }
    }

    /**
     * Called when messages are delete
     *
     * @access  public
     */
    public function message_label_delete()
    {
        if (!empty($_POST['_uid'])) {
            $uids = get_input_value('_uid', RCUBE_INPUT_POST);
            $uids = explode(',', $uids);

            foreach ($uids as $uid) {
                $mbox = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['mbox'];
                $this->rc->storage->set_folder($mbox);
                $ruid = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'];

                $del = $this->rc->imap->delete_message($ruid, $mbox);
            }

            if (!$del) {
                // send error message
                if ($_POST['_from'] != 'show') {
                    $this->rc->output->command('list_mailbox');
                }
                rcmail_display_server_error('errordeleting');
                $this->rc->output->send();
                exit;
            } else {
                $this->rc->output->command('list_mailbox');
                $this->rc->output->show_message('messagedeleted', 'confirmation');
                $this->rc->output->send();
                exit;
            }
        }
    }

    /**
     * Nullify label folder search results
     *
     * By nullifying we are letting the plugin know we are no longer in an label folder search
     *
     * @access  public
     */
    public function not_label_folder_search()
    {
        $_SESSION['label_folder_search']['uid_mboxes'] = 0;
        $_SESSION['label_id'] = 0;
    }

    /**
     * Sort result header array by date, size, subject, or from using a bubble sort
     *
     * @param   array    Indexed array with message header objects
     * @access  private
     */
    private function sort_search_result(&$result_h)
    {
        // Bubble sort! <3333 (ideally sorting and page trimming should be done
        // in js but php has the convienent rcmail_js_message_list function
        for ($x = 0; $x < count($result_h); $x++) {
            for ($y = 0; $y < count($result_h); $y++) {
                // ick can a variable name be put into a variable so i can get this out of 2 loops
                switch ($_SESSION['sort_col']) {
                    case 'date': $first = $result_h[$x]->timestamp;
                        $second = $result_h[$y]->timestamp;
                        break;
                    case 'size': $first = $result_h[$x]->size;
                        $second = $result_h[$y]->size;
                        break;
                    case 'subject': $first = strtolower($result_h[$x]->subject);
                        $second = strtolower($result_h[$y]->subject);
                        break;
                    case 'from': $first = strtolower($result_h[$x]->from);
                        $second = strtolower($result_h[$y]->from);
                        break;
                }

                if ($first < $second) {
                    $hold = $result_h[$x];
                    $result_h[$x] = $result_h[$y];
                    $result_h[$y] = $hold;
                }
            }
        }

        if ($_SESSION['sort_order'] == 'DESC') {
            $result_h = array_reverse($result_h);
        }
    }

    /**
     * Redirect function on arrival label to folder list
     *
     * @access  public
     */
    public function message_label_redirect()
    {
        $this->rc->output->redirect(array('_task' => 'settings', '_action' => 'label_preferences'));
    }

    /**
     * render labbel menu for markmessagemenu
     */
    public function render_labels_menu($val)
    {
        $prefs = $this->rc->config->get('message_label', array());
        $input = new html_checkbox();
        if (count($prefs) > 0) {
            $attrib['class'] = 'toolbarmenu labellistmenu';
            $ul .= html::tag('li', array('class' => 'separator_below inactive'), $this->gettext('label_set'));
            foreach ($prefs as $p) {
                $ul .= html::tag(
                    'li',
                    null,
                    html::a(
                        array(
                            'class' => 'labellink',
                            'href' => '#',
                            'onclick' => "return rcmail.command('label_messages', '{$p['id']}', this, event)"
                        ),
                        html::tag(
                            'span',
                            array(
                                'class' => 'listmenu',
                                'style' => 'background-color:' . $p['color']
                            ),
                            ''
                        ) . $p['text']
                    )
                );
            }

            $out = html::tag('ul', $attrib, $ul, html::$common_attrib);
        }

        $this->rc->output->add_footer($out);
    }

    /**
     * Label list to folder list menu and set flags in imap conn
     *
     * @access  public
     */
    public function folder_list_label($args)
    {
        $args['content'] .= html::div(array('id' => 'labels-title', 'class' => 'boxtitle label_header_menu'), $this->gettext('label_title') . html::tag('span', array('class' => 'drop_arrow'), ''));
        $prefs = $this->rc->config->get('message_label', array());

        if (!strlen($attrib['id'])) {
            $attrib['id'] = 'labellist';
        }
        $display_label = $this->rc->config->get('message_label_display');
        if ($display_label == 'false') {
            $style = 'display: none;';
        }
        if (count($prefs) > 0) {
            $table = new html_table($attrib);
            foreach ($prefs as $p) {
                $brightness = $this->calc_brightness($p['color']);
                $color = ($brightness < 130) ? "#FFFFFF" : "#000000";
                $colorClass = ($brightness < 130) ? 'light' : 'dark';
                $table->add_row(array('id' => 'rcmrow' . $p['id'], 'class' => 'labels_row'));
                $table->add(array('class' => 'labels_color'), html::tag('span', array('class' => 'lmessage ' . $colorClass, 'style' => 'background-color:' . $p['color']), ''));
                $table->add(array('class' => 'labels_name'), $p['text']);
            }
            $args['content'] .= html::div(array('class' => 'lmenu', 'id' => 'drop_labels', 'style' => $style), $table->show($attrib));
        } else {
            $args['content'] .= html::div('lmenu', html::a(array('href' => '#', 'onclick' => 'return rcmail.command(\'plugin.label_redirect\',\'true\',true)'), $this->gettext('label_create')));
        }

        $flags = array();
        foreach ($prefs as $prefs_val) {
            $flags += array(strtoupper($prefs_val['id']) => '$labels_' . $prefs_val['id']);
        }

        $this->rc->imap->conn->flags = array_merge($this->rc->imap->conn->flags, $flags);
        // add id to message label table if not specified
        $this->rc->output->add_gui_object('labellist', $attrib['id']);

        return $args;
    }

    public function display_list_preferences()
    {
        $display_label = get_input_value('_display_label', RCUBE_INPUT_POST);
        $display_folder = get_input_value('_display_folder', RCUBE_INPUT_POST);

        if (!empty($display_label)) {
            if ($display_label == 'true') {
                $this->rc->user->save_prefs(array('message_label_display' => 'true'));
                $this->rc->output->command('display_message', $this->gettext('display_label_on'), 'confirmation');
            } elseif ($display_label == 'false') {
                $this->rc->user->save_prefs(array('message_label_display' => 'false'));
                $this->rc->output->command('display_message', $this->gettext('display_label_off'), 'confirmation');
            } else {
                $this->rc->output->command('display_message', $this->gettext('display_error'), 'error');
            }
            $this->rc->output->send();
        }
    }

    public function flag_message_load($p)
    {
        $prefs = $this->rc->config->get('message_label', array());
        $flags = array();
        foreach ($prefs as $prefs_val) {
            $flags += array(strtoupper($prefs_val['id']) => '$labels_' . $prefs_val['id']);
            $flags += array(strtoupper('u' . $prefs_val['id']) => '$ulabels_' . $prefs_val['id']);
        }
        $this->rc->imap->conn->flags = array_merge($this->rc->imap->conn->flags, $flags);
    }

    /**
     * Display label configuratin in user preferences tab
     *
     * @access  public
     */
    public function label_preferences($args)
    {
        if ($args['section'] == 'label_preferences') {

            $this->rc = rcmail::get_instance();
            $this->rc->imap_connect();

            $args['blocks']['label_display_options'] = array('options' => array(), 'name' => Q($this->gettext('label_display_options')));
            $args['blocks']['create_label'] = array('options' => array(), 'name' => Q($this->gettext('label_create')));
            $args['blocks']['list_label'] = array('options' => array(), 'name' => Q($this->gettext('label_title')));

            $i = 0;
            $prefs = $this->rc->config->get('message_label', array());

            //insert empty row
            $args['blocks']['create_label']['options'][$i++] = array('title' => '', 'content' => $this->get_form_row());
            //insert display options
            $displayOptions = $this->rc->config->get('message_label_display_options', array());

            $label1 = html::label('_show_message_label_header', Q($this->gettext('mailbox_headers_in_results')));
            $label2 = html::label('_show_message_mbox_info', Q($this->gettext('mailbox_info_in_results')));
            $arg1 = array('name' => '_show_message_label_header', 'id' => '_show_message_label_header', 'type' => 'checkbox', 'title' => "", 'class' => 'watermark linput', 'value' => 1);
            if (isset($displayOptions['_show_message_label_header']) && $displayOptions['_show_message_label_header'] === true) {
                $arg1['checked'] = 'checked';
            }
            $check1 = html::tag('input', $arg1);
            $arg2 = array('name' => '_show_message_mbox_info', 'id' => '_show_message_mbox_info', 'type' => 'checkbox', 'title' => "", 'class' => 'watermark linput', 'value' => 1);
            if (isset($displayOptions['_show_message_mbox_info']) && $displayOptions['_show_message_mbox_info'] === true) {
                $arg2['checked'] = 'checked';
            }
            $check2 = html::tag('input', $arg2);
            $args['blocks']['label_display_options']['options'][0] = array('title' => '', 'content' => '<p>' . $check1 . ' ' . $label1 . '</p>');
            $args['blocks']['label_display_options']['options'][1] = array('title' => '', 'content' => '<p>' . $check2 . ' ' . $label2 . '</p>');

            foreach ($prefs as $p) {
                $args['blocks']['list_label']['options'][$i++] = array(
                    'title' => '&nbsp;',
                    'content' => $this->get_form_row($p['id'], $p['header'], $p['folder'], $p['input'], $p['color'], $p['text'], true)
                );
            }
        }

        return($args);
    }

    /**
     * Create row label
     *
     * @access  private
     */
    private function get_form_row($id = '', $header = 'from', $folder = 'all', $input = '', $color = '#000000', $text = '', $delete = false)
    {
        $this->add_texts('localization');

        if (!$text) {
            $text = Q($this->gettext('label_name'));
        }
        if (!$id) {
            $id = uniqid();
        }
        if (!$folder) {
            $folder = 'all';
        }
        // header select box
        $header_select = new html_select(array('name' => '_label_header[]', 'class' => 'label_header'));
        $header_select->add(Q($this->gettext('subject')), 'subject');
        $header_select->add(Q($this->gettext('from')), 'from');
        $header_select->add(Q($this->gettext('to')), 'to');
        $header_select->add(Q($this->gettext('cc')), 'cc');

        // folder search select
        $folder_search = new html_select(array('name' => '_folder_search[]', 'class' => 'folder_search'));

        $p = array('maxlength' => 100, 'realnames' => false);

        // get mailbox list
        $a_folders = $this->rc->storage->list_folders_subscribed();
        $delimiter = $this->rc->storage->get_hierarchy_delimiter();
        $a_mailboxes = array();

        foreach ($a_folders as $folder_list) {
            rcmail_build_folder_tree($a_mailboxes, $folder_list, $delimiter);
        }
        $folder_search->add(Q($this->gettext('label_all')), 'all');

        rcmail_render_folder_tree_select($a_mailboxes, $mbox, $p['maxlength'], $folder_search, $p['realnames']);

        // input field
        $search_info_text = $this->gettext('search_info');
        $input = new html_inputfield(array('name' => '_label_input[]', 'type' => 'text', 'autocomplete' => 'off', 'class' => 'watermark linput', 'value' => $input));
        $text = html::tag('input', array('name' => '_label_text[]', 'type' => 'text', 'title' => $search_info_text, 'class' => 'watermark linput', 'value' => $text));
        $select_color = html::tag('input', array('id' => $id, 'name' => '_label_color[]', 'type' => 'hidden', 'text' => 'hidden', 'class' => 'label_color_input', 'value' => $color));
        $id_field = html::tag('input', array('name' => '_label_id[]', 'type' => 'hidden', 'value' => $id));

        if (!$delete) {
            $button = html::a(array('href' => '#cc', 'class' => 'lhref', 'onclick' => 'return rcmail.command(\'save\',\'\',this)'), $this->gettext('add_row'));
        } else {
            $button = html::a(array('href' => '#cc', 'class' => 'lhref', 'onclick' => 'return rcmail.command(\'plugin.label_delete_row\',\'' . $id . '\')'), $this->gettext('delete_row'));
        }

        $content = $select_color . "&nbsp;" .
                $text . "&nbsp;" .
                $header_select->show($header) . "&nbsp;" .
                $this->gettext('label_matches') . "&nbsp;" .
                $input->show() . "&nbsp;" .
                $id_field . "&nbsp;" .
                $this->gettext('label_folder') . "&nbsp;" .
                $folder_search->show($folder, array('is_escaped' => true)) . "&nbsp;" .
                $button;

        return $content;
    }

    /**
     * Add a section label to the preferences section list
     *
     * @access  public
     */
    public function preferences_section_list($args)
    {
        $args['list']['label_preferences'] = array(
            'id' => 'label_preferences',
            'section' => Q($this->gettext('label_title'))
        );

        return($args);
    }

    /**
     * Save preferences
     *
     * @access  public
     */
    public function label_save($args)
    {
        if ($args['section'] != 'label_preferences') {
            return;
        }
        $rcmail = rcmail::get_instance();

        $id = get_input_value('_label_id', RCUBE_INPUT_POST);
        $header = get_input_value('_label_header', RCUBE_INPUT_POST);
        $folder = get_input_value('_folder_search', RCUBE_INPUT_POST);
        $input = get_input_value('_label_input', RCUBE_INPUT_POST);
        $color = get_input_value('_label_color', RCUBE_INPUT_POST);
        $text = get_input_value('_label_text', RCUBE_INPUT_POST);

        for ($i = 0; $i < count($header); $i++) {
            if (!in_array($header[$i], array('subject', 'from', 'to', 'cc'))) {
                $rcmail->output->show_message('message_label.headererror', 'error');
                return;
            }
            if (!preg_match('/^#[0-9a-fA-F]{2,6}$/', $color[$i])) {
                $rcmail->output->show_message('message_label.invalidcolor', 'error');
                return;
            }
            if ($input[$i] == '') {
                continue;
            }
            $prefs[] = array('id' => $id[$i], 'header' => $header[$i], 'folder' => $folder[$i], 'input' => $input[$i], 'color' => $color[$i], 'text' => $text[$i]);
        }
        $displayOptions = array();
        $displayOptions['_show_message_label_header'] = get_input_value('_show_message_label_header', RCUBE_INPUT_POST) == 1 ? true : false;
        $displayOptions['_show_message_mbox_info'] = get_input_value('_show_message_mbox_info', RCUBE_INPUT_POST) == 1 ? true : false;

        $args['prefs']['message_label'] = $prefs;
        $args['prefs']['message_label_display_options'] = $displayOptions;
        return($args);
    }

    public function action_check_mode()
    {
        $check = get_input_value('_check', RCUBE_INPUT_POST);
        $this->rc = rcmail::get_instance();

        $mode = $this->rc->config->get('message_label_mode');

        if ($check == 'highlighting') {
            $this->rc->user->save_prefs(array('message_label_mode' => 'highlighting'));
            $this->rc->output->command('display_message', $this->gettext('check_highlighting'), 'confirmation');
        } elseif ($check == 'labels') {
            $this->rc->user->save_prefs(array('message_label_mode' => 'labels'));
            $this->rc->output->command('display_message', $this->gettext('check_labels'), 'confirmation');
        } else {
            $this->rc->output->command('display_message', $this->gettext('check_error'), 'error');
        }
        $this->rc->output->send();
    }

    public function calc_brightness($color)
    {
        $rgb = $this->hex2RGB($color);

        return sqrt(
            $rgb["red"] * $rgb["red"] * .299 +
            $rgb["green"] * $rgb["green"] * .587 +
            $rgb["blue"] * $rgb["blue"] * .114
        );
    }

    //http://www.php.net/manual/en/function.hexdec.php#99478
    public function hex2RGB($hexStr, $returnAsString = false, $seperator = ',')
    {
        $hexStr = preg_replace("/[^0-9A-Fa-f]/", '', $hexStr); // Gets a proper hex string
        $rgbArray = array();
        if (strlen($hexStr) == 6) { //If a proper hex code, convert using bitwise operation. No overhead... faster
            $colorVal = hexdec($hexStr);
            $rgbArray['red'] = 0xFF & ($colorVal >> 0x10);
            $rgbArray['green'] = 0xFF & ($colorVal >> 0x8);
            $rgbArray['blue'] = 0xFF & $colorVal;
        } elseif (strlen($hexStr) == 3) { //if shorthand notation, need some string manipulations
            $rgbArray['red'] = hexdec(str_repeat(substr($hexStr, 0, 1), 2));
            $rgbArray['green'] = hexdec(str_repeat(substr($hexStr, 1, 1), 2));
            $rgbArray['blue'] = hexdec(str_repeat(substr($hexStr, 2, 1), 2));
        } else {
            return false; //Invalid hex color code
        }

        return $returnAsString ? implode($seperator, $rgbArray) : $rgbArray; // returns the rgb string or the associative array
    }
}
