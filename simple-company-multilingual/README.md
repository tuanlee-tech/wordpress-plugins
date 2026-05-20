# Simple Company Multilingual - Refactored

This package was refactored from the uploaded single-file plugin into a modular structure.

## Structure

- `simple-company-multilingual.php` - plugin bootstrap.
- `includes/trait-scm-*.php` - feature modules split by responsibility.
- `includes/class-simple-company-multilingual.php` - main class, constructor, singleton and trait composition.
- `assets/admin.css` - admin styles moved out of inline PHP.
- `assets/admin.js` - admin scripts moved out of inline PHP.
- `assets/frontend.css` - frontend switcher styles moved out of inline PHP.
- `uninstall.php` - cleanup only when `delete_data_on_uninstall` is enabled.

## Compatibility

The refactor keeps the existing option/meta keys:

- `scm_settings`
- `_scm_language`
- `_scm_translation_group`
- `_scm_translation_url_slug`

The goal is maintainability without changing existing data or URL behavior.
