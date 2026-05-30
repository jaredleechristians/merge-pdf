<?php

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

function debug_log_line($message)
{
    file_put_contents(
        __DIR__ . '/debug.log',
        '[' . date('c') . '] ' . $message . PHP_EOL,
        FILE_APPEND
    );
}

function fail($code, $message)
{
    debug_log_line("FAIL {$code}: {$message}");

    http_response_code($code);
    header('Content-Type: text/plain');

    echo $message;
    exit;
}

debug_log_line('Request started');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Only POST requests are allowed');
}

if (!extension_loaded('curl')) {
    fail(500, 'PHP cURL extension is not enabled');
}

if (!class_exists('CURLFile')) {
    fail(500, 'CURLFile class is not available');
}

/*
|--------------------------------------------------------------------------
| Get Gotenberg URL
|--------------------------------------------------------------------------
*/

$gotenbergBaseUrl =
    $_ENV['GOTENBERG_URL']
    ?? $_SERVER['GOTENBERG_URL']
    ?? getenv('GOTENBERG_URL')
    ?? '';

debug_log_line('ENV GOTENBERG_URL=' . ($gotenbergBaseUrl ?: '[EMPTY]'));

if (trim($gotenbergBaseUrl) === '') {
    fail(
        500,
        'GOTENBERG_URL environment variable is not available inside PHP'
    );
}

$gotenbergUrl =
    rtrim($gotenbergBaseUrl, '/')
    . '/forms/pdfengines/merge';

debug_log_line('Gotenberg URL: ' . $gotenbergUrl);

/*
|--------------------------------------------------------------------------
| Validate uploaded files
|--------------------------------------------------------------------------
*/

if (
    !isset($_FILES['files']) ||
    empty($_FILES['files']['tmp_name'])
) {
    fail(400, 'No PDF files uploaded');
}

$files = $_FILES['files'];

if (
    !is_array($files['tmp_name']) ||
    count($files['tmp_name']) < 2
) {
    fail(400, 'Please upload at least two PDF files');
}

/*
|--------------------------------------------------------------------------
| Build CURL payload
|--------------------------------------------------------------------------
*/

$postFields = [];

foreach ($files['tmp_name'] as $i => $tmpName) {

    if (!is_uploaded_file($tmpName)) {
        continue;
    }

    $originalName = basename(
        $files['name'][$i] ?? ('file-' . $i . '.pdf')
    );

    $orderedName =
        str_pad($i + 1, 4, '0', STR_PAD_LEFT)
        . '-'
        . $originalName;

    debug_log_line(
        "Adding file {$orderedName} (" .
        filesize($tmpName) .
        ' bytes)'
    );

    $postFields["files[$i]"] = new CURLFile(
        $tmpName,
        'application/pdf',
        $orderedName
    );
}

if (count($postFields) < 2) {
    fail(400, 'Less than two valid uploaded files found');
}

/*
|--------------------------------------------------------------------------
| Call Gotenberg
|--------------------------------------------------------------------------
*/

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $gotenbergUrl,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_TIMEOUT => 180,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

debug_log_line('Calling Gotenberg');

$response = curl_exec($ch);

$curlErrNo = curl_errno($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

curl_close($ch);

debug_log_line("curl_errno: {$curlErrNo}");
debug_log_line("curl_error: {$curlError}");
debug_log_line("HTTP status: {$httpCode}");
debug_log_line("Content-Type: {$contentType}");
debug_log_line(
    "Response length: " .
    strlen((string)$response)
);

if ($response === false) {
    fail(
        500,
        "cURL failed.\n\nError {$curlErrNo}: {$curlError}"
    );
}

if ($httpCode < 200 || $httpCode >= 300) {
    fail(
        $httpCode ?: 500,
        "Gotenberg returned HTTP {$httpCode}\n\n{$response}"
    );
}

/*
|--------------------------------------------------------------------------
| Verify PDF
|--------------------------------------------------------------------------
*/

if (substr($response, 0, 4) !== '%PDF') {

    fail(
        500,
        "Gotenberg did not return a PDF.\n\n" .
        "Content-Type: {$contentType}\n\n" .
        $response
    );
}

/*
|--------------------------------------------------------------------------
| Return merged PDF
|--------------------------------------------------------------------------
*/

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="merged.pdf"');
header('Content-Length: ' . strlen($response));

echo $response;
exit;