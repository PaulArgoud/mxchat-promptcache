<?php
/**
 * Plugin Name: MXChat Prompt Cache
 * Description: Active le prompt caching Anthropic (tools + system + 2 derniers messages user, TTL 1h) sur les appels API du plugin MXChat Basic, sans modifier ses fichiers. Activation automatique. Métriques via WP-CLI.
 * Version: 0.3.0
 * Requires PHP: 7.4
 * Author: Paul Argoud
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

// Minimums de préfixe cacheable par famille de modèles (en caractères, ~4 chars/token).
// Anthropic ignore SILENCIEUSEMENT cache_control si le préfixe est trop court :
// aucune erreur, juste cache_creation_input_tokens=0. D'où l'importance des seuils.
const MXCHAT_PC_MIN_CHARS_OPUS          = 16400; // 4096 tokens (Opus 4.5/4.6/4.7)
const MXCHAT_PC_MIN_CHARS_HAIKU_4_5     = 16400; // 4096 tokens
const MXCHAT_PC_MIN_CHARS_SONNET_4_6    = 8200;  // 2048 tokens
const MXCHAT_PC_MIN_CHARS_SONNET_LEGACY = 4000;  // 1024 tokens (Sonnet 4.5 et antérieurs)
const MXCHAT_PC_MIN_CHARS_DEFAULT       = 16400; // défaut sûr (modèle inconnu)

const MXCHAT_PC_EXTENDED_TTL_HEADER = 'extended-cache-ttl-2025-04-11';
const MXCHAT_PC_STATS_KEY           = 'mxchat_pc_stats';
const MXCHAT_PC_DEBUG_KEY           = 'mxchat_pc_last_debug';
const MXCHAT_PC_MAX_BREAKPOINTS     = 4;

add_filter('http_request_args', 'mxchat_pc_inject_cache_control', 10, 2);
add_filter('http_response', 'mxchat_pc_record_metrics', 10, 3);

/** Strict host+path match. */
function mxchat_pc_is_anthropic_messages_url($url) {
    if (!is_string($url)) {
        return false;
    }
    $parsed = wp_parse_url($url);
    if (!is_array($parsed)) {
        return false;
    }
    $host = $parsed['host'] ?? '';
    $path = $parsed['path'] ?? '';
    return $host === 'api.anthropic.com' && $path === '/v1/messages';
}

/** Seuil minimum en caractères pour qu'Anthropic mette réellement en cache le préfixe. */
function mxchat_pc_min_chars_for_model($model) {
    if (!is_string($model) || $model === '') {
        return MXCHAT_PC_MIN_CHARS_DEFAULT;
    }
    $m = strtolower($model);
    if (strpos($m, 'opus') !== false) {
        return MXCHAT_PC_MIN_CHARS_OPUS;
    }
    if (strpos($m, 'haiku-4') !== false || strpos($m, 'haiku-3-5') !== false) {
        return MXCHAT_PC_MIN_CHARS_HAIKU_4_5;
    }
    if (strpos($m, 'sonnet-4-6') !== false) {
        return MXCHAT_PC_MIN_CHARS_SONNET_4_6;
    }
    if (strpos($m, 'sonnet') !== false) {
        return MXCHAT_PC_MIN_CHARS_SONNET_LEGACY;
    }
    if (strpos($m, 'haiku') !== false) {
        return MXCHAT_PC_MIN_CHARS_SONNET_4_6;
    }
    return MXCHAT_PC_MIN_CHARS_DEFAULT;
}

function mxchat_pc_blocks_have_cache_control($blocks) {
    if (!is_array($blocks)) {
        return false;
    }
    foreach ($blocks as $block) {
        if (is_array($block) && !empty($block['cache_control'])) {
            return true;
        }
    }
    return false;
}

/** TTL 1h par défaut, fallback 5min si l'utilisateur opt-out via constante. */
function mxchat_pc_ephemeral_control() {
    if (defined('MXCHAT_PC_EXTENDED_TTL') && MXCHAT_PC_EXTENDED_TTL === false) {
        return ['type' => 'ephemeral'];
    }
    return ['type' => 'ephemeral', 'ttl' => '1h'];
}

function mxchat_pc_using_extended_ttl() {
    return !(defined('MXCHAT_PC_EXTENDED_TTL') && MXCHAT_PC_EXTENDED_TTL === false);
}

/** Taille texte cumulée du bloc system (chaîne ou array de blocs). */
function mxchat_pc_system_size($system) {
    if (is_string($system)) {
        return strlen($system);
    }
    if (!is_array($system)) {
        return 0;
    }
    $total = 0;
    foreach ($system as $block) {
        if (is_array($block) && isset($block['text']) && is_string($block['text'])) {
            $total += strlen($block['text']);
        }
    }
    return $total;
}

