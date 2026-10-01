<?php

/**
 * @file
 * Moonshot AI (Kimi) adapter for AI core.
 */

class AIMoonshotAdapter extends AIAdapterBase {

  use AICompatibleTrait;

  /** @var string */
  protected $baseUrl = 'https://api.moonshot.cn/v1';

  /** @var array|null */
  protected $models = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct($api_key, ?AIApi $api = NULL) {
    parent::__construct($api_key, $api);

    $config = config('ai_provider_moonshot.settings');
    $region = $config->get('endpoint_region') ?: 'china';
    $custom_url = trim((string) $config->get('custom_url'));

    if ($region === 'custom' && $custom_url !== '') {
      $this->baseUrl = rtrim($custom_url, '/');
    }
    elseif ($region === 'global') {
      $this->baseUrl = 'https://api.moonshot.ai/v1';
    }
    else {
      $this->baseUrl = 'https://api.moonshot.cn/v1';
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultHeaders(): array {
    return [
      'Authorization' => 'Bearer ' . $this->apiKey,
      'Content-Type' => 'application/json',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    if ($this->models !== NULL) {
      return $this->models;
    }

    $models = [];
    try {
      $result = $this->makeRequest($this->baseUrl . '/models', [], [], 'GET', 10);
      if (!empty($result['data']) && is_array($result['data'])) {
        foreach ($result['data'] as $model) {
          $id = $model['id'] ?? ($model['name'] ?? NULL);
          if (!empty($id)) {
            $models[$id] = $model['name'] ?? $id;
          }
        }
      }
    }
    catch (\Exception $e) {
      watchdog('ai_provider_moonshot', 'Failed to fetch Moonshot models: @message', ['@message' => $e->getMessage()], WATCHDOG_DEBUG);
    }

    if (empty($models)) {
      $models = [
        'kimi-k3' => 'Kimi K3 (Multimodal & Reasoning, 1M context)',
        'kimi-k2.7-code' => 'Kimi K2.7 Code',
        'kimi-k2.7-code-highspeed' => 'Kimi K2.7 Code HighSpeed',
        'kimi-k2.6' => 'Kimi K2.6',
        'kimi-k2.5' => 'Kimi K2.5',
        'moonshot-v1-8k' => 'Moonshot V1 8K',
        'moonshot-v1-32k' => 'Moonshot V1 32K',
        'moonshot-v1-128k' => 'Moonshot V1 128K',
      ];
    }

    asort($models);
    return $this->models = $models;
  }

  /**
   * {@inheritdoc}
   */
  public function getModelsByCapability($capability): array {
    $models = $this->getModels();
    $capability = ai_normalize_capability_name($capability);
    $filtered = [];

    foreach ($models as $id => $label) {
      $ok = FALSE;
      switch ($capability) {
        case 'text':
        case 'chat':
          $ok = TRUE;
          break;

        case 'thinking':
          $ok = (bool) preg_match('/k3|k2\.7|k2\.6|thinking/i', $id);
          break;

        case 'tool_calling':
          $ok = TRUE;
          break;

        case 'vision':
          $ok = (bool) preg_match('/k3|vision/i', $id);
          break;

        case 'embeddings':
        case 'embedding':
        case 'image':
        case 'moderation':
        case 'stt':
          $ok = FALSE;
          break;
      }

      if ($ok) {
        $filtered[$id] = $label;
      }
    }

    backdrop_alter('ai_model_capabilities', $filtered, $capability, $this);
    return $filtered;
  }

  /**
   * {@inheritdoc}
   */
  public function completions(string $model, string $prompt, $temperature, $max_tokens = 512, bool $stream_response = FALSE) {
    $messages = [
      ['role' => 'user', 'content' => $prompt],
    ];
    return $this->chat($model, $messages, $temperature, $max_tokens, $stream_response);
  }

  /**
   * {@inheritdoc}
   */
  public function chat(string $model, array $messages, $temperature, $max_tokens = 1024, bool $stream_response = FALSE, array $context_extra = []) {
    $payload = [
      'model' => $model,
      'messages' => $messages,
      'temperature' => (float) $temperature,
    ];
    if ((int) $max_tokens > 0) {
      $payload['max_tokens'] = (int) $max_tokens;
    }

    if (!empty($context_extra['response_format'])) {
      $payload['response_format'] = $context_extra['response_format'];
    }
    elseif (!empty($context_extra['json_schema'])) {
      $payload['response_format'] = [
        'type' => 'json_schema',
        'json_schema' => [
          'name' => $context_extra['json_schema_name'] ?? 'response',
          'strict' => TRUE,
          'schema' => $context_extra['json_schema'],
        ],
      ];
    }
    elseif (!empty($context_extra['json_mode'])) {
      $payload['response_format'] = ['type' => 'json_object'];
    }

    $url = $this->baseUrl . '/chat/completions';

    try {
      if ($stream_response) {
        $payload['stream'] = TRUE;
        return $this->buildStreamingResponse($url, [
          'method' => 'POST',
          'headers' => array_merge(['Accept' => 'text/event-stream'], $this->getDefaultHeaders()),
          'data' => json_encode($payload),
          'timeout' => 300,
        ], function ($data) {
          return $data['choices'][0]['delta']['content'] ?? '';
        });
      }

      $result = $this->makeRequest($url, $payload, [], 'POST', 300);
      return trim($result['choices'][0]['message']['content'] ?? '');
    }
    catch (\Exception $e) {
      watchdog('ai_provider_moonshot', 'Moonshot chat error: @message', ['@message' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function chatWithTools(string $model, array $messages, array $tools, $temperature, $max_tokens = 1024, string $tool_choice = 'auto', array $context_extra = []): array {
    $payload = [
      'model' => $model,
      'messages' => $messages,
      'tools' => $tools,
      'tool_choice' => $tool_choice,
      'temperature' => (float) $temperature,
    ];
    if ((int) $max_tokens > 0) {
      $payload['max_tokens'] = (int) $max_tokens;
    }

    $url = $this->baseUrl . '/chat/completions';

    try {
      $result = $this->makeRequest($url, $payload, [], 'POST', 300);
      return $this->normalizeToolResponse($result);
    }
    catch (\Exception $e) {
      watchdog('ai_provider_moonshot', 'Moonshot chatWithTools error: @message', ['@message' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function embedding(string $input, string $model, bool $log = TRUE): array {
    watchdog('ai_provider_moonshot', 'Embeddings are not supported by Moonshot AI.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Embeddings are not supported by Moonshot AI.');
  }

  /**
   * {@inheritdoc}
   */
  public function images(string $model, string $prompt, string $size, string $response_format, string $quality = 'standard', string $style = 'natural', ?string $output_format = NULL) {
    watchdog('ai_provider_moonshot', 'Image generation is not supported by Moonshot AI.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Image generation is not supported by Moonshot AI.');
  }

  /**
   * {@inheritdoc}
   */
  public function textToSpeech(string $model, string $input, string $voice, string $response_format) {
    watchdog('ai_provider_moonshot', 'Text-to-speech is not supported by Moonshot AI.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Text-to-speech is not supported by Moonshot AI.');
  }

  /**
   * {@inheritdoc}
   */
  public function speechToText(string $model, string $file, string $task = 'transcribe', $temperature = 0.4, string $response_format = 'verbose_json') {
    watchdog('ai_provider_moonshot', 'Speech-to-text is not supported by Moonshot AI.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Speech-to-text is not supported by Moonshot AI.');
  }

  /**
   * {@inheritdoc}
   */
  public function moderation(string $input, string $model = 'omni-moderation-latest'): array {
    watchdog('ai_provider_moonshot', 'Moderation is not supported by Moonshot AI.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Moderation is not supported by Moonshot AI.');
  }

}
