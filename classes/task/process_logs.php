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
     * Find the bounds of the log records eligible for archiving, used to estimate progress.
     *
     * Records are extracted in ID order (see extract_records()), so the ID of the record
     * processed so far is what actually advances monotonically as the task runs - not its
     * timecreated, which only approximately follows ID order (backdated/imported events,
     * clock skew, etc. can break that assumption, causing erratic/stalling progress if we
     * based percentages on timecreated instead). Using the position within the eligible ID
     * range instead gives a progress percentage that is guaranteed to increase smoothly.
     *
     * Both lookups are cheap, indexed ORDER BY timecreated ... LIMIT 1 queries, avoiding the
     * cost of a full COUNT() over the (potentially huge) logstore table.
     *
     * @param int $interval Interval of months in seconds.
     * @param object $config Plugin config.
     * @return ?object Object with starttime (timecreated of the oldest eligible record, used
     *      for reporting/keynames), startid and endid (ID bounds of the eligible records, used
     *      for progress reporting), or null if there are no eligible records.
     */
    private function get_eligible_record_bounds($interval, $config): ?object {
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

        $newest = $DB->get_records_select(
            'logstore_standard_log',
            'timecreated <= ?' . $coursefiltersql,
            array_merge([$threshold], $coursefilterparams),
            'timecreated DESC',
            'id',
            0,
            1
        );

        $oldestrecord = reset($oldest);
        $newestrecord = reset($newest);

        return (object)[
            'starttime' => (int)$oldestrecord->timecreated,
            'startid' => (int)$oldestrecord->id,
            'endid' => (int)$newestrecord->id,
        ];
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
     * Records are read in ID order, using the ID of the last record read as the
     * cursor for the next query.
     *
     * Progress is reported based on the position of the last processed record's ID within
     * the full range of eligible IDs. Records are extracted in ID order, so this position
     * is guaranteed to increase smoothly as the task runs, unlike the records' timecreated
     * (which only approximately follows ID order, and can jump around or stall the progress
     * bar if backdated/imported events break that assumption). This also avoids needing an
     * expensive record count to know how much work there is to do.
     *
     * @param int $stopat The time to stop process, if there are still records.
     * @param int $interval Interval of months in seconds.
     * @param resource $fp File pointer to temp file to write to.
     * @param object $config Plugin config.
     * @param ?int $startid ID of the oldest eligible record, used to report progress. Null to skip reporting.
     * @param ?int $endid ID of the newest eligible record (at the archive threshold).
     * @return array $recordids the ID's of the log entries written to the file.
     */
    private function extract_records($stopat, $interval, $fp, $config, ?int $startid = null, ?int $endid = null) {
        global $DB;

        $threshold = time() - $interval;
        $recordids = [];
        $lastid = 0;
        $limit = 1000;
        $reportprogress = $this->progress !== null && $startid !== null && $endid !== null;
        $idrange = $reportprogress ? max($endid - $startid, 1) : 0;

        mtrace('Getting records older than: ' . date('Y-m-d H:i:s', $threshold));

        [$coursefiltersql, $coursefilterparams] = $this->get_course_filter_sql($config);

        // Get 1000 rows of data from the log table, ordered by ID so that each query can
        // start where the previous one finished. Using the ID as a cursor keeps the cost of
        // every query the same. An increasing OFFSET instead makes the database re-read and
        // discard all of the records already processed, so the task gets progressively
        // slower the longer it runs - and because nothing is deleted until the end of the
        // run, the rows being skipped are still there to be skipped again.
        // Keep getting records 1000 at a time until we run out of records or max execution time is reached.
        while (time() <= $stopat) {
            if (self::has_memory_exceeded()) {
                $memlimitthr = round(self::MEMORY_LIMIT_THRESHOLD * 100, 0);
                mtrace("Memory limit threshold of {$memlimitthr}% reached, stopping processing to avoid an out-of-memory error");
                break;
            }

            $records = $DB->get_recordset_select(
                'logstore_standard_log',
                'id > ? AND timecreated <= ?' . $coursefiltersql,
                array_merge([$lastid, $threshold], $coursefilterparams),
                'id ASC',
                '*',
                0,
                $limit
            );

            // We do not want to load all results into memory,
            // we want to write them to a file as we go.
            $count = 0;
            $lasttimecreated = null;
            foreach ($records as $record) {
                $recordids[] = $record->id;
                $lastid = $record->id;
                fputcsv($fp, (array)$record);
                $lasttimecreated = $record->timecreated;
                $count++;
            }
            $records->close();

            // The ID of the last record processed tells us how far through the eligible ID
            // range we have got, which (since we extract in ID order) increases smoothly and
            // monotonically as the task runs. Extraction is capped at 90%, reserving the
            // remainder for the upload/delete stages that follow it.
            if ($reportprogress && $lasttimecreated !== null) {
                $percent = min(90, max(0, (($lastid - $startid) / $idrange) * 90));
                $this->progress->update_full(
                    $percent,
                    get_string('progress_extracted', 'tool_s3logs', date('Y-m-d H:i:s', $lasttimecreated))
                );
            }

            if ($count < $limit) {
                break; // Stop trying to get records when we run out.
            }
        }

        return $recordids;
    }

    /**
     * Build the S3 object key for an archived batch of records.
     *
     * Normally {prefix}_{date}_{first}_{last}.csv, but the leading underscore is
     * omitted when no prefix is configured, e.g. {date}_{first}_{last}.csv. The date
     * is in ISO 8601 date format (Y-m-d), and is the timecreated of the earliest
     * (oldest) record in the batch, not the time the task ran. The time-of-day
     * component is omitted (events are batched by day, and it avoids colons in the key).
     *
     * @param string $prefix Configured S3 key prefix, may be empty.
     * @param int $earliesttimecreated Timecreated of the earliest (oldest) record in the batch.
     * @param int $firstrecord ID of the first (oldest) record in the batch.
     * @param int $lastrecord ID of the last (newest) record in the batch.
     * @return string
     */
    private function build_keyname(string $prefix, int $earliesttimecreated, int $firstrecord, int $lastrecord): string {
        $parts = array_filter(
            [$prefix, date('Y-m-d', $earliesttimecreated), $firstrecord, $lastrecord],
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

            // Find the bounds of the eligible records (by ID, for progress reporting, and by
            // timecreated, for the keyname/status messages), rather than running an expensive
            // COUNT() of matching records.
            $bounds = $this->get_eligible_record_bounds($maxage, $config);

            if ($bounds === null) {
                $this->progress->update_full(100, get_string('progress_norecords', 'tool_s3logs'));
                return;
            }
            $rangestart = $bounds->starttime;

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
            $recordids = $this->extract_records($stopat, $maxage, $fp, $config, $bounds->startid, $bounds->endid);
            fclose($fp); // Close file now that we have it.
            $elapsedtime = time() - $starttime;

            if (!empty($recordids)) {
                // If file isn't empty upload this file to s3.
                $numrecords = count($recordids);
                $firstrecord = min($recordids);
                $lastrecord = max($recordids);

                $keyname = $this->build_keyname($config->prefix, $rangestart, $firstrecord, $lastrecord);
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
