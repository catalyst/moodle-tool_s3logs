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
 * Task to process logs.
 *
 * @package     tool_s3logs
 * @category    task
 * @copyright   2017 Matt Porritt <mattp@catlayst-au.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_s3logs\task;

defined('MOODLE_INTERNAL') || die();

use tool_s3logs\client\s3_client;

/**
 * Class to process logs.
 *
 * @package     tool_s3logs
 * @category    task
 * @copyright   2017 Matt Porritt <mattp@catlayst-au.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_logs extends \core\task\scheduled_task {

    /**
     * {@inheritDoc}
     * @see \core\task\scheduled_task::get_name()
     */
    public function get_name() {
        // Shown in admin screens
        return get_string('processlogs', 'tool_s3logs');
    }

    /**
     * Creates a temp file on the files system.
     * Returns the name inlcuding path of the file
     * and a file pointer.
     *
     * @return array File name and file pointer.
     */
    private function get_temp_file() {
        $tempdir = make_temp_directory('s3logs_upload');
        $tempfile = tempnam ($tempdir, 's3logs_');
        $fp = fopen($tempfile, 'w');

        return array ($tempfile, $fp);
    }

    /**
     * Given a file pointer write the fields from the logstore
     * table as headers.
     *
     * @param file $fp Valid file pointer
     * @return int $result The Length of the header cotnent written.
     */
    private function write_file_headers($fp){
        global $DB;

        $headerrecords = $DB->get_columns('logstore_standard_log');
        $headers = array();
        foreach ($headerrecords as $key => $value) {
            $headers[] = $key;
        }
        $result = fputcsv($fp, $headers);

        return $result;
    }

    /**
     * Extract the log records from the db and write
     * to a temporary file.
     *
     * @param int $stopat The time to stop process, if there are still records.
     * @param int $maxage Get log entries that are older than this unix timestamp.
     * @param file $fp File pointer to temp file to write to.
     * @return array $recordids the ID's of the log entries written to the file.
     */
    private function extract_records($stopat, $maxage, $fp) {
        global $DB;

        $start = 0;
        $limit = 1000;
        $step = 1000;
        $threshold = time() - $maxage;
        $recordids = array();

        // Get 1000 rows of data from the log table order by oldest first.
        // Keep getting records 1000 at a time until we run out of records or max execution time is reached.
        while (time() <= $stopat){
            $results = $DB->get_records_select(
                    'logstore_standard_log',
                    'timecreated >= ?',
                    array($threshold),
                    'timecreated ASC',
                    '*',
                    $start,
                    $limit
                    );

            if (empty($results)) {
                mtrace('Records processing finished before time limit reached');
                break; // Stop trying to get records when we run out;
            }

            // Increment record start position for next iteration.
            $start += $step;

            // We do not want to load all results into memory,
            // we want to write them to a file as we go.
            foreach($results as $key => $value){
                $recordids[] = $key;
                fputcsv($fp, (array)$value);
            }

        }

        return $recordids;
    }

    /**
     * Deletes rows from teh log store table.
     *
     * @param array $recordids Array of record ID's to delete
     */
    private function delete_records ($recordids){
        global $DB;

        $todelete = implode(',', $recordids);

        $truncatesql = "DELETE FROM {logstore_standard_log}
        WHERE id IN ({$todelete})";
        $DB->execute($truncatesql);
    }

    /**
     *
     * {@inheritDoc}
     * @see \core\task\task_base::execute()
     */
    public function execute() {
        $config = get_config('tool_s3logs');

        // Set up basic vars.
        $maxage = 60 * 60 * 24 * 30 * $config->maxlogage; // We standardise on a month having 30 days.
        $stopat = time() + $config->maxruntime;
 
        // Get a temp file.
        mtrace('Getting temporary file...');
        list ($tempfile, $fp) = $this->get_temp_file();

        // Add the table headers to the temp file
        mtrace('writing table headers to temporary file...');
        $headerwrite = $this->write_file_headers($fp);
        if (!$headerwrite) {
            throw new \moodle_exception('noheaders', 'tool_s3logs', '');
        }

        // Extract records from DB and add them to the temp file.
        mtrace('Finding records and updating temporary file...');
        $recordids = $this->extract_records($stopat, $maxage, $fp);
        fclose($fp); // Close file now that we have it

        if (!empty($recordids)) {
            // if file isn't empty upload this file to s3
            mtrace('Uploading records to S3...');
            $firstrecord = min($recordids);
            $lastrecord = max($recordids);
            $keyname = 'logstore_standard_log_' . date(YmdHis). '_' . $firstrecord . '_' . $lastrecord;
            $s3client = new s3_client();
            $s3url = $s3client->upload_file($tempfile, $keyname);

            if (!$s3url){
                throw new \moodle_exception('s3uploadfailed', 'tool_s3logs', '');
            } else {
                // Delete the processed records from the log table.
                mtrace('Deleting records from DB...');
                $this->delete_records($recordids);
            }
        } else {
            mtrace('No records found to process, finishing...');
        }
    }
}
