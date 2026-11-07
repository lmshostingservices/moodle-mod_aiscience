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

namespace mod_aiscience\task;

/**
 * Ad-hoc task: build an experiment version with AI.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class build_experiment extends \core\task\adhoc_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskbuild', 'mod_aiscience');
    }

    /**
     * Run it.
     */
    public function execute() {
        \core_php_time_limit::raise(1800);
        $data = $this->get_custom_data();
        \mod_aiscience\ai\generator::run_build((int)$data->versionid);
    }
}
