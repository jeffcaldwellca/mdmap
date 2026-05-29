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
    }
  });

  /* ── Delete mapping ─────────────────────────────────────────── */
  $('.mdmap_app_delete_mapping a').on('click', function (e) {
    e.preventDefault();
    $(this).closest('.mdmap_app_mapping').remove();
    buildNotice(
      o.removedMessage,
      o.undoMessage,
      o.dismissMessage
    ).insertBefore('.mdmap_app_wrap p.submit');
  });

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
          buildNotice(res.data.message, o.undoMessage, o.dismissMessage)
            .insertBefore('.mdmap_app_wrap p.submit');
          setTimeout(function () { location.reload(); }, 1500);
        } else {
          buildNotice((res.data ? res.data.message : o.importError), o.undoMessage, o.dismissMessage)
            .insertBefore('.mdmap_app_wrap p.submit');
        }
      }).fail(function () {
        buildNotice(o.importError, o.undoMessage, o.dismissMessage)
          .insertBefore('.mdmap_app_wrap p.submit');
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
  function buildNotice(message, undoLabel, dismissLabel) {
    var $undo    = $('<a>').addClass('mdmap_app_reload').attr('href', '#').text(undoLabel);
    var $msg     = $('<p>').append($('<strong>').text(message)).append(' \u2014 ').append($undo);
    var $dismiss = $('<button>').attr('type', 'button').addClass('notice-dismiss')
                    .append($('<span>').addClass('screen-reader-text').text(dismissLabel));
    return $('<div>').addClass('notice notice-info is-dismissible mdmap_app_notice')
                     .append($msg).append($dismiss);
  }
});
