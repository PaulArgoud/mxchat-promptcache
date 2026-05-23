# Changelog

Toutes les modifications notables sont consignées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), et ce projet adhère à [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0] - 2026-05-23

### Fixed

- **Critique** : seuils minimum corrigés pour Opus 4.x et Haiku 4.5 (4096 tokens, ~16 400 caractères, au lieu de 1024). Avec l'ancien seuil, `cache_control` était bien injecté mais Anthropic ignorait silencieusement la mise en cache sur ces modèles (`cache_creation_input_tokens = 0`).

### Added

- Caching des `tools` (4e breakpoint, le plus stable du préfixe).
- Deuxième breakpoint "rolling" sur l'historique : avant-dernier message user en plus du dernier, pour maintenir un hit rate stable au fil de la conversation.
- Statistiques ventilées par modèle Anthropic.
- Commande `wp mxchat-pc stats --by-model`.
- Commande `wp mxchat-pc debug` (détails de la dernière requête : modèle, breakpoints ajoutés, usage retourné).
- Détection fine du modèle (Opus / Haiku 4.x / Sonnet 4.6 / Sonnet legacy) pour appliquer le bon seuil minimum.

### Changed

- Découplage system / messages : un breakpoint pré-existant sur l'historique n'empêche plus le cache du `system`.
- Les seuils sont désormais exposés via des constantes (`MXCHAT_PC_MIN_CHARS_OPUS`, `MXCHAT_PC_MIN_CHARS_SONNET_4_6`, etc.) pour faciliter le tuning.
- Compteur de breakpoints utilisés pour garantir le plafond Anthropic (4 max).

## [0.2.0]

### Added

- Cache de l'historique de conversation (2e breakpoint sur le dernier message).
- TTL étendu à 1h via le header beta `extended-cache-ttl-2025-04-11`.
- Métriques WP-CLI : `wp mxchat-pc stats` et `wp mxchat-pc reset`.
- Support des prompts `system` déjà fournis en tableau de blocs.
- Seuil minimum adapté au modèle (Haiku 2048 tokens, autres 1024).

### Changed

- Match d'URL strict (host + path) au lieu de `strpos`.

### Fixed

- Idempotence : ne re-cache pas si `cache_control` déjà présent dans le payload.

## [0.1.0]

### Added

- Version initiale : caching du bloc `system` via le filtre `http_request_args`.
- Header beta `extended-cache-ttl-2025-04-11` pour la TTL 1h.
