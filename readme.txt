=== MXChat Prompt Cache ===
Contributors: paulargoud
Tags: mxchat, anthropic, claude, cache, prompt-caching
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Active le prompt caching Anthropic sur les appels Claude du plugin MXChat
Basic, sans modifier ses fichiers.

== Description ==

Addon transparent pour MXChat Basic. Active automatiquement le prompt
caching d'Anthropic via le filtre WordPress `http_request_args`, sur
jusqu'à 4 breakpoints stratégiquement placés :

* **tools** (si présents et volumineux) — le préfixe le plus stable
* **system** (gros prompt statique)
* **avant-dernier message user** — sert de point de lecture du cache au
  tour suivant
* **dernier message user** — point d'écriture du cache courant

Stratégie "rolling" : à chaque tour, le breakpoint d'écriture devient le
breakpoint de lecture du tour suivant. Le hit rate reste stable même quand
la conversation s'allonge.

TTL de cache : **1 heure** par défaut (header beta
`extended-cache-ttl-2025-04-11`). Plus rentable qu'un cache 5 min sur les
chatbots à trafic moyen (>= 3 requêtes par fenêtre).

Gains typiques :

* ~90 % de réduction du coût des tokens d'entrée mis en cache
* Latence first-token sensiblement réduite
* Aucune configuration requise

== Prérequis MXChat (important) ==

Sur MXChat Basic 3.2.6, le chat principal utilise `curl_exec`
directement pour le streaming (ligne 8201 de
`includes/class-mxchat-integrator.php`) et BYPASSE l'API HTTP
WordPress. Ce code path n'est pas interceptable par
`http_request_args` : le plugin ne peut donc rien faire tant que
le streaming est actif.

**Action requise** : désactiver le streaming dans les réglages
MXChat (`MxChat → Settings`). Le chat tombe alors sur la branche
fallback `mxchat_generate_response_claude` (ligne 8984), qui passe
par `wp_remote_post` et est pleinement intercepté.

Trade-off : perte de l'effet "machine à écrire" côté UX vs.
~85-95 % de réduction du coût input une fois le cache chaud
(typiquement 3-5 requêtes). Mesuré en conditions réelles sur
Haiku 4.5 : 49 % de hit rate dès la 3e requête.

Restent cacheables même avec streaming ON :

* le Content Generator (admin) — `wp_remote_post`
* le bouton "Test API" admin (one-shot) — `wp_remote_post`
* le fallback streaming en cas d'erreur — `wp_remote_post`

NON cacheable tant que la PR upstream n'est pas mergée :

* le chat principal en mode streaming — `curl_exec` direct
* le test admin "Test streaming" — `curl_exec` direct

== Configuration optionnelle ==

Une seule constante PHP, à définir dans `wp-config.php` si besoin :

`define('MXCHAT_PC_EXTENDED_TTL', false);`

→ retombe sur la TTL standard de 5 minutes (et n'envoie plus le header
beta). À utiliser si votre compte Anthropic rejette le header
`extended-cache-ttl-2025-04-11`.

== Hooks d'extensibilité ==

Trois filtres permettent d'ajuster le comportement sans forker :

* `mxchat_pc_should_inject($should, $payload, $args, $url)` → désactiver
  l'injection pour une requête spécifique.
* `mxchat_pc_min_chars($min, $model, $payload)` → override du seuil
  minimum (par modèle ou par requête).
* `mxchat_pc_ephemeral_control($control)` → override de la valeur
  `cache_control` injectée (ex : TTL custom).

== Seuils minimum par modèle ==

Anthropic ignore SILENCIEUSEMENT `cache_control` si le préfixe est trop
court. Le plugin détecte le modèle dans chaque requête et applique le bon
seuil :

* Opus 4.5 / 4.6 / 4.7         : 4096 tokens (~16 400 caractères)
* Haiku 4.5                    : 4096 tokens (~16 400 caractères)
* Sonnet 4.6                   : 2048 tokens (~8 200 caractères)
* Sonnet <= 4.5                : 1024 tokens (~4 000 caractères)

== Métriques (WP-CLI) ==

`wp mxchat-pc stats`            → hit rate global sur 24 h glissantes.

