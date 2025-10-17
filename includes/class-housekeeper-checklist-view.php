<?php
/**
 * File: class-housekeeper-checklist-view.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-housekeeper-checklist-view.php
 *
 * Renders the public-facing housekeeper checklist and handles submissions.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GMS_Housekeeper_Checklist_View {

    const REST_NAMESPACE = 'gms/v1';
    const REST_ROUTE = '/housekeeper-checklists';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'handle_submission'),
                'permission_callback' => '__return_true',
                'args' => array(
                    'token' => array(
                        'required' => true,
                        'type' => 'string',
                    ),
                ),
            )
        );
    }

    public static function enqueueAssets($token) {
        $token = sanitize_text_field((string) $token);

        if ($token === '') {
            return;
        }

        $reservation = GMS_Database::getReservationByHousekeeperToken($token);

        if (!$reservation) {
            return;
        }

        $script_handle = 'gms-housekeeper-checklist';
        $style_handle = 'gms-housekeeper-checklist';

        $script_path = GMS_PLUGIN_PATH . 'assets/js/housekeeper-checklist.js';
        $style_path = GMS_PLUGIN_PATH . 'assets/css/housekeeper-checklist.css';

        $script_version = file_exists($script_path) ? filemtime($script_path) : GMS_VERSION;
        $style_version = file_exists($style_path) ? filemtime($style_path) : GMS_VERSION;

        wp_enqueue_style($style_handle, GMS_PLUGIN_URL . 'assets/css/housekeeper-checklist.css', array(), $style_version);
        wp_enqueue_script($script_handle, GMS_PLUGIN_URL . 'assets/js/housekeeper-checklist.js', array('jquery'), $script_version, true);

        $blueprint = self::getBlueprint();
        $payload = array(
            'token' => $token,
            'restUrl' => esc_url_raw(rest_url(self::REST_NAMESPACE . self::REST_ROUTE)),
            'restNonce' => wp_create_nonce('wp_rest'),
            'submitNonce' => wp_create_nonce(self::getNonceAction($token)),
            'structure' => $blueprint,
            'i18n' => array(
                'next' => __('Next', 'guest-management-system'),
                'previous' => __('Previous', 'guest-management-system'),
                'submit' => __('Submit checklist', 'guest-management-system'),
                'saving' => __('Submitting…', 'guest-management-system'),
                'success' => __('Checklist submitted successfully.', 'guest-management-system'),
                'photoRequirement' => __('Please provide all required photos before submitting.', 'guest-management-system'),
                'taskRequirement' => __('Please complete all required tasks before submitting.', 'guest-management-system'),
            ),
        );

        wp_localize_script($script_handle, 'gmsHousekeeperChecklist', $payload);
    }

    public static function displayChecklist($token) {
        $token = sanitize_text_field((string) $token);

        if ($token === '') {
            self::renderError(__('This checklist link is invalid or has expired.', 'guest-management-system'));
            return;
        }

        $reservation = GMS_Database::getReservationByHousekeeperToken($token);

        if (!$reservation) {
            self::renderError(__('This checklist link is invalid or has expired.', 'guest-management-system'));
            return;
        }

        $blueprint = self::getBlueprint();
        $phase_count = count($blueprint['phases']);
        $company_name = get_option('gms_company_name', get_option('blogname'));
        $property_name = isset($reservation['property_name']) ? $reservation['property_name'] : '';
        $checkin = isset($reservation['checkin_date']) ? $reservation['checkin_date'] : '';
        $checkout = isset($reservation['checkout_date']) ? $reservation['checkout_date'] : '';
        $checkin_display = $checkin ? date_i18n(get_option('date_format', 'F j, Y'), strtotime($checkin)) : '';
        $checkout_display = $checkout ? date_i18n(get_option('date_format', 'F j, Y'), strtotime($checkout)) : '';
        $guest_name = isset($reservation['guest_name']) ? $reservation['guest_name'] : '';
        $form_id = 'gms-housekeeper-checklist-form';

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html(sprintf(__('Housekeeper Checklist · %s', 'guest-management-system'), $property_name)); ?></title>
    <?php wp_head(); ?>
</head>
<body class="gms-housekeeper-page">
    <main class="gms-housekeeper-layout">
        <header class="gms-housekeeper-header">
            <div class="gms-housekeeper-header__meta">
                <span class="gms-housekeeper-header__label"><?php echo esc_html($company_name); ?></span>
                <h1 class="gms-housekeeper-header__title"><?php echo esc_html(__('Housekeeper Checklist', 'guest-management-system')); ?></h1>
            </div>
            <div class="gms-housekeeper-header__details">
                <?php if ($property_name !== '') : ?>
                    <p class="gms-housekeeper-header__property"><?php echo esc_html($property_name); ?></p>
                <?php endif; ?>
                <ul class="gms-housekeeper-header__list">
                    <?php if ($guest_name !== '') : ?>
                        <li>
                            <span class="gms-housekeeper-header__list-label"><?php esc_html_e('Guest', 'guest-management-system'); ?></span>
                            <span class="gms-housekeeper-header__list-value"><?php echo esc_html($guest_name); ?></span>
                        </li>
                    <?php endif; ?>
                    <?php if ($checkin_display !== '') : ?>
                        <li>
                            <span class="gms-housekeeper-header__list-label"><?php esc_html_e('Check-in', 'guest-management-system'); ?></span>
                            <span class="gms-housekeeper-header__list-value"><?php echo esc_html($checkin_display); ?></span>
                        </li>
                    <?php endif; ?>
                    <?php if ($checkout_display !== '') : ?>
                        <li>
                            <span class="gms-housekeeper-header__list-label"><?php esc_html_e('Check-out', 'guest-management-system'); ?></span>
                            <span class="gms-housekeeper-header__list-value"><?php echo esc_html($checkout_display); ?></span>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </header>

        <section class="gms-housekeeper-status" aria-live="polite"></section>

        <form id="<?php echo esc_attr($form_id); ?>" class="gms-housekeeper-form" method="post" enctype="multipart/form-data" data-phase-count="<?php echo esc_attr($phase_count); ?>">
            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
            <input type="hidden" name="submit_nonce" value="<?php echo esc_attr(wp_create_nonce(self::getNonceAction($token))); ?>">
            <?php self::renderPhases($blueprint); ?>
            <div class="gms-housekeeper-form__actions">
                <button type="button" class="gms-housekeeper-button gms-housekeeper-button--secondary" data-action="previous">
                    <?php esc_html_e('Previous', 'guest-management-system'); ?>
                </button>
                <button type="button" class="gms-housekeeper-button gms-housekeeper-button--primary" data-action="next">
                    <?php esc_html_e('Next', 'guest-management-system'); ?>
                </button>
                <button type="submit" class="gms-housekeeper-button gms-housekeeper-button--primary" data-action="submit">
                    <?php esc_html_e('Submit checklist', 'guest-management-system'); ?>
                </button>
            </div>
        </form>
    </main>
    <?php wp_footer(); ?>
</body>
</html>
<?php
    }

    public static function handle_submission(WP_REST_Request $request) {
        $token = sanitize_text_field((string) $request->get_param('token'));
        $nonce = sanitize_text_field((string) $request->get_param('submit_nonce'));

        if ($token === '' || !wp_verify_nonce($nonce, self::getNonceAction($token))) {
            return new WP_Error('gms_housekeeper_invalid_token', __('The submission token is invalid or expired.', 'guest-management-system'), array('status' => 403));
        }

        $reservation = GMS_Database::getReservationByHousekeeperToken($token);

        if (!$reservation) {
            return new WP_Error('gms_housekeeper_not_found', __('The reservation associated with this checklist could not be located.', 'guest-management-system'), array('status' => 404));
        }

        $blueprint = self::getBlueprint();
        $tasks_input = (array) $request->get_param('tasks');
        $notes_input = (array) $request->get_param('notes');
        $inventory_notes = '';

        $validation = self::validateTasks($tasks_input, $notes_input, $blueprint);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $required_photos = self::getRequiredPhotoSlots($blueprint);
        $file_params = $request->get_file_params();
        $photos = isset($file_params['photos']) ? $file_params['photos'] : array();

        $processed_photos = self::processPhotos($photos, $required_photos, $reservation['id']);
        if (is_wp_error($processed_photos)) {
            return $processed_photos;
        }

        if (isset($notes_input['inventory_notes'])) {
            $inventory_notes = wp_kses_post($notes_input['inventory_notes']);
        }

        $payload = array(
            'tasks' => $validation['tasks'],
            'notes' => $validation['notes'],
            'submitted_by' => array(
                'ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
            ),
        );

        $submission_id = GMS_Database::createHousekeeperSubmission(array(
            'reservation_id' => $reservation['id'],
            'housekeeper_token' => $token,
            'status' => 'submitted',
            'payload' => $payload,
            'inventory_notes' => $inventory_notes,
        ));

        if (!$submission_id) {
            self::cleanupUploadedAttachments($processed_photos);
            return new WP_Error('gms_housekeeper_persist_error', __('Unable to record the checklist submission. Please try again.', 'guest-management-system'), array('status' => 500));
        }

        foreach ($processed_photos as $photo) {
            $photo_created = GMS_Database::addHousekeeperPhoto(array(
                'reservation_id' => $reservation['id'],
                'submission_id' => $submission_id,
                'task_key' => $photo['slot'],
                'attachment_id' => $photo['attachment_id'],
                'caption' => $photo['caption'],
            ));

            if (!$photo_created) {
                GMS_Database::deleteHousekeeperPhotosBySubmission($submission_id);
                GMS_Database::deleteHousekeeperSubmission($submission_id);
                self::cleanupUploadedAttachments($processed_photos);

                return new WP_Error(
                    'gms_housekeeper_photo_error',
                    __('Unable to save one of the uploaded photos. Please try again.', 'guest-management-system'),
                    array('status' => 500)
                );
            }
        }

        $response = array(
            'success' => true,
            'message' => __('Checklist submitted successfully.', 'guest-management-system'),
        );

        return rest_ensure_response($response);
    }

    private static function renderError($message) {
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php esc_html_e('Checklist unavailable', 'guest-management-system'); ?></title>
    <?php wp_head(); ?>
</head>
<body class="gms-housekeeper-page gms-housekeeper-page--error">
    <main class="gms-housekeeper-layout">
        <section class="gms-housekeeper-error">
            <h1><?php esc_html_e('Checklist unavailable', 'guest-management-system'); ?></h1>
            <p><?php echo esc_html($message); ?></p>
        </section>
    </main>
    <?php wp_footer(); ?>
</body>
</html>
<?php
    }

    private static function renderPhases(array $blueprint) {
        $index = 0;
        foreach ($blueprint['phases'] as $phase) {
            $index++;
            $phase_id = 'gms-phase-' . esc_attr($phase['key']);
            $is_active = $index === 1;
            ?>
            <section class="gms-housekeeper-phase<?php echo $is_active ? ' is-active' : ''; ?>" id="<?php echo esc_attr($phase_id); ?>" data-phase-key="<?php echo esc_attr($phase['key']); ?>" data-phase-index="<?php echo esc_attr($index); ?>">
                <header class="gms-housekeeper-phase__header">
                    <span class="gms-housekeeper-phase__step"><?php echo esc_html(sprintf(__('Step %d', 'guest-management-system'), $index)); ?></span>
                    <h2 class="gms-housekeeper-phase__title"><?php echo esc_html($phase['label']); ?></h2>
                    <?php if (!empty($phase['description'])) : ?>
                        <p class="gms-housekeeper-phase__description"><?php echo esc_html($phase['description']); ?></p>
                    <?php endif; ?>
                </header>
                <?php
                foreach ($phase['sections'] as $section) {
                    self::renderSection($section);
                }
                ?>
            </section>
            <?php
        }
    }

    private static function renderSection(array $section) {
        $section_id = 'gms-section-' . esc_attr($section['key']);
        ?>
        <div class="gms-housekeeper-section" id="<?php echo esc_attr($section_id); ?>" data-section-key="<?php echo esc_attr($section['key']); ?>">
            <div class="gms-housekeeper-section__header">
                <h3 class="gms-housekeeper-section__title"><?php echo esc_html($section['label']); ?></h3>
                <?php if (!empty($section['description'])) : ?>
                    <p class="gms-housekeeper-section__description"><?php echo esc_html($section['description']); ?></p>
                <?php endif; ?>
            </div>
            <?php if (!empty($section['tasks'])) : ?>
                <ul class="gms-housekeeper-tasks">
                    <?php foreach ($section['tasks'] as $task) :
                        $input_id = 'task-' . esc_attr($task['key']);
                        $is_required = !empty($task['required']);
                        ?>
                        <li class="gms-housekeeper-task">
                            <input type="checkbox" class="gms-housekeeper-task__input" id="<?php echo esc_attr($input_id); ?>" name="tasks[<?php echo esc_attr($task['key']); ?>]" value="1" <?php checked(!empty($task['default'])); ?> <?php echo $is_required ? 'required' : ''; ?>>
                            <label for="<?php echo esc_attr($input_id); ?>" class="gms-housekeeper-task__label">
                                <?php echo esc_html($task['label']); ?>
                                <?php if (!empty($task['helper'])) : ?>
                                    <span class="gms-housekeeper-task__helper"><?php echo esc_html($task['helper']); ?></span>
                                <?php endif; ?>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!empty($section['notes'])) : ?>
                <?php foreach ($section['notes'] as $note) :
                    $textarea_id = 'note-' . esc_attr($note['key']);
                    ?>
                    <label for="<?php echo esc_attr($textarea_id); ?>" class="gms-housekeeper-note">
                        <span class="gms-housekeeper-note__label"><?php echo esc_html($note['label']); ?></span>
                        <textarea id="<?php echo esc_attr($textarea_id); ?>" name="notes[<?php echo esc_attr($note['key']); ?>]" rows="3" placeholder="<?php echo esc_attr($note['placeholder'] ?? ''); ?>" <?php echo !empty($note['required']) ? 'required' : ''; ?>></textarea>
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($section['photos'])) : ?>
                <div class="gms-housekeeper-photos" data-photo-group="<?php echo esc_attr($section['key']); ?>">
                    <h4 class="gms-housekeeper-photos__title"><?php esc_html_e('Required photos', 'guest-management-system'); ?></h4>
                    <div class="gms-housekeeper-photos__grid">
                        <?php foreach ($section['photos'] as $photo_slot) :
                            $photo_id = 'photo-' . esc_attr($photo_slot['key']);
                            ?>
                            <label for="<?php echo esc_attr($photo_id); ?>" class="gms-housekeeper-photo">
                                <span class="gms-housekeeper-photo__label"><?php echo esc_html($photo_slot['label']); ?></span>
                                <input type="file" id="<?php echo esc_attr($photo_id); ?>" name="photos[<?php echo esc_attr($section['key']); ?>][<?php echo esc_attr($photo_slot['key']); ?>]" accept="image/*" required>
                                <span class="gms-housekeeper-photo__hint"><?php esc_html_e('Tap to upload', 'guest-management-system'); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function validateTasks(array $tasks_input, array $notes_input, array $blueprint) {
        $required_tasks = self::getRequiredTasks($blueprint);
        $normalized_tasks = array();

        foreach ($tasks_input as $task_key => $value) {
            $normalized_tasks[$task_key] = (bool) $value;
        }

        foreach ($required_tasks as $task_key) {
            if (empty($normalized_tasks[$task_key])) {
                return new WP_Error('gms_housekeeper_missing_tasks', __('Please complete all required checklist items.', 'guest-management-system'), array('status' => 400));
            }
        }

        $normalized_notes = array();
        foreach ($notes_input as $note_key => $note_value) {
            $normalized_notes[$note_key] = wp_kses_post($note_value);
        }

        return array(
            'tasks' => $normalized_tasks,
            'notes' => $normalized_notes,
        );
    }

    private static function getRequiredTasks(array $blueprint) {
        $tasks = array();
        foreach ($blueprint['phases'] as $phase) {
            foreach ($phase['sections'] as $section) {
                if (empty($section['tasks'])) {
                    continue;
                }
                foreach ($section['tasks'] as $task) {
                    if (!empty($task['required'])) {
                        $tasks[] = $task['key'];
                    }
                }
            }
        }
        return $tasks;
    }

    private static function getRequiredPhotoSlots(array $blueprint) {
        $slots = array();
        foreach ($blueprint['phases'] as $phase) {
            foreach ($phase['sections'] as $section) {
                if (empty($section['photos'])) {
                    continue;
                }
                foreach ($section['photos'] as $photo_slot) {
                    $slots[$section['key']][] = array(
                        'slot' => $photo_slot['key'],
                        'label' => $photo_slot['label'],
                    );
                }
            }
        }
        return $slots;
    }

    private static function processPhotos($photos, array $required_slots, $reservation_id) {
        if (!empty($required_slots)) {
            foreach ($required_slots as $section_key => $slots) {
                if (!isset($photos['name'][$section_key])) {
                    return new WP_Error('gms_housekeeper_missing_photos', __('Please upload the required photos for each room.', 'guest-management-system'), array('status' => 400));
                }
            }
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachments = array();

        foreach ($required_slots as $section_key => $slots) {
            foreach ($slots as $slot) {
                $slot_key = $slot['slot'];

                if (!isset($photos['name'][$section_key][$slot_key]) || $photos['name'][$section_key][$slot_key] === '') {
                    self::cleanupUploadedAttachments($attachments);
                    return new WP_Error('gms_housekeeper_missing_photos', __('Please upload the required photos for each room.', 'guest-management-system'), array('status' => 400));
                }

                $file_array = array(
                    'name' => $photos['name'][$section_key][$slot_key],
                    'type' => $photos['type'][$section_key][$slot_key],
                    'tmp_name' => $photos['tmp_name'][$section_key][$slot_key],
                    'error' => $photos['error'][$section_key][$slot_key],
                    'size' => $photos['size'][$section_key][$slot_key],
                );

                $uploaded = wp_handle_upload($file_array, array('test_form' => false));

                if (isset($uploaded['error'])) {
                    self::cleanupUploadedAttachments($attachments);
                    return new WP_Error('gms_housekeeper_upload_failed', $uploaded['error'], array('status' => 400));
                }

                $file_name = wp_basename($uploaded['file']);
                $sanitized_title = sanitize_file_name($file_name);

                if ($sanitized_title === '') {
                    $sanitized_title = 'housekeeper-photo-' . time();
                }

                $attachment = array(
                    'post_mime_type' => $uploaded['type'],
                    'post_title' => $sanitized_title,
                    'post_content' => '',
                    'post_status' => 'inherit',
                );

                $attachment_id = wp_insert_attachment($attachment, $uploaded['file']);

                if (is_wp_error($attachment_id) || !$attachment_id) {
                    self::cleanupUploadedAttachments($attachments);
                    return new WP_Error('gms_housekeeper_upload_failed', __('Unable to save one of the uploaded images.', 'guest-management-system'), array('status' => 500));
                }

                $metadata = wp_generate_attachment_metadata($attachment_id, $uploaded['file']);
                if (!empty($metadata)) {
                    wp_update_attachment_metadata($attachment_id, $metadata);
                }

                update_post_meta($attachment_id, '_gms_housekeeper_reservation', intval($reservation_id));
                update_post_meta($attachment_id, '_gms_housekeeper_task', sanitize_text_field($section_key . ':' . $slot_key));

                $attachments[] = array(
                    'attachment_id' => $attachment_id,
                    'slot' => $section_key . ':' . $slot_key,
                    'caption' => $slot['label'],
                );
            }
        }

        return $attachments;
    }

    private static function cleanupUploadedAttachments(array $attachments) {
        foreach ($attachments as $attachment) {
            if (!empty($attachment['attachment_id'])) {
                wp_delete_attachment($attachment['attachment_id'], true);
            }
        }
    }

    public static function getBlueprint() {
        $bedroom_sections = array();
        for ($i = 1; $i <= 3; $i++) {
            $bedroom_key = 'bedroom_' . $i;
            $bedroom_sections[] = array(
                'key' => $bedroom_key,
                'label' => sprintf(__('Bedroom %d', 'guest-management-system'), $i),
                'description' => __('Reset the room, stage linens, and confirm amenities.', 'guest-management-system'),
                'tasks' => array(
                    array(
                        'key' => $bedroom_key . '_bed_made',
                        'label' => __('Beds made with fresh linens and accent pillows arranged', 'guest-management-system'),
                        'required' => true,
                    ),
                    array(
                        'key' => $bedroom_key . '_surfaces_dusted',
                        'label' => __('Surfaces dusted and mirrors/windows polished', 'guest-management-system'),
                        'required' => true,
                    ),
                    array(
                        'key' => $bedroom_key . '_inventory_checked',
                        'label' => __('Inventory checked (hangers, spare linens, décor)', 'guest-management-system'),
                        'required' => true,
                    ),
                ),
                'notes' => array(
                    array(
                        'key' => $bedroom_key . '_notes',
                        'label' => __('Bedroom notes', 'guest-management-system'),
                        'placeholder' => __('Record missing items or maintenance follow-ups.', 'guest-management-system'),
                    ),
                ),
                'photos' => array(
                    array(
                        'key' => $bedroom_key . '_wide',
                        'label' => __('Wide shot (bed & nightstands)', 'guest-management-system'),
                    ),
                    array(
                        'key' => $bedroom_key . '_detail',
                        'label' => __('Detail shot (closet or dresser)', 'guest-management-system'),
                    ),
                ),
            );
        }

        $bathroom_sections = array();
        for ($i = 1; $i <= 2; $i++) {
            $bathroom_key = 'bathroom_' . $i;
            $bathroom_sections[] = array(
                'key' => $bathroom_key,
                'label' => sprintf(__('Bathroom %d', 'guest-management-system'), $i),
                'description' => __('Sanitize fixtures, stage towels, and restock supplies.', 'guest-management-system'),
                'tasks' => array(
                    array(
                        'key' => $bathroom_key . '_sanitized',
                        'label' => __('Sink, shower, tub, and toilet sanitized', 'guest-management-system'),
                        'required' => true,
                    ),
                    array(
                        'key' => $bathroom_key . '_towels',
                        'label' => __('Towels folded, toiletries replenished, amenities staged', 'guest-management-system'),
                        'required' => true,
                    ),
                    array(
                        'key' => $bathroom_key . '_floors',
                        'label' => __('Floors vacuumed and mopped, vents wiped', 'guest-management-system'),
                        'required' => true,
                    ),
                ),
                'notes' => array(
                    array(
                        'key' => $bathroom_key . '_notes',
                        'label' => __('Bathroom notes', 'guest-management-system'),
                        'placeholder' => __('List damages, leaks, or missing stock.', 'guest-management-system'),
                    ),
                ),
                'photos' => array(
                    array(
                        'key' => $bathroom_key . '_wide',
                        'label' => __('Wide shot (vanity & shower)', 'guest-management-system'),
                    ),
                    array(
                        'key' => $bathroom_key . '_amenities',
                        'label' => __('Amenities & towel display', 'guest-management-system'),
                    ),
                ),
            );
        }

        return array(
            'phases' => array(
                array(
                    'key' => 'arrival',
                    'label' => __('Arrival & Safety', 'guest-management-system'),
                    'description' => __('Secure the property and log any immediate issues before cleaning.', 'guest-management-system'),
                    'sections' => array(
                        array(
                            'key' => 'arrival_overview',
                            'label' => __('Arrival checks', 'guest-management-system'),
                            'description' => __('Complete these steps before entering cleaning mode.', 'guest-management-system'),
                            'tasks' => array(
                                array(
                                    'key' => 'arrival_security',
                                    'label' => __('Alarm disarmed, entry doors secured from inside', 'guest-management-system'),
                                    'required' => true,
                                ),
                                array(
                                    'key' => 'arrival_walkthrough',
                                    'label' => __('Initial walkthrough completed (note damages or guest belongings)', 'guest-management-system'),
                                    'required' => true,
                                ),
                                array(
                                    'key' => 'arrival_supplies',
                                    'label' => __('Verified cleaning supplies on hand', 'guest-management-system'),
                                    'required' => true,
                                ),
                            ),
                            'notes' => array(
                                array(
                                    'key' => 'arrival_notes',
                                    'label' => __('Arrival notes', 'guest-management-system'),
                                    'placeholder' => __('Record any guest belongings, damages, or urgent issues.', 'guest-management-system'),
                                ),
                            ),
                        ),
                    ),
                ),
                array(
                    'key' => 'bedrooms',
                    'label' => __('Bedrooms', 'guest-management-system'),
                    'description' => __('Reset each bedroom for the next arrival.', 'guest-management-system'),
                    'sections' => $bedroom_sections,
                ),
                array(
                    'key' => 'bathrooms',
                    'label' => __('Bathrooms', 'guest-management-system'),
                    'description' => __('Ensure bathrooms are sanitized and fully stocked.', 'guest-management-system'),
                    'sections' => $bathroom_sections,
                ),
                array(
                    'key' => 'common_areas',
                    'label' => __('Kitchen & Living Spaces', 'guest-management-system'),
                    'description' => __('Deep clean high-touch areas and stage amenities.', 'guest-management-system'),
                    'sections' => array(
                        array(
                            'key' => 'kitchen',
                            'label' => __('Kitchen reset', 'guest-management-system'),
                            'tasks' => array(
                                array(
                                    'key' => 'kitchen_appliances',
                                    'label' => __('Appliances wiped inside/out, dishwasher emptied, garbage removed', 'guest-management-system'),
                                    'required' => true,
                                ),
                                array(
                                    'key' => 'kitchen_surfaces',
                                    'label' => __('Counters, backsplash, and cabinet fronts sanitized', 'guest-management-system'),
                                    'required' => true,
                                ),
                                array(
                                    'key' => 'kitchen_inventory',
                                    'label' => __('Inventory checked (coffee/tea, dish soap, paper goods)', 'guest-management-system'),
                                    'required' => true,
                                ),
                            ),
                            'notes' => array(
                                array(
                                    'key' => 'kitchen_notes',
                                    'label' => __('Kitchen notes', 'guest-management-system'),
                                    'placeholder' => __('Missing items, maintenance, or appliance issues.', 'guest-management-system'),
                                ),
                            ),
                        ),
                        array(
                            'key' => 'living_room',
                            'label' => __('Living & outdoor areas', 'guest-management-system'),
                            'tasks' => array(
                                array(
                                    'key' => 'living_surfaces',
                                    'label' => __('Surfaces dusted, mirrors/windows polished, décor staged', 'guest-management-system'),
                                    'required' => true,
                                ),
                                array(
                                    'key' => 'living_floors',
                                    'label' => __('Floors vacuumed/mopped, rugs shaken out', 'guest-management-system'),
                                    'required' => true,
                                ),
                                array(
                                    'key' => 'living_tech',
                                    'label' => __('Remote controls, streaming devices, and lamps tested', 'guest-management-system'),
                                    'required' => true,
                                ),
                            ),
                            'notes' => array(
                                array(
                                    'key' => 'living_notes',
                                    'label' => __('Living area notes', 'guest-management-system'),
                                    'placeholder' => __('Document furniture damage or outdoor needs.', 'guest-management-system'),
                                ),
                            ),
                        ),
                    ),
                ),
                array(
                    'key' => 'departure',
                    'label' => __('Final Walkthrough & Lock-up', 'guest-management-system'),
                    'description' => __('Ensure the home is staged, stocked, and secured.', 'guest-management-system'),
                    'sections' => array(
                        array(
                            'key' => 'departure_checks',
                            'label' => __('Final checklist', 'guest-management-system'),
                            'tasks' => array(
                                array(
                                    'key' => 'departure_staging',
                                    'label' => __('Lights set, thermostat adjusted, blinds positioned per property guide', 'guest-management-system'),
                                    'required' => true,
                                ),
                                array(
                                    'key' => 'departure_doors',
                                    'label' => __('All doors/windows locked and alarm armed', 'guest-management-system'),
                                    'required' => true,
                                ),
                                array(
                                    'key' => 'departure_supplies',
                                    'label' => __('Trash removed, laundry running/completed, supplies restocked', 'guest-management-system'),
                                    'required' => true,
                                ),
                            ),
                            'notes' => array(
                                array(
                                    'key' => 'inventory_notes',
                                    'label' => __('Inventory & follow-up notes', 'guest-management-system'),
                                    'placeholder' => __('Record any purchases needed or issues to escalate.', 'guest-management-system'),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    private static function getNonceAction($token) {
        return 'gms_housekeeper_submit_' . $token;
    }
}
