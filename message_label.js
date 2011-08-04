/**
 * @version 0.3
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


rcube_webmail.prototype.unlabel_messages = function(row, label, type) {
    var a_uids = [], count = 0, msg;
    remove = 'tr#'+row+' span.'+label;
    a_uids[0] = row.replace(/^rcmrow/, '');
    if (type == 'filter') {
      label_string = 'u'+label;
    } else {
      label_string = 'un'+label;
    }
    $(remove).parent().remove();

    if (rcmail.env.search_labels == label) {
      rcmail.message_list.remove_row(a_uids[0]);
    }

    rcmail.toggle_flagged_status(label_string, a_uids);

}

// label selected messages
rcube_webmail.prototype.label_messages = function(label) {

      label = label.id.replace(/^rcmrow/, '');

      // exit if current or no mailbox specified or if selection is empty
      if (!rcmail.env.uid && (!rcmail.message_list || !rcmail.message_list.get_selection().length))
        return;

      // Hide message command buttons until a message is selected
      rcmail.enable_command(rcmail.env.message_commands, false);

      var a_uids = [], count = 0, msg;

      if (rcmail.env.uid)
        a_uids[0] = rcmail.env.uid;
      else {
        var n, id, root, roots = [],
          selection = rcmail.message_list.get_selection();

        for (n=0, len=selection.length; n<len; n++) {
          id = selection[n];
          a_uids.push(id);

          if (rcmail.env.threading) {
            count += this.update_thread(id);
            root = this.message_list.find_root(id);
            if (root != id && $.inArray(root, roots) < 0) {
              roots.push(root);
            }
          }
        }
        // make sure there are no selected rows
        if (!rcmail.env.display_next)
          rcmail.message_list.clear_selection();

        /*
        // update thread tree icons
        for (n=0, len=roots.length; n<len; n++) {
          rcmail.add_tree_icons(roots[n]);
        }
        */
      }
      rcmail.toggle_label_status(label, a_uids);
}

rcube_webmail.prototype.label_search = function(post) {
  lock = rcmail.set_busy(true, 'loading');
  rcmail.clear_message_list();
  rcmail.http_post('plugin.message_label_search', post, lock);
}

rcube_webmail.prototype.toggle_label_status = function(flag, a_uids) {

  var i, len = a_uids.length,
  url = '_uid='+rcmail.uids_to_list(a_uids)+'&_flag='+flag,
  lock = rcmail.display_message(this.get_label('markingmessage'), 'loading');

  rcmail.http_post('mark', url, lock);
  rcmail.clear_message_list();
  rcmail.command('list',rcmail.env.mailbox,this);
}

rcube_webmail.prototype.redirect_label_pref = function(post) {
  if (post != null) {
    rcmail.http_post('plugin.message_label_redirect', '');
  }
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

rcube_webmail.prototype.labels_select = function(list)
{
  var id = list.get_single_selection();
  if (id != null) {
    post = '_id='+list.rows[id].uid;
    lock = rcmail.set_busy(true, 'loading');
    rcmail.clear_message_list();
    rcmail.http_post('plugin.message_label_search', post, lock);
  }
};

rcube_webmail.prototype.label_msglist_select = function(list)
{
  if(rcmail.env.label_folder_search_active) {
    if(list.selection.length == 1){
      var mbox = rcmail.env.label_folder_search_uid_mboxes[list.selection[0]].mbox;
      rcmail.select_folder(mbox, rcmail.env.mailbox);
      rcmail.env.mailbox = mbox;
    }
      rcmail.message_list.draggable = false;
    }
    else
      rcmail.message_list.draggable = true;
};

if(window.rcmail) {
  rcmail.register_command('plugin.label_redirect', function(post) { rcmail.redirect_label_pref(post) }, true);
  rcmail.register_command('plugin.label_mark', function(post) { rcmail.label_mark(post) }, true);
  rcmail.register_command('plugin.label_move', function(post) { rcmail.label_move(post) }, true);
  rcmail.register_command('plugin.label_delete', function(post) { rcmail.label_delete(post) }, true);
  rcmail.register_command('plugin.label_delete_row', 'label_delete_row', true);

  rcmail.addEventListener('init', function(evt) {
    if (rcmail.gui_objects.labellist) {
      var p = rcmail;
      rcmail.label_list = new rcube_list_widget(rcmail.gui_objects.labellist,
        {multiselect:false, draggable:false, keyboard:false});
      rcmail.label_list.addEventListener('select', function(o){ p.labels_select(o); });
      rcmail.label_list.row_init = function (row) {
        row.obj.onmouseover = function() {
          if (rcmail.drag_active && rcmail.env.mailbox) {
            $('#'+row.id).addClass('droptarget');
          }
        };
        row.obj.onmouseout = function() {
          if (rcmail.drag_active && rcmail.env.mailbox) {
            $('#'+row.id).removeClass('droptarget');
          }
        };
        $('#'+row.id).mouseup(function(){
          if (rcmail.drag_active && rcmail.env.mailbox) {
            p.message_list.draglayer.hide();
            p.label_messages(row);
          }
        });
      };

      rcmail.label_list.init();

      rcmail.message_list.addEventListener('select', function(o){ rcmail.label_msglist_select(o); });

    }
  })

  rcmail.addEventListener('insertrow', function(evt) {
    var message = rcmail.env.messages[evt.row.uid];
    if(message.flags && message.flags.plugin_label) {
      for (var i=0; i<message.flags.plugin_label.length; i++) {
        var label = '<span class="lbox">'
                    +'<span class="lmessage '+message.flags.plugin_label[i].id+'" style="background-color:'
                      +message.flags.plugin_label[i].color+';">'
                      +'<a onclick=\"rcmail.unlabel_messages(\''+evt.row.obj.id+'\',\''
                        +message.flags.plugin_label[i].id+'\''
                        +',\''+message.flags.plugin_label[i].type+'\')">'
                      +message.flags.plugin_label[i].text+'</a></span></span>';

        $("#"+evt.row.obj.id+" .subject .msgicon").after(label);
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
}

$(document).ready(function(){
  if (rcmail.gui_objects.sectionslist && (rcmail.env.action == 'label_preferences')) {
    section_select_init('label_preferences');
  }
});