/** Taille cumulée des définitions tools (name + description + input_schema sérialisé). */
function mxchat_pc_tools_size($tools) {
    if (!is_array($tools)) {
        return 0;
    }
    $total = 0;
    foreach ($tools as $tool) {
        if (!is_array($tool)) {
            continue;
        }
        if (isset($tool['name']) && is_string($tool['name'])) {
            $total += strlen($tool['name']);
        }
        if (isset($tool['description']) && is_string($tool['description'])) {
            $total += strlen($tool['description']);
        }
        if (isset($tool['input_schema'])) {
            $encoded = wp_json_encode($tool['input_schema']);
            if (is_string($encoded)) {
                $total += strlen($encoded);
            }
        }
    }
    return $total;
}

/** Renvoie les indexes des N derniers messages avec role=user (plus récent en premier). */
function mxchat_pc_last_user_indexes($messages, $limit = 2) {
    $indexes = [];
    if (!is_array($messages)) {
        return $indexes;
    }
    for ($i = count($messages) - 1; $i >= 0 && count($indexes) < $limit; $i--) {
        if (isset($messages[$i]['role']) && $messages[$i]['role'] === 'user') {
            $indexes[] = $i;
        }
    }
    return $indexes;
}

/** Ajoute cache_control au dernier bloc d'un content (string ou array). Renvoie true si muté. */
function mxchat_pc_add_cache_control_to_content(&$content) {
    if (is_string($content)) {
        $content = [[
            'type'          => 'text',
            'text'          => $content,
            'cache_control' => mxchat_pc_ephemeral_control(),
        ]];
        return true;
    }
    if (is_array($content) && count($content) > 0) {
        $last = count($content) - 1;
        if (is_array($content[$last])) {
            $content[$last]['cache_control'] = mxchat_pc_ephemeral_control();
            return true;
        }
    }
    return false;
}

function mxchat_pc_inject_cache_control($args, $url) {
    if (!mxchat_pc_is_anthropic_messages_url($url)) {
        return $args;
    }
    if (empty($args['body']) || !is_string($args['body'])) {
        return $args;
    }

    $payload = json_decode($args['body'], true);
    if (!is_array($payload)) {
        return $args;
    }

    $model     = isset($payload['model']) && is_string($payload['model']) ? $payload['model'] : '';
    $min_chars = mxchat_pc_min_chars_for_model($model);
    $mutated   = false;
    $used      = 0;
    $debug     = [
        'time'        => time(),
        'model'       => $model,
        'min_chars'   => $min_chars,
        'breakpoints' => [
            'tools'     => false,
            'system'    => false,
            'prev_user' => false,
            'last_user' => false,
        ],
    ];

    // --- 1) Tools : breakpoint sur le dernier outil si volumineux et non déjà caché.
    // Les tools sont rendus AVANT system dans le préfixe : c'est le bloc le plus stable.
    if (
        $used < MXCHAT_PC_MAX_BREAKPOINTS
        && !empty($payload['tools'])
        && is_array($payload['tools'])
        && !mxchat_pc_blocks_have_cache_control($payload['tools'])
        && mxchat_pc_tools_size($payload['tools']) >= $min_chars
    ) {
        $last = count($payload['tools']) - 1;
        if (is_array($payload['tools'][$last])) {
            $payload['tools'][$last]['cache_control'] = mxchat_pc_ephemeral_control();
            $mutated = true;
            $used++;
            $debug['breakpoints']['tools'] = true;
        }
    }

    // --- 2) System : indépendant de l'historique (un breakpoint déjà présent
    //                 sur les messages ne doit PAS bloquer la mise en cache du system).
    if ($used < MXCHAT_PC_MAX_BREAKPOINTS && !empty($payload['system'])) {
        $system_size = mxchat_pc_system_size($payload['system']);
        if ($system_size >= $min_chars) {
            if (is_string($payload['system'])) {
                $payload['system'] = [[
                    'type'          => 'text',
                    'text'          => $payload['system'],
                    'cache_control' => mxchat_pc_ephemeral_control(),
                ]];
                $mutated = true;
                $used++;
                $debug['breakpoints']['system'] = true;
            } elseif (is_array($payload['system']) && !mxchat_pc_blocks_have_cache_control($payload['system'])) {
                $last = count($payload['system']) - 1;
                if (is_array($payload['system'][$last])) {
                    $payload['system'][$last]['cache_control'] = mxchat_pc_ephemeral_control();
                    $mutated = true;
                    $used++;
                    $debug['breakpoints']['system'] = true;
                }
            }
        }
    }

    // --- 3) Historique : 2 breakpoints "rolling" sur les derniers messages user.
    // Le dernier user = écriture du cache. L'avant-dernier user = lecture au prochain tour
    // (il était le "dernier" lors de la requête précédente). Cette stratégie maintient
    // un hit rate stable même quand la conversation s'allonge.
    if (
        $used < MXCHAT_PC_MAX_BREAKPOINTS
        && !empty($payload['messages'])
        && is_array($payload['messages'])
        && count($payload['messages']) >= 3
    ) {
        $already_cached = false;
        foreach ($payload['messages'] as $msg) {
            if (is_array($msg) && isset($msg['content']) && is_array($msg['content'])) {
                if (mxchat_pc_blocks_have_cache_control($msg['content'])) {
                    $already_cached = true;
                    break;
                }
            }
        }

        if (!$already_cached) {
            $user_idx           = mxchat_pc_last_user_indexes($payload['messages'], 2);
            $slots_remaining    = MXCHAT_PC_MAX_BREAKPOINTS - $used;

            // Avant-dernier user d'abord (ordre logique du préfixe).
            if ($slots_remaining >= 2 && isset($user_idx[1])) {
                $idx = $user_idx[1];
                if (isset($payload['messages'][$idx]['content'])
                    && mxchat_pc_add_cache_control_to_content($payload['messages'][$idx]['content'])
                ) {
                    $mutated = true;
                    $used++;
                    $debug['breakpoints']['prev_user'] = true;
                }
            }
            // Dernier user (write breakpoint).
            if ($used < MXCHAT_PC_MAX_BREAKPOINTS && isset($user_idx[0])) {
                $idx = $user_idx[0];
                if (isset($payload['messages'][$idx]['content'])
                    && mxchat_pc_add_cache_control_to_content($payload['messages'][$idx]['content'])
                ) {
                    $mutated = true;
                    $used++;
                    $debug['breakpoints']['last_user'] = true;
                }
            }
        }
    }

    set_transient(MXCHAT_PC_DEBUG_KEY, $debug, HOUR_IN_SECONDS);

    if (!$mutated) {
        return $args;
    }

    // Header beta requis pour la TTL 1h.
    if (mxchat_pc_using_extended_ttl()) {
        if (!isset($args['headers']) || !is_array($args['headers'])) {
            $args['headers'] = [];
        }
        $existing = $args['headers']['anthropic-beta'] ?? '';
        if (strpos($existing, MXCHAT_PC_EXTENDED_TTL_HEADER) === false) {
            $args['headers']['anthropic-beta'] = $existing === ''
                ? MXCHAT_PC_EXTENDED_TTL_HEADER
                : $existing . ',' . MXCHAT_PC_EXTENDED_TTL_HEADER;
        }
    }

    $args['body'] = wp_json_encode($payload);
    return $args;
}

