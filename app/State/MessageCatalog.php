<?php
declare(strict_types=1);

final class MessageCatalog
{
    public static function get(string $key): string
    {
        $messages = [
            AppState::IDLE => 'Idle',
            AppState::CONNECTED => 'Connected',
            AppState::FAILED => 'Unavailable',

            'phase.scaffold_ready' => 'scaffold-ready',

            'brandmeister.unavailable' => 'Unavailable',
            'tgif.unavailable' => 'Unavailable',
            'ysf.unavailable' => 'Unavailable',
            'allstar.unavailable' => 'Unavailable',

            'allstar.note.connected_nodes' => 'AllStar should eventually show connected nodes.',
            'allstar.note.favorites' => 'AllStar should eventually support favorites for connect/disconnect.',
            'allstar.note.placeholder' => 'This data is placeholder-only until live status parsing is added.',

            'allstar.connected_nodes_empty' => 'No live AllStar node data is connected yet.',
            'allstar.favorites_empty' => 'No favorites are loaded yet.',
        ];

        return $messages[$key] ?? $key;
    }
}