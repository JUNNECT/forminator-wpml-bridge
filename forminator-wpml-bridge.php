<?php
/**
 * Plugin Name: Forminator WPML Bridge
 * Description: Switches Forminator forms by the current WPML language.
 * Version: 1.0.0
 * Author: Junnect
 * Author URI: https://junnect.nl
 * Text Domain: forminator-wpml-bridge 
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 8.1
 */

// SPDX-License-Identifier: GPL-2.0-or-later

if (!defined('ABSPATH')) {
    exit;
}

// if (class_exists('Forminator_WPML_Bridge_Plugin')) {
//     return;
// }

class Forminator_WPML_Bridge_Plugin {
    private static $instance = null;
    private $option_name = 'forminator_wpml_forms';
    private $default_language_option_name = 'forminator_wpml_default_language';
    private $forms_transient_name = 'forminator_wpml_bridge_forms';
    private $forms_cache = null;
    private $form_indexes_cache = null;
    private $languages_cache = null;
    private $default_language_cache = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __construct() {
        add_action('admin_menu', [$this, 'registerAdminPage']);
        add_action('admin_init', [$this, 'handleFormUpdate']);
        add_action('save_post_forminator_forms', [$this, 'clearFormCache']);
        add_action('before_delete_post', [$this, 'clearFormCache']);
        add_action('deleted_post', [$this, 'clearFormCache']);
        add_action('trashed_post', [$this, 'clearFormCache']);
        add_filter('the_content', [$this, 'replaceForminatorShortcodesInContent'], 1);
        add_filter('widget_text', [$this, 'replaceForminatorShortcodesInContent'], 1);
        add_filter('et_builder_render_layout', [$this, 'replaceForminatorShortcodesInContent'], 1);
        add_filter('pre_do_shortcode_tag', [$this, 'interceptForminatorShortcode'], 10, 4);
        add_filter('forminator_shortcode_output', [$this, 'filterFormOutput'], 10, 2);
        add_shortcode('forminator_lang', [$this, 'renderLanguageAwareForm']);
    }

    /**
     * Check if WPML is active enough for language-aware switching.
     */
    private function isWPMLActive() {
        return defined('ICL_SITEPRESS_VERSION') || function_exists('icl_get_languages') || has_filter('wpml_current_language');
    }

    /**
     * Register admin page.
     */
    public function registerAdminPage() {
        add_submenu_page(
            'tools.php',
            __('Forminator WPML Languages', 'forminator-wpml-bridge'),
            __('WPML Languages', 'forminator-wpml-bridge'),
            'manage_options',
            'forminator-wpml-bridge',
            [$this, 'renderAdminPage']
        );
    }