/** Accumule les stats hit/miss dans un transient (24 h, rolling), ventilées par modèle. */
function mxchat_pc_record_metrics($response, $args, $url) {
    if (!mxchat_pc_is_anthropic_messages_url($url)) {
        return $response;
    }
    if (is_wp_error($response)) {
        return $response;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['usage']) || !is_array($data['usage'])) {
        return $response;
    }

    $model = isset($data['model']) && is_string($data['model']) ? $data['model'] : 'unknown';

    $stats = get_transient(MXCHAT_PC_STATS_KEY);
    if (!is_array($stats)) {
        $stats = [
            'requests'              => 0,
            'cache_creation_tokens' => 0,
            'cache_read_tokens'     => 0,
            'input_tokens'          => 0,
            'per_model'             => [],
            'since'                 => time(),
        ];
    }
    if (!isset($stats['per_model']) || !is_array($stats['per_model'])) {
        $stats['per_model'] = [];
    }
    if (!isset($stats['per_model'][$model])) {
        $stats['per_model'][$model] = [
            'requests'              => 0,
            'cache_creation_tokens' => 0,
            'cache_read_tokens'     => 0,
            'input_tokens'          => 0,
        ];
    }

    $cache_creation = (int) ($data['usage']['cache_creation_input_tokens'] ?? 0);
    $cache_read     = (int) ($data['usage']['cache_read_input_tokens'] ?? 0);
    $input          = (int) ($data['usage']['input_tokens'] ?? 0);

    $stats['requests']++;
    $stats['cache_creation_tokens'] += $cache_creation;
    $stats['cache_read_tokens']     += $cache_read;
    $stats['input_tokens']          += $input;

    $stats['per_model'][$model]['requests']++;
    $stats['per_model'][$model]['cache_creation_tokens'] += $cache_creation;
    $stats['per_model'][$model]['cache_read_tokens']     += $cache_read;
    $stats['per_model'][$model]['input_tokens']          += $input;

    set_transient(MXCHAT_PC_STATS_KEY, $stats, DAY_IN_SECONDS);

    // Best-effort : enrichit l'enregistrement debug courant avec la réponse correspondante.
    $debug = get_transient(MXCHAT_PC_DEBUG_KEY);
    if (is_array($debug)) {
        $debug['response_model'] = $model;
        $debug['usage'] = [
            'input_tokens'                => $input,
            'cache_creation_input_tokens' => $cache_creation,
            'cache_read_input_tokens'     => $cache_read,
        ];
        set_transient(MXCHAT_PC_DEBUG_KEY, $debug, HOUR_IN_SECONDS);
    }

    return $response;
}

