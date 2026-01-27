<?php

/**
 * Message configuration
 *
 * @package   mod_zoom
 */

defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    // Notify users about session creation.
    'session_notification' => [
        'defaults' => [
            'email' => MESSAGE_DEFAULT_ENABLED,
        ],
    ],

    // Send a reminder about the session.
    'session_reminder' => [
        'defaults' => [
            'popup' => MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_DEFAULT_ENABLED,
        ],
    ],
];
