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
        private $oldSkywayData = true; // fetch old data first
        public $continue = true;
        
        public function __construct($db) {
            $this->db = $db;
            $this->label = 'Fetching recordings to delete';
            $this->message = 'Active execution';
        }
        
        public function run() {
            try {
                if (!$this->db || $this->db->connect_errno) {
                    $this->message = "Database connection failed!";
                    $this->logMessage($this->message . ": " . $this->db->connect_error, "error");
                    return;
                }

                while(true) {
                    //- fetch old skyway data first
                    if ($this->oldSkywayData) {
                        $oldRecordings = [];
                        $stmt = $this->db->prepare("
                            SELECT 
                                `is_new_skyway`,
                                `recording_id`
                            FROM `lesson_audio_files` 
                            WHERE `deleted_flg` = 1
                                AND `deleted_flg_physical` = 0
                                AND `deleted_locally` = 0
                                AND `is_new_skyway` = 0
                            GROUP BY `recording_id`
                            ORDER BY `id` ASC LIMIT ?;
                        ");
                    } else {
                        $stmt = $this->db->prepare("
                            SELECT 
                                `is_new_skyway`,
                                `skyway_channel_id`
                            FROM `lesson_audio_files`
                            WHERE `deleted_flg` = 1
                                AND `deleted_flg_physical` = 0
                                AND `deleted_locally` = 0
                                AND `is_new_skyway` = 1
                            GROUP BY `skyway_channel_id`
                            ORDER BY `id` ASC LIMIT ?;
                        ");
                    }

                    $stmt->bind_param("i", $this->batchSize);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows == 0) {
                        if ($this->oldSkywayData) {
                            $this->oldSkywayData = false;
                            $stmt->close();
                            continue; // Retry with new skyway data
                        } else {
                            $this->message = "All eligible recordings have been processed.";
                            break;
                        }
                    }

                    $recordings = $result->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    
                    if (!empty($recordings)) {
                        $this->message = "Deleting recordings count : " . count($recordings) . "<br>";
                        $deleteFile = $this->processData($recordings);
                        $recordingIds = !empty($deleteFile['recording_ids']) ? $deleteFile['recording_ids'] : [];
                        $skywayChannelIds = !empty($deleteFile['skyway_channel_ids']) ? $deleteFile['skyway_channel_ids'] : [];

                        //- update old skyway recording
                        if (!empty($recordingIds) && is_array($recordingIds)) {
                            $idsPlaceholders = implode(',', array_fill(0, count($recordingIds), '?'));
                            $types = str_repeat('s', count($recordingIds));
                            $stmtUpdate = $this->db->prepare("
                                UPDATE `lesson_audio_files` 
                                SET `deleted_locally` = 1
                                WHERE `recording_id` IN ($idsPlaceholders);
                            ");
                            $stmtUpdate->bind_param($types, ...$recordingIds);
                            $stmtUpdate->execute();
                            $stmtUpdate->close();
                        }
                        
                        if (!empty($skywayChannelIds) && is_array($skywayChannelIds)) {
                            $idsPlaceholders = implode(',', array_fill(0, count($skywayChannelIds), '?'));
                            $types = str_repeat('s', count($skywayChannelIds));
                            $stmtUpdate = $this->db->prepare("
                                UPDATE `lesson_audio_files` 
                                SET `deleted_locally` = 1
                                WHERE `skyway_channel_id` IN ($idsPlaceholders);
                            ");
                            $stmtUpdate->bind_param($types, ...$skywayChannelIds);
                            $stmtUpdate->execute();
                            $stmtUpdate->close();
                        }
                        //- redirect next batch
                        $this->redirect();
                    } else {
                        $this->message = "No recordings found to process.";
                        $this->continue = false;
                        break;
                    }
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
            if (empty($recordings)) {
                return ['success' => false, 'message' => 'No recordings to process.'];
            }

            $ACCESS_TOKEN = $this->getToken();

            // Set environment variable for this PHP process
            putenv("CLOUDSDK_AUTH_ACCESS_TOKEN={$ACCESS_TOKEN}");
            putenv("CLOUDSDK_CORE_DISABLE_PROMPTS=1");
            putenv("CLOUDSDK_STORAGE_PARALLEL_PROCESS_COUNT=16");
            putenv("CLOUDSDK_STORAGE_THREAD_COUNT=16");
            
            // Path to your shell script
            $filename = 'bin/delete_skyway_command.sh';
            file_put_contents($filename, "#!/bin/bash\nbash <<EOF\n");

            $recordingIds = [];
            $skywayChannelIds = [];

            $gcloud = $this->detectGCloud();

            $command = sprintf(
                "%s storage rm --recursive \\\n",
                escapeshellcmd($gcloud)
            );

            file_put_contents($filename, $command, FILE_APPEND);       
            
            foreach ($recordings as $recordItem) {
                //- Skip if empty record
                if (empty($recordItem)) {
                    continue;
                }

                // Get recording id
                $recordingId = !empty($recordItem['recording_id']) ? $recordItem['recording_id'] : '';
                $skywayChannelId = !empty($recordItem['skyway_channel_id']) ? $recordItem['skyway_channel_id'] : '';
                $isNewSkyway = !empty($recordItem['is_new_skyway']);

                if ($isNewSkyway) {
                    if (empty($skywayChannelId)) continue;
                    $skywayChannelIds[] = $skywayChannelId;
                    $objectName = 'gs://' . $this->bucketName . '/' . $skywayChannelId;
                } else {
                    if (empty($recordingId)) continue;
                    $recordingIds[] = $recordingId;
                    $objectName = 'gs://' . $this->bucketName . '/' . $this->skywayPath . '/' . $recordingId;
                }

                $commandString = sprintf(
                    "%s \\\n",
                    escapeshellarg($objectName)
                );

                file_put_contents($filename, $commandString, FILE_APPEND);
            }
            
            file_put_contents($filename, "EOF", FILE_APPEND); 

            exec("./$filename", $output, $returnVar);

            $outputLog = implode("\n", $output);
            
            return [
                'success' => true,
                'output' => $outputLog,
                'status' => $returnVar,
                'total_processed' => count($recordings),
                'recording_ids' => $recordingIds,
                'skyway_channel_ids' => $skywayChannelIds
            ];
        }
        
        private function logMessage($message, $path = "debug") {
            $logDir = __DIR__ . "/logs/";
            if (!is_dir($logDir)) mkdir($logDir, 0777, true);
            
            $logFile = $logDir . $path . ".log";
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
        }

        /**
         * Detect the gcloud binary path
         * @return string
         */
        private function detectGCloud(): string {
            $commands = [
                'gcloud', // Default PATH
                '/usr/bin/gcloud', // Common Linux path
                '/nix/orb/data/.env-out/bin/gcloud', //  Docker OrbStackExpand commentComment on line R603ResolvedCode has comments. Press enter to view.
                '/opt/homebrew/bin/gcloud', // macOS Apple Silicon
                '/usr/local/bin/gcloud', // macOS Intel
            ];

            foreach ($commands as $bin) {
                if (is_executable($bin)) {
                    return $bin;
                }
            }

            return 'gcloud'; // fallback to PATH
        }
        
        private function redirect() {
            echo "<script>
                    window.addEventListener('load', function() {   
                        setTimeout(function() {
                            window.location.href = 'index.php';
                        }, 3000);
                    });
                </script>";
        }
    }
    $migration = new GCSMigration($db);
    $migration->run();
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
            .label, .status { font-weight: bold; word-break: break-word; }
            .label { color: green; }
            .status { color: blue; }
        </style>
    </head>
    <body>
        <div class="container">
            <h2>Local Migration</h2>
            <p><span class="label"><?php echo $migration->label; ?></span></p>
            <p><strong>Status:</strong> <span class="status"><?php echo htmlspecialchars($migration->message, ENT_QUOTES, 'UTF-8'); ?></span></p>
        </div>
    </body>
</html>
