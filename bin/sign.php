<?php

function logMessage($message)
{
    $logFile = '/tmp/signature_process.log';
    $timestamp = date('[Y-m-d H:i:s]');
    file_put_contents($logFile, "$timestamp $message\n", FILE_APPEND);
}

function getAvailableCertificates()
{
    // Получаем список сертификатов
    $command = 'certmgr -list';
    exec($command, $output, $return_var);

    if ($return_var !== 0) {
        logMessage('Ошибка получения сертификатов: ' . implode("\n", $output));
        return [];
    }

    $certificates = [];
    foreach ($output as $line) {
        // Ищем строку с SHA1 Thumbprint и извлекаем отпечаток
        if (preg_match('/SHA1 Thumbprint\s+:\s+([a-fA-F0-9]+)/', $line, $matches)) {
            $thumbprint = trim($matches[1]); // Отпечаток сертификата

            if (!in_array($thumbprint, $certificates)) {
                $certificates[] = $thumbprint;
            }
        }
    }
    return $certificates;
}

function signPdf($inputFile, $outputFile, $thumbprint)
{
    $inputStream = tempnam(sys_get_temp_dir(), 'input_');
    file_put_contents($inputStream, "Y\n");

    $command = "echo Y | cryptcp -sign \"$inputFile\" \"$outputFile\" -thumbprint $thumbprint";

    exec($command . " < $inputStream", $output, $return_var);

    unlink($inputStream);

    return [$return_var, $output];
}

function cleanupOldFiles($dir, $days)
{
    $files = glob("$dir/*");
    $now = time();

    foreach ($files as $file) {
        // Если файл существует и его возраст больше указанного количества дней
        if (is_file($file) && ($now - filemtime($file) >= $days * 86400)) {
            unlink($file); // Удаляем файл
            logMessage("Удален старый файл: $file");
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    logMessage('Получен POST-запрос для подписи');

    if (pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION) !== 'pdf') {
        logMessage('Попытка загрузки неверного формата файла: ' . $_FILES['file']['name']);
        echo json_encode(['status' => 'error', 'message' => 'Только PDF-файлы разрешены.']);
        http_response_code(400);
        exit;
    }

    $originalFileName = $_FILES['file']['name'];
    $safeFileName = preg_replace('/[^a-zA-Z0-9_.-]/u', '_', $originalFileName);
    $inputFile = '/tmp/' . uniqid() . '_' . $safeFileName;
    $outputFile = '/tmp/' . uniqid() . '_' . preg_replace('/\.pdf$/', '.sig', $safeFileName);

    // Сохраняем загруженный файл
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $inputFile)) {
        $error = error_get_last();
        logMessage('Ошибка при сохранении файла: ' . $_FILES['file']['name'] . ' | Ошибка: ' . $error['message']);
        echo json_encode(['status' => 'error', 'message' => 'Ошибка при сохранении файла.']);
        http_response_code(500);
        exit;
    }

    // Очищаем старые файлы, старше 7 дней
    cleanupOldFiles('/tmp', 7);

    // Получаем доступные сертификаты
    $certificates = getAvailableCertificates();

    if (empty($certificates)) {
        logMessage('Нет доступных сертификатов для подписи.');
        echo json_encode(['status' => 'error', 'message' => 'Нет доступных сертификатов для подписи.']);
        http_response_code(500);
        exit;
    }

    logMessage('Доступные сертификаты: ' . implode(', ', $certificates));

    $success = false;
    $errorOutput = [];

    foreach ($certificates as $certificate) {
        $filteredCerts = array_filter($certificates, function ($c) use ($certificate) {
            return $c === $certificate;
        });

        if (count($filteredCerts) === 1) {
            logMessage('Попытка подписи с сертификатом: ' . $certificate);
            list($return_var, $output) = signPdf($inputFile, $outputFile, $certificate);
            if ($return_var === 0) {
                logMessage('Подпись выполнена успешно с сертификатом: ' . $certificate);
                $success = true;
                break;
            } else {
                $errorOutput[] = 'Ошибка при подписи с сертификатом ' . $certificate . ': ' . implode("\n", $output);
                logMessage('Ошибка при подписи с сертификатом ' . $certificate . ': ' . implode("\n", $output));
            }
        } else {
            logMessage('Сертификат не уникален: ' . $certificate);
        }
    }

    if (!$success) {
        logMessage('Все попытки подписать PDF не удались: ' . implode("\n", $errorOutput));
        echo json_encode(['status' => 'error', 'message' => 'Все попытки подписать PDF не удались: ' . implode("\n", $errorOutput)]);
        http_response_code(500);
        exit;
    }

    $signedFileContent = file_get_contents($outputFile);
    if ($signedFileContent === false) {
        logMessage('Ошибка при чтении подписанного файла.');
        echo json_encode(['status' => 'error', 'message' => 'Ошибка при чтении подписанного файла.']);
        http_response_code(500);
        exit;
    }

    $base64Content = base64_encode($signedFileContent);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'fileName' => basename($outputFile),
        'fileType' => 'application/pdf',
        'fileData' => $base64Content,
    ]);
    exit;
}