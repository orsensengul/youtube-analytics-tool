<?php

class RapidApiClient
{
    private string $key;
    private string $host;

    public function __construct(string $key, string $host)
    {
        $this->key = $key;
        $this->host = $host;
    }

    public function get(string $url, array $query = []): array
    {
        $ch = curl_init();
        $qs = http_build_query($query);
        $fullUrl = $url . ($qs ? ('?' . $qs) : '');

        curl_setopt_array($ch, [
            CURLOPT_URL => $fullUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'X-RapidAPI-Key: ' . $this->key,
                'X-RapidAPI-Host: ' . $this->host,
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['error' => 'Curl error: ' . $err];
        }
        if ($httpCode >= 400) {
            return ['error' => 'HTTP ' . $httpCode . ' from RapidAPI', 'raw' => $response];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Invalid JSON response', 'raw' => $response];
        }
        return $data;
    }
}
