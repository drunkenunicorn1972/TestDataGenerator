<?php declare(strict_types=1);

namespace TestDataGenerator\Service;

use GuzzleHttp\Client as GuzzleClient;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class GeminiClient
{
    private SystemConfigService $systemConfig;
    private GuzzleClient $httpClient;

    public function __construct(SystemConfigService $systemConfig)
    {
        $this->systemConfig = $systemConfig;
        $this->httpClient = new GuzzleClient();
    }

    public function generateText(string $prompt, array $schema): string
    {
        $apiKey = (string) $this->systemConfig->get('TestDataGenerator.config.apiKey');
        $model = (string) $this->systemConfig->get('TestDataGenerator.config.llmVersion');
        if (empty($model)) {
            $model = 'gemini-3.5-flash';
        }

        if (empty($apiKey)) {
            throw new \Exception('Gemini API Key is not configured.');
        }

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            $model,
            $apiKey
        );

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema
            ]
        ];

        try {
            $response = $this->httpClient->post($url, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 90.0,
                'force_ip_resolve' => 'v4',
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : '';
            throw new \Exception(sprintf(
                'Gemini API request failed: %s. Response: %s',
                $e->getMessage(),
                $responseBody
            ), 0, $e);
        }

        $body = json_decode($response->getBody()->getContents(), true);

        if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
            return $body['candidates'][0]['content']['parts'][0]['text'];
        }

        throw new \Exception('Invalid response from Gemini API.');
    }

    public function generateImage(string $prompt): string
    {
        $apiKey = (string) $this->systemConfig->get('TestDataGenerator.config.apiKey');
        if (empty($apiKey)) {
            throw new \Exception('Gemini API Key is not configured.');
        }

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent?key=%s',
            $apiKey
        );

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
                'imageConfig' => [
                    'aspectRatio' => '1:1',
                ]
            ]
        ];

        try {
            $response = $this->httpClient->post($url, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 60.0,
                'force_ip_resolve' => 'v4',
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : '';
            throw new \Exception(sprintf(
                'Gemini API request failed: %s. Response: %s',
                $e->getMessage(),
                $responseBody
            ), 0, $e);
        }

        $body = json_decode($response->getBody()->getContents(), true);

        if (isset($body['candidates'][0]['content']['parts'])) {
            foreach ($body['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['inlineData']['data'])) {
                    return base64_decode($part['inlineData']['data']);
                }
            }
        }

        if (isset($body['predictions'][0]['bytesBase64Encoded'])) {
            return base64_decode($body['predictions'][0]['bytesBase64Encoded']);
        }

        if (isset($body['predictions'][0]['image']['imageBytes'])) {
            return base64_decode($body['predictions'][0]['image']['imageBytes']);
        }

        throw new \Exception('Invalid response structure from Gemini Imagen API.');
    }
}
