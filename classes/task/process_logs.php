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
     * @var ?string Early exit reason (memory/time/interrupt), set by extract_records(); mtraced
     * after the progress bar so it doesn't mess up the bar's output.
     */
    private ?string $earlyexitmessage = null;

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
        // Use a per-request, node-local directory (not $CFG->tempdir, which is shared storage)
        // since this file never needs to be seen by other nodes, and is cleaned up automatically
        // on shutdown even if this task does not complete normally.
        $tempdir = make_request_directory();
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
        // Explicitly pass the (previously implicit) default escape character to avoid the
        // PHP 8.4+ deprecation notice about its default value changing in a future version.
        $result = fputcsv($fp, $headers, escape: '\\');

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
     * Current memory usage as a fraction of the configured PHP memory limit.
     *
     * @return ?float Usage / limit (e.g. 0.42 for 42%), or null if there is no usable limit
     *      to compare against (unlimited, or an invalid/unparsable memory_limit value).
     */
    private static function get_memory_usage_ratio(): ?float {
        $memlimit = ini_get('memory_limit');

        if ($memlimit === false || $memlimit === '-1' || $memlimit === '') {
            // No memory limit.
            return null;
        }

        $reallimit = get_real_size($memlimit);
        if ($reallimit <= 0) {
            // Invalid or unusable configured limit.
            return null;
        }

        return memory_get_usage(true) / $reallimit;
    }

    /**
     * Determines whether the memory usage for self::extract_records() has exceeded the defined threshold.
     *
     * @return bool Returns true when memory usage reaches self::MEMORY_LIMIT_THRESHOLD of memory limit; otherwise false.
     */
    public static function has_memory_exceeded(): bool {
        $ratio = self::get_memory_usage_ratio();

        return $ratio !== null && $ratio >= self::MEMORY_LIMIT_THRESHOLD;
    }

    /**
     * Current memory usage, formatted for display in progress/debug text.
     *
     * Shown as a percentage of the configured memory_limit where one is set (matching what
     * self::has_memory_exceeded() checks against), falling back to an absolute size when
     * there is no usable limit to express it as a percentage of.
     *
     * @return string e.g. "42.3%" or, when unlimited/unparsable, an absolute size e.g. "128MB".
     */
    private static function get_memory_usage_display(): string {
        $ratio = self::get_memory_usage_ratio();

        if ($ratio === null) {
            return display_size(memory_get_usage(true));
        }

        return round($ratio * 100, 1) . '%';
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
        global $DB, $CFG;

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
                // Defer the mtrace until after the progress bar (in execute()) rather than
                // printing it here, so it doesn't mess up the progress bar's output.
                $this->earlyexitmessage = "Memory limit threshold of {$memlimitthr}% reached, "
                    . "stopping processing to avoid an out-of-memory error";
                break;
            }

            if (\core\local\cli\shutdown::should_gracefully_exit()) {
                // Defer the mtrace until after the progress bar (in execute()) rather than
                // printing it here, so it doesn't mess up the progress bar's output.
                $this->earlyexitmessage = 'Interrupt signal received, stopping processing for this run';
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
                // Explicitly pass the (previously implicit) default escape character to avoid
                // the PHP 8.4+ deprecation notice about its default value changing in future.
                fputcsv($fp, (array)$record, escape: '\\');
                $lasttimecreated = $record->timecreated;
                $count++;
            }
            $records->close();

            // The ID of the last record processed tells us how far through the eligible ID
            // range we have got, which (since we extract in ID order) increases smoothly and
            // monotonically as the task runs. The upload/delete steps that follow extraction
            // happen outside the progress bar (plain mtrace), so extraction runs the bar to 100%.
            if ($reportprogress && $lasttimecreated !== null) {
                $percent = min(100, max(0, (($lastid - $startid) / $idrange) * 100));
                $progresstext = get_string('progress_extracted', 'tool_s3logs', date('Y-m-d H:i:s', $lasttimecreated));

                if (!empty($CFG->debugdeveloper)) {
                    // Debugging only: suffix the progress text (rather than a separate mtrace)
                    // with memory usage, so it doesn't garble the progress bar's own output.
                    $progresstext .= ' (' . get_string('progress_memory', 'tool_s3logs', self::get_memory_usage_display()) . ')';
                }

                $this->progress->update_full($percent, $progresstext);
            }

            if ($count < $limit) {
                break; // Stop trying to get records when we run out.
            }
        }

        if ($this->earlyexitmessage === null && time() > $stopat) {
            // Defer the mtrace until after the progress bar (in execute()) rather than
            // printing it here, so it doesn't mess up the progress bar's output.
            $this->earlyexitmessage = 'WARNING: Maximum run time of ' . $config->maxruntime
                . 's reached, stopping processing for this run';
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
            mtrace(get_string('progress_vacuuming', 'tool_s3logs'));
            $starttime = microtime(true);
            $DB->execute('VACUUM {logstore_standard_log}');
            mtrace(get_string('progress_vacuumed', 'tool_s3logs', sprintf('%.1f', microtime(true) - $starttime)));
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

            // Find the bounds of the eligible records (by ID, for progress reporting, and by
            // timecreated, for the keyname/status messages), rather than running an expensive
            // COUNT() of matching records.
            mtrace(get_string('progress_finding', 'tool_s3logs'));
            $bounds = $this->get_eligible_record_bounds($maxage, $config);

            if ($bounds === null) {
                mtrace(get_string('progress_norecords', 'tool_s3logs'));
                return;
            }
            $rangestart = $bounds->starttime;

            $this->start_stored_progress();

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

            try {
                // Extract records from DB and add them to the temp file.
                $starttime = microtime(true);
                $recordids = $this->extract_records($stopat, $maxage, $fp, $config, $bounds->startid, $bounds->endid);
                fclose($fp); // Close file now that we have it.

                // Deferred from extract_records() til immediately after the progress bar, so
                // it doesn't mess up its output, but before anything else happens.
                if ($this->earlyexitmessage !== null) {
                    mtrace($this->earlyexitmessage);
                }

                if (!empty($recordids)) {
                    $numrecords = count($recordids);
                    $firstrecord = min($recordids);
                    $lastrecord = max($recordids);
                    $keyname = $this->build_keyname($config->prefix, $rangestart, $firstrecord, $lastrecord);

                    mtrace(get_string('progress_extraction_complete', 'tool_s3logs', (object)[
                        'count' => $numrecords,
                        'tempfile' => $tempfile,
                        'elapsed' => sprintf('%.1f', microtime(true) - $starttime),
                    ]));

                    $starttime = microtime(true);
                    $s3client = new s3_client();
                    $s3url = $s3client->upload_file($tempfile, $keyname);

                    if (!$s3url) {
                        throw new \moodle_exception('s3uploadfailed', 'tool_s3logs', '');
                    } else {
                        mtrace(get_string('progress_uploaded', 'tool_s3logs', (object)[
                            'count' => $numrecords,
                            'keyname' => $keyname,
                            'elapsed' => sprintf('%.1f', microtime(true) - $starttime),
                        ]));

                        // Delete the processed records from the log table.
                        $starttime = microtime(true);
                        $this->delete_records($recordids);
                        mtrace(get_string('progress_deleted', 'tool_s3logs', (object)[
                            'count' => $numrecords,
                            'elapsed' => sprintf('%.1f', microtime(true) - $starttime),
                        ]));

                        $this->vacuum_logstore($config);
                    }
                } else {
                    $this->progress->update_full(100, get_string('progress_norecords', 'tool_s3logs'));
                }
            } finally {
                if (file_exists($tempfile)) {
                    unlink($tempfile);
                }
            }
        }
    }
}
