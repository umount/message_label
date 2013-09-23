/**
 * @version 1.2
 * @author Denis Sobolev <dns.sobol@gmail.com>
 * @author Steve Ludovicy <steve@gms.lu>
 */
section_select_init = function (id) {
    if (id) {
        var add_url = '',
            target = window;
        if (rcmail.env.contentframe && window.frames && window.frames[rcmail.env.contentframe]) {
            add_url = '&_framed=1';
            target = window.frames[rcmail.env.contentframe];
        }
        target.location.href = rcmail.env.comm_path + '&_action=edit-prefs&_section=' + id + add_url;
    }
    rcmail.enable_command('label_messages', false);

    return true;
};

rcube_webmail.prototype.redirect_draft_messages = function (check) {
    if (rcmail.env.label_folder_search_active) {
        if (rcmail.task == 'mail') {
            uid = rcmail.get_single_uid();
            if (uid && (!rcmail.env.uid || uid != rcmail.env.uid || check)) {
                if ((rcmail.env.mailbox == rcmail.env.drafts_mailbox) || check) {
                    url = {
                        _mbox: this.env.mailbox,
                        _search: 'labelsearch'
                    };
                    url[this.env.mailbox == this.env.drafts_mailbox ? '_draft_uid' : '_uid'] = uid;
                    this.goto_url('compose', url, true);
                }
            }
        }
        return true;
    }
    return false;
}

rcube_webmail.prototype.unlabel_messages = function (row, label, type) {
    var a_uids = [],
        count = 0,
        msg;
    remove = 'tr#' + row + ' span.' + label;
    a_uids[0] = row.replace(/^rcmrow/, '');
    if (type == 'filter') {
        label_string = 'u' + label;
    } else if (type == 'flabel') {
        label_string = label;
    } else {
        label_string = 'un' + label;
    }
    $(remove).parent().remove();

    if (rcmail.env.search_labels == label) {
        if (rcmail.env.label_folder_search_active)
            rcmail.message_list.remove_row(a_uids[0]);
    }

    rcmail.toggle_label_status(label_string, a_uids, type, false);

}

