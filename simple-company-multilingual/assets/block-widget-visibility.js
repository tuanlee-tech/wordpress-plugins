(function (wp) {
  if (
    !wp ||
    !wp.hooks ||
    !wp.compose ||
    !wp.blockEditor ||
    !wp.components ||
    !wp.element
  ) {
    return;
  }

  var addFilter = wp.hooks.addFilter;
  var createElement = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var InspectorControls = wp.blockEditor.InspectorControls;
  var PanelBody = wp.components.PanelBody;
  var CheckboxControl = wp.components.CheckboxControl;
  var withSelect = wp.compose.withSelect;
  var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
  var languages =
    (window.scmBlockWidgetVisibility &&
      window.scmBlockWidgetVisibility.languages) ||
    [];
  var i18n =
    (window.scmBlockWidgetVisibility && window.scmBlockWidgetVisibility.i18n) ||
    {};

  function normalizeRules(value) {
    return Array.isArray(value) && value.length ? value : ['all'];
  }

  function toggleRule(rules, locale, checked) {
    rules = normalizeRules(rules).filter(function (item) {
      return item !== '';
    });

    if (locale === 'all') {
      return checked ? ['all'] : [];
    }

    rules = rules.filter(function (item) {
      return item !== 'all';
    });

    if (checked && rules.indexOf(locale) === -1) {
      rules.push(locale);
    }

    if (!checked) {
      rules = rules.filter(function (item) {
        return item !== locale;
      });
    }

    return rules.length ? rules : ['all'];
  }

  addFilter(
    'blocks.registerBlockType',
    'scm/widget-visibility-attributes',
    function (settings) {
      settings.attributes = settings.attributes || {};

      if (!settings.attributes.scmVisibilityLanguages) {
        settings.attributes.scmVisibilityLanguages = {
          type: 'array',
          default: ['all'],
        };
      }

      return settings;
    },
  );

  var withScmVisibilityControls = createHigherOrderComponent(function (
    BlockEdit,
  ) {
    return function (props) {
      var rules = normalizeRules(props.attributes.scmVisibilityLanguages);

      return createElement(
        Fragment,
        null,
        createElement(BlockEdit, props),
        createElement(
          InspectorControls,
          null,
          createElement(
            PanelBody,
            {
              title: i18n.title || 'Company Multilingual Visibility',
              initialOpen: false,
            },
            createElement(
              'p',
              { className: 'description' },
              i18n.description ||
                'Choose which languages should display this block widget.',
            ),
            createElement(CheckboxControl, {
              label: i18n.allLanguages || 'All languages',
              checked: rules.indexOf('all') !== -1,
              onChange: function (checked) {
                props.setAttributes({
                  scmVisibilityLanguages: toggleRule(rules, 'all', checked),
                });
              },
            }),
            languages.map(function (language) {
              return createElement(CheckboxControl, {
                key: language.locale,
                label: language.label,
                checked: rules.indexOf(language.locale) !== -1,
                onChange: function (checked) {
                  props.setAttributes({
                    scmVisibilityLanguages: toggleRule(
                      rules,
                      language.locale,
                      checked,
                    ),
                  });
                },
              });
            }),
          ),
        ),
      );
    };
  }, 'withScmVisibilityControls');

  addFilter(
    'editor.BlockEdit',
    'scm/widget-visibility-controls',
    withScmVisibilityControls,
  );
})(window.wp);
