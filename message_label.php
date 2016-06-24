<?php

/**
 * @version 1.2
 * @author Denis Sobolev <dns.sobol@gmail.com>
 *
 */
class message_label extends rcube_plugin {

	public $task = 'mail|settings';
	public $rc;

	public function init() {
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
		}
	}

	/**
	 * Called when the application is initialized
	 * redirect to internal function
	 *
	 * @access  public
	 */
	function startup($args) {
		$search = rcube_utils::get_input_value('_search', rcube_utils::INPUT_GET);
		if (!isset($search)) {
			$search = rcube_utils::get_input_value('_search', rcube_utils::INPUT_POST);
		}

		$uid = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_GET);
		$mbox = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_GET);
		$page = rcube_utils::get_input_value('_page', rcube_utils::INPUT_GET);
		$sort = rcube_utils::get_input_value('_sort', rcube_utils::INPUT_GET);

		if ($search == 'labelsearch') {
			if (($args['action'] == 'show' || $args['action'] == 'preview') && !empty($uid)) {
				$uid = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'];
				$this->rc->output->redirect(array('_task' => 'mail', '_action' => $args['action'], '_mbox' => $mbox, '_uid' => $uid));
			}
			if ($args['action'] == 'compose') {
				$draft_uid = rcube_utils::get_input_value('_draft_uid', rcube_utils::INPUT_GET);
				if (!empty($draft_uid)) {
					$draft_uid = $_SESSION['label_folder_search']['uid_mboxes'][$draft_uid]['uid'];
					$this->rc->output->redirect(array('_task' => 'mail', '_action' => $args['action'], '_mbox' => $mbox, '_draft_uid' => $draft_uid));
					$this->rc->output->send();
				} elseif (!empty($uid)) {
					$uid = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'];
					$this->rc->output->redirect(array('_task' => 'mail', '_action' => $args['action'], '_mbox' => $mbox, '_uid' => $uid));
					$this->rc->output->send();
				}
			}
			if ($args['action'] == 'list') {
				$this->rc->output->command('label_search', '_page=' . $page . '&_sort=' . $sort);
				$this->rc->output->send();
				$args['abort'] = true;
			}
			if ($args['action'] == 'mark') {
				$flag = rcube_utils::get_input_value('_flag', rcube_utils::INPUT_POST);
				$uid = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST);

				$post_str = '_flag=' . $flag . '&_uid=' . $uid;
				if ($quiet = rcube_utils::get_input_value('_quiet', rcube_utils::INPUT_POST)){
					$post_str .= '&_quiet=' . $quiet;
				}
				if ($from = rcube_utils::get_input_value('_from', rcube_utils::INPUT_POST)){
					$post_str .= '&_from=' . $from;
				}
				if ($count = rcube_utils::get_input_value('_count', rcube_utils::INPUT_POST)){
					$post_str .= '&_count=' . $count;
				}
				if ($ruid = rcube_utils::get_input_value('_ruid', rcube_utils::INPUT_POST)){
					$post_str .= '&_ruid=' . $ruid;
				}

				$this->rc->output->command('label_mark', $post_str);
				$this->rc->output->send();
				$args['abort'] = true;
			}
			if ($args['action'] == 'moveto') {
				$target_mbox = rcube_utils::get_input_value('_target_mbox', rcube_utils::INPUT_POST);
				$uid = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST);

				$post_str = '_uid=' . $uid . '&_target_mbox=' . $target_mbox;

				$this->rc->output->command('label_move', $post_str);
				$this->rc->output->send();
				$args['abort'] = true;
			}
			if ($args['action'] == 'delete') {
				$uid = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST);

				$post_str = '_uid=' . $uid;

				$this->rc->output->command('label_delete', $post_str);
				$this->rc->output->send();
				$args['abort'] = true;
			}
		} else if ($_SESSION['label_folder_search']['uid_mboxes']) {
			// if action is empty then the page has been refreshed
			if (!$args['action']) {
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
		//write_log('debug', preg_replace('/\r\n$/', '', print_r($prefs, true)));

		if (!count($prefs) or !isset($p['messages']) or !is_array($p['messages'])){
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

			if (!empty($message->flags))
				foreach ($message->flags as $flag => $set_val) {
					if (stripos($flag, 'ulabels') === 0) {
						$flag_id = str_ireplace('ulabels_', '', $flag);
						if (!empty($ret_key)) {
							foreach ($ret_key as $key_search => $value) {
								$id = $value['id'];
								if ($prefs[$id]['id'] == strtolower($flag_id) && $value['type'] == 'filter'){
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
										if ($value_filter == $searh_filter){
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

			//write_log('debug', preg_replace('/\r\n$/', '', print_r($ret_key,true)));

			if (!empty($ret_key)) {
				sort($ret_key);
				$message->list_flags['extra_flags']['plugin_label'] = array();
				$k = 0;
				foreach ($ret_key as $label_id) {
					!empty($p['text']) ? $text = $p['text'] : $text = 'label';
					$id = $label_id['id'];
					$type = $label_id['type'];
					$message->list_flags['extra_flags']['plugin_label'][$k]['color'] = $prefs[$id]['color'];
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
	function message_label_imap_set() {
		if (($uids = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST)) && ($flag = rcube_utils::get_input_value('_flag', rcube_utils::INPUT_POST))) {
			$flag = $a_flags_map[$flag] ? $a_flags_map[$flag] : strtoupper($flag);
			$type = rcube_utils::get_input_value('_type', rcube_utils::INPUT_POST);
			$label_search = rcube_utils::get_input_value('_label_search', rcube_utils::INPUT_POST);

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
					} else if (empty($_POST['_quiet'])) {
						$this->rc->output->show_message('messagemarked', 'confirmation');
					}
				}
			} else {
				if ($type == 'flabel') {
					$unlabel = 'UN' . $flag;
					$marked = $this->rc->imap->set_flag($uids, $unlabel);
					$unfiler = 'U' . $flag;
					$marked = $this->rc->imap->set_flag($uids, $unfiler);
				} else{
					$marked = $this->rc->imap->set_flag($uids, $flag);
				}

				if (!$marked) {
					// send error message
					if ($_POST['_from'] != 'show'){
						$this->rc->output->command('list_mailbox');
					}
					$this->rc->display_server_error('errormarking');
					$this->rc->output->send();
					exit;
				} else if (empty($_POST['_quiet'])) {
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
	function message_label_search() {
		// reset list_page and old search results
		$this->rc->imap->set_page(1);
		$this->rc->imap->set_search_set(NULL);
		$_SESSION['page'] = 1;
		$page = rcube_utils::get_input_value('_page', rcube_utils::INPUT_POST);

		$page = $page ? $page : 1;

		$id = rcube_utils::get_input_value('_id', rcube_utils::INPUT_POST);

		// is there a sort type for this request?
		if ($sort = rcube_utils::get_input_value('_sort', rcube_utils::INPUT_POST)) {
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
		$result_h = Array();
		$tmp_page_size = $this->rc->storage->get_pagesize();
		//$this->rc->imap->page_size = 500;

		if ($use_saved_list && $_SESSION['all_folder_search']['uid_mboxes']) {
			$result_h = $this->get_search_result();
		} else {
			$result_h = $this->perform_search($search_str, $folders, $id);
		}

		$this->rc->output->set_env('label_folder_search_active', 1);
		//$this->rc->imap->page_size = $tmp_page_size;
		$count = count($result_h);

		$this->sort_search_result($result_h);

		$result_h = $this->get_paged_result($result_h, $page);

		// Make sure we got the headers
		if (!empty($result_h)) {
			rcmail_js_message_list($result_h, false);
			if ($search_str) {
				$this->rc->output->show_message('searchsuccessful', 'confirmation', array('nr' => $count));
			}
		}
		// handle IMAP errors (e.g. #1486905)
		else if ($err_code = $this->rc->imap->get_error_code()) {
			$this->rc->display_server_error();
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

	/**
	 * Perform the all folder search
	 *
	 * @param   string   Search string
	 * @return  array	Indexed array with message header objects
	 * @access  private
	 */
	private function perform_search($search_string, $folders, $label_id) {
		$result_h = array();
		$uid_mboxes = array();
		$id = 1;
		$result = array();
		$result_label = array();

		$search_string_label = 'KEYWORD "$labels_' . $label_id . '"';

		// Search all folders and build a final set
		if ($folders[0] == 'all' || empty($folders)){
			$folders_search = $this->rc->imap->list_folders_subscribed();
		} else {
			$folders_search = $folders;
		}

		foreach ($folders_search as $mbox) {

			if ($mbox == $this->rc->config->get('trash_mbox')){
				continue;
			}

			$this->rc->storage->set_folder($mbox);

			$this->rc->storage->search($mbox, $search_string, RCMAIL_CHARSET, $_SESSION['sort_col']);
			$result = $this->rc->storage->list_messages($mbox, 1, $_SESSION['sort_col'], $_SESSION['sort_order']);

			$this->rc->storage->search($mbox, $search_string_label, RCMAIL_CHARSET, $_SESSION['sort_col']);
			$result_label = $this->rc->storage->list_messages($mbox, 1, $_SESSION['sort_col'], $_SESSION['sort_order']);

			if (!empty($result_label)){
				foreach ($result_label as $header_obj) {
					$add = true;
					foreach ($result as $result_obj){
						if ($result_obj->id == $header_obj->id) {
							$add = false;
							break;
						}
					}
					if ($add){
						array_push($result, $header_obj);
					}
				}
			}

			foreach ($result as $row) {
				$uid_mboxes[$id] = array('uid' => $row->uid, 'mbox' => $mbox);
				$row->uid = $id;
				$add_res = 1;
				$add_labels = 0;

				if (!empty($row->flags)){
					foreach ($row->flags as $flag => $set_val) {
						if ($flag == strtoupper('ulabels_' . $label_id)) {
							$add_res = 0;
							break;
						}
					}
				}
				if ($add_res) {
					$result_h[] = $row;
					$id++;
				} else {
					foreach ($row->flags as $flag => $set_val){
						if ($flag == strtoupper('labels_' . $label_id)) {
							$add_labels = 1;
							break;
						}
					}
					if ($add_labels) {
						$result_h[] = $row;
						$id++;
					}
				}
			}
		}

		foreach ($result_h as $set_flag) {
			$set_flag->flags['skip_mbox_check'] = true;
		}

		//write_log('debug', preg_replace('/\r\n$/', '', print_r($result_h,true)));

		$_SESSION['label_folder_search']['uid_mboxes'] = $uid_mboxes;
		$this->rc->output->set_env('label_folder_search_uid_mboxes', $uid_mboxes);

		return $result_h;
	}

	/**
	 * Slice message header array to return only the messages corresponding the page parameter
	 *
	 * @param   array	Indexed array with message header objects
	 * @param   int	  Current page to list
	 * @return  array	Sliced array with message header objects
	 * @access  public
	 */
	function get_paged_result($result_h, $page) {
		// Apply page size rules
		if (count($result_h) > $this->rc->storage->get_pagesize()){
			$result_h = array_slice($result_h, ($page - 1) * $this->rc->storage->get_pagesize(), $this->rc->storage->get_pagesize());
		}

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

		if (($uids = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST)) && ($flag = rcube_utils::get_input_value('_flag', rcube_utils::INPUT_POST))) {

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
					if ($_POST['_from'] != 'show'){
						$this->rc->output->command('list_mailbox');
					}

					$this->rc->output->show_message('errormarking', 'error');
					$this->rc->output->send();
					exit;
				} else if (empty($_POST['_quiet'])) {
					$this->rc->output->show_message('messagemarked', 'confirmation');
				}
			}

			$skip_deleted = $this->rc->config->get('skip_deleted');
			$read_when_deleted = $this->rc->config->get('read_when_deleted');

			if ($flag == 'DELETED' && $read_when_deleted && !empty($_POST['_ruid'])) {
				$uids = rcube_utils::get_input_value('_ruid', rcube_utils::INPUT_POST);
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

				foreach ($mbox_names as $mbox)
					$this->rc->output->command('set_unread_count', $mbox, $this->rc->imap->messagecount($mbox, 'UNSEEN'), ($mbox == 'INBOX'));
			} else if ($flag == 'DELETED' && $skip_deleted) {
				if ($_POST['_from'] == 'show') {
					if ($next = rcube_utils::get_input_value('_next_uid', RCUBE_INPUT_GPC)){
						$this->rc->output->command('show_message', $next);
					} else {
						$this->rc->output->command('command', 'list');
					}
				} else {
					// refresh saved search set after moving some messages
					if (($search_request = rcube_utils::get_input_value('_search', RCUBE_INPUT_GPC)) && $_SESSION['label_folder_search']['uid_mboxes']) {
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
					$this->rc->output->command('set_rowcount', $this->rc->get_messagecount_text($msg_count));

					// add new rows from next page (if any)
					if (($jump_back || $nextpage_count > 0)) {
						$sort_col = isset($_SESSION['sort_col']) ? $_SESSION['sort_col'] : $this->rc->config->get('message_sort_col');
						$sort_order = isset($_SESSION['sort_order']) ? $_SESSION['sort_order'] : $this->rc->config->get('message_sort_order');

						$a_headers = $this->get_search_result();
						$this->sort_search_result($a_headers);
						$a_headers = array_slice($a_headers, $sort_order == 'DESC' ? 0 : -$count, $count);
						$this->rc->js_message_list($a_headers, false, false);
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
	 * @return  array	Indexed array with message header objects
	 * @access  public
	 */
	function get_search_result() {
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
	function message_label_move() {
		if (!empty($_POST['_uid']) && !empty($_POST['_target_mbox'])) {
			$uids = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST);
			$target = rcube_utils::get_input_value('_target_mbox', rcube_utils::INPUT_POST);
			$uids = explode(',', $uids);

			foreach ($uids as $uid) {
				$mbox = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['mbox'];
				$this->rc->storage->set_folder($mbox);
				$ruid = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'];

				// flag messages as read before moving them
				if ($this->rc->config->get('read_when_deleted') && $target == $this->rc->config->get('trash_mbox')){
					$this->rc->imap->set_flag($ruid, 'SEEN');
				}

				$moved = $this->rc->imap->move_message($ruid, $target, $mbox);
			}

			if (!$moved) {
				// send error message
				if ($_POST['_from'] != 'show'){
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
	function message_label_delete() {
		if (!empty($_POST['_uid'])) {
			$uids = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST);
			$uids = explode(',', $uids);

			foreach ($uids as $uid) {
				$mbox = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['mbox'];
				$this->rc->storage->set_folder($mbox);
				$ruid = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'];

				$del = $this->rc->imap->delete_message($ruid, $mbox);
			}

			if (!$del) {
				// send error message
				if ($_POST['_from'] != 'show')
					$this->rc->output->command('list_mailbox');

				$this->rc->display_server_error('errordeleting');
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
	function not_label_folder_search() {
		$_SESSION['label_folder_search']['uid_mboxes'] = 0;
		$_SESSION['label_id'] = 0;
	}

	/**
	 * Sort result header array by date, size, subject, or from using a bubble sort
	 *
	 * @param   array	Indexed array with message header objects
	 * @access  private
	 */
	private function sort_search_result(&$result_h) {
		// Bubble sort! <3333 (ideally sorting and page trimming should be done
		// in js but php has the convienent rcmail::js_message_list function
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

		if ($_SESSION['sort_order'] == 'DESC'){
			$result_h = array_reverse($result_h);
		}
	}

	/**
	 * Redirect function on arrival label to folder list
	 *
	 * @access  public
	 */
	function message_label_redirect() {
		$this->rc->output->redirect(array('_task' => 'settings', '_action' => 'label_preferences'));
	}

	/**
	 * render labbel menu for markmessagemenu
	 */
	function render_labels_menu($val) {
		$prefs = $this->rc->config->get('message_label', array());
		$input = new html_checkbox();
		if (count($prefs) > 0) {
			$attrib['class'] = 'toolbarmenu labellistmenu';
			$ul .= html::tag('li', array('class' => 'separator_below'), $this->gettext('label_set'));

			foreach ($prefs as $p) {
				$ul .= html::tag('li', null, html::a(
										array('class' => 'labellink active',
									'href' => '#',
									'onclick' => 'rcmail.label_messages(\'' . $p['id'] . '\')'), html::tag('span', array('class' => 'listmenu', 'style' => 'background-color:' . $p['color']), '') . $p['text']));
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
	function folder_list_label($args) {

		$args['content'] .= html::div(array('id' => 'labels-title', 'class' => 'boxtitle label_header_menu'), $this->gettext('label_title') . html::tag('span', array('class' => 'drop_arrow'), ''));
		$prefs = $this->rc->config->get('message_label', array());

		if (!strlen($attrib['id']))
			$attrib['id'] = 'labellist';

		$display_label = $this->rc->config->get('message_label_display');
		if ($display_label == 'false')
			$style = 'display: none;';

		if (count($prefs) > 0) {
			$table = new html_table($attrib);
			foreach ($prefs as $p) {
				$table->add_row(array('id' => 'rcmrow' . $p['id'], 'class' => 'labels_row'));
				$table->add(array('class' => 'labels_color'), html::tag('span', array('class' => 'lmessage', 'style' => 'background-color:' . $p['color']), ''));
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
		//write_log('debug', preg_replace('/\r\n$/', '', print_r($this->rc->imap->conn->flags,true)));
		// add id to message label table if not specified
		$this->rc->output->add_gui_object('labellist', $attrib['id']);

		return $args;
	}

	function display_list_preferences() {
		$display_label = rcube_utils::get_input_value('_display_label', rcube_utils::INPUT_POST);
		$display_folder = rcube_utils::get_input_value('_display_folder', rcube_utils::INPUT_POST);

		if (!empty($display_label)) {
			if ($display_label == 'true') {
				$this->rc->user->save_prefs(array('message_label_display' => 'true'));
				$this->rc->output->command('display_message', $this->gettext('display_label_on'), 'confirmation');
			} else if ($display_label == 'false') {
				$this->rc->user->save_prefs(array('message_label_display' => 'false'));
				$this->rc->output->command('display_message', $this->gettext('display_label_off'), 'confirmation');
			} else {
				$this->rc->output->command('display_message', $this->gettext('display_error'), 'error');
			}
			$this->rc->output->send();
		}
	}

	function flag_message_load($p) {
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
	function label_preferences($args) {
		if ($args['section'] == 'label_preferences') {

			$this->rc = rcmail::get_instance();
			$this->rc->storage_connect();

			$args['blocks']['create_label'] = array('options' => array(), 'name' => rcube::Q($this->gettext('label_create')));
			$args['blocks']['list_label'] = array('options' => array(), 'name' => rcube::Q($this->gettext('label_title')));

			$i = 0;
			$prefs = $this->rc->config->get('message_label', array());

			//insert empty row
			$args['blocks']['create_label']['options'][$i++] = array('title' => '', 'content' => $this->get_form_row());

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
	private function get_form_row($id = '', $header = 'from', $folder = 'all', $input = '', $color = '#000000', $text = '', $delete = false) {

		$this->add_texts('localization');

		if (!$text){
			$text = rcube::Q($this->gettext('label_name'));
		}
		//if (!$input) $input = rcube::Q($this->gettext('label_matches'));
		if (!$id){
			$id = uniqid();
		}
		if (!$folder){
			$folder = 'all';
		}

		// header select box
		$header_select = new html_select(array('name' => '_label_header[]', 'class' => 'label_header'));
		$header_select->add(rcube::Q($this->gettext('subject')), 'subject');
		$header_select->add(rcube::Q($this->gettext('from')), 'from');
		$header_select->add(rcube::Q($this->gettext('to')), 'to');
		$header_select->add(rcube::Q($this->gettext('cc')), 'cc');

		// folder search select
		$folder_search = new html_select(array('name' => '_folder_search[]', 'class' => 'folder_search'));

		$p = array('maxlength' => 100, 'realnames' => false);

		// get mailbox list
		$a_folders = $this->rc->storage->list_folders_subscribed();
		$delimiter = $this->rc->storage->get_hierarchy_delimiter();
		$a_mailboxes = array();

		foreach ($a_folders as $folder_list){
			$this->rc->build_folder_tree($a_mailboxes, $folder_list, $delimiter);
		}

		$folder_search->add(rcube::Q($this->gettext('label_all')), 'all');

		$this->rc->render_folder_tree_select($a_mailboxes, $mbox, $p['maxlength'], $folder_search, $p['realnames']);

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
				$folder_search->show($folder) . "&nbsp;" .
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
			'id' => 'label_preferences',
			'section' => rcube::Q($this->gettext('label_title'))
		);
		return($args);
	}

	/**
	 * Save preferences
	 *
	 * @access  public
	 */
	function label_save($args) {
		if ($args['section'] != 'label_preferences') {
			return;
		}

		$rcmail = rcmail::get_instance();

		$id = rcube_utils::get_input_value('_label_id', rcube_utils::INPUT_POST);
		$header = rcube_utils::get_input_value('_label_header', rcube_utils::INPUT_POST);
		$folder = rcube_utils::get_input_value('_folder_search', rcube_utils::INPUT_POST);
		$input = rcube_utils::get_input_value('_label_input', rcube_utils::INPUT_POST);
		$color = rcube_utils::get_input_value('_label_color', rcube_utils::INPUT_POST);
		$text = rcube_utils::get_input_value('_label_text', rcube_utils::INPUT_POST);

		//write_log('debug', preg_replace('/\r\n$/', '', print_r($_POST,true)));

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

		$args['prefs']['message_label'] = $prefs;
		return($args);
	}

	function action_check_mode() {
		$check = rcube_utils::get_input_value('_check', rcube_utils::INPUT_POST);
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
