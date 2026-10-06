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

namespace tool_s3logs\check;
use core\check\check;
use core\check\result;
use action_link;
use moodle_url;
use tool_s3logs\local\client\s3_client;
use tool_s3logs\task\process_logs;

/**
 * Status check for s3 logs delivery.
 *
 * @package    tool_s3logs
 * @author     Dmitrii Metelkin <dmitriim@catalyst-au.net>
 * @copyright  Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class status extends check {
    /**
     * Link to the settings page.
     *
     * @return action_link|null
     */
    public function get_action_link(): ?action_link {
        return new action_link(
            new moodle_url('/admin/settings.php', ['section' => 'tool_s3logs']),
            get_string('pluginname', 'tool_s3logs')
        );
    }

    /**
     * Check for the status.
     *
     * @return result
     */
    public function get_result(): result {
        $client = new s3_client();
        $consolelink = $this->get_console_link_html($client);

        // Connection check.
        $connection = $client->test_connection();
        if (!$connection->success) {
            $details = '';
            if (!empty($connection->details)) {
                $details = s($connection->details);
            }
            return new result(result::ERROR, trim(get_string('connectionfailure', 'tool_s3logs', '')), $details . $consolelink);
        }

        // Permission check.
        $permissions = $client->test_permissions();
        if (!$permissions->success) {
            // Aggregate permission messages into details.
            $detailmsgs = '';
            if (!empty($permissions->messages) && is_array($permissions->messages)) {
                foreach (array_keys($permissions->messages) as $msg) {
                    $detailmsgs .= $msg . "\n";
                }
            }
            $details = $detailmsgs ? s($detailmsgs) : '';
            return new result(result::ERROR, trim(get_string('writefailure', 'tool_s3logs', '')), $details . $consolelink);
        }

        // All configured, but disabled.
        if (empty(get_config('tool_s3logs', 'enable'))) {
            return new result(result::WARNING, get_string('logarchiverdisabled', 'tool_s3logs'), $consolelink);
        }

        // If the last run hit its maximum runtime, it usually means there were still
        // eligible records left to archive when time ran out - i.e. the task is not keeping
        // pace with new logs being created, and maxruntime should likely be increased.
        $maxruntimewarning = $this->check_last_run_duration($consolelink);
        if ($maxruntimewarning !== null) {
            return $maxruntimewarning;
        }

        return new result(result::OK, get_string('connectionsuccess', 'tool_s3logs'), $consolelink);
    }

    /**
     * Builds an HTML link to the bucket in the AWS console, for inclusion in a result's details.
     *
     * @param s3_client $client Client to use to build the link.
     * @return string HTML chunk with the link, or an empty string if the bucket/region are not configured.
     */
    private function get_console_link_html(s3_client $client): string {
        $consoleurl = $client->get_console_url();
        if ($consoleurl === null) {
            return '';
        }

        return \html_writer::link(
            $consoleurl,
            get_string('viewbucketinconsole', 'tool_s3logs'),
            ['target' => '_blank', 'rel' => 'noopener noreferrer']
        );
    }

    /**
     * Check whether the last run of the archiving task hit its configured maximum runtime.
     *
     * @param string $consolelink HTML chunk with a link to the bucket in the AWS console, appended to the details.
     * @return ?result A warning result if the last run maxed out, null otherwise.
     */
    private function check_last_run_duration(string $consolelink = ''): ?result {
        global $DB;

        $maxruntime = (int)get_config('tool_s3logs', 'maxruntime');
        if (empty($maxruntime)) {
            return null;
        }

        $lastrun = $DB->get_records(
            'task_log',
            ['classname' => process_logs::class],
            'id DESC',
            'id, timestart, timeend',
            0,
            1
        );

        if (empty($lastrun)) {
            return null;
        }

        $lastrun = reset($lastrun);
        $duration = $lastrun->timeend - $lastrun->timestart;

        if ($duration >= $maxruntime) {
            return new result(
                result::WARNING,
                get_string('maxruntimeexceeded', 'tool_s3logs', format_time($maxruntime)),
                get_string('maxruntimeexceeded_details', 'tool_s3logs', (object)[
                    'duration' => (int)round($duration),
                    'maxruntime' => $maxruntime,
                ]) . $consolelink
            );
        }

        return null;
    }
}
