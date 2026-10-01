<?php

/**
 * @file
 * Moonshot AI (Kimi) adapter for AI core.
 */

class AIMoonshotAdapter extends AIAdapterBase {

  use AICompatibleTrait;

  /** @var string */
  protected $baseUrl = 'https://api.moonshot.ai/v1';

  /** @var array|null */
  protected $models = NULL;

  /**
   * Per-model capability flags reported by /models, keyed by model ID.
   *
   * @var array
   */
  protected $modelInfo = [];

  /**
   * {@inheritdoc}
   */
  public function __construct($api_key, ?AIApi $api = NULL) {
    parent::__construct($api_key, $api);

    $config = config('ai_provider_moonshot.settings');
    $region = $config->get('endpoint_region') ?: 'global';
    $custom_url = trim((string) $config->get('custom_url'));

    if ($region === 'custom' && $custom_url !== '') {
      $this->baseUrl = rtrim($custom_url, '/');
    }
    elseif ($region === 'china') {
      $this->baseUrl = 'https://api.moonshot.cn/v1';
    }
    else {
      $this->baseUrl = 'https://api.moonshot.ai/v1';
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

    // The catalog changes often (whole model series are retired), so there is
    // no built-in fallback list; an unreachable API means no models.
    $models = [];
    try {
      $result = $this->makeRequest($this->baseUrl . '/models', [], [], 'GET', 10);
      foreach ($result['data'] ?? [] as $model) {
        $id = $model['id'] ?? NULL;
        if (empty($id)) {
          continue;
        }
        $models[$id] = $id;
        $this->modelInfo[$id] = [
          'vision' => !empty($model['supports_image_in']),
          'thinking' => !empty($model['supports_reasoning']),
        ];
      }
    }
    catch (\Exception $e) {
      watchdog('ai_provider_moonshot', 'Failed to fetch Moonshot models: @message', ['@message' => $e->getMessage()], WATCHDOG_WARNING);
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
      switch ($capability) {
        case 'text':
        case 'tool_calling':
          $ok = TRUE;
          break;

        // Reported per model by /models (supports_image_in,
        // supports_reasoning).
        case 'vision':
        case 'thinking':
          $ok = !empty($this->modelInfo[$id][$capability]);
          break;

        default:
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
   * Build the shared chat-completions payload.
   *
   * Current Kimi models fix temperature (and top_p, penalties) server-side
   * and the docs say to omit them, so the caller's temperature is not sent.
   * max_tokens is deprecated in favor of max_completion_tokens.
   */
  protected function buildPayload(string $model, array $messages, $max_tokens): array {
    $payload = [
      'model' => $model,
      'messages' => $messages,
    ];
    if ((int) $max_tokens > 0) {
      $payload['max_completion_tokens'] = (int) $max_tokens;
    }
    return $payload;
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
    $payload = $this->buildPayload($model, $messages, $max_tokens);

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
    $payload = $this->buildPayload($model, $messages, $max_tokens);
    $payload['tools'] = $tools;
    $payload['tool_choice'] = $tool_choice;

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
