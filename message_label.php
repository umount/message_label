<?php

/**
 * @version 0.2
 * @author Denis Sobolev <dns.sobol@gmail.com>
 *
 */

class message_label extends rcube_plugin
{
  public $task = 'mail|settings';
  public $rc;

  public function init() {
    $rcmail = rcmail::get_instance();
    $this->rc = $rcmail;

    $this->add_texts('localization', true);
    $this->add_hook('messages_list', array($this, 'message_set_label'));
    $this->add_hook('preferences_list', array($this, 'label_preferences'));
    $this->add_hook('preferences_save', array($this, 'label_save'));
    $this->add_hook('preferences_sections_list',array($this, 'preferences_section_list'));

    if ($rcmail->action == '' || $rcmail->action == 'show') {
        $labellink = $this->api->output->button(array('command' => 'plugin.label_redirect', 'type' => 'link', 'class' => 'active', 'content' => $this->gettext('label_pref')));
        $this->api->add_content(html::tag('li', array('class' => 'separator_above'), $labellink), 'mailboxoptions');
    }

    if ($rcmail->action == '')
      $this->add_hook('template_object_mailboxlist', array($this, 'folder_list_label'));

    $this->add_hook('startup', array($this, 'startup'));

    $this->register_action('plugin.message_label_redirect', array($this, 'message_label_redirect'));
    $this->register_action('plugin.message_label_search', array($this, 'message_label_search'));
    $this->register_action('plugin.message_label_mark', array($this, 'message_label_mark'));
    $this->register_action('plugin.message_label_move', array($this, 'message_label_move'));
    $this->register_action('plugin.message_label_delete', array($this, 'message_label_delete'));
    $this->register_action('plugin.not_label_folder_search', array($this, 'not_label_folder_search'));

    $this->register_action('plugin.message_label.check_mode', array($this, 'action_check_mode'));

    $this->include_script('message_label.js');
    $this->include_script('colorpicker/mColorPicker.js');

    $this->include_stylesheet($this->local_skin_path().'/message_label.css');
  }

