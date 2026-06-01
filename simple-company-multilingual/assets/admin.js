/* Simple Company Multilingual admin scripts */
jQuery(function ($) {
  var scmTabStorageKey = 'scm_active_settings_tab';

  function scmActivateTab(target) {
    if (!target || !$(target).length) {
      target = '#scm-tab-general';
    }

    $('.scm-tabs .nav-tab').removeClass('nav-tab-active');

    $('.scm-tabs .nav-tab')
      .filter(function () {
        return $(this).attr('href') === target;
      })
      .addClass('nav-tab-active');

    $('.scm-tab-panel').removeClass('is-active');
    $(target).addClass('is-active');

    try {
      window.localStorage.setItem(scmTabStorageKey, target);
    } catch (e) {}
  }

  $(document).on('click', '.scm-tabs .nav-tab', function (e) {
    e.preventDefault();

    var target = $(this).attr('href');

    if (target) {
      window.location.hash = target.replace('#', '');
      scmActivateTab(target);
    }
  });

  $(document).on('submit', '.scm-settings-wrap form', function () {
    var activeTab = $('.scm-tab-panel.is-active').attr('id');

    if (activeTab) {
      try {
        window.localStorage.setItem(scmTabStorageKey, '#' + activeTab);
      } catch (e) {}
    }
  });

  var initialTab = '#scm-tab-general';

  if (window.location.hash && $(window.location.hash).length) {
    initialTab = window.location.hash;
  } else {
    try {
      var savedTab = window.localStorage.getItem(scmTabStorageKey);

      if (savedTab && $(savedTab).length) {
        initialTab = savedTab;
      }
    } catch (e) {}
  }

  scmActivateTab(initialTab);

  $(document).on('change', '.scm-new-language-select', function () {
    var option = $(this).find('option:selected');
    var box = $(this).closest('.scm-add-language-box');
    var label = option.data('label') || '';
    var prefix = option.data('prefix') || '';
    var wpLocale = option.data('wp-locale') || '';
    var flag = option.data('flag') || '🌐';

    box.find('.scm-new-language-label').val(label);
    box.find('.scm-new-language-prefix').val(prefix);
    box.find('.scm-new-language-wp-locale').val(wpLocale);

    box.find('.scm-new-language-flag').val(flag);
  });

  $(document).on('click', '.scm-flag-upload', function (e) {
    e.preventDefault();

    var button = $(this);
    var input = button.closest('td').find('.scm-flag-image-url');

    var frame = wp.media({
      title: 'Select flag image',
      button: { text: 'Use this image' },
      multiple: false,
    });

    frame.on('select', function () {
      var attachment = frame.state().get('selection').first().toJSON();

      if (attachment && attachment.url) {
        input.val(attachment.url);
      }
    });

    frame.open();
  });

  function scmGetCurrentListStatus() {
    var params = new URLSearchParams(window.location.search || '');
    return params.get('post_status') || 'all';
  }

  function scmCanCollapseTranslationRows() {
    var status = scmGetCurrentListStatus();

    return status === 'all' || status === 'publish';
  }

  function scmReorderTranslationRowsByGroup() {
    var tableBody = $('#the-list');

    if (!tableBody.length) {
      return;
    }

    tableBody.children('tr.scm-translation-row--root').each(function () {
      var root = $(this);
      var button = root.find('.scm-toggle-children').first();
      var groupId = button.data('group');

      if (!groupId) {
        var groupClass = (root.attr('class') || '').match(/scm-translation-group-(\d+)/);
        groupId = groupClass ? groupClass[1] : '';
      }

      if (!groupId) {
        return;
      }

      var children = tableBody.children(
        'tr.scm-translation-row--child.scm-translation-group-' + groupId,
      );

      if (children.length) {
        children.detach();
        root.after(children);
      }
    });
  }

  function scmInitTranslationRows() {
    var canCollapse = scmCanCollapseTranslationRows();

    $('body').toggleClass('scm-translation-list-collapse-enabled', canCollapse);

    scmReorderTranslationRowsByGroup();

    if (canCollapse) {
      $('tr.scm-translation-row--child').addClass('scm-is-collapsed');
      $('.scm-toggle-children').attr('aria-expanded', 'false').text('Show translations');
    } else {
      $('tr.scm-translation-row--child').removeClass('scm-is-collapsed');
      $('.scm-toggle-children').attr('aria-expanded', 'true').text('Hide translations');
    }
  }

  $(document).on('click', '.scm-toggle-children', function () {
    if (!scmCanCollapseTranslationRows()) {
      return;
    }

    var button = $(this);
    var groupId = button.data('group');
    var expanded = button.attr('aria-expanded') === 'true';

    if (!groupId) {
      return;
    }

    $(
      'tr.scm-translation-row--child.scm-translation-group-' + groupId,
    ).toggleClass('scm-is-collapsed', expanded);

    button.attr('aria-expanded', expanded ? 'false' : 'true');
    button.text(expanded ? 'Show translations' : 'Hide translations');
  });

  scmInitTranslationRows();
});