// --- WP-CLI : `wp mxchat-pc stats [--by-model]` / `reset` / `debug` ---
if (defined('WP_CLI') && WP_CLI) {
    class MXChat_PC_CLI {
        /**
         * Affiche les statistiques de prompt caching.
         *
         * ## OPTIONS
         *
         * [--by-model]
         * : Ajoute un récapitulatif par modèle Anthropic.
         */
        public function stats($args, $assoc_args) {
            $stats = get_transient(MXCHAT_PC_STATS_KEY);
            if (!is_array($stats)) {
                WP_CLI::log('Aucune statistique enregistrée.');
                return;
            }

            $this->print_block('Global', $stats, true);

            if (!empty($assoc_args['by-model']) && !empty($stats['per_model'])) {
                WP_CLI::log("\n--- Détail par modèle ---");
                foreach ($stats['per_model'] as $model => $m) {
                    $this->print_block($model, $m, false);
                }
            }
        }

        private function print_block($label, $s, $with_since) {
            $cached      = (int) ($s['cache_read_tokens'] ?? 0);
            $creation    = (int) ($s['cache_creation_tokens'] ?? 0);
            $input       = (int) ($s['input_tokens'] ?? 0);
            $requests    = (int) ($s['requests'] ?? 0);
            $total_input = $input + $cached + $creation;
            $hit_rate    = $total_input > 0 ? ($cached / $total_input) * 100 : 0.0;

            $lines = [
                sprintf("\n[%s]", $label),
                sprintf("  Requêtes              : %d", $requests),
                sprintf("  Tokens lus du cache   : %d", $cached),
                sprintf("  Tokens écrits cache   : %d", $creation),
                sprintf("  Tokens entrée bruts   : %d", $input),
                sprintf("  Taux de hit (cache)   : %.1f %%", $hit_rate),
            ];
            if ($with_since && isset($s['since'])) {
                $lines[] = sprintf("  Depuis                : %s", date('Y-m-d H:i:s', (int) $s['since']));
            }
            WP_CLI::log(implode("\n", $lines));
        }

        /** Réinitialise statistiques et debug. */
        public function reset() {
            delete_transient(MXCHAT_PC_STATS_KEY);
            delete_transient(MXCHAT_PC_DEBUG_KEY);
            WP_CLI::success('Statistiques et debug réinitialisés.');
        }

        /** Affiche les détails de la dernière requête interceptée (transient 1 h). */
        public function debug() {
            $debug = get_transient(MXCHAT_PC_DEBUG_KEY);
            if (!is_array($debug)) {
                WP_CLI::log('Aucune donnée de debug (transient expiré ou aucune requête récente).');
                return;
            }
            WP_CLI::log(sprintf(
                "Dernière requête  : %s\n" .
                "Modèle (request)  : %s\n" .
                "Modèle (réponse)  : %s\n" .
                "Seuil min (chars) : %d\n" .
                "Breakpoints ajoutés :\n" .
                "  - tools     : %s\n" .
                "  - system    : %s\n" .
                "  - prev_user : %s\n" .
                "  - last_user : %s\n" .
                "Usage (tokens) :\n" .
                "  - input                 : %d\n" .
                "  - cache_creation_input  : %d\n" .
                "  - cache_read_input      : %d",
                date('Y-m-d H:i:s', (int) ($debug['time'] ?? 0)),
                $debug['model'] ?? '—',
                $debug['response_model'] ?? '—',
                (int) ($debug['min_chars'] ?? 0),
                !empty($debug['breakpoints']['tools'])     ? 'oui' : 'non',
                !empty($debug['breakpoints']['system'])    ? 'oui' : 'non',
                !empty($debug['breakpoints']['prev_user']) ? 'oui' : 'non',
                !empty($debug['breakpoints']['last_user']) ? 'oui' : 'non',
                (int) ($debug['usage']['input_tokens'] ?? 0),
                (int) ($debug['usage']['cache_creation_input_tokens'] ?? 0),
                (int) ($debug['usage']['cache_read_input_tokens'] ?? 0)
            ));
        }
    }
    WP_CLI::add_command('mxchat-pc', 'MXChat_PC_CLI');
}
