<?php
declare(strict_types=1);

/**
 * Auto Metabox Generator — the edit screen for a declared `fields` array,
 * whether or not the post type has a Data model behind it.
 *
 * THIS CLASS DOES NOT SANITIZE. It reads the POST, unslashes it once, narrows
 * it to the fields the SITE declared, and hands the values on: to the Data
 * model, which cleans them inside update(), or — where a post type has no
 * model — straight to NTDST_FieldTypes::get($type)->sanitize. It once carried a
 * private type switch of its own, and two tables that answer "what is a bool"
 * can disagree (INV-8): this one read the string 'false' as true and absint()'d
 * the sign off an int, while the model's table did neither.
 *
 * The `html` type's storage and output rules belong to the VOCABULARY and live
 * with the entry that owns them — the `html` row in api/FieldTypes.php. What
 * matters here: an `html` field stores wp_kses_post()'s answer and nothing
 * else. A <script> TAG does not survive it; the script's BODY does, as text,
 * because kses removes the tag and keeps what was between them. Escaping on the
 * way OUT is the consumer's half, and it is wp_kses_post() again — never
 * esc_html(), never a raw echo.
 *
 * @package NTDST
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

final class NTDST_MetaboxGenerator
{
    private static ?self $instance = null;
    private array $registered_models = [];

    /**
     * Post-scoped transient prefix carrying a failed-save error across the
     * save_post redirect to the next admin request, where
     * render_save_error_notice() renders and deletes it (read-once). The key is
     * `<prefix><post_id>`.
     */
    private const SAVE_ERROR_TRANSIENT_PREFIX = 'ntdst_metabox_save_error_';

    private function __construct()
    {
        add_action('add_meta_boxes', [$this, 'register_metaboxes']);
        add_action('save_post', [$this, 'save_metabox_data'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_metabox_scripts']);
        add_action('admin_notices', [$this, 'render_save_error_notice']);
    }

    /**
     * Enqueue the metabox field client (relation autocomplete, gallery,
     * repeater) on registered-model edit screens.
     *
     * The client ships WITH this class in ntdst-core — it drives the markup
     * render_* below emits and calls the `relation_search` api_data action
     * NTDST_RelationField registers, so the three travel together. It used
     * to be probed from the active theme's `assets/dist/theme-services.js`,
     * which no theme ever shipped: every site's pickers were silently dead.
     *
     * The relation autocomplete transports through window.ntdstAPI, so the
     * shared API client is enqueued alongside and declared as a dependency
     * — without it metabox-fields.js disables relation fields on its own.
     */
    public function enqueue_metabox_scripts(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        global $post_type;

        if (!isset($this->registered_models[$post_type])) {
            return;
        }

        $deps = ['jquery', 'jquery-ui-sortable'];

        if (function_exists('ntdst_enqueue_api_client')) {
            ntdst_enqueue_api_client();
            $deps[] = 'ntdst-api';
        }

        $path = dirname(__DIR__) . '/assets/js/metabox-fields.js';
        wp_enqueue_script(
            'ntdst-metabox-fields',
            plugins_url('assets/js/metabox-fields.js', dirname(__DIR__) . '/ntdst-core.php'),
            $deps,
            file_exists($path) ? (string) filemtime($path) : '1.0.0',
            true,
        );
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register a model for auto-metabox generation.
     *
     * The declaration is checked HERE, by the vocabulary itself, exactly as the
     * Data model's constructor checks its own (reviewer S-5): a `fields` array
     * is a `fields` array, and the plain-post-type half of the fleet declares
     * one too. Until v5.0.0 this door checked nothing, so a retired name became
     * a "Saving failed" notice after an editor had typed a screenful, and a
     * cell-less sub-field became a text input in a repeater row.
     *
     * @throws InvalidArgumentException naming the field and what to write instead.
     */
    public function register(string $model_name, array $config): void
    {
        $fields = $config['fields'] ?? [];

        if (is_array($fields)) {
            NTDST_FieldTypes::assertDeclarations($fields, "Metabox '{$model_name}'");
        }

        $this->registered_models[$model_name] = $config;
    }

    /**
     * Register metaboxes for all registered models
     */
    public function register_metaboxes(): void
    {
        foreach ($this->registered_models as $model_name => $config) {
            // Skip if auto_metabox is explicitly set to false
            // This allows services to handle their own metabox rendering
            if (isset($config['auto_metabox']) && $config['auto_metabox'] === false) {
                continue;
            }

            $fields = $config['fields'] ?? [];

            if (empty($fields)) {
                continue;
            }

            // Check if field groups are defined
            $field_groups = $config['field_groups'] ?? null;

            if ($field_groups && is_array($field_groups)) {
                // Check if tabbed interface is requested
                if (!empty($config['use_tabs'])) {
                    $this->register_tabbed_metabox($model_name, $config, $fields, $field_groups);
                } else {
                    // Create multiple metaboxes based on groups
                    $this->register_grouped_metaboxes($model_name, $config, $fields, $field_groups);
                }
            } else {
                // Create single metabox with all fields (default behavior)
                add_meta_box(
                    "ntdst_{$model_name}_fields",
                    $config['metabox_title'] ?? ucwords(str_replace('_', ' ', $model_name)) . ' Fields',
                    [$this, 'render_metabox'],
                    $model_name,
                    $config['metabox_context'] ?? 'normal',
                    $config['metabox_priority'] ?? 'high',
                    ['model_name' => $model_name, 'fields' => $fields],
                );
            }
        }
    }

    /**
     * Register multiple metaboxes based on field groups
     */
    private function register_grouped_metaboxes(string $model_name, array $config, array $all_fields, array $field_groups): void
    {
        $used_fields = [];

        foreach ($field_groups as $group_key => $group_config) {
            $group_fields_keys = $group_config['fields'] ?? [];

            if (empty($group_fields_keys)) {
                continue;
            }

            // Extract only the fields for this group
            $group_fields = [];
            foreach ($group_fields_keys as $field_key) {
                if (isset($all_fields[$field_key])) {
                    $group_fields[$field_key] = $all_fields[$field_key];
                    $used_fields[] = $field_key;
                }
            }

            if (empty($group_fields)) {
                continue;
            }

            add_meta_box(
                "ntdst_{$model_name}_{$group_key}",
                $group_config['title'] ?? ucwords(str_replace('_', ' ', $group_key)),
                [$this, 'render_metabox'],
                $model_name,
                $group_config['context'] ?? 'normal',
                $group_config['priority'] ?? 'default',
                ['model_name' => $model_name, 'fields' => $group_fields],
            );
        }

        // Create "Other Fields" metabox for ungrouped fields
        $ungrouped_fields = array_diff_key($all_fields, array_flip($used_fields));

        if (!empty($ungrouped_fields)) {
            add_meta_box(
                "ntdst_{$model_name}_other",
                'Other Fields',
                [$this, 'render_metabox'],
                $model_name,
                'normal',
                'low',
                ['model_name' => $model_name, 'fields' => $ungrouped_fields],
            );
        }
    }

    /**
     * Register single tabbed metabox for field groups
     */
    private function register_tabbed_metabox(string $model_name, array $config, array $all_fields, array $field_groups): void
    {
        $this->warn_discarded_group_placement($model_name, $field_groups);

        add_meta_box(
            "ntdst_{$model_name}_tabbed",
            $config['metabox_title'] ?? ucwords(str_replace('_', ' ', $model_name)),
            [$this, 'render_tabbed_metabox'],
            $model_name,
            $config['tabs_context'] ?? 'normal',
            $config['tabs_priority'] ?? 'high',
            [
                'model_name' => $model_name,
                'fields' => $all_fields,
                'field_groups' => $field_groups,
            ],
        );
    }

    /**
     * Warn when a tabbed model declares per-group placement that is discarded.
     *
     * `context` and `priority` on a field group are read by exactly one
     * caller — register_grouped_metaboxes(), the `use_tabs` FALSY branch. A
     * tabbed model registers ONE metabox at the model-level `tabs_context` /
     * `tabs_priority` and passes the groups through as tab definitions only,
     * so a group declaring `'context' => 'side'` under `use_tabs` is dropped
     * on the floor. It read as effective and did nothing, with no warning, no
     * error and no log — daan alone shipped four models in that state.
     *
     * DIAGNOSTIC ONLY. This does not fix the caller's config and does not
     * start honouring the discarded keys: making `use_tabs` place per group
     * is a rendering change across every ntdst site, not this one. Called
     * from register_tabbed_metabox() rather than from the branch in
     * register_metaboxes(), so it fires on exactly the path that discards —
     * the grouped path (where the keys ARE honoured) and the
     * `auto_metabox => false` opt-out cannot reach it, which is a structural
     * guarantee rather than a duplicated condition that can drift.
     *
     * Mechanism: `ntdst_log('metabox')->warning()`, not `_doing_it_wrong()`.
     * See NTDST_Data_Model::warnUnregisteredKeys() — the framework's existing
     * answer to this exact situation (a caller passed config the framework
     * silently drops), same channel-scoped warning, same `function_exists`
     * guard. `_doing_it_wrong()` appears nowhere in ntdst-core, and under
     * WP_DEBUG it raises E_USER_WARNING, which would turn a diagnostic into
     * suite failures for consumers running PHPUnit with failOnWarning.
     *
     * @param array<array-key, mixed> $field_groups
     */
    private function warn_discarded_group_placement(string $model_name, array $field_groups): void
    {
        $offending = [];

        foreach ($field_groups as $group_key => $group_config) {
            if (!is_array($group_config)) {
                continue;
            }

            // One entry per GROUP, not per discarded key: a group declaring
            // both is one misconfiguration, not two log lines.
            if (isset($group_config['context']) || isset($group_config['priority'])) {
                // `field_groups` is a LIST on real services (e.g. a
                // getFieldGroups() that returns unkeyed group arrays), where
                // (string) $group_key degrades to "0, 1, 2, 3" and names
                // nothing the developer can search for — which is the entire
                // purpose of this warning. Fall back to the group's title.
                $offending[] = is_int($group_key)
                    ? ($group_config['title'] ?? "#{$group_key}")
                    : (string) $group_key;
            }
        }

        if (empty($offending) || !function_exists('ntdst_log')) {
            return;
        }

        ntdst_log('metabox')->warning(
            sprintf(
                'Model "%s" sets use_tabs, so the per-group "context"/"priority" declared by '
                    . 'group(s) [%s] are ignored — a tabbed model registers ONE metabox at the '
                    . 'model-level tabs_context/tabs_priority. Set tabs_context/tabs_priority on '
                    . 'the model, or drop use_tabs to get one placed metabox per group.',
                $model_name,
                implode(', ', $offending),
            ),
            [
                'model' => $model_name,
                'groups' => $offending,
            ],
        );
    }

    /**
     * Render tabbed metabox HTML
     */
    public function render_tabbed_metabox(\WP_Post $post, array $metabox): void
    {
        $model_name = $metabox['args']['model_name'];
        $all_fields = $metabox['args']['fields'];
        $field_groups = $metabox['args']['field_groups'];

        // Check if this is a registered Data model
        $is_data_model = $this->isDataModel($model_name);

        // Get current values
        if ($is_data_model) {
            // 'any': the edit screen renders whatever status the row has.
            $data = ntdst_data()->get($model_name)->find($post->ID, 'any');
            $values = ($data && !is_wp_error($data)) ? $data->fields : [];
        } else {
            // The save's own key rule — see render_metabox().
            $values = [];
            foreach (array_keys($all_fields) as $field_name) {
                $values[$field_name] = get_post_meta($post->ID, sanitize_key((string) $field_name), true);
            }
        }

        // The post's own nonce — see render_metabox().
        wp_nonce_field($this->nonce_action($model_name, $post->ID), "ntdst_{$model_name}_nonce");

        // Render tab navigation
        echo '<div class="ntdst-tabbed-metabox">';
        echo '<h2 class="nav-tab-wrapper">';

        $first_tab = true;
        foreach ($field_groups as $group_key => $group_config) {
            $group_fields_keys = $group_config['fields'] ?? [];
            if (empty($group_fields_keys)) {
                continue;
            }

            $tab_title = $group_config['title'] ?? ucwords(str_replace('_', ' ', $group_key));
            $active_class = $first_tab ? ' nav-tab-active' : '';

            echo '<a href="#tab-' . esc_attr($group_key) . '" class="nav-tab' . $active_class . '" data-tab="' . esc_attr($group_key) . '">';
            echo esc_html($tab_title);
            echo '</a>';

            $first_tab = false;
        }

        echo '</h2>';

        // Render tab content
        $first_tab = true;
        foreach ($field_groups as $group_key => $group_config) {
            $group_fields_keys = $group_config['fields'] ?? [];
            if (empty($group_fields_keys)) {
                continue;
            }

            $display_style = $first_tab ? '' : ' style="display:none;"';

            echo '<div id="tab-' . esc_attr($group_key) . '" class="ntdst-tab-content"' . $display_style . '>';

            // Render fields for this group
            echo '<div class="ntdst-metabox-fields">';

            foreach ($group_fields_keys as $field_key) {
                if (isset($all_fields[$field_key])) {
                    // No native `required` in a tabbed box: every inactive
                    // panel is display:none (and the tab JS hides the active
                    // one on the next switch), so a native constraint would
                    // land on an unfocusable control and the browser would
                    // refuse to submit the post at all. Marker + aria-required
                    // still tell the editor.
                    $this->render_field(
                        $field_key,
                        $all_fields[$field_key],
                        $values[$field_key] ?? null,
                        false,
                    );
                }
            }

            echo '</div>';
            echo '</div>';

            $first_tab = false;
        }

        echo '</div>';

        // Add JavaScript for tab switching
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('.ntdst-tabbed-metabox .nav-tab').on('click', function(e) {
                e.preventDefault();

                var $this = $(this);
                var tab = $this.data('tab');

                // Update active tab
                $this.siblings().removeClass('nav-tab-active');
                $this.addClass('nav-tab-active');

                // Show/hide content
                $this.closest('.ntdst-tabbed-metabox').find('.ntdst-tab-content').hide();
                $('#tab-' + tab).show();

                // Save active tab to localStorage
                localStorage.setItem('ntdst_active_tab_<?php echo esc_js($model_name); ?>', tab);
            });

            // Restore active tab from localStorage
            var activeTab = localStorage.getItem('ntdst_active_tab_<?php echo esc_js($model_name); ?>');
            if (activeTab) {
                $('.ntdst-tabbed-metabox .nav-tab[data-tab="' + activeTab + '"]').trigger('click');
            }
        });
        </script>
        <style>
        /* Tabbed Metabox Styling */
        .ntdst-tabbed-metabox {
            margin: -12px -12px 0;
        }

        .ntdst-tabbed-metabox .nav-tab-wrapper {
            margin: 0 !important;
            padding: 12px 12px 0 12px !important;
            background: transparent;
            border-bottom: 1px solid #c3c4c7;
            line-height: 1 !important;
            font-size: inherit !important;
        }

        .ntdst-tabbed-metabox .nav-tab {
            position: relative;
            margin: 0 8px -2px 0;
            padding: 10px 16px;
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            font-size: 13px;
            line-height: 1.4;
            color: #646970;
            text-decoration: none;
            transition: all 0.15s ease-in-out;
        }

        .ntdst-tabbed-metabox .nav-tab:hover {
            color: #1d2327;
            border-bottom-color: #8c8f94;
        }

        .ntdst-tabbed-metabox .nav-tab-active {
            color: #1d2327;
            font-weight: 500;
            border-bottom-color: #2271b1;
        }

        .ntdst-tabbed-metabox .nav-tab-active:hover {
            border-bottom-color: #2271b1;
        }

        .ntdst-tab-content {
            background: #fff;
            padding: 20px 12px 12px;
            border-top: 1px solid #c3c4c7;
            margin-top: -1px;
        }

        .ntdst-tab-content .ntdst-metabox-fields {
            padding: 0;
        }

        .ntdst-tab-content .ntdst-field {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #f0f0f1;
        }

        .ntdst-tab-content .ntdst-field:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .ntdst-tab-content .ntdst-field label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #1d2327;
        }

        .ntdst-tab-content .ntdst-field .description {
            margin-top: 6px;
            font-size: 13px;
            color: #646970;
            font-style: normal;
        }

        .ntdst-tab-content .ntdst-field input[type="text"],
        .ntdst-tab-content .ntdst-field input[type="email"],
        .ntdst-tab-content .ntdst-field input[type="url"],
        .ntdst-tab-content .ntdst-field input[type="number"],
        .ntdst-tab-content .ntdst-field textarea,
        .ntdst-tab-content .ntdst-field select {
            width: 100%;
            max-width: 600px;
        }

        .ntdst-tab-content .ntdst-field textarea {
            min-height: 100px;
        }

        /* Repeater fields in tabs */
        .ntdst-tab-content .ntdst-repeater-field {
            margin-top: 10px;
        }

        .ntdst-tab-content .ntdst-repeater-table {
            margin-top: 10px;
        }

        .ntdst-tab-content .ntdst-repeater-add {
            margin-top: 10px;
        }

        /* Gallery fields in tabs */
        .ntdst-tab-content .ntdst-gallery-container {
            margin-top: 10px;
        }

        /* Image fields in tabs */
        .ntdst-tab-content .ntdst-image-preview {
            margin-top: 10px;
        }

        /* Relation fields in tabs */
        .ntdst-tab-content .ntdst-relation-field {
            margin-top: 10px;
        }
        </style>
        <?php

        $this->render_shared_field_styles();
    }

    /**
     * The field styles both metabox renders share, emitted once per request.
     *
     * The guard is INSIDE, not at each call site: two callers each kept their
     * own `static $shared_styles_rendered` flag, so the block was emitted twice
     * on a screen carrying one tabbed metabox and one plain one — and a third
     * caller would have had to remember to bring a third flag.
     */
    private function render_shared_field_styles(): void
    {
        static $rendered = false;

        if ($rendered) {
            return;
        }

        $rendered = true;

        echo '<style>
            /* Required-field marker */
            .ntdst-required {
                color: #d63638;
                font-weight: 700;
                margin-left: 2px;
            }

            /* Relation Field Styles */
            .ntdst-relation-field {
                margin-top: 8px;
            }
            .ntdst-relation-selected {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
                margin-bottom: 10px;
                min-height: 32px;
            }
            .ntdst-relation-tag {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                background: #2271b1;
                color: #fff;
                padding: 4px 8px 4px 12px;
                border-radius: 3px;
                font-size: 13px;
                line-height: 1.4;
            }
            .ntdst-relation-tag:hover {
                background: #135e96;
            }
            .ntdst-relation-remove {
                background: transparent;
                border: none;
                color: #fff;
                font-size: 18px;
                line-height: 1;
                cursor: pointer;
                padding: 0;
                width: 16px;
                height: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 2px;
            }
            .ntdst-relation-remove:hover {
                background: rgba(255, 255, 255, 0.2);
            }
            .ntdst-relation-search {
                position: relative;
            }
            .ntdst-relation-input {
                width: 100%;
                max-width: 500px;
            }
            .ntdst-relation-results {
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                max-width: 500px;
                background: #fff;
                border: 1px solid #8c8f94;
                border-top: none;
                max-height: 300px;
                overflow-y: auto;
                z-index: 1000;
                box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            }
            .ntdst-relation-result-item {
                padding: 8px 12px;
                cursor: pointer;
                border-bottom: 1px solid #f0f0f1;
                font-size: 13px;
            }
            .ntdst-relation-result-item:hover {
                background: #f6f7f7;
            }
            .ntdst-relation-result-item:last-child {
                border-bottom: none;
            }
            .ntdst-relation-result-empty,
            .ntdst-relation-result-loading {
                padding: 12px;
                text-align: center;
                color: #646970;
                font-size: 13px;
            }
        </style>';
    }

    /**
     * Render metabox HTML
     */
    public function render_metabox(\WP_Post $post, array $metabox): void
    {
        $model_name = $metabox['args']['model_name'];
        $fields = $metabox['args']['fields'];

        // Check if this is a registered Data model or native post type
        $is_data_model = $this->isDataModel($model_name);

        // Get current values
        if ($is_data_model) {
            // Use Data.php ORM for registered models
            // 'any': the edit screen renders whatever status the row has.
            $data = ntdst_data()->get($model_name)->find($post->ID, 'any');
            $values = ($data && !is_wp_error($data)) ? $data->fields : [];
        } else {
            // Use WordPress native functions for unregistered/native post types.
            // sanitize_key() is the SAME key rule the save writes under — one
            // question, asked on both sides, or a screen reads a key its own
            // save never wrote.
            $values = [];
            foreach (array_keys($fields) as $field_name) {
                $values[$field_name] = get_post_meta($post->ID, sanitize_key((string) $field_name), true);
            }
        }

        // One nonce per POST, minted on every box this screen draws: the token
        // is the post's, and a screen that reuses another post's is a
        // "save any {$model_name}" credential. Re-emitting it for a second
        // metabox on the same screen is a duplicate hidden input, which the
        // browser posts once — the price of a per-post token, and the reason
        // the old per-model static could not stay.
        wp_nonce_field($this->nonce_action($model_name, $post->ID), "ntdst_{$model_name}_nonce");

        echo '<div class="ntdst-metabox-fields">';

        foreach ($fields as $field_name => $field_type) {
            $this->render_field($field_name, $field_type, $values[$field_name] ?? null);
        }

        echo '</div>';

        // Add basic metabox styling (non-tabbed)
        echo '<style>
            .ntdst-metabox-fields { padding: 10px 0; }
            .ntdst-field { margin-bottom: 20px; }
            .ntdst-field label {
                display: block;
                font-weight: 600;
                margin-bottom: 5px;
                font-size: 13px;
            }
            .ntdst-field input[type="text"],
            .ntdst-field input[type="number"],
            .ntdst-field input[type="email"],
            .ntdst-field textarea,
            .ntdst-field select {
                width: 100%;
                max-width: 500px;
            }
            .ntdst-field textarea { min-height: 100px; }
            .ntdst-field .description {
                font-size: 12px;
                color: #666;
                margin-top: 5px;
            }
            .ntdst-field-array {
                background: #f5f5f5;
                padding: 10px;
                border-radius: 4px;
            }
        </style>';

        $this->render_shared_field_styles();
    }

    /**
     * Render one declared field: resolve its control, then render it.
     *
     * The declaration names a TYPE; NTDST_FieldTypes answers with the CONTROL.
     * This method never reads the type name itself — an unknown or retired
     * name fatals in the registry, naming what to write instead, rather than
     * falling through to a text box that looks like it worked.
     *
     * Defense-in-depth: $name, $field_id, $field_name, and $label all come
     * from CPT-config field keys (developer-controlled, not user input), but
     * we esc_attr/esc_html them anyway so a typo'd or third-party CPT
     * registration can't introduce an XSS path.
     *
     * $allow_native_required is OFF for the tabbed render path. A `required`
     * control inside a `display:none` panel cannot be focused, so the browser
     * refuses the submit outright with only a console message and the post
     * never saves.
     */
    private function render_field(
        string $name,
        mixed $type,
        mixed $value,
        bool $allow_native_required = true,
    ): void {
        $label = ucwords(str_replace('_', ' ', $name));
        $field_id = "ntdst_field_{$name}";
        $field_name = "ntdst_fields[{$name}]";
        $field_id_attr = esc_attr($field_id);
        $field_name_attr = esc_attr($field_name);

        // The declaration, always as an array. A bare string is a type and
        // nothing else; from here down there is ONE shape, so the control arms
        // read the developer's own words — options, sub_fields, post_type,
        // button_text — out of one place instead of two.
        $config = is_array($type) ? $type : ['type' => $type];
        $type_name = NTDST_FieldTypes::declaredType($type);
        $readonly = !empty($config['readonly']);
        $required = !empty($config['required']);
        $safe_value = $value ?? '';

        // A `callback` field renders ITSELF. It is a render directive, not a
        // vocabulary entry — NTDST_FieldTypes has no `callback` and should not
        // grow one — so it is answered before the registry is asked.
        if ($type_name === 'callback') {
            if (isset($config['callback']) && is_callable($config['callback'])) {
                global $post;
                call_user_func($config['callback'], $post, $name, $value);
            }

            return;
        }

        $entry = NTDST_FieldTypes::get($type_name);
        $control = $entry->control;

        // Native constraint validation is only safe on a control the browser
        // can FOCUS and that actually CARRIES the value — and the registry
        // entry answers both questions, so there is no second list of type
        // names here to disagree with the first (INV-8).
        //
        //   cell: false   the composite widgets — `html` behind the editor,
        //                 `relation`/`gallery`/`repeater` built out of hidden
        //                 inputs. Nothing here is focusable or validatable.
        //   checkbox      `required` on a checkbox means "must be TICKED",
        //                 which silently rewrites "mandatory" into "must be
        //                 Yes"; the arm posts a companion hidden input anyway,
        //                 so the field is never absent from the POST.
        //   media         the value is a hidden input behind a picker button.
        //
        // The failure this avoids is not a missing hint: a constraint on an
        // unfocusable control makes the whole post form permanently
        // unsubmittable, reporting only "An invalid form control ... is not
        // focusable" to the console.
        $native_required = $required
            && $allow_native_required
            && !$readonly
            && $entry->cell
            && !in_array($control, ['checkbox', 'media'], true);

        // The constraint is exposed to assistive tech either way: the control
        // carries `required aria-required` where the browser can honour it (see
        // render_control()), and the .ntdst-field wrapper carries the aria hint
        // alone where `required` itself would be harmful.
        $wrapper_attrs = ($required && !$native_required) ? ' aria-required="true"' : '';
        $label_marker = $required
            ? ' <span class="ntdst-required" aria-hidden="true">*</span>'
            : '';

        echo '<div class="ntdst-field"' . $wrapper_attrs . '>';
        echo '<label for="' . $field_id_attr . '">' . esc_html($label) . $label_marker . '</label>';

        // A readonly field displays as text — except where the control is
        // already its own readonly display: a disabled `select` keeps its
        // options, and a `json` area keeps its shape.
        if ($readonly && $control !== 'select' && $control !== 'json') {
            // Only `decimal` formats; every other control shows what is stored,
            // sign and all. absint() on the way in was the FR-5 bug, and a
            // display that hides the sign is the same bug with no save.
            $display = $control === 'decimal'
                ? number_format((float) $safe_value, 2)
                : $safe_value;

            echo '<div class="ntdst-readonly-value" style="padding: 8px 0; font-size: 14px;">';
            echo '<strong>' . esc_html($display) . '</strong>';
            echo '<input type="hidden" name="' . $field_name_attr . '" value="' . esc_attr($safe_value) . '">';
            echo '<p class="description">This value is automatically calculated and cannot be edited.</p>';
            echo '</div>';
            echo '</div>';

            return;
        }

        $this->render_control($control, $field_id, $field_name, $value, $config, false, $name, $native_required);

        echo '</div>';
    }

    /**
     * THE renderer — one control, keyed by the registry's `control` and by
     * nothing else (FR-7).
     *
     * There used to be two switches over TYPE NAMES: one here and one in the
     * repeater row. Two switches are two vocabularies, and they had already
     * drifted apart from the registry's and from each other — the top-level
     * one still answered the retired name `wysiwyg` and so rendered a declared
     * `html` field as a plain text input, and the row's knew `number` and
     * `integer` but not `int`, the name the vocabulary actually uses, so every
     * declared `int` cell rendered as text. Both bugs are the same bug: a
     * fallback arm that turns an unrecognised name into a text box, silently.
     *
     * So there is no fallback. An unknown control is a LogicException, because
     * it can only mean the registry grew a control this renderer has not been
     * taught — a fault to fix, never a text box to ship.
     *
     * ONE ESCAPING RULE: this method hands every arm the RAW id and name, and
     * every arm escapes what it prints. It used to escape both here and pass
     * the escaped pair down — except to the four arms that need the raw values
     * (wp_editor() cannot take an escaped id; the pickers echo the name into
     * their own attributes), so a reader had to know, per parameter, which of
     * the two a given arm had been given.
     *
     * @param string               $control    The registry entry's `control` —
     *                                       rendering intent, never a type name.
     * @param string               $field_id   The control's DOM id, raw.
     * @param string               $input_name The submitted name, raw:
     *                                       `ntdst_fields[<field>]` at top level,
     *                                       `ntdst_fields[<field>][<i>][<sub>]`
     *                                       inside a repeater row.
     * @param mixed                $value      The stored value.
     * @param array<string, mixed> $config     The field's own declaration.
     * @param bool                 $inCell     TRUE inside a repeater row: the column
     *                                       header is the label, so the control renders
     *                                       bare and in the row's own classes.
     * @param string               $data_field_name The DECLARED field key. `relation`
     *                                       and `gallery` hand it to their JS as
     *                                       `data-field-name`; it is NOT the submitted
     *                                       name, which is bracketed.
     * @param bool                 $native_required Already decided by render_field()
     *                                       against the registry entry — the browser
     *                                       constraint is only safe on a control it can
     *                                       focus. A row never carries one.
     *
     * @throws LogicException on a control this renderer does not know.
     */
    private function render_control(
        string $control,
        string $field_id,
        string $input_name,
        mixed $value,
        array $config,
        bool $inCell,
        string $data_field_name = '',
        bool $native_required = false,
    ): void {
        // Emitted on the control itself; the .ntdst-field wrapper carries the
        // aria hint where the constraint would be harmful (see render_field()).
        $attrs = $native_required ? ' required aria-required="true"' : '';

        // The three single-line string controls take the CONTROL as the input
        // type: `text`, `email` and `url` name the HTML input they are.
        $line = $inCell ? 'ntdst-repeater-input' : 'regular-text';

        match ($control) {
            'text'     => $this->control_input($control, $line, '', $field_id, $input_name, $value, $attrs),
            'email'    => $this->control_input($control, $line, '', $field_id, $input_name, $value, $attrs),
            'url'      => $this->control_input($control, $line, '', $field_id, $input_name, $value, $attrs),
            'date'     => $this->control_input('date', $inCell ? 'ntdst-repeater-date' : '', '', $field_id, $input_name, $value, $attrs),
            'number'   => $this->control_input('number', $inCell ? 'ntdst-repeater-number' : 'small-text', ' step="1"', $field_id, $input_name, $value, $attrs),
            'decimal'  => $this->control_input('number', $inCell ? 'ntdst-repeater-number' : 'small-text', ' step="0.01"', $field_id, $input_name, $value, $attrs),
            'textarea' => $this->control_textarea($field_id, $input_name, $value, $inCell, $attrs),
            'checkbox' => $this->control_checkbox($field_id, $input_name, $value, $inCell),
            'select'   => $this->control_select($field_id, $input_name, $value, $config, $inCell, $attrs),
            'html'     => $this->control_html($field_id, $input_name, $value),
            'json'     => $this->control_json($field_id, $input_name, $value, $inCell, $attrs),
            'media'    => $this->control_media($field_id, $input_name, $value, $config),
            'relation' => $this->render_relation_field($field_id, $input_name, $data_field_name, $value, $config),
            'gallery'  => $this->render_gallery_field($field_id, $input_name, $data_field_name, $value, $config),
            'repeater' => $this->render_repeater_field($field_id, $input_name, $data_field_name, $value, $config),
            default    => throw new \LogicException("Unknown control '{$control}'."),
        };
    }

    /** Every single-line input control: only the type, the class and the step differ. */
    private function control_input(
        string $input_type,
        string $class,
        string $extra,
        string $field_id,
        string $input_name,
        mixed $value,
        string $required_attrs,
    ): void {
        echo '<input type="' . $input_type . '" id="' . esc_attr($field_id) . '" name="'
            . esc_attr($input_name) . '" value="' . esc_attr($value ?? '') . '"'
            . ($class !== '' ? ' class="' . $class . '"' : '')
            . $extra . $required_attrs . '>';
    }

    private function control_textarea(string $field_id, string $input_name, mixed $value, bool $inCell, string $required_attrs): void
    {
        $rows = $inCell ? '2' : '5';
        $class = $inCell ? 'ntdst-repeater-textarea' : 'large-text';

        echo '<textarea id="' . esc_attr($field_id) . '" name="' . esc_attr($input_name) . '" rows="' . $rows
            . '" class="' . $class . '"' . $required_attrs . '>' . esc_textarea($value ?? '') . '</textarea>';
    }

    /**
     * The hidden companion input comes FIRST so the checkbox wins when ticked.
     * Without it an unticked box submits nothing at all and save_metabox_data()
     * leaves the old value standing (T56).
     *
     * `required` is never emitted here — see render_field(): on a checkbox it
     * would mean "must be ticked".
     */
    private function control_checkbox(string $field_id, string $input_name, mixed $value, bool $inCell): void
    {
        $checked = $value ? ' checked' : '';
        $id = esc_attr($field_id);
        $nm = esc_attr($input_name);

        echo '<input type="hidden" name="' . $nm . '" value="0">';

        // In a row the column header is the label, so the box renders bare.
        if ($inCell) {
            echo '<input type="checkbox" id="' . $id . '" name="' . $nm . '" value="1"' . $checked . '>';

            return;
        }

        echo '<label><input type="checkbox" id="' . $id . '" name="' . $nm . '" value="1"' . $checked . '> Yes</label>';
    }

    /** @param array<string, mixed> $config */
    private function control_select(string $field_id, string $input_name, mixed $value, array $config, bool $inCell, string $required_attrs): void
    {
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        $readonly = !empty($config['readonly']);
        $class = $inCell ? 'ntdst-repeater-select' : 'regular-text';
        $safe_value = $value ?? '';
        $id = esc_attr($field_id);
        $nm = esc_attr($input_name);

        echo '<select id="' . $id . '" name="' . $nm . '" class="' . $class . '"'
            . ($readonly ? ' disabled' : '') . $required_attrs . '>';

        foreach ($options as $opt_value => $opt_label) {
            $selected = ($safe_value == $opt_value) ? ' selected' : '';
            echo '<option value="' . esc_attr($opt_value) . '"' . $selected . '>' . esc_html($opt_label) . '</option>';
        }

        echo '</select>';

        // A disabled select submits nothing: the hidden input preserves it.
        if ($readonly) {
            echo '<input type="hidden" name="' . $nm . '" value="' . esc_attr($safe_value) . '">';
        }
    }

    /**
     * wp_editor() echoes its own markup and requires a unique editor ID that is
     * lowercase alphanumeric/underscores only (no dashes, no brackets).
     * $field_id is already "ntdst_field_{$name}" from a developer-controlled
     * field key, so sanitize_key() is defence-in-depth, not a fix for untrusted
     * input here. Both arguments are the RAW id and name — the editor escapes
     * its own output.
     */
    private function control_html(string $field_id, string $input_name, mixed $value): void
    {
        wp_editor($value ?? '', sanitize_key($field_id), [
            'textarea_name' => $input_name,
            'textarea_rows' => 10,
            'media_buttons' => false,
            'teeny'         => true,
        ]);
    }

    private function control_json(string $field_id, string $input_name, mixed $value, bool $inCell, string $required_attrs): void
    {
        $json_value = is_array($value) ? json_encode($value, JSON_PRETTY_PRINT) : ($value ?? '');
        $id = esc_attr($field_id);
        $nm = esc_attr($input_name);

        if ($inCell) {
            echo '<textarea id="' . $id . '" name="' . $nm . '" rows="2" class="ntdst-repeater-textarea"'
                . $required_attrs . '>' . esc_textarea($json_value) . '</textarea>';

            return;
        }

        echo '<div class="ntdst-field-array">';
        echo '<textarea id="' . $id . '" name="' . $nm . '" rows="8" class="large-text code"'
            . $required_attrs . '>' . esc_textarea($json_value) . '</textarea>';
        echo '<p class="description">Enter valid JSON array. Example: ["value1", "value2"]</p>';
        echo '</div>';
    }

    /**
     * Render relation field (autocomplete post/user selector)
     */
    private function render_relation_field(string $field_id, string $field_name, string $name, mixed $value, array $options): void
    {
        $post_type = $options['post_type'] ?? 'post';
        $multiple = $options['multiple'] ?? true;
        $description = $options['description'] ?? '';
        $is_user_field = ($post_type === 'user');

        // Set appropriate placeholder
        if ($is_user_field) {
            $user_role = $options['user_role'] ?? '';
            $placeholder = $options['placeholder'] ?? "Search " . ($user_role ? $user_role . 's' : 'users') . "...";
        } else {
            $placeholder = $options['placeholder'] ?? "Search {$post_type}...";
        }

        // Normalize value to array
        $selected_ids = [];
        if (!empty($value)) {
            $selected_ids = is_array($value) ? array_map('intval', $value) : [intval($value)];
        }

        // Get selected items data (posts or users)
        $selected_items = [];
        if (!empty($selected_ids)) {
            if ($is_user_field) {
                // Get users
                $user_args = [
                    'include' => $selected_ids,
                ];
                if (!empty($options['user_role'])) {
                    $user_args['role'] = $options['user_role'];
                }
                $selected_items = get_users($user_args);
            } else {
                // Get posts
                $selected_items = get_posts([
                    'post_type' => $post_type,
                    'post__in' => $selected_ids,
                    'posts_per_page' => -1,
                    'orderby' => 'post__in',
                ]);
            }
        }

        // Build data attributes
        $data_attrs = [
            'data-field-name="' . esc_attr($name) . '"',
            'data-post-type="' . esc_attr($post_type) . '"',
            'data-multiple="' . ($multiple ? '1' : '0') . '"',
        ];

        if ($is_user_field && !empty($options['user_role'])) {
            $data_attrs[] = 'data-user-role="' . esc_attr($options['user_role']) . '"';
        }

        echo '<div class="ntdst-relation-field" ' . implode(' ', $data_attrs) . '>';

        // Selected items display
        echo '<div class="ntdst-relation-selected" id="' . esc_attr($field_id) . '_selected">';
        foreach ($selected_items as $item) {
            $item_id = $item->ID;
            $item_title = $is_user_field ? $item->display_name : $item->post_title;

            echo '<span class="ntdst-relation-tag" data-id="' . esc_attr($item_id) . '">';
            echo esc_html($item_title);
            echo '<button type="button" class="ntdst-relation-remove" aria-label="Remove">&times;</button>';
            echo '<input type="hidden" name="' . esc_attr($field_name) . '[]" value="' . esc_attr($item_id) . '">';
            echo '</span>';
        }
        echo '</div>';

        // Search input
        echo '<div class="ntdst-relation-search">';
        echo '<input type="text" class="ntdst-relation-input regular-text" placeholder="' . esc_attr($placeholder) . '" autocomplete="off">';
        echo '<div class="ntdst-relation-results" style="display: none;"></div>';
        echo '</div>';

        if ($description) {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Render gallery field (image selector with drag & drop reordering)
     */
    private function render_gallery_field(string $field_id, string $field_name, string $name, mixed $value, array $options): void
    {
        static $gallery_js_loaded = false;

        // Enqueue WordPress media library
        wp_enqueue_media();

        $description = $options['description'] ?? '';
        $button_text = $options['button_text'] ?? 'Add Images';

        // Normalize value to array of attachment IDs
        $attachment_ids = [];
        if (!empty($value)) {
            $attachment_ids = is_array($value) ? array_map('intval', $value) : [intval($value)];
        }

        echo '<div class="ntdst-gallery-field" data-field-name="' . esc_attr($name) . '">';

        // Gallery preview container
        echo '<div class="ntdst-gallery-preview" id="' . esc_attr($field_id) . '_preview">';

        foreach ($attachment_ids as $attachment_id) {
            $image_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
            $image_title = get_the_title($attachment_id);
            $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            $edit_url = admin_url('post.php?post=' . $attachment_id . '&action=edit');

            if ($image_url) {
                $has_alt = !empty($alt_text);
                $item_class = 'ntdst-gallery-item' . (!$has_alt ? ' no-alt-text' : '');

                echo '<div class="' . esc_attr($item_class) . '" data-id="' . esc_attr($attachment_id) . '">';
                echo '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($image_title) . '">';
                echo '<button type="button" class="ntdst-gallery-remove" aria-label="Remove">&times;</button>';

                // Alt text indicator
                if ($has_alt) {
                    echo '<span class="alt-indicator" title="' . esc_attr($alt_text) . '">✓ Alt</span>';
                } else {
                    echo '<span class="alt-indicator missing" title="No alt text">⚠ No Alt</span>';
                }

                // Edit link
                echo '<a href="' . esc_url($edit_url) . '" class="edit-link" target="_blank" title="Edit image">✎</a>';

                echo '<input type="hidden" name="' . esc_attr($field_name) . '[]" value="' . esc_attr($attachment_id) . '">';
                echo '</div>';
            }
        }

        echo '</div>';

        // Add images button
        echo '<button type="button" class="button ntdst-gallery-add" data-field-id="' . esc_attr($field_id) . '">' . esc_html($button_text) . '</button>';

        if ($description) {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }

        echo '</div>';

        // Inline CSS (only once)
        if (!$gallery_js_loaded) {
            echo '<style>
                .ntdst-gallery-field {
                    margin-top: 8px;
                }
                .ntdst-gallery-preview {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 10px;
                    margin-bottom: 12px;
                    min-height: 40px;
                    padding: 10px;
                    background: #f9f9f9;
                    border: 1px dashed #ccc;
                    border-radius: 4px;
                }
                .ntdst-gallery-preview:empty::before {
                    content: "No images selected. Click the button below to add images.";
                    color: #999;
                    font-size: 13px;
                    font-style: italic;
                    display: block;
                    padding: 20px;
                    text-align: center;
                    width: 100%;
                }
                .ntdst-gallery-item {
                    position: relative;
                    width: 100px;
                    height: 100px;
                    background: #fff;
                    border: 2px solid #ddd;
                    border-radius: 4px;
                    overflow: hidden;
                    cursor: move;
                    transition: border-color 0.2s, box-shadow 0.2s;
                }
                .ntdst-gallery-item:hover {
                    border-color: #2271b1;
                    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
                }
                .ntdst-gallery-item.ui-sortable-helper {
                    opacity: 0.7;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
                }
                .ntdst-gallery-item.ui-sortable-placeholder {
                    border: 2px dashed #2271b1;
                    background: #e5f3ff;
                    visibility: visible !important;
                }
                .ntdst-gallery-item img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                }
                .ntdst-gallery-remove {
                    position: absolute;
                    top: 4px;
                    right: 4px;
                    background: rgba(0,0,0,0.7);
                    color: #fff;
                    border: none;
                    border-radius: 2px;
                    width: 20px;
                    height: 20px;
                    font-size: 16px;
                    line-height: 1;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 0;
                    opacity: 0;
                    transition: opacity 0.2s, background 0.2s;
                }
                .ntdst-gallery-item:hover .ntdst-gallery-remove {
                    opacity: 1;
                }
                .ntdst-gallery-remove:hover {
                    background: #d63638;
                }
                .ntdst-gallery-add {
                    margin-bottom: 8px;
                }
                .ntdst-gallery-item.no-alt-text {
                    border-color: #d63638;
                }
                .alt-indicator {
                    position: absolute;
                    bottom: 4px;
                    left: 4px;
                    background: rgba(22, 163, 74, 0.9);
                    color: #fff;
                    font-size: 10px;
                    font-weight: 600;
                    padding: 2px 6px;
                    border-radius: 3px;
                    line-height: 1.4;
                    white-space: nowrap;
                    pointer-events: none;
                    transition: opacity 0.2s;
                }
                .alt-indicator.missing {
                    background: rgba(234, 88, 12, 0.9);
                }
                .edit-link {
                    position: absolute;
                    top: 4px;
                    left: 4px;
                    background: rgba(0,0,0,0.7);
                    color: #fff;
                    text-decoration: none;
                    font-size: 14px;
                    width: 20px;
                    height: 20px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    border-radius: 2px;
                    opacity: 0;
                    transition: opacity 0.2s, background 0.2s;
                }
                .ntdst-gallery-item:hover .edit-link {
                    opacity: 1;
                }
                .edit-link:hover {
                    background: #2271b1;
                    color: #fff;
                }
            </style>';

            $gallery_js_loaded = true;
        }
    }

    /**
     * Render repeater field (multi-row data with sub-fields)
     */
    private function render_repeater_field(string $field_id, string $field_name, string $name, mixed $value, array $options): void
    {
        static $repeater_styles_rendered = false;

        $description = $options['description'] ?? '';
        $sub_fields = $options['sub_fields'] ?? [];
        $button_text = $options['button_text'] ?? 'Add Row';
        $min_rows = $options['min_rows'] ?? 0;
        $max_rows = $options['max_rows'] ?? null;

        // Normalize value to array of rows
        $rows = [];
        if (!empty($value) && is_array($value)) {
            $rows = $value;
        }

        // Ensure minimum rows
        while (count($rows) < $min_rows) {
            $rows[] = [];
        }

        // The media picker's assets are emitted ONCE per request, by the first
        // `media` control that renders — and on a repeater with no rows yet
        // that control is the one inside the hidden ROW TEMPLATE. A
        // `<script type="text/html">` block ends at the first `</script>` the
        // parser sees, wherever it came from: the template closed on the
        // picker's own script tag, the rest of the row markup spilled onto the
        // visible page, and "Add Row" cloned a truncated row. So a repeater
        // that HAS a media cell emits them here, ahead of its own markup.
        foreach ($sub_fields as $sub_field_type) {
            if (NTDST_FieldTypes::get(NTDST_FieldTypes::declaredType($sub_field_type))->control === 'media') {
                $this->render_media_picker_assets();

                break;
            }
        }

        // No `data-field-name` here: the repeater's JS finds its rows and its
        // template through `data-field-id`, and the row inputs carry the
        // submitted name themselves. `relation` and `gallery` keep theirs —
        // their JS reads them to build the inputs it appends.
        echo '<div class="ntdst-repeater-field" data-field-id="' . esc_attr($field_id) . '" data-max-rows="' . esc_attr($max_rows ?? '') . '">';

        if ($description) {
            echo '<p class="description" style="margin-top: 0;">' . esc_html($description) . '</p>';
        }

        // Table with header
        echo '<table class="ntdst-repeater-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th class="ntdst-repeater-handle-header"></th>'; // Drag handle column

        // Sub-field headers
        foreach ($sub_fields as $sub_field_name => $sub_field_type) {
            $label = is_array($sub_field_type) ? ($sub_field_type['label'] ?? ucwords(str_replace('_', ' ', $sub_field_name))) : ucwords(str_replace('_', ' ', $sub_field_name));
            echo '<th>' . esc_html($label) . '</th>';
        }

        echo '<th class="ntdst-repeater-actions-header"></th>'; // Remove button column
        echo '</tr>';
        echo '</thead>';

        // Rows container (tbody)
        echo '<tbody class="ntdst-repeater-rows" id="' . esc_attr($field_id) . '_rows">';

        foreach ($rows as $row_index => $row_data) {
            $this->render_repeater_row($field_name, $name, $row_index, $row_data, $sub_fields);
        }

        echo '</tbody>';
        echo '</table>';

        // Add row button
        echo '<button type="button" class="button ntdst-repeater-add">' . esc_html($button_text) . '</button>';

        // Row template (hidden, used by JavaScript)
        echo '<script type="text/html" id="' . esc_attr($field_id) . '_template">';
        $this->render_repeater_row($field_name, $name, '__INDEX__', [], $sub_fields);
        echo '</script>';

        echo '</div>';

        // Inline CSS, once per request. The add/remove/sort BEHAVIOUR is
        // assets/js/metabox-fields.js's — enqueued by enqueue_metabox_scripts()
        // on every post.php/post-new.php screen of a registered model, which is
        // every screen that can render a repeater. This block used to ship a
        // second copy of those handlers, delegated on `document` exactly as the
        // asset delegates them, so both fired: one click on "Add Row" appended
        // TWO rows and one click on "Remove" ran the asset's confirm() beside
        // this copy's fadeOut().
        if (!$repeater_styles_rendered) {
            echo '<style>
                .ntdst-repeater-field {
                    margin-top: 8px;
                }
                .ntdst-repeater-table {
                    width: 100%;
                    max-width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 12px;
                    background: #fff;
                    border: 1px solid #ddd;
                }
                .ntdst-repeater-table thead th {
                    background: #f9f9f9;
                    padding: 10px;
                    text-align: left;
                    font-weight: 600;
                    font-size: 12px;
                    border-bottom: 2px solid #ddd;
                    white-space: nowrap;
                }
                .ntdst-repeater-handle-header {
                    width: 30px !important;
                }
                .ntdst-repeater-actions-header {
                    width: 40px !important;
                }
                .ntdst-repeater-table tbody tr {
                    border-bottom: 1px solid #eee;
                }
                .ntdst-repeater-table tbody tr:hover {
                    background: #fafafa;
                }
                .ntdst-repeater-table td {
                    padding: 8px 10px;
                    vertical-align: middle;
                }
                .ntdst-repeater-handle {
                    width: 30px;
                    text-align: center;
                    cursor: move;
                }
                .ntdst-repeater-drag-handle {
                    color: #999;
                    font-size: 18px;
                    line-height: 1;
                    cursor: move;
                    user-select: none;
                    display: inline-block;
                }
                .ntdst-repeater-drag-handle:hover {
                    color: #2271b1;
                }
                .ntdst-repeater-actions {
                    width: 40px;
                    text-align: center;
                }
                .ntdst-repeater-remove {
                    background: transparent;
                    color: #dc3232;
                    border: none;
                    padding: 4px 8px;
                    cursor: pointer;
                    font-size: 20px;
                    line-height: 1;
                    border-radius: 3px;
                }
                .ntdst-repeater-remove:hover {
                    background: #dc3232;
                    color: #fff;
                }
                .ntdst-repeater-input,
                .ntdst-repeater-textarea,
                .ntdst-repeater-select,
                .ntdst-repeater-number,
                .ntdst-repeater-date {
                    width: 100%;
                    padding: 6px 8px;
                    border: 1px solid #ddd;
                    border-radius: 3px;
                    font-size: 13px;
                }
                .ntdst-repeater-textarea {
                    resize: vertical;
                    font-family: inherit;
                }
                .ntdst-repeater-number {
                    max-width: 100px;
                }
                .ntdst-repeater-date {
                    max-width: 150px;
                }
                .ntdst-repeater-rows:empty::after {
                    content: "No rows added yet. Click the button below to add a row.";
                    display: block;
                    padding: 20px;
                    text-align: center;
                    color: #999;
                    font-style: italic;
                    width: max-content;
                }
                .ntdst-repeater-row.ui-sortable-helper {
                    display: table;
                    width: 100%;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                    background: #fff;
                }
                .ntdst-repeater-row.ui-sortable-placeholder {
                    background: #f0f6fc;
                    border: 2px dashed #2271b1;
                    visibility: visible !important;
                    height: 50px;
                }
            </style>';

            $repeater_styles_rendered = true;
        }
    }

    /**
     * Render a single repeater row (table row format).
     *
     * Every cell goes through render_control() — the SAME renderer the
     * top-level field uses, in cell mode. The row had its own switch over type
     * names until T06; it knew `number` and `integer`, two names v5.0.0
     * retired, and not `int`, the name the vocabulary uses, so every declared
     * `int` cell fell through to a text input.
     *
     * @param array<string, mixed> $row_data
     * @param array<string, mixed> $sub_fields
     */
    private function render_repeater_row(string $field_name, string $name, mixed $row_index, array $row_data, array $sub_fields): void
    {
        echo '<tr class="ntdst-repeater-row" data-index="' . esc_attr($row_index) . '">';

        // Drag handle column
        echo '<td class="ntdst-repeater-handle">';
        echo '<span class="ntdst-repeater-drag-handle" title="Drag to reorder">⋮⋮</span>';
        echo '</td>';

        // Sub-field columns
        foreach ($sub_fields as $sub_field_name => $sub_field_type) {
            $sub_field_value = $row_data[$sub_field_name] ?? '';
            $sub_field_id = "ntdst_field_{$name}_{$row_index}_{$sub_field_name}";
            $sub_field_full_name = "{$field_name}[{$row_index}][{$sub_field_name}]";

            // The same normalisation render_field() does: a bare string is a
            // type and nothing else, and the vocabulary answers which.
            $config = is_array($sub_field_type) ? $sub_field_type : ['type' => $sub_field_type];
            $sub_type = NTDST_FieldTypes::declaredType($sub_field_type);
            $entry = NTDST_FieldTypes::get($sub_type);

            // `cell = false` is the vocabulary's RENDERING verdict: this cell
            // cannot be drawn in a table row. register() refuses the
            // declaration outright, so reaching here means one was injected
            // past it — a `fields` filter, a cached registration — and the row
            // must fault rather than draw a single-line box holding markup.
            if (!$entry->cell) {
                throw new \LogicException(
                    "Field '{$name}' sub-field '{$sub_field_name}': '{$sub_type}' cannot be drawn "
                    . 'in a repeater row — it has no cell control.',
                );
            }

            echo '<td>';

            // No label and no `required`: the column header is the label, and a
            // constraint inside a row that JavaScript clones would be cloned
            // with it.
            $this->render_control(
                $entry->control,
                $sub_field_id,
                $sub_field_full_name,
                $sub_field_value,
                $config,
                true,
                $sub_field_name,
            );

            echo '</td>';
        }

        // Remove button column
        echo '<td class="ntdst-repeater-actions">';
        echo '<button type="button" class="ntdst-repeater-remove" title="Remove row">×</button>';
        echo '</td>';

        echo '</tr>';
    }

    /**
     * The `media` control — one single-attachment picker, row or top level.
     *
     * Same mechanism as render_gallery_field() — wp_enqueue_media() plus a
     * wp.media frame — but single-valued: the field holds ONE attachment, so
     * there is no ordered set and no drag-and-drop. There is no $inCell branch
     * because there is nothing to branch on: a picker in a table cell and a
     * picker under a label are the same widget, and the delegated handlers in
     * render_media_picker_assets() serve both.
     *
     * `image` versus `file` is the DECLARATION's business, not the control's:
     * both declare the `media` control and differ only in what the picker
     * offers and what its button says. The registry has no third answer to
     * read here, so the declared type is what decides — and the browser JS
     * reads it back off `data-media-type` to scope the wp.media library.
     *
     * Storage is deliberately untouched by this render: a top-level
     * `image`/`file` field still submits the bare attachment id under the
     * field's own name, unlike a repeater cell's empty-string marker —
     * ProfileService's four fields already hold live values in the int shape
     * on every ntdst site.
     *
     * @param array<string, mixed> $config The field's own declaration.
     */
    private function control_media(string $field_id, string $input_name, mixed $value, array $config): void
    {
        wp_enqueue_media();
        $this->render_media_picker_assets();

        $id = esc_attr($field_id);
        $nm = esc_attr($input_name);
        $attachment_id = absint($value);
        $is_image = (($config['type'] ?? '') === 'image');
        $is_attachment = $attachment_id > 0 && get_post_type($attachment_id) === 'attachment';
        $button_text = $config['button_text'] ?? ($is_image ? 'Select Image' : 'Select File');

        echo '<div class="ntdst-repeater-media" data-media-type="' . ($is_image ? 'image' : 'file') . '">';
        echo '<input type="hidden" id="' . $id . '" name="' . $nm . '" value="' . esc_attr($is_attachment ? (string) $attachment_id : '') . '" class="ntdst-repeater-media-input">';

        echo '<div class="ntdst-repeater-media-preview">';
        if ($is_attachment) {
            $thumbnail = $is_image ? wp_get_attachment_image_url($attachment_id, 'thumbnail') : '';

            if ($thumbnail) {
                echo '<img src="' . esc_url($thumbnail) . '" alt="">';
            }

            echo '<span class="ntdst-repeater-media-name">' . esc_html(get_the_title($attachment_id)) . '</span>';
        }
        echo '</div>';

        echo '<button type="button" class="button ntdst-repeater-media-select">' . esc_html($button_text) . '</button>';
        echo '<button type="button" class="button-link ntdst-repeater-media-clear"' . ($is_attachment ? '' : ' hidden') . '>Remove</button>';
        echo '</div>';
    }

    /**
     * The picker's own CSS and delegated click handlers, once per request.
     *
     * Emitted from the cell rather than from render_repeater_field(), where T45
     * put it: ProfileService declares four `image`/`file` fields and no repeater
     * at all, so a screen can carry pickers with no repeater block to inherit
     * the handlers from. Delegated on `document`, so rows cloned from a
     * repeater's hidden row template are wired without re-binding.
     */
    private function render_media_picker_assets(): void
    {
        static $assets_rendered = false;

        if ($assets_rendered) {
            return;
        }

        $assets_rendered = true;

        echo '<style>
            .ntdst-repeater-media {
                display: flex;
                align-items: center;
                gap: 8px;
                white-space: nowrap;
            }
            .ntdst-repeater-media-preview {
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .ntdst-repeater-media-preview img {
                width: 40px;
                height: 40px;
                object-fit: cover;
                border: 1px solid #ddd;
                border-radius: 3px;
                display: block;
            }
            .ntdst-repeater-media-name {
                font-size: 12px;
                color: #50575e;
                max-width: 160px;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .ntdst-repeater-media-clear[hidden] {
                display: none;
            }
        </style>';

        echo '<script>
        jQuery(document).ready(function($) {
            $(document).on("click", ".ntdst-repeater-media-select", function(e) {
                e.preventDefault();

                var $cell = $(this).closest(".ntdst-repeater-media");
                var mediaType = $cell.data("media-type");
                var frame = wp.media({
                    title: mediaType === "image" ? "Select Image" : "Select File",
                    button: { text: "Use this file" },
                    multiple: false,
                    library: mediaType === "image" ? { type: "image" } : {}
                });

                frame.on("select", function() {
                    var attachment = frame.state().get("selection").first().toJSON();
                    var thumbnail = (attachment.sizes && attachment.sizes.thumbnail)
                        ? attachment.sizes.thumbnail.url
                        : "";

                    $cell.find(".ntdst-repeater-media-input").val(attachment.id);

                    var $preview = $cell.find(".ntdst-repeater-media-preview").empty();
                    if (mediaType === "image" && thumbnail) {
                        $preview.append($("<img>").attr({ src: thumbnail, alt: "" }));
                    }
                    // .text(), not string concatenation: an attachment title is
                    // user-supplied content.
                    $preview.append($("<span>").addClass("ntdst-repeater-media-name").text(attachment.title));

                    $cell.find(".ntdst-repeater-media-clear").prop("hidden", false);
                });

                frame.open();
            });

            $(document).on("click", ".ntdst-repeater-media-clear", function(e) {
                e.preventDefault();

                var $cell = $(this).closest(".ntdst-repeater-media");
                $cell.find(".ntdst-repeater-media-input").val("");
                $cell.find(".ntdst-repeater-media-preview").empty();
                $(this).prop("hidden", true);
            });
        });
        </script>';
    }

    /**
     * The action a post's nonce is minted for and verified against.
     *
     * It carries the POST ID because a nonce is the post's, not the post type's:
     * one token per model was a general-purpose "save any gig" credential that
     * travelled in the page of anything its holder could already edit.
     *
     * The FIELD NAME stays per model. The browser has to post a name the save
     * path can look up before it knows which action to verify, and the post id
     * is already in $post_id — putting it in the name too buys nothing and
     * breaks every form that posts the name this class has always emitted.
     */
    private function nonce_action(string $model_name, int $post_id): string
    {
        return "ntdst_save_{$model_name}_{$post_id}";
    }

    /**
     * Save an edit screen: the DECLARED fields of it, and nothing else.
     *
     * THE DECLARATION IS THE ALLOW-LIST. `$_POST['ntdst_fields']` was walked
     * verbatim, so the browser chose which keys the save wrote: on a Data model
     * those keys reach update(), which maps `post_status`, `post_author` and
     * `post_parent` onto wp_posts COLUMNS — a contributor could publish their
     * own draft with one extra input — and on a plain post type they became
     * meta rows, `_thumbnail_id` among them. array_intersect_key() against the
     * declaration is what closes that: it is the only list on this screen the
     * SITE wrote.
     *
     * ABSENT MEANS CLEARED, for every control built out of the inputs it emits.
     * `relation`, `gallery` and `repeater` post one input per picked item and,
     * emptied, only their container — so a screen with nothing but emptied
     * pickers posts a nonce and no `ntdst_fields` at all. The old early return
     * on an empty POST made that one save the one save that did nothing. The
     * nonce already proved the form was submitted; that is what a submission is.
     *
     * WHICH fields those are is the REGISTRY's answer — the entry's `control`,
     * never a type name matched here (INV-8). Asked of the raw declared name, a
     * retired alias ('person', which v5.0.0 folded into 'relation') simply did
     * not match, and the emptied picker was silently left standing while the
     * rest of the screen saved around it.
     *
     * NOTHING IS WRITTEN UNTIL EVERY FIELD HAS RESOLVED. The vocabulary refuses
     * a name outside the seventeen, so a declaration a `fields` filter or an
     * older cached registration slipped past register() throws while it is
     * being resolved. Outside the try that is a white-screened post that loses
     * every other field on it; written as it goes, it is a half-saved post
     * beside a "Saving failed" notice. Resolve all, then write all, or write
     * nothing and tell the editor.
     */
    public function save_metabox_data(int $post_id, \WP_Post $post): void
    {
        $model_name = $post->post_type;

        if (!isset($this->registered_models[$model_name])) {
            return;
        }

        $nonce_name = "ntdst_{$model_name}_nonce";

        if (!isset($_POST[$nonce_name])) {
            return;
        }

        if (!wp_verify_nonce(wp_unslash($_POST[$nonce_name]), $this->nonce_action($model_name, $post_id))) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $fields_config = $this->registered_models[$model_name]['fields'] ?? [];
        $fields_config = is_array($fields_config) ? $fields_config : [];

        // The boundary, crossed once: unslashed here and nowhere else, and
        // narrowed to the declared set before a single field is looked at. A
        // non-array `ntdst_fields` (`?ntdst_fields=x`) is a submission with a
        // hostile shape, not a reason to skip the clearing rule below.
        $submitted = $_POST['ntdst_fields'] ?? [];
        $posted = is_array($submitted)
            ? array_intersect_key(wp_unslash($submitted), $fields_config)
            : [];

        // Prevent infinite loops - remove this hook temporarily
        remove_action('save_post', [$this, 'save_metabox_data'], 10);

        try {
            $is_data_model = $this->isDataModel($model_name);
            $values = [];

            foreach ($posted as $field_name => $field_value) {
                $declaration = $fields_config[$field_name];
                $type = NTDST_FieldTypes::declaredType($declaration);

                // A `callback` field renders ITSELF and the consumer's own code
                // owns whatever it stores — a render directive, not a
                // vocabulary entry. The render side has always answered it
                // before asking the registry; the save side did not, so one
                // posted `callback` field threw and killed the whole screen.
                if ($type === 'callback') {
                    continue;
                }

                if ($is_data_model) {
                    // Hand the model the posted value UNCLEANED. The model's
                    // own registry-bound sanitizer runs inside update() and is
                    // the one and only clean (INV-8): a value cleaned here and
                    // cleaned again there is a value cleaned by two tables that
                    // can disagree. Idempotent functions hide the disagreement
                    // — sanitize_text_field() twice reads exactly like once —
                    // so it only ever surfaced on the types where it costs: an
                    // int that lost its sign to absint(), a bool that read the
                    // string 'false' as true.
                    $values[$field_name] = $field_value;

                    continue;
                }

                // No model here, so no model sanitizer: the registry is asked
                // directly, exactly once per submitted field. The full config
                // rides along because a `repeater` reads its own sub_fields out
                // of it and resolves every cell through this same table — there
                // is no second, hand-rolled row walk.
                $values[$field_name] = (NTDST_FieldTypes::get($type)->sanitize)(
                    $field_value,
                    is_array($declaration) ? $declaration : [],
                );
            }

            // A picker the editor emptied posts nothing at all: absent is how
            // the screen says "cleared".
            foreach ($fields_config as $field_name => $declaration) {
                if (array_key_exists($field_name, $values)) {
                    continue;
                }

                $type = NTDST_FieldTypes::declaredType($declaration);

                if ($type === 'callback') {
                    continue;
                }

                // Resolved for EVERY declared field, submitted or not: a
                // declaration this path cannot resolve stops the save here
                // rather than being skipped, which is how a cleared picker
                // comes back.
                $control = NTDST_FieldTypes::get($type)->control;

                if (in_array($control, ['relation', 'gallery', 'repeater'], true)) {
                    $values[$field_name] = [];
                }
            }

            if ($is_data_model) {
                $model = ntdst_data()->get($model_name);
                // 'any': the edit screen edits whatever status the row has.
                $existing = $model->find($post_id, 'any');

                if (!$existing || is_wp_error($existing)) {
                    // "This row is not mine" is a REFUSAL, never a create. The
                    // old branch fell through to create(), which cannot honour
                    // a post_id, so a save of a row the model could not see
                    // forked a second published post and said "saved".
                    $this->record_save_error($post_id, $model_name, new \WP_Error(
                        'metabox_save_no_row',
                        'Saving failed — see logs.',
                        "No existing {$model_name} row to update for post {$post_id}.",
                    ));

                    return;
                }

                // update() RETURNS WP_Error on a failed validate/persist
                // (validation_failed, meta_update_failed status 500). The catch
                // below only traps \Throwable, so a WP_Error RETURN was
                // captured and discarded — a silent "saved".
                $result = $model->update($post_id, $values);

                if (is_wp_error($result)) {
                    $this->record_save_error($post_id, $model_name, $result);

                    return;
                }
            } else {
                foreach ($values as $field_name => $value) {
                    // The key a posted field is stored under is WordPress's own
                    // answer, not the browser's string.
                    $meta_key = sanitize_key($field_name);

                    // An empty list is a CLEARED field: deleting is cleaner
                    // than storing a serialized empty array.
                    if (is_array($value) && $value === []) {
                        delete_post_meta($post_id, $meta_key);

                        continue;
                    }

                    update_post_meta($post_id, $meta_key, $value);
                }
            }

            // ONE hook, after both branches, on a genuine save only. On the Data
            // branch the payload is what was POSTED (unslashed, uncleaned) — the
            // model cleaned what it STORED. A listener reading this is reading
            // raw input; that is a documented 5.0.0 BREAKING row.
            do_action("ntdst/metabox_saved/{$model_name}", $post_id, $values);
        } catch (\Throwable $e) {
            // Converge on the same surfacing channel as a WP_Error RETURN
            // above: record_save_error() both sets the post-scoped transient
            // (so the editor sees the failure instead of a silent "saved")
            // and logs it — no separate inline log here, or the failure would
            // be recorded twice. The editor-facing message is GENERIC: a
            // refused type name and a DB-layer throw both carry detail
            // (table/column names, the whole vocabulary) that does not belong
            // on an edit screen. The raw exception text rides in the
            // WP_Error's data slot, which record_save_error() logs but never
            // surfaces.
            $this->record_save_error(
                $post_id,
                $model_name,
                new \WP_Error(
                    'metabox_save_exception',
                    'Saving failed — see logs.',
                    $e->getMessage(),
                ),
            );
        } finally {
            // Re-added on EVERY way out of the block above, the two refusal
            // returns included: a save that left this method with the hook
            // still detached would silently stop saving every later post in
            // the request.
            add_action('save_post', [$this, 'save_metabox_data'], 10, 2);
        }
    }

    /**
     * Surface a failed ORM save to the editor. Writes a post-scoped transient
     * (the contract channel — read once on the next admin request by
     * render_save_error_notice()) and logs it. Both failure modes converge
     * here: a WP_Error RETURN from update()/create() and a \Throwable from
     * the same calls (wrapped in a WP_Error by the catch). Only the error's
     * MESSAGE is editor-facing; string error data, when present, is detail
     * for the log alone (the catch parks the raw exception text there).
     *
     * No same-request settings-error is registered: this runs inside save_post,
     * and the redirect WordPress issues immediately afterwards discards any
     * notice queued for the current request before anything can render it. The
     * transient is what actually carries the message across that redirect.
     */
    private function record_save_error(int $post_id, string $model_name, \WP_Error $error): void
    {
        $message = $error->get_error_message();

        set_transient(self::SAVE_ERROR_TRANSIENT_PREFIX . $post_id, $message, MINUTE_IN_SECONDS * 5);

        if (function_exists('ntdst_log')) {
            $context = ['code' => $error->get_error_code()];

            $detail = $error->get_error_data();
            if (is_string($detail) && $detail !== '') {
                $context['detail'] = $detail;
            }

            ntdst_log('metabox')->error(
                "Save failed for {$model_name} (post {$post_id}): {$message}",
                $context,
            );
        }
    }

    /**
     * Render (and delete — read-once) a failed-save error for the post currently
     * on the edit screen. Hooked to admin_notices; the transient set by
     * record_save_error() carries the message across the save_post redirect.
     *
     * Authorized on `edit_post` for the target post, mirroring the same check
     * save_metabox_data() already makes on the write side: the message is the
     * edit screen's own, so whoever may edit that post may read it — and nobody
     * else. The post id comes from an unauthenticated `$_GET`, so without this
     * the notice rendered for any logged-in user who loaded `?post=<id>` inside
     * the 5-minute window.
     */
    public function render_save_error_notice(): void
    {
        $post_id = 0;
        if (isset($_GET['post'])) {
            $post_id = absint(wp_unslash($_GET['post'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only, read-once notice keyed to the current edit screen.
        } elseif (isset($GLOBALS['post']) && $GLOBALS['post'] instanceof \WP_Post) {
            $post_id = (int) $GLOBALS['post']->ID;
        }

        if ($post_id <= 0) {
            return;
        }

        // Authorize BEFORE the read, not after it. The read below is DESTRUCTIVE
        // (delete_transient — the notice is read-once), so a gate placed after
        // get_transient() would still consume the message: an unauthorized viewer
        // would render nothing while silently destroying the notice, and the
        // editor who actually needs it would never learn their save failed.
        // Placed after the `$post_id <= 0` guard so no meta-cap is resolved for
        // an id that was already rejected.
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $key = self::SAVE_ERROR_TRANSIENT_PREFIX . $post_id;
        $message = get_transient($key);

        if ($message === false || $message === '') {
            return;
        }

        delete_transient($key);

        printf(
            '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
            esc_html((string) $message),
        );
    }

    /**
     * Check if this model has a Data-layer schema (ORM-backed).
     *
     * Delegates to NTDST_Data_Manager::isRegistered(), which checks the
     * ORM's own registry without auto-creating a phantom empty model as a
     * side effect (unlike NTDST_Data_Manager::get()). This class's own
     * $registered_models only tracks metabox field/render config — a model
     * can be registered there (e.g. a test isolating the metabox generator
     * from a real CPT service, or any future caller of this class's own
     * register()) without ever being a Data-ORM model, so checking for a
     * non-empty 'fields' key here was a false positive: it routed native
     * post types with no ORM schema into the ORM save branch, where
     * NTDST_Data_Manager::get() auto-registered a phantom empty-schema
     * model and silently dropped every submitted field
     * (see NTDST_Data_Model::warnUnregisteredKeys()).
     */
    private function isDataModel(string $model_name): bool
    {
        return function_exists('ntdst_data') && ntdst_data()->isRegistered($model_name);
    }
}

/**
 * Global helper - get metabox generator instance
 */
if (!function_exists('ntdst_metabox')) {
    function ntdst_metabox(): NTDST_MetaboxGenerator
    {
        return NTDST_MetaboxGenerator::instance();
    }
}