  /**
   * Called when the application is initialized
   * redirect to internal function
   *
   * @access  public
   */
  function startup($args) {
    $search = get_input_value('_search', RCUBE_INPUT_GET);
    if (!isset($search)) $search = get_input_value('_search', RCUBE_INPUT_POST);

    $uid = get_input_value('_uid', RCUBE_INPUT_GET);
    $mbox =  get_input_value('_mbox', RCUBE_INPUT_GET);
    $page =  get_input_value('_page', RCUBE_INPUT_GET);
    $sort = get_input_value('_sort', RCUBE_INPUT_GET);

    if($search == 'labelsearch') {
      if($args['action'] == 'show' || $args['action'] == 'preview') {
        $uid = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'];
        $this->rc->output->redirect(array('_task' => 'mail', '_action' => $args['action'], '_mbox' => $mbox, '_uid' => $uid));
      }
      if($args['action'] == 'list') {
        $this->rc->output->command('label_search', '_page='.$page.'&_sort='.$sort);
        $this->rc->output->send();
        $args['abort'] = true;
      }
      if ($args['action'] == 'mark') {
        $flag = get_input_value('_flag', RCUBE_INPUT_POST);
        $uid = get_input_value('_uid', RCUBE_INPUT_POST);

        $post_str =  '_flag='.$flag.'&_uid='.$uid;
        if ($quiet = get_input_value('_quiet', RCUBE_INPUT_POST)) $post_str .= '&_quiet='.$quiet;
        if ($from = get_input_value('_from', RCUBE_INPUT_POST)) $post_str .= '&_from='.$from;
        if ($count = get_input_value('_count', RCUBE_INPUT_POST)) $post_str .= '&_count='.$count;
        if ($ruid = get_input_value('_ruid', RCUBE_INPUT_POST)) $post_str .= '&_ruid='.$ruid;

        $this->rc->output->command('label_mark', $post_str);
        $this->rc->output->send();
        $args['abort'] = true;
      }
      if ($args['action'] == 'moveto') {
        $target_mbox = get_input_value('_target_mbox', RCUBE_INPUT_POST);
        $uid = get_input_value('_uid', RCUBE_INPUT_POST);

        $post_str =  '_uid='.$uid.'&_target_mbox='.$target_mbox;

        $this->rc->output->command('label_move', $post_str);
        $this->rc->output->send();
        $args['abort'] = true;
      }
      if ($args['action'] == 'delete') {
        $uid = get_input_value('_uid', RCUBE_INPUT_POST);

        $post_str =  '_uid='.$uid;

        $this->rc->output->command('label_delete', $post_str);
        $this->rc->output->send();
        $args['abort'] = true;
      }

    }
    else if($_SESSION['label_folder_search']['uid_mboxes']) {
      // if action is empty then the page has been refreshed
      if(!$args['action']) {
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
  function message_set_label($p) {
    $prefs = $this->rc->config->get('message_label', array());

    if(!count($prefs) or !isset($p['messages']) or !is_array($p['messages'])) return $p;

    foreach($p['messages'] as $message) {
      $color='';
      foreach($prefs as $p) {
        if(stristr($message->$p['header'], $p['input'])) {
          $color = $p['color'];
          !empty($p['text']) ? $text= $p['text'] : $text='label';
        }
      }
      if(!empty($color)) {
        if (empty($message->list_flags['extra_flags'])) {
            $message->list_flags['extra_flags']['plugin_label']['color'] = $color;
            $message->list_flags['extra_flags']['plugin_label']['text'] = $text;
        }
      }
    }
    return $p;
  }

  /**
   * Serching mail by label id
   * js function label_search
   *
   * @access  public
   */
  function message_label_search(){
    // reset list_page and old search results
    $this->rc->imap->set_page(1);
    $this->rc->imap->set_search_set(NULL);
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
      $sort_col   = isset($_SESSION['sort_col'])   ? $_SESSION['sort_col']   : $this->rc->config->get('message_sort_col');
      $sort_order = isset($_SESSION['sort_order']) ? $_SESSION['sort_order'] : $this->rc->config->get('message_sort_order');
    }

    isset($id) ? $_SESSION['label_id'] = $id : $id = $_SESSION['label_id'];

    $prefs = $this->rc->config->get('message_label', array());

    // get search string
    $str = '';
    foreach($prefs as $p) {
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

    $subject[$header] = 'HEADER '.$header;
    $search = $srch ? trim($srch) : trim($str);

    if ($subject) {
      $search_str .= str_repeat(' OR', count($subject)-1);
      foreach ($subject as $sub)
        $search_str .= sprintf(" %s {%d}\r\n%s", $sub, strlen($search), $search);
        $_SESSION['search_mods'] = $subject;
    }

    $search_str = trim($search_str);
    $count = 0;
    $result_h = Array();
    $tmp_page_size = $this->rc->imap->page_size;
    $this->rc->imap->page_size = 500;

    if($use_saved_list && $_SESSION['all_folder_search']['uid_mboxes'])
        $result_h = $this->get_search_result();
    else
        $result_h = $this->perform_search($search_str,$folders);

    $this->rc->output->set_env('label_folder_search_active', 1);
    $this->rc->imap->page_size = $tmp_page_size;
    $count = count($result_h);

    $this->sort_search_result($result_h);

    $result_h = $this->get_paged_result($result_h, $page);

    // Make sure we got the headers
    if (!empty($result_h)) {
      rcmail_js_message_list($result_h);
      if ($search_str)
        $this->rc->output->show_message('searchsuccessful', 'confirmation',  array('nr' => $count));
    }
    // handle IMAP errors (e.g. #1486905)
    else  if ($err_code = $this->rc->imap->get_error_code()) {
      rcmail_display_server_error();
    }
    else {
      $this->rc->output->show_message('searchnomatch', 'notice');
    }

    // update message count display and reset threads
    $this->rc->output->set_env('search_request', "labelsearch");
    $this->rc->output->set_env('messagecount', $count);
    $this->rc->output->set_env('pagecount', ceil($count/$this->rc->imap->page_size));
    $this->rc->output->set_env('threading', (bool)false);
    $this->rc->output->set_env('threads', 0);

    $this->rc->output->command('set_rowcount', rcmail_get_messagecount_text($count, $page));

    $this->rc->output->send();
    exit;
  }

  /**
   * Perform the all folder search
   *
   * @param   string   Search string
   * @return  array    Indexed array with message header objects
   * @access  private
   */
  private function perform_search($search_string,$folders) {
    $result_h = array();
    $uid_mboxes = array();
    $id = 1;

    // Search all folders and build a final set
    if ($folders[0] == 'all' || empty($folders))
        $folders_search = $this->rc->imap->list_mailboxes();
    else
        $folders_search = $folders;

    foreach($folders_search as $mbox) {

      if($mbox == $this->rc->config->get('trash_mbox'))
        continue;

      $this->rc->imap->set_mailbox($mbox);
      $this->rc->imap->search($mbox, $search_string, RCMAIL_CHARSET, $_SESSION['sort_col']);
      $result = $this->rc->imap->list_headers($mbox, 1, $_SESSION['sort_col'], $_SESSION['sort_order']);

      foreach($result as $row) {
        $uid_mboxes[$id] = array('uid' => $row->uid, 'mbox' => $mbox);
        $row->uid = $id;

        $result_h[] = $row;
        $id++;
      }
    }

    $_SESSION['label_folder_search']['uid_mboxes'] = $uid_mboxes;
    $this->rc->output->set_env('label_folder_search_uid_mboxes', $uid_mboxes);

    return $result_h;
  }

  /**
   * Slice message header array to return only the messages corresponding the page parameter
   *
   * @param   array    Indexed array with message header objects
   * @param   int      Current page to list
   * @return  array    Sliced array with message header objects
   * @access  public
   */
  function get_paged_result($result_h, $page) {
    // Apply page size rules
    if(count($result_h) > $this->rc->imap->page_size)
      $result_h = array_slice($result_h, ($page-1)*$this->rc->imap->page_size, $this->rc->imap->page_size);

    return $result_h;
  }

  /**
   * Called when message statuses are modified or they have been flagged
   *
   * @access  public
   */
  function message_label_mark() {

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
        $old_pages = ceil($old_count / $this->rc->imap->page_size);
        $count = sizeof(explode(',', $uids));
      }

      $uids = explode(',', $uids);

      // mark each uid individually because the mailboxes may differ
      foreach($uids as $uid) {
        $mbox = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['mbox'];
        $this->rc->imap->set_mailbox($mbox);
        $marked = $this->rc->imap->set_flag($_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'], $flag);

        if (!$marked) {
          // send error message
          if ($_POST['_from'] != 'show')
            $this->rc->output->command('list_mailbox');

          $this->rc->output->show_message('errormarking', 'error');
          $this->rc->output->send();
          exit;
        }
        else if (empty($_POST['_quiet'])) {
          $this->rc->output->show_message('messagemarked', 'confirmation');
        }
      }

      $skip_deleted = $this->rc->config->get('skip_deleted');
      $read_when_deleted = $this->rc->config->get('read_when_deleted');

      if($flag == 'DELETED' && $read_when_deleted && !empty($_POST['_ruid'])) {
        $uids = get_input_value('_ruid', RCUBE_INPUT_POST);
        $uids = explode(',', $uids);

        foreach($uids as $uid) {
          $mbox = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['mbox'];
          $this->rc->imap->set_mailbox($mbox);
          $read = $this->rc->imap->set_flag($_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'], 'SEEN');

          if ($read != -1 && !$skip_deleted)
            $this->rc->output->command('flag_deleted_as_read', $uid);
        }
      }

      if ($flag == 'SEEN' || $flag == 'UNSEEN' || ($flag == 'DELETED' && !$skip_deleted)) {
        // just update unread count for all mailboxes, easier than figuring out which were changed
        $mbox_names = $this->rc->imap->list_mailboxes();

        foreach($mbox_names as $mbox)
          $this->rc->output->command('set_unread_count', $mbox, $this->rc->imap->messagecount($mbox, 'UNSEEN'), ($mbox == 'INBOX'));
      }
      else if ($flag == 'DELETED' && $skip_deleted) {
        if ($_POST['_from'] == 'show') {
          if ($next = get_input_value('_next_uid', RCUBE_INPUT_GPC))
            $this->rc->output->command('show_message', $next);
          else
            $this->rc->output->command('command', 'list');
        } else {
          // refresh saved search set after moving some messages
          if (($search_request = get_input_value('_search', RCUBE_INPUT_GPC)) && $_SESSION['label_folder_search']['uid_mboxes']) {
            $_SESSION['search'][$search_request] = $this->perform_search($this->rc->imap->search_string);
          }
          $msg_count      = count($_SESSION['label_folder_search']['uid_mboxes']);
          $pages          = ceil($msg_count / $this->rc->imap->page_size);
          $nextpage_count = $old_count - $this->rc->imap->page_size * $_SESSION['page'];
          $remaining      = $msg_count - $this->rc->imap->page_size * ($_SESSION['page'] - 1);
          // jump back one page (user removed the whole last page)
          if ($_SESSION['page'] > 1 && $nextpage_count <= 0 && $remaining == 0) {
            $this->rc->imap->set_page($_SESSION['page']-1);
            $_SESSION['page'] = $this->rc->imap->list_page;
            $jump_back = true;
          }
          // update message count display
          $this->rc->output->set_env('messagecount', $msg_count);
          $this->rc->output->set_env('current_page', $this->rc->imap->list_page);
          $this->rc->output->set_env('pagecount', $pages);
          // update mailboxlist
          foreach($this->rc->imap->list_mailboxes() as $mbox) {
            $unseen_count = $msg_count ? $this->rc->imap->messagecount($mbox, 'UNSEEN') : 0;
            $this->rc->output->command('set_unread_count', $mbox, $unseen_count, ($mbox == 'INBOX'));
          }
          $this->rc->output->command('set_rowcount', rcmail_get_messagecount_text($msg_count));

          // add new rows from next page (if any)
          if (($jump_back || $nextpage_count > 0)) {
            $sort_col   = isset($_SESSION['sort_col'])   ? $_SESSION['sort_col']   : $this->rc->config->get('message_sort_col');
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
  function get_search_result() {
    $result_h = array();

    foreach($_SESSION['label_folder_search']['uid_mboxes'] as $id => $uid_mbox) {
      $uid = $uid_mbox['uid'];
      $mbox = $uid_mbox['mbox'];

      $result_h = $this->rc->imap->list_headers($mbox, 1, $_SESSION['sort_col'], $_SESSION['sort_order']);
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
  function message_label_move() {
    if (!empty($_POST['_uid']) && !empty($_POST['_target_mbox'])) {
      $uids = get_input_value('_uid', RCUBE_INPUT_POST);
      $target = get_input_value('_target_mbox', RCUBE_INPUT_POST);
      $uids = explode(',', $uids);

      foreach($uids as $uid) {
        $mbox = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['mbox'];
        $this->rc->imap->set_mailbox($mbox);
        $ruid = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'];

        // flag messages as read before moving them
        if ($this->rc->config->get('read_when_deleted') && $target == $this->rc->config->get('trash_mbox'))
          $this->rc->imap->set_flag($ruid, 'SEEN');

        $moved = $this->rc->imap->move_message($ruid, $target, $mbox);
      }

      if (!$moved) {
        // send error message
        if ($_POST['_from'] != 'show')
          $this->rc->output->command('list_mailbox');

        $this->rc->output->show_message('errormoving', 'error');
        $this->rc->output->send();
        exit;
      }
      else {
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
  function message_label_delete() {
    if (!empty($_POST['_uid'])) {
      $uids = get_input_value('_uid', RCUBE_INPUT_POST);
      $uids = explode(',', $uids);

      foreach($uids as $uid) {
        $mbox = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['mbox'];
        $this->rc->imap->set_mailbox($mbox);
        $ruid = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'];

        $del = $this->rc->imap->delete_message($ruid, $mbox);
      }

      if (!$del) {
        // send error message
        if ($_POST['_from'] != 'show')
          $this->rc->output->command('list_mailbox');

        rcmail_display_server_error('errordeleting');
        $this->rc->output->send();
        exit;
      }
      else {
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
  function not_label_folder_search() {
    $_SESSION['label_folder_search']['uid_mboxes'] = 0;
    $_SESSION['label_id'] = 0;
  }

  /**
   * Sort result header array by date, size, subject, or from using a bubble sort
   *
   * @param   array    Indexed array with message header objects
   * @access  private
   */
  private function sort_search_result(&$result_h) {
    // Bubble sort! <3333 (ideally sorting and page trimming should be done
    // in js but php has the convienent rcmail_js_message_list function
    for($x = 0; $x < count($result_h); $x++) {
      for($y = 0; $y < count($result_h); $y++) {
        // ick can a variable name be put into a variable so i can get this out of 2 loops
        switch($_SESSION['sort_col']) {
          case 'date':    $first = $result_h[$x]->timestamp;
                          $second = $result_h[$y]->timestamp;
                          break;
          case 'size':    $first = $result_h[$x]->size;
                          $second = $result_h[$y]->size;
                          break;
          case 'subject': $first = strtolower($result_h[$x]->subject);
                          $second = strtolower($result_h[$y]->subject);
                          break;
          case 'from':    $first = strtolower($result_h[$x]->from);
                          $second = strtolower($result_h[$y]->from);
                          break;
        }

        if($first < $second) {
          $hold = $result_h[$x];
          $result_h[$x] = $result_h[$y];
          $result_h[$y] = $hold;
        }
      }
    }

    if($_SESSION['sort_order'] == 'DESC')
      $result_h = array_reverse($result_h);
  }

  /**
   * Redirect function on arrival label to folder list
   *
   * @access  public
   */
  function message_label_redirect(){
    $this->rc->output->redirect(array('_task'=>'settings','_action'=>'label_preferences'));
  }

  /**
   * Label list to folder list menu
   *
   * @access  public
   */
  function folder_list_label($args) {
    $args['content'] .= html::div(array('id'=>'mailboxlist-title', 'class'=>'boxtitle label_header_menu'), $this->gettext('label_title'));
    $prefs = $this->rc->config->get('message_label', array());
    if (count($prefs) > 0)
      foreach($prefs as $p) {
        $args['content'] .= html::div('lmenu',
            html::tag('span',array('class'=>'lmessage', 'style'=>'background-color:'.$p['color']),'').
            html::a(array('href'=>'#', 'onclick'=>'return rcmail.command(\'plugin.label_search\',\'_id='.$p['id'].'\')'),$p['text'])
          );
      }
    else
      $args['content'] .= html::div('lmenu',html::a(array('href'=>'#','onclick'=>'return rcmail.command(\'plugin.label_redirect\',\'true\',true)'),$this->gettext('label_create')));

    $mode = $this->rc->config->get('message_label_mode');
    if (empty($mode)) $mode = 'labels';
    $this->rc->output->set_env('message_label_mode', $mode);

    return $args;
  }

  /**
   * Display label configuratin in user preferences tab
   *
   * @access  public
   */
  function label_preferences($args) {
    if($args['section'] == 'label_preferences') {

      $this->rc = rcmail::get_instance();
      $this->rc->imap_connect();

      $args['blocks']['select_mode'] = array('options' => array(), 'name' => Q($this->gettext('label_mode')));

      $radio = new html_radiobutton(array('name' => 'select_mode'));

      $mode = $this->rc->config->get('message_label_mode');
      if (empty($mode)) $mode = 'labels';

      $selector = $radio->show($mode, array('value' => 'labels', 'onclick' => 'return rcmail.command(\'plugin.message_label.check_mode\', \'labels\')')). Q($this->gettext('labels'));
      $selector .= $radio->show($mode, array('value' => 'highlighting', 'onclick' => 'return rcmail.command(\'plugin.message_label.check_mode\', \'highlighting\')')). Q($this->gettext('highlighting'));

      $args['blocks']['select_mode']['options'][0] = array('title' => '', 'content' => $selector);

      $mode = $this->rc->config->get('message_label_mode');

      $args['blocks']['create_label'] =  array('options' => array(), 'name' => Q($this->gettext('label_create')));
      $args['blocks']['list_label'] =  array('options' => array(), 'name' => Q($this->gettext('label_title')));

      $i = 0;
      $prefs = $this->rc->config->get('message_label', array());

      //insert empty row
      $args['blocks']['create_label']['options'][$i++] = array('title' => '', 'content' => $this->get_form_row());

      foreach($prefs as $p) {
        $args['blocks']['list_label']['options'][$i++] = array(
          'title'   => '',
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
  private function get_form_row($id = '', $header = 'from', $folder = 'all', $input = '', $color = '#000000', $text = '', $delete = false) {

    $this->add_texts('localization');

    if (!$text) $text = Q($this->gettext('label_name'));
    //if (!$input) $input = Q($this->gettext('label_matches'));
    if (!$id) $id = uniqid();
    if (!$folder) $folder = 'all';
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
    $mbox_name = $this->rc->imap->get_mailbox_name();

    // build the folders tree
    // get mailbox list
    $a_folders = $this->rc->imap->list_mailboxes();
    $delimiter = $this->rc->imap->get_hierarchy_delimiter();
    $a_mailboxes = array();

    foreach ($a_folders as $folder_list)
      rcmail_build_folder_tree($a_mailboxes, $folder_list, $delimiter);

    $folder_search->add(Q($this->gettext('label_all')), 'all');

    rcmail_render_folder_tree_select($a_mailboxes, $mbox, $p['maxlength'], $folder_search, $p['realnames']);

    // input field
    $input = new html_inputfield(array('name' => '_label_input[]', 'type' => 'text', 'autocomplete' => 'off', 'class' => 'watermark linput', 'value' => $input));
    $text = html::tag('input', array('name' => '_label_text[]' ,'type' => 'text' , 'title' => 'Найти информацию', 'class' => 'watermark linput', 'value' => $text));
    $select_color = html::tag('input', array('id' => $id ,'name' => '_label_color[]' ,'type' => 'color' ,'text' => 'hidden', 'class' => 'label_color_input', 'value' => $color));
    $id_field =   html::tag('input', array('name' => '_label_id[]' ,'type' => 'hidden' ,'value' => $id));

    if (!$delete) {
      $button = html::a(array('href'=>'#cc', 'class'=>'lhref', 'onclick' => 'return rcmail.command(\'save\',\'\',this)'),$this->gettext('add_row'));
    } else {
      $button = html::a(array('href'=>'#cc', 'class'=>'lhref', 'onclick' => 'return rcmail.command(\'plugin.label_delete_row\',\''.$id.'\')'), $this->gettext('delete_row'));
    }

    $content = $select_color .
      $text .
      $header_select->show($header) .
      $this->gettext('label_matches') .
      $input->show() .
      $id_field .
      $this->gettext('label_folder') .
      $folder_search->show($folder) .
      $button;

    return $content;
  }

  /**
   * Add a section label to the preferences section list
   *
   * @access  public
   */
  function preferences_section_list($args) {
    $args['list']['label_preferences'] = array(
      'id'      => 'label_preferences',
      'section' => Q($this->gettext('label_title'))
      );
    return($args);
  }

  /**
   * Save preferences
   *
   * @access  public
   */
  function label_save($args) {
    if($args['section'] != 'label_preferences') return;

    $rcmail = rcmail::get_instance();

    $id      = get_input_value('_label_id', RCUBE_INPUT_POST);
    $header  = get_input_value('_label_header', RCUBE_INPUT_POST);
    $folder  = get_input_value('_folder_search', RCUBE_INPUT_POST);
    $input   = get_input_value('_label_input', RCUBE_INPUT_POST);
    $color   = get_input_value('_label_color', RCUBE_INPUT_POST);
    $text    = get_input_value('_label_text', RCUBE_INPUT_POST);

    for($i=0; $i < count($header); $i++) {
      if(!in_array($header[$i], array('subject', 'from', 'to', 'cc'))) {
        $rcmail->output->show_message('message_label.headererror', 'error');
        return;
      }
      if(!preg_match('/^#[0-9a-fA-F]{2,6}$/', $color[$i])) {
        $rcmail->output->show_message('message_label.invalidcolor', 'error');
        return;
      }
      if($input[$i] == '') {
        continue;
      }
      $prefs[] = array('id' => $id[$i], 'header' => $header[$i], 'folder' => $folder[$i], 'input' => $input[$i], 'color' => $color[$i], 'text' => $text[$i]);
    }

    $args['prefs']['message_label'] = $prefs;
    return($args);
  }

  function action_check_mode(){
    $check = get_input_value('_check', RCUBE_INPUT_POST);
    $this->rc = rcmail::get_instance();

    $mode = $this->rc->config->get('message_label_mode');

    if ($check == 'highlighting') {
      $this->rc->user->save_prefs(array('message_label_mode' => 'highlighting'));
      $this->rc->output->command('display_message', $this->gettext('check_highlighting'), 'confirmation');
    } else if ($check == 'labels') {
      $this->rc->user->save_prefs(array('message_label_mode' => 'labels'));
      $this->rc->output->command('display_message', $this->gettext('check_labels'), 'confirmation');
    } else {
      $this->rc->output->command('display_message', $this->gettext('check_error'), 'error');
    }
    $this->rc->output->send();
  }

}
?>
