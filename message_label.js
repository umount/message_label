/**
 * @version 0.2
 * @author Denis Sobolev <dns.sobol@gmail.com>
 *
 */


section_select_init = function(id) {
  if (id) {
    var add_url = '', target = window;
    if (rcmail.env.contentframe && window.frames && window.frames[rcmail.env.contentframe]) {
      add_url = '&_framed=1';
      target = window.frames[rcmail.env.contentframe];
    }
    target.location.href = rcmail.env.comm_path+'&_action=edit-prefs&_section='+id+add_url;
  }
  return true;
};

rcube_webmail.prototype.redirect_label_pref = function(post) {
  if (post != null) {
    rcmail.http_post('plugin.message_label_redirect', '');
  }
}

rcube_webmail.prototype.label_search = function(post) {
  lock = rcmail.set_busy(true, 'loading');
  rcmail.clear_message_list();
  rcmail.http_post('plugin.message_label_search', post, lock);
}

rcube_webmail.prototype.label_mark = function(post) {
  rcmail.http_post('plugin.message_label_mark', post);
}

rcube_webmail.prototype.label_move = function(post) {
  rcmail.http_post('plugin.message_label_move', post);
}

rcube_webmail.prototype.label_delete = function(post) {
  rcmail.http_post('plugin.message_label_delete', post);
}

function label_delete_row(id) {
  if(confirm(rcmail.get_label('message_label.deleteconfirm'))) {
    $('#'+id).closest('tr').remove();
    rcmail.command('save','',this);
  }
}

function check_mode(mode) {
  if (mode == 'highlighting') {
    rcmail.http_post('plugin.message_label.check_mode', '_check=highlighting');
  } else {
    rcmail.http_post('plugin.message_label.check_mode', '_check=labels');
  }
}


if(window.rcmail) {
  rcmail.register_command('plugin.label_redirect', function(post) { rcmail.redirect_label_pref(post) }, true);
  rcmail.register_command('plugin.label_search', function(post) { rcmail.label_search(post) }, true);
  rcmail.register_command('plugin.label_mark', function(post) { rcmail.label_mark(post) }, true);
  rcmail.register_command('plugin.label_move', function(post) { rcmail.label_move(post) }, true);
  rcmail.register_command('plugin.label_delete', function(post) { rcmail.label_delete(post) }, true);
  rcmail.register_command('plugin.label_delete_row', 'label_delete_row', true);

  rcmail.register_command('plugin.message_label.check_mode', check_mode, true);
  rcmail.enable_command('plugin.message_label.check_mode', true);

  rcmail.addEventListener('insertrow', function(evt) {
    var message = rcmail.env.messages[evt.row.uid];
    if(message.flags && message.flags.plugin_label) {
      if (rcmail.env.message_label_mode == 'highlighting') {
        evt.row.obj.style.backgroundColor = message.flags.plugin_label.color;
      } else {
        var label = '<span class="lbox"><span class="lmessage" style="background-color:'+message.flags.plugin_label.color+';">'+message.flags.plugin_label.text+'</span></span>';
        $("#"+evt.row.obj.id+" .subject .status").after(label);
      }
    }
  });

  rcmail.addEventListener('beforelist', function(evt) {
    // this is how we will know we are in an label folder search
    if(evt != '' && rcmail.env.label_folder_search_active) {
      rcmail.env.label_folder_search_active = 0;
      rcmail.env.search_request = null;
      rcmail.env.all_folder_search_uid_mboxes = null;
      rcmail.http_post('plugin.not_label_folder_search', true);
    }
  });

  rcmail.addEventListener('pre_msglist_select', function(evt) {
    if(rcmail.env.label_folder_search_active) {
      if(evt.list.selection.length == 1){
        var mbox = rcmail.env.label_folder_search_uid_mboxes[evt.list.selection[0]].mbox;
        rcmail.select_folder(mbox, rcmail.env.mailbox);
        rcmail.env.mailbox = mbox;
      }
        rcmail.message_list.draggable = false;
      }
      else
        rcmail.message_list.draggable = true;
    });
}

$(document).ready(function(){
  if (rcmail.gui_objects.sectionslist && (rcmail.env.action == 'label_preferences')) {
    section_select_init('label_preferences');
  }
});