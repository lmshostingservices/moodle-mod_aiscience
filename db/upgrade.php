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
 * Upgrade steps for AI Science Experiment.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_aiscience_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();
    if ($oldversion < 2026110600) {
        $table = new xmldb_table('aiscience_version');
        $field = new xmldb_field('basedon', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'repairs');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026110600, 'aiscience');
    }
    if ($oldversion < 2026110601) {
        // Ready-made experiments gained the prediction check, CER-grouped reports and clearer labels.
        \mod_aiscience\local\library::refresh_all();
        upgrade_mod_savepoint(true, 2026110601, 'aiscience');
    }
    if ($oldversion < 2026110602) {
        $table = new xmldb_table('aiscience_attempt');
        $field = new xmldb_field('marks', XMLDB_TYPE_TEXT, null, null, null, null, null, 'grader');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('aiscience_credit');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('aiscienceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('questionkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('credits', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('course_time', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'timecreated']);
            $dbman->create_table($table);
        }
        // Ready-made experiments gained marking guides and the before-you-send report check.
        \mod_aiscience\local\library::refresh_all();
        upgrade_mod_savepoint(true, 2026110602, 'aiscience');
    }
    if ($oldversion < 2026110700) {
        // All AI now runs through LMS Labs. Remove the old provider choice, the Anthropic key (so it is never
        // sent anywhere), the model settings and the locally set credit balance.
        foreach (['provider', 'apikey', 'model', 'maxtokens', 'aicredits', 'mockreplies'] as $name) {
            unset_config($name, 'mod_aiscience');
        }
        upgrade_mod_savepoint(true, 2026110700, 'aiscience');
    }
    return true;
}
