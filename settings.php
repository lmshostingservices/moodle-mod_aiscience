<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Admin settings.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading('mod_aiscience/intro', '', get_string('settingsintro', 'mod_aiscience')));
    $settings->add(new admin_setting_heading(
        'mod_aiscience/lmslabsheading',
        get_string('lmslabs', 'mod_aiscience'),
        \mod_aiscience\ai\lmslabs::status_html() . get_string('lmslabs_desc', 'mod_aiscience')
    ));
    if (!class_exists('\\local_aiconfig\\config')) {
        $settings->add(new admin_setting_configtext(
            'mod_aiscience/siteid',
            get_string('siteid', 'mod_aiscience'),
            get_string('siteid_desc', 'mod_aiscience'),
            '',
            PARAM_RAW_TRIMMED // Pipeline-ignore: PARAM_RAW — Site URL or ID, trimmed.
        ));
        $settings->add(new admin_setting_configpasswordunmask(
            'mod_aiscience/apikey',
            get_string('apikey', 'mod_aiscience'),
            get_string('apikey_desc', 'mod_aiscience'),
            ''
        ));
    }
    $settings->add(new admin_setting_heading(
        'mod_aiscience/markingheading',
        get_string('marking', 'mod_aiscience'),
        get_string('marking_desc', 'mod_aiscience')
    ));
    $settings->add(new admin_setting_heading('mod_aiscience/voiceheading', get_string('voiceheading', 'mod_aiscience'), ''));
    $settings->add(new admin_setting_configtext(
        'mod_aiscience/voice',
        get_string('voice', 'mod_aiscience'),
        get_string('voice_desc', 'mod_aiscience'),
        '',
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configselect(
        'mod_aiscience/voicerate',
        get_string('voicerate', 'mod_aiscience'),
        '',
        '0.95',
        ['0.8' => '0.8', '0.9' => '0.9', '0.95' => '0.95', '1' => '1.0', '1.1' => '1.1']
    ));
}
