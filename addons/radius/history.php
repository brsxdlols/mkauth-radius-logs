<?php

function radius_latest_history($login)
{
    $basePath = radius_log_path();
    $files = array($basePath);
    $index = 1;

    for ($index = 1; $index <= 14; $index++) {
        foreach (array($basePath . '.' . $index, $basePath . '.' . $index . '.gz') as $path) {
            if (is_readable($path)) {
                $files[] = $path;
            }
        }
    }

    $startedAt = microtime(true);
    $bytesRead = 0;
    $searchedFiles = 0;

    foreach ($files as $file) {
        $gzip = substr($file, -3) === '.gz';
        $handle = $gzip ? @gzopen($file, 'rb') : @fopen($file, 'rb');
        $lastLine = null;
        $complete = true;

        if (!$handle) {
            continue;
        }

        while (!($gzip ? gzeof($handle) : feof($handle))) {
            if ($bytesRead >= 67108864 || microtime(true) - $startedAt > 2) {
                $complete = false;
                break;
            }
            $line = $gzip ? gzgets($handle, 65536) : fgets($handle, 65536);
            if ($line === false) {
                break;
            }
            $bytesRead += strlen($line);
            if (radius_extract_login($line) === $login) {
                $lastLine = trim($line);
            }
        }

        $gzip ? gzclose($handle) : fclose($handle);
        $searchedFiles++;

        if (!$complete) {
            return array(
                'row' => null,
                'note' => 'Busca histórica atingiu o limite seguro; último registro não confirmado.',
            );
        }
        if ($lastLine !== null) {
            $lastLine = preg_replace('/\[([^\/\]]+)\/[^\]]*\]/', '[$1/***]', $lastLine);
            $lastLine = preg_replace('/((?:User-Password|Cleartext-Password|CHAP-Password)\s*[:=]\s*)("[^"]*"|\S+)/i', '$1***', $lastLine);
            $type = radius_log_type($lastLine);
            $labels = radius_type_labels();

            return array(
                'row' => array(
                    'type' => $type,
                    'label' => $labels[$type],
                    'line' => $lastLine,
                    'login' => $login,
                ),
                'note' => 'Último registro localizado no histórico disponível.',
            );
        }
    }

    return array(
        'row' => null,
        'note' => 'Nenhum registro deste login nos ' . $searchedFiles . ' arquivos disponíveis (atual e até 14 rotações).',
    );
}
