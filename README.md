# MXChat Prompt Cache

[![Latest release](https://img.shields.io/github/v/release/PaulArgoud/mxchat-promptcache)](https://github.com/PaulArgoud/mxchat-promptcache/releases/latest)
[![License: GPL-2.0+](https://img.shields.io/badge/license-GPL--2.0%2B-blue)](https://www.gnu.org/licenses/gpl-2.0.html)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b)](https://wordpress.org/)
[![CI](https://github.com/PaulArgoud/mxchat-promptcache/actions/workflows/ci.yml/badge.svg)](https://github.com/PaulArgoud/mxchat-promptcache/actions/workflows/ci.yml)

Plugin WordPress qui active automatiquement le [prompt caching Anthropic](https://docs.claude.com/en/docs/build-with-claude/prompt-caching) sur les appels API du plugin **MXChat Basic**, sans modifier ses fichiers.

> Stable tag : **0.4.0** — PHP 7.4+ — WordPress 5.8+

## Pourquoi

MXChat Basic envoie à chaque requête un gros prompt `system` et l'historique complet de la conversation à l'API Anthropic. Sans cache, chaque token est facturé au plein tarif et la latence first-token augmente avec la longueur du contexte.

Le prompt caching d'Anthropic réutilise les préfixes identiques entre requêtes :

- **~90 %** de réduction du coût des tokens d'entrée cachés (`~0.1×` le tarif d'input)
- **TTFT sensiblement réduit** quand le préfixe vient du cache
- **Zéro modification de MXChat** — tout passe par le filtre `http_request_args` de WordPress

## Prérequis MXChat (important)

Sur **MXChat Basic 3.2.6**, le chat principal utilise `curl_exec` directement pour le streaming (ligne 8201 de `includes/class-mxchat-integrator.php`) et **bypasse l'API HTTP WordPress**. Ce code path n'est pas interceptable par `http_request_args`, donc le plugin ne peut **rien faire** dessus tant que le streaming est actif.

**Action requise** : désactiver le mode streaming dans les réglages MXChat (`MxChat → Settings`). Le chat tombe alors sur la branche fallback `mxchat_generate_response_claude` (ligne 8984), qui utilise `wp_remote_post` et est pleinement intercepté.

**Trade-off** : perte de l'effet "machine à écrire" côté UX vs. **~85-95 % de réduction du coût input** une fois le cache chaud (typiquement 3-5 requêtes). Mesuré en conditions réelles sur Haiku 4.5 : 49 % de hit rate dès la 3e requête, convergence vers 90 %+ ensuite.

Une issue/PR upstream serait envisageable pour exposer un filtre `mxchat_pre_claude_stream_payload` juste avant le `curl_setopt(CURLOPT_POSTFIELDS, ...)` ligne 8119 — ce qui permettrait de cacher même avec streaming ON. Patch de quelques lignes côté MXChat.

### Ce qui est cacheable même avec streaming ON

| Source | Méthode | Cacheable ? |
|---|---|---|
| Chat principal (streaming) | `curl_exec` | ✗ bypasse WP HTTP API |
| Fallback non-streamé | `wp_remote_post` | ✓ |
| Content Generator (admin) | `wp_remote_post` | ✓ |
| Test "Test API" admin | `wp_remote_post` | ✓ (one-shot) |
| Intent classification | `wp_remote_post` | ✓ techniquement, mais prompt trop court → sous le seuil min |

## Fonctionnement

Le plugin hooke deux filtres WordPress :

| Filtre | Rôle |
|---|---|
| `http_request_args` | Détecte les requêtes POST vers `api.anthropic.com/v1/messages`, injecte les `cache_control` et le header beta TTL 1h |
| `http_response` | Extrait `usage.cache_*_input_tokens` de la réponse, accumule les stats |

### Stratégie de breakpoints (4 max imposés par Anthropic)

| # | Cible | Rôle | Conditions |
|---|---|---|---|
| 1 | Dernier outil de `tools[]` | Cache les définitions d'outils (très stable) | `tools` présents + taille >= seuil |
| 2 | Dernier bloc de `system` | Cache le prompt système | Taille >= seuil |
| 3 | Avant-dernier message user | Point de **lecture** au tour suivant | >= 3 messages dans l'historique |
| 4 | Dernier message user | Point d'**écriture** du cache courant | idem |

Le couple (3, 4) est "rolling" : le breakpoint d'écriture du tour N devient le breakpoint de lecture du tour N+1. Le hit rate reste stable même quand la conversation s'allonge.

### Seuils minimum par modèle

Anthropic ignore silencieusement `cache_control` quand le préfixe est trop court. Le plugin lit le champ `model` de chaque requête et applique le seuil approprié :

| Famille de modèle | Tokens min | ~ Caractères |
|---|---|---|
| Opus 4.5 / 4.6 / 4.7 | 4 096 | 16 400 |
| Haiku 4.5 | 4 096 | 16 400 |
| Sonnet 4.6 | 2 048 | 8 200 |
| Sonnet <= 4.5 | 1 024 | 4 000 |

## Installation

```bash
cd wp-content/plugins/
git clone https://github.com/PaulArgoud/mxchat-promptcache.git
wp plugin activate mxchat-promptcache
```

Ou : déposer `mxchat-promptcache.php` dans `wp-content/plugins/mxchat-promptcache/` et activer depuis l'admin WordPress.

Aucune configuration requise. Vérifier après quelques requêtes :

```bash
wp mxchat-pc stats
```

## Configuration

Une seule constante, à définir dans `wp-config.php` **avant** le chargement du plugin :

```php
// Désactive la TTL 1h (utilise la TTL standard 5min, pas de header beta)
define('MXCHAT_PC_EXTENDED_TTL', false);
```

À utiliser uniquement si ton compte Anthropic rejette le header beta `extended-cache-ttl-2025-04-11`.

### Filters d'extensibilité

Trois hooks WordPress permettent d'ajuster le comportement sans forker :

```php
// Désactiver l'injection pour une requête spécifique
add_filter('mxchat_pc_should_inject', function ($should, $payload, $args, $url) {
    if (($payload['metadata']['user_id'] ?? '') === 'no-cache-user') {
        return false;
    }
    return $should;
}, 10, 4);

// Override du seuil minimum (par modèle ou par requête)
add_filter('mxchat_pc_min_chars', function ($min, $model, $payload) {
    return $model === 'claude-haiku-4-5' ? 12000 : $min;
}, 10, 3);

// Override de la valeur cache_control (ex: TTL custom)
add_filter('mxchat_pc_ephemeral_control', function ($control) {
    return ['type' => 'ephemeral']; // force TTL 5min
});
```

## Utilisation (WP-CLI)

```bash
# Hit rate global sur 24h glissantes
wp mxchat-pc stats

# Ventilation par modèle Anthropic
wp mxchat-pc stats --by-model

# Cumulatif depuis l'installation + 24h glissantes
wp mxchat-pc stats --total

# Détails de la dernière requête (breakpoints, modèle, usage)
wp mxchat-pc debug

# Reset des compteurs 24h + debug
wp mxchat-pc reset

# Reset complet (24h + cumulatif + debug)
wp mxchat-pc reset --total
```

Exemple de sortie `stats --total --by-model` :

```
[Cumulatif (depuis installation)]
  Requêtes              : 12480
  Tokens lus du cache   : 98 421 100
  Tokens écrits cache   : 4 312 800
  Tokens entrée bruts   : 8 920 400
  Taux de hit (cache)   : 88.0 %
  Depuis                : 2026-04-01 10:00:00

[Glissant 24 h]
  Requêtes              : 142
  Tokens lus du cache   : 1 245 300
  ...

--- Détail par modèle (24 h) ---

[claude-sonnet-4-6]
  Requêtes              : 98
  ...
```

## Limites connues

- **Streaming MXChat non interceptable.** Voir la section [Prérequis MXChat](#prérequis-mxchat-important) ci-dessus. Solution actuelle : désactiver le streaming. Solution propre : PR upstream pour exposer un filtre avant le `curl_setopt` ligne 8119.
- **Stabilité de l'historique.** Les breakpoints sur les messages supposent que MXChat envoie un historique stable d'un appel à l'autre. Reformater ou tronquer l'historique entre les tours invalide le cache.
- **Idempotence.** Si une future version de MXChat ajoute nativement des `cache_control` sur l'historique, le plugin détecte leur présence et ne touche pas aux messages (mais continue à cacher `tools` et `system`).

## Compatibilité

- WordPress 5.8+
- PHP 7.4 → 8.3 (CI testée sur 7.4 / 8.0 / 8.1 / 8.2 / 8.3)
- MXChat Basic (toutes versions — le plugin n'introspecte pas MXChat)
- Tous les modèles Anthropic supportant le prompt caching (GA sur l'ensemble du catalogue actuel)

## Internationalisation

Le plugin est prêt pour la traduction (`Text Domain: mxchat-promptcache`, `Domain Path: /languages`). Les chaînes user-facing (WP-CLI) sont wrappées dans `__()`. Le fichier `.pot` peut être généré via :

```bash
wp i18n make-pot . languages/mxchat-promptcache.pot
```

## Changelog

Voir [CHANGELOG.md](CHANGELOG.md).

## Licence

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)

## Auteur

Paul Argoud
