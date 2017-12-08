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
 * Fetch course logs CLI script
 *
 * @package   tool_s3logs
 * @author    Kenneth Hendricks <kennethhendricks@catalyst-au.net>
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir.'/clilib.php');

$help =
    "Retrive logs from S3 for courses.

Options:
--courses=courseid1,courseid2   Comma seperated list of courseids.
--logfolder=/tmp/course_logs    Path to folder where pulled logs will be stored.
-h, --help                      Print out this help.

Example:
\$ sudo -u www-data /usr/bin/php admin/tool/s3_logs/cli/fetch_course_logs.php --courses=101,102,103 --logfolder=/tmp/course_logs
";

list($options, $unrecognized) = cli_get_params(
    array(
        'courses'   => null,
        'logfolder' => null,
        'help'      => false,
    ),
    array(
        'h' => 'help',
    )
);

if ($options['help'] || $options['courses'] === null || $options['logfolder'] === null) {
    echo $help;
    exit(0);
}

$courseids = explode(',', $options['courses']);

// Check course ids are positive integers.
foreach ($courseids as $courseid) {
    if (!ctype_digit($courseid)) {
        echo "Invalid course id: '{$courseid}'\n";
        exit(0);
    }
}

$logfolder = $options['logfolder'];

if (!is_dir($logfolder)) {
    echo "Supplied path is not a directory\n";
    exit(0);
}

if (!is_writable($logfolder)) {
    echo "Supplied folder is not writable\n";
    exit(0);
}

tool_s3logs\log_fetcher\course_log_fetcher::fetch_logs($courseids, $logfolder);