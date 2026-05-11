# Forminator WPML Bridge

WordPress plugin that switches Forminator forms based on the current WPML language.

## What it does

- Maps Forminator forms to WPML languages.
- Auto-detects related forms from names such as `gratis-adviesgesprek-en` and `gratis-adviesgesprek-nl`.
- Supports language tags such as `Contact Form [EN]` and `Contact Form [NL]`.
- Adds a `[forminator_lang id="123"]` shortcode.
- Intercepts frontend `[forminator_form id="123"]` shortcodes so Divi and normal content can switch forms automatically.
- Caches form metadata to reduce admin and frontend overhead.

## Installation

1. Upload this folder to `wp-content/plugins/forminator-wpml-bridge`.
2. Activate **Forminator WPML Bridge** in WordPress.
3. Go to **Tools > WPML Languages**.
4. Click **Auto-Detect Forms by Language Code** or configure mappings manually.

## Shortcodes

Use the language-aware shortcode directly:

```text
[forminator_lang id="123"]
```

Disable inline debug comments:

```text
[forminator_lang id="123" debug="0"]
```

Plain Forminator shortcodes are also intercepted on the frontend:

```text
[forminator_form id="123"]
```

## Naming Convention

Auto-detection supports suffixes:

```text
gratis-adviesgesprek-en
gratis-adviesgesprek-nl
```

And bracketed language tags:

```text
Contact Form [EN]
Contact Form [NL]
```

## Requirements

- WordPress 5.0+
- PHP 8.1+
- Forminator
- WPML
