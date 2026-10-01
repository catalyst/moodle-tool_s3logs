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
    /**
     * Number of records to read from the database per query.
     */
    const BATCH_SIZE = 1000;

    /**
     * Number of records to write to each archive file, if not configured.
     */
    const DEFAULT_CHUNK_SIZE = 100000;

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
     * The file is written through the zlib stream wrapper, so the CSV is gzipped
     * as it is generated. Log records are highly repetitive and typically
     * compress by an order of magnitude, which cuts the temp disk space, the
     * upload time and the ongoing S3 storage cost by about the same factor.
     *
     * @return array File name and file pointer.
     */
    private function get_temp_file() {
        $tempdir = make_temp_directory('s3logs_upload');
        $tempfile = tempnam($tempdir, 's3logs_');
        $fp = fopen('compress.zlib://' . $tempfile, 'w');

        return [$tempfile, $fp];
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
     * Extract a chunk of log records from the db and write
     * to a temporary file.
     *
     * Only records older than the threshold and with an ID greater than
     * the given cursor are considered, so that each call carries on from
     * where the previous one finished.
     *
     * @param int $threshold Archive records created at or before this time.
     * @param int $lastid Only consider records with an ID greater than this.
     * @param int $chunksize Maximum number of records to write to the file.
     * @param int $stopat The time to stop process, if there are still records.
     * @param resource $fp File pointer to temp file to write to.
     * @return array The IDs of the log entries written to the file, in ascending order.
     */
    private function extract_records($threshold, $lastid, $chunksize, $stopat, $fp) {
        global $DB;

        $recordids = [];

        // Get 1000 rows of data from the log table, ordered by ID so that each query
        // can start where the previous one finished. Using the ID as a cursor keeps the
        // cost of every query the same. An increasing OFFSET instead makes the database
        // re-read and discard all of the records already processed, so the task gets
        // progressively slower the longer it runs.
        // Keep getting records 1000 at a time until the chunk is full, we run out of
        // records, or max execution time is reached.
        while (count($recordids) < $chunksize && time() <= $stopat) {
            $limit = min(self::BATCH_SIZE, $chunksize - count($recordids));
            $records = $DB->get_recordset_select(
                'logstore_standard_log',
                'id > :lastid AND timecreated <= :threshold',
                ['lastid' => $lastid, 'threshold' => $threshold],
                'id ASC',
                '*',
                0,
                $limit
            );

            // We do not want to load all results into memory,
            // we want to write them to a file as we go.
            $count = 0;
            foreach ($records as $record) {
                $recordids[] = $record->id;
                $lastid = $record->id;
                fputcsv($fp, (array)$record);
                $count++;
            }
            $records->close();

            if ($count < $limit) {
                break; // Stop trying to get records when we run out.
            }
        }

        return $recordids;
    }

    /**
     * Deletes the archived rows from the log store table.
     *
     * Delete the IDs that were actually written to the archive. Do not be tempted
     * to replace this with a range delete over the first and last ID of the chunk:
     * the extract can skip records inside that range - a course ID filter, for
     * instance - and a range delete would then remove records that were never
     * archived. Holding one chunk worth of IDs is what makes this affordable.
     *
     * @param array $recordids Array of record ID's to delete
     */
    private function delete_records($recordids) {
        global $DB;

        $chunks = array_chunk($recordids, self::BATCH_SIZE);
        foreach ($chunks as $chunk) {
            $DB->delete_records_list('logstore_standard_log', 'id', $chunk);
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
            return;
        }

        // Set up basic vars.
        $maxage = 60 * 60 * 24 * 30 * $config->maxlogage; // We standardise on a month having 30 days.
        $stopat = time() + $config->maxruntime;
        $threshold = time() - $maxage;
        $chunksize = empty($config->chunksize) ? self::DEFAULT_CHUNK_SIZE : (int)$config->chunksize;
        $s3client = new s3_client();
        $lastid = 0;
        $total = 0;

        mtrace('Getting records older than: ' . date('Y-m-d H:i:s', $threshold));

        // Archive the records in chunks. Each chunk gets its own file, which is
        // uploaded and then deleted from the log table before the next chunk is read.
        // Processing the whole run as a single chunk instead meant holding the ID of
        // every record archived over the whole run in memory and building an
        // arbitrarily large temp file, and a run that died near the end threw away all
        // of the work it had done.
        while (time() <= $stopat) {
            // Get a temp file.
            mtrace('Getting temporary file...');
            [$tempfile, $fp] = $this->get_temp_file();

            // Add the table headers to the temp file.
            mtrace('Writing table headers to temporary file...');
            $headerwrite = $this->write_file_headers($fp);
            if (!$headerwrite) {
                fclose($fp);
                unlink($tempfile);
                throw new \moodle_exception('noheaders', 'tool_s3logs', '');
            }

            // Extract records from DB and add them to the temp file.
            mtrace('Finding records and updating temporary file...');
            $starttime = time();
            $recordids = $this->extract_records($threshold, $lastid, $chunksize, $stopat, $fp);
            fclose($fp); // Close file now that we have it.
            $elapsedtime = time() - $starttime;

            if (empty($recordids)) {
                unlink($tempfile);
                mtrace('No more records found to process, finishing...');
                break;
            }

            // The records are extracted in ascending ID order, so the first and last
            // of them are the lowest and highest ID in the chunk.
            $numrecords = count($recordids);
            $firstrecord = reset($recordids);
            $lastrecord = end($recordids);

            $keyname = $config->prefix . '_' . date('YmdHis') . '_' . $firstrecord . '_' . $lastrecord . '.csv.gz';
            mtrace('Extracting ' . $numrecords . ' records from DB took: ' . $elapsedtime . ' seconds...');
            mtrace('Uploading ' . $numrecords . ' records to S3...');

            try {
                $s3url = $s3client->upload_file($tempfile, $keyname, 'application/gzip');
            } finally {
                unlink($tempfile);
            }

            if (!$s3url) {
                throw new \moodle_exception('s3uploadfailed', 'tool_s3logs', '');
            }

            mtrace('Uploaded file name: ' . $keyname);

            // Delete the processed records from the log table.
            mtrace('Deleting ' . $numrecords . ' records from DB...');
            $this->delete_records($recordids);

            // Carry the cursor over so the next chunk starts where this one finished.
            $lastid = $lastrecord;
            $total += $numrecords;
        }

        mtrace('Archived ' . $total . ' records in total.');
    }
}
