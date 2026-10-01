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
    use \core\task\stored_progress_task_trait;

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
     * Find the timecreated of the oldest log record eligible for archiving.
     *
     * This is a cheap, indexed lookup (ORDER BY timecreated ASC LIMIT 1) used
     * as the starting point of a time-based progress estimate, avoiding the
     * cost of a full COUNT() over the (potentially huge) logstore table.
     *
     * @param int $interval Interval of months in seconds.
     * @param object $config Plugin config.
     * @return ?int Timecreated of the oldest eligible record, or null if there are none.
     */
    private function get_oldest_eligible_time($interval, $config): ?int {
        global $DB;

        $threshold = time() - $interval;
        [$coursefiltersql, $coursefilterparams] = $this->get_course_filter_sql($config);

        $oldest = $DB->get_records_select(
            'logstore_standard_log',
            'timecreated <= ?' . $coursefiltersql,
            array_merge([$threshold], $coursefilterparams),
            'timecreated ASC',
            'id, timecreated',
            0,
            1
        );

        if (empty($oldest)) {
            return null;
        }

        return reset($oldest)->timecreated;
    }

    /**
     * Extract the log records from the db and write
     * to a temporary file.
     *
     * The method is passed an interval of months in seconds,
     * we want to get all records that are older than this
     * number of months.
     *
     * Progress is reported based on the timecreated of the records being
     * processed, relative to the full time range being archived. This avoids
     * needing an expensive record count to know how much work there is to do.
     *
     * @param int $stopat The time to stop process, if there are still records.
     * @param int $interval Interval of months in seconds.
     * @param resource $fp File pointer to temp file to write to.
     * @param object $config Plugin config.
     * @param ?int $rangestart Timecreated of the oldest eligible record, used to report progress. Null to skip reporting.
     * @param ?int $rangeend Timecreated of the newest eligible record (the archive threshold).
     * @return array $recordids the ID's of the log entries written to the file.
     */
    private function extract_records($stopat, $interval, $fp, $config, ?int $rangestart = null, ?int $rangeend = null) {
        global $DB;

        $threshold = time() - $interval;
        $recordids = [];
        $start = 0;
        $limit = 1000;
        $step = 1000;
        $reportprogress = $this->progress !== null && $rangestart !== null && $rangeend !== null;
        $timerange = $reportprogress ? max($rangeend - $rangestart, 1) : 0;

        mtrace('Getting records older than: ' . date('Y-m-d H:i:s', $threshold));

        [$coursefiltersql, $coursefilterparams] = $this->get_course_filter_sql($config);

        // Get 1000 rows of data from the log table order by oldest first.
        // Keep getting records 1000 at a time until we run out of records or max execution time is reached.
        while (time() <= $stopat) {
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
                break; // Stop trying to get records when we run out.
            }

            // Increment record start position for next iteration.
            $start += $step;

            // We do not want to load all results into memory,
            // we want to write them to a file as we go.
            $lasttimecreated = null;
            foreach ($results as $key => $value) {
                $recordids[] = $key;
                fputcsv($fp, (array)$value);
                $lasttimecreated = $value->timecreated;
            }

            // Results are ordered oldest first, so the last record processed in this batch
            // tells us how far through the time range we have got. Extraction is capped at 90%,
            // reserving the remainder for the upload/delete stages that follow it.
            if ($reportprogress && $lasttimecreated !== null) {
                $percent = min(90, max(0, (($lasttimecreated - $rangestart) / $timerange) * 90));
                $this->progress->update_full(
                    $percent,
                    get_string('progress_extracted', 'tool_s3logs', date('Y-m-d H:i:s', $lasttimecreated))
                );
            }
        }

        return $recordids;
    }

    /**
     * Build the S3 object key for an archived batch of records.
     *
     * Normally {prefix}_{YmdHis}_{first}_{last}.csv, but the leading underscore is
     * omitted when no prefix is configured, e.g. {YmdHis}_{first}_{last}.csv.
     *
     * @param string $prefix Configured S3 key prefix, may be empty.
     * @param int $firstrecord ID of the first (oldest) record in the batch.
     * @param int $lastrecord ID of the last (newest) record in the batch.
     * @return string
     */
    private function build_keyname(string $prefix, int $firstrecord, int $lastrecord): string {
        $parts = array_filter(
            [$prefix, date('YmdHis'), $firstrecord, $lastrecord],
            fn($part) => $part !== ''
        );

        return implode('_', $parts) . '.csv';
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
            if ($this->progress !== null) {
                $this->progress->update_full(99, get_string('progress_vacuuming', 'tool_s3logs'));
            } else {
                mtrace('Running VACUUM on logstore_standard_log...');
            }
            $DB->execute('VACUUM {logstore_standard_log}');
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
            $maxage = (int)$config->maxlogage; // Stored in seconds via admin_setting_configduration.
            $stopat = time() + $config->maxruntime;
            $rangeend = time() - $maxage; // The archive threshold ("time we are archiving up to").

            $this->start_stored_progress();
            $this->progress->update_full(0, get_string('progress_finding', 'tool_s3logs'));

            // Find the oldest eligible record's timecreated, so we can report progress based on
            // the time range being archived, rather than an expensive COUNT() of matching records.
            $rangestart = $this->get_oldest_eligible_time($maxage, $config);

            if ($rangestart === null) {
                $this->progress->update_full(100, get_string('progress_norecords', 'tool_s3logs'));
                return;
            }

            $progressrange = (object)[
                'start' => date('Y-m-d H:i:s', $rangestart),
                'end' => date('Y-m-d H:i:s', $rangeend),
            ];
            $this->progress->update_full(0, get_string('progress_archiving', 'tool_s3logs', $progressrange));

            // Get a temp file and write the headers to it.
            [$tempfile, $fp] = $this->get_temp_file();
            $headerwrite = $this->write_file_headers($fp);
            if (!$headerwrite) {
                throw new \moodle_exception('noheaders', 'tool_s3logs', '');
            }

            // Extract records from DB and add them to the temp file.
            $starttime = time();
            $recordids = $this->extract_records($stopat, $maxage, $fp, $config, $rangestart, $rangeend);
            fclose($fp); // Close file now that we have it.
            $elapsedtime = time() - $starttime;

            if (!empty($recordids)) {
                // If file isn't empty upload this file to s3.
                $numrecords = count($recordids);
                $firstrecord = min($recordids);
                $lastrecord = max($recordids);

                $keyname = $this->build_keyname($config->prefix, $firstrecord, $lastrecord);
                $this->progress->update_full(95, get_string('progress_uploading', 'tool_s3logs', (object)[
                    'count' => $numrecords,
                    'elapsed' => $elapsedtime,
                ]));

                $s3client = new s3_client();
                $s3url = $s3client->upload_file($tempfile, $keyname);

                if (!$s3url) {
                    throw new \moodle_exception('s3uploadfailed', 'tool_s3logs', '');
                } else {
                    // Delete the processed records from the log table.
                    $this->progress->update_full(98, get_string('progress_deleting', 'tool_s3logs', $numrecords));
                    $this->delete_records($recordids);
                    $this->vacuum_logstore($config);
                    $this->progress->update_full(100, get_string('progress_archived', 'tool_s3logs', (object)[
                        'count' => $numrecords,
                        'keyname' => $keyname,
                    ]));
                }
            } else {
                $this->progress->update_full(100, get_string('progress_norecords', 'tool_s3logs'));
            }
        }
    }
}
