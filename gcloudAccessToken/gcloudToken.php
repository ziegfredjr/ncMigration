<?php
    require 'vendor/autoload.php'; // Load Google Cloud SDK

    use Google\Auth\ApplicationDefaultCredentials;
    use GuzzleHttp\Client;
    use GuzzleHttp\HandlerStack;

    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    try {
        // Set up authentication
        putenv('GOOGLE_APPLICATION_CREDENTIALS=/Applications/XAMPP/xamppfiles/htdocs/ncMigration/config/google-storage/credentials/nativecamp-91104-a63ba5eecd51.json');

        // Create an HTTP client with authentication
        $client = new Client([
            'handler' => HandlerStack::create(ApplicationDefaultCredentials::getMiddleware(['https://www.googleapis.com/auth/cloud-platform'])),
        ]);

        // Fetch an access token
        $credentials = ApplicationDefaultCredentials::getCredentials(['https://www.googleapis.com/auth/cloud-platform']);
        $token = $credentials->fetchAuthToken();
        
        echo "Access Token: " . $token['access_token'];

    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
?>
