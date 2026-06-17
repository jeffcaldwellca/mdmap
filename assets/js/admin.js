jQuery(document).ready(function ($) {
  var o = localizedObj;

  /* ── Mapping accordion ──────────────────────────────────────── */
  $('.mdmap_app_mappings').accordion({
    header: '.mdmap_app_mapping_header',
    collapsible: true,
    active: false,
    animate: { duration: 200, easing: 'swing' },
    heightStyle: 'content'
  });

  // Prevent accordion toggle when clicking inside inputs
  $('.mdmap_app_input_wrap input').on('click', function (e) {
    e.stopPropagation();
  });

  /* ── Drag-to-reorder sort ───────────────────────────────────── */
  $('.mdmap_app_mappings').sortable({
    handle: '.mdmap_app_mapping_header',
    axis: 'y',
    cursor: 'grabbing',
    placeholder: 'mdmap_app_sort_placeholder',
    tolerance: 'pointer',
    start: function (e, ui) {
      // Collapse accordion item being dragged
      ui.item.find('.mdmap_app_mapping_body').hide();
    },
    update: function () {
      // Re-index sortorder hidden inputs to reflect new order
      $(this).find('.mdmap_app_sortorder').each(function (i) {
        $(this).val(i);
      });
      // Accordion needs to be refreshed after DOM reorder
      $(this).accordion('refresh');
      markDirty();
    }
  });

  /* ── Delete mapping ─────────────────────────────────────────── */
  $('.mdmap_app_delete_mapping a').on('click', function (e) {
    e.preventDefault();
    $(this).closest('.mdmap_app_mapping').remove();
    showNotice(buildNotice(o.removedMessage, o.undoMessage, o.dismissMessage));
    markDirty();
  });

  /* ── Unsaved-changes hint ───────────────────────────────────── */
  // Surface a persistent reminder the moment a mapping is edited, added,
  // toggled, reordered, or removed - mirrors the delete-undo affordance so
  // users know their changes only take effect on save. Cleared on reload (save).
  var dirtyShown = false;
  function markDirty() {
    if (dirtyShown) return;
    dirtyShown = true;
    var $status = $('.mdmap_app_save_status');
    if ($status.length) {
      // Surface unsaved state inside the action bar (status left of the Save button).
      $status.addClass('is-dirty').text(o.unsavedStatus).attr('title', o.unsavedMessage);
    } else {
      // Save bar absent (e.g. at the max_input_vars limit) - fall back to a notice under the heading.
      var $msg    = $('<p>').append($('<strong>').text(o.unsavedMessage));
      var $notice = $('<div>').addClass('notice notice-warning mdmap_app_notice mdmap_app_dirty_notice').append($msg);
      showNotice($notice);
    }
  }
  // Any keystroke or change in an existing row or the add-new row marks the form dirty.
  $('body').on('input change', '.mdmap_app_mappings :input, .mdmap_app_new_mapping :input', markDirty);

  /* ── Active toggle: reflect disabled state live ─────────────── */
  $('body').on('change', '.mdmap_app_toggle_label input[type=checkbox]', function () {
    $(this).closest('.mdmap_app_mapping').toggleClass('mdmap_app_mapping_disabled', !this.checked);
  });

  /* ── Health check ───────────────────────────────────────────── */
  $('body').on('click', '.mdmap_app_health_btn', function () {
    var $btn    = $(this);
    var $result = $btn.siblings('.mdmap_app_health_result');
    var domain  = $btn.data('domain');
    $result.attr('class', 'mdmap_app_health_result mdmap_app_health_loading').text('\u2026');
    $.post(o.ajaxUrl, {
      action: 'mdmap_health_check',
      nonce:  o.healthNonce,
      domain: domain
    }).done(function (res) {
      if (res.success) {
        var code   = res.data.code;
        var ok     = (code >= 200 && code < 400);
        var label  = (ok ? o.healthOk : o.healthFail) + ' (' + code + ')';
        $result.attr('class', 'mdmap_app_health_result ' + (ok ? 'mdmap_app_health_ok' : 'mdmap_app_health_fail')).text(label);
      } else {
        $result.attr('class', 'mdmap_app_health_result mdmap_app_health_fail').text(o.healthError + ': ' + (res.data ? res.data.message : ''));
      }
    }).fail(function () {
      $result.attr('class', 'mdmap_app_health_result mdmap_app_health_fail').text(o.healthError);
    });
  });

  /* ── Export mappings ────────────────────────────────────────── */
  $('#mdmap_export_btn').on('click', function () {
    $.post(o.ajaxUrl, { action: 'mdmap_export_mappings', nonce: o.exportNonce })
      .done(function (res) {
        if (!res.success) return;
        var blob = new Blob([JSON.stringify(res.data.mappings, null, 2)], { type: 'application/json' });
        var url  = URL.createObjectURL(blob);
        var $a   = $('<a>').attr({ href: url, download: 'mdmap-mappings.json' }).appendTo('body');
        $a[0].click();
        $a.remove();
        URL.revokeObjectURL(url);
      });
  });

  /* ── Import mappings ────────────────────────────────────────── */
  $('#mdmap_import_file').on('change', function () {
    var file = this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function (e) {
      $.post(o.ajaxUrl, {
        action: 'mdmap_import_mappings',
        nonce:  o.importNonce,
        data:   e.target.result
      }).done(function (res) {
        if (res.success) {
          showNotice(buildNotice(res.data.message, null, o.dismissMessage, 'success'));
          setTimeout(function () { location.reload(); }, 1500);
        } else {
          showNotice(buildNotice((res.data ? res.data.message : o.importError), null, o.dismissMessage, 'error'));
        }
      }).fail(function () {
        showNotice(buildNotice(o.importError, null, o.dismissMessage, 'error'));
      });
    };
    reader.readAsText(file);
    // Reset so the same file can be re-selected
    this.value = '';
  });

  /* ── Delegated: undo reload ─────────────────────────────────── */
  $('body').on('click', '.mdmap_app_reload', function (e) {
    e.preventDefault();
    location.reload();
  });

  /* ── Delegated: dismiss dynamically created notices ─────────── */
  $('body').on('click', '.mdmap_app_notice .notice-dismiss', function () {
    $(this).closest('.mdmap_app_notice').remove();
  });

  /* ── Helper: build a dismissible notice ─────────────────────── */
  // actionLabel is optional \u2014 when provided, an undo/reload link is appended.
  // noticeType picks the WP notice colour (info/success/error); defaults to info.
  function buildNotice(message, actionLabel, dismissLabel, noticeType) {
    var $msg = $('<p>').append($('<strong>').text(message));
    if (actionLabel) {
      var $undo = $('<a>').addClass('mdmap_app_reload').attr('href', '#').text(actionLabel);
      $msg.append(' \u2014 ').append($undo);
    }
    var $dismiss = $('<button>').attr('type', 'button').addClass('notice-dismiss')
                    .append($('<span>').addClass('screen-reader-text').text(dismissLabel));
    return $('<div>').addClass('notice notice-' + (noticeType || 'info') + ' is-dismissible mdmap_app_notice')
                     .append($msg).append($dismiss);
  }

  // Place a notice where it stays visible: above the sticky Save button when
  // present, else under the page heading (Save is hidden at the max_input_vars limit).
  function showNotice($notice) {
    var $bar = $('.mdmap_app_wrap .mdmap_app_actionbar');
    if ($bar.length) {
      $notice.insertBefore($bar);
    } else {
      $('.mdmap_app_wrap .mdmap_app_brandhead').first().after($notice);
    }
  }
});
