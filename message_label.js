/**
 * @version 1.2
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

rcube_webmail.prototype.redirect_draft_messages = function(check) {
    if (rcmail.env.label_folder_search_active || check) {
        if (rcmail.task == 'mail') {
            uid = rcmail.get_single_uid();
            if (uid && (!rcmail.env.uid || uid != rcmail.env.uid || check)) {
                if (rcmail.env.mailbox == rcmail.env.drafts_mailbox)  {
                    if (check) rcmail.env.framed = true;
                    rcmail.goto_url('compose', { _draft_uid: uid, _mbox: rcmail.env.mailbox, _search: 'labelsearch' }, true);
                }
            }
        }
    }
}

rcube_webmail.prototype.unlabel_messages = function(row, label, type) {
    var a_uids = [], count = 0, msg;
    remove = 'tr#'+row+' span.'+label;
    a_uids[0] = row.replace(/^rcmrow/, '');
    if (type == 'filter') {
        label_string = 'u'+label;
    } else if (type == 'flabel') {
        label_string = label;
    } else {
        label_string = 'un'+label;
    }
    $(remove).parent().remove();

    if (rcmail.env.search_labels == label) {
        if (rcmail.env.label_folder_search_active)
            rcmail.message_list.remove_row(a_uids[0]);
    }

    rcmail.toggle_label_status(label_string, a_uids, type, false);

}

// label selected messages
rcube_webmail.prototype.label_messages = function(label) {

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

    }
    rcmail.toggle_label_status(label, a_uids,'label',true);
}

rcube_webmail.prototype.label_search = function(post) {
    lock = rcmail.set_busy(true, 'loading');
    rcmail.env.search_request = 'labelsearch';
    rcmail.clear_message_list();
    rcmail.http_post('plugin.message_label_search', post, lock);
}

rcube_webmail.prototype.toggle_label_status = function(flag, a_uids, type, update) {

    var i, len = a_uids.length,
    url = '_uid='+rcmail.uids_to_list(a_uids)+'&_flag='+flag+'&_type='+type;

    if (update == true)
        url += '&_update=1';

    if (rcmail.env.label_folder_search_active)
        url += '&_label_search=1';

    lock = rcmail.display_message(rcmail.get_label('markingmessage'), 'loading');
    rcmail.http_post('plugin.message_label_setlabel', url, lock);

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

rcube_webmail.prototype.labels_select = function(list) {
    var id = list.get_single_selection();
    if (id != null) {
        post = '_id='+list.rows[id].uid;
        lock = rcmail.set_busy(true, 'loading');
        rcmail.clear_message_list();
        rcmail.http_post('plugin.message_label_search', post, lock);
    }
};

rcube_webmail.prototype.label_msglist_select = function(list) {
    if(rcmail.env.label_folder_search_active) {
        if(list.selection.length == 1){
            var mbox = rcmail.env.label_folder_search_uid_mboxes[list.selection[0]].mbox;
            var ex = [];
            if (this.env.mailbox != mbox) {
                li = rcmail.get_folder_li(mbox, '', true);
                parent = $(li).parents(".mailbox");
                parent.each(function () {
                    div = $(this.getElementsByTagName('div')[0]);
                    a = $(this.getElementsByTagName('a')[0]);
                    if (div.hasClass('collapsed')) {
                        ex.push($(a).attr("rel"));
                    }
                 });
                for(var i = ex.length-1; i >= 0; i--) {
                    rcmail.command('collapse-folder', ex[i]);
                }
                rcmail.select_folder(mbox, '', true);
                rcmail.env.mailbox = mbox;
            }
        }
        rcmail.message_list.draggable = false;
    }
    else
        rcmail.message_list.draggable = true;
};

if(window.rcmail) {
    rcmail.register_command('plugin.label_redirect', function(post) {
        rcmail.redirect_label_pref(post)
    }, true);
    rcmail.register_command('plugin.label_mark', function(post) {
        rcmail.label_mark(post)
    }, true);
    rcmail.register_command('plugin.label_move', function(post) {
        rcmail.label_move(post)
    }, true);
    rcmail.register_command('plugin.label_delete', function(post) {
        rcmail.label_delete(post)
    }, true);
    rcmail.register_command('plugin.label_delete_row', 'label_delete_row', true);

    rcmail.addEventListener('init', function(evt) {
        if (rcmail.gui_objects.labellist) {
            var p = rcmail;
            rcmail.label_list = new rcube_list_widget(rcmail.gui_objects.labellist,
            {
                multiselect:false,
                draggable:false,
                keyboard:false
            });
            rcmail.label_list.addEventListener('select', function(o){
                p.labels_select(o);
            });
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
                        id = row.id.replace(/^rcmrow/, '');
                        p.label_messages(id);
                    }
                });
            };

            rcmail.label_list.init();

            rcmail.message_list.addEventListener('dblclick', function(o){
                rcmail.redirect_draft_messages();
            });

            rcmail.message_list.addEventListener('select', function(o){
                rcmail.label_msglist_select(o);
            });

            if (rcmail.message_list) {
                rcmail.addEventListener('actionbefore', function(command){
                    switch (command.action) {
                        case 'edit':
                            rcmail.redirect_draft_messages(true);
                        break;
                        default:
                        break;
                    }
                });
            }
        }
    });

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
            rcmail.label_list.clear_selection();
        }
    });

    rcmail.addEventListener('actionbefore', function(command){
        switch (command.action) {
            case 'edit':
                rcmail.redirect_draft_messages(true);
            break;
            default:
                break;
            }
        });

}

$(document).ready(function(){

    if (rcmail.gui_objects.sectionslist && (rcmail.env.action == 'label_preferences')) {
        section_select_init('label_preferences');
    }
    $("#markmessagemenu").append($(".labellistmenu"));

    var showOrHide;

    if ($('#drop_labels').css('display') == 'none') {
        showOrHide = true;
    } else {
        showOrHide = false;
    }

    $("#labels-title").click(function () {
        if ( showOrHide == true ) {
            $('#drop_labels').show();
            rcmail.http_post('plugin.message_label_display_list', '_display_label=true');
            showOrHide = false;
        } else if ( showOrHide == false ) {
            $('#drop_labels').hide();
            rcmail.http_post('plugin.message_label_display_list', '_display_label=false');
            showOrHide = true;
        }
    });

});