`wp mxchat-pc stats --by-model` → ventilation par modèle Anthropic.

`wp mxchat-pc stats --total`    → cumulatif depuis l'installation + 24 h.

`wp mxchat-pc debug`            → détails de la dernière requête (modèle,
                                  breakpoints ajoutés, usage retourné).

`wp mxchat-pc reset`            → réinitialise compteurs 24 h et debug.

`wp mxchat-pc reset --total`    → réinitialise aussi le cumulatif.

Compteurs alimentés par le champ `usage` retourné par l'API Anthropic
(`cache_read_input_tokens`, `cache_creation_input_tokens`,
`input_tokens`).

== Limites connues ==

* **Streaming MXChat non interceptable** (voir section "Prérequis MXChat"
  ci-dessus). Solution actuelle : désactiver le streaming dans les
  réglages MXChat. Solution propre : PR upstream pour exposer un
  filtre `mxchat_pre_claude_stream_payload` avant le `curl_setopt`
  ligne 8119.

* **Stabilité de l'historique.** Les breakpoints conversation supposent
  que MXChat envoie l'historique de façon stable (mêmes messages, même
  ordre) d'un appel à l'autre. Si le plugin tronque ou reformate
  l'historique entre les tours, le hit rate sera plus faible.

* **Idempotence.** Si une future version de MXChat ajoute nativement des
  `cache_control` sur l'historique, le plugin détecte leur présence et
  ne touche pas aux messages (mais peut toujours cacher tools/system).

== Installation ==

1. Copier le dossier `mxchat-promptcache` dans `wp-content/plugins/`.
2. Activer le plugin depuis l'admin WordPress.
3. Vérifier après quelques requêtes : `wp mxchat-pc stats`.

== Changelog ==

= 0.4.0 =
* Header de plugin complet : `Plugin URI`, `Author URI`, `Update URI`,
  `Text Domain`, `Domain Path`.
* Internationalisation (i18n) : toutes les chaînes user-facing wrappées
  dans `__()` avec text-domain `mxchat-promptcache`.
* Trois filters d'extensibilité : `mxchat_pc_should_inject`,
  `mxchat_pc_min_chars`, `mxchat_pc_ephemeral_control`.
* Statistiques cumulatives depuis l'installation (option `wp_option`
  jamais expirée), exposées via `wp mxchat-pc stats --total`.
* `wp mxchat-pc reset --total` pour reset complet.
* Bail-out précoce sur les méthodes HTTP non-POST (évite un
  `json_decode` inutile sur OPTIONS preflight, etc.).
* `mxchat_pc_tools_size` : short-circuit quand le seuil est atteint
  (mineur, utile sur les gros tool sets).
* GitHub Actions CI : `php -l` sur PHP 7.4 / 8.0 / 8.1 / 8.2 / 8.3.

= 0.3.0 =
* **Fix critique** : seuils minimum corrigés pour Opus 4.x et Haiku 4.5
  (4096 tokens et non 1024) ; cache était silencieusement ignoré sur ces
  modèles.
* Caching des `tools` ajouté (4e breakpoint, le plus stable).
* Stratégie "rolling" sur l'historique : 2 breakpoints sur les 2 derniers
  messages user (lecture + écriture).
* Découplage system / messages : un breakpoint sur l'historique n'empêche
  plus le cache du system.
* Stats ventilées par modèle Anthropic ; commande `--by-model`.
* Nouvelle commande `wp mxchat-pc debug` pour diagnostiquer une requête.

= 0.2.0 =
* Cache de l'historique de conversation (2e breakpoint).
* TTL étendu à 1h via le header beta `extended-cache-ttl-2025-04-11`.
* Métriques WP-CLI : `wp mxchat-pc stats` / `wp mxchat-pc reset`.
* Match d'URL strict (host + path) plutôt que `strpos`.
* Idempotence : ne re-cache pas si `cache_control` déjà présent.
* Support des prompts `system` déjà fournis en tableau de blocs.
* Seuil minimum adapté au modèle (Haiku 2048 tokens, autres 1024).

= 0.1.0 =
* Version initiale : caching du bloc system via `http_request_args`.
