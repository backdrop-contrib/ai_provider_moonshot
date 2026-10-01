# AI Provider Moonshot

Moonshot AI (Kimi) provider for the Backdrop CMS AI module.

Adds Moonshot AI's Kimi models to the providers the `ai` module can route to,
using its OpenAI-compatible API.

## Supported operations

| Operation | Supported | Notes |
|---|---|---|
| Chat | Yes | Streaming supported. JSON mode and JSON schema responses supported. Temperature is not sent: current Kimi models fix it server-side. |
| Completions | Yes | Sent as a single chat message; uses the chat endpoint. |
| Tool calling | Yes | Standard `tools` / `tool_choice` payload. The model's `reasoning_content` is returned so agent tool loops can send it back, as thinking models require. |
| Thinking | Yes | Models that `/models` reports with `supports_reasoning`. |
| Vision | Yes | Models that `/models` reports with `supports_image_in`. |
| Embeddings | No | |
| Image generation | No | |
| Moderation | No | |
| Speech-to-text | No | |

The model list and the vision/thinking flags come from the API's `/models`
endpoint. There is no built-in fallback list: Moonshot retires whole model
series, so a hardcoded list goes stale. Use the Model capabilities page to
adjust what each model is offered for.

## Endpoint region

Moonshot runs separate gateways for accounts registered in China and
internationally, and a key only works on its own region. The provider settings
at `admin/config/ai/settings` add:

- **Endpoint Region** — International (`https://api.moonshot.ai/v1`, the
  default), China (`https://api.moonshot.cn/v1`), or Custom.
- **Custom Base URL** — used when Custom is selected, for proxies or private
  gateways.

## Installation

- Install this module using the official [Backdrop CMS instructions](https://backdropcms.org/user-guide/modules).
- Create an authentication key with the Key module holding your Moonshot API
  key (https://platform.kimi.ai/console/api-keys, or platform.kimi.com for China).
- Enable and configure the provider at `admin/config/ai/settings`, choosing the
  region that matches your account.

## Issues

Bugs and feature requests should be reported in the [Issue Queue](https://github.com/backdrop-contrib/ai_provider_moonshot/issues).

## Current Maintainer

[Justin Keiser](https://github.com/keiserjb)

## Credits

- Created for Backdrop CMS by [Justin Keiser](https://github.com/keiserjb).

- Developed with AI assistance.

## License

This project is GPL v2 software. See the LICENSE.txt file in this directory for complete text.
