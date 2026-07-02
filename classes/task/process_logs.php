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

namespace tool_s3logs\task;

use tool_s3logs\local\client\s3_client;

/**
 * Class to process logs.
 *
 * @package     tool_s3logs
 * @category    task
 * @copyright   2017 Matt Porritt <mattp@catalyst-au.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_logs extends \core\task\scheduled_task {
    /** @var float The memory limit threshold as a fraction of the configured memory limit. */
    const MEMORY_LIMIT_THRESHOLD = 0.8; // Stop processing if we have used 80% of the memory limit.

    /**
     * {@inheritDoc}
     * @see \core\task\scheduled_task::get_name()
     */
    public function get_name() {
        // Shown in admin screens.
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
        $tempfile = tempnam($tempdir, 's3logs_');
        $fp = fopen($tempfile, 'w');

        return  [$tempfile, $fp];
    }

    /**
     * Given a file pointer write the fields from the logstore
     * table as headers.
     *
     * @param resource $fp Valid file pointer
     * @return int $result The Length of the header cotnent written.
     */
    private function write_file_headers($fp) {
        global $DB;

        $headerrecords = $DB->get_columns('logstore_standard_log');
        $headers = [];
        foreach ($headerrecords as $key => $value) {
            $headers[] = $key;
        }
        $result = fputcsv($fp, $headers);

        return $result;
    }

    /**
     * Build SQL condition and params for the course ID filter.
     *
     * Returns an empty string and empty array when no filter is configured.
     *
     * @param object $config Plugin config.
     * @return array [$sql, $params] ready to append to a WHERE clause.
     */
    private function get_course_filter_sql($config): array {
        global $DB;

        $raw = isset($config->courseids) ? trim($config->courseids) : '';
        if ($raw === '') {
            return ['', []];
        }

        // Split, trim, and keep only strictly numeric tokens to avoid accidentally
        // targeting course 0 (e.g. "1,2,3,abc" must not become "1,2,3,0").
        $tokens = array_map('trim', explode(',', $raw));
        $ids = array_map('intval', array_filter($tokens, 'ctype_digit'));

        if (empty($ids)) {
            return ['', []];
        }

        $mode = isset($config->coursefiltermode) ? $config->coursefiltermode : 'include';
        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_QM, 'param', $mode !== 'exclude');

        return [" AND courseid $insql", $inparams];
    }

    /**
     * Determines whether the memory usage for self::extract_records() has exceeded the defined threshold.
     *
     * @return bool Returns true when memory usage reaches self::MEMORY_LIMIT_THRESHOLD of memory limit; otherwise false.
     */
    public static function has_memory_exceeded(): bool {
        $memlimit = ini_get('memory_limit');

        if ($memlimit === false || $memlimit === '-1' || $memlimit === '') {
            // No memory limit.
            return false;
        }

        $reallimit = get_real_size($memlimit);
        if ($reallimit <= 0) {
            // Invalid or unusable configured limit.
            return false;
        }

        return memory_get_usage(true) >= ($reallimit * self::MEMORY_LIMIT_THRESHOLD);
    }

    /**
     * Extract the log records from the db and write
     * to a temporary file.
     *
     * The method is passed an interval of months in seconds,
     * we want to get all records that are older than this
     * number of months.
     *
     * @param int $stopat The time to stop process, if there are still records.
     * @param int $interval Interval of months in seconds.
     * @param resource $fp File pointer to temp file to write to.
     * @param object $config Plugin config.
     * @return array $recordids the ID's of the log entries written to the file.
     */
    private function extract_records($stopat, $interval, $fp, $config) {
        global $DB;

        $threshold = time() - $interval;
        $recordids = [];
        $start = 0;
        $limit = 1000;
        $step = 1000;

        mtrace('Getting records older than: ' . date('Y-m-d H:i:s', $threshold));

        [$coursefiltersql, $coursefilterparams] = $this->get_course_filter_sql($config);

        // Get 1000 rows of data from the log table order by oldest first.
        // Keep getting records 1000 at a time until we run out of records or max execution time is reached.
        while (time() <= $stopat) {
            if (self::has_memory_exceeded()) {
                $memlimitthr = round(self::MEMORY_LIMIT_THRESHOLD * 100, 0);
                mtrace("Memory limit threshold of {$memlimitthr}% reached, stopping processing to avoid an out-of-memory error");
                break;
            }

            $results = $DB->get_records_select(
                'logstore_standard_log',
                'timecreated <= ?' . $coursefiltersql,
                array_merge([$threshold], $coursefilterparams),
                'timecreated ASC',
                '*',
                $start,
                $limit
            );

            if (empty($results)) {
                mtrace('Records processing finished before time limit reached');
                break; // Stop trying to get records when we run out.
            }

            // Increment record start position for next iteration.
            $start += $step;

            // We do not want to load all results into memory,
            // we want to write them to a file as we go.
            foreach ($results as $key => $value) {
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
    private function delete_records($recordids) {
        global $DB;

        $chunks = array_chunk($recordids, 1000, true);
        foreach ($chunks as $chunk) {
            $DB->delete_records_list('logstore_standard_log', 'id', $chunk);
        }
    }

    /**
     * Issues a VACUUM on logstore_standard_log to reclaim dead tuple space.
     *
     * Only executed on PostgreSQL and only when the vacuum_after_delete setting
     * is enabled. Called after a successful batch delete to prevent autovacuum
     * from falling behind during aggressive archiving campaigns.
     *
     * @param object $config Plugin config.
     */
    private function vacuum_logstore($config): void {
        global $DB;

        if (empty($config->vacuum_after_delete)) {
            return;
        }

        if ($DB->get_dbfamily() !== 'postgres') {
            return;
        }

        try {
            mtrace('Running VACUUM on logstore_standard_log...');
            $DB->execute('VACUUM {logstore_standard_log}');
            mtrace('VACUUM complete.');
        } catch (\dml_exception $e) {
            mtrace('WARNING: VACUUM on logstore_standard_log failed: ' . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     * @see \core\task\task_base::execute()
     */
    public function execute() {
        $config = get_config('tool_s3logs');

        if (empty($config->enable)) {
            mtrace('Log archive tasks are disabled.');
        } else {
            // Set up basic vars.
            $maxage = 60 * 60 * 24 * 30 * $config->maxlogage; // We standardise on a month having 30 days.
            $stopat = time() + $config->maxruntime;

            // Get a temp file.
            mtrace('Getting temporary file...');
             [$tempfile, $fp] = $this->get_temp_file();

            // Add the table headers to the temp file.
            mtrace('Writing table headers to temporary file...');
            $headerwrite = $this->write_file_headers($fp);
            if (!$headerwrite) {
                throw new \moodle_exception('noheaders', 'tool_s3logs', '');
            }

            // Extract records from DB and add them to the temp file.
            mtrace('Finding records and updating temporary file...');
            $starttime = time();
            $recordids = $this->extract_records($stopat, $maxage, $fp, $config);
            fclose($fp); // Close file now that we have it.
            $elapsedtime = time() - $starttime;

            if (!empty($recordids)) {
                // If file isn't empty upload this file to s3.
                $numrecords = count($recordids);
                $firstrecord = min($recordids);
                $lastrecord = max($recordids);

                $keyname = $config->prefix . '_' . date('YmdHis') . '_' . $firstrecord . '_' . $lastrecord . '.csv';
                mtrace('Extracting records from DB took: ' . $elapsedtime . ' seconds...');
                mtrace('Uploading ' . $numrecords . ' records to S3...');

                $s3client = new s3_client();
                $s3url = $s3client->upload_file($tempfile, $keyname);

                if (!$s3url) {
                    throw new \moodle_exception('s3uploadfailed', 'tool_s3logs', '');
                } else {
                    mtrace('Uploaded file name: ' . $keyname);
                    // Delete the processed records from the log table.
                    mtrace('Deleting ' . $numrecords . ' records from DB...');
                    $this->delete_records($recordids);
                    $this->vacuum_logstore($config);
                }
            } else {
                mtrace('No records found to process, finishing...');
            }
        }
    }
}
