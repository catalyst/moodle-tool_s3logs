<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Upgrade steps are defined here.
 *
 * @package     tool_s3logs
 * @category    upgrade
 * @copyright   2026 Catalyst IT
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute tool_s3logs upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_tool_s3logs_upgrade($oldversion) {
    global $CFG;

    if ($oldversion < 2026052903) {
        // The maxlogage setting used to be stored as a number of months (PARAM_INT) and is now
        // stored in seconds, to support the admin_setting_configduration widget. Convert any
        // existing value so behaviour is preserved for sites that have already configured it.
        $months = get_config('tool_s3logs', 'maxlogage');
        if ($months !== false && $months !== '') {
            $seconds = (int)$months * 60 * 60 * 24 * 30; // We standardise on a month having 30 days.
            set_config('maxlogage', $seconds, 'tool_s3logs');
        }

        upgrade_plugin_savepoint(true, 2026052903, 'tool', 's3logs');
    }

    if ($oldversion < 2026052910) {
        // Temp files used to be written to $CFG->tempdir/s3logs_upload (shared storage), but are
        // now written to a per-request, node-local directory instead, and cleaned up as the task
        // runs. Remove any old files left behind under the old shared location.
        $olddir = $CFG->tempdir . '/s3logs_upload';
        if (is_dir($olddir)) {
            fulldelete($olddir);
        }

        upgrade_plugin_savepoint(true, 2026052910, 'tool', 's3logs');
    }

    return true;
}
