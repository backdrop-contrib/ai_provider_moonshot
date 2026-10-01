# AI Provider Moonshot

Moonshot AI (Kimi) provider for the Backdrop CMS AI module.

Adds Moonshot AI's Kimi models to the providers the `ai` module can route to,
using its OpenAI-compatible API.

## Supported operations

| Operation | Supported | Notes |
|---|---|---|
| Chat | Yes | Streaming supported. JSON mode and JSON schema responses supported. |
| Completions | Yes | Sent as a single chat message; uses the chat endpoint. |
| Tool calling | Yes | Standard `tools` / `tool_choice` payload. |
| Thinking | Yes | Kimi K3, K2.7 and K2.6 models. |
| Vision | Yes | Kimi K3 and vision models. |
| Embeddings | No | |
| Image generation | No | |
| Moderation | No | |
| Speech-to-text | No | |

The model list is fetched from the API's `/models` endpoint, falling back to a
built-in list of Kimi and Moonshot V1 models when the request fails.

## Endpoint region

Moonshot runs separate gateways for accounts registered in China and
internationally, and a key only works on its own region. The provider settings
at `admin/config/ai/settings` add:

- **Endpoint Region** — China (`https://api.moonshot.cn/v1`, the default),
  Global (`https://api.moonshot.ai/v1`), or Custom.
- **Custom Base URL** — used when Custom is selected, for proxies or private
  gateways.

## Installation

- Install this module using the official [Backdrop CMS instructions](https://backdropcms.org/user-guide/modules).
- Create an authentication key with the Key module holding your Moonshot API
  key (https://platform.moonshot.cn/console/api-keys).
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
