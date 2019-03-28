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
 * Course log fetcher
 *
 * @package   tool_s3logs
 * @author    Kenneth Hendricks <kennethhendricks@catalyst-au.net>
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_s3logs\log_fetcher;

use tool_s3logs\client\s3_client;

class course_log_fetcher {

    /**
     * Fetches logs for multiple courses at once and writes to seperate files.
     *
     * @param integer $courseids Course ids.
     * @param string  $logfolder Log folder path.
     * @return void
     */
    public static function fetch_logs($courseids, $logfolder) {

        echo "Setting up course log files ... \n";
        $courseloghandles = self::get_course_log_file_handles($courseids, $logfolder);
        self::write_headers_to_course_log_files($courseloghandles);

        echo "Getting S3 log file keys ... \n";
        $s3logkeys = self::get_s3_log_keys();

        echo "Fetching logs from S3 \n";
        self::write_s3_logs_to_course_logs($courseids, $courseloghandles, $s3logkeys);

        echo "Fetching logs from DB \n";
        self::write_current_database_logs_to_course_logs($courseids, $courseloghandles);

        self::close_course_log_file_handles($courseloghandles);

        echo "Finished pulling logs \n";
    }

    /**
     * Pulls s3 logs, parses them and writes relevent course log entries to
     * respective files.
     *
     * @param array $courseids        Course ids.
     * @param array $courseloghandles Course log file handles.
     * @param array $s3logkeys        S3 log keys
     * @return void
     */
    private static function write_s3_logs_to_course_logs($courseids, $courseloghandles, $s3logkeys) {
        $currentlogcount = 0;
        $totallogs = count($s3logkeys);
        foreach ($s3logkeys as $s3logkey) {
            $currentlogcount++;

            echo "\nDownloading $s3logkey ($currentlogcount/$totallogs) \n";
            $loghandle = self::download_s3_log_file($s3logkey);
            $completedlogs = 0;

            echo "Parsing $s3logkey \n";
            while (($row = fgetcsv($loghandle)) !== false) {
                if (self::is_course_log_entry($courseids, $row)) {
                    $entrycourseid = $row[11]; // holds contextinstanceid
                    fputcsv($courseloghandles[$entrycourseid], $row);

                    echo "Found log entry for course $entrycourseid \n";

                    if ($row[1] == '\core\event\course_deleted') {
                        $completedlogs++;

                        // We've parsed all logs needed.
                        if ($completedlogs == count($courseids)) {
                            echo "Finishing early, {$completedlogs} course delete events found \n";
                            fclose($loghandle);
                            return;
                        }
                    }
                }
            }

            fclose($loghandle);
        }
    }

    /**
     * Determines if log row is a course log entry we want.
     * Matches courseid = contextinstanceid and contextlevel = course
     *
     * @param array $courseids Course ids.
     * @param array $row       Log row.
     * @return boolean
     */
    private static function is_course_log_entry($courseids, $row) {
        if (in_array($row[11], $courseids) && $row[10] == CONTEXT_COURSE) {
            return true;
        }
        return false;
    }

    /**
     * Downloads an s3 log file to the temp dir and opens a handle for it.
     *
     * @param string $s3logkey
     * @return resource
     */
    private static function download_s3_log_file($s3logkey) {
        $s3client = new s3_client();
        $tempdir = make_temp_directory('s3logs_download');
        $tempfilepath = tempnam($tempdir, 's3logs_');
        $s3client->download_file($tempfilepath, $s3logkey);
        $loghandle = fopen($tempfilepath, 'r');
        return $loghandle;
    }

    /**
     * Gets oldest -> newest array of S3 log keys
     *
     * @return array
     */
    private static function get_s3_log_keys() {
        $s3client = new s3_client();
        $porentialkeys = $s3client->get_all_keys();

        $keys = array();
        foreach ($porentialkeys as $potentialkey) {
            // Dont include keys that arn't expected format.
            if (!preg_match('/.*_\d{14}_\d*_\d*.csv/', $potentialkey)) {
                continue;
            }

            // Strip prefix so we can sort.
            $sortable = trim(substr($potentialkey, strpos($potentialkey, '_') + 1));
            $keys[$sortable] = $potentialkey;
        }

        // Sort so ordered oldest -> newest.
        ksort($keys);

        return $keys;
    }

    /**
     * Writes current log contents for courses that are in the DB to log files.
     *
     * @param integer $courseids      Course ids.
     * @param array   $logfilehandles Log file handles, indexed by courseid.
     * @return void
     */
    private static function write_current_database_logs_to_course_logs($courseids, $logfilehandles) {
        global $DB;

        foreach ($courseids as $courseid) {
            $records = $DB->get_records(
                'logstore_standard_log',
                array(
                    'contextlevel' => CONTEXT_COURSE,
                    'contextinstanceid' => $courseid
                )
            );

            foreach ($records as $record) {
                fputcsv($logfilehandles[$courseid], (array) $record);
            }
        }
    }

    /**
     * Creates file handles to pull logs to for each supplied course.
     *
     * @param integer $courseids Course ids.
     * @param string  $logfolder Log folder path.
     * @return array
     */
    private static function get_course_log_file_handles($courseids, $logfolder) {
        $now = time();
        $handles = array();

        foreach ($courseids as $courseid) {
            $filename = "{$logfolder}/course_{$courseid}_retreived_{$now}.csv";
            $fp = fopen($filename, 'w');
            $handles[$courseid] = $fp;
        }

        return $handles;
    }

    /**
     * Closes array of file handles
     *
     * @param array $filehandles
     * @return void
     */
    private static function close_course_log_file_handles(&$filehandles) {
        foreach ($filehandles as $handle) {
            fclose($handle);
        }
    }

    /**
     * Writes logstore_standard_log CSV headers to file handles.
     *
     * @param array $filehandles
     * @return void
     */
    private static function write_headers_to_course_log_files(&$filehandles) {
        global $DB;
        $headerrecords = $DB->get_columns('logstore_standard_log');
        $headers = array();
        foreach ($headerrecords as $key => $value) {
            $headers[] = $key;
        }

        foreach ($filehandles as $handle) {
            fputcsv($handle, $headers);
        }
    }
}