// label selected messages
rcube_webmail.prototype.label_messages = function (label) {

    // exit if current or no mailbox specified or if selection is empty
    if (!rcmail.env.uid && (!rcmail.message_list || !rcmail.message_list.get_selection().length))
        return;

    // Hide message command buttons until a message is selected
    rcmail.enable_command(rcmail.env.message_commands, false);

    var a_uids = [],
        count = 0,
        msg;
    if (rcmail.env.label_folder_search_active && rcmail.message_list && rcmail.message_list.get_selection().length) {
        var res = rcmail.label_check_multi_mbox();
    }
    else if (rcmail.env.uid) {
        a_uids[0] = rcmail.env.uid;
    }
    else {
        var n, id, root, roots = [],
            selection = rcmail.message_list.get_selection();

        for (n = 0, len = selection.length; n < len; n++) {
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
    rcmail.toggle_label_status(label, a_uids, 'label', true);
}

rcube_webmail.prototype.label_search = function (post) {
    lock = rcmail.set_busy(true, 'loading');
    rcmail.env.search_request = 'labelsearch';
    rcmail.clear_message_list();
    rcmail.http_post('plugin.message_label_search', post, lock);
}

rcube_webmail.prototype.toggle_label_status = function (flag, a_uids, type, update) {

    var i, len = a_uids.length,
        url = '_uid=' + rcmail.uids_to_list(a_uids) + '&_flag=' + flag + '&_type=' + type;

    if (update == true)
        url += '&_update=1';

    if (rcmail.env.label_folder_search_active)
        url += '&_label_search=1';

    lock = rcmail.display_message(rcmail.get_label('markingmessage'), 'loading');
    rcmail.http_post('plugin.message_label_setlabel', url, lock);

}

rcube_webmail.prototype.message_label_add_mbox = function (mbox, count, showMbox) {
    if (!this.gui_objects.messagelist || !this.message_list)
        return false;

    var colspan = showMbox == true ? 9 : 8;

    $(rcmail.message_list.list).append('<tr class="mlabel_mbox"><td><span class="mlabel_found">' + count + '</span></td><td colspan="' + colspan + '">' + mbox + '</td></tr>');

}

rcube_webmail.prototype.redirect_label_pref = function (post) {
    if (post != null) {
        rcmail.http_post('plugin.message_label_redirect', '');
    }
}

rcube_webmail.prototype.label_mark = function (post) {
    rcmail.http_post('plugin.message_label_mark', post);
}

rcube_webmail.prototype.label_move = function (post) {
    rcmail.http_post('plugin.message_label_move', post);
}

rcube_webmail.prototype.label_delete = function (post) {
    rcmail.http_post('plugin.message_label_delete', post);
}

function label_delete_row(id) {
    if (confirm(rcmail.get_label('message_label.deleteconfirm'))) {
        $('#' + id).closest('tr').remove();
        rcmail.command('save', '', this);
    }
}

rcube_webmail.prototype.labels_select = function (list) {
    var id = list.get_single_selection();
    if (id != null) {
        post = '_id=' + list.rows[id].uid;
        lock = rcmail.set_busy(true, 'loading');
        rcmail.clear_message_list();
        rcmail.http_post('plugin.message_label_search', post, lock);
    }
};

rcube_webmail.prototype.label_msglist_select = function (list) {
    var enableCommand = (list.get_selection().length > 0) && !rcmail.env.label_folder_search_active;
    rcmail.enable_command('label_messages', enableCommand);
    if(enableCommand) {
      $("#markmessagemenu .labellink").addClass('active');
      $("#markmessagemenu .labellistmenu .separator_below").removeClass('inactive');
    } else {
      $("#markmessagemenu .labellink").removeClass('active');
      $("#markmessagemenu .labellistmenu .separator_below").addClass('inactive');
    }
    if (rcmail.env.label_folder_search_active) {
        if (list.selection.length == 1) {
            var parts = list.selection[0].split('__MB__');
            var mid = parts[0];
            var md5Mbox = parts[1];
            var mbox = rcmail.env.ml_md5_folders[md5Mbox];
            rcmail.env.uid = mid;
            if (this.env.mailbox != mbox) {
                var ex = [];
                li = rcmail.get_folder_li(mbox, '', true);
                parent = $(li).parents(".mailbox");
                parent.each(function () {
                    div = $(this.getElementsByTagName('div')[0]);
                    a = $(this.getElementsByTagName('a')[0]);
                    if (div.hasClass('collapsed')) {
                        ex.push($(a).attr("rel"));
                    }
                });
                for (var i = ex.length - 1; i >= 0; i--) {
                    rcmail.command('collapse-folder', ex[i]);
                }
                rcmail.select_folder(mbox, '', true);
                rcmail.env.mailbox = mbox;
            }
            return false;
        }
    }

}

rcube_webmail.prototype.label_check_multi_mbox = function () {
    var raw_selection = rcmail.message_list.get_selection();
    var md5_folders = rcmail.env.ml_md5_folders;
    var i;
    var mcount = 0;
    var selections = {};
    for (i in raw_selection) {
        raw_selection[i];
        var parts = raw_selection[i].split('__MB__');
        var mid = parts[0];
        var md5Mbox = parts[1];
        var mbox = rcmail.env.ml_md5_folders[md5Mbox];
        if (!selections[mbox]) {
            selections[mbox] = [];
            mcount++;
        }
        selections[mbox].push(mid);
    }

    return {
        isMulti: mcount > 1,
        selections: selections
    };
}

rcube_webmail.prototype.label_perform_action = function (props, action) {

    var raw_selection = rcmail.message_list.get_selection();
    var md5_folders = rcmail.env.ml_md5_folders;
    var i;
    var selections = {};
    for (i in raw_selection) {
        raw_selection[i];
        var parts = raw_selection[i].split('__MB__');
        var mid = parts[0];
        var md5Mbox = parts[1];
        var mbox = rcmail.env.ml_md5_folders[md5Mbox];
        if (!selections[mbox]) {
            selections[mbox] = [];
        }
        selections[mbox].push(mid);
    }

    if (i != undefined) {
        // show wait message
        if (rcmail.env.action == 'show') {
            lock = rcmail.set_busy(true, 'movingmessage');
        } else {
            rcmail.show_contentframe(false);
        }
        // Hide message command buttons until a message is selected
        rcmail.enable_command(rcmail.env.message_commands, false);
        var j;
        for (j in selections) {
            rcmail.select_folder(j, '', true);
            rcmail.env.mailbox = j;
            var uids = selections[j].join(',');
            var lock = false,
                post_data = rcmail.selection_post_data({
                    _target_mbox: props.id,
                    _uid: uids
                });
            rcmail._with_selected_messages(action, post_data, lock);
        }
        // Make sure we have no selection
        rcmail.env.uid = undefined;
        rcmail.message_list.selection = [];
    }

}

if (window.rcmail) {
    rcmail.register_command('plugin.label_redirect', function (post) {
        rcmail.redirect_label_pref(post)
    }, true);
    rcmail.register_command('plugin.label_mark', function (post) {
        rcmail.label_mark(post)
    }, true);
    rcmail.register_command('plugin.label_move', function (post) {
        rcmail.label_move(post)
    }, true);
    rcmail.register_command('plugin.label_delete', function (post) {
        rcmail.label_delete(post)
    }, true);
    rcmail.register_command('plugin.label_delete_row', 'label_delete_row', true);

    rcmail.addEventListener('init', function (evt) {
        if (rcmail.gui_objects.labellist) {
            var p = rcmail;
            rcmail.label_list = new rcube_list_widget(rcmail.gui_objects.labellist, {
                multiselect: false,
                draggable: false,
                keyboard: false
            });
            rcmail.label_list.addEventListener('select', function (o) {
                p.labels_select(o);
            });
            rcmail.label_list.row_init = function (row) {
                row.obj.onmouseover = function () {
                    if (rcmail.drag_active && rcmail.env.mailbox) {
                        $('#' + row.id).addClass('droptarget');
                    }
                };
                row.obj.onmouseout = function () {
                    if (rcmail.drag_active && rcmail.env.mailbox) {
                        $('#' + row.id).removeClass('droptarget');
                    }
                };
                $('#' + row.id).mouseup(function () {
                    if (rcmail.drag_active && rcmail.env.mailbox) {
                        p.message_list.draglayer.hide();
                        id = row.id.replace(/^rcmrow/, '');
                        p.label_messages(id);
                    }
                });
            };

            rcmail.label_list.init();

            rcmail.message_list.addEventListener('dblclick', function (o) {
                rcmail.redirect_draft_messages();
            });

            rcmail.message_list.addEventListener('select', function (o) {
                rcmail.label_msglist_select(o);
            });


            rcmail.addEventListener('beforemoveto', function (props) {
                if (rcmail.env.search_request == 'labelsearch') {
                    rcmail.label_perform_action(props, 'moveto');

                    return false;
                }
            });

            rcmail.addEventListener('beforedelete', function (props) {
                if (rcmail.env.search_request == 'labelsearch') {
                    rcmail.label_perform_action(props, 'delete');

                    return false;
                }
            });

            rcmail.addEventListener('beforemark', function (flag) {
                if (rcmail.env.search_request == 'labelsearch') {
                    var res = rcmail.label_check_multi_mbox();
                    //Update on server
                    var i;
                    var sel = res.selections;
                    for (i in sel) {
                        var uids = sel[i].join(',');
                        var lock = false;
                        var post_data = {
                            _uid: uids,
                            _flag: flag,
                            _mbox: i,
                            _remote: 1
                        };
                        rcmail.http_post('mark', post_data, lock);
                    }
                    //Refresh ui
                    var messages = [];
                    var selections = rcmail.message_list.get_selection();
                    var j;
                    for (j in selections) {
                        messages.push('#rcmrow' + selections[j]);
                    }
                    var selector = messages.join(', ');
                    var selection = $(selector);
                    switch (flag) {
                    case 'read':
                        selection.removeClass('unread');
                        break;
                    case 'unread':
                        selection.addClass('unread');
                        break;
                    case 'flagged':
                        selection.addClass('flagged');
                        $("td.flag span", selection).removeClass('unflagged').addClass('flagged');
                        break;
                    case 'unflagged':
                        selection.removeClass('flagged');
                        $("td.flag span", selection).removeClass('flagged').addClass('unflagged');
                        break;
                    default:
                        break;
                    }
                    return false;
                }
            });

            rcmail.addEventListener('beforeforward', function (props) {
                if (rcmail.env.search_request == 'labelsearch' && rcmail.message_list.selection.length > 1) {
                    var res = rcmail.label_check_multi_mbox();
                    if (res.isMulti == true) {
                        //Selections from more then one folder
                        return false;
                    } else {
                        //Only one folder, redirecting
                        var i, url, sel = res.selections;
                        for (i in sel) {
                            url = '&_forward_uid=' + sel[i].join(',') + '&_mbox=' + i;
                        }
                        url += '&_attachment=1&_action=compose';
                        window.location = location.pathname + rcmail.env.comm_path + url;

                        return false;
                    }
                }
            });

            rcmail.addEventListener('beforeforward-attachment', function (props) {
                if (rcmail.env.search_request == 'labelsearch' && rcmail.message_list.selection.length > 1) {
                    var res = rcmail.label_check_multi_mbox();
                    if (res.isMulti == true) {
                        //Selections from more then one folder
                        return false;
                    } else {
                        //Only one folder, redirecting
                        var i, url, sel = res.selections;
                        for (i in sel) {
                            url = '&_forward_uid=' + sel[i].join(',') + '&_mbox=' + i;
                        }
                        url += '&_attachment=1&_action=compose';
                        window.location = location.pathname + rcmail.env.comm_path + url;

                        return false;
                    }
                }
            });

            rcmail.addEventListener('beforetoggle_flag', function (props) {
                if (rcmail.env.search_request == 'labelsearch') {
                    var flag = $(props).hasClass('unflagged') ? 'flagged' : 'unflagged';
                    var tr = $(props).closest('tr');
                    var id = tr.attr('id').replace('rcmrow', '');
                    var parts = id.split('__MB__');
                    var lock = false;
                    var mbox = rcmail.env.ml_md5_folders[parts[1]];
                    var post_data = {
                        _uid: parts[0],
                        _flag: flag,
                        _mbox: mbox,
                        _remote: 1
                    };
                    rcmail.http_post('mark', post_data, lock);
                    if (flag == 'flagged') {
                        tr.addClass('flagged');
                        $("td.flag span", tr).removeClass('unflagged').addClass('flagged');
                    } else {
                        tr.removeClass('flagged');
                        $("td.flag span", tr).removeClass('flagged').addClass('unflagged');
                    }
                    return false;
                }
            });
        }
    });


    rcmail.addEventListener('insertrow', function (evt) {
        var message = rcmail.env.messages[evt.row.uid];
        if (message == undefined) {
            message = rcmail.env.messages[evt.row.id.replace('rcmrow', '')];
        }
        if (message.flags && message.flags.plugin_label) {
            for (var i = 0; i < message.flags.plugin_label.length; i++) {
                var label = '<span class="lbox">' + '<span class="lmessage ' + message.flags.plugin_label[i].id + ' ' + message.flags.plugin_label[i].class + '" style="background-color:' + message.flags.plugin_label[i].color + ';">' + '<span class="ml_label" onclick=\"rcmail.unlabel_messages(\'' + evt.row.obj.id + '\',\'' + message.flags.plugin_label[i].id + '\'' + ',\'' + message.flags.plugin_label[i].type + '\')">' + message.flags.plugin_label[i].text + '</span></span></span>';

                $("#" + evt.row.obj.id + " .subject .msgicon").after(label);
            }
        }
    });

    rcmail.addEventListener('plugin.avaddheader', function (evt) {
        if ($("#messagelist #rcavbox").length == 0) {
            $("#messagelist tr:first").append('<td class="mbox" id="rcavbox" style="width: 30%;"><span class="mbox">Mbox</span></td>');
        }
    });

    rcmail.addEventListener('beforelist', function (evt) {
        // this is how we will know we are in an label folder search
        if (evt != '' && rcmail.env.label_folder_search_active) {
            $("#rcavbox").remove();
            rcmail.env.label_folder_search_active = 0;
            rcmail.env.search_request = null;
            rcmail.env.all_folder_search_uid_mboxes = null;
            rcmail.http_post('plugin.not_label_folder_search', true);
            rcmail.label_list.clear_selection();
        }
    });

    rcmail.addEventListener('beforeedit', function (command) {
        rcmail.env.framed = true;
        if (rcmail.redirect_draft_messages(true))
            return false;
    });

}

$(document).ready(function () {

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
        if (showOrHide == true) {
            $('#drop_labels').show();
            rcmail.http_post('plugin.message_label_display_list', '_display_label=true');
            showOrHide = false;
        } else if (showOrHide == false) {
            $('#drop_labels').hide();
            rcmail.http_post('plugin.message_label_display_list', '_display_label=false');
            showOrHide = true;
        }
    });

});