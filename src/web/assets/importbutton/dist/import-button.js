(function ($) {
  'use strict';

  function addButton() {
    var config = window.csvExportImportButton;
    if (!config) return;
    var $export = $('#export-btn');
    if (!$export.length || $('#csv-export-import-btn').length) return;

    $('<a/>', {
      id: 'csv-export-import-btn',
      'class': 'btn',
      href: config.url,
      text: config.label,
      title: config.title,
    }).insertAfter($export);
  }

  $(addButton);
  // The footer can be re-rendered when switching sources
  Garnish.$doc.on('elementIndexUpdated', addButton);
})(jQuery);
