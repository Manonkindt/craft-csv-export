(function ($) {
  'use strict';

  var attempts = 0;

  function isEntriesIndex() {
    var index = window.Craft && Craft.elementIndex;
    return !!(index && index.elementType === 'craft\\elements\\Entry' && index.settings && index.settings.context === 'index');
  }

  function addButton() {
    var config = window.csvExportImportButton;
    if (!config || $('#csv-export-import-btn').length) return;

    var $export = $('#export-btn');
    if (!$export.length) return;

    // Craft.elementIndex is created on DOM ready as well; retry briefly until it exists
    if (!(window.Craft && Craft.elementIndex)) {
      if (attempts++ < 20) setTimeout(addButton, 100);
      return;
    }
    if (!isEntriesIndex()) return;

    $('<a/>', {
      id: 'csv-export-import-btn',
      'class': 'btn',
      href: config.url,
      text: config.label,
      title: config.title,
    }).insertAfter($export);
  }

  $(addButton);
})(jQuery);