    /**
     * Get all Forminator forms.
     */
    private function getAllForms() {
        if ($this->forms_cache !== null) {
            return $this->forms_cache;
        }

        $cached_forms = get_transient($this->forms_transient_name);
        if (is_array($cached_forms)) {
            $this->forms_cache = $cached_forms;

            return $this->forms_cache;
        }

        global $wpdb;

        $this->forms_cache = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s ORDER BY post_title ASC",
            'forminator_forms',
            'publish'
        ));

        set_transient($this->forms_transient_name, $this->forms_cache, 12 * HOUR_IN_SECONDS);

        return $this->forms_cache;
    }

    /**
     * Clear request and persistent form metadata caches.
     */
    public function clearFormCache($post_id = 0) {
        if ($post_id && get_post_type($post_id) !== 'forminator_forms') {
            return;
        }

        $this->forms_cache = null;
        $this->form_indexes_cache = null;
        delete_transient($this->forms_transient_name);
    }

    /**
     * Build cheap lookup indexes once per request.
     */
    private function getFormIndexes() {
        if ($this->form_indexes_cache !== null) {
            return $this->form_indexes_cache;
        }

        $indexes = [
            'by_id' => [],
            'ids' => [],
            'by_language' => [],
            'by_base_language' => [],
            'base_forms' => [],
        ];

        foreach ($this->getAllForms() as $form) {
            $form_id = (int) $form->ID;
            $language = $this->extractLanguageFromTitle($form->post_title);
            $base_title = $this->getBaseTitle($form->post_title);

            $indexes['by_id'][$form_id] = $form;
            $indexes['ids'][] = $form_id;

            if ($language) {
                $indexes['by_language'][$language][] = $form;
                $indexes['by_base_language'][$base_title][$language] = $form;
            }
        }

        $default_language = $this->getDefaultLanguage();
        foreach ($indexes['by_id'] as $form) {
            if ($this->isBaseForm($form, $default_language)) {
                $indexes['base_forms'][] = $form;
            }
        }

        $this->form_indexes_cache = $indexes;

        return $this->form_indexes_cache;
    }

    /**
     * Parse language code from form title.
     * Supports tags like [EN], [nl], [pt-BR] and suffixes like form-en, form_nl, form pt-br.
     */
    private function extractLanguageFromTitle($title) {
        if (preg_match('/\[([a-z]{2,3}(?:[-_][a-z0-9]{2,8})?)\]/i', $title, $matches)) {
            return $this->normalizeLanguageCode($matches[1]);
        }

        foreach ($this->getLanguageCodesByLength() as $lang_code) {
            $pattern = str_replace('\-', '[-_]', preg_quote($lang_code, '/'));
            if (preg_match('/(?:[-_\s])(' . $pattern . ')$/i', $title)) {
                return $lang_code;
            }
        }

        return null;
    }

    /**
     * Remove a language code suffix or token from a form title.
     */
    private function getBaseTitle($title) {
        $title = trim(preg_replace('/\s*\[[a-z]{2,3}(?:[-_][a-z0-9]{2,8})?\]\s*/i', ' ', $title));

        foreach ($this->getLanguageCodesByLength() as $lang_code) {
            $pattern = str_replace('\-', '[-_]', preg_quote($lang_code, '/'));
            if (preg_match('/^(.+?)(?:[-_\s])' . $pattern . '$/i', $title, $matches)) {
                return trim($matches[1], " \t\n\r\0\x0B-_");
            }
        }

        return $title;
    }

    /**
     * Get form configuration from options.
     */
    public function getFormConfig() {
        $config = get_option($this->option_name, []);

        return is_array($config) ? $config : [];
    }

    /**
     * Save form configuration.
     */
    private function saveFormConfig($config) {
        update_option($this->option_name, $config, false);
    }

    /**
     * Handle form configuration updates from admin page.
     */
    public function handleFormUpdate() {
        if (!isset($_POST['forminator_wpml_nonce'])) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['forminator_wpml_nonce']));
        if (!wp_verify_nonce($nonce, 'forminator_wpml_save')) {
            wp_die(esc_html__('Security check failed.', 'forminator-wpml-bridge'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'forminator-wpml-bridge'));
        }

        if (isset($_POST['default_language'])) {
            $this->saveDefaultLanguage(wp_unslash($_POST['default_language']));
        }

        if (isset($_POST['auto_detect']) && wp_unslash($_POST['auto_detect']) === '1') {
            $this->autoDetectForms();
            $this->redirectToAdminPage('detected');
        }

        if (isset($_POST['form_config']) && is_array($_POST['form_config'])) {
            $this->saveFormConfig($this->sanitizePostedConfig(wp_unslash($_POST['form_config'])));
            $this->redirectToAdminPage('saved');
        }
    }

    /**
     * Sanitize posted mapping configuration.
     */
    private function sanitizePostedConfig($posted_config) {
        $config = [];
        $valid_languages = array_keys($this->getActiveLanguages());
        $indexes = $this->getFormIndexes();
        $valid_form_ids = $indexes['ids'];

        foreach ($posted_config as $base_form_id => $lang_forms) {
            $base_form_id = absint($base_form_id);
            if ($base_form_id <= 0 || !is_array($lang_forms) || !in_array($base_form_id, $valid_form_ids, true)) {
                continue;
            }

            foreach ($lang_forms as $lang_code => $mapped_form_id) {
                $lang_code = $this->normalizeLanguageCode($lang_code);
                $mapped_form_id = absint($mapped_form_id);

                if ($mapped_form_id <= 0) {
                    continue;
                }

                if (!in_array($lang_code, $valid_languages, true) || !in_array($mapped_form_id, $valid_form_ids, true)) {
                    continue;
                }

                $config[$base_form_id][$lang_code] = $mapped_form_id;
            }
        }

        return $config;
    }

    /**
     * Redirect after a successful admin POST.
     */
    private function redirectToAdminPage($message) {
        wp_safe_redirect(add_query_arg(
            'message',
            rawurlencode($message),
            admin_url('tools.php?page=forminator-wpml-bridge')
        ));
        exit;
    }

    /**
     * Auto-detect forms by language code in title.
     */
    private function autoDetectForms() {
        $config = [];
        $languages = $this->getActiveLanguages();
        $groups = [];

        foreach ($this->getAllForms() as $form) {
            $lang = $this->extractLanguageFromTitle($form->post_title);
            if (!$lang || !isset($languages[$lang])) {
                continue;
            }

            $base_title = $this->getBaseTitle($form->post_title);
            $groups[$base_title][$lang] = (int) $form->ID;
        }

        foreach ($groups as $language_forms) {
            foreach ($language_forms as $source_lang => $source_form_id) {
                foreach ($language_forms as $target_lang => $target_form_id) {
                    if ($source_lang === $target_lang) {
                        continue;
                    }

                    $config[$source_form_id][$target_lang] = $target_form_id;
                }
            }
        }

        $this->saveFormConfig($config);
    }

    /**
     * Get active WPML languages.
     */
    private function getActiveLanguages() {
        if ($this->languages_cache !== null) {
            return $this->languages_cache;
        }

        $this->languages_cache = [];
        if (!function_exists('icl_get_languages')) {
            return $this->languages_cache;
        }

        $wpml_langs = icl_get_languages('skip_missing=0&orderby=code');
        if (!is_array($wpml_langs)) {
            return $this->languages_cache;
        }

        foreach ($wpml_langs as $lang_code => $lang_data) {
            $lang_code = $this->normalizeLanguageCode($lang_code);
            $this->languages_cache[$lang_code] = isset($lang_data['native_name'])
                ? (string) $lang_data['native_name']
                : strtoupper($lang_code);
        }

        return $this->languages_cache;
    }

    /**
     * Get the saved default language, or auto-detect it from WPML/site language.
     */
    private function getDefaultLanguage() {
        if ($this->default_language_cache !== null) {
            return $this->default_language_cache;
        }

        $saved_language = $this->normalizeLanguageCode(get_option($this->default_language_option_name, ''));
        $languages = $this->getActiveLanguages();
        if ($saved_language && isset($languages[$saved_language])) {
            $this->default_language_cache = $saved_language;

            return $this->default_language_cache;
        }

        $this->default_language_cache = $this->getDetectedDefaultLanguage();

        return $this->default_language_cache;
    }

    /**
     * Detect the default language from the WordPress site locale, then WPML.
     */
    private function getDetectedDefaultLanguage() {
        $default_language = '';

        if (function_exists('get_locale')) {
            $locale = get_locale();
            $default_language = is_string($locale) ? strtok($locale, '_-') : '';
        }

        if (!$default_language) {
            $default_language = apply_filters('wpml_default_language', null);
        }

        if (!$default_language && defined('ICL_LANGUAGE_CODE')) {
            $default_language = ICL_LANGUAGE_CODE;
        }

        $default_language = $this->normalizeLanguageCode($default_language);
        $languages = $this->getActiveLanguages();

        if ($default_language && isset($languages[$default_language])) {
            return $default_language;
        }

        if (!empty($languages)) {
            return (string) array_key_first($languages);
        }

        return $default_language ?: 'en';
    }

    /**
     * Save a manually selected default language. Empty means auto-detect.
     */
    private function saveDefaultLanguage($language_code) {
        $language_code = $this->normalizeLanguageCode($language_code);
        $languages = $this->getActiveLanguages();

        if ($language_code && isset($languages[$language_code])) {
            update_option($this->default_language_option_name, $language_code, false);
        } else {
            delete_option($this->default_language_option_name);
        }

        $this->default_language_cache = null;
    }

    /**
     * Get the current WPML language.
     */
    private function getCurrentLanguage() {
        $current_language = apply_filters('wpml_current_language', null);
        if (!$current_language && defined('ICL_LANGUAGE_CODE')) {
            $current_language = ICL_LANGUAGE_CODE;
        }

        return $current_language
            ? $this->normalizeLanguageCode($current_language)
            : $this->getDefaultLanguage();
    }

    /**
     * Normalize WPML language codes.
     */
    private function normalizeLanguageCode($language_code) {
        return strtolower(str_replace('_', '-', sanitize_key((string) $language_code)));
    }

    /**
     * Return active language codes longest-first so pt-br wins before br.
     */
    private function getLanguageCodesByLength() {
        $codes = array_keys($this->getActiveLanguages());
        usort($codes, function($a, $b) {
            return strlen($b) <=> strlen($a);
        });

        return $codes;
    }

    /**
     * Filter standard Forminator shortcode output.
     */
    public function replaceForminatorShortcodesInContent($content) {
        if (is_admin() || !$this->isWPMLActive() || !is_string($content) || strpos($content, '[forminator_form') === false) {
            return $content;
        }

        $pattern = get_shortcode_regex(['forminator_form']);

        return preg_replace_callback('/' . $pattern . '/', function($matches) {
            if ($matches[1] === '[' && $matches[6] === ']') {
                return substr($matches[0], 1, -1);
            }

            $attrs = shortcode_parse_atts($matches[3]);
            $form_id = is_array($attrs) && isset($attrs['id']) ? absint($attrs['id']) : 0;
            if ($form_id <= 0) {
                return $matches[0];
            }

            return sprintf('[forminator_lang id="%d" debug="0"]', $form_id);
        }, $content);
    }

    /**
     * Intercept Forminator shortcodes that were not replaced at content-filter level.
     */
    public function interceptForminatorShortcode($return, $tag, $attr, $m) {
        if ($tag !== 'forminator_form' || is_admin() || !$this->isWPMLActive()) {
            return $return;
        }

        $form_id = is_array($attr) && isset($attr['id']) ? absint($attr['id']) : 0;
        if ($form_id <= 0) {
            return $return;
        }

        $switched_id = $this->getSwitchedFormId($form_id);
        if (!$switched_id || $switched_id === $form_id) {
            return $return;
        }

        $switched_shortcode = $this->replaceShortcodeId($m[0], $switched_id);
        remove_filter('pre_do_shortcode_tag', [$this, 'interceptForminatorShortcode'], 10);
        $output = do_shortcode($switched_shortcode);
        add_filter('pre_do_shortcode_tag', [$this, 'interceptForminatorShortcode'], 10, 4);

        return $output;
    }

    /**
     * Replace only the id attribute inside a Forminator shortcode string.
     */
    private function replaceShortcodeId($shortcode, $form_id) {
        if (preg_match('/\bid\s*=\s*(["\']).*?\1/i', $shortcode)) {
            return preg_replace('/\bid\s*=\s*(["\']).*?\1/i', 'id="' . absint($form_id) . '"', $shortcode, 1);
        }

        if (preg_match('/\bid\s*=\s*[^\s\]]+/i', $shortcode)) {
            return preg_replace('/\bid\s*=\s*[^\s\]]+/i', 'id="' . absint($form_id) . '"', $shortcode, 1);
        }

        return preg_replace('/\]/', ' id="' . absint($form_id) . '"]', $shortcode, 1);
    }

    /**
     * Filter standard Forminator shortcode output.
     */
    public function filterFormOutput($output, $settings) {
        if (!$this->isWPMLActive()) {
            return $output;
        }

        $form_id = isset($settings['id']) ? absint($settings['id']) : 0;
        if ($form_id <= 0) {
            return $output;
        }

        $switched_id = $this->getSwitchedFormId($form_id);
        if (!$switched_id || $switched_id === $form_id) {
            return $output;
        }

        if (!class_exists('Forminator_CForm_Model')) {
            return $output;
        }

        $form = Forminator_CForm_Model::get_form($switched_id);
        if (!$form || !method_exists($form, 'render')) {
            return $output;
        }

        $switched_settings = $settings;
        $switched_settings['id'] = $switched_id;

        return $form->render($switched_settings);
    }

    /**
     * Shortcode for language-aware forms.
     * Usage: [forminator_lang id="123"] or [forminator_lang id="123" debug="0"]
     */
    public function renderLanguageAwareForm($atts) {
        $atts = shortcode_atts([
            'id' => 0,
            'debug' => '1',
        ], $atts, 'forminator_lang');

        $original_id = absint($atts['id']);
        $form_id = $original_id;
        $debug_enabled = $atts['debug'] !== '0' && strtolower((string) $atts['debug']) !== 'false';

        if ($form_id <= 0) {
            return '<!-- Forminator: Invalid form ID -->';
        }

        if ($this->isWPMLActive()) {
            $form_id = $this->getSwitchedFormId($form_id);
        }

        $output = do_shortcode(sprintf('[forminator_form id="%d"]', $form_id));

        if (!$debug_enabled) {
            return $output;
        }

        return $this->getShortcodeDebugComment($original_id, $form_id) . $output;
    }

    /**
     * Build an inline HTML comment for shortcode debugging.
     */
    private function getShortcodeDebugComment($original_id, $rendered_id) {
        $config = $this->getFormConfig();
        $current_language = $this->getCurrentLanguage();
        $default_language = $this->getDefaultLanguage();
        $saved_default_language = $this->normalizeLanguageCode(get_option($this->default_language_option_name, ''));
        $mapping = isset($config[$original_id]) && is_array($config[$original_id])
            ? array_map('absint', $config[$original_id])
            : [];

        $debug = [
            'shortcode' => 'forminator_lang',
            'wpml_active' => $this->isWPMLActive(),
            'current_language' => $current_language,
            'default_language' => $default_language,
            'saved_default_language' => $saved_default_language ?: 'auto',
            'detected_default_language' => $this->getDetectedDefaultLanguage(),
            'original_id' => (int) $original_id,
            'rendered_id' => (int) $rendered_id,
            'mapping_for_original_id' => $mapping,
            'mapped_id_for_current_language' => isset($mapping[$current_language]) ? (int) $mapping[$current_language] : null,
            'rendered_form_exists' => $this->formExists($rendered_id),
        ];

        return "\n<!-- Forminator WPML Bridge debug: " . esc_html(wp_json_encode($debug)) . " -->\n";
    }

    /**
     * Get the switched form ID for current language.
     */
    private function getSwitchedFormId($original_id) {
        if (!$this->isWPMLActive()) {
            return $original_id;
        }

        $current_lang = $this->getCurrentLanguage();
        $config = $this->getFormConfig();
        if (isset($config[$original_id][$current_lang])) {
            $switched_id = absint($config[$original_id][$current_lang]);

            return $this->formExists($switched_id) ? $switched_id : $original_id;
        }

        $detected_id = $this->detectSwitchedFormIdFromTitle($original_id, $current_lang);
        if ($detected_id && $detected_id !== $original_id) {
            return $detected_id;
        }

        if ($current_lang === $this->getDefaultLanguage()) {
            return $original_id;
        }

        return $original_id;
    }

    /**
     * Find a sibling form by matching title base and target language.
     */
    private function detectSwitchedFormIdFromTitle($original_id, $target_language) {
        $indexes = $this->getFormIndexes();
        if (empty($indexes['by_id'][$original_id])) {
            return $original_id;
        }

        $source_form = $indexes['by_id'][$original_id];
        $base_title = $this->getBaseTitle($source_form->post_title);

        if (isset($indexes['by_base_language'][$base_title][$target_language])) {
            return (int) $indexes['by_base_language'][$base_title][$target_language]->ID;
        }

        return $original_id;
    }

    /**
     * Check whether a configured form still exists.
     */
    private function formExists($form_id) {
        $indexes = $this->getFormIndexes();

        return isset($indexes['by_id'][(int) $form_id]);
    }

    /**
     * Render admin configuration page.
     */
    public function renderAdminPage() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'forminator-wpml-bridge'));
        }

        $message = isset($_GET['message']) ? sanitize_key(wp_unslash($_GET['message'])) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Forminator WPML Language Configuration', 'forminator-wpml-bridge'); ?></h1>

            <?php if ($message === 'saved') : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Configuration saved successfully.', 'forminator-wpml-bridge'); ?></p></div>
            <?php elseif ($message === 'detected') : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Forms detected and configuration saved.', 'forminator-wpml-bridge'); ?></p></div>
            <?php endif; ?>

            <?php if (!$this->isWPMLActive()) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e('WPML does not appear to be active. You can configure mappings, but language switching will be disabled until WPML is available.', 'forminator-wpml-bridge'); ?></p></div>
            <?php endif; ?>

            <p><?php esc_html_e('Configure which Forminator forms to use for each language. Auto-detection groups forms with the same base title and language tags or suffixes such as "Contact Form [EN]", "contact-form-en", and "contact-form-nl".', 'forminator-wpml-bridge'); ?></p>

            <form method="post" action="">
                <?php wp_nonce_field('forminator_wpml_save', 'forminator_wpml_nonce'); ?>

                <table class="form-table" role="presentation">
                    <?php $this->renderDefaultLanguageField(); ?>
                    <tr>
                        <th scope="row">
                            <label for="auto_detect"><?php esc_html_e('Auto-Detect Forms', 'forminator-wpml-bridge'); ?></label>
                        </th>
                        <td>
                            <p>
                                <button type="submit" name="auto_detect" value="1" class="button button-secondary">
                                    <?php esc_html_e('Auto-Detect Forms by Language Code', 'forminator-wpml-bridge'); ?>
                                </button>
                            </p>
                            <p class="description">
                                <?php esc_html_e('This scans all forms and groups titles that share the same base name with different language tags.', 'forminator-wpml-bridge'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php $this->renderFormMappingTable(); ?>

                <?php submit_button(__('Save Configuration', 'forminator-wpml-bridge')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render the configurable default-language field.
     */
    private function renderDefaultLanguageField() {
        $languages = $this->getActiveLanguages();
        $saved_language = $this->normalizeLanguageCode(get_option($this->default_language_option_name, ''));
        $detected_language = $this->getDetectedDefaultLanguage();
        ?>
        <tr>
            <th scope="row">
                <label for="default_language"><?php esc_html_e('Default Language', 'forminator-wpml-bridge'); ?></label>
            </th>
            <td>
                <select id="default_language" name="default_language">
                    <option value="">
                        <?php echo esc_html(sprintf(__('Auto-detect from site language (%s)', 'forminator-wpml-bridge'), strtoupper($detected_language))); ?>
                    </option>
                    <?php foreach ($languages as $lang_code => $lang_name) : ?>
                        <option value="<?php echo esc_attr($lang_code); ?>" <?php selected($saved_language, $lang_code); ?>>
                            <?php echo esc_html(sprintf('%s (%s)', $lang_name, strtoupper($lang_code))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">
                    <?php esc_html_e('Auto-detect uses the WordPress site language first, then WPML’s default language.', 'forminator-wpml-bridge'); ?>
                </p>
            </td>
        </tr>
        <?php
    }

    /**
     * Render form mapping configuration table.
     */
    private function renderFormMappingTable() {
        $indexes = $this->getFormIndexes();
        $forms = $indexes['base_forms'];
        $forms_by_language = $indexes['by_language'];
        $config = $this->getFormConfig();
        $languages = $this->getActiveLanguages();
        $default_language = $this->getDefaultLanguage();

        if (empty($forms)) {
            echo '<p>' . esc_html__('No Forminator forms found.', 'forminator-wpml-bridge') . '</p>';
            return;
        }

        if (empty($languages)) {
            echo '<p>' . esc_html__('No WPML languages found.', 'forminator-wpml-bridge') . '</p>';
            return;
        }
        ?>
        <h2><?php esc_html_e('Form Language Mapping', 'forminator-wpml-bridge'); ?></h2>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php echo esc_html(sprintf(__('Base Form (%s)', 'forminator-wpml-bridge'), strtoupper($default_language))); ?></th>
                    <?php foreach ($languages as $lang_code => $lang_name) : ?>
                        <?php if ($lang_code === $default_language) : ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <th><?php echo esc_html($lang_name); ?> (<?php echo esc_html(strtoupper($lang_code)); ?>)</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forms as $form) : ?>
                    <?php if (!$this->isBaseForm($form, $default_language)) : ?>
                        <?php continue; ?>
                    <?php endif; ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($form->post_title); ?></strong>
                            <br><small><?php echo esc_html(sprintf(__('ID: %d', 'forminator-wpml-bridge'), $form->ID)); ?></small>
                        </td>
                        <?php foreach ($languages as $lang_code => $lang_name) : ?>
                            <?php if ($lang_code === $default_language) : ?>
                                <?php continue; ?>
                            <?php endif; ?>
                            <td>
                                <select name="form_config[<?php echo esc_attr($form->ID); ?>][<?php echo esc_attr($lang_code); ?>]" style="width: 100%; max-width: 250px;">
                                    <option value=""><?php esc_html_e('None', 'forminator-wpml-bridge'); ?></option>
                                    <?php
                                    $selected_id = isset($config[$form->ID][$lang_code]) ? absint($config[$form->ID][$lang_code]) : 0;
                                    foreach ($forms_by_language[$lang_code] ?? [] as $option_form) :
                                        if ((int) $option_form->ID === (int) $form->ID) {
                                            continue;
                                        }
                                        ?>
                                        <option value="<?php echo esc_attr($option_form->ID); ?>" <?php selected($selected_id, $option_form->ID); ?>>
                                            <?php echo esc_html(sprintf('%s (ID: %d)', $option_form->post_title, $option_form->ID)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * A base form is either untagged or tagged with WPML's default language.
     */
    private function isBaseForm($form, $default_language) {
        $lang = $this->extractLanguageFromTitle($form->post_title);

        return $lang === null || $lang === $default_language;
    }
}

add_action('init', ['Forminator_WPML_Bridge_Plugin', 'getInstance']);
