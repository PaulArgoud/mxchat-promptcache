# MXChat Prompt Cache

Plugin WordPress qui active automatiquement le [prompt caching Anthropic](https://docs.claude.com/en/docs/build-with-claude/prompt-caching) sur les appels API du plugin **MXChat Basic**, sans modifier ses fichiers.

> Stable tag : **0.3.0** — PHP 7.4+ — WordPress 5.8+

## Pourquoi

MXChat Basic envoie à chaque requête un gros prompt `system` et l'historique complet de la conversation à l'API Anthropic. Sans cache, chaque token est facturé au plein tarif et la latence first-token augmente avec la longueur du contexte.

Le prompt caching d'Anthropic réutilise les préfixes identiques entre requêtes :

- **~90 %** de réduction du coût des tokens d'entrée cachés (`~0.1×` le tarif d'input)
- **TTFT sensiblement réduit** quand le préfixe vient du cache
- **Zéro modification de MXChat** — tout passe par le filtre `http_request_args` de WordPress

## Fonctionnement

Le plugin hooke deux filtres WordPress :

| Filtre | Rôle |
|---|---|
| `http_request_args` | Détecte les requêtes vers `api.anthropic.com/v1/messages`, injecte les `cache_control` et le header beta TTL 1h |
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
git clone https://github.com/<ton-handle>/mxchat-promptcache.git
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

## Utilisation (WP-CLI)

```bash
# Hit rate global sur 24h glissantes
wp mxchat-pc stats

# Ventilation par modèle Anthropic
wp mxchat-pc stats --by-model

# Détails de la dernière requête (breakpoints, modèle, usage)
wp mxchat-pc debug

# Reset des compteurs
wp mxchat-pc reset
```

Exemple de sortie `stats` :

```
[Global]
  Requêtes              : 142
  Tokens lus du cache   : 1245300
  Tokens écrits cache   : 87500
  Tokens entrée bruts   : 124800
  Taux de hit (cache)   : 85.4 %
  Depuis                : 2026-05-22 14:32:18
```

## Limites connues

- **Flux streaming non couvert.** MXChat Basic utilise cURL directement pour streamer les réponses du chat principal — ces appels contournent l'API HTTP de WordPress et ne sont pas interceptables par `http_request_args`. Les appels non-streamés (intent, content generator, fallbacks) sont bien cachés.
- **Stabilité de l'historique.** Les breakpoints sur les messages supposent que MXChat envoie un historique stable d'un appel à l'autre. Reformater ou tronquer l'historique entre les tours invalide le cache.
- **Idempotence.** Si une future version de MXChat ajoute nativement des `cache_control` sur l'historique, le plugin détecte leur présence et ne touche pas aux messages (mais continue à cacher `tools` et `system`).

## Compatibilité

- WordPress 5.8+
- PHP 7.4+
- MXChat Basic (toutes versions — le plugin n'introspecte pas MXChat)
- Tous les modèles Anthropic supportant le prompt caching (GA sur l'ensemble du catalogue actuel)

## Changelog

Voir [CHANGELOG.md](CHANGELOG.md).

## Licence

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)

## Auteur

Paul Argoud
