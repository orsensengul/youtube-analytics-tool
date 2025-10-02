<?php
declare(strict_types=1);

/**
 * AI Provider Manager with Automatic Fallback
 *
 * Manages multiple AI providers with priority-based selection,
 * error tracking, and automatic failover.
 */
class AIProvider {
    private array $providers;
    private int $errorResetTime;

    public function __construct(array $providers, int $errorResetTime = 300) {
        $this->providers = $providers;
        $this->errorResetTime = $errorResetTime;
        $this->initializeErrorTracking();
    }

    /**
     * Initialize error tracking in session
     */
    private function initializeErrorTracking(): void {
        if (!isset($_SESSION['ai_provider_errors'])) {
            $_SESSION['ai_provider_errors'] = [];
            foreach ($this->providers as $provider) {
                $_SESSION['ai_provider_errors'][$provider['name']] = [
                    'count' => 0,
                    'last_error_time' => null,
                    'status' => 'active',
                ];
            }
        }
    }

    /**
     * Get the next available provider based on priority and error status
     */
    private function getAvailableProvider(): ?array {
        // Sort by priority (lower number = higher priority)
        $sortedProviders = $this->providers;
        usort($sortedProviders, fn($a, $b) => $a['priority'] - $b['priority']);

        $now = time();

        foreach ($sortedProviders as $provider) {
            $name = $provider['name'];
            $errors = $_SESSION['ai_provider_errors'][$name] ?? ['count' => 0, 'status' => 'active'];

            // Reset error count if reset time has passed
            if ($errors['last_error_time'] &&
                ($now - $errors['last_error_time']) > $this->errorResetTime) {
                $_SESSION['ai_provider_errors'][$name]['count'] = 0;
                $_SESSION['ai_provider_errors'][$name]['status'] = 'active';
                $errors['count'] = 0;
                $errors['status'] = 'active';
            }

            // Use this provider if it hasn't exceeded error threshold
            if ($errors['count'] < $provider['error_threshold'] &&
                $errors['status'] === 'active') {
                return $provider;
            }
        }

        // No available provider, return highest priority one (will be attempted anyway)
        return $sortedProviders[0] ?? null;
    }

    /**
     * Record a successful API call
     */
    private function recordSuccess(string $providerName): void {
        $_SESSION['ai_provider_errors'][$providerName]['count'] = 0;
        $_SESSION['ai_provider_errors'][$providerName]['status'] = 'active';
    }

    /**
     * Record a failed API call
     */
    private function recordError(string $providerName, array $provider): void {
        $_SESSION['ai_provider_errors'][$providerName]['count']++;
        $_SESSION['ai_provider_errors'][$providerName]['last_error_time'] = time();

        // Check if threshold exceeded
        if ($_SESSION['ai_provider_errors'][$providerName]['count'] >= $provider['error_threshold']) {
            $_SESSION['ai_provider_errors'][$providerName]['status'] = 'throttled';
        }
    }

    /**
     * Make AI API request with automatic fallback
     *
     * @param array $messages Messages array for chat completion
     * @return array ['success' => bool, 'data' => mixed, 'error' => string|null, 'provider' => string|null]
     */
    public function request(array $messages): array {
        $attempts = 0;
        $maxAttempts = count($this->providers);
        $lastError = '';

        while ($attempts < $maxAttempts) {
            $provider = $this->getAvailableProvider();

            if (!$provider) {
                return [
                    'success' => false,
                    'data' => null,
                    'error' => 'No available AI providers',
                    'provider' => null,
                ];
            }

            try {
                $result = $this->makeRequest($provider, $messages);

                if ($result['success']) {
                    $this->recordSuccess($provider['name']);
                    return [
                        'success' => true,
                        'data' => $result['data'],
                        'error' => null,
                        'provider' => $provider['name'],
                    ];
                } else {
                    $lastError = $result['error'];
                    $this->recordError($provider['name'], $provider);
                }
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                $this->recordError($provider['name'], $provider);
            }

            $attempts++;
        }

        return [
            'success' => false,
            'data' => null,
            'error' => $lastError ?: 'All AI providers failed',
            'provider' => null,
        ];
    }

    /**
     * Make actual HTTP request to AI provider
     */
    private function makeRequest(array $provider, array $messages): array {
        $body = json_encode([
            'model' => $provider['model'],
            'messages' => $messages,
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($provider['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $provider['key'],
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => $provider['timeout_seconds'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'cURL error: ' . $curlError,
            ];
        }

        if ($httpCode >= 400) {
            return [
                'success' => false,
                'data' => null,
                'error' => "HTTP $httpCode: " . substr($response, 0, 200),
            ];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'Invalid JSON response',
            ];
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!$content) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'No content in response',
            ];
        }

        return [
            'success' => true,
            'data' => $content,
            'error' => null,
        ];
    }

    /**
     * Get current provider status for debugging
     */
    public function getProviderStatus(): array {
        $status = [];
        foreach ($this->providers as $provider) {
            $name = $provider['name'];
            $errors = $_SESSION['ai_provider_errors'][$name] ?? ['count' => 0, 'status' => 'active'];
            $status[$name] = [
                'priority' => $provider['priority'],
                'error_count' => $errors['count'],
                'status' => $errors['status'],
                'threshold' => $provider['error_threshold'],
            ];
        }
        return $status;
    }
}
