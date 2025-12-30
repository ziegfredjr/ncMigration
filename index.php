<?php
    require 'vendor/autoload.php';
    require 'config/db.php';

    use Google\Cloud\Storage\StorageClient;

    class GCSMigration {
        private $db;
        private $bucketName = 'nc_webrtc_recording';
        private $skywayPath = '78974d5f-55a8-4469-85a8-e81002001b05';
        private $batchSize = 500;
        private $token;
        public $message;
        public $startDate;
        public $endDate;
        
        public function __construct($db) {
            $this->db = $db;
            $this->token = '';
            $this->message = 'Active execution';
        }
        
        /**
         * Run the migration process
         */
        public function run() {            
            try {
                if (!$this->db || $this->db->connect_errno) {
                    $this->message = "Database connection failed!";
                    $this->logMessage($this->message . ": " . $this->db->connect_error, "error");
                    return;
                }

                // - raw query
                $sql = "
                    SELECT 
                        `id`,
                        `recording_id`,
                        `chat_hash`, 
                        `is_new_skyway`,
                        `audio_path`,
                        `skyway_channel_id`,
                        `user_id`
                    FROM 
                        `lesson_audio_files`
                    WHERE 
                        `deleted_flg` = 1 AND
                        `deleted_flg_physical` = 0
                    ORDER BY `id` ASC LIMIT ?;
                ";

                //- Prepare and execute statement
                $stmt = $this->db->prepare($sql);
                $stmt->bind_param("i", $this->batchSize);
                $stmt->execute();
                $result = $stmt->get_result();

                $recordings = [];
                while ($row = $result->fetch_assoc()) {
                    $recordings[] = $row;
                }
                $stmt->close();

                if (!empty($recordings)) {
                    $deleteFile = $this->processData($recordings);
                    $this->updateDeletedRecords($deleteFile);
                    $this->redirect();
                } else {
                    $this->message = "No more records to process.";
                    $this->logMessage($this->message, "info");
                }
            } catch (Exception $e) {
                $this->logMessage("Error in run: " . $e->getMessage(), "error");
            }
        }
        
        /**
         * Get Google Cloud Access Token
         * @return string
         */
        private function getToken() {
            $tokenFile = __DIR__ . '/gcloudAccessToken/gcloudToken.php';
            if (file_exists($tokenFile)) {
                ob_start();
                include($tokenFile);
                return trim(str_replace("Access Token: ", "", ob_get_clean()));
            }
            throw new Exception("Token file not found!");
        }
        
        /**
         * Process data and delete files from GCS
         * @param array $recordings
         * @return array status, recording_ids
         */
        private function processData($recordings) {
            try {
                //- validate recordings
                if (empty($recordings)) {
                    return ['success' => false, 'message' => 'No recordings to process.'];
                }

                //-- Get Access Token
                $this->token = $this->getToken();

                //- Prepare delete script
                $filename = 'bin/delete_skyway_command.sh';
                file_put_contents($filename, "#!/bin/bash\nbash <<EOF\n");

                $recordingIds = [];

                foreach ($recordings as $recordItem) {
                    $isNewSkyway = $recordItem['is_new_skyway'] ?? 0;
                    $file = $recordItem['recording_id'] ?? '';
                    $recordingIds[] = $recordItem['id'];

                    //- Skip if file is empty
                    if (empty($file)) {
                        continue;
                    }

                    $oldSkywayPath = $this->skywayPath . '/' . $file . '/audio.ogg';
                    $objectName = $isNewSkyway ? $recordItem['audio_path'] : $oldSkywayPath;

                    //- Skip if object name is empty
                    if (empty($objectName)) continue;

                    $commandString = "curl -X DELETE -H \"Authorization: Bearer {$this->token}\" \"https://storage.googleapis.com/{$this->bucketName}/{$objectName}\" >> logs/process.log 2>&1 &\r\n";

                    //- Append command to script file
                    file_put_contents($filename, $commandString, FILE_APPEND);

                    //- Also delete metadata file
                    $commandStringMeta = str_replace(['.ogg', '.webm'], '.json', $commandString);
                    file_put_contents($filename, $commandStringMeta, FILE_APPEND);
                }

                //- Finalize script
                file_put_contents($filename, "EOF\n", FILE_APPEND);
                //- Execute script
                exec("./$filename");

                return [
                    'success' => true,
                    'recording_ids' => $recordingIds,
                    'message' => 'Delete commands executed.'
                ];
            } catch (Exception $e) {
                $this->logMessage("Error in processData: " . $e->getMessage(), "error");
            }
        }

        /**
         * Update records as physically deleted
         */
        private function updateDeletedRecords($params = []) {
            $this->recordingIds = $recordingIds = $params['recording_ids'] ?? [];

            //- Update database records
            if (!empty($recordingIds)) {
                $placeholders = implode(',', array_fill(0, count($recordingIds), '?'));
                $sql = "UPDATE `lesson_audio_files` SET `deleted_flg_physical` = 1 WHERE `id` IN ($placeholders)";
                $stmt = $this->db->prepare($sql);
                $types = str_repeat('i', count($recordingIds));
                $stmt->bind_param($types, ...$recordingIds);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        private function logMessage($message, $path = "debug") {
            $logDir = __DIR__ . "/logs/";
            if (!is_dir($logDir)) mkdir($logDir, 0777, true);
            
            $logFile = $logDir . $path . ".log";
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
        }
        
        private function redirect() {
            echo "<script>
                setTimeout(function() {
                    window.location.href = 'index.php';
                }, 3000);
            </script>";
        }
    }

    $monthDate = $_GET['date'] ?? "2022-09-01";
    $migration = new GCSMigration($db);
    $migration->run($monthDate);
?>

<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Google Cloud Storage Files</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ccc; border-radius: 5px; box-shadow: 2px 2px 10px rgba(0, 0, 0, 0.1); }
            h2 { text-align: center; }
            .date, .status { font-weight: bold; word-break: break-word; }
            .date { color: green; }
            .status { color: blue; }
        </style>
    </head>
    <body>
        <div class="container">
            <h2>Local Migration</h2>
            <?php if (!empty($migration->recordingIds)): ?>
                <p><strong>Processing Data ID's:</strong> <span class="date"><?php echo htmlspecialchars(implode(', ', $migration->recordingIds), ENT_QUOTES, 'UTF-8'); ?></span></p>
            <?php endif; ?>
            <p><strong>Status:</strong> <span class="status"><?php echo htmlspecialchars($migration->message, ENT_QUOTES, 'UTF-8'); ?></span></p>
        </div>
    </body>
</html>
